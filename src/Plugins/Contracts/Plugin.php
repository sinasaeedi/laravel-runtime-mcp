<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Plugins\Contracts;

use SinaSaeedi\RuntimeMcp\Doctor\Check;
use SinaSaeedi\RuntimeMcp\Security\Boot\BootRule;

/**
 * Spec 4.2: one group of tools that the operator turns on in runtime-mcp.plugins.
 *
 * Extend AbstractPlugin instead of implementing this directly: it holds the safe defaults.
 * No laravel/mcp import (spec 4.1).
 */
interface Plugin
{
    /** The key in runtime-mcp.plugins, for example "queue". Lower snake case. Public API. */
    public function name(): string;

    /** One sentence for doctor, install and app_info. */
    public function description(): string;

    /** The Composer package this plugin needs ("laravel/horizon"), or null for a base plugin. */
    public function requiredPackage(): ?string;

    /** On when the operator has not set runtime-mcp.plugins.<name>. True only for base plugins. */
    public function enabledByDefault(): bool;

    /** Every tool name of this plugin starts with this, for example "queue_". */
    public function toolPrefix(): string;

    /** @return list<class-string> the RuntimeTool classes this plugin registers; McpServer refuses any other class (01-08) */
    public function tools(): array;

    /** True when its tools read the database: boot validation then needs database.connection (spec 5.11, 01-10). */
    public function requiresReadOnlyConnection(): bool;

    /** @return list<BootRule> extra boot validation rules; they run only while the plugin is active (01-10) */
    public function bootRules(): array;

    /** @return list<Check> extra doctor checks; they run only while the plugin is active (01-12) */
    public function doctorChecks(): array;

    /** @return list<class-string> the prompt classes this plugin registers (spec 5.14; 01-36 types them) */
    public function prompts(): array;
}
