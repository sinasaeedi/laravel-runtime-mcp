<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Security;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;

/**
 * Spec 4.6: the environment name, and whether it is strict or relaxed.
 *
 * Read fresh on every call. Never keep the name in a static property (rule 14).
 */
final class Environment
{
    public function __construct(
        private readonly Application $app,
        private readonly Repository $config,
    ) {}

    /** The value of app()->environment(), for example "staging". */
    public function name(): string
    {
        return $this->app->environment();
    }

    /** True only for a name listed in runtime-mcp.environments.relaxed (exact, case-sensitive). */
    public function isRelaxed(): bool
    {
        $relaxed = $this->config->get('runtime-mcp.environments.relaxed');

        // A missing or malformed list means "every environment is strict" (principle 1.4.3).
        if (! is_array($relaxed) || ! array_is_list($relaxed)) {
            return false;
        }

        foreach ($relaxed as $entry) {
            if (! is_string($entry)) {
                return false;
            }
        }

        return in_array($this->name(), $relaxed, true);
    }

    public function isStrict(): bool
    {
        return ! $this->isRelaxed();
    }
}
