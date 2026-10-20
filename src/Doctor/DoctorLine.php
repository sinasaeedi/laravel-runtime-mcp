<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Doctor;

/** One result with the title of its check, and the class that produced it (a check, or a plugin). */
final readonly class DoctorLine
{
    /** @param  class-string  $source */
    public function __construct(
        public string $title,
        public CheckResult $result,
        public string $source,
    ) {}
}
