<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Plugins\Queue;

use RuntimeException;

/**
 * 01-23 Part D: FailedJobReader could not look. The message is the note of an `unavailable` result (rule 11):
 * package text, never a database message, a path or a host.
 */
final class FailedJobsUnavailable extends RuntimeException {}
