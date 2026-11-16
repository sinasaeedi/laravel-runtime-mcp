<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Mcp;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Prompts\Argument;
use LogicException;
use SinaSaeedi\RuntimeMcp\Prompts\Contracts\RuntimePrompt;
use SinaSaeedi\RuntimeMcp\Prompts\PromptArgument;
use SinaSaeedi\RuntimeMcp\Prompts\PromptContext;
use SinaSaeedi\RuntimeMcp\Security\Auth\CurrentPrincipal;
use SinaSaeedi\RuntimeMcp\Security\Authorization\PromptGate;
use SinaSaeedi\RuntimeMcp\Tools\Contracts\RuntimeTool;
use Throwable;

/**
 * The only subclass of laravel/mcp's Prompt (spec 5.14). One instance wraps one RuntimePrompt.
 * laravel/mcp uses it for prompts/list only: Methods\GetPrompt answers prompts/get.
 *
 * Built per request by McpServer, so the constructor stays cheap (no database, no I/O):
 * ServerContext::prompts() calls shouldRegister() on every prompts/list.
 */
final class McpPromptAdapter extends Prompt
{
    /**
     * @param  array<string, RuntimeTool>  $tools  the registered tools by name
     */
    public function __construct(
        private readonly RuntimePrompt $prompt,
        private readonly array $tools,
        private readonly Container $container,
    ) {
        $name = $prompt->name();

        if (preg_match(McpToolAdapter::NAME_PATTERN, $name) !== 1) {
            throw new LogicException("[{$name}] is not a valid prompt name: use lower snake case, at most 64 characters.");
        }

        // Otherwise Primitive falls back to the class basename: every prompt "mcp-prompt-adapter".
        $this->name = $name;
        $this->title = $prompt->title();
        $this->description = $prompt->description();
    }

    public function runtimePrompt(): RuntimePrompt
    {
        return $this->prompt;
    }

    /** @return array<string, RuntimeTool> */
    public function registeredTools(): array
    {
        return $this->tools;
    }

    public function context(): PromptContext
    {
        return PromptContext::build($this->container, $this->tools);
    }

    /** @return array<int, Argument> */
    public function arguments(): array
    {
        return array_map(
            fn (PromptArgument $argument): Argument => new Argument($argument->name, self::describe($argument), $argument->required),
            $this->prompt->arguments($this->context()),
        );
    }

    /**
     * Spec 5.5, 5.14: hides a prompt unless the Gate allows every tool it needs. laravel/mcp calls it
     * through the container (Primitive::eligibleForRegistration()). A convenience only: Methods\GetPrompt
     * enforces. A prompt whose own code throws is hidden and reported: ListPrompts has no catch, and
     * Server::handle() rethrows in debug mode (spec 3.2).
     */
    public function shouldRegister(PromptGate $gate, CurrentPrincipal $principals): bool
    {
        try {
            if (! $gate->decide($principals->get(), $this->prompt, $this->tools)->allowed) {
                return false;
            }

            // The whole mapping, not only the prompt's call: ListPrompts then runs it with no catch, so a
            // wrong item (not a PromptArgument) or a bad allowed value must fail here.
            $this->arguments();

            return true;
        } catch (Throwable $e) {
            $this->report($e);

            return false;
        }
    }

    /** The argument's description, plus its allowed values (config names, never secrets). */
    public static function describe(PromptArgument $argument): string
    {
        return match (true) {
            $argument->allowed === null => $argument->description,
            $argument->allowed === [] => $argument->description.' No value is configured on this server.',
            default => $argument->description.' One of: '.implode(', ', $argument->allowed).'.',
        };
    }

    private function report(Throwable $e): void
    {
        try {
            $this->container->make(ExceptionHandler::class)->report($e);
        } catch (Throwable) {
            // Nothing left to tell. The prompt stays hidden.
        }
    }
}
