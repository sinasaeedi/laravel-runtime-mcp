<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Security\Sql;

/** Spec 5.2, 6.4, 01-15: the rows of one agent query, at most max_rows, limited by the database. */
final readonly class QueryResult
{
    /**
     * @param  list<string>  $columns  in column order, also for an empty result; duplicate names are kept
     * @param  list<list<mixed>>  $rows  each row a list in column order
     */
    public function __construct(
        public array $columns,
        public array $rows,
        // count($rows): at most max_rows.
        public int $rowCount,
        // The database returned the extra row: more rows match, how many is not known.
        public bool $hasMore,
        public LimitStrategy $strategy,
        // MariaDB ignores an ORDER BY inside the derived table unless the query has its own LIMIT.
        public bool $orderMayBeLost,
    ) {}
}
