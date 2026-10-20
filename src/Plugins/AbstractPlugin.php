<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Plugins;

use SinaSaeedi\RuntimeMcp\Plugins\Contracts\Plugin;

/**
 * The safe defaults. A plugin overrides only what it needs. A plugin that forgets
 * enabledByDefault() stays off (principle 1.4.3).
 */
abstract class AbstractPlugin implements Plugin
{
    public function requiredPackage(): ?string
    {
        return null;
    }

    public function enabledByDefault(): bool
    {
        return false;
    }

    public function tools(): array
    {
        return [];
    }

    public function requiresReadOnlyConnection(): bool
    {
        return false;
    }

    public function bootRules(): array
    {
        return [];
    }

    public function doctorChecks(): array
    {
        return [];
    }

    public function prompts(): array
    {
        return [];
    }
}
