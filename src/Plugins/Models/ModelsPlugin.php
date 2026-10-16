<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Plugins\Models;

use SinaSaeedi\RuntimeMcp\Plugins\AbstractPlugin;

/** Base plugin (spec 4.2): no package needed, on by default. Tools: model_show (01-21). */
final class ModelsPlugin extends AbstractPlugin
{
    public function name(): string
    {
        return 'models';
    }

    public function description(): string
    {
        return 'One record of an allow-listed Eloquent model, with only the columns the agent may see.';
    }

    public function enabledByDefault(): bool
    {
        return true;
    }

    public function toolPrefix(): string
    {
        return 'model_';
    }

    public function requiresReadOnlyConnection(): bool
    {
        return true;
    }
}
