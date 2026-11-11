<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Plugins\Logs;

use ErrorException;
use Generator;

/**
 * Spec 5.8, 01-25 Part B: the lines of one file, newest first, read from the end in 64 KB chunks within the call's
 * Budget. Never file() or file_get_contents() on a log.
 *
 * Chunks are joined as bytes and split only at "\n": 0x0A never occurs inside a UTF-8 character, so no character is
 * cut. The oldest, incomplete line at the budget edge is never yielded (it belongs to an older entry).
 */
final class ReverseLineReader
{
    public const CHUNK_BYTES = 65_536;

    public const MISSING = 'File %s does not exist.';

    public const UNREADABLE = 'File %s is not readable by the PHP process.';

    /** Its newest entries may already be in the result (LogScan), so the note says what was left out. */
    public const CHANGED = 'File %s changed while it was read; its older entries were not searched.';

    /** The reason this file cannot be opened, or null. Before fopen(): Laravel turns its warning into an ErrorException. */
    public static function problem(string $path): ?string
    {
        return match (true) {
            ! is_file($path) => sprintf(self::MISSING, basename($path)),
            ! is_readable($path) => sprintf(self::UNREADABLE, basename($path)),
            default => null,
        };
    }

    /**
     * Lines without "\n" and a trailing "\r", newest first. The generator returns why it stopped.
     *
     * @return Generator<int, string, mixed, ReadEnd>
     */
    public function lines(string $path, Budget $budget): Generator
    {
        try {
            $handle = fopen($path, 'rb');
        } catch (ErrorException) {
            return ReadEnd::Unreadable;
        }

        if ($handle === false) {
            return ReadEnd::Unreadable;
        }

        try {
            $stat = fstat($handle);
            // Lines written after this point are not read: the end is fixed at open.
            $offset = $stat === false ? 0 : $stat['size'];
            $carry = '';
            $last = true;

            while ($offset > 0) {
                $wanted = min(self::CHUNK_BYTES, $offset);
                $granted = $budget->take($wanted);

                if ($granted === 0) {
                    return ReadEnd::Budget;
                }

                $offset -= $granted;

                if (fseek($handle, $offset) !== 0) {
                    return ReadEnd::Changed;
                }

                $chunk = fread($handle, $granted);

                if ($chunk === false || strlen($chunk) !== $granted) {
                    return ReadEnd::Changed;
                }

                $parts = explode("\n", $chunk.$carry);
                // The first part may start inside a line: keep it for the next chunk.
                $carry = array_shift($parts);

                for ($i = count($parts) - 1; $i >= 0; $i--) {
                    // A file that ends with "\n" has an empty last part, which is no line.
                    if ($last && $i === count($parts) - 1 && $parts[$i] === '') {
                        $last = false;

                        continue;
                    }

                    $last = false;

                    yield self::line($parts[$i]);
                }

                if ($granted < $wanted) {
                    return ReadEnd::Budget;
                }
            }

            if (! ($last && $carry === '')) {
                yield self::line($carry);
            }

            return ReadEnd::Start;
        } finally {
            fclose($handle);
        }
    }

    private static function line(string $line): string
    {
        return str_ends_with($line, "\r") ? substr($line, 0, -1) : $line;
    }
}
