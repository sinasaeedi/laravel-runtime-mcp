<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tools;

/** The progress of a call whose client sent no progressToken (spec 5.15): every report does nothing. */
final class NullProgress implements Progress
{
    public function report(int $done, ?int $total = null, ?string $message = null): void
    {
        // No token, no stream: the result stays a plain JSON response.
    }
}
