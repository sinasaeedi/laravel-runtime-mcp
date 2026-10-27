<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Support;

use Closure;
use PhpToken;
use RuntimeException;

/**
 * Finds calls of banned global functions, and uses of banned PHP classes, by a token scan. Pest's
 * arch API checks a function only when it exists in the running PHP, so a missing extension would
 * make its check pass with nothing checked, and it ignores PHP's own classes (SoapClient) entirely.
 * This scan reads the source and needs no extension.
 */
final class SourceScanner
{
    /**
     * Every call of a banned global function, and every `use function` import of one, in the files.
     *
     * @param  list<string>  $files
     * @param  list<string>  $functions
     * @return list<string> "relative/path.php:line name"
     */
    public static function bannedCalls(array $files, array $functions): array
    {
        return self::scanFiles($files, static fn (string $code): array => self::bannedCallsInCode($code, $functions));
    }

    /**
     * Every use of a banned global class (`use`, `new`, a type, `::class`, a static call), in the files.
     *
     * @param  list<string>  $files
     * @param  list<string>  $classes
     * @return list<string> "relative/path.php:line name"
     */
    public static function bannedClasses(array $files, array $classes): array
    {
        return self::scanFiles($files, static fn (string $code): array => self::bannedClassesInCode($code, $classes));
    }

    /**
     * @param  list<string>  $functions
     * @return list<array{0: string, 1: int}>
     */
    public static function bannedCallsInCode(string $code, array $functions): array
    {
        $banned = array_map('strtolower', $functions);
        $tokens = self::tokens($code);
        $found = [];
        $inUseFunction = false;

        foreach ($tokens as $i => $token) {
            // Every name up to the `;` of a `use function` statement is an import: `use function a, b as c;`.
            if ($token->is(T_USE) && ($tokens[$i + 1] ?? null)?->is(T_FUNCTION)) {
                $inUseFunction = true;
            } elseif ($token->text === ';') {
                $inUseFunction = false;
            }

            if (! $token->is([T_STRING, T_NAME_FULLY_QUALIFIED])) {
                continue;
            }

            $name = strtolower(ltrim($token->text, '\\'));

            if (! in_array($name, $banned, true)) {
                continue;
            }

            $previous = $tokens[$i - 1] ?? null;
            $next = $tokens[$i + 1] ?? null;

            // A method call, a static call, a constant, `new`: not the global function.
            if ($previous?->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_NEW, T_CONST])) {
                continue;
            }

            // The name after `as` is the alias, not the imported function.
            $isUseFunction = $inUseFunction && ! $previous?->is(T_AS);

            // `function dump()` declares a function or a method; it does not call one.
            if ($previous?->is(T_FUNCTION) && ! $isUseFunction) {
                continue;
            }

            if ($next?->text === '(' || $isUseFunction) {
                $found[] = [$name, $token->line];
            }
        }

        return $found;
    }

    /**
     * @param  list<string>  $classes
     * @return list<array{0: string, 1: int}>
     */
    public static function bannedClassesInCode(string $code, array $classes): array
    {
        $banned = array_map('strtolower', $classes);
        $tokens = self::tokens($code);
        $found = [];

        foreach ($tokens as $i => $token) {
            if (! $token->is([T_STRING, T_NAME_FULLY_QUALIFIED])) {
                continue;
            }

            if (! in_array(strtolower(ltrim($token->text, '\\')), $banned, true)) {
                continue;
            }

            $previous = $tokens[$i - 1] ?? null;

            // A member (`->SoapClient`, `::SoapClient`), an alias (`as SoapClient`) or a declaration of
            // our own: not the global class.
            if ($previous?->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_AS, T_FUNCTION, T_CONST, T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM])) {
                continue;
            }

            $found[] = [ltrim($token->text, '\\'), $token->line];
        }

        return $found;
    }

    /**
     * @param  list<string>  $files
     * @param  Closure(string): list<array{0: string, 1: int}>  $scan
     * @return list<string>
     */
    private static function scanFiles(array $files, Closure $scan): array
    {
        $root = dirname(__DIR__, 2).'/';
        $found = [];

        foreach ($files as $file) {
            // An unreadable file must fail the scan, not pass it as empty.
            $code = @file_get_contents($file);

            if ($code === false) {
                throw new RuntimeException(sprintf('Cannot read [%s] to scan it.', $file));
            }

            foreach ($scan($code) as [$name, $line]) {
                $found[] = str_replace($root, '', $file).':'.$line.' '.$name;
            }
        }

        return $found;
    }

    /** @return list<PhpToken> Without whitespace and comments. */
    private static function tokens(string $code): array
    {
        return array_values(array_filter(
            PhpToken::tokenize($code),
            static fn (PhpToken $token): bool => ! $token->isIgnorable(),
        ));
    }
}
