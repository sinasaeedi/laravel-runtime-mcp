<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Support;

use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * One JSON-RPC POST to the MCP endpoint, in either protocol era. Used by every HTTP test.
 */
trait McpRequests
{
    public const MODERN_VERSION = '2026-07-28';

    public const LEGACY_VERSION = '2025-11-25';

    private int $mcpRequestId = 0;

    /**
     * @param  array<string, mixed>  $params
     * @param  array<string, string>  $headers  extra headers; they win over the ones added here
     * @return TestResponse<Response>
     */
    public function mcpPost(
        string $method,
        array $params = [],
        array $headers = [],
        ProtocolEra $era = ProtocolEra::Legacy,
        string $path = '/mcp',
    ): TestResponse {
        $base = ['Accept' => 'application/json, text/event-stream'];

        if ($era === ProtocolEra::Modern) {
            $meta = is_array($params['_meta'] ?? null) ? $params['_meta'] : [];
            $params['_meta'] = [
                'io.modelcontextprotocol/protocolVersion' => self::MODERN_VERSION,
                'io.modelcontextprotocol/clientCapabilities' => (object) [],
                'io.modelcontextprotocol/clientInfo' => ['name' => 'runtime-mcp-tests', 'version' => '1.0.0'],
                ...$meta,
            ];
            $base['MCP-Protocol-Version'] = self::MODERN_VERSION;
            $base['Mcp-Method'] = $method;

            // ValidateMcpHeaders needs Mcp-Name only where JsonRpcRequest::requiresName() is true.
            if (in_array($method, ['tools/call', 'prompts/get'], true) && is_string($params['name'] ?? null)) {
                $base['Mcp-Name'] = $params['name'];
            }
        } elseif ($method === 'initialize') {
            $params += [
                'protocolVersion' => self::LEGACY_VERSION,
                'capabilities' => (object) [],
                'clientInfo' => ['name' => 'runtime-mcp-tests', 'version' => '1.0.0'],
            ];
        } else {
            // A real legacy client sends the negotiated version after the handshake.
            $base['MCP-Protocol-Version'] = self::LEGACY_VERSION;
        }

        $body = ['jsonrpc' => '2.0', 'id' => ++$this->mcpRequestId, 'method' => $method];

        if ($params !== []) {
            $body['params'] = $params;
        }

        return $this->postJson($path, $body, [...$base, ...$headers]);
    }
}
