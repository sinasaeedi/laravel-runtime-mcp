<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Support;

use RuntimeException;

final class OutboundCallAttempted extends RuntimeException
{
    public function __construct(string $url)
    {
        parent::__construct(sprintf(
            'Code under test tried to open [%s]. The package makes no outbound calls (spec rule 4).',
            $url,
        ));
    }
}
