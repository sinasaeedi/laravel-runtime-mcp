<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Doctor;

use SinaSaeedi\RuntimeMcp\Doctor\Check;

/** The container cannot build it: $name is a string with no default (a mixed one would be built as null). */
final class UnbuildableCheck implements Check
{
    public function __construct(private readonly string $name) {}

    public function title(): string
    {
        return $this->name;
    }

    public function run(): array
    {
        return [];
    }
}
