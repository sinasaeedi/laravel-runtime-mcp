<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Doctor\Checks;

use Illuminate\Contracts\Config\Repository;
use SinaSaeedi\RuntimeMcp\Doctor\Check;
use SinaSaeedi\RuntimeMcp\Doctor\CheckResult;
use SinaSaeedi\RuntimeMcp\Security\Auth\ApiKey;

/**
 * Spec 5.3: STDIO runs as auth.stdio_principal. Unset is valid (STDIO off). A value that is not a
 * valid name also keeps STDIO off, with no boot issue (01-10), so doctor is the only place that says so.
 *
 * A key pasted here by mistake is a valid name (rmcp_ plus 43 base64url characters), and it would be
 * written to every audit entry. So a value with the key prefix fails, and is never printed.
 */
final class StdioPrincipal implements Check
{
    public function __construct(private readonly Repository $config) {}

    public function title(): string
    {
        return 'STDIO principal';
    }

    public function run(): array
    {
        $principal = $this->config->get('runtime-mcp.auth.stdio_principal');

        if ($principal === null || $principal === '') {
            return [CheckResult::pass('RUNTIME_MCP_STDIO_PRINCIPAL is not set, so the STDIO server does not start.')];
        }

        if (is_string($principal) && str_starts_with($principal, ApiKey::PREFIX)) {
            return [CheckResult::fail(
                'RUNTIME_MCP_STDIO_PRINCIPAL looks like an API key. The principal name is written to every audit entry.',
                'Set it to a name such as `local-dev`, and replace the key if it was shared.',
            )];
        }

        if (ApiKey::isValidName($principal)) {
            return [CheckResult::pass("The STDIO server runs as `{$principal}`.")];
        }

        // Never print the value: it is not a name, so it could be anything, a key included.
        return [CheckResult::fail(
            'RUNTIME_MCP_STDIO_PRINCIPAL is not a valid name, so the STDIO server does not start.',
            'Use 1 to 64 letters, digits, dots, dashes or underscores, as for a key name.',
        )];
    }
}
