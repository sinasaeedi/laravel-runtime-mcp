<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Tools;

use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use SinaSaeedi\RuntimeMcp\Security\ReadOnly\ReadOnlyCommitRefused;
use SinaSaeedi\RuntimeMcp\Security\Sql\ReadOnlySession;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\ReadOnly\ObservedOrder;
use SinaSaeedi\RuntimeMcp\Tools\Contracts\RuntimeTool;
use SinaSaeedi\RuntimeMcp\Tools\Tier;
use SinaSaeedi\RuntimeMcp\Tools\ToolInput;
use SinaSaeedi\RuntimeMcp\Tools\ToolResult;

/**
 * 01-16: a read tool whose app code tries one kind of write, chosen by `action`. Every action first
 * binds RAN, so a test can prove that the tool did not run when the transaction could not be opened.
 */
final class WriteAttemptTool extends RuntimeTool
{
    public const RAN = 'write_attempt.ran';

    public const ACTIONS = [
        'read', 'insert', 'insert_listed', 'observer', 'accessor', 'cache_database', 'cache_array', 'cache_lock',
        'nested', 'commit', 'commit_caught', 'pdo_commit', 'caught_write', 'reconnect', 'ddl', 'session',
        'returned_model', 'reconnect_listed_down', 'rollback',
    ];

    /** The listed connection that insert_listed writes to. */
    public const LISTED = 'testing_readonly';

    public function name(): string
    {
        return 'write_attempt';
    }

    public function description(): string
    {
        return 'Tries one kind of database write from app code. action: one of the fixture actions.';
    }

    public function tier(): Tier
    {
        return Tier::Read;
    }

    public function schema(JsonSchema $schema): array
    {
        return ['action' => $schema->string()->enum(self::ACTIONS)->required()];
    }

    public function rules(): array
    {
        return ['action' => ['required', 'string', 'in:'.implode(',', self::ACTIONS)]];
    }

    public function execute(ToolInput $input): ToolResult
    {
        app()->instance(self::RAN, true);

        $value = match ($input->get('action')) {
            'read' => DB::table('ro_orders')->count(),
            'insert' => DB::table('ro_orders')->insert(['n' => 'insert']),
            'insert_listed' => DB::connection(self::LISTED)->table('ro_orders')->insert(['n' => 'listed']),
            'observer' => ObservedOrder::query()->first()?->id,
            // Events off, so the observer does not write first: only the accessor's increment() writes.
            'accessor' => ObservedOrder::withoutEvents(fn (): mixed => ObservedOrder::query()->first()?->views_seen),
            'cache_database' => Cache::store('database')->put('ro_key', 'value', 60),
            'cache_array' => Cache::store('array')->put('ro_key', 'value', 60),
            'cache_lock' => $this->lock(),
            'nested' => DB::transaction(fn (): bool => DB::table('ro_orders')->insert(['n' => 'nested'])),
            'commit' => $this->commitThenInsert(false),
            'commit_caught' => $this->commitThenInsert(true),
            'pdo_commit' => $this->pdoCommitThenInsert(),
            'caught_write' => $this->caughtWrite(),
            'reconnect' => $this->reconnectThenInsert(),
            'ddl' => DB::statement('CREATE TABLE ro_created (id INTEGER)'),
            'session' => app(ReadOnlySession::class)->run(fn (Connection $connection): mixed => $connection->scalar('SELECT 1')),
            // The model itself in the data: only its serialization runs the views_seen accessor.
            'returned_model' => ObservedOrder::withoutEvents(fn (): ?ObservedOrder => ObservedOrder::query()->first()?->append('views_seen')),
            'reconnect_listed_down' => $this->reconnectListedDown(),
            'rollback' => $this->rollBackThenInsert(),
            default => null,
        };

        return ToolResult::ok(['value' => $value], 1);
    }

    /** A database cache lock: DatabaseLock::acquire() catches its failed insert, then updates. */
    private function lock(): bool
    {
        $store = Cache::store('database')->getStore();

        return $store instanceof LockProvider ? $store->lock('ro_lock', 10)->get() === true : throw new RuntimeException('No lock store.');
    }

    /** An unbalanced commit, as buggy app code does it, then a write. */
    private function commitThenInsert(bool $catch): bool
    {
        try {
            DB::commit();
        } catch (ReadOnlyCommitRefused $e) {
            if (! $catch) {
                throw $e;
            }
        }

        return DB::table('ro_orders')->insert(['n' => 'after commit']);
    }

    /** A commit straight through the PDO: no event, so RefuseReadOnlyCommit cannot see it. */
    private function pdoCommitThenInsert(): mixed
    {
        DB::connection()->getPdo()->commit();

        try {
            return DB::table('ro_orders')->insert(['n' => 'after pdo commit']);
        } catch (QueryException) {
            // The session default blocked it; the outcome is ENDED anyway.
            return null;
        }
    }

    /** App code that swallows a failed write and goes on reading (PostgreSQL then says 25P02). */
    private function caughtWrite(): int
    {
        try {
            DB::table('ro_orders')->insert(['n' => 'caught']);
        } catch (QueryException) {
            // The app ignores it.
        }

        return DB::table('ro_orders')->count();
    }

    private function reconnectThenInsert(): mixed
    {
        DB::reconnect();

        try {
            return DB::table('ro_orders')->insert(['n' => 'after reconnect']);
        } catch (QueryException) {
            // SQLite :memory: is a new, empty database after a reconnect; the outcome is ENDED anyway.
            return null;
        }
    }

    /** An unbalanced rollback, as buggy app code does it in a catch block, then a write. */
    private function rollBackThenInsert(): bool
    {
        DB::rollBack();

        return DB::table('ro_orders')->insert(['n' => 'after rollback']);
    }

    /** App code reconnects a listed connection whose server is gone: the new PDO is lazy, so nothing connects yet. */
    private function reconnectListedDown(): null
    {
        config(['database.connections.'.self::LISTED.'.database' => '/nonexistent/rmcp/listed.sqlite']);
        DB::connection(self::LISTED)->reconnect();

        return null;
    }
}
