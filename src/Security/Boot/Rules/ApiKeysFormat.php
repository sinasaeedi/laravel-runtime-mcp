<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Security\Boot\Rules;

use Illuminate\Contracts\Config\Repository;
use SinaSaeedi\RuntimeMcp\Security\Auth\ApiKeyRing;
use SinaSaeedi\RuntimeMcp\Security\Boot\BootIssue;
use SinaSaeedi\RuntimeMcp\Security\Boot\BootRule;

/**
 * Spec 5.11: a key entry that is not "name:<64 hex>". For every driver, as the spec says. The
 * message gives positions only, never the text of an entry.
 */
final class ApiKeysFormat implements BootRule
{
    public const ID = 'api_keys.format';

    public function __construct(private readonly Repository $config) {}

    public function id(): string
    {
        return self::ID;
    }

    public function check(): array
    {
        $positions = ApiKeyRing::fromConfig($this->config)->malformedPositions();

        if ($positions === []) {
            return [];
        }

        $message = count($positions) === 1
            ? "RUNTIME_MCP_API_KEYS entry {$positions[0]} is not in the name:sha256hex format."
            : 'RUNTIME_MCP_API_KEYS entries '.self::listing($positions).' are not in the name:sha256hex format.';

        return [BootIssue::error(self::ID, $message)];
    }

    /** @param  non-empty-list<int>  $positions  "2 and 4", "2, 4 and 7" */
    private static function listing(array $positions): string
    {
        $last = array_pop($positions);

        return implode(', ', $positions).' and '.$last;
    }
}
