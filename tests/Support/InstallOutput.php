<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Support;

use Illuminate\Database\Connection;
use PHPUnit\Framework\Assert;
use SinaSaeedi\RuntimeMcp\Console\InstallCommand;

/**
 * What a script does with runtime-mcp:install's output (01-28, as 01-33 and 01-34 will): cut a block between its
 * marker lines, run the role SQL, and load the printed connection as config/database.php would.
 */
final class InstallOutput
{
    /** @return list<string> the lines strictly between the two marker lines, which must each occur once */
    public static function between(string $output, string $begin, string $end): array
    {
        $lines = explode("\n", $output);
        $from = array_keys($lines, $begin, true);
        $to = array_keys($lines, $end, true);

        Assert::assertCount(1, $from, "Marker [{$begin}] must occur once.");
        Assert::assertCount(1, $to, "Marker [{$end}] must occur once.");
        Assert::assertGreaterThan($from[0], $to[0]);

        return array_slice($lines, $from[0] + 1, $to[0] - $from[0] - 1);
    }

    /**
     * Where a test connection points, as strings: the DB_* values a script would set in .env.
     *
     * @return array{host: string, port: string, database: string, username: string, password: string}
     */
    public static function place(string $connection): array
    {
        $config = config('database.connections.'.$connection);
        Assert::assertIsArray($config);
        $text = fn (string $key): string => is_scalar($config[$key] ?? null) ? (string) $config[$key] : '';

        return [
            'host' => $text('host'), 'port' => $text('port'), 'database' => $text('database'),
            'username' => $text('username'), 'password' => $text('password'),
        ];
    }

    /** The role SQL as it was printed, one string with a final line break (as RoleSqlGenerator returns it). */
    public static function sql(string $output): string
    {
        return implode("\n", self::between($output, InstallCommand::SQL_BEGIN, InstallCommand::SQL_END))."\n";
    }

    /**
     * Runs the printed SQL as an operator would: CHANGE_ME replaced, the comment lines left out, one statement per
     * ";" at a line end (the PostgreSQL CREATE ROLE spans two lines), as ReadOnlyRole::create() does.
     */
    public static function runSql(Connection $privileged, string $sql, string $password): void
    {
        $sql = str_replace("'CHANGE_ME'", "'{$password}'", $sql);
        $lines = array_filter(explode("\n", $sql), fn (string $line): bool => ! str_starts_with($line, '--'));

        foreach (preg_split('/;\s*(?:\n|$)/', implode("\n", $lines)) ?: [] as $statement) {
            if (trim($statement) !== '') {
                $privileged->getPdo()->exec($statement);
            }
        }
    }

    /**
     * The printed connection, loaded from a PHP file as config/database.php loads it, with these env values set
     * only while the file runs (env() reads $_SERVER and $_ENV).
     *
     * @param  array<string, string>  $env
     * @return array<array-key, mixed> the connection's config
     */
    public static function connection(string $output, array $env): array
    {
        $file = tempnam(sys_get_temp_dir(), 'rmcp-install-');
        Assert::assertIsString($file);
        $code = "<?php\n\nreturn [\n".implode("\n", self::between($output, InstallCommand::CONNECTION_BEGIN, InstallCommand::CONNECTION_END))."\n];\n";
        file_put_contents($file, $code);
        $saved = [];

        foreach ($env as $key => $value) {
            $saved[$key] = [$_SERVER[$key] ?? null, $_ENV[$key] ?? null];
            $_SERVER[$key] = $value;
            $_ENV[$key] = $value;
        }

        try {
            $connections = require $file;
        } finally {
            unlink($file);

            foreach ($saved as $key => [$server, $environment]) {
                if ($server === null) {
                    unset($_SERVER[$key]);
                } else {
                    $_SERVER[$key] = $server;
                }

                if ($environment === null) {
                    unset($_ENV[$key]);
                } else {
                    $_ENV[$key] = $environment;
                }
            }
        }

        Assert::assertIsArray($connections);
        Assert::assertSame([InstallCommand::CONNECTION], array_keys($connections));
        $connection = $connections[InstallCommand::CONNECTION];
        Assert::assertIsArray($connection);

        return $connection;
    }
}
