<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Doctor\Checks;

use SinaSaeedi\RuntimeMcp\Doctor\Check;
use SinaSaeedi\RuntimeMcp\Doctor\CheckResult;
use SinaSaeedi\RuntimeMcp\Tools\CatalogSetting;
use SinaSaeedi\RuntimeMcp\Tools\OutputCap;

/**
 * Spec 4.5, 5.8, 5.17, 7.3 (01-40): catalog.enabled, and the cap in use with the catalog on. The numbers are config
 * values, but not secrets, so they are printed (01-12: names and numbers only). A value that is not a bool keeps the
 * catalog off and fails: the operator thinks the agent sees something else. A small cap is a valid choice of the app
 * (spec 5.17: its mcp.tool_search.* config), so it only warns.
 */
final class Catalog implements Check
{
    /** Below this cap in use, a normal db_query or logs_search result is cut to a few rows. */
    public const MIN_USEFUL_BYTES = 8_000;

    public const OFF = 'The catalog is off: tools/list shows every tool.';

    /** %1$d is the cap in use, %2$s its source (FROM_OUTPUT or FROM_TOOL_SEARCH). */
    public const ON = 'The catalog is on: tools/list shows only search_tools and execute_tools. The cap in use is %1$d bytes (%2$s).';

    public const FROM_OUTPUT = 'from output.max_bytes';

    public const FROM_TOOL_SEARCH = 'from mcp.tool_search.max_output_bytes minus '.OutputCap::CATALOG_MARGIN;

    public const SMALL_FIX = 'Raise mcp.tool_search.max_output_bytes: below '.self::MIN_USEFUL_BYTES.' bytes most results are cut.';

    public const BAD_VALUE = 'catalog.enabled is not true or false, so the catalog is off.';

    public const BAD_VALUE_FIX = 'Set catalog.enabled to true or false.';

    public function __construct(
        private readonly CatalogSetting $catalog,
        private readonly OutputCap $cap,
    ) {}

    public function title(): string
    {
        return 'Catalog';
    }

    public function run(): array
    {
        if (! $this->catalog->valueIsValid()) {
            return [CheckResult::fail(self::BAD_VALUE, self::BAD_VALUE_FIX)];
        }

        if (! $this->catalog->enabled()) {
            return [CheckResult::pass(self::OFF)];
        }

        $limit = $this->cap->limit();
        $source = $limit < $this->cap->catalogLimit($this->catalog) ? self::FROM_OUTPUT : self::FROM_TOOL_SEARCH;
        $message = sprintf(self::ON, $limit, $source);

        return [$limit < self::MIN_USEFUL_BYTES ? CheckResult::warn($message, self::SMALL_FIX) : CheckResult::pass($message)];
    }
}
