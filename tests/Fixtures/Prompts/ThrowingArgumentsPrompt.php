<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Prompts;

use SinaSaeedi\RuntimeMcp\Prompts\Contracts\RuntimePrompt;
use SinaSaeedi\RuntimeMcp\Prompts\PromptArgument;
use SinaSaeedi\RuntimeMcp\Prompts\PromptContext;
use SinaSaeedi\RuntimeMcp\Prompts\PromptInput;

/** Its arguments() throws (an argument name that PromptArgument refuses): hidden in the list, FAILURE on get. */
final class ThrowingArgumentsPrompt extends RuntimePrompt
{
    public function name(): string
    {
        return 'throwing_arguments_prompt';
    }

    public function title(): string
    {
        return 'Throwing arguments prompt';
    }

    public function description(): string
    {
        return 'Fails inside arguments().';
    }

    public function arguments(PromptContext $context): array
    {
        return [new PromptArgument('Not A Name', 'Refused.')];
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
