<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Security\Boot;

/**
 * Spec 5.11: one boot validation rule. Core rules are listed in BootValidator::RULES; a plugin adds
 * its own through Plugin::bootRules().
 *
 * A rule reads config (and class metadata) only. It never queries the database, opens a connection
 * or calls out: under PHP-FPM it runs on every request.
 */
interface BootRule
{
    /** A stable id for doctor, the log and tests, for example "api_keys.present". Public API. */
    public function id(): string;

    /** @return list<BootIssue> empty when the rule passes */
    public function check(): array;
}
