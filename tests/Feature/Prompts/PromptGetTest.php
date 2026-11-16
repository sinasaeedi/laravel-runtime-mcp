<?php

declare(strict_types=1);

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Exceptions\JsonRpcException;
use Laravel\Mcp\Schema\Implementation;
use Laravel\Mcp\Server\Methods\GetPrompt as LaravelGetPrompt;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Transport\JsonRpcRequest;
use SinaSaeedi\RuntimeMcp\Audit\AuditLogger;
use SinaSaeedi\RuntimeMcp\Audit\AuditUnavailable;
use SinaSaeedi\RuntimeMcp\Audit\Events\ToolCalled;
use SinaSaeedi\RuntimeMcp\Audit\Events\ToolDenied;
use SinaSaeedi\RuntimeMcp\Audit\Events\ToolFailed;
use SinaSaeedi\RuntimeMcp\Mcp\Methods\GetPrompt;
use SinaSaeedi\RuntimeMcp\Security\Redaction\Redactor;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Models\Order;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Prompts\EchoPrompt;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Prompts\NoToolPrompt;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Prompts\ThrowingArgumentsPrompt;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Prompts\ThrowingPrompt;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Prompts\ThrowingToolsPrompt;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Prompts\UnknownToolPrompt;
use SinaSaeedi\RuntimeMcp\Tests\Support\AuditLog;
use SinaSaeedi\RuntimeMcp\Tests\Support\FixtureTools;
use SinaSaeedi\RuntimeMcp\Tests\Support\Prompts;
use SinaSaeedi\RuntimeMcp\Tests\Support\ProtocolEra;

/*
 * 01-36 Part C and D, spec 5.7, 5.14: our prompts/get handler finds hidden prompts too, answers unknown,
 * hidden and denied ones with laravel/mcp's own "not found" error, and writes exactly one prompt_get
 * audit entry for every request that names a prompt. laravel/mcp's HttpTransport sends a JSON-RPC
 * error as HTTP 400 for -32602 and 500 for -32603.
 */

beforeEach(fn () => AuditLog::clear());
afterEach(fn () => AuditLog::clear());

/**
 * The fixture tools, with the custom prompts set after the boot (RuntimeServer reads the config on each
 * call), so the boot rules never see them.
 *
 * @param  list<class-string>  $prompts
 * @param  array<string, mixed>  $config
 */
function promptGetServe(array $prompts = [EchoPrompt::class], array $config = []): void
{
    FixtureTools::serve($config);
    config(['runtime-mcp.prompts' => $prompts]);
}

/** @return array<array-key, mixed> the only audit entry */
function promptGetEntry(): array
{
    $entries = AuditLog::entries();
    expect($entries)->toHaveCount(1);

    return $entries[0] ?? [];
}

/** @return array{message: string, code: int} the JSON-RPC error that a handler throws for this request */
function promptGetError(object $handler, JsonRpcRequest $request): array
{
    assert($handler instanceof GetPrompt || $handler instanceof LaravelGetPrompt);
    $context = new ServerContext(
        supportedProtocolVersions: [], serverCapabilities: [], implementation: new Implementation('t', '1'),
        instructions: '', maxPaginationLength: 50, defaultPaginationLength: 15, tools: [], resources: [], prompts: [],
    );

    try {
        $handler->handle($request, $context);
    } catch (JsonRpcException $e) {
        $error = $e->toJsonRpcResponse()->toArray()['error'] ?? null;
        assert(is_array($error) && is_string($error['message'] ?? null) && is_int($error['code'] ?? null));

        return ['message' => $error['message'], 'code' => $error['code']];
    }

    throw new LogicException('The handler did not throw.');
}

