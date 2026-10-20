<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Doctor\Checks;

use Illuminate\Contracts\Config\Repository;
use SinaSaeedi\RuntimeMcp\Doctor\Check;
use SinaSaeedi\RuntimeMcp\Doctor\CheckResult;

/**
 * Spec 5.3, 5.11: the auth driver. Until 01-35 there is no auth.driver boot rule, and only api_key
 * works: any other driver makes AuthenticateApiKey refuse every request (closed). 01-35 adds the
 * sanctum driver and its boot rules, and changes this check.
 */
final class AuthDriver implements Check
{
    public const SUPPORTED = ['api_key'];

    public function __construct(private readonly Repository $config) {}

    public function title(): string
    {
        return 'Auth driver';
    }

    public function run(): array
    {
        $driver = $this->config->get('runtime-mcp.auth.driver');

        if (is_string($driver) && in_array($driver, self::SUPPORTED, true)) {
            return [CheckResult::pass("auth.driver is `{$driver}`.")];
        }

        // Print the value only when it looks like a driver name: never an arbitrary config string.
        $shown = is_string($driver) && preg_match('/^[a-z0-9_]{1,32}$/D', $driver) === 1 ? "`{$driver}`" : 'not a driver name';

        return [CheckResult::fail(
            "auth.driver is {$shown}, so every request is refused. This version supports only `api_key`.",
            'Set runtime-mcp.auth.driver to `api_key`.',
        )];
    }
}
