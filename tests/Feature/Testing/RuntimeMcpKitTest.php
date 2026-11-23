<?php

declare(strict_types=1);

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\AssertionFailedError;
use SinaSaeedi\RuntimeMcp\Audit\AuditEntry;
use SinaSaeedi\RuntimeMcp\Audit\Events\AuthFailed;
use SinaSaeedi\RuntimeMcp\Audit\Reason;
use SinaSaeedi\RuntimeMcp\Mcp\McpRoutes;
use SinaSaeedi\RuntimeMcp\Mcp\McpToolAdapters;
use SinaSaeedi\RuntimeMcp\Principal;
use SinaSaeedi\RuntimeMcp\Security\Auth\AuthMethod;
use SinaSaeedi\RuntimeMcp\Security\Auth\CurrentPrincipal;
use SinaSaeedi\RuntimeMcp\Security\Auth\LinkedUserMissing;
use SinaSaeedi\RuntimeMcp\Server\RuntimeServer;
use SinaSaeedi\RuntimeMcp\Testing\PendingRuntimeMcp;
use SinaSaeedi\RuntimeMcp\Testing\RuntimeMcp;
use SinaSaeedi\RuntimeMcp\Testing\RuntimeMcpPromptResult;
use SinaSaeedi\RuntimeMcp\Testing\RuntimeMcpResult;
use SinaSaeedi\RuntimeMcp\Testing\SteppingClock;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Mcp\OrderLookupTool;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Models\OrderStatus;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Prompts\EchoPrompt;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Prompts\OrderLookupPrompt;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Tools\EchoTool;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Tools\NoteLessTool;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Tools\ProgressProbeTool;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Tools\ResultTool;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Tools\SecondEchoTool;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Tools\ThrowingTool;
use SinaSaeedi\RuntimeMcp\Tests\Support\AuditLog;
use SinaSaeedi\RuntimeMcp\Tests\Support\Gates;
use SinaSaeedi\RuntimeMcp\Tests\Support\Users;
use SinaSaeedi\RuntimeMcp\Tools\ProgressClock;

/*
 * 01-39, spec 7.5: the test kit, used the way a team uses it. No bootWith(): the endpoint stays off (enabled = false is
 * the default in a team's tests), and the team's tools, wrapped tools and prompts are set in config inside the test,
 * as the generated test does. The default connection is the in-memory SQLite `testing`.
 */

beforeEach(fn () => AuditLog::clear());
afterEach(fn () => AuditLog::clear());

/** A team's config: custom tools, one wrapped tool, two custom prompts. */
function kitServe(): void
{
    config([
        'runtime-mcp.tools' => [EchoTool::class, ResultTool::class, ThrowingTool::class, ProgressProbeTool::class, SecondEchoTool::class, NoteLessTool::class],
        'runtime-mcp.wrapped_tools' => [['class' => OrderLookupTool::class, 'name' => OrderLookupTool::NAME, 'tier' => 'read_sensitive', 'open_world' => false]],
        'runtime-mcp.prompts' => [OrderLookupPrompt::class, EchoPrompt::class],
    ]);
}

/** The message of the kit assertion that $assert runs, or null when it passed. */
function kitFailure(Closure $assert): ?string
{
    try {
        $assert();
    } catch (AssertionFailedError $e) {
        return $e->getMessage();
    }

    return null;
}

/**
 * A result built by hand, for an answer the pipeline does not give today.
 *
 * @param  array<string, mixed>  $result
 */
function kitResult(array $result, AuditEntry ...$entries): RuntimeMcpResult
{
    return new RuntimeMcpResult('x', ['jsonrpc' => '2.0', 'id' => 1, 'result' => $result], [], array_values($entries), false, false);
}

/** A passing call of `echo` as `sina`. */
function kitEcho(string $text = 'hi'): RuntimeMcpResult
{
    Gates::allow('echo');

    return RuntimeMcp::test()->as('sina')->call('echo', ['text' => $text]);
}

