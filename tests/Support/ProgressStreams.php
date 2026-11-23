<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Support;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use SinaSaeedi\RuntimeMcp\Plugins\PluginRegistry;
use SinaSaeedi\RuntimeMcp\Testing\SteppingClock;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Plugins\ProgressProbePlugin;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Tools\ProgressProbeTool;
use SinaSaeedi\RuntimeMcp\Tools\ProgressClock;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 01-37: tools/call with a progressToken over HTTP, and the SSE messages of the answer.
 *
 * Nothing of a streamed call runs before the stream is read: postJson() returns the StreamedResponse unread, and the
 * pipeline and the audit run inside streamedContent(). So read messages() before you look at the audit log.
 */
final class ProgressStreams
{
    /**
     * Serves progress_probe on the real endpoint, with a clock that steps one second per report.
     *
     * @param  array<string, mixed>  $config  more boot-time config, as for bootWith()
     */
    public static function serve(array $config = [], bool $gate = true): void
    {
        currentTest()->bootWith(['runtime-mcp.enabled' => true, 'cache.limiter' => 'array', ...$config]);

        app()->scoped(PluginRegistry::class, fn (Application $app): PluginRegistry => new PluginRegistry(
            $app, $app->make(Repository::class), [ProgressProbePlugin::class],
        ));

        if ($gate) {
            Gates::allow(ProgressProbeTool::NAME);
        }

        self::clock(new SteppingClock);
    }

    /** The clock that every FiberProgress of the next calls reads. */
    public static function clock(ProgressClock $clock): void
    {
        app()->instance(ProgressClock::class, $clock);
    }

    /**
     * One tools/call over HTTP with the default test key; `$meta` is the request _meta (null: none).
     *
     * @param  array<array-key, mixed>  $arguments
     * @param  array<string, mixed>|null  $meta
     * @return TestResponse<Response>
     */
    public static function call(string $name, array $arguments = [], ?array $meta = ['progressToken' => 'abc'], ProtocolEra $era = ProtocolEra::Legacy): TestResponse
    {
        $params = ['name' => $name, 'arguments' => (object) $arguments];

        if ($meta !== null) {
            $params['_meta'] = $meta;
        }

        return currentTest()->mcpPost('tools/call', $params, era: $era)->assertOk();
    }

    /**
     * The JSON-RPC messages of a streamed answer, in order: each "data: <json>" block, decoded.
     *
     * @param  TestResponse<Response>  $response
     * @return list<array<array-key, mixed>>
     */
    public static function messages(TestResponse $response): array
    {
        // Not assert(): zend.assertions is -1 here, so its message would never show.
        if (! $response->baseResponse instanceof StreamedResponse) {
            throw new RuntimeException('The answer is not streamed: '.$response->getContent());
        }

        $messages = [];

        foreach (explode("\n\n", trim($response->streamedContent())) as $block) {
            if (! str_starts_with($block, 'data: ')) {
                throw new RuntimeException('Not an SSE data block: '.$block);
            }

            $message = json_decode(substr($block, 6), true, 512, JSON_THROW_ON_ERROR);
            assert(is_array($message));
            $messages[] = $message;
        }

        return $messages;
    }

    /**
     * The notifications/progress params of a streamed answer, in order.
     *
     * @param  list<array<array-key, mixed>>  $messages
     * @return list<array<array-key, mixed>>
     */
    public static function notifications(array $messages): array
    {
        $params = [];

        foreach ($messages as $message) {
            if (($message['method'] ?? null) === 'notifications/progress') {
                assert(is_array($message['params']));
                $params[] = $message['params'];
            }
        }

        return $params;
    }

    /**
     * The result of the last message, which must be the JSON-RPC response.
     *
     * @param  list<array<array-key, mixed>>  $messages
     * @return array<array-key, mixed>
     */
    public static function result(array $messages): array
    {
        $last = $messages[array_key_last($messages)] ?? [];
        $result = $last['result'] ?? null;

        if (! isset($last['id']) || ! is_array($result)) {
            throw new RuntimeException('The last message is not the result: '.json_encode($last));
        }

        return $result;
    }

    /**
     * The envelope (structuredContent) of the last message.
     *
     * @param  list<array<array-key, mixed>>  $messages
     * @return array<array-key, mixed>
     */
    public static function envelope(array $messages): array
    {
        $envelope = self::result($messages)['structuredContent'] ?? null;
        if (! is_array($envelope)) {
            throw new RuntimeException('The result has no structuredContent.');
        }

        return $envelope;
    }
}
