<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Security\Grants;

use Illuminate\Contracts\Config\Repository;

/**
 * Spec 5.1, rule 13: the masked_columns patterns. A matching column gets no grant, so the database
 * hides it in every SQL form. Pure: no database, no container.
 *
 * A pattern is a column name with * as the only wildcard. The match is case-insensitive and anchored:
 * "password" matches PASSWORD, not password_changed_at.
 */
final class ColumnMask
{
    /** @var list<string> */
    private readonly array $patterns;

    /** @var list<string> one regex per pattern, built once */
    private readonly array $regexes;

    /** @var list<mixed> */
    private readonly array $ignored;

    /** @param array<array-key, mixed> $patterns */
    public function __construct(array $patterns)
    {
        $usable = [];
        $ignored = [];

        foreach ($patterns as $pattern) {
            if (is_string($pattern) && $pattern !== '') {
                $usable[] = $pattern;
            } else {
                $ignored[] = $pattern;
            }
        }

        $this->patterns = $usable;
        $this->ignored = $ignored;

        // Not fnmatch(): its case folding is not portable. "s": a * also spans a line break, so a line
        // break in a column name cannot hide it from the mask.
        $this->regexes = array_map(
            fn (string $pattern): string => '/^'.str_replace('\*', '.*', preg_quote($pattern, '/')).'$/iDs',
            $usable,
        );
    }

    /** Reads runtime-mcp.database.masked_columns. Not an array (null, a string, …) → InvalidGrantConfig. */
    public static function fromConfig(Repository $config): self
    {
        $patterns = $config->get('runtime-mcp.database.masked_columns');

        if (! is_array($patterns)) {
            throw new InvalidGrantConfig('masked_columns');
        }

        return new self($patterns);
    }

    public function matches(string $column): bool
    {
        foreach ($this->regexes as $regex) {
            // false (the PCRE backtrack limit, for example) masks the column: an error never grants it.
            if (preg_match($regex, $column) !== 0) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> the usable patterns, as written */
    public function patterns(): array
    {
        return $this->patterns;
    }

    /** @return list<mixed> the entries that are not a non-empty string (doctor, 01-13, reports them) */
    public function ignored(): array
    {
        return $this->ignored;
    }
}
