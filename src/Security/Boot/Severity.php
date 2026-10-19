<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Security\Boot;

/**
 * Spec 5.11, 4.6: an error keeps the endpoint off (strict) or throws (relaxed); a warning is only
 * logged. doctor (01-12) prints both.
 */
enum Severity: string
{
    case Error = 'error';
    case Warning = 'warning';
}
