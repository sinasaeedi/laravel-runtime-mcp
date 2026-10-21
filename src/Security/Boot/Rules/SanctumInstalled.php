<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Security\Boot\Rules;

use Composer\InstalledVersions;
use Illuminate\Contracts\Config\Repository;
use SinaSaeedi\RuntimeMcp\Security\Boot\BootIssue;
use SinaSaeedi\RuntimeMcp\Security\Boot\BootRule;

/**
 * Spec 5.11: auth.driver is `sanctum`, and laravel/sanctum is not installed. Sanctum is optional
 * (composer.json `suggest`), as every plugin package is.
 */
final class SanctumInstalled implements BootRule
{
    public const ID = 'auth.sanctum.installed';

    public const PACKAGE = 'laravel/sanctum';

    /** @param  string  $package  the container uses the default; a test names a package that is not installed */
    public function __construct(
        private readonly Repository $config,
        private readonly string $package = self::PACKAGE,
    ) {}

    public function id(): string
    {
        return self::ID;
    }

    public function check(): array
    {
        if ($this->config->get('runtime-mcp.auth.driver') !== 'sanctum' || InstalledVersions::isInstalled($this->package)) {
            return [];
        }

        return [BootIssue::error(self::ID, "auth.driver is `sanctum`, but `{$this->package}` is not installed. Run composer require {$this->package}.")];
    }
}
