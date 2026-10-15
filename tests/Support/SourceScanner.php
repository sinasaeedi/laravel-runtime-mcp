<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Support;

use PhpToken;

/**
 * Finds calls of banned global functions by a token scan. Pest's arch API checks a function only
 * when it exists in the running PHP, so a missing extension would make its check pass with nothing
 * checked. This scan reads the source and needs no extension.
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
        $root = dirname(__DIR__, 2).'/';
        $found = [];

        foreach ($files as $file) {
            foreach (self::bannedCallsInCode((string) file_get_contents($file), $functions) as [$name, $line]) {
                $found[] = str_replace($root, '', $file).':'.$line.' '.$name;
            }
        }

        return $found;
    }

    /**
     * @param  list<string>  $functions
     * @return list<array{0: string, 1: int}>
     */
    public static function bannedCallsInCode(string $code, array $functions): array
    {
        $banned = array_map('strtolower', $functions);
        $tokens = array_values(array_filter(
            PhpToken::tokenize($code),
            static fn (PhpToken $token): bool => ! $token->isIgnorable(), // drops whitespace and comments
        ));
        $found = [];

        foreach ($tokens as $i => $token) {
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

            $isUseFunction = $previous?->is(T_FUNCTION) && ($tokens[$i - 2] ?? null)?->is(T_USE);

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
}
