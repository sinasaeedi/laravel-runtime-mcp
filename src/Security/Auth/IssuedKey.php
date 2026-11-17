<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Security\Auth;

/**
 * Spec 5.3, 7.2 (01-27): one new key, as KeyIssuer made it. The key is shown once and kept nowhere: this object lives
 * for one command run. The client part (server name, URL, command) is ClientSetup's.
 */
final class IssuedKey
{
    /**
     * @param  non-empty-string  $name  the key name: the principal (spec 5.3)
     * @param  string  $key  "rmcp_" + base64url, shown once
     * @param  string  $pair  "name:<sha256 hex>", the part the server keeps
     * @param  string  $envValue  the whole new RUNTIME_MCP_API_KEYS value: the valid current pairs, then $pair
     * @param  list<string>  $warnings  about the current value (malformed, a name already there), never with a hash
     */
    public function __construct(
        public readonly string $name,
        public readonly string $key,
        public readonly string $pair,
        public readonly string $envValue,
        public readonly array $warnings,
    ) {}
}
