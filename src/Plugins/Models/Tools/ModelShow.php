<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Plugins\Models\Tools;

use Closure;
use DateTimeInterface;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use LogicException;
use SinaSaeedi\RuntimeMcp\Audit\Reason;
use SinaSaeedi\RuntimeMcp\Plugins\Database\ReadableSchema;
use SinaSaeedi\RuntimeMcp\Plugins\Database\Tools\DbSchema;
use SinaSaeedi\RuntimeMcp\Plugins\Models\ModelRegistry;
use SinaSaeedi\RuntimeMcp\Plugins\Models\RelationShape;
use SinaSaeedi\RuntimeMcp\Principal;
use SinaSaeedi\RuntimeMcp\Security\Auth\CurrentPrincipal;
use SinaSaeedi\RuntimeMcp\Security\Authorization\ModelPolicy;
use SinaSaeedi\RuntimeMcp\Security\Grants\InvalidGrantConfig;
use SinaSaeedi\RuntimeMcp\Security\ReadOnly\WriteAttemptExplainer;
use SinaSaeedi\RuntimeMcp\Security\Redaction\Redactor;
use SinaSaeedi\RuntimeMcp\Security\Sql\Exceptions\QueryFailed;
use SinaSaeedi\RuntimeMcp\Security\Sql\Exceptions\QueryRejected;
use SinaSaeedi\RuntimeMcp\Security\Sql\Exceptions\QueryTimedOut;
use SinaSaeedi\RuntimeMcp\Security\Sql\ReadOnlySession;
use SinaSaeedi\RuntimeMcp\Security\UnsupportedDriver;
use SinaSaeedi\RuntimeMcp\Support\Timestamp;
use SinaSaeedi\RuntimeMcp\Tools\AuthorizationDenied;
use SinaSaeedi\RuntimeMcp\Tools\Contracts\RuntimeTool;
use SinaSaeedi\RuntimeMcp\Tools\Pipeline\CheckGateStage;
use SinaSaeedi\RuntimeMcp\Tools\Tier;
use SinaSaeedi\RuntimeMcp\Tools\ToolInput;
use SinaSaeedi\RuntimeMcp\Tools\ToolResult;
use Throwable;

/**
 * Spec 5.1, 5.5, 5.6, 6.2 (01-21): one record of an allow-listed model, the way the app sees it (casts,
 * enums, $hidden, global scopes), with only the readable columns, on the read-only connection, behind the
 * model's `view` Policy. Up to 5 allow-listed relations, at most 50 records each, each record behind its
 * own `view` Policy.
 *
 * Every database read runs inside ReadOnlySession::run() (Q1): the statement timeout, a read-only
 * transaction on the read-only connection, the strict refusal of the default connection and the session
 * reset. ReadableSchema runs its own run(), which cannot nest on MySQL (a Case B savepoint needs 01-16's
 * mark), so the column reads run between two frames: the record, then the relations. A MorphTo target is
 * known only from the record, which is why there are two.
 *
 * Built through the container on every request (also tools/list): the constructor reads nothing, and the
 * description and schema read only the config (ModelRegistry never throws).
 */
final class ModelShow extends RuntimeTool
{
    public const MAX_ALIAS = 64;

    public const MAX_KEY = 64;

    public const MAX_WITH = 5;

    public const MAX_RELATED = 50;

    /** The cast types that hold a date or a time (HasAttributes::getCastType()). */
    public const DATE_CASTS = ['date', 'datetime', 'custom_datetime', 'immutable_date', 'immutable_datetime', 'immutable_custom_datetime', 'timestamp'];

    /** PostgreSQL refuses a key that does not fit the key column's type: invalid text (a UUID), out of range. */
    public const KEY_STATES = ['22P02', '22003'];

    public const NO_MODELS = 'No model is allow-listed in runtime-mcp.models, so model_show has nothing to show.';

    public const NOT_READABLE = 'Model `%s` maps to table `%s`, which the read-only role cannot read.';

