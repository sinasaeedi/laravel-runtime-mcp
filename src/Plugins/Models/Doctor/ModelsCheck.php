<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Plugins\Models\Doctor;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Casts\AsEncryptedArrayObject;
use Illuminate\Database\Eloquent\Casts\AsEncryptedCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use SinaSaeedi\RuntimeMcp\Doctor\Check;
use SinaSaeedi\RuntimeMcp\Doctor\CheckResult;
use SinaSaeedi\RuntimeMcp\Plugins\Models\ModelRegistry;
use SinaSaeedi\RuntimeMcp\Plugins\Models\RelationShape;
use SinaSaeedi\RuntimeMcp\Security\Grants\ColumnMask;
use SinaSaeedi\RuntimeMcp\Security\Grants\InvalidGrantConfig;
use Throwable;

/**
 * Spec 6.2, 7.3, 01-21 Part E: the `models` config. It builds models and relations with `new` and never
 * queries. Aliases, class names, relation, attribute and connection names come from code or config and
 * have the shape of names, so they are printed (01-12). Each alias and each relation is checked on its
 * own, so one broken model does not hide the others.
 */
final class ModelsCheck implements Check
{
    /** An invalid alias is printed only when it has the shape of a name; else by its position. */
    public const PRINTABLE = '/^[A-Za-z0-9_.-]{1,64}$/D';

    public const ENCRYPTED_CLASSES = [AsEncryptedArrayObject::class, AsEncryptedCollection::class];

    public function __construct(
        private readonly ModelRegistry $registry,
        private readonly Repository $config,
    ) {}

    public function title(): string
    {
        return 'Models';
    }

    public function run(): array
    {
        $entries = $this->registry->entries();
        $invalid = $this->registry->invalid();

        if ($entries === [] && $invalid === []) {
            return [CheckResult::pass('No model is allow-listed.')];
        }

        $results = [];
        // The 1-based position among all the entries of runtime-mcp.models, not among the invalid ones (01-21 triage).
        $models = $this->config->get('runtime-mcp.models');
        $positions = is_array($models) ? array_flip(array_map(strval(...), array_keys($models))) : [];

        foreach ($invalid as $alias => $reason) {
            $position = ($positions[$alias] ?? 0) + 1;
            $name = preg_match(self::PRINTABLE, $alias) === 1 ? "`{$alias}`" : "The entry at position {$position}";
            $results[] = CheckResult::fail("{$name} is left out of model_show: {$reason}", 'Fix the entry in runtime-mcp.models.');
        }

        foreach ($entries as $alias => $entry) {
            try {
                array_push($results, ...$this->entry($alias, $entry));
            } catch (Throwable $e) {
                $results[] = CheckResult::fail('`'.$alias.'` could not be checked ('.$e::class.').', 'Run php artisan runtime-mcp:doctor -v, or read the app log.');
            }
        }

        return $results;
    }

    /**
     * @param  array{class: class-string<Model>, relations: list<string>, appends: list<string>}  $entry
     * @return list<CheckResult>
     */
    private function entry(string $alias, array $entry): array
    {
        $model = new $entry['class'];
        $results = [];
        $own = $model->getConnectionName();

        if ($own !== null) {
            $results[] = CheckResult::warn(
                "model_show reads `{$alias}` on the read-only connection, not on `{$own}`.",
                "Leave `{$alias}` out of runtime-mcp.models, or make sure the read-only connection holds its table.",
            );
        }

        $readOnly = $this->config->get('runtime-mcp.database.connection');
        $parent = $model->newInstance()->setConnection(is_string($readOnly) && $readOnly !== '' ? $readOnly : null);

        foreach ($entry['relations'] as $name) {
            $result = $this->relation($alias, $parent, $name);

            if ($result !== null) {
                $results[] = $result;
            }
        }

        foreach ($this->decryptedCasts($model) as $attribute) {
            $results[] = CheckResult::warn(
                "model_show returns casts applied, so `{$alias}`.`{$attribute}` would be returned decrypted.",
                "Add `{$attribute}` to the model's \$hidden, or to runtime-mcp.database.masked_columns.",
            );
        }

        if ($results === []) {
            $results[] = CheckResult::pass("`{$alias}`: {$entry['class']}, ".count($entry['relations']).' relation(s), '.count($entry['appends']).' append(s).');
        }

        return $results;
    }

    /** As model_show builds it, on a fresh model: no attributes, no query. Null when the relation is fine. */
    private function relation(string $alias, Model $parent, string $name): ?CheckResult
    {
        $fix = "Remove `{$name}` from the relations of `{$alias}`.";

        try {
            $relation = $parent->newInstance()->{$name}();
        } catch (Throwable $e) {
            return CheckResult::fail('Relation `'.$alias.'`.`'.$name.'` could not be built ('.$e::class.').', $fix);
        }

        if (! $relation instanceof Relation) {
            return CheckResult::fail("Relation `{$alias}`.`{$name}` does not return an Eloquent relation.", $fix);
        }

        if (RelationShape::typeOf($relation) === null) {
            return CheckResult::fail('Relation `'.$alias.'`.`'.$name.'` is a '.class_basename($relation).', which model_show does not load.', $fix);
        }

        if ($relation instanceof MorphTo) {
            // Its target comes from each record's type column: model_show checks it then.
            return null;
        }

        $own = RelationShape::of($relation)->ownConnection();

        return $own === null ? null : CheckResult::warn(
            "Relation `{$alias}`.`{$name}` uses connection `{$own[1]}` (".$own[0]::class.') and will not be loaded.',
            "Remove `{$name}` from the relations of `{$alias}`, or remove the model's own \$connection.",
        );
    }

    /**
     * The attributes with an encrypted cast that neither $hidden (or $visible) nor masked_columns hides.
     * A masked_columns value that is not a list masks nothing here: every such attribute warns.
     *
     * @return list<string>
     */
    private function decryptedCasts(Model $model): array
    {
        try {
            $mask = ColumnMask::fromConfig($this->config);
        } catch (InvalidGrantConfig) {
            $mask = null;
        }

        $hidden = $model->getHidden();
        $visible = $model->getVisible();
        $found = [];

        foreach ($model->getCasts() as $attribute => $cast) {
            if (! self::isEncrypted($cast)) {
                continue;
            }

            $shown = ! in_array($attribute, $hidden, true) && ($visible === [] || in_array($attribute, $visible, true));

            if ($shown && ($mask === null || ! $mask->matches($attribute))) {
                $found[] = $attribute;
            }
        }

        return $found;
    }

    /** "encrypted", "encrypted:array", …, or AsEncryptedArrayObject / AsEncryptedCollection (with arguments). */
    private static function isEncrypted(mixed $cast): bool
    {
        if (! is_string($cast)) {
            return false;
        }

        if (str_starts_with($cast, 'encrypted')) {
            return true;
        }

        $class = explode(':', $cast, 2)[0];

        foreach (self::ENCRYPTED_CLASSES as $encrypted) {
            if (is_a($class, $encrypted, true)) {
                return true;
            }
        }

        return false;
    }
}
