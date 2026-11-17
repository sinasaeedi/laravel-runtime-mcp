<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Security\Auth;

use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;

/**
 * Spec 5.3, 7.2 (01-27): makes a key and the RUNTIME_MCP_API_KEYS value that holds it. It writes nothing: the
 * operator sets the value in this server's .env (rule 6), and the server keeps only the hash.
 *
 * Reads the current value from the config, so under config:cache it is the cached one (the command says so).
 */
final class KeyIssuer
{
    public const NAME_RULE = 'A key name can use only letters, digits, ".", "_" and "-", and at most 64 characters.';

    public const PREFIX_RULE = 'A key name cannot start with "rmcp_": that is the prefix of a key.';

    public function __construct(private readonly Repository $config) {}

    /** @throws InvalidArgumentException with NAME_RULE or PREFIX_RULE */
    public function issue(string $name): IssuedKey
    {
        if (! ApiKey::isValidName($name)) {
            throw new InvalidArgumentException(stripos($name, ApiKey::PREFIX) === 0 ? self::PREFIX_RULE : self::NAME_RULE);
        }

        $key = ApiKey::generate();
        $pair = "{$name}:".ApiKey::hash($key);
        $ring = ApiKeyRing::fromConfig($this->config);
        $warnings = [];

        if (! $ring->isValid()) {
            // Never echo a malformed value: it can hold anything, even a raw key pasted by mistake.
            $positions = $ring->malformedPositions();
            $entries = (count($positions) === 1 ? 'entry ' : 'entries ').implode(', ', $positions);
            $warnings[] = "The current RUNTIME_MCP_API_KEYS value is malformed ({$entries}). Fix it, then append this pair.";

            return new IssuedKey($name, $key, $pair, $pair, $warnings);
        }

        $pairs = $ring->pairs();

        foreach ($pairs as $existing) {
            if (strstr($existing, ':', true) === $name) {
                $warnings[] = "A key named \"{$name}\" already exists. This adds a second key for rotation; remove the old pair once the new key works.";

                break;
            }
        }

        return new IssuedKey($name, $key, $pair, implode(',', [...$pairs, $pair]), $warnings);
    }
}