it('TK1 runs a call with no principal through the pipeline: the real no_principal denial, audited', function () {
    kitServe();
    Gates::allow('echo');

    $result = RuntimeMcp::test()->call('echo', ['text' => 'hi']);

    $result->assertDenied('no_principal')->assertAudited('denied')->assertStatus('unavailable')->assertNote('No authenticated principal.');
    expect($result->envelope())->toBe(['environment' => 'testing', 'status' => 'unavailable', 'note' => 'No authenticated principal.', 'truncated' => false, 'data' => null]);
});

it('TK2 denies a tool without a Gate (default deny) and hides it from tools/list', function () {
    kitServe();

    $chain = RuntimeMcp::test()->as('sina');
    $chain->call('echo', ['text' => 'hi'])->assertDenied('no_gate')->assertAudited('denied');

    expect($chain->listedTools())->toBe([]);
    $chain->assertNotListed('echo', 'db_query');
});

it('TK3 runs an allowed call and checks the whole envelope', function () {
    kitServe();

    kitEcho()
        ->assertOk()
        ->assertStatus('ok')
        ->assertData(['text' => 'hi'])
        ->assertData(fn (mixed $data): bool => is_array($data) && $data['text'] === 'hi')
        ->assertTruncated(false)
        ->assertMatchesOutputSchema()
        ->assertAudited()
        ->assertAudited('allowed');

    expect(RuntimeMcp::test()->as('sina')->listedTools())->toBe(['echo']);
    RuntimeMcp::test()->as('sina')->assertListed('echo')->assertNotListed('result');
});

it('TK4 fails each assertion on a wrong result, with a message that says why', function (string $case, string $message) {
    kitServe();
    Gates::allow('echo', 'result');

    $ok = fn (): RuntimeMcpResult => RuntimeMcp::test()->as('sina')->call('echo', ['text' => 'hi']);
    $failure = kitFailure(fn () => match ($case) {
        'status' => $ok()->assertStatus('empty'),
        'ok on an error' => RuntimeMcp::test()->call('echo', ['text' => 'hi'])->assertOk(),
        'ok on a non-ok success' => RuntimeMcp::test()->as('sina')->call('result', ['kind' => 'empty'])->assertOk(),
        'note' => RuntimeMcp::test()->as('sina')->call('result', ['kind' => 'empty'])->assertNote('laravel-2026-10-02.log'),
        'note of an ok result' => $ok()->assertNote('hi'),
        'data array' => $ok()->assertData(['text' => 'other']),
        'data array, same values, other type' => RuntimeMcp::test()->as('sina')->call('result', ['kind' => 'ok'])->assertData(['rows' => [['1'], ['2']]]),
        'data closure false' => $ok()->assertData(fn (mixed $data): bool => false),
        'data closure not true' => $ok()->assertData(fn (mixed $data): int => 1),
        'truncated' => $ok()->assertTruncated(3),
        'denied on an allowed call' => $ok()->assertDenied(),
        'denied with another reason' => RuntimeMcp::test()->call('echo', ['text' => 'hi'])->assertDenied('no_gate'),
        'failed on a success' => $ok()->assertFailed(),
        'failed on a denied call' => RuntimeMcp::test()->call('echo', ['text' => 'hi'])->assertFailed(),
        'failed with another class' => RuntimeMcp::test()->as('sina')->call('echo', ['text' => str_repeat('x', 101)])->assertFailed(RuntimeException::class),
        'audited with another decision' => $ok()->assertAudited('denied'),
        'progress without withProgress()' => $ok()->assertProgress(0),
        'listed' => RuntimeMcp::test()->as('sina')->assertListed('result', 'db_query'),
        'not listed' => RuntimeMcp::test()->as('sina')->assertNotListed('echo'),
        default => throw new InvalidArgumentException($case),
    });

    expect($failure)->toBeString()->toContain($message);
})->with([
    'assertStatus' => ['status', 'The call of [echo] has status [ok], not [empty]. Note: null'],
    'assertOk on a denied call' => ['ok on an error', 'has status [unavailable], not [ok]. Note: No authenticated principal.'],
    'assertOk on an empty result' => ['ok on a non-ok success', 'has status [empty], not [ok]. Note: No match in laravel-2026-10-01.log.'],
    'assertNote' => ['note', 'The note of [result] does not contain [laravel-2026-10-02.log]. Note: No match in laravel-2026-10-01.log.'],
    'assertNote with no note' => ['note of an ok result', 'The note of [echo] does not contain [hi]. Note: null'],
    'assertData array' => ['data array', 'The data of [echo] is not the expected data.'],
    'assertData compares types' => ['data array, same values, other type', 'The data of [result] is not the expected data.'],
    'assertData closure that says false' => ['data closure false', 'does not pass the given closure (it must return true).'],
    'assertData closure that returns 1' => ['data closure not true', 'does not pass the given closure (it must return true).'],
    'assertTruncated' => ['truncated', 'The call of [echo] has truncated false, not 3.'],
    'assertDenied on an allowed call' => ['denied on an allowed call', 'The call of [echo] was audited as [allowed] (reason null), not [denied].'],
    'assertDenied with another reason' => ['denied with another reason', 'was denied with the reason [no_principal], not [no_gate].'],
    'assertFailed on a success' => ['failed on a success', 'The call of [echo] did not fail: its outcome is [success].'],
    'assertFailed on a denied call' => ['failed on a denied call', 'The call of [echo] was audited as [denied] (reason no_principal), not [allowed].'],
    'assertFailed with another class' => ['failed with another class', 'failed with [Illuminate\Validation\ValidationException], not [RuntimeException].'],
    'assertAudited with another decision' => ['audited with another decision', 'was audited as [allowed] (reason null), not [denied].'],
    'assertProgress without withProgress()' => ['progress without withProgress()', RuntimeMcpResult::NO_PROGRESS],
    'assertListed' => ['listed', 'The tool [db_query] is not in tools/list for this principal.'],
    'assertNotListed' => ['not listed', 'The tool [echo] is in tools/list for this principal.'],
]);

