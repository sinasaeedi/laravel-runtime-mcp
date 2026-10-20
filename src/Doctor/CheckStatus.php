<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Doctor;

/** Spec 7.3: the three outcomes of one doctor result. Only Fail changes the exit code. */
enum CheckStatus: string
{
    case Pass = 'pass';
    case Warn = 'warn';
    case Fail = 'fail';
}
