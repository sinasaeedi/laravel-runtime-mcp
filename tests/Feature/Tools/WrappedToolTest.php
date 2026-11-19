<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use SinaSaeedi\RuntimeMcp\Audit\Events\ToolCalled;
use SinaSaeedi\RuntimeMcp\Audit\Events\ToolDenied;
use SinaSaeedi\RuntimeMcp\Mcp\EnvelopeSchema;
use SinaSaeedi\RuntimeMcp\Mcp\McpToolAdapter;
use SinaSaeedi\RuntimeMcp\Mcp\Wrapped\ResponseConverter;
use SinaSaeedi\RuntimeMcp\Mcp\Wrapped\WrappedTool;
use SinaSaeedi\RuntimeMcp\Mcp\Wrapped\WrappedTools;
use SinaSaeedi\RuntimeMcp\Security\ReadOnly\WriteAttemptExplainer;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Mcp\OrderLookupTool;
use SinaSaeedi\RuntimeMcp\Tests\Support\AuditLog;
use SinaSaeedi\RuntimeMcp\Tests\Support\FixtureTools;
use SinaSaeedi\RuntimeMcp\Tests\Support\Gates;
use SinaSaeedi\RuntimeMcp\Tests\Support\ProgressStreams;
use SinaSaeedi\RuntimeMcp\Tests\Support\ProtocolEra;
use SinaSaeedi\RuntimeMcp\Tests\Support\WriteAttempts;
use SinaSaeedi\RuntimeMcp\Tools\Tier;
use SinaSaeedi\RuntimeMcp\Tools\ToolInput;

/*
 * 01-38, spec 5.16, 11.2 ("Wrapped tools"): an official laravel/mcp tool listed in runtime-mcp.wrapped_tools runs behind
 * the whole pipeline, over HTTP with the base plugins, in both protocol eras. SQLite `testing` is the default
 * connection, as in CustomToolTest; the tables are made after the boot.
 */

beforeEach(fn () => AuditLog::clear());
afterEach(fn () => AuditLog::clear());

/** @return array<string, mixed> one wrapped_tools entry */
function wrappedEntry(string $name = OrderLookupTool::NAME, string $tier = 'read_sensitive', bool $openWorld = false): array
{
    return ['class' => OrderLookupTool::class, 'name' => $name, 'tier' => $tier, 'open_world' => $openWorld];
}

/** @param  array<string, mixed>  $config */
function wrappedServe(bool $gate = true, array $config = []): void
{
    currentTest()->bootWith(['runtime-mcp.enabled' => true, 'runtime-mcp.wrapped_tools' => [wrappedEntry()], ...$config]);
    WriteAttempts::tables('testing');

    if ($gate) {
        Gates::allow(OrderLookupTool::NAME);
    }
}

/** @return array<array-key, mixed> the only audit entry */
function wrappedEntryOnly(): array
{
    $entries = AuditLog::entries();
    expect($entries)->toHaveCount(1);

    return $entries[0];
}

/** @return array<string, array<array-key, mixed>> tools/list by name */
function wrappedList(ProtocolEra $era = ProtocolEra::Legacy): array
{
    $tools = currentTest()->mcpPost('tools/list', era: $era)->assertOk()->json('result.tools');
    assert(is_array($tools));
    $byName = [];

    foreach ($tools as $tool) {
        assert(is_array($tool) && is_string($tool['name'] ?? null));
        $byName[$tool['name']] = $tool;
    }

    return $byName;
}