    public const COLUMN_NOT_READABLE = 'Model `%s` needs column `%s` of table `%s`, which the read-only role cannot read.';

    public const NOT_FOUND = 'No `%s` with key `%s` in table `%s` (read-only connection).';

    public const KEY_TYPE = '`%s` has an integer primary key, so the key `%s` matches no record.';

    public const KEY_REFUSED = 'The key `%s` does not fit the type of the primary key of table `%s` (SQLSTATE %s), so no record matches.';

    public const POLICY_DENIED = 'Access denied: the `view` Policy of %s denied this record.';

    public const POLICY_ERROR = 'Access denied: the `view` Policy of %s failed. The server log names the cause.';

    public const UNSUPPORTED = 'model_show supports PostgreSQL, MySQL and MariaDB; this connection uses %s.';

    public const BAD_DENIED_TABLES = 'runtime-mcp.database.denied_tables is not a list of names, so model_show reads no table. Fix the config.';

    public const TIMED_OUT = 'Reading `%s` was stopped by the %d ms statement timeout.';

    public const REFUSED = 'The database refused the read of `%s` (SQLSTATE %s).';

    public const RELATION_NOT_BUILT = 'Relation `%s` could not be built (%s).';

    public const RELATION_NOT_RELATION = 'Relation `%s` does not return an Eloquent relation.';

    public const RELATION_UNSUPPORTED = 'Relation `%s` is a %s, which model_show does not load.';

    public const RELATION_OWN_CONNECTION = 'Relation `%s` uses its own connection `%s`, so it was not loaded.';

    public const RELATION_MORPH_CLASS = 'Relation `%s` points to `%s`, which is not an Eloquent model class, so it was not loaded.';

    public const RELATION_TABLE = 'Relation `%s` reads table `%s`, which the read-only role cannot read.';

    public const RELATION_COLUMN = 'Relation `%s` needs column `%s` of table `%s`, which the read-only role cannot read.';

    public const RELATION_TRUNCATED = 'Relation `%s` has more than %d records; the first %d are shown.';

    public const RELATION_DENIED = 'The `view` Policy denied %d record(s) of relation `%s`; they are left out.';

    /** The longest MorphTo type value repeated in a note: it is app data. */
    public const MAX_SHOWN_CLASS = 100;

    public function __construct(
        private readonly ModelRegistry $registry,
        private readonly ReadableSchema $schema,
        private readonly ReadOnlySession $session,
        private readonly ModelPolicy $policies,
        private readonly CurrentPrincipal $principals,
        private readonly DatabaseManager $db,
        private readonly Repository $config,
        private readonly WriteAttemptExplainer $writes,
        private readonly ExceptionHandler $exceptions,
        private readonly Container $container,
    ) {}

    public function name(): string
    {
        return 'model_show';
    }

    public function description(): string
    {
        $text = 'Shows one record of an allow-listed Eloquent model by its primary key, the way the application sees it: '
            .'casts applied, hidden attributes removed, and only the columns the read-only role can read. Optional: up to '
            .self::MAX_WITH.' allow-listed relations, at most '.self::MAX_RELATED.' records each. For lists and counts, use '
            .'db_query.';
        $models = [];

        foreach ($this->registry->entries() as $alias => $entry) {
            $models[] = $alias.' ('.($entry['relations'] === [] ? 'no relations' : 'relations: '.implode(', ', $entry['relations'])).')';
        }

        $text .= $models === []
            ? ' No model is allow-listed in runtime-mcp.models yet, so every call returns unavailable.'
            : ' Models: '.implode(', ', $models).'.';

        return $text.' Input: model, key (at most '.self::MAX_KEY.' characters), with (optional, at most '.self::MAX_WITH.' relation names).';
    }

    public function tier(): Tier
    {
        return Tier::ReadSensitive;
    }

