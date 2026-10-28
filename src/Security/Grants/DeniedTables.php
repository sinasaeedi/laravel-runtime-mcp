<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Security\Grants;

use Illuminate\Contracts\Config\Repository;

/**
 * Spec 5.1: the denied_tables list. A denied table gets no grant at all. Pure: no database, no
 * container.
 *
 * The match is exact and case-insensitive. "audit.sessions" matches that schema only; "sessions"
 * matches the table in any schema (the snapshot holds only the app schemas). With a table prefix,
 * the real name and the name without the prefix both match (Sina, 2026-10-19): that can only deny more.
 */
final class DeniedTables
{
    /** @var array<string, true> lower-case table names that match in any schema */
    private readonly array $anySchema;

    /** @var array<string, true> lower-case "schema.table" keys */
    private readonly array $qualified;

    /** @var list<mixed> */
    private readonly array $ignored;

    /** @param array<array-key, mixed> $entries */
    public function __construct(array $entries)
    {
        $anySchema = [];
        $qualified = [];
        $ignored = [];

        foreach ($entries as $entry) {
            // "a.b.c", ".sessions", "sessions." and "public." can match no table: doctor reports them.
            if (! is_string($entry) || $entry === '' || substr_count($entry, '.') > 1
                || str_starts_with($entry, '.') || str_ends_with($entry, '.')) {
                $ignored[] = $entry;

                continue;
            }

            if (str_contains($entry, '.')) {
                $qualified[strtolower($entry)] = true;
            } else {
                $anySchema[strtolower($entry)] = true;
            }
        }

        $this->anySchema = $anySchema;
        $this->qualified = $qualified;
        $this->ignored = $ignored;
    }

    /** Reads runtime-mcp.database.denied_tables. Not an array → InvalidGrantConfig. */
    public static function fromConfig(Repository $config): self
    {
        $entries = $config->get('runtime-mcp.database.denied_tables');

        if (! is_array($entries)) {
            throw new InvalidGrantConfig('denied_tables');
        }

        return new self($entries);
    }

    /** $prefix is the connection's table prefix ('' for none). */
    public function contains(string $schema, string $table, string $prefix = ''): bool
    {
        $schema = strtolower($schema);
        $names = [strtolower($table)];

        if ($prefix !== '' && str_starts_with($names[0], strtolower($prefix))) {
            $names[] = substr($names[0], strlen($prefix));
        }

        foreach ($names as $name) {
            if (isset($this->anySchema[$name]) || isset($this->qualified[$schema.'.'.$name])) {
                return true;
            }
        }

        return false;
    }

    /** @return list<mixed> the entries that are not a non-empty string, have more than one dot, or an empty part */
    public function ignored(): array
    {
        return $this->ignored;
    }
}
