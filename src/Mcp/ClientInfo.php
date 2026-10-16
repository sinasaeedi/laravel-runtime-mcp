<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Mcp;

use Laravel\Mcp\Enums\MetaKey;

/**
 * Spec 5.7: the MCP client name and version, for the audit. Only a 2026-07-28 request carries them,
 * in _meta under io.modelcontextprotocol/clientInfo, and even there they are optional. A legacy
 * client sends them only in initialize, and the server keeps no session (spec 3.2), so its calls
 * have no client. The audit never guesses one.
 */
final class ClientInfo
{
    public const MAX_NAME = 100;

    public const MAX_VERSION = 50;

    /**
     * @param  array<array-key, mixed>|null  $meta  Laravel\Mcp\Request::meta() or JsonRpcRequest::meta()
     * @return array{name: string, version: ?string}|null
     */
    public static function from(?array $meta): ?array
    {
        $info = $meta[MetaKey::CLIENT_INFO->value] ?? null;

        if (! is_array($info) || ! isset($info['name']) || ! is_string($info['name']) || $info['name'] === '') {
            return null;
        }

        $version = $info['version'] ?? null;

        if ($version !== null && ! is_string($version)) {
            return null;
        }

        return [
            'name' => mb_substr($info['name'], 0, self::MAX_NAME),
            'version' => $version === null ? null : mb_substr($version, 0, self::MAX_VERSION),
        ];
    }
}
