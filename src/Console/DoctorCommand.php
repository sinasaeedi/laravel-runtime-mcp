<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use SinaSaeedi\RuntimeMcp\Doctor\CheckStatus;
use SinaSaeedi\RuntimeMcp\Doctor\Doctor;
use SinaSaeedi\RuntimeMcp\Security\Environment;
use SinaSaeedi\RuntimeMcp\Security\Redaction\InvalidRedactionPattern;
use SinaSaeedi\RuntimeMcp\Security\Redaction\Redactor;
use SinaSaeedi\RuntimeMcp\Server\RuntimeServer;
use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * Spec 7.3: runs every doctor check, prints one report, and exits 1 on any failure, so a deploy
 * script can stop a bad release (rule 15). It reads; it never changes anything.
 *
 * Every text goes through the redaction engine before it is printed: a deploy log is often public to
 * a team, and a plugin check or a boot message can hold a value the operator pasted by mistake.
 */
final class DoctorCommand extends Command
{
    public const NAME = 'runtime-mcp:doctor';

    private const GLYPHS = [
        'pass' => '<fg=green;options=bold>✓</>',
        'warn' => '<fg=yellow;options=bold>!</>',
        'fail' => '<fg=red;options=bold>✗</>',
    ];

    /** @var string */
    protected $signature = self::NAME;

    /** @var string */
    protected $description = 'Check the Runtime MCP config and print one report (exit code 1 on a failure)';

    public function handle(Doctor $doctor, RuntimeServer $server, Environment $environment, Repository $config): int
    {
        $report = $doctor->run();
        $redactor = self::redactor($config);
        $text = fn (string $value): string => OutputFormatter::escape($redactor->redactString($value));
        $mode = $environment->isStrict() ? 'strict' : 'relaxed';

        $this->newLine();
        $this->line('  '.$text("Runtime MCP doctor — {$server->name()}, {$mode}, package {$server->version()}"));
        $this->newLine();

        foreach ($report->lines as $line) {
            $status = $report->status($line);

            $this->components->twoColumnDetail(self::GLYPHS[$status->value].' '.$text($line->title), $text($line->result->message));

            if ($status !== CheckStatus::Pass && $line->result->fix !== null) {
                $this->line('    → '.$text($line->result->fix));
            }
        }

        $this->newLine();

        match (true) {
            $report->count(CheckStatus::Fail) > 0 => $this->components->error($report->summary()),
            $report->count(CheckStatus::Warn) > 0 => $this->components->warn($report->summary()),
            default => $this->components->info($report->summary()),
        };

        return $report->exitCode();
    }

    /** A bad extra pattern is reported by its own check; the built-in patterns still apply here. */
    private static function redactor(Repository $config): Redactor
    {
        try {
            return new Redactor($config);
        } catch (InvalidRedactionPattern) {
            $builtIn = clone $config;
            $builtIn->set('runtime-mcp.redaction.extra_patterns', []);

            return new Redactor($builtIn);
        }
    }
}
