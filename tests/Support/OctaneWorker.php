<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Support;

use Closure;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Laravel\Octane\ApplicationFactory;
use Laravel\Octane\CurrentApplication;
use Laravel\Octane\Testing\Fakes\FakeClient;
use Laravel\Octane\Testing\Fakes\FakeWorker;
use RuntimeException;
use SinaSaeedi\RuntimeMcp\Tests\TestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * 01-30 (spec 5.13): MCP requests through a real Octane worker (laravel/octane's FakeWorker and FakeClient). The
 * worker builds its own app from tests/Fixtures/Octane/bootstrap/app.php, keeps it, and serves each request on a clone
 * of it, as `octane:start` does; between requests Octane's own listeners flush the scoped instances. The test's app
 * is put back as the current app after the run.
 */
final class OctaneWorker
{
    /** The initial instance that holds the worker app's config (key => value). */
    public const CONFIG = 'runtime-mcp.tests.octane.config';

    /** The initial instance that holds the tool names whose Gate allows every principal. */
    public const GATES = 'runtime-mcp.tests.octane.gates';

    /** The worker app's base config: what TestCase::defineEnvironment() and bootWith() give the test's app. */
    public const BASE = [
        'app.key' => 'base64:a2tra2tra2tra2tra2tra2tra2tra2tra2tra2tra2s=',
        'app.name' => 'Runtime Test',
        'app.url' => 'https://app.test',
        'database.default' => 'testing',
        'database.connections.testing' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true],
        'database.connections.'.TestCase::READ_ONLY_CONNECTION => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
        'cache.limiter' => 'array',
        'runtime-mcp.enabled' => true,
        'runtime-mcp.database.connection' => TestCase::READ_ONLY_CONNECTION,
    ];

    /** Testbench's Laravel skeleton: the base path of the worker app, so it shares the test's storage. */
    public static function skeleton(): string
    {
        $path = realpath(dirname(__DIR__, 2).'/vendor/orchestra/testbench-core/laravel');

        if ($path === false) {
            throw new RuntimeException('Testbench\'s Laravel skeleton is missing.');
        }

        return $path;
    }

    /**
     * Serves the requests, in order, on one worker. Returns the responses in the same order.
     *
     * @param  list<Request>  $requests
     * @param  array<string, mixed>  $config  more config for the worker app
     * @param  list<string>  $gates
     * @param  (Closure(Application): void)|null  $after  runs on the worker's own app after the last request (the app
     *                                                    every request was a clone of; warm services such as `db` are shared)
     * @return list<Response>
     */
    public static function serve(array $requests, array $config = [], array $gates = [], ?Closure $after = null): array
    {
        $test = app();
        $client = new FakeClient($requests);
        $worker = new FakeWorker(new ApplicationFactory(dirname(__DIR__).'/Fixtures/Octane'), $client);

        try {
            $worker->boot([self::CONFIG => [...self::BASE, ...$config], self::GATES => $gates]);
            $worker->run();

            if ($after !== null) {
                $after($worker->application());
            }
        } finally {
            // Octane made the worker's request app the current one (container and facades): give the test its app back.
            CurrentApplication::set($test);
        }

        $errors = is_array($client->errors) ? $client->errors : [];

        if ($errors !== []) {
            throw new RuntimeException('The worker failed: '.implode("\n", array_map(fn (mixed $error): string => is_string($error) ? $error : get_debug_type($error), $errors)));
        }

        $responses = [];

        foreach (is_array($client->responses) ? $client->responses : [] as $response) {
            if ($response instanceof Response) {
                $responses[] = $response;
            }
        }

        return $responses;
    }

    /**
     * One legacy tools/call POST, as an MCP client sends it to the endpoint.
     *
     * @param  array<string, mixed>  $arguments
     */
    public static function call(int $id, string $tool, string $key, array $arguments = []): Request
    {
        $body = json_encode([
            'jsonrpc' => '2.0', 'id' => $id, 'method' => 'tools/call',
            'params' => ['name' => $tool, 'arguments' => (object) $arguments],
        ], JSON_THROW_ON_ERROR);

        return Request::create('https://app.test/mcp', 'POST', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json, text/event-stream',
            'HTTP_AUTHORIZATION' => 'Bearer '.$key,
            'HTTP_MCP_PROTOCOL_VERSION' => TestCase::LEGACY_VERSION,
        ], content: $body);
    }
}
