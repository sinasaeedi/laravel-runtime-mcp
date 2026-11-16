<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Prompts;

use InvalidArgumentException;

/**
 * One argument of a prompt (spec 5.14). Every value is a string: the prompts/get handler builds the rule
 * list as [required or filled, string, max:200, ...$rules], and adds an `in` rule with its own message
 * when $allowed is set. A built-in prompt passes $allowed for enum-like values (aliases, channels),
 * never an `in:` text: a name with a comma would split it.
 */
final readonly class PromptArgument
{
    /** The same rule as a tool name (spec 4.3); /D so a trailing newline is no match. */
    public const NAME_PATTERN = '/^[a-z][a-z0-9_]{0,63}$/D';

    /**
     * @param  list<mixed>  $rules  more Laravel rules (strings or rule objects); end every regex with /D
     * @param  list<string>|null  $allowed  the only values allowed, or null for any value the rules allow
     */
    public function __construct(
        public string $name,
        public string $description,
        public bool $required = false,
        public array $rules = [],
        public ?array $allowed = null,
    ) {
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new InvalidArgumentException('A prompt argument name is lower snake case, at most 64 characters.');
        }
    }
}
