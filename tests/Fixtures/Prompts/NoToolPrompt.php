<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Prompts;

use SinaSaeedi\RuntimeMcp\Prompts\Contracts\RuntimePrompt;
use SinaSaeedi\RuntimeMcp\Prompts\PromptInput;

/** Names no tool: never shown (default deny), and prompts.tools warns. */
final class NoToolPrompt extends RuntimePrompt
{
    public function name(): string
    {
        return 'no_tool_prompt';
    }

    public function title(): string
    {
        return 'NoToolPrompt';
    }

    public function description(): string
    {
        return 'A test prompt.';
    }

    public function tools(): array
    {
        return [];
    }

    public function message(PromptInput $input): string
    {
        return 'A fixed text.';
    }
}