it('PG1 answers an allowed prompt with one user message, and audits it once, in both eras', function (ProtocolEra $era) {
    Prompts::serve();
    Event::fake([ToolCalled::class, ToolDenied::class, ToolFailed::class]);

    $response = Prompts::get('health_check', era: $era)->assertOk();
    $entry = promptGetEntry();

    expect($response->json('result.description'))->toBe('A short health check of this server: app state, queues, failed jobs and recent errors.')
        ->and($response->json('result.messages'))->toHaveCount(1)
        ->and($response->json('result.messages.0.role'))->toBe('user')
        ->and($response->json('result.messages.0.content.type'))->toBe('text')
        ->and($response->json('result.messages.0.content.text'))->toStartWith('Give a short health check of the `testing` environment of `Runtime Test`.')
        ->and($entry)->toMatchArray([
            'kind' => 'prompt_get',
            'environment' => 'testing',
            'principal' => 'tests',
            'user_id' => null,
            'auth_method' => 'api_key',
            'ip' => '127.0.0.1',
            'client' => $era === ProtocolEra::Modern ? ['name' => 'runtime-mcp-tests', 'version' => '1.0.0'] : null,
            'tool' => null,
            'params' => [],
            'decision' => 'allowed',
            'reason' => null,
            'outcome' => 'success',
            'error_class' => null,
            'row_count' => null,
            'output_bytes' => null,
            'details' => ['prompt' => 'health_check'],
        ])
        ->and($entry['duration_ms'])->toBeInt();

    Event::assertDispatched(ToolCalled::class, fn (ToolCalled $event): bool => $event->entry->kind === 'prompt_get');
    Event::assertDispatchedTimes(ToolCalled::class, 1);
    Event::assertNotDispatched(ToolDenied::class);
    Event::assertNotDispatched(ToolFailed::class);
})->with([ProtocolEra::Legacy, ProtocolEra::Modern]);

it('PG2 answers an unknown prompt with laravel/mcp\'s not-found error, and audits it as unknown_prompt', function () {
    Prompts::serve();
    Event::fake([ToolDenied::class]);

    Prompts::get('nope', ['a' => 'b'])->assertStatus(400)
        ->assertJsonPath('error.code', -32602)
        ->assertJsonPath('error.message', 'Prompt [nope] not found.');

    expect(promptGetEntry())->toMatchArray([
        'kind' => 'prompt_get', 'decision' => 'denied', 'reason' => 'unknown_prompt', 'outcome' => null,
        'params' => ['a' => 'b'], 'details' => ['prompt' => 'nope'],
    ]);
    Event::assertDispatched(ToolDenied::class, fn (ToolDenied $event): bool => $event->entry->reason === 'unknown_prompt');
});

it('PG3 gives exactly the text and code of laravel/mcp\'s own handler for an unknown and a missing name', function (string $case) {
    Prompts::serve();
    $request = new JsonRpcRequest(id: 9, method: 'prompts/get', params: $case === 'unknown' ? ['name' => 'nope'] : ['arguments' => []]);

    expect(promptGetError(app(GetPrompt::class), $request))->toBe(promptGetError(new LaravelGetPrompt, $request));
})->with(['unknown', 'missing']);

it('PG4 answers a prompt whose tool the Gate denies like an unknown one, and audits the first denied tool', function () {
    Prompts::serve(allow: array_values(array_diff(Prompts::BUILT_IN_TOOLS, ['queue_failed_jobs_list', 'queue_failed_job_show'])));
    Event::fake([ToolDenied::class]);

    Prompts::get('investigate_failed_jobs', ['since' => '7d'])->assertStatus(400)
        ->assertJsonPath('error.code', -32602)
        ->assertJsonPath('error.message', 'Prompt [investigate_failed_jobs] not found.');

    expect(promptGetEntry())->toMatchArray([
        'decision' => 'denied', 'reason' => 'prompt_tool_denied', 'outcome' => null, 'params' => ['since' => '7d'],
        'details' => ['prompt' => 'investigate_failed_jobs', 'tool' => 'queue_failed_jobs_list'],
    ]);
    Event::assertDispatchedTimes(ToolDenied::class, 1);
});

