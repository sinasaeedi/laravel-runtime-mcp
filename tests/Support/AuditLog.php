<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Support;

use UnexpectedValueException;

/**
 * Reads the package's audit files (storage/logs/runtime-mcp-<date>.log in the Testbench skeleton).
 * The files outlive a test, so every audit test clears them before and after it runs.
 */
final class AuditLog
{
    /** @return list<string> */
    public static function files(string $pattern = 'runtime-mcp-*.log'): array
    {
        $files = glob(storage_path('logs/'.$pattern));

        return $files === false ? [] : $files;
    }

    public static function clear(): void
    {
        foreach (self::files() as $file) {
            unlink($file);
        }
    }

    /** @return list<string> every line of the audit files, in order */
    public static function lines(string $pattern = 'runtime-mcp-*.log'): array
    {
        $lines = [];

        foreach (self::files($pattern) as $file) {
            $content = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

            foreach ($content === false ? [] : $content as $line) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * Each line decoded: a Monolog JSON record (message, context, level_name, channel, ...).
     *
     * @return list<array<array-key, mixed>>
     */
    public static function records(string $pattern = 'runtime-mcp-*.log'): array
    {
        return array_map(self::decode(...), self::lines($pattern));
    }

    /**
     * The audit entry of each line (the record's context).
     *
     * @return list<array<array-key, mixed>>
     */
    public static function entries(string $pattern = 'runtime-mcp-*.log'): array
    {
        return array_map(function (array $record): array {
            $context = $record['context'] ?? null;

            if (! is_array($context)) {
                throw new UnexpectedValueException('An audit line has no context object.');
            }

            return $context;
        }, self::records($pattern));
    }

    /** @return array<array-key, mixed> */
    public static function decode(string $line): array
    {
        $record = json_decode($line, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($record)) {
            throw new UnexpectedValueException('An audit line is not a JSON object.');
        }

        return $record;
    }
}
