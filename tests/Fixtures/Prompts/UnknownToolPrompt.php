<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Prompts;

use SinaSaeedi\RuntimeMcp\Prompts\Contracts\RuntimePrompt;
use SinaSaeedi\RuntimeMcp\Prompts\PromptInput;

/** Needs a tool that is not registered. */
final class UnknownToolPrompt extends RuntimePrompt
{
    public function name(): string
    {
        return 'unknown_tool_prompt';
    }

    public function title(): string
    {
        return 'UnknownToolPrompt';
    }

    public function description(): string
    {
        return 'A test prompt.';
    }

    public function tools(): array
    {
        return ['echo', 'nope_tool'];
    }

    public function message(PromptInput $input): string
    {
        return 'A fixed text.';
    }
}
