<?php

declare(strict_types=1);

use SinaSaeedi\RuntimeMcp\Plugins\Database\PermissionMessage;
use SinaSaeedi\RuntimeMcp\Security\Sql\Exceptions\QueryPermissionDenied;

/*
 * Spec 5.1, 01-20: the table a permission error names. The messages are the real ones, measured as a
 * column-grant role on PostgreSQL 17.11, MySQL 8.4.11, MariaDB 11.4.13 and 10.11.19 (01-11 and 01-20
 * audits); the integration files get the same messages through PDO.
 */

function permissionMessageTable(?int $driverCode, string $message): ?string
{
    return PermissionMessage::table(new QueryPermissionDenied($driverCode === null ? '42501' : '42000', $driverCode, $message));
}

it('PM1 reads the table, view or materialized view of a PostgreSQL 42501', function (string $message, string $table) {
    expect(permissionMessageTable(null, $message))->toBe($table);
})->with([
    'a table (also a column grant)' => ['ERROR:  permission denied for table probe_users', 'probe_users'],
    'a view' => ['ERROR:  permission denied for view probe_secrets', 'probe_secrets'],
    'a materialized view' => ['ERROR:  permission denied for materialized view rmcp_audit_mv', 'rmcp_audit_mv'],
    'a system table' => ['ERROR:  permission denied for table pg_authid', 'pg_authid'],
    'mixed case and a space' => ['ERROR:  permission denied for table Order Items', 'Order Items'],
    'a prefixed name' => ['ERROR:  permission denied for table pfx_items', 'pfx_items'],
    'no ERROR: prefix' => ['permission denied for table users', 'users'],
    'a second line' => ["ERROR:  permission denied for table users\nDETAIL:  something", 'users'],
]);

it('PM2 names no table for the other PostgreSQL permission errors', function (string $message) {
    expect(permissionMessageTable(null, $message))->toBeNull();
})->with([
    'a schema' => ['ERROR:  permission denied for schema secret'],
    'a function' => ['ERROR:  permission denied for function pg_read_file'],
    'a sequence' => ['ERROR:  permission denied for sequence users_id_seq'],
    'a process' => ['ERROR:  permission denied to terminate process'],
    'a table in another sentence' => ['ERROR:  must be owner of table users'],
    'empty' => [''],
]);

it('PM3 reads the quoted table of MySQL and MariaDB 1142 and 1143', function (int $code, string $message, string $table) {
    expect(permissionMessageTable($code, $message))->toBe($table);
})->with([
    'MySQL 1142, SELECT *' => [1142, "SELECT command denied to user 'probe_ro'@'127.0.0.1' for table 'probe_users'", 'probe_users'],
    'MySQL 1142, a missing table' => [1142, "SELECT command denied to user 'rmcp_audit_probe'@'172.17.0.1' for table 'rmcp_audit_nope'", 'rmcp_audit_nope'],
    'MySQL 1142, another database (no database in the message)' => [1142, "SELECT command denied to user 'rmcp_audit_probe'@'172.17.0.1' for table 'user'", 'user'],
    'MySQL 1142, a quote in the name' => [1142, "SELECT command denied to user 'u'@'h' for table 'it's'", "it's"],
    'MySQL and MariaDB 1143' => [1143, "SELECT command denied to user 'probe_ro'@'127.0.0.1' for column 'password' in table 'probe_users'", 'probe_users'],
    '1143, a missing column' => [1143, "SELECT command denied to user 'rmcp_audit_probe'@'172.17.0.1' for column 'nope' in table 'rmcp_audit_t'", 'rmcp_audit_t'],
    '1143, backticks' => [1143, 'SELECT command denied to user `u`@`h` for column `password` in table `users`', 'users'],
]);

it('PM4 reads the backticked, schema-qualified table of MariaDB 1142, with doubled backticks undone', function (string $message, string $table) {
    expect(permissionMessageTable(1142, $message))->toBe($table);
})->with([
    'SELECT *' => ["SELECT command denied to user 'probe_ro'@'127.0.0.1' for table `runtime_mcp`.`probe_users`", 'runtime_mcp.probe_users'],
    'another database' => ["SELECT command denied to user 'rmcp_audit_probe'@'172.17.0.1' for table `mysql`.`user`", 'mysql.user'],
    'doubled backticks' => ["SELECT command denied to user 'u'@'h' for table `a``b`.`c``d`", 'a`b.c`d'],
    'a dot inside a name' => ["SELECT command denied to user 'u'@'h' for table `db`.`a.b`", 'db.a.b'],
    'no schema' => ["SELECT command denied to user 'u'@'h' for table `orders`", 'orders'],
]);

it('PM5 names no table for 1044, 1370, other codes, or a message of another shape', function (int $code, string $message) {
    expect(permissionMessageTable($code, $message))->toBeNull();
})->with([
    '1044' => [1044, "Access denied for user 'probe_ro'@'%' to database 'runtime_mcp'"],
    '1370' => [1370, "execute command denied to user 'probe_ro'@'%' for routine 'runtime_mcp.write_it'"],
    '1142 of another shape' => [1142, 'SELECT command denied to user for something else'],
    '1143 of another shape' => [1143, "SELECT command denied to user 'u'@'h' for column 'password'"],
    'another code with a table' => [1227, "SELECT command denied to user 'u'@'h' for table 'users'"],
]);
