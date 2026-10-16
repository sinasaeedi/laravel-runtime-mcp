<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Redaction;

/** A backed enum in tool data (01-04): redact() turns it into its value. */
enum SampleStatus: string
{
    case Shipped = 'shipped';
}
