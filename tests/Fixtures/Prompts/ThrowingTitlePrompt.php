<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Prompts;

use RuntimeException;
use SinaSaeedi\RuntimeMcp\Prompts\Contracts\RuntimePrompt;
use SinaSaeedi\RuntimeMcp\Prompts\PromptInput;

/** Its title() throws: McpPromptAdapter reads it in its constructor, so the prompts.* rules cannot run. */
final class ThrowingTitlePrompt extends RuntimePrompt
{
    public function name(): string
    {
        return 'throwing_title_prompt';
    }

    public function title(): string
    {
        throw new RuntimeException('title failed');
    }

    public function description(): string
    {
        return 'Fails inside title().';
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