it('PG5 treats a tool that is not registered as denied, and a prompt with no tool as denied', function (string $class, string $name, array $details) {
    /** @var class-string $class */
    promptGetServe([$class]);

    Prompts::get($name)->assertStatus(400)->assertJsonPath('error.message', "Prompt [{$name}] not found.");

    expect(promptGetEntry())->toMatchArray(['reason' => 'prompt_tool_denied', 'details' => $details]);
})->with([
    'not registered' => [UnknownToolPrompt::class, 'unknown_tool_prompt', ['prompt' => 'unknown_tool_prompt', 'tool' => 'nope_tool']],
    'no tool' => [NoToolPrompt::class, 'no_tool_prompt', ['prompt' => 'no_tool_prompt']],
]);

it('PG6 denies a prompt whose tools() throws as gate_error, reported once, with no exception text', function () {
    promptGetServe([ThrowingToolsPrompt::class], ['app.debug' => true]);
    Exceptions::fake();

    $response = Prompts::get('throwing_tools_prompt')->assertStatus(400)->assertJsonPath('error.message', 'Prompt [throwing_tools_prompt] not found.');

    expect(promptGetEntry())->toMatchArray(['decision' => 'denied', 'reason' => 'gate_error'])
        ->and((string) $response->getContent())->not->toContain('tools() failed');
    Exceptions::assertReportedCount(1);
});

it('PG7 denies a call with no principal as no_principal, with the same error', function () {
    Prompts::serve();

    $error = promptGetError(app(GetPrompt::class), new JsonRpcRequest(id: 1, method: 'prompts/get', params: ['name' => 'health_check']));

    expect($error)->toBe(['message' => 'Prompt [health_check] not found.', 'code' => -32602])
        ->and(promptGetEntry())->toMatchArray(['decision' => 'denied', 'reason' => 'no_principal', 'principal' => null]);
});

it('PG8 refuses a request without a name as laravel/mcp does, with no audit entry', function () {
    Prompts::serve();

    currentTest()->mcpPost('prompts/get', ['arguments' => (object) []])->assertStatus(400)
        ->assertJsonPath('error.code', -32602)
        ->assertJsonPath('error.message', 'Missing [name] parameter.');

    expect(AuditLog::entries())->toBe([]);
});

it('PG9 shows a name that is not a string by its type, and audits it as unknown', function () {
    Prompts::serve();

    currentTest()->mcpPost('prompts/get', ['name' => ['health_check']])->assertStatus(400)
        ->assertJsonPath('error.message', 'Prompt [array] not found.');

    expect(promptGetEntry())->toMatchArray(['reason' => 'unknown_prompt', 'details' => ['prompt' => 'array']]);
});

it('PG10 keeps the whole name in the error and cuts it to 64 characters in the audit', function () {
    Prompts::serve();
    $name = str_repeat('p', 100);

    Prompts::get($name)->assertStatus(400)->assertJsonPath('error.message', "Prompt [{$name}] not found.");

    expect(promptGetEntry()['details'])->toBe(['prompt' => str_repeat('p', 64)]);
});

it('PG11 refuses arguments that are not an object as laravel/mcp does, before our handler, with no audit entry', function () {
    Prompts::serve();

    currentTest()->mcpPost('prompts/get', ['name' => 'health_check', 'arguments' => ['a']])->assertStatus(400)
        ->assertJsonPath('error.code', -32602)
        ->assertJsonPath('error.message', 'Invalid params: The [arguments] member must be an object.');

    expect(AuditLog::entries())->toBe([]);
});

