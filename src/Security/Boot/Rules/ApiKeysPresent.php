<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Security\Boot\Rules;

use Illuminate\Contracts\Config\Repository;
use SinaSaeedi\RuntimeMcp\Security\Auth\ApiKey;
use SinaSaeedi\RuntimeMcp\Security\Auth\ApiKeyRing;
use SinaSaeedi\RuntimeMcp\Security\Boot\BootIssue;
use SinaSaeedi\RuntimeMcp\Security\Boot\BootRule;

/**
 * Spec 5.11: the api_key driver with no key. An error, unless auth.stdio_principal is a valid name:
 * then STDIO runs alone and the HTTP endpoint stays off (Sina, 2026-10-02).
 */
final class ApiKeysPresent implements BootRule
{
    public const ID = 'api_keys.present';

    public const ERROR = 'RUNTIME_MCP_API_KEYS is empty. Create a key with `php artisan runtime-mcp:key <name>` '
        .'(in a relaxed environment, set RUNTIME_MCP_ENABLED=false first: artisan cannot start while this error stands), '
        .'or set RUNTIME_MCP_STDIO_PRINCIPAL to use only the STDIO server.';

    public function __construct(private readonly Repository $config) {}

    public function id(): string
    {
        return self::ID;
    }

    public function check(): array
    {
        if ($this->config->get('runtime-mcp.auth.driver') !== 'api_key') {
            return [];
        }

        // A ring with only malformed segments is not empty: api_keys.format reports it.
        if (! ApiKeyRing::fromConfig($this->config)->isEmpty()) {
            return [];
        }

        $principal = $this->config->get('runtime-mcp.auth.stdio_principal');

        if (ApiKey::isValidName($principal)) {
            return [BootIssue::warning(self::ID, "RUNTIME_MCP_API_KEYS is empty, so the HTTP endpoint stays off. The STDIO server runs as `{$principal}`.")];
        }

        return [BootIssue::error(self::ID, self::ERROR)];
    }
}