it('TK5 runs a linked name as its User, as in production (link_users)', function () {
    kitServe();
    config(['runtime-mcp.auth.link_users' => ['sina' => 'sina@example.com']]);
    $user = Users::create('sina@example.com');
    $seen = null;
    Gate::define('runtime-mcp.echo', function (mixed $who) use (&$seen): bool {
        $seen = $who;

        return true;
    });

    RuntimeMcp::test()->as('sina')->call('echo', ['text' => 'hi'])->assertOk();

    expect($seen)->toBeInstanceOf($user::class)
        ->and(data_get($seen, 'email'))->toBe('sina@example.com')
        ->and(AuditLog::entries()[0] ?? [])->toMatchArray(['principal' => 'sina', 'auth_method' => 'testing', 'user_id' => $user->getKey()]);
});

it('TK6 throws LinkedUserMissing from as() for a name linked to a user that cannot be found', function () {
    config(['runtime-mcp.auth.link_users' => ['sina' => 'nobody@example.com']]);
    Users::create('someone@example.com');

    expect(fn () => RuntimeMcp::test()->as('sina'))->toThrow(LinkedUserMissing::class, 'Key [sina] is linked to a user that cannot be found.');
});

it('TK7 refuses a name that is not a valid key name', function (string $name) {
    expect(fn () => RuntimeMcp::test()->as($name))->toThrow(InvalidArgumentException::class, PendingRuntimeMcp::BAD_NAME);
})->with(['empty' => [''], 'a pasted key' => ['rmcp_AQEB'], 'a space' => ['sina saeedi'], 'longer than 64' => [str_repeat('a', 65)], 'a slash' => ['ops/sina']]);

