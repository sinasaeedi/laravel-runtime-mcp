<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Prompts;

use SinaSaeedi\RuntimeMcp\Prompts\Contracts\RuntimePrompt;
use SinaSaeedi\RuntimeMcp\Prompts\PromptInput;

/** The name of a built-in prompt: prompts.unique fails. */
final class DuplicatePrompt extends RuntimePrompt
{
    public function name(): string
    {
        return 'health_check';
    }

    public function title(): string
    {
        return 'DuplicatePrompt';
    }

    public function description(): string
    {
        return 'A test prompt.';
    }

    public function tools(): array
    {
        return ['echo'];
    }

    public function message(PromptInput $input): string
    {
        return 'A fixed text.';
    }
}
