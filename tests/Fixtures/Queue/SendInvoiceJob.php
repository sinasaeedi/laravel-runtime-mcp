<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Queue;

use Illuminate\Contracts\Queue\ShouldQueue;
use SinaSaeedi\RuntimeMcp\Tests\Support\FailedJobs;

/**
 * 01-24: a queued job that carries customer data in plain properties, so Laravel's own Queue::createPayload() puts it
 * into data.command (FailedJobs::realPayload()). A model would not do: SerializesModels keeps only its identifier.
 */
final class SendInvoiceJob implements ShouldQueue
{
    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(
        public string $email = FailedJobs::CUSTOMER_EMAIL,
        public string $address = FailedJobs::CUSTOMER_ADDRESS,
    ) {}

    public function handle(): void {}
}