it('TK8 runs asUser() as user-<id>, with the Gates for that user', function () {
    kitServe();
    $user = Users::create('ops@example.com');
    $seen = null;
    Gate::define('runtime-mcp.echo', function (mixed $who) use (&$seen): bool {
        $seen = $who;

        return true;
    });

    RuntimeMcp::test()->asUser($user)->call('echo', ['text' => 'hi'])->assertOk();

    $id = $user->getAuthIdentifier();
    expect(is_int($id) || is_string($id))->toBeTrue()
        ->and($seen)->toBe($user)
        ->and(AuditLog::entries()[0] ?? [])->toMatchArray(['principal' => Principal::userName(is_int($id) || is_string($id) ? $id : 0), 'auth_method' => 'testing', 'user_id' => $id]);
});

it('TK9 refuses asUser() for a user whose identifier is not an int or a string', function () {
    expect(fn () => RuntimeMcp::test()->asUser(new GenericUser(['id' => ['x']])))
        ->toThrow(InvalidArgumentException::class, PendingRuntimeMcp::BAD_USER);
});

it('TK10 keeps each chain\'s principal to its own requests, and leaves none behind', function () {
    kitServe();
    Gates::allow('echo');

    $sina = RuntimeMcp::test()->as('sina');
    $nobody = RuntimeMcp::test();

    $sina->call('echo', ['text' => 'a'])->assertOk();
    $nobody->call('echo', ['text' => 'b'])->assertDenied('no_principal');
    $sina->call('echo', ['text' => 'c'])->assertOk();

    expect(app(CurrentPrincipal::class)->get())->toBeNull()
        ->and(array_column(AuditLog::entries(), 'principal'))->toBe(['sina', null, 'sina']);
});

it('TK11 denies an unknown tool name, audited as unknown_tool, with no envelope', function () {
    kitServe();

    $result = RuntimeMcp::test()->as('sina')->call('no_such_tool');

    $result->assertDenied('unknown_tool')->assertAudited('denied');
    expect(kitFailure(fn () => $result->envelope()))->toBe('The call of [no_such_tool] returned no envelope: Tool [no_such_tool] not found.');
});

it('TK12 reports a tool that throws as a failed call, with the exception class', function () {
    kitServe();
    Gates::allow('throwing');
    Exceptions::fake(); // RunTool reports the exception: keep it out of laravel.log

    RuntimeMcp::test()->as('sina')->call('throwing')
        ->assertFailed()
        ->assertFailed(RuntimeException::class)
        ->assertFailed('\RuntimeException')
        ->assertStatus('unavailable')
        ->assertNote('The tool failed: RuntimeException')
        ->assertAudited('allowed');

    Exceptions::assertReported(RuntimeException::class);
});

it('TK13 reports refused input as a failed call (ValidationException)', function () {
    kitServe();
    Gates::allow('echo');

    RuntimeMcp::test()->as('sina')->call('echo', ['text' => str_repeat('x', 101)])
        ->assertFailed(ValidationException::class)
        ->assertNote('Invalid input: ');
});

it('TK14 checks partial results and truncated counts', function () {
    kitServe();
    Gates::allow('result');

    RuntimeMcp::test()->as('sina')->call('result', ['kind' => 'partial_counted'])
        ->assertStatus('partial')
        ->assertTruncated(3)
        ->assertNote('omitted')
        ->assertData(['rows' => [[1]]])
        ->assertMatchesOutputSchema();
});

