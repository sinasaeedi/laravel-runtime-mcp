<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Support;

use UnexpectedValueException;

/**
 * Checks a decoded JSON value against a JSON schema that uses only these keywords: type (a name or
 * a list), enum, const, anyOf, minimum, properties, required, additionalProperties: false,
 * description. Enough for EnvelopeSchema (01-08), with no new dev dependency. Any other keyword
 * throws, so the schema cannot grow past what this checks.
 */
final class JsonSchemaSubset
{
    private const KEYWORDS = ['type', 'enum', 'const', 'anyOf', 'minimum', 'properties', 'required', 'additionalProperties', 'description'];

    /**
     * @param  array<array-key, mixed>  $schema
     * @return list<string> the violations, empty when the value matches
     */
    public static function violations(mixed $value, array $schema, string $path = '$'): array
    {
        $unknown = array_diff(array_map(strval(...), array_keys($schema)), self::KEYWORDS);

        if ($unknown !== []) {
            throw new UnexpectedValueException('The checker does not know: '.implode(', ', $unknown));
        }

        $errors = [];

        if (isset($schema['type'])) {
            $types = is_array($schema['type']) ? $schema['type'] : [$schema['type']];

            if (! in_array(self::typeOf($value), $types, true)
                && ! (self::typeOf($value) === 'integer' && in_array('number', $types, true))) {
                $names = array_map(fn (mixed $type): string => is_string($type) ? $type : '?', $types);

                return ["{$path}: is ".self::typeOf($value).', expected '.implode('|', $names)];
            }
        }

        if (array_key_exists('const', $schema) && $value !== $schema['const']) {
            $errors[] = "{$path}: is not the constant";
        }

        if (isset($schema['enum']) && is_array($schema['enum']) && ! in_array($value, $schema['enum'], true)) {
            $errors[] = "{$path}: is not in the enum";
        }

        if (isset($schema['minimum']) && is_int($value) && $value < $schema['minimum']) {
            $errors[] = "{$path}: is below the minimum";
        }

        if (isset($schema['anyOf']) && is_array($schema['anyOf'])) {
            $matches = array_filter($schema['anyOf'], fn (mixed $branch): bool => is_array($branch) && self::violations($value, $branch, $path) === []);

            if ($matches === []) {
                $errors[] = "{$path}: matches no anyOf branch";
            }
        }

        if (is_array($value) && self::typeOf($value) === 'object') {
            $errors = [...$errors, ...self::objectViolations($value, $schema, $path)];
        }

        return $errors;
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @param  array<array-key, mixed>  $schema
     * @return list<string>
     */
    private static function objectViolations(array $value, array $schema, string $path): array
    {
        $errors = [];
        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];

        foreach (is_array($schema['required'] ?? null) ? $schema['required'] : [] as $key) {
            if (is_string($key) && ! array_key_exists($key, $value)) {
                $errors[] = "{$path}.{$key}: is required";
            }
        }

        foreach ($value as $key => $item) {
            if (isset($properties[$key]) && is_array($properties[$key])) {
                $errors = [...$errors, ...self::violations($item, $properties[$key], "{$path}.{$key}")];
            } elseif (($schema['additionalProperties'] ?? true) === false) {
                $errors[] = "{$path}.{$key}: is not allowed";
            }
        }

        return $errors;
    }

    /** The JSON type of a value decoded with json_decode(..., true). A non-list array is an object. */
    private static function typeOf(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => 'boolean',
            is_int($value) => 'integer',
            is_float($value) => 'number',
            is_string($value) => 'string',
            is_array($value) && ($value === [] || array_is_list($value)) => 'array',
            default => 'object',
        };
    }
}
