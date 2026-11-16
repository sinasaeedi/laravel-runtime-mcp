<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Prompts;

use SinaSaeedi\RuntimeMcp\Prompts\Contracts\RuntimePrompt;
use SinaSaeedi\RuntimeMcp\Prompts\PromptInput;

/** A name that is not lower snake case: prompts.name fails. */
final class BadNamePrompt extends RuntimePrompt
{
    public function name(): string
    {
        return 'Bad-Name';
    }

    public function title(): string
    {
        return 'BadNamePrompt';
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
