<?php

declare(strict_types=1);

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Exceptions;
use SinaSaeedi\RuntimeMcp\Tools\Contracts\RuntimeTool;
use SinaSaeedi\RuntimeMcp\Tools\Pipeline\RunTool;
use SinaSaeedi\RuntimeMcp\Tools\Tier;
use SinaSaeedi\RuntimeMcp\Tools\ToolCall;
use SinaSaeedi\RuntimeMcp\Tools\ToolInput;
use SinaSaeedi\RuntimeMcp\Tools\ToolOutcome;
use SinaSaeedi\RuntimeMcp\Tools\ToolResult;

/*
 * Stage 5 alone: validation with the tool's rules, the unknown-key rule, the cut messages, and
 * what reaches execute(). The HTTP side is PipelineBehaviourTest.
 */

/**
 * A tool with these rules whose execute() returns the input it got, or runs $execute.
 *
 * @param  array<string, mixed>  $rules
 * @param  array<array-key, mixed>  $arguments
 * @param  (Closure(ToolInput): ToolResult)|null  $execute
 */
function runToolWith(array $rules, array $arguments, ?Closure $execute = null): ToolOutcome
{
    $tool = new class($rules, $execute) extends RuntimeTool
    {
        /**
         * @param  array<string, mixed>  $toolRules
         * @param  (Closure(ToolInput): ToolResult)|null  $run
         */
        public function __construct(private readonly array $toolRules, private readonly ?Closure $run) {}

        public function name(): string
        {
            return 'run_tool_test';
        }

        public function description(): string
        {
            return 'A test tool.';
        }

        public function tier(): Tier
        {
            return Tier::Read;
        }

        public function schema(JsonSchema $schema): array
        {
            return [];
        }

        public function rules(): array
        {
            return $this->toolRules;
        }

        public function execute(ToolInput $input): ToolResult
        {
            return $this->run === null ? ToolResult::ok(['input' => $input->all()]) : ($this->run)($input);
        }
    };

    return app(RunTool::class)->handle(new ToolCall($tool, $arguments, null, hrtime(true)));
}

it('passes only the validated arguments to the tool', function () {
    $outcome = runToolWith(['text' => ['required', 'string'], 'limit' => ['integer']], ['text' => 'a']);

    expect($outcome->isError)->toBeFalse()
        ->and($outcome->result->data)->toBe(['input' => ['text' => 'a']]);
});

it('allows the first segment of a dotted rule key, and passes only the validated nested keys', function () {
    $outcome = runToolWith(['filters.status' => ['string']], ['filters' => ['status' => 'failed', 'other' => 'x']]);

    expect($outcome->isError)->toBeFalse()
        ->and($outcome->result->data)->toBe(['input' => ['filters' => ['status' => 'failed']]]);
});

it('refuses every unknown top-level key, and cuts a long key name', function () {
    $outcome = runToolWith([], ['a' => 1, 'b' => 2, str_repeat('k', 80) => 3]);

    expect($outcome->isError)->toBeTrue()
        ->and($outcome->allowed)->toBeTrue()
        ->and($outcome->result->note)->toBe('Invalid input: The a argument is not allowed. The b argument is not allowed. The '.str_repeat('k', RunTool::MAX_KEY).' argument is not allowed.');
});

it('refuses a numeric key that no rule names', function () {
    expect(runToolWith([], [0 => 'x'])->result->note)->toBe('Invalid input: The 0 argument is not allowed.');
});

it('cuts a long message to 300 characters, and reports the exception', function () {
    Exceptions::fake();
    $message = str_repeat('m', 400);

    $outcome = runToolWith([], [], fn (ToolInput $input): ToolResult => throw new RuntimeException($message));

    expect($outcome->result->note)->toBe('The tool failed: RuntimeException: '.str_repeat('m', RunTool::MAX_MESSAGE))
        ->and($outcome->errorClass)->toBe(RuntimeException::class);

    Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage() === $message);
});

it('cuts a long list of validation messages to 300 characters', function () {
    $rules = [];
    $arguments = [];

    foreach (range(1, 20) as $number) {
        $rules["field_{$number}"] = ['required'];
    }

    $note = runToolWith($rules, $arguments)->result->note;

    expect(mb_strlen((string) $note))->toBe(mb_strlen('Invalid input: ') + RunTool::MAX_MESSAGE);
});

it('redacts a validation message before the cut, so a secret across the cut does not stay in part', function () {
    $prefix = str_repeat('v', 290).' ';
    $token = 'ghp_'.str_repeat('A1b2C3d4E5', 4);
    $rule = fn (string $attribute, mixed $value, Closure $fail) => $fail($prefix.$token);

    $note = runToolWith(['token' => [$rule]], ['token' => 'x'])->result->note;

    expect($note)->toBe('Invalid input: '.mb_substr($prefix.'[redacted:github_token]', 0, RunTool::MAX_MESSAGE));
});

it('gives the default from get() for an argument sent as null, and keeps the null in all()', function () {
    $outcome = runToolWith(['limit' => ['nullable', 'integer']], ['limit' => null], fn (ToolInput $input): ToolResult => ToolResult::ok([
        'get' => $input->get('limit', 50),
        'all' => $input->all(),
    ]));

    expect($outcome->result->data)->toBe(['get' => 50, 'all' => ['limit' => null]]);
});
