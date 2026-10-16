<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Redaction;

use JsonSerializable;

/** Returns itself from jsonSerialize(): redact() must stop at MAX_DEPTH, never recurse forever (01-04). */
final class SelfSerializing implements JsonSerializable
{
    public function jsonSerialize(): self
    {
        return $this;
    }
}
