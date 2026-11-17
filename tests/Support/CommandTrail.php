<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Support;

use Illuminate\Contracts\Console\Kernel as KernelContract;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * What a command leaves behind (01-27): every event it dispatches, every log line, and every file under the app's
 * base path. Start it before Artisan::call(), then read it with the secret the command printed.
 *
 * Event payloads are read as text: strings, arrays, an Eloquent model's attributes, and the public properties of
 * other objects. The console input and output objects are left out: Artisan::call() puts its output buffer (which
 * holds the printed secret) in CommandFinished, and a real `php artisan` run writes to stdout instead.
 * Never Log::spy() here: it replaces LogManager and breaks the audit (01-09). MessageLogged sees every channel.
 *
 * Laravel sends CommandStarting and CommandFinished only outside unit tests (Kernel::__construct() skips
 * rerouteSymfonyCommandEvents() while runningUnitTests()), so start() turns them on, as a real run has them. Call it
 * before the first Artisan::call() of the test: the kernel hands the dispatcher to Artisan when it builds it.
 */
final class CommandTrail
{
    /** @var list<string> the text of every event payload, in order */
    public array $texts = [];

    /** @var list<string> every log message, any channel */
    public array $logged = [];

    /** @var array<string, string> path => sha1 of the content, before the command */
    private array $files;

    private function __construct()
    {
        $this->files = self::files();
    }

    public static function start(): self
    {
        $trail = new self;
        app(KernelContract::class)->rerouteSymfonyCommandEvents();

        Event::listen('*', function (string $event, array $payload) use ($trail): void {
            $trail->texts[] = $event;

            foreach ($payload as $value) {
                self::read($value, $trail->texts, 0);
            }
        });

        Event::listen(MessageLogged::class, function (MessageLogged $logged) use ($trail): void {
            $trail->logged[] = $logged->message;
        });

        return $trail;
    }

    /** @return list<string> the payload texts that hold the secret */
    public function eventsHolding(string $secret): array
    {
        return array_values(array_filter($this->texts, fn (string $text): bool => str_contains($text, $secret)));
    }

    /** @return list<string> the files that were added, changed or deleted since start() */
    public function changedFiles(): array
    {
        $now = self::files();
        $changed = [];

        foreach ($now + $this->files as $path => $_) {
            if (($now[$path] ?? null) !== ($this->files[$path] ?? null)) {
                $changed[] = $path;
            }
        }

        sort($changed);

        return $changed;
    }

    /** @param  list<string>  $out */
    private static function read(mixed $value, array &$out, int $depth): void
    {
        if ($depth > 4) {
            return;
        }

        if (is_string($value)) {
            $out[] = $value;

            return;
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                self::read($item, $out, $depth + 1);
            }

            return;
        }

        if (! is_object($value) || $value instanceof InputInterface || $value instanceof OutputInterface
            || $value instanceof Command || $value instanceof ConsoleApplication || $value instanceof Container) {
            return;
        }

        if ($value instanceof Model) {
            self::read($value->getAttributes(), $out, $depth + 1);

            return;
        }

        foreach (get_object_vars($value) as $property) {
            self::read($property, $out, $depth + 1);
        }
    }

    /** @return array<string, string> */
    private static function files(): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path(), RecursiveDirectoryIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->isFile()) {
                // The content, not the size and time: a file written again in the same second with the same
                // size would look unchanged.
                $files[$file->getPathname()] = (string) sha1_file($file->getPathname());
            }
        }

        return $files;
    }
}