it('TK15 calls a wrapped tool through the same API', function () {
    kitServe();

    RuntimeMcp::test()->as('sina')->call(OrderLookupTool::NAME, ['order' => 7])->assertDenied('no_gate');

    Gates::allow(OrderLookupTool::NAME);
    RuntimeMcp::test()->as('sina')->call(OrderLookupTool::NAME, ['order' => 7])->assertOk()->assertData(['id' => 7])->assertMatchesOutputSchema()->assertAudited('allowed');

    app()->instance(OrderLookupTool::HIDE, true);
    RuntimeMcp::test()->as('sina')->call(OrderLookupTool::NAME, ['order' => 7])->assertDenied('not_available');
    RuntimeMcp::test()->as('sina')->assertNotListed(OrderLookupTool::NAME);

    // Triage 2026-11-23 (review m2, DoD 2): one real entry per call, audited as the kit's principal.
    $entries = AuditLog::entries();

    expect(array_column($entries, 'tool'))->toBe([OrderLookupTool::NAME, OrderLookupTool::NAME, OrderLookupTool::NAME])
        ->and(array_column($entries, 'decision'))->toBe(['denied', 'allowed', 'denied'])
        ->and(array_column($entries, 'auth_method'))->toBe(['testing', 'testing', 'testing'])
        ->and(array_column($entries, 'principal'))->toBe(['sina', 'sina', 'sina']);
});

it('TK16 gets a prompt, and sees it hidden when one of its tools is denied or when it does not exist', function () {
    kitServe();

    RuntimeMcp::test()->as('sina')->prompt('order_lookup_prompt')->assertHidden();
    RuntimeMcp::test()->as('sina')->prompt('no_such_prompt')->assertHidden();

    Gates::allow(OrderLookupTool::NAME);
    RuntimeMcp::test()->as('sina')->prompt('order_lookup_prompt')->assertSee('Call app_order_lookup with the order id.');
    RuntimeMcp::test()->as('sina')->prompt('echo_prompt', ['mode' => 'quick'])->assertHidden();

    Gates::allow('echo');
    RuntimeMcp::test()->as('sina')->prompt('echo_prompt', ['mode' => 'quick'])->assertSee('Mode quick, topic (none)');
});

it('TK17 sees the built-in health_check prompt hidden when one of its tools is denied', function () {
    Gates::allow('app_info');
    RuntimeMcp::test()->as('sina')->prompt('health_check')->assertHidden();

    Gates::allow('queue_overview');
    RuntimeMcp::test()->as('sina')->prompt('health_check')->assertSee('app_info');
});

it('TK18 fails the prompt assertions on a wrong answer', function (string $case, string $message) {
    kitServe();
    Gates::allow(OrderLookupTool::NAME, 'echo');

    $failure = kitFailure(fn (): RuntimeMcpPromptResult => match ($case) {
        'see' => RuntimeMcp::test()->as('sina')->prompt('order_lookup_prompt')->assertSee('order number'),
        'see on a hidden prompt' => RuntimeMcp::test()->prompt('order_lookup_prompt')->assertSee('Call'),
        'hidden' => RuntimeMcp::test()->as('sina')->prompt('order_lookup_prompt')->assertHidden(),
        'hidden, refused input' => RuntimeMcp::test()->as('sina')->prompt('echo_prompt')->assertHidden(),
        default => throw new InvalidArgumentException($case),
    });

    expect($failure)->toBeString()->toContain($message);
})->with([
    'assertSee' => ['see', 'The text [order number] is not in the messages of the prompt [order_lookup_prompt].'],
    'assertSee on a hidden prompt' => ['see on a hidden prompt', 'The text [Call] is not in the messages of the prompt [order_lookup_prompt].'],
    'assertHidden on a shown prompt' => ['hidden', 'The prompt [order_lookup_prompt] is not hidden: '],
    'assertHidden on refused input' => ['hidden, refused input', 'The prompt [echo_prompt] is not hidden: '],
]);

it('TK19 sends a progressToken with withProgress(), and counts the notifications', function () {
    kitServe();
    Gates::allow(ProgressProbeTool::NAME);

    RuntimeMcp::test()->as('sina')->withProgress()->call(ProgressProbeTool::NAME, ['steps' => 3])
        ->assertProgress(3)
        ->assertOk()
        ->assertData(fn (mixed $data): bool => is_array($data) && $data['steps'] === 3)
        ->assertAudited('allowed');

    RuntimeMcp::test()->as('sina')->withProgress()->call(ProgressProbeTool::NAME, ['steps' => 0])->assertProgress(0);

    expect(kitFailure(fn () => RuntimeMcp::test()->as('sina')->withProgress()->call(ProgressProbeTool::NAME, ['steps' => 2])->assertProgress(3)))
        ->toStartWith('The call of [progress_probe] sent 2 progress notifications, not 3.')
        // The principal is still set inside the stream (spec 11.2 "Progress"), and the clock binding is gone again.
        ->and(array_column(AuditLog::entries(), 'principal'))->toBe(['sina', 'sina', 'sina'])
        ->and(app()->bound(ProgressClock::class))->toBeFalse();
});

