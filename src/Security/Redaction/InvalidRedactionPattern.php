<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Security\Redaction;

use InvalidArgumentException;

/**
 * A bad entry in runtime-mcp.redaction.extra_patterns. Every tool call fails until it is fixed: the
 * engine never skips a pattern the operator meant to apply (principle 1.4.3). doctor (01-12) reports it.
 */
final class InvalidRedactionPattern extends InvalidArgumentException
{
    public const NOT_AN_ENTRY = 'not a string or a {pattern, type} array';

    public const DOES_NOT_COMPILE = 'the regex does not compile';

    public const BAD_TYPE = 'type must match /^[a-z][a-z0-9_]{0,31}$/';

    /**
     * @param  int|string  $entry  the key of the bad entry, as written (0-based for a list)
     * @param  self::NOT_AN_ENTRY|self::DOES_NOT_COMPILE|self::BAD_TYPE  $why
     */
    public function __construct(public readonly int|string $entry, public readonly string $why)
    {
        // Never the pattern text: the message can reach a log or the doctor output.
        parent::__construct("runtime-mcp.redaction.extra_patterns entry {$entry} is invalid: {$why}.");
    }
}
