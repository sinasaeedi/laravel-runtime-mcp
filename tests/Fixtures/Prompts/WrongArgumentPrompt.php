<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Prompts;

use Laravel\Mcp\Server\Prompts\Argument;
use SinaSaeedi\RuntimeMcp\Prompts\Contracts\RuntimePrompt;
use SinaSaeedi\RuntimeMcp\Prompts\PromptContext;
use SinaSaeedi\RuntimeMcp\Prompts\PromptInput;

/**
 * Its arguments() returns laravel/mcp's own Argument, not a PromptArgument. PHP does not check the
 * list's item type, so the call itself succeeds; McpPromptAdapter's mapping fails (a TypeError).
 */
final class WrongArgumentPrompt extends RuntimePrompt
{
    public function name(): string
    {
        return 'wrong_argument_prompt';
    }

    public function title(): string
    {
        return 'Wrong argument prompt';
    }

    public function description(): string
    {
        return 'Returns a laravel/mcp Argument.';
    }

    public function arguments(PromptContext $context): array
    {
        /** @phpstan-ignore return.type (the wrong item is the point) */
        return [new Argument('order', 'The order.', true)];
    }

    public function tools(): array
    {
        return ['echo'];
    }

    public function message(PromptInput $input): string
    {
        return 'Never built.';
    }
}
