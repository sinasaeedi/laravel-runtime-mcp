<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Mcp\Testing;

use Illuminate\Contracts\Container\Container;
use Laravel\Mcp\Exceptions\JsonRpcException;
use Laravel\Mcp\Server\Transport\FakeTransporter;
use Laravel\Mcp\Transport\JsonRpcRequest;
use Laravel\Mcp\Transport\JsonRpcResponse;
use LogicException;
use ReflectionMethod;
use SinaSaeedi\RuntimeMcp\Mcp\McpServer;

/**
 * The laravel/mcp side of the test kit (spec 4.1, 7.5; 01-39 Part B). It sends one JSON-RPC request to a fresh McpServer
 * without HTTP, the way laravel/mcp's own Server::tool() does inside (PendingTestResponse::executeRequest(): the server's
 * runMethodHandle() with its createContext()), and reads the whole answer. So every request reaches our tools/call and
 * prompts/get handlers (Methods\CallTool, Methods\GetPrompt) and the full pipeline; only the HTTP middleware (Host
 * check, auth) is skipped.
 *
 * Not Server::tool() itself: it sends no _meta (so no progressToken, 01-37), and it needs a Tool object, which an
 * unknown name does not have. Not TestResponse: its response is protected, and a macro would write TestResponse's
 * static $macros. The answer goes back as plain arrays, so src/Testing/ never sees a laravel/mcp object.
 */
final class McpTestDriver
{
    /** The JSON-RPC id of every kit request. */
    public const REQUEST_ID = 'runtime-mcp-test';

    public function __construct(private readonly Container $container) {}

    /**
     * Sends one request and reads every message of the answer. A streamed answer (01-37) runs the pipeline, and so the
     * audit, only while it is read, so it is read to its end here.
     *
     * @param  array<string, mixed>  $params
     * @return array{response: array<array-key, mixed>, notifications: list<array<array-key, mixed>>}
     */
    public function send(string $method, array $params): array
    {
        $server = $this->container->make(McpServer::class, ['transport' => new FakeTransporter]);
        $server->start();

        try {
            $answer = (new ReflectionMethod($server, 'runMethodHandle'))
                ->invoke($server, new JsonRpcRequest(self::REQUEST_ID, $method, $params), $server->createContext());
        } catch (JsonRpcException $e) {
            // An unknown tool or prompt, a missing name: laravel/mcp's own JSON-RPC error, as executeRequest() gives.
            $answer = $e->toJsonRpcResponse();
        }

        $response = null;
        $notifications = [];

        // TestResponse::__construct()'s rule: the message with an id is the response, every other one a notification.
        foreach (is_iterable($answer) ? $answer : [$answer] as $message) {
            if (! $message instanceof JsonRpcResponse) {
                throw new LogicException('The server answered with something that is not a JSON-RPC message.');
            }

            $array = $message->toArray();

            if (array_key_exists('id', $array)) {
                $response = $array;
            } else {
                $notifications[] = $array;
            }
        }

        return [
            'response' => $response ?? throw new LogicException("The server sent no response to [{$method}]."),
            'notifications' => $notifications,
        ];
    }

    /**
     * The names in tools/list, every page: laravel/mcp shows 15 tools per page by default and gives a nextCursor.
     *
     * @return list<string>
     */
    public function listedTools(): array
    {
        $names = [];
        $cursor = null;

        do {
            $response = $this->send('tools/list', $cursor === null ? [] : ['cursor' => $cursor])['response'];
            $tools = data_get($response, 'result.tools');

            if (! is_array($tools)) {
                throw new LogicException('tools/list gave no list of tools.');
            }

            foreach ($tools as $tool) {
                $name = is_array($tool) ? ($tool['name'] ?? null) : null;
                $names[] = is_string($name) ? $name : throw new LogicException('tools/list gave a tool without a name.');
            }

            $next = data_get($response, 'result.nextCursor');
            $cursor = is_string($next) ? $next : null;
        } while ($cursor !== null);

        return $names;
    }
}
