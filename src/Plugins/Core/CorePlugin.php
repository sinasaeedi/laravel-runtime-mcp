<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Plugins\Core;

use SinaSaeedi\RuntimeMcp\Plugins\AbstractPlugin;

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
}
