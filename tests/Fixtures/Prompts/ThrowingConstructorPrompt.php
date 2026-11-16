<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Prompts;

use RuntimeException;
use SinaSaeedi\RuntimeMcp\Prompts\Contracts\RuntimePrompt;
use SinaSaeedi\RuntimeMcp\Prompts\PromptInput;

/** Its constructor throws: the prompts.* rules cannot run, so the boot fails. */
final class ThrowingConstructorPrompt extends RuntimePrompt
{
    public function __construct()
    {
        throw new RuntimeException('constructor failed');
    }

    public function name(): string
    {
        return 'throwing_constructor_prompt';
    }

    public function title(): string
    {
        return 'Throwing constructor prompt';
    }

    public function description(): string
    {
        return 'Fails when it is built.';
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
