<?php

declare(strict_types=1);

use SinaSaeedi\RuntimeMcp\Security\Grants\MySqlGrant;
use SinaSaeedi\RuntimeMcp\Security\Grants\MySqlGrantParser;

/*
 * 01-13: the SHOW GRANTS parser. The lines are copied from MySQL 8.4, MariaDB 10.11 and MariaDB 11.4
 * (2026-10-20 probe), plus hand-made names that hold the characters the parser must not be fooled by.
 */

function parsedGrant(string $line): MySqlGrant
{
    $grant = MySqlGrantParser::parse($line);
    assert($grant instanceof MySqlGrant);

    return $grant;
}

it('reads a column list and a table privilege in one line (MySQL 8.4, a merged role)', function () {
    $grant = parsedGrant('GRANT SELECT (`id`, `number`), INSERT ON `audit_probe`.`orders` TO `probe_ro`@`%`');

    expect($grant->privileges)->toBe([
        ['name' => 'SELECT', 'columns' => ['id', 'number']],
        ['name' => 'INSERT', 'columns' => null],
    ])
        ->and($grant->targetKind)->toBe(MySqlGrant::TABLE)
        ->and($grant->database)->toBe('audit_probe')
        ->and($grant->table)->toBe('orders')
        ->and($grant->target)->toBe('`audit_probe`.`orders`')
        ->and([$grant->granteeName, $grant->granteeHost, $grant->public, $grant->grantOption])->toBe(['probe_ro', '%', false, false])
        ->and($grant->isRoleGrant())->toBeFalse();
});

it('reads a role grant with several roles and no spaces (MySQL 8.4)', function () {
    $grant = parsedGrant('GRANT `probe_default`@`%`,`probe_other`@`%` TO `probe_ro`@`%`');

    expect($grant->isRoleGrant())->toBeTrue()
        ->and($grant->roles)->toBe(['`probe_default`@`%`', '`probe_other`@`%`'])
        ->and($grant->privileges)->toBe([])
        ->and($grant->target)->toBe('');
});

it('reads a MariaDB role without a host, granted to a user and to a role', function () {
    $toUser = parsedGrant('GRANT `probe_role` TO `probe_ro`@`%`');
    $toRole = parsedGrant('GRANT `probe_nested` TO `probe_other`');

    expect($toUser->roles)->toBe(['`probe_role`'])
        ->and($toUser->isFor('probe_ro', '%'))->toBeTrue()
        ->and([$toRole->granteeName, $toRole->granteeHost])->toBe(['probe_other', null])
        ->and($toRole->isFor('probe_other', '%'))->toBeFalse();
});

it('reads the two lines of GRANT ALL ON *.* on MySQL 8.4: a static list and a dynamic list with no spaces', function () {
    $static = parsedGrant('GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, RELOAD, SHUTDOWN, PROCESS, FILE, REFERENCES, INDEX, ALTER, SHOW DATABASES, SUPER, CREATE TEMPORARY TABLES, LOCK TABLES ON *.* TO `probe_all`@`%`');
    $dynamic = parsedGrant('GRANT ALLOW_NONEXISTENT_DEFINER,APPLICATION_PASSWORD_ADMIN,AUDIT_ABORT_EXEMPT,AUDIT_ADMIN ON *.* TO `probe_all`@`%`');

    expect(array_column($static->privileges, 'name'))->toBe([
        'SELECT', 'INSERT', 'UPDATE', 'DELETE', 'CREATE', 'DROP', 'RELOAD', 'SHUTDOWN', 'PROCESS', 'FILE',
        'REFERENCES', 'INDEX', 'ALTER', 'SHOW DATABASES', 'SUPER', 'CREATE TEMPORARY TABLES', 'LOCK TABLES',
    ])
        ->and($static->targetKind)->toBe(MySqlGrant::GLOBAL)
        ->and([$static->database, $static->table])->toBe([null, null])
        ->and(array_column($dynamic->privileges, 'name'))->toBe(['ALLOW_NONEXISTENT_DEFINER', 'APPLICATION_PASSWORD_ADMIN', 'AUDIT_ABORT_EXEMPT', 'AUDIT_ADMIN']);
});

it('reads ALL as ALL PRIVILEGES, a database pattern and WITH GRANT OPTION', function () {
    $all = parsedGrant('GRANT ALL PRIVILEGES ON `app\_db`.* TO `u`@`localhost` WITH GRANT OPTION');
    $short = parsedGrant('GRANT ALL ON `app`.* TO `u`@`localhost`');

    expect($all->privileges)->toBe([['name' => 'ALL PRIVILEGES', 'columns' => null]])
        ->and($all->targetKind)->toBe(MySqlGrant::DATABASE)
        ->and($all->database)->toBe('app\_db')
        ->and($all->grantOption)->toBeTrue()
        ->and($short->privileges[0]['name'])->toBe('ALL PRIVILEGES')
        ->and($short->grantOption)->toBeFalse();
});

