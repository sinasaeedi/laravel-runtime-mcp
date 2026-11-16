<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Prompts;

use RuntimeException;
use SinaSaeedi\RuntimeMcp\Prompts\Contracts\RuntimePrompt;
use SinaSaeedi\RuntimeMcp\Prompts\PromptInput;

/** Its message() throws with a secret in the message: the answer must hold neither. */
final class ThrowingPrompt extends RuntimePrompt
{
    public const SECRET = 'message failed for order 4711';

    public function name(): string
    {
        return 'throwing_prompt';
    }

    public function title(): string
    {
        return 'Throwing prompt';
    }

    public function description(): string
    {
        return 'Fails inside message().';
    }

    public function tools(): array
    {
        return ['echo'];
    }

    public function message(PromptInput $input): string
    {
        throw new RuntimeException(self::SECRET);
    }
}
