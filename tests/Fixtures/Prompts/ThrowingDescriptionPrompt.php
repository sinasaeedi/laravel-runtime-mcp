<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Prompts;

use SinaSaeedi\RuntimeMcp\Prompts\Contracts\RuntimePrompt;
use SinaSaeedi\RuntimeMcp\Prompts\PromptInput;

/**
 * Its description() reads a config key that is missing: null for a string return type, a TypeError.
 * McpPromptAdapter reads it in its constructor, so the prompts.* rules cannot run.
 */
final class ThrowingDescriptionPrompt extends RuntimePrompt
{
    public function name(): string
    {
        return 'throwing_description_prompt';
    }

    public function title(): string
    {
        return 'Throwing description prompt';
    }

    public function description(): string
    {
        /** @phpstan-ignore return.type (the TypeError is the point) */
        return config('runtime-mcp-fixtures.missing_description');
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
