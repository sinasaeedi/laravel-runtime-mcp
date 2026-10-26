<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tools\Pipeline;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\ValidationException;
use JsonSerializable;
use SinaSaeedi\RuntimeMcp\Security\ReadOnly\WriteAttemptExplainer;
use SinaSaeedi\RuntimeMcp\Security\Redaction\Redactor;
use SinaSaeedi\RuntimeMcp\Tools\ToolCall;
use SinaSaeedi\RuntimeMcp\Tools\ToolInput;
use SinaSaeedi\RuntimeMcp\Tools\ToolOutcome;
use Stringable;
use Throwable;
use UnitEnum;

/**
 * Stage 5 (spec 4.4), the destination of the pipeline: validate the arguments, then run the tool.
 *
 * laravel/mcp does not validate arguments against the schema (spec 3.2), so rules() is the
 * enforced copy, and an argument that no rule names is refused (spec 4.3: a strict schema).
 */
final class RunTool
{
    /** The longest part of a note that comes from a message (validation or exception), in characters. */
    public const MAX_MESSAGE = 300;

    /** The longest unknown argument name repeated in a note, in characters. */
    public const MAX_KEY = 64;

    public function __construct(
        private readonly Factory $validation,
        private readonly ExceptionHandler $exceptions,
        private readonly WriteAttemptExplainer $writes,
    ) {}

    public function handle(ToolCall $call): ToolOutcome
    {
        $rules = $call->tool->rules();
        $validator = $this->validation->make($call->arguments, $rules);
        $unknown = self::unknownKeys($call->arguments, $rules);

        $validator->after(function (Validator $validator) use ($unknown): void {
            foreach ($unknown as $key) {
                $validator->errors()->add($key, "The {$key} argument is not allowed.");
            }
        });

        try {
            $validated = $validator->validate();
        } catch (ValidationException $e) {
            return ToolOutcome::failed($e, 'Invalid input: '.self::cut(implode(' ', $e->validator->errors()->all())));
        }

        try {
            $result = $call->tool->execute(new ToolInput($validated));

            // Inside the read-only transaction: redaction (stage 7) runs after the rollback (6).
            return ToolOutcome::success($result->withContent(self::plain($result->data), $result->note));
        } catch (Throwable $e) {
            // Spec 4.4: the full exception goes to the app's log; the agent gets the class and the
            // message, which RedactStage redacts on the way out. A write that the read-only transaction
            // blocked gets its own note (01-16).
            $this->exceptions->report($e);

            return ToolOutcome::failed($e, $this->writes->note($e) ?? 'The tool failed: '.$e::class.': '.self::cut($e->getMessage()));
        }
    }

    /**
     * The top-level argument names that no rule key names. A rule key "filters.status" allows
     * "filters"; keys inside it are left to the rules (array:a,b).
     *
     * @param  array<array-key, mixed>  $arguments
     * @param  array<string, mixed>  $rules
     * @return list<string>
     */
    private static function unknownKeys(array $arguments, array $rules): array
    {
        $allowed = [];

        foreach (array_keys($rules) as $rule) {
            $allowed[explode('.', $rule, 2)[0]] = true;
        }

        $unknown = [];

        foreach (array_keys($arguments) as $key) {
            if (! isset($allowed[(string) $key])) {
                $unknown[] = mb_substr((string) $key, 0, self::MAX_KEY);
            }
        }

        return $unknown;
    }

    /**
     * The data with every object that the redaction engine reads (JsonSerializable, Arrayable,
     * Stringable) read now. Reading one runs app code, an accessor or a lazy query, which must not run
     * after the rollback (spec 5.9). Enums stay: reading them runs no app code. Any other object stays,
     * and the redaction engine refuses it. Depths count as in Redactor.
     */
    private static function plain(mixed $value, int $depth = 1): mixed
    {
        if ($depth > Redactor::MAX_DEPTH) {
            return Redactor::UNSUPPORTED;
        }

        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = self::plain($item, $depth + 1);
            }

            return $value;
        }

        return match (true) {
            $value instanceof UnitEnum => $value,
            $value instanceof JsonSerializable => self::plain($value->jsonSerialize(), $depth + 1),
            $value instanceof Arrayable => self::plain($value->toArray(), $depth + 1),
            $value instanceof Stringable => (string) $value,
            default => $value,
        };
    }

    private static function cut(string $message): string
    {
        return mb_substr($message, 0, self::MAX_MESSAGE);
    }
}
