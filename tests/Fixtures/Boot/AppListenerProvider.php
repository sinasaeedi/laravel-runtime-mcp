<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Boot;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;
use SinaSaeedi\RuntimeMcp\Audit\Events\BootValidationFailed;

/**
 * An app's own provider (bootstrap/providers.php): Laravel boots it after the package providers. It
 * listens for BootValidationFailed in boot(), as the Laravel docs show for AppServiceProvider.
 */
final class AppListenerProvider extends ServiceProvider
{
    /** @var list<BootValidationFailed> */
    public array $events = [];

    public function boot(Dispatcher $events): void
    {
        $events->listen(BootValidationFailed::class, function (BootValidationFailed $event): void {
            $this->events[] = $event;
        });
    }
}
