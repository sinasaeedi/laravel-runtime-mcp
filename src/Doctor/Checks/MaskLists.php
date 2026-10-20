<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Doctor\Checks;

use Illuminate\Contracts\Config\Repository;
use SinaSaeedi\RuntimeMcp\Doctor\Check;
use SinaSaeedi\RuntimeMcp\Doctor\CheckResult;
use SinaSaeedi\RuntimeMcp\Security\Grants\ColumnMask;
use SinaSaeedi\RuntimeMcp\Security\Grants\DeniedTables;
use SinaSaeedi\RuntimeMcp\Security\Grants\InvalidGrantConfig;

/**
 * Spec 5.1, 01-11: masked_columns and denied_tables. Not an array stops the role SQL (Sina,
 * 2026-10-19), so it fails. An entry that is not a name is ignored, so it warns, with the count and
 * never the values. 01-13 defers to this check for a malformed list.
 */
final class MaskLists implements Check
{
    public function __construct(private readonly Repository $config) {}

    public function title(): string
    {
        return 'Mask lists';
    }

    public function run(): array
    {
        $results = [];

        try {
            $ignored = count(ColumnMask::fromConfig($this->config)->ignored());

            if ($ignored > 0) {
                $results[] = self::ignored('masked_columns', $ignored, 'column name');
            }
        } catch (InvalidGrantConfig $e) {
            $results[] = self::invalid($e);
        }

        try {
            $ignored = count(DeniedTables::fromConfig($this->config)->ignored());

            if ($ignored > 0) {
                $results[] = self::ignored('denied_tables', $ignored, 'table name');
            }
        } catch (InvalidGrantConfig $e) {
            $results[] = self::invalid($e);
        }

        return $results === [] ? [CheckResult::pass('masked_columns and denied_tables are lists of names.')] : $results;
    }

    private static function invalid(InvalidGrantConfig $e): CheckResult
    {
        return CheckResult::fail($e->getMessage(), 'Set it to a list of names, as in the published config file.');
    }

    private static function ignored(string $key, int $count, string $what): CheckResult
    {
        $entries = $count === 1 ? '1 entry that is not a '.$what.'. It is' : "{$count} entries that are not a {$what}. They are";

        return CheckResult::warn("{$key} has {$entries} ignored.", 'Write each entry as a non-empty string.');
    }
}
