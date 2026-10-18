<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tools;

/**
 * Spec 4.4: the status of a result envelope. The values are public API (spec 10.1).
 *
 * Unavailable means "the tool could not look". It never means "nothing there" (rule 11).
 */
enum Status: string
{
    case Ok = 'ok';
    case Empty = 'empty';
    case Partial = 'partial';
    case Unavailable = 'unavailable';
}