it('WT1 lists a wrapped tool with our name, title, annotations and outputSchema, and its own schema and description', function (ProtocolEra $era) {
    wrappedServe(config: ['runtime-mcp.wrapped_tools' => [wrappedEntry(), wrappedEntry('app_order_world', 'read', true)]]);
    Gates::allow('app_order_world');

    $tools = wrappedList($era);
    $tool = $tools[OrderLookupTool::NAME] ?? [];
    $properties = data_get($tool, 'inputSchema.properties');

    expect($tool['title'] ?? null)->toBe('App Order Lookup')
        ->and($tool['description'] ?? null)->toBe((new OrderLookupTool)->description()."\n\n".McpToolAdapter::UNTRUSTED)
        ->and($tool['annotations'] ?? null)->toBe(['title' => 'App Order Lookup', 'readOnlyHint' => true, 'openWorldHint' => false])
        ->and($tools['app_order_world']['annotations'] ?? null)->toBe(['title' => 'App Order World', 'readOnlyHint' => true, 'openWorldHint' => true])
        ->and(is_array($properties) ? array_keys($properties) : null)->toBe(['order', 'mode'])
        ->and(data_get($tool, 'inputSchema.required'))->toBe(['order'])
        ->and($tool['outputSchema'] ?? null)->toBe(EnvelopeSchema::toArray())
        ->and($tools)->not->toHaveKey('order-lookup-tool')
        ->and(array_slice(array_keys($tools), -2))->toBe([OrderLookupTool::NAME, 'app_order_world']);
})->with([ProtocolEra::Legacy, ProtocolEra::Modern]);

it('WT2 runs a wrapped tool through the pipeline: the envelope, the label, and one audited success', function (ProtocolEra $era) {
    wrappedServe();
    Event::fake([ToolCalled::class]);

    $response = FixtureTools::call(OrderLookupTool::NAME, ['order' => 7], $era);
    $entry = wrappedEntryOnly();

    expect(FixtureTools::envelope($response))->toBe([
        'environment' => 'testing',
        'status' => 'ok',
        'note' => null,
        'truncated' => false,
        'data' => ['id' => 7],
    ])
        ->and(FixtureTools::text($response, 0))->toStartWith('Result from ')
        ->and(FixtureTools::result($response)['isError'])->toBeFalse()
        ->and($entry['tool'])->toBe(OrderLookupTool::NAME)
        ->and($entry['decision'])->toBe('allowed')
        ->and($entry['outcome'])->toBe('success')
        ->and($entry['params'])->toBe(['order' => 7]);

    Event::assertDispatched(ToolCalled::class, 1);
})->with([ProtocolEra::Legacy, ProtocolEra::Modern]);

it('WT3 turns each kind of official answer into the envelope', function (string $mode, array $expected) {
    wrappedServe();

    $envelope = FixtureTools::envelope(FixtureTools::call(OrderLookupTool::NAME, ['order' => 7, 'mode' => $mode]));

    expect(array_intersect_key($envelope, $expected))->toBe($expected)
        ->and(wrappedEntryOnly()['outcome'])->toBe('success');
})->with([
    'structured' => ['structured', ['status' => 'ok', 'data' => ['id' => 7, 'state' => 'shipped']]],
    'text' => ['text', ['status' => 'ok', 'data' => ['text' => 'Order 7 shipped']]],
    'text and an image' => ['image', ['status' => 'partial', 'note' => ResponseConverter::LEFT_OUT_ONE, 'truncated' => 1, 'data' => ['text' => 'Order 7']]],
    'an error' => ['error', ['status' => 'unavailable', 'note' => 'The tool returned an error: Not found', 'data' => null]],
    'a generator' => ['generator', ['status' => 'ok', 'data' => ['text' => 'Order 7 shipped']]],
    'a Request resolved again' => ['nested', ['status' => 'ok', 'data' => ['nested' => 7]]],
    'no Laravel user' => ['user', ['status' => 'ok', 'data' => ['user' => null]]],
]);

it('WT4 answers the tool\'s own validation failure as invalid input, audited as an error and never reported', function (array $arguments, string $note) {
    wrappedServe();
    Exceptions::fake();

    $envelope = FixtureTools::envelope(FixtureTools::call(OrderLookupTool::NAME, $arguments));
    $entry = wrappedEntryOnly();

    expect($envelope['status'])->toBe('unavailable')
        ->and($envelope['note'])->toBe($note)
        ->and($entry['outcome'])->toBe('error')
        ->and($entry['error_class'] ?? null)->toBe(ValidationException::class);

    Exceptions::assertNothingReported();
})->with([
    'missing' => [[], 'Invalid input: The order field is required.'],
    'too small' => [['order' => 0], 'Invalid input: The order field must be at least 1.'],
    'a wrong mode' => [['order' => 7, 'mode' => 'evil'], 'Invalid input: The selected mode is invalid.'],
]);

