<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Prompts;

use SinaSaeedi\RuntimeMcp\Prompts\Contracts\RuntimePrompt;
use SinaSaeedi\RuntimeMcp\Prompts\PromptArgument;
use SinaSaeedi\RuntimeMcp\Prompts\PromptContext;
use SinaSaeedi\RuntimeMcp\Prompts\PromptInput;

/**
 * A custom prompt for the fixture tool `echo`: `topic` has no rule beyond the package's (a loose rule, so
 * a secret reaches the text and is redacted), `level` has allowed values, `mode` is required.
 */
final class EchoPrompt extends RuntimePrompt
{
    public function name(): string
    {
        return 'echo_prompt';
    }

    public function title(): string
    {
        return 'Echo prompt';
    }

    public function description(): string
    {
        return 'Echoes its arguments.';
    }

    public function arguments(PromptContext $context): array
    {
        return [
            new PromptArgument('mode', 'Any mode.', true),
            new PromptArgument('topic', 'Any text.'),
            new PromptArgument('level', 'A level.', allowed: ['low', 'high']),
        ];
    }

    public function tools(): array
    {
        return ['echo'];
    }

    public function message(PromptInput $input): string
    {
        return 'Mode '.$input->get('mode').', topic '.($input->get('topic') ?? '(none)').', level '.($input->get('level') ?? '(none)').'.';
    }
}
