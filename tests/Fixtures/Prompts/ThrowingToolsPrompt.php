<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Prompts;

use RuntimeException;
use SinaSaeedi\RuntimeMcp\Prompts\Contracts\RuntimePrompt;
use SinaSaeedi\RuntimeMcp\Prompts\PromptInput;

/** Its tools() throws: hidden, reported, denied gate_error. */
final class ThrowingToolsPrompt extends RuntimePrompt
{
    public function name(): string
    {
        return 'throwing_tools_prompt';
    }

    public function title(): string
    {
        return 'ThrowingToolsPrompt';
    }

    public function description(): string
    {
        return 'A test prompt.';
    }

    public function tools(): array
    {
        throw new RuntimeException('tools() failed: sk-'.'ant-api03-'.str_repeat('a', 40));
    }

    public function message(PromptInput $input): string
    {
        return 'A fixed text.';
    }
}
