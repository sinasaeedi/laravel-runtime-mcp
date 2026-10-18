<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Support;

use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Stringable;

/**
 * Takes the place of the app's default log (Psr\Log\LoggerInterface) for one test, so a Gate warning
 * can be read and laravel.log gets no lines. The audit is not affected: AuditLogger writes through
 * LogManager, which stays bound.
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<array-key, mixed>}> */
    public array $records = [];

    public static function install(): self
    {
        $logger = new self;
        app()->instance(LoggerInterface::class, $logger);

        return $logger;
    }

    /** @param  array<array-key, mixed>  $context */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => is_string($level) ? $level : '?', 'message' => (string) $message, 'context' => $context];
    }

    /** @return list<string> the messages logged at this level */
    public function messages(string $level = 'warning'): array
    {
        return array_values(array_map(
            fn (array $record): string => $record['message'],
            array_filter($this->records, fn (array $record): bool => $record['level'] === $level),
        ));
    }
}