it('PG12 refuses input that breaks a rule with -32602 and the messages, audited as a failed call', function (string $prompt, array $arguments, string $message) {
    /** @var array<string, mixed> $arguments */
    Prompts::serve(['runtime-mcp.models' => ['order' => ['class' => Order::class]], 'runtime-mcp.logs.channels' => ['stack']]);
    Event::fake([ToolFailed::class]);

    Prompts::get($prompt, $arguments)->assertStatus(400)
        ->assertJsonPath('error.code', -32602)
        ->assertJsonPath('error.message', 'Invalid params: '.$message);

    expect(promptGetEntry())->toMatchArray([
        'decision' => 'allowed', 'reason' => null, 'outcome' => 'error',
        'error_class' => 'Illuminate\Validation\ValidationException', 'params' => app(Redactor::class)->redact($arguments),
    ]);
    Event::assertDispatchedTimes(ToolFailed::class, 1);
})->with([
    'since in words' => ['investigate_failed_jobs', ['since' => '24 hours'], 'The since field format is invalid.'],
    'since zero' => ['investigate_failed_jobs', ['since' => '0h'], 'The since field format is invalid.'],
    'since with a newline' => ['triage_recent_errors', ['since' => "24h\n"], 'The since field format is invalid.'],
    'a job class with a backtick' => ['investigate_failed_jobs', ['job_class' => 'App`Jobs'], 'The job class field format is invalid.'],
    'a job class with a trailing newline' => ['investigate_failed_jobs', ['job_class' => "App\\Jobs\n"], 'The job class field format is invalid.'],
    'a JWT as job class' => ['investigate_failed_jobs', ['job_class' => 'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.abc'], 'The job class field format is invalid.'],
    'an unknown argument' => ['health_check', ['evil' => 'x'], 'The evil argument is not allowed.'],
    'an unknown argument named like a token (the message is redacted)' => ['health_check', ['ghp_'.str_repeat('a', 36) => 'x'], 'The [redacted:github_token] argument is not allowed.'],
    'a model that is not an alias' => ['explain_record', ['model' => 'user', 'key' => '1'], 'The model argument must be one of: order.'],
    'a channel that is not configured' => ['triage_recent_errors', ['channel' => 'daily'], 'The channel argument must be one of: stack.'],
    'a missing required argument' => ['explain_record', ['key' => '1'], 'The model field is required.'],
    'a required argument sent empty' => ['explain_record', ['model' => '', 'key' => '1'], 'The model field is required.'],
    'a key of 65 characters' => ['explain_record', ['model' => 'order', 'key' => str_repeat('k', 65)], 'The key field format is invalid.'],
    'a value that is not a string' => ['investigate_failed_jobs', ['since' => 24], 'The since field must be a string. The since field format is invalid.'],
    'a value over 200 characters' => ['investigate_failed_jobs', ['job_class' => str_repeat('J', 201)], 'The job class field must not be greater than 200 characters. The job class field format is invalid.'],
]);

it('PG13 says when an argument has no allowed value on this server', function () {
    Prompts::serve(['runtime-mcp.models' => []]);

    Prompts::get('explain_record', ['model' => 'order', 'key' => '1'])->assertStatus(400)
        ->assertJsonPath('error.message', 'Invalid params: The model argument has no allowed value on this server.');
});

it('PG14 treats an optional argument sent as "" or null as absent', function (mixed $value) {
    Prompts::serve();

    expect(Prompts::text('investigate_failed_jobs', ['since' => $value, 'job_class' => $value]))
        ->toBe(Prompts::text('investigate_failed_jobs'));
})->with(['empty' => [''], 'null' => [null]]);

it('PG15 redacts an argument value before it goes into the text, and in the audit', function () {
    promptGetServe();
    $jwt = 'eyJhbGciOiJIUzI1NiJ9'.'.eyJzdWIiOiIxMjM0NTY3ODkwIn0.dozjgNryP4J3jVmNHl0w5N_XgL0n3I9PlFUP0THsR8U';

    $text = Prompts::text('echo_prompt', ['mode' => 'fast', 'topic' => "token {$jwt}"]);

    expect($text)->toBe('Mode fast, topic token [redacted:jwt], level (none).')
        ->and(json_encode(AuditLog::entries()))->not->toContain($jwt)
        ->and(promptGetEntry()['params'])->toBe(['mode' => 'fast', 'topic' => 'token [redacted:jwt]']);
});

