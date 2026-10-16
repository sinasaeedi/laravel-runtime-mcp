<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Audit;

use RuntimeException;
use Throwable;

/**
 * The audit entry could not be written, so the tool call must not return its result (spec 5.7,
 * fail closed). 01-08 turns it into an error result.
 *
 * The message is fixed. Never copy the previous message: when Monolog cannot open or write the
 * file, its message repeats the whole log context (Monolog\Utils::getRecordMessageForException()).
 */
final class AuditUnavailable extends RuntimeException
{
    public const MESSAGE = 'The audit log could not be written.';

    public function __construct(Throwable $previous)
    {
        parent::__construct(self::MESSAGE, 0, $previous);
    }
}