it('WT5 refuses an argument that the official schema does not name, before the tool\'s code runs', function () {
    wrappedServe();

    $envelope = FixtureTools::envelope(FixtureTools::call(OrderLookupTool::NAME, ['order' => 7, 'evil' => 1]));

    expect($envelope['note'])->toBe('Invalid input: The evil argument is not allowed.')
        ->and(app()->bound(OrderLookupTool::RAN))->toBeFalse();
});

it('WT6 blocks a database write from a wrapped tool with the write note, and changes nothing', function () {
    wrappedServe();
    Exceptions::fake(); // RunTool reports the blocked write

    $envelope = FixtureTools::envelope(FixtureTools::call(OrderLookupTool::NAME, ['order' => 7, 'mode' => 'insert']));

    expect($envelope['status'])->toBe('unavailable')
        ->and($envelope['note'])->toBe(WriteAttemptExplainer::WRITE)
        ->and(WriteAttempts::count('testing', 'ro_orders'))->toBe(1)
        ->and(wrappedEntryOnly()['outcome'])->toBe('error');
});

it('WT7 redacts a secret that a wrapped tool returns', function () {
    wrappedServe();

    $response = FixtureTools::call(OrderLookupTool::NAME, ['order' => 7, 'mode' => 'jwt']);

    expect(FixtureTools::envelope($response)['data'])->toBe(['id' => 7, 'token' => '[redacted:jwt]'])
        ->and(str_contains((string) $response->getContent(), 'eyJ'))->toBeFalse();
});

it('WT8 hides and denies a wrapped tool that has no Gate, and audits the call', function (ProtocolEra $era) {
    wrappedServe(gate: false);
    Event::fake([ToolDenied::class]);

    $response = FixtureTools::call(OrderLookupTool::NAME, ['order' => 7], $era);
    $entry = wrappedEntryOnly();

    expect(wrappedList($era))->not->toHaveKey(OrderLookupTool::NAME)
        ->and(FixtureTools::envelope($response)['note'])->toBe('Access denied: the app defines no Gate `runtime-mcp.app_order_lookup`, so this tool is off (default deny).')
        ->and($entry['decision'])->toBe('denied')
        ->and($entry['reason'])->toBe('no_gate')
        ->and(app()->bound(OrderLookupTool::RAN))->toBeFalse();

    Event::assertDispatched(ToolDenied::class, 1);
})->with([ProtocolEra::Legacy, ProtocolEra::Modern]);

it('WT9 hides a wrapped tool whose own shouldRegister() says no, and denies a direct call as not_available', function (ProtocolEra $era) {
    wrappedServe();
    app()->instance(OrderLookupTool::HIDE, true);
    Event::fake([ToolDenied::class]);

    $response = FixtureTools::call(OrderLookupTool::NAME, ['order' => 7], $era);
    $entry = wrappedEntryOnly();

    expect(wrappedList($era))->not->toHaveKey(OrderLookupTool::NAME)
        ->and(FixtureTools::result($response)['isError'])->toBeTrue()
        ->and(FixtureTools::envelope($response)['status'])->toBe('unavailable')
        ->and(FixtureTools::envelope($response)['note'])->toBe(WrappedTool::NOT_AVAILABLE)
        ->and($entry['decision'])->toBe('denied')
        ->and($entry['reason'])->toBe('not_available')
        ->and(app()->bound(OrderLookupTool::RAN))->toBeFalse();

    Event::assertDispatched(ToolDenied::class, 1);
})->with([ProtocolEra::Legacy, ProtocolEra::Modern]);

it('WT10 hides a wrapped tool whose own shouldRegister() throws, reports it, and still answers tools/list', function () {
    wrappedServe();
    Gates::allow('app_info');
    app()->instance(OrderLookupTool::THROW, true);
    Exceptions::fake();

    $tools = wrappedList();
    $entry = FixtureTools::call(OrderLookupTool::NAME, ['order' => 7]);

    expect($tools)->not->toHaveKey(OrderLookupTool::NAME)
        ->and($tools)->toHaveKey('app_info')
        ->and(FixtureTools::envelope($entry)['note'])->toBe(WrappedTool::NOT_AVAILABLE)
        ->and(wrappedEntryOnly()['reason'])->toBe('not_available');

    Exceptions::assertReported(RuntimeException::class);
});

