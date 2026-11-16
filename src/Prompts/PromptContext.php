<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Prompts;

use Illuminate\Contracts\Container\Container;
use SinaSaeedi\RuntimeMcp\Plugins\Logs\LogSettings;
use SinaSaeedi\RuntimeMcp\Plugins\Models\ModelRegistry;
use SinaSaeedi\RuntimeMcp\Principal;
use SinaSaeedi\RuntimeMcp\Security\Auth\CurrentPrincipal;
use SinaSaeedi\RuntimeMcp\Security\Authorization\ToolGate;
use SinaSaeedi\RuntimeMcp\Security\Environment;
use SinaSaeedi\RuntimeMcp\Server\RuntimeServer;
use SinaSaeedi\RuntimeMcp\Tools\Contracts\RuntimeTool;

/**
 * What a prompt may know (rule 18): config facts and Gate answers, never app data. It has no clock: the
 * current time is neither source text nor an argument, so a text says "before now" and the tool echoes
 * the window it used.
 */
final class PromptContext
{
    /** @param  array<string, RuntimeTool>  $tools  the registered tools, by name */
    public function __construct(
        private readonly Environment $environment,
        private readonly RuntimeServer $server,
        private readonly ModelRegistry $models,
        private readonly LogSettings $logs,
        private readonly ToolGate $gate,
        private readonly ?Principal $principal,
        private readonly array $tools,
    ) {}

    /** @param  array<string, RuntimeTool>  $tools  the registered tools, by name */
    public static function build(Container $container, array $tools): self
    {
        return new self(
            $container->make(Environment::class),
            $container->make(RuntimeServer::class),
            $container->make(ModelRegistry::class),
            $container->make(LogSettings::class),
            $container->make(ToolGate::class),
            $container->make(CurrentPrincipal::class)->get(),
            $tools,
        );
    }

    public function environment(): string
    {
        return $this->environment->name();
    }

    public function appName(): string
    {
        return $this->server->appName();
    }

    /** @return list<string> the valid aliases of runtime-mcp.models, as model_show accepts them (01-21) */
    public function modelAliases(): array
    {
        return $this->models->aliases();
    }

    /** @return list<string> the valid names of runtime-mcp.logs.channels, in order (01-25) */
    public function logChannels(): array
    {
        return $this->logs->channels();
    }

    /** The tool is registered, and the Gate allows it for the current principal (spec 6.11: "if it is available"). */
    public function allows(string $tool): bool
    {
        $registered = $this->tools[$tool] ?? null;

        return $registered !== null && $this->gate->decide($this->principal, $registered)->allowed;
    }
}
