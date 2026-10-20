<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Doctor\Checks;

use Illuminate\Contracts\Config\Repository;
use SinaSaeedi\RuntimeMcp\Doctor\Check;
use SinaSaeedi\RuntimeMcp\Doctor\CheckResult;
use SinaSaeedi\RuntimeMcp\Security\Http\HostRules;

/**
 * Spec 5.4, 01-07: each allowed_origins entry must be an origin, or it never matches. Positions only,
 * 1-based, never the value (Sina, 2026-10-20): the list can come from .env.
 */
final class AllowedOrigins implements Check
{
    public function __construct(private readonly Repository $config) {}

    public function title(): string
    {
        return 'Allowed origins';
    }

    public function run(): array
    {
        $origins = $this->config->get('runtime-mcp.allowed_origins');

        if (! is_array($origins) || ! array_is_list($origins)) {
            return [CheckResult::fail(
                'runtime-mcp.allowed_origins is not a list, so every browser origin is refused.',
                'Set it to a list of origins such as https://admin.example.com, or to [].',
            )];
        }

        $results = [];

        foreach ($origins as $index => $entry) {
            if (! is_string($entry) || HostRules::normaliseOrigin($entry) === null) {
                $results[] = CheckResult::fail(
                    'allowed_origins entry '.($index + 1).' is not scheme://host[:port], so it never matches.',
                    'Write it as https://example.com or https://example.com:8443, with no path.',
                );
            }
        }

        if (in_array('null', HostRules::fromConfig($this->config)->origins(), true)) {
            $results[] = CheckResult::warn(
                'allowed_origins lists `null`, so sandboxed pages and local files can reach the endpoint.',
                'Remove `null` from runtime-mcp.allowed_origins.',
            );
        }

        if ($results !== []) {
            return $results;
        }

        return [CheckResult::pass(match (count($origins)) {
            0 => 'No browser origin is allowed. Clients without an Origin header are not affected.',
            1 => '1 browser origin is allowed.',
            default => count($origins).' browser origins are allowed.',
        })];
    }
}
