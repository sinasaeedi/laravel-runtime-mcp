<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Doctor\Checks;

use Illuminate\Contracts\Config\Repository;
use SinaSaeedi\RuntimeMcp\Doctor\Check;
use SinaSaeedi\RuntimeMcp\Doctor\CheckResult;
use SinaSaeedi\RuntimeMcp\Security\Http\HostRules;

/**
 * Spec 5.4, 01-07: the resolved host list. Empty refuses every request (fail closed), and no boot
 * rule reports it, so doctor does. Counts only: a host can come from .env.
 */
final class AllowedHosts implements Check
{
    public function __construct(private readonly Repository $config) {}

    public function title(): string
    {
        return 'Allowed hosts';
    }

    public function run(): array
    {
        $configured = $this->config->get('runtime-mcp.allowed_hosts');

        if ($configured !== null && (! is_array($configured) || ! array_is_list($configured))) {
            return [CheckResult::fail(
                'runtime-mcp.allowed_hosts is not a list, so every request is refused.',
                'Set it to a list of host or host:port names, or to null for the host of APP_URL.',
            )];
        }

        $hosts = HostRules::fromConfig($this->config)->hosts();

        if ($hosts === []) {
            return [CheckResult::fail('Every request is refused.', 'Set APP_URL or runtime-mcp.allowed_hosts.')];
        }

        $source = $configured === null ? 'the host of APP_URL' : 'runtime-mcp.allowed_hosts';

        return [CheckResult::pass(count($hosts) === 1 ? "1 host is allowed ({$source})." : count($hosts)." hosts are allowed ({$source}).")];
    }
}
