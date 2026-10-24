<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Security\Sql;

/** Spec 5.2, 01-15: how the database limited the rows. For tests and logs, never for the agent. */
enum LimitStrategy: string
{
    // SELECT * FROM (<query>) AS runtime_mcp_q LIMIT <n>: every driver.
    case Wrapped = 'wrapped';

    // PostgreSQL only: DECLARE … CURSOR FOR <query>, then FETCH <n>.
    case Cursor = 'cursor';

    // PostgreSQL only: the statement as sent (EXPLAIN, SHOW). statement_timeout still applies.
    case Bare = 'bare';
}
