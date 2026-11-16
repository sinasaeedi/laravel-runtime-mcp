<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tools;

/**
 * One accepted report, on its way out of the call's fiber (FiberProgress suspends with it). The message is already
 * redacted and cut. The adapter adds the progressToken and sends params() as a notifications/progress.
 */
final readonly class ProgressReport
{
    public function __construct(
        public int $done,
        public ?int $total,
        public ?string $message,
    ) {}

    /**
     * The MCP params without the token: `total` and `message` only when they are set.
     *
     * @return array{progress: int, total?: int, message?: string}
     */
    public function params(): array
    {
        $params = ['progress' => $this->done];

        if ($this->total !== null) {
            $params['total'] = $this->total;
        }

        if ($this->message !== null) {
            $params['message'] = $this->message;
        }

        return $params;
    }
}