it('PG16 answers a prompt whose message() throws with a fixed -32603 error, reported, audited with the class, also in debug mode', function () {
    promptGetServe([ThrowingPrompt::class], ['app.debug' => true]);
    Exceptions::fake();

    $response = Prompts::get('throwing_prompt')->assertStatus(500)
        ->assertJsonPath('error.code', -32603)
        ->assertJsonPath('error.message', GetPrompt::FAILURE);

    expect((string) $response->getContent())->not->toContain(ThrowingPrompt::SECRET)
        ->and(promptGetEntry())->toMatchArray(['decision' => 'allowed', 'outcome' => 'error', 'error_class' => RuntimeException::class]);
    Exceptions::assertReportedCount(1);
    Exceptions::assertReported(RuntimeException::class);
});

it('PG17 answers a prompt whose arguments() throws with the same fixed error', function () {
    promptGetServe([ThrowingArgumentsPrompt::class]);
    Exceptions::fake();

    Prompts::get('throwing_arguments_prompt')->assertStatus(500)->assertJsonPath('error.message', GetPrompt::FAILURE);

    expect(promptGetEntry())->toMatchArray(['outcome' => 'error', 'error_class' => InvalidArgumentException::class]);
});

it('PG18 withholds the text when the audit cannot be written, and keeps the not-found error for a denial', function () {
    promptGetServe(config: ['logging.channels.runtime-mcp' => [
        'driver' => 'daily',
        'path' => __FILE__.'/no-such-dir/audit.log',
        'formatter' => Monolog\Formatter\JsonFormatter::class,
    ]]);
    Exceptions::fake();

    Prompts::get('echo_prompt', ['mode' => 'fast'])->assertStatus(500)
        ->assertJsonPath('error.code', -32603)
        ->assertJsonPath('error.message', GetPrompt::WITHHELD);
    Prompts::get('nope')->assertStatus(400)->assertJsonPath('error.message', 'Prompt [nope] not found.');

    Exceptions::assertReportedCount(2);
    Exceptions::assertReported(AuditUnavailable::class);
});

it('PG19 counts denied and refused prompts/get against the denied-call threshold, never a success', function () {
    promptGetServe();
    app()->scoped(AuditLogger::class, fn (Application $app): AuditLogger => new AuditLogger($app, app(Repository::class), 2));
    currentTest()->freezeTime();

    Prompts::get('echo_prompt', ['mode' => 'a'])->assertOk();
    Prompts::get('echo_prompt', ['mode' => 'b'])->assertOk();
    Prompts::get('echo_prompt', ['mode' => 'c'])->assertOk();
    Prompts::get('nope', ['x' => '1'])->assertStatus(400);
    Prompts::get('echo_prompt', ['level' => 'bad'])->assertStatus(400);
    Prompts::get('nope', ['x' => '3'])->assertStatus(400);

    expect(array_map(fn (array $entry): mixed => $entry['params'] ?? null, AuditLog::entries()))->toBe([
        ['mode' => 'a'],
        ['mode' => 'b'],
        ['mode' => 'c'],
        ['x' => '1'],
        ['level' => 'bad'],
        ['_omitted' => sprintf(AuditLogger::PARAMS_OMITTED, 2)],
    ]);
});

it('PG20 audits each prompts/get once, also when the Gate changes between the list and the get', function () {
    Prompts::serve();
    expect(Prompts::listed())->toContain('explain_record');

    Gate::define('runtime-mcp.model_show', fn (): bool => false);
    Prompts::get('explain_record', ['model' => 'x', 'key' => '1'])->assertStatus(400)->assertJsonPath('error.message', 'Prompt [explain_record] not found.');

    expect(promptGetEntry())->toMatchArray(['reason' => 'prompt_tool_denied', 'details' => ['prompt' => 'explain_record', 'tool' => 'model_show']]);
});

it('PG21 cuts the messages of refused input to 300 characters', function () {
    Prompts::serve();
    $arguments = [];

    foreach (range(1, 40) as $n) {
        $arguments["extra_{$n}"] = 'x';
    }

    $message = Prompts::get('health_check', $arguments)->assertStatus(400)->json('error.message');

    expect($message)->toBe('Invalid params: '.mb_substr(implode(' ', array_map(fn (int $n): string => "The extra_{$n} argument is not allowed.", range(1, 40))), 0, 300));
});
