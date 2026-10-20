<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Doctor\Checks;

use Illuminate\Contracts\Config\Repository;
use SinaSaeedi\RuntimeMcp\Doctor\Check;
use SinaSaeedi\RuntimeMcp\Doctor\CheckResult;

/** Spec 4.5: the endpoint is off unless runtime-mcp.enabled is exactly true. Off is valid, so a warning. */
final class Endpoint implements Check
{
    public function __construct(private readonly Repository $config) {}

    public function title(): string
    {
        return 'Endpoint';
    }

    public function run(): array
    {
        if ($this->config->get('runtime-mcp.enabled') === true) {
            return [CheckResult::pass('RUNTIME_MCP_ENABLED is true.')];
        }

        return [CheckResult::warn(
            'The endpoint is off (RUNTIME_MCP_ENABLED is not true).',
            'Set RUNTIME_MCP_ENABLED=true when agents may connect. The other checks still run.',
        )];
    }
}
