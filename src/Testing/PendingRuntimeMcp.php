<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Testing;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;
use PHPUnit\Framework\Assert;
use SinaSaeedi\RuntimeMcp\Mcp\Testing\McpTestDriver;
use SinaSaeedi\RuntimeMcp\Principal;
use SinaSaeedi\RuntimeMcp\Security\Auth\ApiKey;
use SinaSaeedi\RuntimeMcp\Security\Auth\AuthMethod;
use SinaSaeedi\RuntimeMcp\Security\Auth\CurrentPrincipal;
use SinaSaeedi\RuntimeMcp\Security\Auth\LinkedUserMissing;
use SinaSaeedi\RuntimeMcp\Security\Auth\PrincipalResolver;
use SinaSaeedi\RuntimeMcp\Tools\ProgressClock;

/**
 * One test-kit chain (01-39): an optional principal, an optional progressToken, and the audit entries its calls wrote.
 *
 * The principal is held here, not in the container: each request first forgets the scoped CurrentPrincipal, sets this
 * chain's principal (if any), sends, and forgets it again. So two chains in one test never share a principal, a
 * chain with no as() always gets the real no_principal denial, and no principal outlives the request (rule 14).
 */
final class PendingRuntimeMcp
{
    public const BAD_NAME = 'as() needs a valid key name: letters, digits, ".", "_" and "-", at most 64 characters, not starting with rmcp_.';

    public const BAD_USER = 'asUser() needs a user whose identifier is an int or a string.';

    /** The progressToken of every withProgress() call. */
    public const PROGRESS_TOKEN = 'runtime-mcp-test';

    /** %s is the tool name. */
    public const LISTED = 'The tool [%s] is not in tools/list for this principal.';

    /** %s is the tool name. */
    public const NOT_LISTED = 'The tool [%s] is in tools/list for this principal.';

    private ?Principal $principal = null;

    private bool $progress = false;

    public function __construct(
        private readonly Container $container,
        private readonly AuditRecorder $recorder,
    ) {}

    /**
     * An API-key principal with this name. auth.link_users applies, as in production (01-06): a linked name runs the
     * Gates for its User.
     *
     * @throws LinkedUserMissing when the name is linked to a user that cannot be found
     */
    public function as(string $name): self
    {
        if (! ApiKey::isValidName($name)) {
            throw new InvalidArgumentException(self::BAD_NAME);
        }

        $this->principal = $this->container->make(PrincipalResolver::class)->resolve($name, AuthMethod::Testing);

        return $this;
    }

    /** A user principal: named "user-<id>" by 01-35's rule, and the Gates run for $user. No token is made. */
    public function asUser(Authenticatable $user): self
    {
        $id = $user->getAuthIdentifier();

        if (! is_int($id) && ! is_string($id)) {
            throw new InvalidArgumentException(self::BAD_USER);
        }

        $this->principal = new Principal(Principal::userName($id), AuthMethod::Testing, $user);

        return $this;
    }

    /** The next calls carry _meta.progressToken, so a tool's reports come back as notifications (spec 5.15, 01-37). */
    public function withProgress(): self
    {
        $this->progress = true;

        return $this;
    }

    /** @param  array<string, mixed>  $arguments */
    public function call(string $tool, array $arguments = []): RuntimeMcpResult
    {
        $params = ['name' => $tool, 'arguments' => $arguments];

        if ($this->progress) {
            $params['_meta'] = ['progressToken' => self::PROGRESS_TOKEN];
        }

        $mark = $this->recorder->count();
        $answer = $this->asPrincipal(fn (McpTestDriver $driver): array => $driver->send('tools/call', $params));

        return new RuntimeMcpResult($tool, $answer['response'], $answer['notifications'], $this->recorder->since($mark), $this->progress, $this->eventsFaked());
    }

    /** @param  array<string, mixed>  $arguments */
    public function prompt(string $name, array $arguments = []): RuntimeMcpPromptResult
    {
        $mark = $this->recorder->count();
        $answer = $this->asPrincipal(fn (McpTestDriver $driver): array => $driver->send('prompts/get', ['name' => $name, 'arguments' => $arguments]));

        return new RuntimeMcpPromptResult($name, $answer['response'], $this->recorder->since($mark), $this->eventsFaked());
    }

    /** @return list<string> the tools that tools/list shows this principal: the Gate allows them, and they are available */
    public function listedTools(): array
    {
        return $this->asPrincipal(fn (McpTestDriver $driver): array => $driver->listedTools());
    }

    public function assertListed(string ...$tools): self
    {
        $listed = $this->listedTools();

        foreach ($tools as $tool) {
            Assert::assertContains($tool, $listed, sprintf(self::LISTED, $tool));
        }

        return $this;
    }

    public function assertNotListed(string ...$tools): self
    {
        $listed = $this->listedTools();

        foreach ($tools as $tool) {
            Assert::assertNotContains($tool, $listed, sprintf(self::NOT_LISTED, $tool));
        }

        return $this;
    }

    /**
     * Runs one request as this chain's principal. With withProgress(), a SteppingClock steps one second per report, so
     * FiberProgress's one-second rule drops none (a clock the team bound itself is kept).
     *
     * @template T
     *
     * @param  Closure(McpTestDriver): T  $send
     * @return T
     */
    private function asPrincipal(Closure $send): mixed
    {
        $clock = $this->progress && ! $this->container->bound(ProgressClock::class);
        $this->container->forgetInstance(CurrentPrincipal::class);

        try {
            if ($this->principal !== null) {
                $this->container->make(CurrentPrincipal::class)->set($this->principal);
            }

            if ($clock) {
                $this->container->instance(ProgressClock::class, new SteppingClock);
            }

            return $send($this->container->make(McpTestDriver::class));
        } finally {
            $this->container->forgetInstance(CurrentPrincipal::class);

            if ($clock) {
                $this->container->forgetInstance(ProgressClock::class);
            }
        }
    }

    /**
     * Event::fake() of the audit events hides them from the recorder; the failure message then says so. Laravel's own
     * check (Facade::isFake(), 12.69 and 13.36): the facade's resolved instance is a Fake, as EventFake is.
     */
    private function eventsFaked(): bool
    {
        return Event::isFake();
    }
}
