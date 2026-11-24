<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Support;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use SinaSaeedi\RuntimeMcp\Plugins\Contracts\Plugin;
use SinaSaeedi\RuntimeMcp\Plugins\PluginRegistry;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Plugins\BigResultPlugin;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Plugins\FixtureToolsPlugin;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 01-40: the fixture tools behind the real catalog switch, on the real endpoint, and the answers of search_tools and
 * execute_tools. execute_tools answers over SSE (ToolInvoker streams the array that ExecuteTools::handle() returns), so
 * messages() reads either a JSON answer or an SSE one. Read messages() before you look at the audit log: a streamed
 * answer has run, but its body is sent only when it is read.
 */
final class CatalogCalls
{
    /** The fixture tools that serve() allows unless a test names others. `secret` is not here: it stays hidden. */
    public const ALLOWED = ['echo', 'second_echo', 'throwing', 'big_result'];

    /** The plugins that serve() registers unless a test names others. */
    public const PLUGINS = [FixtureToolsPlugin::class, BigResultPlugin::class];

    /**
     * @param  array<string, mixed>  $config  more boot-time config, as for bootWith()
     * @param  list<string>|null  $allow  the fixture tools whose Gate allows every principal (null: ALLOWED)
     * @param  list<class-string<Plugin>>  $plugins
     */
    public static function serve(array $config = [], ?array $allow = null, ?string $environment = null, array $plugins = self::PLUGINS): void
    {
        currentTest()->bootWith(['runtime-mcp.enabled' => true, 'runtime-mcp.catalog.enabled' => true, ...$config], $environment);

        app()->scoped(PluginRegistry::class, fn (Application $app): PluginRegistry => new PluginRegistry(
            $app, $app->make(Repository::class), $plugins,
        ));

        Gates::allow(...$allow ?? self::ALLOWED);
    }

    /**
     * One execute_tools call over HTTP with the default test key.
     *
     * @param  array<array-key, mixed>  $arguments  the execute_tools arguments, for example ['calls' => [...]]
     * @param  array<string, mixed>|null  $meta
     * @return TestResponse<Response>
     */
    public static function execute(array $arguments, ProtocolEra $era = ProtocolEra::Legacy, ?array $meta = null): TestResponse
    {
        return self::call('execute_tools', $arguments, $era, $meta);
    }

    /**
     * @param  list<array{0: string, 1?: array<array-key, mixed>}>  $calls  [name, arguments]
     * @return array{calls: list<array{name: string, arguments: object}>}
     */
    public static function calls(array $calls): array
    {
        return ['calls' => array_map(fn (array $call): array => ['name' => $call[0], 'arguments' => (object) ($call[1] ?? [])], $calls)];
    }

    /**
     * @param  array<array-key, mixed>  $arguments
     * @param  array<string, mixed>|null  $meta
     * @return TestResponse<Response>
     */
    public static function call(string $name, array $arguments = [], ProtocolEra $era = ProtocolEra::Legacy, ?array $meta = null): TestResponse
    {
        $params = ['name' => $name, 'arguments' => (object) $arguments];

        if ($meta !== null) {
            $params['_meta'] = $meta;
        }

        return currentTest()->mcpPost('tools/call', $params, era: $era)->assertOk();
    }

    /**
     * The JSON-RPC messages of an answer, in order: one for a JSON answer, each "data:" block for an SSE one.
     *
     * @param  TestResponse<Response>  $response
     * @return list<array<array-key, mixed>>
     */
    public static function messages(TestResponse $response): array
    {
        if ($response->baseResponse instanceof StreamedResponse) {
            return ProgressStreams::messages($response);
        }

        $message = $response->json();

        if (! is_array($message)) {
            throw new RuntimeException('The answer is not JSON: '.$response->getContent());
        }

        return [$message];
    }

    /**
     * The result's one text item: the JSON of the batch of execute_tools or the list of search_tools.
     *
     * @param  list<array<array-key, mixed>>  $messages
     */
    public static function text(array $messages): string
    {
        $text = data_get(ProgressStreams::result($messages), 'content.0.text');

        if (! is_string($text)) {
            throw new RuntimeException('The result has no text item.');
        }

        return $text;
    }

    /**
     * The JSON of the result's one text item, decoded.
     *
     * @param  list<array<array-key, mixed>>  $messages
     * @return array<array-key, mixed>
     */
    public static function batch(array $messages): array
    {
        $text = self::text($messages);
        $batch = json_decode($text, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($batch)) {
            throw new RuntimeException('The text item is not a JSON object: '.$text);
        }

        return $batch;
    }

    /**
     * The names in tools/list.
     *
     * @return list<string>
     */
    public static function listed(ProtocolEra $era = ProtocolEra::Legacy): array
    {
        $tools = currentTest()->mcpPost('tools/list', era: $era)->assertOk()->json('result.tools');

        if (! is_array($tools)) {
            throw new RuntimeException('tools/list gave no tools.');
        }

        return array_values(array_map(fn (mixed $tool): string => is_array($tool) && is_string($tool['name'] ?? null) ? $tool['name'] : '?', $tools));
    }

    /**
     * Each audit entry as [tool, decision, reason, outcome], in log order.
     *
     * @return list<array{mixed, mixed, mixed, mixed}>
     */
    public static function audit(): array
    {
        return array_map(fn (array $entry): array => [$entry['tool'] ?? null, $entry['decision'] ?? null, $entry['reason'] ?? null, $entry['outcome'] ?? null], AuditLog::entries());
    }
}
