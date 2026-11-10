<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Plugins\Queue;

use SinaSaeedi\RuntimeMcp\Security\Redaction\Redactor;

/**
 * Spec 5.6 "Job payloads", 6.7, 01-24 Part B: a failed job's payload with its data replaced by [redacted:payload].
 *
 * An allow-list, so it fails closed: only the keys that Laravel writes to describe a job are shown
 * (Queue::createObjectPayload(), createStringPayload() and createPayload()'s delay, plus RedisQueue's id and attempts;
 * checked in Laravel 12.69 and 13.36). Every other top-level key keeps its name and loses its value:
 * Queue::createPayloadUsing() hooks and Horizon (v1.5.0: tags, type, pushedAt, silenced) add app data there. A kept
 * key whose value is not a scalar is masked too, because a hook can overwrite a known key.
 *
 * Under `data` only commandName and batchId are shown, and command is masked. A `data` with any other key (the data
 * array of a string job, or a hook that replaced it) is masked whole: its keys are the app's.
 *
 * JSON only: never unserialize() and never decrypt data.command (it would run app code and rebuild models).
 */
final class PayloadMask
{
    /** json_decode()'s depth: 31 nested arrays or objects at most. Laravel's payload has two. */
    public const DEPTH = 32;

    /** The top-level keys shown, when their value is a scalar or null. */
    public const KEPT = [
        'uuid', 'displayName', 'job', 'maxTries', 'maxExceptions', 'failOnTimeout', 'countCrashesAsExceptions', 'backoff',
        'timeout', 'retryUntil', 'deleteWhenMissingModels', 'createdAt', 'delay', 'id', 'attempts',
    ];

    /** The keys under data that Laravel writes; command holds the serialized job. */
    public const DATA_KEYS = ['commandName', 'command', 'batchId'];

    /** The keys under data that are shown, when their value is a scalar or null. */
    public const KEPT_DATA = ['commandName', 'batchId'];

    /**
     * The payload as an array when it is a JSON object within DEPTH, else null (not Laravel's format: show the marker).
     *
     * @return array<array-key, mixed>|null
     */
    public static function decode(string $payload): ?array
    {
        $decoded = json_decode($payload, true, self::DEPTH);

        // An object, never a list: '{}' and '[]' both decode to [], so the first character tells them apart.
        return is_array($decoded) && str_starts_with(ltrim($payload), '{') ? $decoded : null;
    }

    /**
     * @param  array<array-key, mixed>  $payload  from decode()
     * @return array<string, mixed>
     */
    public static function mask(array $payload): array
    {
        $masked = [];

        foreach ($payload as $key => $value) {
            $key = (string) $key;

            $masked[$key] = match (true) {
                $key === 'data' => self::data($value),
                in_array($key, self::KEPT, true) && self::isScalar($value) => $value,
                default => Redactor::PAYLOAD,
            };
        }

        return $masked;
    }

    /** @return array<string, mixed>|string */
    private static function data(mixed $data): array|string
    {
        if (! is_array($data) || $data === [] || array_is_list($data)) {
            return Redactor::PAYLOAD;
        }

        $masked = [];

        foreach ($data as $key => $value) {
            $key = (string) $key;

            if (! in_array($key, self::DATA_KEYS, true)) {
                return Redactor::PAYLOAD;
            }

            $masked[$key] = in_array($key, self::KEPT_DATA, true) && self::isScalar($value) ? $value : Redactor::PAYLOAD;
        }

        return $masked;
    }

    private static function isScalar(mixed $value): bool
    {
        return $value === null || is_scalar($value);
    }
}
