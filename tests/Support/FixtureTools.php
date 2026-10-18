<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Support;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Testing\TestResponse;
use SinaSaeedi\RuntimeMcp\Plugins\PluginRegistry;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Plugins\FixtureToolsPlugin;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves the pipeline fixture tools (01-08) on the real endpoint, behind the real middleware.
 * laravel/mcp builds McpServer through the container per request, so the registry swap after the
 * boot is what each request sees (01-03).
 */
final class FixtureTools
{
    /**
     * @param  array<string, mixed>  $config  more boot-time config, as for bootWith()
     * @param  bool  $gates  allow every fixture tool (01-09); false for the tests of the Gate itself
     */
    public static function serve(array $config = [], ?string $environment = null, bool $gates = true): void
    {
        currentTest()->bootWith(['runtime-mcp.enabled' => true, ...$config], $environment);

        app()->scoped(PluginRegistry::class, fn (Application $app): PluginRegistry => new PluginRegistry(
            $app, $app->make(Repository::class), [FixtureToolsPlugin::class],
        ));

        if ($gates) {
            Gates::allowFixtureTools();
        }
    }

    /**
     * One tools/call over HTTP with the default test key.
     *
     * @param  array<array-key, mixed>  $arguments
     * @return TestResponse<Response>
     */
    public static function call(string $name, array $arguments = [], ProtocolEra $era = ProtocolEra::Legacy): TestResponse
    {
        return currentTest()->mcpPost('tools/call', ['name' => $name, 'arguments' => (object) $arguments], era: $era)->assertOk();
    }

    /**
     * The result of a tools/call response.
     *
     * @param  TestResponse<Response>  $response
     * @return array<array-key, mixed>
     */
    public static function result(TestResponse $response): array
    {
        $result = $response->json('result');
        assert(is_array($result), 'The response has no result: '.$response->getContent());

        return $result;
    }

    /**
     * The text of one content item of a tools/call response: 0 is the label, 1 the structured text.
     *
     * @param  TestResponse<Response>  $response
     */
    public static function text(TestResponse $response, int $index): string
    {
        $text = $response->json("result.content.{$index}.text");
        assert(is_string($text), "Content item {$index} has no text: ".$response->getContent());

        return $text;
    }

    /**
     * The structuredContent of a tools/call response: the envelope.
     *
     * @param  TestResponse<Response>  $response
     * @return array<array-key, mixed>
     */
    public static function envelope(TestResponse $response): array
    {
        $envelope = self::result($response)['structuredContent'] ?? null;
        assert(is_array($envelope), 'The result has no structuredContent: '.$response->getContent());

        return $envelope;
    }
}
