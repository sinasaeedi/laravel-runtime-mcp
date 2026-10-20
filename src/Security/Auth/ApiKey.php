<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Security\Auth;

/**
 * Spec 5.3: the key format. A key is 32 random bytes, shown as "rmcp_" + base64url (no padding).
 *
 * Static methods only, no static property (rule 14). 01-27 (runtime-mcp:key) builds on generate()
 * and hash(); 01-10 and 01-28 use NAME_PATTERN through isValidName().
 */
final class ApiKey
{
    public const PREFIX = 'rmcp_';

    /** "rmcp_" (5) + base64url of 32 bytes without padding (43). */
    public const LENGTH = 48;

    /** A key name: the principal in every audit entry. The D modifier: "$" never accepts a trailing newline. */
    public const NAME_PATTERN = '/^[A-Za-z0-9._-]{1,64}$/D';

    /** One RUNTIME_MCP_API_KEYS segment, already trimmed: "name:<64 hex>". */
    public const ENTRY_PATTERN = '/^([A-Za-z0-9._-]{1,64}):([0-9a-fA-F]{64})$/D';

    public static function generate(): string
    {
        return self::PREFIX.rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /** Lower-case hex SHA-256 of the WHOLE key, prefix included. */
    public static function hash(string $key): string
    {
        return hash('sha256', $key);
    }

    /**
     * A valid name is never empty. The "=" narrows only for true: a non-empty string can still be
     * invalid, so false must not mean "not a non-empty string" (a plain assert would say so).
     *
     * A name never starts with the key prefix, in any case (01-12): a key pasted as a name matches
     * NAME_PATTERN, and the name is written to every audit entry and event, and to the boot log.
     *
     * @phpstan-assert-if-true =non-empty-string $name
     */
    public static function isValidName(mixed $name): bool
    {
        return is_string($name)
            && preg_match(self::NAME_PATTERN, $name) === 1
            && stripos($name, self::PREFIX) !== 0;
    }
}