it('TK20 lists every tool on every page of tools/list', function () {
    kitServe();
    $names = array_map(fn ($adapter): string => $adapter->name(), McpToolAdapters::fromDefinition(app(RuntimeServer::class), app()));
    Gates::allow(...$names);

    $listed = RuntimeMcp::test()->as('sina')->listedTools();

    expect(count($names))->toBeGreaterThan(15)
        ->and($listed)->toEqualCanonicalizing($names)
        ->and($listed)->toHaveCount(count($names));
});

it('TK21 says that Event::fake() hides the audit events, and works when only other events are faked', function () {
    kitServe();
    Gates::allow('echo');
    Event::fake();

    expect(kitFailure(fn () => RuntimeMcp::test()->as('sina')->call('echo', ['text' => 'hi'])->assertAudited()))
        ->toStartWith('The call of [echo] wrote 0 tool_call audit entries, not 1.'.RuntimeMcpResult::EVENTS_FAKED);

    expect(kitFailure(fn () => RuntimeMcp::test()->as('sina')->prompt('no_such_prompt')->assertHidden()))
        ->toStartWith('The prompt [no_such_prompt] wrote 0 denied prompt_get audit entries, not 1.'.RuntimeMcpResult::EVENTS_FAKED);

    Event::fake([AuthFailed::class]);
    RuntimeMcp::test()->as('sina')->call('echo', ['text' => 'hi'])->assertAudited('allowed');
    RuntimeMcp::test()->as('sina')->prompt('no_such_prompt')->assertHidden();
});

it('TK22 shows 01-16\'s note when the test holds a transaction (RefreshDatabase)', function () {
    kitServe();
    Gates::allow('echo');
    DB::beginTransaction();

    try {
        $result = RuntimeMcp::test()->as('sina')->call('echo', ['text' => 'hi']);

        expect(kitFailure(fn () => $result->assertStatus('ok')))->toContain('has status [unavailable], not [ok]. Note: The read-only transaction could not be opened')
            ->and(kitFailure(fn () => $result->assertNote('is already in a transaction (a test with RefreshDatabase')))->toBeNull();
    } finally {
        DB::rollBack();
    }
});

it('TK23 meets the rate limit like any principal', function () {
    kitServe();
    config(['runtime-mcp.rate_limits.per_principal' => '2,1', 'cache.limiter' => 'array']);
    Gates::allow('echo');

    $chain = RuntimeMcp::test()->as('sina');
    $chain->call('echo', ['text' => 'a'])->assertOk();
    $chain->call('echo', ['text' => 'b'])->assertOk();
    $chain->call('echo', ['text' => 'c'])->assertDenied('rate_limited');
});

it('TK24 works with the endpoint off, and registers no route', function () {
    kitServe();

    expect(config('runtime-mcp.enabled'))->toBeFalse()
        ->and(Route::has(McpRoutes::ROUTE_NAME))->toBeFalse();

    kitEcho()->assertOk();

    expect(Route::has(McpRoutes::ROUTE_NAME))->toBeFalse();
});

it('TK25 counts one PHPUnit assertion or more for every passing kit assertion, so no kit test is risky', function () {
    kitServe();
    $result = kitEcho();

    foreach ([
        fn () => $result->assertOk(),
        fn () => $result->assertStatus('ok'),
        fn () => $result->assertData(['text' => 'hi']),
        fn () => $result->assertTruncated(false),
        fn () => $result->assertAudited(),
        fn () => $result->assertMatchesOutputSchema(),
        fn () => RuntimeMcp::assertToolContract(EchoTool::class),
        fn () => RuntimeMcp::test()->as('sina')->assertListed('echo'),
    ] as $index => $assert) {
        $before = Assert::getCount();
        $assert();

        expect(Assert::getCount())->toBeGreaterThan($before, "assertion {$index}");
    }
});