    public function schema(JsonSchema $schema): array
    {
        $aliases = $this->registry->aliases();
        $union = $this->registry->relationUnion();

        // An empty enum is a schema no value can meet: with no valid alias, model is a plain string (Q5).
        $model = $aliases === [] ? $schema->string()->max(self::MAX_ALIAS) : $schema->string()->enum($aliases);
        $relation = $union === [] ? $schema->string() : $schema->string()->enum($union);

        return [
            'model' => $model->description('The model alias, from runtime-mcp.models.')->required(),
            // No union type: illuminate/json-schema's UnionType has no max() (01-18 audit). The rules take an integer too.
            'key' => $schema->string()->description('The primary key of the record, for example 1234.')->max(self::MAX_KEY)->required(),
            'with' => $schema->array()->items($relation)->description('Relations of this model to load.')->max(self::MAX_WITH),
        ];
    }

    public function rules(): array
    {
        $aliases = $this->registry->aliases();
        $union = $this->registry->relationUnion();

        return [
            'model' => $aliases === [] ? ['required', 'string', 'max:'.self::MAX_ALIAS] : ['required', 'string', Rule::in($aliases)],
            // Never string (it refuses 65), integer or numeric: with a numeric rule max:64 limits the value, not the length.
            'key' => ['required', 'max:'.self::MAX_KEY, function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_string($value) && ! is_int($value)) {
                    $fail('The key must be a string or an integer.');
                }
            }],
            'with' => ['sometimes', 'array', 'list', 'max:'.self::MAX_WITH],
            // filled: the other rules skip "" (it is not implicit), and "" would reach a method call.
            'with.*' => ['filled', 'string', 'distinct', ...($union === [] ? [] : [Rule::in($union)]), $this->allowListed(...)],
        ];
    }

    /** with.*: the relation must be in this model's own allow-list. The closure gets the validator 4th (Laravel 12.69 and 13). */
    private function allowListed(string $attribute, mixed $value, Closure $fail, mixed $validator = null): void
    {
        $alias = $validator instanceof Validator ? ($validator->getData()['model'] ?? null) : null;
        $entry = is_string($alias) ? $this->registry->entry($alias) : null;

        if ($entry === null || ! is_string($value) || ! in_array($value, $entry['relations'], true)) {
            $fail('Each with value must be an allow-listed relation of this model.');
        }
    }

    public function execute(ToolInput $input): ToolResult
    {
        $alias = $input->get('model');
        $entry = is_string($alias) ? $this->registry->entry($alias) : null;

        if (! is_string($alias) || $entry === null) {
            return ToolResult::unavailable(self::NO_MODELS);
        }

        $key = $input->get('key');
        $with = $input->get('with', []);

        try {
            return $this->show(
                $alias,
                $entry,
                is_int($key) ? $key : (string) (is_string($key) ? $key : ''),
                array_values(array_filter(is_array($with) ? $with : [], is_string(...))),
            );
        } catch (InvalidGrantConfig) {
            // Fail closed (Sina, 2026-10-19): never a read without the denied-table filter.
            return ToolResult::unavailable(self::BAD_DENIED_TABLES);
        } catch (UnsupportedDriver $e) {
            return ToolResult::unavailable(sprintf(self::UNSUPPORTED, $e->driver));
        } catch (QueryRejected $e) {
            return ToolResult::unavailable($this->rejected($e, $alias));
        }
    }

    /**
     * @param  array{class: class-string<Model>, relations: list<string>, appends: list<string>}  $entry
     * @param  list<string>  $with
     */
    private function show(string $alias, array $entry, int|string $key, array $with): ToolResult
    {
        $connection = $this->readOnlyConnection();
        $model = (new $entry['class'])->setConnection($connection->getName());
        $prefix = $connection->getTablePrefix();
        $table = $model->getTable();
        $shownKey = (string) $key;

        $readable = [];
        $columns = $this->readable($table, $prefix, $readable);

        if ($columns === []) {
            return ToolResult::unavailable(sprintf(self::NOT_READABLE, $alias, $table));
        }

        foreach ([$model->getKeyName(), ...RelationShape::softDelete($model)] as $column) {
            if (! in_array($column, $columns, true)) {
                return ToolResult::unavailable(sprintf(self::COLUMN_NOT_READABLE, $alias, $column, $table));
            }
        }

        // Q4, after the readable check (an unreadable table is unavailable, never empty): MySQL and
        // MariaDB compare '1234abc' as 1234 and 'abc' as 0; PostgreSQL refuses both (22P02).
        if (in_array($model->getKeyType(), ['int', 'integer'], true)) {
            $key = self::integerKey($key);

            if ($key === null) {
                return ToolResult::empty(sprintf(self::KEY_TYPE, $alias, $shownKey));
            }
        }

        $relations = [];
        $probes = [];

        foreach ($with as $name) {
            $probe = $this->probe($model, $name);

            if (is_string($probe)) {
                $relations[$name] = ['type' => null, 'unavailable' => $probe];
            } else {
                $probes[$name] = $probe;
            }
        }

        $looked = false;

        try {
            $found = $this->session->run(function (Connection $ro) use ($model, $columns, $key, $entry, $probes, &$looked): ?array {
                return $this->readRecord($model, $columns, $key, $entry['appends'], $probes, $looked);
            });
        } catch (QueryFailed $e) {
            // Q4 is about the lookup: the same SQLSTATE from an accessor or a cast after it is a refused read (01-21 triage).
            if (! $looked && in_array($e->sqlState, self::KEY_STATES, true)) {
                return ToolResult::empty(sprintf(self::KEY_REFUSED, $shownKey, $table, $e->sqlState));
            }

            throw $e;
        }

        if ($found === null) {
            return ToolResult::empty(sprintf(self::NOT_FOUND, $alias, $shownKey, $table));
        }

        // The relations in $built hold the record: frame 2 queries from it.
        [$data, $built] = $found;
        $plans = [];

        foreach ($built as $name => $relation) {
            if (is_string($relation)) {
                $relations[$name] = ['type' => RelationShape::typeOf($probes[$name]), 'unavailable' => $relation];
            } elseif ($relation === null) {
                // A MorphTo with an empty type column points nowhere.
                $relations[$name] = ['type' => RelationShape::typeOf($probes[$name]), 'record' => null];
            } else {
                $plan = $this->plan($name, $relation, $table, $columns, $prefix, $readable);

                if (is_string($plan)) {
                    $relations[$name] = ['type' => RelationShape::typeOf($relation), 'unavailable' => $plan];
                } else {
                    $plans[$name] = $plan;
                }
            }
        }

        if ($plans !== []) {
            $loaded = $this->session->run(fn (Connection $ro): array => $this->loadRelations($plans, $ro));
            $relations = [...$relations, ...$loaded];
        }

        return $this->answer($alias, $shownKey, $data, $with, $relations);
    }

    /**
     * Frame 1: the record with its readable columns, its Policy, its array, and each relation built on it.
     *
     * @param  list<string>  $columns
     * @param  list<string>  $appends
     * @param  array<string, Relation<Model, Model, mixed>>  $probes
     * @param  bool  $looked  set once the lookup returned
     * @return array{array<array-key, mixed>, array<string, Relation<Model, Model, mixed>|string|null>}|null
     */
    private function readRecord(Model $model, array $columns, int|string $key, array $appends, array $probes, bool &$looked): ?array
    {
        // Never Model::on(): its newQuery() eager-loads $with (relations that are not allow-listed, with
        // SELECT *, maybe on their own connection). Global scopes stay on. Qualified with getTable(): the
        // grammar adds the prefix itself.
        $record = $model->newQueryWithoutRelationships()
            ->select(array_map($model->qualifyColumn(...), $columns))
            ->whereKey($key)
            ->first();
        $looked = true;

        if (! $record instanceof Model) {
            return null;
        }

        $this->authorize($record);
        $data = $this->serialise($record, $appends);
        $built = [];

        foreach ($probes as $name => $probe) {
            $built[$name] = $this->build($record, $name, $probe, $columns);
        }

        return [$data, $built];
    }

    /**
     * The relation built on a fresh model: no attributes, no query. It tells the type before the record
     * exists, so a MorphTo is never built from the record before its target class is checked.
     *
     * @return Relation<Model, Model, mixed>|string the relation, or why it is not loaded
     */
    private function probe(Model $model, string $name): Relation|string
    {
        try {
            $relation = $model->newInstance()->{$name}();
        } catch (Throwable $e) {
            // A related model that names a connection this server does not configure throws here.
            $this->exceptions->report($e);

            return sprintf(self::RELATION_NOT_BUILT, $name, $e::class);
        }

        if (! $relation instanceof Relation) {
            return sprintf(self::RELATION_NOT_RELATION, $name);
        }

        return RelationShape::typeOf($relation) === null
            ? sprintf(self::RELATION_UNSUPPORTED, $name, class_basename($relation))
            : $relation;
    }

    /**
     * The relation built on the record (inside frame 1). A MorphTo builds its target from the type column,
     * so the class is checked first: a type that names no model class never reaches `new`.
     *
     * @param  Relation<Model, Model, mixed>  $probe
     * @param  list<string>  $columns
     * @return Relation<Model, Model, mixed>|string|null null: a MorphTo whose type column is empty
     */
    private function build(Model $record, string $name, Relation $probe, array $columns): Relation|string|null
    {
        if ($probe instanceof MorphTo) {
            foreach ([$probe->getForeignKeyName(), $probe->getMorphType()] as $column) {
                if (! in_array($column, $columns, true)) {
                    return sprintf(self::RELATION_COLUMN, $name, $column, $record->getTable());
                }
            }

            $type = $record->getAttribute($probe->getMorphType());

            if ($type === null || $type === '') {
                return null;
            }

            $class = is_string($type) ? (Relation::getMorphedModel($type) ?? $type) : get_debug_type($type);

            if (! ModelRegistry::isModelClass($class)) {
                return sprintf(self::RELATION_MORPH_CLASS, $name, mb_substr($class, 0, self::MAX_SHOWN_CLASS));
            }
        }

        try {
            $relation = $record->{$name}();
        } catch (Throwable $e) {
            $this->exceptions->report($e);

            return sprintf(self::RELATION_NOT_BUILT, $name, $e::class);
        }

        return $relation instanceof Relation && $relation::class === $probe::class
            ? $relation
            : sprintf(self::RELATION_NOT_RELATION, $name);
    }

    /**
     * Between the frames: the relation's own connection and its readable columns.
     *
     * @param  Relation<Model, Model, mixed>  $relation
     * @param  list<string>  $parentColumns  the record's readable columns
     * @param  array<string, list<string>>  $readable
     * @return array{Relation<Model, Model, mixed>, RelationShape, list<string>}|string
     */
    private function plan(string $name, Relation $relation, string $parentTable, array $parentColumns, string $prefix, array &$readable): array|string
    {
        $shape = RelationShape::of($relation);
        $own = $shape->ownConnection();

        if ($own !== null) {
            return sprintf(self::RELATION_OWN_CONNECTION, $name, $own[1]);
        }

        foreach ($shape->parentColumns as $column) {
            if (! in_array($column, $parentColumns, true)) {
                return sprintf(self::RELATION_COLUMN, $name, $column, $parentTable);
            }
        }

        foreach ($shape->tables as $table => $needed) {
            $columns = $this->readable($table, $prefix, $readable);

            if ($columns === []) {
                return sprintf(self::RELATION_TABLE, $name, $table);
            }

            foreach ($needed as $column) {
                if (! in_array($column, $columns, true)) {
                    return sprintf(self::RELATION_COLUMN, $name, $column, $table);
                }
            }
        }

        return [$relation, $shape, $readable[$prefix.'|'.$shape->relatedTable()]];
    }

    /**
     * Frame 2: each relation queried from the loaded record. With an existing parent limit() is a plain
     * LIMIT; load() would build the relation on a new instance (exists = false) and turn it into a
     * row_number() window inside "select * from (…)".
     *
     * @param  array<string, array{Relation<Model, Model, mixed>, RelationShape, list<string>}>  $plans
     * @return array<string, array<string, mixed>>
     */
    private function loadRelations(array $plans, Connection $ro): array
    {
        $out = [];

        foreach ($plans as $name => [$relation, $shape, $columns]) {
            // Built in frame 1. When frame 1's reset failed, ReadOnlySession threw that connection away (01-14
            // Q2); the relation still holds it, and its query would reconnect outside this frame's read-only
            // transaction and timeout (01-21 triage).
            if ($relation->getBaseQuery()->getConnection() !== $ro) {
                throw new QueryFailed('HY000', null, ReadOnlySession::SESSION_LOST);
            }

            $related = $relation->getRelated();
            // The related model's own $with must not load either.
            $relation->withoutEagerLoads()->select(array_map($related->qualifyColumn(...), $columns));

            if ($relation->getQuery()->getQuery()->orders === null) {
                $relation->orderBy($related->getQualifiedKeyName());
            }

            if ($shape->one) {
                $first = $relation->first();
                $models = $first instanceof Model ? [$first] : [];
                $truncated = false;
            } else {
                $models = array_values($relation->limit(self::MAX_RELATED + 1)->get()->all());
                $truncated = count($models) > self::MAX_RELATED;
                $models = array_slice($models, 0, self::MAX_RELATED);
            }

            $records = [];
            $denied = 0;

            foreach ($models as $model) {
                // Q2: each related record passes its own view Policy; a Policy that throws counts as a denial.
                if (! $this->policies->view($this->principal(), $model)->allowed) {
                    $denied++;

                    continue;
                }

                $records[] = $this->serialise($model, []);
            }

            $entry = $shape->one
                ? ['type' => $shape->type, 'record' => $records[0] ?? null]
                : ['type' => $shape->type, 'records' => $records, 'truncated' => $truncated];

            if ($denied > 0) {
                $entry['denied'] = $denied;
            }

            $out[$name] = $entry;
        }

        return $out;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @param  list<string>  $with
     * @param  array<string, array<string, mixed>>  $relations
     */
    private function answer(string $alias, string $key, array $data, array $with, array $relations): ToolResult
    {
        $result = ['model' => $alias, 'key' => $key, 'record' => $data];

        if ($with === []) {
            return ToolResult::ok($result, 1);
        }

        $notes = [];
        $ordered = [];

        // In the order the agent asked for them.
        foreach ($with as $name) {
            $entry = $relations[$name];
            $ordered[$name] = $entry;

            if (isset($entry['unavailable']) && is_string($entry['unavailable'])) {
                $notes[] = $entry['unavailable'];
            }

            if (($entry['truncated'] ?? false) === true) {
                $notes[] = sprintf(self::RELATION_TRUNCATED, $name, self::MAX_RELATED, self::MAX_RELATED);
            }

            if (is_int($entry['denied'] ?? null)) {
                $notes[] = sprintf(self::RELATION_DENIED, $entry['denied'], $name);
            }
        }

        $result['relations'] = $ordered;

        // The omitted counts are unknown (51 fetched proves only "more"), so truncated stays false (01-08).
        return $notes === []
            ? ToolResult::ok($result, 1)
            : ToolResult::partial($result, implode(' ', $notes), false, 1);
    }

    /** Spec 5.5: the record's view Policy. A denial is audited as one, with the decision's own reason (01-09). */
    private function authorize(Model $record): void
    {
        $decision = $this->policies->view($this->principal(), $record);

        if ($decision->reason === null) {
            return;
        }

        $note = sprintf($decision->reason === Reason::GateError ? self::POLICY_ERROR : self::POLICY_DENIED, $record::class);

        if ($decision->message !== null && $decision->message !== '') {
            // Redacted whole, then cut, as CheckGateStage::note().
            $note .= ' '.mb_substr($this->container->make(Redactor::class)->redactString($decision->message), 0, CheckGateStage::MAX_MESSAGE);
        }

        throw new AuthorizationDenied($decision->reason, $note);
    }

    /**
     * Casts applied (enums become their values), $hidden and $visible applied, only the given appends.
     * Dates become ISO 8601 with the app offset (spec 4.4), whatever serializeDate() says: only keys already
     * in the array, never one that is hidden, not selected or not a column (getDates() names updated_at even
     * when the table has none; with preventAccessingMissingAttributes() getAttribute() would throw).
     *
     * @param  list<string>  $appends
     * @return array<array-key, mixed>
     */
    private function serialise(Model $model, array $appends): array
    {
        $model->setAppends($appends);
        $array = $model->withoutRelations()->toArray();
        // A through relation's join key, not a column of the related record.
        unset($array['laravel_through_key']);

        // Attribute names are strings; Larastan 3.14+ types toArray() keys as string, older versions as array-key.
        foreach (array_filter(array_keys($array), is_string(...)) as $key) {
            if (! in_array($key, $model->getDates(), true) && ! $model->hasCast($key, self::DATE_CASTS)) {
                continue;
            }

            $value = $model->getAttribute($key);

            if ($value instanceof DateTimeInterface || is_int($value)) {
                $array[$key] = Timestamp::iso($value);
            }
        }

        return $array;
    }

    /**
     * The readable columns of an Eloquent table name, read once per call.
     *
     * @param  array<string, list<string>>  $readable
     * @return list<string>
     */
    private function readable(string $table, string $prefix, array &$readable): array
    {
        return $readable[$prefix.'|'.$table] ??= $this->schema->readableColumns(self::realName($table, $prefix));
    }

    /**
     * The real catalog name of an Eloquent table name: the connection prefix goes before the last part, as
     * Grammar::wrapTable() puts it. orders → pfx_orders, billing.invoices → billing.pfx_invoices.
     */
    public static function realName(string $table, string $prefix): string
    {
        $at = strrpos($table, '.');

        return $at === false ? $prefix.$table : substr($table, 0, $at + 1).$prefix.substr($table, $at + 1);
    }

    /** As ReadOnlySession::connection(): the configured read-only connection, '' and null give the default. */
    private function readOnlyConnection(): Connection
    {
        $name = $this->config->get('runtime-mcp.database.connection');

        return $this->db->connection(is_string($name) && $name !== '' ? $name : null);
    }

    private function principal(): Principal
    {
        return $this->principals->get() ?? throw new LogicException('model_show runs only after the pipeline set the principal (stage 1).');
    }

    /** A whole number in PHP's int range, or null. Leading zeros are allowed ("007" is 7). */
    public static function integerKey(int|string $key): ?int
    {
        if (is_int($key)) {
            return $key;
        }

        if (preg_match('/^-?[0-9]{1,64}$/D', $key) !== 1) {
            return null;
        }

        $value = filter_var((string) preg_replace('/^(-?)0+(?=[0-9])/', '$1', $key), FILTER_VALIDATE_INT);

        return is_int($value) ? $value : null;
    }

    /** Never the database's message: it can name a host, a user or a value. As DbSchema::rejected(). */
    private function rejected(QueryRejected $e, string $alias): string
    {
        // A write by app code (a retrieved observer): RunTool gives 01-16's note.
        if ($this->writes->note($e) !== null) {
            throw $e;
        }

        if ($e instanceof QueryTimedOut) {
            return sprintf(self::TIMED_OUT, $alias, $e->timeoutMs);
        }

        if ($e instanceof QueryFailed && in_array($e->getMessage(), DbSchema::FIXED_NOTES, true)) {
            return $e->getMessage();
        }

        $this->exceptions->report($e);

        return sprintf(self::REFUSED, $alias, $e->sqlState);
    }
}
