<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Security\Auth;

use Closure;
use Illuminate\Contracts\Config\Repository;

/**
 * Spec 5.3: the "name:sha256hex,name:sha256hex" pairs of RUNTIME_MCP_API_KEYS.
 *
 * Built per use (fromConfig()), never bound in the container, so it never holds a stale config.
 * It keeps no segment text: only the parsed names and hashes, and the positions of bad segments.
 */
final class ApiKeyRing
{
    /** @var list<array{string, string}> name and lower-case hash, in config order */
    private array $entries = [];

    /** @var list<int> 1-based positions of the bad segments, empty segments counted */
    private array $malformed = [];

    /** @var Closure(string, string): bool */
    private readonly Closure $equals;

    /**
     * @param  mixed  $value  the config value: null or '' is an empty ring; another non-string is malformed
     * @param  (Closure(string, string): bool)|null  $equals  the compare; hash_equals() unless a test counts calls
     */
    public function __construct(mixed $value, ?Closure $equals = null)
    {
        $this->equals = $equals ?? hash_equals(...);

        if ($value === null || $value === '') {
            return;
        }

        if (! is_string($value)) {
            $this->malformed = [1];

            return;
        }

        foreach (explode(',', $value) as $index => $segment) {
            $segment = trim($segment);

            if ($segment === '') {
                continue; // a trailing comma or ", ," is harmless
            }

            // The name is the principal: a key-shaped name is malformed too (01-12, isValidName()).
            if (preg_match(ApiKey::ENTRY_PATTERN, $segment, $match) !== 1 || ! ApiKey::isValidName($match[1])) {
                $this->malformed[] = $index + 1;

                continue;
            }

            $this->entries[] = [$match[1], strtolower($match[2])];
        }
    }

    public static function fromConfig(Repository $config): self
    {
        return new self($config->get('runtime-mcp.auth.api_keys'));
    }

    /** No segment at all, valid or not (01-10: "RUNTIME_MCP_API_KEYS is empty"). */
    public function isEmpty(): bool
    {
        return $this->entries === [] && $this->malformed === [];
    }

    public function isValid(): bool
    {
        return $this->malformed === [];
    }

    /** @return list<int> for doctor and boot validation: positions only, never the text */
    public function malformedPositions(): array
    {
        return $this->malformed;
    }

    /**
     * The name of the entry whose hash matches, or null. Compares every entry, with no early
     * return, so the work is the same for the first key, the last key and a wrong key. A ring with
     * a malformed segment matches nothing (01-10 keeps the endpoint off; this is the second lock).
     */
    public function match(string $presentedKey): ?string
    {
        if (! $this->isValid()) {
            return null;
        }

        $presentedHash = ApiKey::hash($presentedKey);
        $matched = null;

        foreach ($this->entries as [$name, $hash]) {
            // hash_equals(known, user): the stored hash first.
            if (($this->equals)($hash, $presentedHash)) {
                $matched ??= $name;
            }
        }

        return $matched;
    }
}
