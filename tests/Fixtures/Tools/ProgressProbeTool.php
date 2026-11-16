<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Tools;

use Fiber;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use SinaSaeedi\RuntimeMcp\Tools\Contracts\RuntimeTool;
use SinaSaeedi\RuntimeMcp\Tools\Tier;
use SinaSaeedi\RuntimeMcp\Tools\ToolInput;
use SinaSaeedi\RuntimeMcp\Tools\ToolResult;

/**
 * 01-37: a read tool that reports `steps` times ("Step n of steps.", or `message`), then does what `then` says. Its
 * data holds ignore_user_abort() as the tool saw it, so a test can prove the streamed call ignores a user abort.
 */
final class ProgressProbeTool extends RuntimeTool
{
    public const NAME = 'progress_probe';

    public const THEN = ['ok', 'throw', 'write', 'suspend', 'nested'];

    /** The message of the exception that `then: throw` raises. */
    public const THROWN = 'The probe failed after its reports.';

    public function name(): string
    {
        return self::NAME;
    }

    public function description(): string
    {
        return 'Reports progress steps times, then does what then says. steps: 0 to 30.';
    }

    public function tier(): Tier
    {
        return Tier::Read;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'steps' => $schema->integer()->min(0)->max(30)->required(),
            'message' => $schema->string()->max(1000),
            'then' => $schema->string()->enum(self::THEN),
        ];
    }

    public function rules(): array
    {
        return [
            'steps' => ['required', 'integer', 'numeric', 'min:0', 'max:30'],
            'message' => ['string', 'max:1000'],
            'then' => ['string', 'in:'.implode(',', self::THEN)],
        ];
    }

    public function execute(ToolInput $input): ToolResult
    {
        $steps = $input->get('steps');
        $steps = is_int($steps) ? $steps : 0;
        $message = $input->get('message');

        for ($step = 1; $step <= $steps; $step++) {
            $input->progress()->report($step, $steps, is_string($message) ? $message : "Step {$step} of {$steps}.");
        }

        $nested = null;

        match ($input->get('then', 'ok')) {
            'throw' => throw new RuntimeException(self::THROWN),
            'write' => DB::table('ro_orders')->insert(['n' => 'progress']),
            // App code that suspends the call's fiber itself (no fiber of its own).
            'suspend' => Fiber::suspend('not a report'),
            // App code that runs its own fiber: a report from inside it is dropped, and the app's fiber is not
            // suspended by the package. A report after it still goes out.
            'nested' => $nested = $this->nested($input, $steps),
            default => null,
        };

        return ToolResult::ok(['steps' => $steps, 'ignore_user_abort' => ignore_user_abort(), 'nested' => $nested], 1);
    }

    /** @return array{inner_terminated: bool} */
    private function nested(ToolInput $input, int $steps): array
    {
        $inner = new Fiber(fn () => $input->progress()->report($steps + 1, null, 'inside the app fiber'));
        $inner->start();
        $input->progress()->report($steps + 2, null, 'after the app fiber');

        return ['inner_terminated' => $inner->isTerminated()];
    }
}
