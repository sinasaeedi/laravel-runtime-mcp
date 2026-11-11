<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Plugins\Logs;

/** 01-25 Part B: why ReverseLineReader stopped reading one file. */
enum ReadEnd
{
    /** It read back to the first byte: the whole file was searched. */
    case Start;

    /** The call's byte budget ran out first: older lines were not read. */
    case Budget;

    /** The file was shorter than at open (rotation, copytruncate), so the rest cannot be trusted. */
    case Changed;

    /** fopen() refused the file after the is_readable() check. */
    case Unreadable;
}
