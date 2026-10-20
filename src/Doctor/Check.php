<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Doctor;

/**
 * Spec 7.3: one doctor check. Core checks are listed in Doctor::CHECKS; a plugin adds its own through
 * Plugin::doctorChecks().
 *
 * A check may query the database (doctor is not boot validation, spec 5.11), but it never changes
 * anything and never calls out (rule 4).
 */
interface Check
{
    /** The left column of the report, for example "Linked users". */
    public function title(): string;

    /** @return list<CheckResult> at least one: a check that has nothing to say returns a Pass */
    public function run(): array;
}
