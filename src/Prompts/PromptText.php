<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Prompts;

use SinaSaeedi\RuntimeMcp\Prompts\Contracts\RuntimePrompt;

/**
 * Text helpers for the built-in prompts (spec 6.11). Pure functions of their arguments.
 */
final class PromptText
{
    /** A window argument: 1 to 999 hours or days, for example 24h or 7d. No zero, no newline (/D). */
    public const SINCE_PATTERN = '/^[1-9][0-9]{0,2}[hd]$/D';

    /** The default window: the tools' own default (the last 24 hours), so the text omits it. */
    public const DEFAULT_SINCE = '24h';

    /** "1 hour", "12 hours", "7 days". $since matches SINCE_PATTERN. */
    public static function duration(string $since): string
    {
        $count = (int) substr($since, 0, -1);
        $unit = str_ends_with($since, 'd') ? 'day' : 'hour';

        return $count.' '.($count === 1 ? $unit : $unit.'s');
    }

    /**
     * Numbered steps, one per line. A null step (an "if it is available" step whose tool the Gate denies)
     * takes no number, so the agent never reads "1, 2, 4".
     *
     * @param  list<string|null>  $steps
     */
    public static function steps(array $steps): string
    {
        $lines = [];

        foreach ($steps as $step) {
            if ($step !== null) {
                $lines[] = (count($lines) + 1).'. '.$step;
            }
        }

        return implode("\n", $lines);
    }

    /** The whole text: the first line, the body, the report shape and the rules paragraph (spec 6.11). */
    public static function compose(string $first, string $body, string $extraRule = ''): string
    {
        $rules = $extraRule === '' ? RuntimePrompt::RULES : RuntimePrompt::RULES.' '.$extraRule;

        return $first."\n\n".$body."\n\n".RuntimePrompt::REPORT."\n\n".$rules;
    }
}
