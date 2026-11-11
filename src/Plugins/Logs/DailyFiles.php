<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Plugins\Logs;

use Carbon\CarbonImmutable;

/**
 * 01-25 Part D, "Daily files": the existing dated files of a daily channel, newest first. Monolog's RotatingFileHandler
 * names them `{filename}-{Y-m-d}` plus `.{extension}` when the path has one, beside `path`, in PHP's default timezone
 * (Laravel gives the handler no timezone). A day with no file is no error: Laravel writes a file only on a day with an
 * entry.
 */
final class DailyFiles
{
    /**
     * @param  CarbonImmutable|null  $from  files dated before this day are left out
     * @param  CarbonImmutable  $to  files dated after this day are left out
     * @return list<string> the paths, newest first, at most $source->maxFiles
     */
    public static function list(LogSource $source, ?CarbonImmutable $from, CarbonImmutable $to): array
    {
        [$directory, $name, $extension] = self::parts($source->path);
        $timezone = date_default_timezone_get();
        $first = $from?->setTimezone($timezone)->format('Y-m-d');
        $last = $to->setTimezone($timezone)->format('Y-m-d');
        $pattern = '/^'.preg_quote($name, '/').'-(\d{4})-(\d{2})-(\d{2})'.preg_quote($extension, '/').'$/D';
        $glob = glob(self::escape($directory).'/'.self::escape($name).'-[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]'.self::escape($extension));
        $dated = [];

        foreach ($glob === false ? [] : $glob as $path) {
            if (preg_match($pattern, basename($path), $m) !== 1 || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                continue;
            }

            $day = "{$m[1]}-{$m[2]}-{$m[3]}";

            if ($day <= $last && ($first === null || $day >= $first) && is_file($path)) {
                $dated[$day] = $path;
            }
        }

        krsort($dated, SORT_STRING);

        return array_slice(array_values($dated), 0, $source->maxFiles);
    }

    /** The name pattern for a note: "laravel-YYYY-MM-DD.log". */
    public static function pattern(LogSource $source): string
    {
        [, $name, $extension] = self::parts($source->path);

        return "{$name}-YYYY-MM-DD{$extension}";
    }

    /** @return array{string, string, string} directory, file name without the extension, ".extension" or "" */
    private static function parts(string $path): array
    {
        $info = pathinfo($path);
        $extension = isset($info['extension']) ? '.'.$info['extension'] : '';

        return [$info['dirname'] ?? '.', $info['filename'], $extension];
    }

    /** glob() reads [, ], *, ? and \ as pattern characters; a name may hold them. */
    private static function escape(string $text): string
    {
        return (string) preg_replace('/([\[\]*?\\\\])/', '\\\\$1', $text);
    }
}