it('WT11 asks the Gate first: a denied principal never runs the tool\'s own shouldRegister()', function () {
    wrappedServe(gate: false);
    app()->instance(OrderLookupTool::THROW, true);
    Exceptions::fake();

    expect(wrappedList())->not->toHaveKey(OrderLookupTool::NAME);

    Exceptions::assertNothingReported();
});

it('WT12 gives the tool its arguments inside a progress stream, where laravel/mcp has forgotten its own request', function (string $mode, array $data) {
    wrappedServe(config: ['cache.limiter' => 'array']);

    $messages = ProgressStreams::messages(ProgressStreams::call(OrderLookupTool::NAME, ['order' => 7, 'mode' => $mode]));
    $result = ProgressStreams::result($messages);
    $envelope = $result['structuredContent'] ?? null;

    expect(is_array($envelope) ? $envelope['data'] : null)->toBe($data)
        ->and(ProgressStreams::notifications($messages))->toBe([])
        ->and(wrappedEntryOnly()['params'])->toBe(['order' => 7, 'mode' => $mode]);
})->with([
    'its handle() Request' => ['json', ['id' => 7]],
    'a Request resolved again' => ['nested', ['nested' => 7]],
]);

it('WT13 puts back the mcp.request that was bound before the call', function () {
    wrappedServe();
    $outer = new Request(['order' => 99]);
    app()->instance('mcp.request', $outer);

    $tool = app(WrappedTools::class)->build()[0];
    $result = $tool->execute(new ToolInput(['order' => 7, 'mode' => 'nested']));

    expect($result->data)->toBe(['nested' => 7])
        ->and(app()->make('mcp.request') === $outer)->toBeTrue();
});

it('WT14 leaves no mcp.request behind when none was bound', function () {
    wrappedServe();
    app()->forgetInstance('mcp.request');

    $tool = app(WrappedTools::class)->build()[0];
    $tool->execute(new ToolInput(['order' => 7]));

    expect(app()->bound('mcp.request'))->toBeFalse();
});

it('WT15 builds the wrapped tool from its entry: one sometimes rule per schema property, and the config values', function () {
    wrappedServe(config: ['runtime-mcp.wrapped_tools' => [wrappedEntry('app_order_world', 'read', true)]]);

    $tool = app(WrappedTools::class)->build()[0];

    expect($tool->rules())->toBe(['order' => ['sometimes'], 'mode' => ['sometimes']])
        ->and($tool->name())->toBe('app_order_world')
        ->and($tool->tier())->toBe(Tier::Read)
        ->and($tool->opensWorld())->toBeTrue()
        ->and($tool->wrappedClass())->toBe(OrderLookupTool::class)
        ->and($tool->ability())->toBe('runtime-mcp.app_order_world');
});

it('WT16 denies a call to a hidden wrapped tool as not_available before the arguments are checked, also with an argument its schema does not name', function (ProtocolEra $era) {
    wrappedServe();
    app()->instance(OrderLookupTool::HIDE, true);
    Event::fake([ToolDenied::class]);

    $response = FixtureTools::call(OrderLookupTool::NAME, ['order' => 7, 'evil' => 1], $era);
    $entry = wrappedEntryOnly();

    expect(FixtureTools::envelope($response)['status'])->toBe('unavailable')
        ->and(FixtureTools::envelope($response)['note'])->toBe(WrappedTool::NOT_AVAILABLE)
        ->and($entry['decision'])->toBe('denied')
        ->and($entry['reason'])->toBe('not_available')
        ->and(app()->bound(OrderLookupTool::RAN))->toBeFalse();

    Event::assertDispatched(ToolDenied::class, 1);
})->with([ProtocolEra::Legacy, ProtocolEra::Modern]);
