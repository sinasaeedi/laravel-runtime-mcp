<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Doctor;

use SinaSaeedi\RuntimeMcp\Doctor\Check;
use SinaSaeedi\RuntimeMcp\Doctor\CheckResult;

/** Returns the results it was given, for plugin and runner tests. */
final class StaticCheck implements Check
{
    /** @param  list<CheckResult>  $results */
    public function __construct(
        private readonly string $title,
        private readonly array $results,
    ) {}

    public function title(): string
    {
        return $this->title;
    }

    public function run(): array
    {
        return $this->results;
    }
}