it('TK26 forgets a principal that something else left in the container before it sends', function () {
    kitServe();
    Gates::allow('echo');
    app(CurrentPrincipal::class)->set(new Principal('left-behind', AuthMethod::ApiKey));

    RuntimeMcp::test()->call('echo', ['text' => 'hi'])->assertDenied('no_principal');
});

it('TK27 keeps a ProgressClock that the team bound itself', function () {
    kitServe();
    Gates::allow(ProgressProbeTool::NAME);
    $clock = new SteppingClock(step: 1); // one nanosecond per report: the one-second rule keeps only the first
    app()->instance(ProgressClock::class, $clock);

    RuntimeMcp::test()->as('sina')->withProgress()->call(ProgressProbeTool::NAME, ['steps' => 3])->assertProgress(1);

    expect(app(ProgressClock::class))->toBe($clock);
});

it('TK28 compares assertData() after a JSON round trip, as the envelope is built', function () {
    kitServe();

    kitEcho('paid')->assertData(['text' => OrderStatus::Paid]);
    kitEcho('Zoë/1')->assertData(['text' => 'Zoë/1']);
});

it('TK29 fails on answers the pipeline does not give today: an error with status ok, a schema break, two entries', function (string $case, string $message) {
    $entry = fn (bool $allowed): AuditEntry => AuditEntry::toolCall(environment: 'testing', tool: 'x', params: [], allowed: $allowed, reason: $allowed ? null : Reason::NoGate);
    $envelope = ['environment' => 'testing', 'status' => 'ok', 'note' => null, 'truncated' => false, 'data' => []];

    $failure = kitFailure(fn (): RuntimeMcpResult => match ($case) {
        'ok with isError' => kitResult(['isError' => true, 'structuredContent' => $envelope], $entry(true))->assertOk(),
        'schema' => kitResult(['structuredContent' => [...$envelope, 'status' => 'fine', 'extra' => 1]], $entry(true))->assertMatchesOutputSchema(),
        'two entries' => kitResult(['structuredContent' => $envelope], $entry(true), $entry(true))->assertAudited(),
        'denied without an error' => kitResult(['structuredContent' => $envelope], $entry(false))->assertDenied('no_gate'),
        default => throw new InvalidArgumentException($case),
    });

    expect($failure)->toBeString()->toContain($message);
})->with([
    'assertOk on an error' => ['ok with isError', 'The call of [x] is an error (isError).'],
    'assertMatchesOutputSchema' => ['schema', 'The envelope of [x] does not match the outputSchema: '],
    'assertAudited with two entries' => ['two entries', 'The call of [x] wrote 2 tool_call audit entries, not 1.'],
    'assertDenied on a result that is not an error' => ['denied without an error', 'The denied call of [x] is not an error.'],
]);

it('TK30 counts only tool_call entries for a call, not a prompt_get entry', function () {
    $envelope = ['environment' => 'testing', 'status' => 'ok', 'note' => null, 'truncated' => false, 'data' => []];
    $prompt = AuditEntry::promptGet(environment: 'testing', prompt: 'echo_prompt', params: [], allowed: false, reason: Reason::UnknownPrompt);
    $call = AuditEntry::toolCall(environment: 'testing', tool: 'x', params: [], allowed: true);

    kitResult(['structuredContent' => $envelope], $prompt, $call)->assertAudited('allowed');

    expect(kitFailure(fn () => kitResult(['structuredContent' => $envelope], $prompt)->assertAudited()))
        ->toStartWith('The call of [x] wrote 0 tool_call audit entries, not 1.');
});
