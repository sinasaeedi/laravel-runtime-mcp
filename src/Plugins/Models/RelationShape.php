<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Plugins\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Database\Eloquent\Relations\HasOneOrManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\MorphOneOrMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use LogicException;

/**
 * 01-21 Part D (Q3): what one relation needs from the database. model_show loads only Laravel's own ten
 * relation classes, matched by exact class: for any other class (a package's relation, a subclass) it cannot
 * know which key columns the query uses, so it cannot prove that they are readable.
 *
 * Tables are Eloquent names (getTable(): no connection prefix). Columns are names without a table.
 */
final readonly class RelationShape
{
    /** Laravel's relation classes that model_show loads. */
    public const TYPES = [
        HasOne::class, HasMany::class, BelongsTo::class, BelongsToMany::class, MorphOne::class,
        MorphMany::class, MorphTo::class, MorphToMany::class, HasOneThrough::class, HasManyThrough::class,
    ];

    /** The types that give one record (or null); the others give a list. */
    public const ONE = [HasOne::class, BelongsTo::class, MorphOne::class, MorphTo::class, HasOneThrough::class];

    /**
     * @param  string  $type  "has_many", "belongs_to", ...: snake case of the class name
     * @param  array<string, list<string>>  $tables  table => the columns of it the query needs, the related table first
     * @param  list<string>  $parentColumns  the columns of the parent record the query reads
     * @param  list<Model>  $models  the related model, and the through model: each one's own connection matters
     */
    private function __construct(
        public string $type,
        public bool $one,
        public array $tables,
        public array $parentColumns,
        public array $models,
    ) {}

    /**
     * "has_many" for a supported relation, null for any other class.
     *
     * @param  Relation<Model, Model, mixed>  $relation
     */
    public static function typeOf(Relation $relation): ?string
    {
        return in_array($relation::class, self::TYPES, true) ? Str::snake(class_basename($relation)) : null;
    }

    /** @param  Relation<Model, Model, mixed>  $relation  a relation of a supported type (typeOf() is not null) */
    public static function of(Relation $relation): self
    {
        $type = (string) self::typeOf($relation);
        $one = in_array($relation::class, self::ONE, true);
        $related = $relation->getRelated();
        $relatedTable = $related->getTable();

        // The more specific classes first: MorphTo extends BelongsTo, MorphToMany extends BelongsToMany,
        // MorphOne and MorphMany extend HasOneOrMany.
        [$tables, $parent, $models] = match (true) {
            $relation instanceof MorphTo => [
                [$relatedTable => [$relation->getOwnerKeyName(), ...self::softDelete($related)]],
                [$relation->getForeignKeyName(), $relation->getMorphType()],
                [$related],
            ],
            $relation instanceof BelongsTo => [
                [$relatedTable => [$relation->getOwnerKeyName(), ...self::softDelete($related)]],
                [$relation->getForeignKeyName()],
                [$related],
            ],
            $relation instanceof MorphOneOrMany => [
                [$relatedTable => [$relation->getForeignKeyName(), $relation->getMorphType(), ...self::softDelete($related)]],
                [$relation->getLocalKeyName()],
                [$related],
            ],
            $relation instanceof HasOneOrMany => [
                [$relatedTable => [$relation->getForeignKeyName(), ...self::softDelete($related)]],
                [$relation->getLocalKeyName()],
                [$related],
            ],
            $relation instanceof BelongsToMany => [
                [
                    $relatedTable => [$relation->getRelatedKeyName(), ...self::softDelete($related)],
                    $relation->getTable() => [
                        $relation->getForeignPivotKeyName(),
                        $relation->getRelatedPivotKeyName(),
                        ...($relation instanceof MorphToMany ? [$relation->getMorphType()] : []),
                        ...array_values(array_filter($relation->getPivotColumns(), is_string(...))),
                    ],
                ],
                [$relation->getParentKeyName()],
                [$related],
            ],
            $relation instanceof HasOneOrManyThrough => [
                [
                    $relatedTable => [$relation->getForeignKeyName(), ...self::softDelete($related)],
                    $relation->getParent()->getTable() => [
                        $relation->getFirstKeyName(),
                        $relation->getSecondLocalKeyName(),
                        ...self::softDelete($relation->getParent()),
                    ],
                ],
                [$relation->getLocalKeyName()],
                [$related, $relation->getParent()],
            ],
            default => throw new LogicException('['.$relation::class.'] is not a relation type that model_show loads.'),
        };

        foreach ($tables as $table => $columns) {
            $tables[$table] = array_values(array_unique($columns));
        }

        return new self($type, $one, $tables, array_values(array_unique($parent)), $models);
    }

    /** The table whose columns the query selects. */
    public function relatedTable(): string
    {
        return (string) array_key_first($this->tables);
    }

    /**
     * The first model of this relation that sets its own connection, and that connection's name. Read on
     * a fresh instance: newRelatedInstance() gives the related model the parent's connection when it has
     * none, so getRelated()->getConnectionName() is the read-only connection for every relation.
     *
     * @return array{Model, string}|null
     */
    public function ownConnection(): ?array
    {
        foreach ($this->models as $model) {
            $name = (new ($model::class))->getConnectionName();

            if ($name !== null) {
                return [$model, $name];
            }
        }

        return null;
    }

    /**
     * The SoftDeletes scope adds "deleted_at is null", so that column must be readable too.
     *
     * @return list<string>
     */
    public static function softDelete(Model $model): array
    {
        if (! in_array(SoftDeletes::class, class_uses_recursive($model), true) || ! method_exists($model, 'getDeletedAtColumn')) {
            return [];
        }

        $column = $model->getDeletedAtColumn();

        return is_string($column) ? [$column] : [];
    }
}
