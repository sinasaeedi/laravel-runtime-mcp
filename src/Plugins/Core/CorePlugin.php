<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Plugins\Core;

use SinaSaeedi\RuntimeMcp\Plugins\AbstractPlugin;
use SinaSaeedi\RuntimeMcp\Plugins\Core\Prompts\HealthCheck;
use SinaSaeedi\RuntimeMcp\Plugins\Core\Tools\AppInfo;

/** Base plugin (spec 4.2): no package needed, on by default. Tools: app_info (01-18). */
final class CorePlugin extends AbstractPlugin
{
    public function name(): string
    {
        return 'core';
    }

    public function description(): string
    {
        return 'Facts about the running app: versions, drivers and flags.';
    }

    public function enabledByDefault(): bool
    {
        return true;
    }

    public function toolPrefix(): string
    {
        return 'app_';
    }

    public function requiresReadOnlyConnection(): bool
    {
        return false;
    }

    public function tools(): array
    {
        return [AppInfo::class];
    }

    /** Spec 6.11 (01-36). */
    public function prompts(): array
    {
        return [HealthCheck::class];
    }
}
