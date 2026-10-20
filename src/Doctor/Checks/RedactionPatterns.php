<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Doctor\Checks;

use Illuminate\Contracts\Config\Repository;
use SinaSaeedi\RuntimeMcp\Doctor\Check;
use SinaSaeedi\RuntimeMcp\Doctor\CheckResult;
use SinaSaeedi\RuntimeMcp\Security\Redaction\InvalidRedactionPattern;
use SinaSaeedi\RuntimeMcp\Security\Redaction\Redactor;

/**
 * Spec 5.6, 01-04: a bad extra_patterns entry makes every tool call fail. The Redactor finds the
 * first one. A person counts from 1, the exception keeps the key as written (0-based for a list), so
 * never print its message. Never print a pattern either.
 */
final class RedactionPatterns implements Check
{
    private const FIXES = [
        InvalidRedactionPattern::NOT_AN_ENTRY => "Use a regex string, or ['pattern' => '/…/', 'type' => 'name'].",
        InvalidRedactionPattern::DOES_NOT_COMPILE => 'Fix the regex; test it with preg_match().',
        InvalidRedactionPattern::BAD_TYPE => 'Use a type of lower-case letters, digits and _, at most 32, that starts with a letter.',
    ];

    public function __construct(private readonly Repository $config) {}

    public function title(): string
    {
        return 'Redaction patterns';
    }

    public function run(): array
    {
        try {
            new Redactor($this->config);
        } catch (InvalidRedactionPattern $e) {
            $entry = is_int($e->entry) ? (string) ($e->entry + 1) : "`{$e->entry}`";

            return [CheckResult::fail(
                "Every tool call fails until redaction.extra_patterns entry {$entry} is fixed.",
                self::FIXES[$e->why],
            )];
        }

        $patterns = $this->config->get('runtime-mcp.redaction.extra_patterns');

        if ($patterns !== null && ! is_array($patterns)) {
            return [CheckResult::warn(
                'redaction.extra_patterns is not an array, so no extra pattern applies.',
                'Set it to a list of patterns, or to [].',
            )];
        }

        $count = is_array($patterns) ? count($patterns) : 0;

        return [CheckResult::pass(match ($count) {
            0 => 'The built-in patterns apply.',
            1 => 'The built-in patterns and 1 extra pattern apply.',
            default => "The built-in patterns and {$count} extra patterns apply.",
        })];
    }
}
