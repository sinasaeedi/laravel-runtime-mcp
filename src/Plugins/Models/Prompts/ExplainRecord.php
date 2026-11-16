<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Plugins\Models\Prompts;

use SinaSaeedi\RuntimeMcp\Prompts\Contracts\RuntimePrompt;
use SinaSaeedi\RuntimeMcp\Prompts\PromptArgument;
use SinaSaeedi\RuntimeMcp\Prompts\PromptContext;
use SinaSaeedi\RuntimeMcp\Prompts\PromptInput;
use SinaSaeedi\RuntimeMcp\Prompts\PromptText;

/**
 * Spec 6.11: the one-record playbook (models plugin). `model` is one of model_show's aliases and `key`
 * fits model_show's 64-character key (01-21), so the prompt never sends input the tool refuses.
 */
final class ExplainRecord extends RuntimePrompt
{
    /** model_show takes a key of at most 64 characters (ModelShow::MAX_KEY); no character that ends a code span. */
    public const KEY_PATTERN = '/^[A-Za-z0-9_-]{1,64}$/D';

    public function name(): string
    {
        return 'explain_record';
    }

    public function title(): string
    {
        return 'Explain a record';
    }

    public function description(): string
    {
        return 'Read one record with its relations and explain it in plain words.';
    }

    public function arguments(PromptContext $context): array
    {
        return [
            new PromptArgument('model', 'The model alias, as runtime-mcp.models names it.', true, allowed: $context->modelAliases()),
            new PromptArgument('key', 'The primary key of the record: letters, digits, _ and -, at most 64 characters.', true, ['regex:'.self::KEY_PATTERN]),
        ];
    }

    public function tools(): array
    {
        return ['model_show'];
    }

    public function message(PromptInput $input): string
    {
        $context = $input->context;
        $model = (string) $input->get('model');
        $key = (string) $input->get('key');

        return PromptText::compose(
            "Explain the `{$model}` record `{$key}` on the `{$context->environment()}` environment of `{$context->appName()}`. This server is read-only.",
            "Call `model_show` with `model` `{$model}`, `key` `{$key}`, and in `with` at most five of the relations that its description lists for `{$model}`. Explain the record in plain words: its state, its important dates, and how its relations fit. Say which fields and relations you could not read.",
        );
    }
}