it('keeps no part of the MariaDB password hash', function () {
    $grant = parsedGrant("GRANT READ_ONLY ADMIN ON *.* TO `probe_ro`@`%` IDENTIFIED BY PASSWORD '*B69027D44F6E5EDC07F1AEAD1477967B16F28227'");

    expect($grant->privileges)->toBe([['name' => 'READ_ONLY ADMIN', 'columns' => null]])
        ->and([$grant->granteeName, $grant->granteeHost])->toBe(['probe_ro', '%'])
        ->and(str_contains(var_export($grant, true), 'B69027D4'))->toBeFalse();
});

it('is not fooled by commas, dots, spaces, quotes and the words ON and TO inside names', function () {
    $grant = parsedGrant('GRANT SELECT (`a, b`, `x``y`, `on to`), INSERT (`c`) ON `my.db`.`t ON x TO y` TO `we``ird`@`10.0.%`');

    expect($grant->privileges)->toBe([
        ['name' => 'SELECT', 'columns' => ['a, b', 'x`y', 'on to']],
        ['name' => 'INSERT', 'columns' => ['c']],
    ])
        ->and([$grant->database, $grant->table])->toBe(['my.db', 't ON x TO y'])
        ->and([$grant->granteeName, $grant->granteeHost])->toBe(['we`ird', '10.0.%']);
});

it('returns null for a line that is not a grant', function (string $line) {
    expect(MySqlGrantParser::parse($line))->toBeNull();
})->with([
    'MariaDB default role' => ['SET DEFAULT ROLE `probe_role` FOR `probe_ro`@`%`'],
    'MySQL partial revoke' => ['REVOKE INSERT ON `mysql`.* FROM `u`@`%`'],
    'empty' => [''],
    'no TO' => ['GRANT SELECT ON *.*'],
]);

it('skips a backslash-escaped quote inside a single-quoted part', function () {
    $grant = parsedGrant("GRANT USAGE ON *.* TO `u`@`%` IDENTIFIED BY PASSWORD 'x\\' WITH GRANT OPTION y'");

    expect($grant->grantOption)->toBeFalse()
        ->and([$grant->granteeName, $grant->granteeHost])->toBe(['u', '%']);
});

it('reads a MariaDB grant to PUBLIC', function () {
    $grant = parsedGrant('GRANT SELECT, INSERT ON `audit_probe`.`orders` TO PUBLIC');

    expect([$grant->public, $grant->granteeName, $grant->granteeHost])->toBe([true, 'PUBLIC', null])
        ->and($grant->isFor('PUBLIC', '%'))->toBeFalse();
});

it('reads a routine and a proxy target, which never match a table', function () {
    $routine = parsedGrant('GRANT EXECUTE, ALTER ROUTINE ON PROCEDURE `db`.`p` TO `u`@`%`');
    $proxy = parsedGrant("GRANT PROXY ON ''@'' TO `root`@`localhost` WITH GRANT OPTION");

    expect($routine->targetKind)->toBe(MySqlGrant::ROUTINE)
        ->and(array_column($routine->privileges, 'name'))->toBe(['EXECUTE', 'ALTER ROUTINE'])
        ->and($proxy->targetKind)->toBe(MySqlGrant::PROXY)
        ->and($proxy->privileges[0]['name'])->toBe('PROXY')
        ->and($proxy->grantOption)->toBeTrue();
});

it('reads single-quoted accounts and a lower-case keyword', function () {
    $grant = parsedGrant("grant usage on *.* to 'u'@'%'");

    expect($grant->privileges[0]['name'])->toBe('USAGE')
        ->and([$grant->granteeName, $grant->granteeHost])->toBe(['u', '%']);
});

it('compares the host without case and the user name exactly', function () {
    $grant = parsedGrant('GRANT USAGE ON *.* TO `u`@`LOCALHOST`');

    expect($grant->isFor('u', 'localhost'))->toBeTrue()
        ->and($grant->isFor('U', 'localhost'))->toBeFalse();
});

it('unquotes backticks, single and double quotes, and leaves a bare word', function (string $name, string $unquoted) {
    expect(MySqlGrantParser::unquote($name))->toBe($unquoted);
})->with([
    'backticks' => ['`a``b`', 'a`b'],
    'single quotes' => ["'it''s'", "it's"],
    'double quotes' => ['"x""y"', 'x"y'],
    'bare' => ['bare', 'bare'],
]);

it('keeps a privilege item it cannot read, as written, so that check 1 names it (2026-10-29 re-review)', function () {
    $grant = parsedGrant('GRANT SELECT, FUTURE-PRIV  X, INSERT ON `app`.`orders` TO `u`@`%`');

    expect($grant->privileges)->toBe([
        ['name' => 'SELECT', 'columns' => null],
        ['name' => 'FUTURE-PRIV X', 'columns' => null],
        ['name' => 'INSERT', 'columns' => null],
    ]);
});
