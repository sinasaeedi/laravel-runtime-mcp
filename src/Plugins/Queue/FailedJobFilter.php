<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Plugins\Queue;

use Carbon\CarbonImmutable;

/** 01-23 Part B: the validated filters of queue_failed_jobs_list. Every value is exact; a null filter is off. */
final readonly class FailedJobFilter
{
    public function __construct(
        public ?string $jobClass = null,
        public ?string $queue = null,
        public ?string $connection = null,
        public ?CarbonImmutable $from = null,
        public ?CarbonImmutable $to = null,
    ) {}

    /** The filters in input order, for the `empty` note: "job_class=App\Jobs\SendInvoice, from … to …". */
    public function describe(): string
    {
        $parts = [];

        foreach (['job_class' => $this->jobClass, 'queue' => $this->queue, 'connection' => $this->connection] as $name => $value) {
            if ($value !== null) {
                $parts[] = "{$name}={$value}";
            }
        }

        if ($this->from !== null) {
            $parts[] = 'from '.FailedJobWindow::iso($this->from);
        }

        if ($this->to !== null) {
            $parts[] = 'to '.FailedJobWindow::iso($this->to);
        }

        return implode(', ', $parts);
    }
}
