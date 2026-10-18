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

    /** The default key of every HTTP test (01-06): 'rmcp_' + base64url of 32 bytes of 0x01. */
    public const TEST_KEY = 'rmcp_AQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQE';

    public const TEST_KEY_NAME = 'tests';

    /** The RUNTIME_MCP_API_KEYS value that holds TEST_KEY. bootWith() sets it unless a test overrides it. */
    public static function defaultKeyEntry(): string
    {
        return self::TEST_KEY_NAME.':'.hash('sha256', self::TEST_KEY);
    }

    private int $mcpRequestId = 0;

    /**
     * @param  array<string, mixed>  $params
     * @param  array<string, string>  $headers  extra headers; they win over the ones added here
     * @param  string|null  $key  sent as "Authorization: Bearer <key>"; null sends no Authorization header
     * @return TestResponse<Response>
     */
    public function mcpPost(
        string $method,
        array $params = [],
        array $headers = [],
        ProtocolEra $era = ProtocolEra::Legacy,
        string $path = '/mcp',
        ?string $key = self::TEST_KEY,
    ): TestResponse {
        $base = ['Accept' => 'application/json, text/event-stream'];

        if ($key !== null) {
            $base['Authorization'] = 'Bearer '.$key;
        }

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
