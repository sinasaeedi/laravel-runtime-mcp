<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Doctor\Checks;

use Illuminate\Contracts\Config\Repository;
use SinaSaeedi\RuntimeMcp\Doctor\Check;
use SinaSaeedi\RuntimeMcp\Doctor\CheckResult;
use SinaSaeedi\RuntimeMcp\Security\Auth\ApiKey;
use SinaSaeedi\RuntimeMcp\Security\Auth\AuthMethod;
use SinaSaeedi\RuntimeMcp\Security\Auth\LinkedUserMissing;
use SinaSaeedi\RuntimeMcp\Security\Auth\PrincipalResolver;

/**
 * Spec 5.3, 01-06: every linked key finds its user, through the same PrincipalResolver that the
 * request uses. It queries the users table: doctor may (spec 5.11 keeps queries out of boot only).
 *
 * Names the key, never the email (01-06; Sina, 2026-10-20): a deploy log is often public to a team.
 */
final class LinkedUsers implements Check
{
    public function __construct(
        private readonly Repository $config,
        private readonly PrincipalResolver $resolver,
    ) {}

    public function title(): string
    {
        return 'Linked users';
    }

    public function run(): array
    {
        $links = $this->config->get('runtime-mcp.auth.link_users');

        if ($links === null || $links === []) {
            return [CheckResult::pass('No key is linked to a user.')];
        }

        if (! is_array($links)) {
            return [CheckResult::fail(
                'auth.link_users is not an array, so every key is rejected.',
                'Set auth.link_users to an array of key name => user email, or to [].',
            )];
        }

        $results = [];
        $found = 0;
        $position = 0;

        foreach ($links as $name => $email) {
            $position++;
            $key = ApiKey::isValidName((string) $name) ? "Key `{$name}`" : "The link at position {$position}";

            if (! is_string($email) || trim($email) === '') {
                $results[] = CheckResult::fail(
                    "{$key} has no email, so calls with this key are rejected.",
                    'Give the key a user email in auth.link_users, or remove the link.',
                );

                continue;
            }

            try {
                $this->resolver->resolve((string) $name, AuthMethod::ApiKey);
                $found++;
            } catch (LinkedUserMissing $e) {
                $previous = $e->getPrevious();

                if ($previous !== null) {
                    // The provider itself fails: every other link fails the same way, so say it once.
                    $results[] = CheckResult::fail(
                        'The user provider could not run ('.$previous::class.'), so every linked key is rejected.',
                        'Check auth.defaults.guard, its user provider, and that the users table exists.',
                    );

                    return $results;
                }

                $results[] = CheckResult::fail(
                    "{$key} is linked to a user that does not exist, so calls with this key are rejected.",
                    'Create the user, or fix the email in auth.link_users.',
                );
            }
        }

        if ($results === []) {
            return [CheckResult::pass($found === 1 ? '1 linked key finds its user.' : "{$found} linked keys find their user.")];
        }

        return $results;
    }
}
