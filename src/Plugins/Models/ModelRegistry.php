<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Plugins\Models;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use ReflectionClass;
use ReflectionMethod;
use Throwable;

/**
 * Spec 4.5, 6.2 (01-21 Part A): the `models` config, alias => ['class', 'relations', 'appends']. A valid
 * entry reaches model_show; an invalid one is left out (fail closed for that alias) and doctor names it.
 *
 * Read on every call, never cached (rule 14), and it never throws: tools are built on every request, also
 * for tools/list, so a broken app model must not break the list (01-20 triage).
 */
final class ModelRegistry
{
    public const ALIAS_PATTERN = '/^[a-z][a-z0-9_]{0,63}$/D';

    /** A relation or an appended attribute: a PHP method or accessor name. */
    public const NAME_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]{0,63}$/D';

    public const KEYS = ['class', 'relations', 'appends'];

    public function __construct(private readonly Repository $config) {}

    /**
     * The valid entries, in config order.
     *
     * @return array<string, array{class: class-string<Model>, relations: list<string>, appends: list<string>}>
     */
    public function entries(): array
    {
        return $this->scan()[0];
    }

    /**
     * Why each invalid entry is left out: alias (as given) => one sentence. Doctor prints it.
     *
     * @return array<string, string>
     */
    public function invalid(): array
    {
        return $this->scan()[1];
    }

    /** @return list<string> */
    public function aliases(): array
    {
        return array_keys($this->entries());
    }

    /** @return array{class: class-string<Model>, relations: list<string>, appends: list<string>}|null */
    public function entry(string $alias): ?array
    {
        return $this->entries()[$alias] ?? null;
    }

    /**
     * Every allow-listed relation name of every valid entry, sorted: the schema's items.enum and the
     * Rule::in() of with.* (01-18: they must agree).
     *
     * @return list<string>
     */
    public function relationUnion(): array
    {
        $names = [];

        foreach ($this->entries() as $entry) {
            array_push($names, ...$entry['relations']);
        }

        $names = array_values(array_unique($names));
        sort($names);

        return $names;
    }

    /**
     * @return array{
     *     array<string, array{class: class-string<Model>, relations: list<string>, appends: list<string>}>,
     *     array<string, string>,
     * }
     */
    private function scan(): array
    {
        $models = $this->config->get('runtime-mcp.models');

        if (! is_array($models)) {
            return [[], []];
        }

        $valid = [];
        $invalid = [];

        foreach ($models as $alias => $entry) {
            $alias = (string) $alias;
            $reason = self::problem($alias, $entry);

            if ($reason !== null) {
                $invalid[$alias] = $reason;

                continue;
            }

            /** @var array{class: class-string<Model>, relations?: list<string>, appends?: list<string>} $entry */
            $valid[$alias] = [
                'class' => $entry['class'],
                'relations' => array_values(array_unique($entry['relations'] ?? [])),
                'appends' => array_values(array_unique($entry['appends'] ?? [])),
            ];
        }

        return [$valid, $invalid];
    }

    /** Null for a valid entry, else why it is left out. Class names come from the config, so they may be printed. */
    private static function problem(string $alias, mixed $entry): ?string
    {
        if (preg_match(self::ALIAS_PATTERN, $alias) !== 1) {
            return 'the alias must be lower snake case: a letter, then letters, digits or underscores, at most 64 characters.';
        }

        if (! is_array($entry)) {
            return 'the entry is not an array with a `class` key.';
        }

        $unknown = array_diff(array_map('strval', array_keys($entry)), self::KEYS);

        if ($unknown !== []) {
            return 'the entry has an unknown key `'.implode('`, `', $unknown).'` (allowed: class, relations, appends).';
        }

        $class = $entry['class'] ?? null;

        if (! is_string($class) || ! self::isModelClass($class)) {
            return 'the class is not a concrete Eloquent model class that can be loaded.';
        }

        $lists = [];

        foreach (['relations', 'appends'] as $key) {
            $names = $entry[$key] ?? [];

            if (! is_array($names) || ! array_is_list($names)) {
                return "`{$key}` is not a list of names.";
            }

            $lists[$key] = [];

            foreach ($names as $name) {
                if (! is_string($name) || preg_match(self::NAME_PATTERN, $name) !== 1) {
                    return "`{$key}` holds a value that is not a method or attribute name.";
                }

                $lists[$key][] = $name;
            }
        }

        foreach ($lists['relations'] as $name) {
            if (! self::isRelationMethod($class, $name)) {
                return "the relation `{$name}` is not a public method of {$class} itself (a method of Eloquent's Model or of a Laravel trait, such as delete or restore, is never a relation).";
            }
        }

        return null;
    }

    /**
     * A concrete Eloquent model class that loads. A parse error in an app model is a Throwable from the
     * autoloader: the answer is false, nothing else fails. model_show checks a MorphTo target with it too.
     */
    public static function isModelClass(string $class): bool
    {
        try {
            return class_exists($class)
                && is_subclass_of($class, Model::class)
                && ! (new ReflectionClass($class))->isAbstract();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * model_show and doctor call the method by name, so a name must never reach a method of Model itself:
     * with 'relations' => ['delete'] it would call $record->delete(). Dynamic relations (resolveRelationUsing())
     * have no method and are refused too.
     */
    private static function isRelationMethod(string $class, string $name): bool
    {
        if (self::isLaravelMethod($class, $name) || ! method_exists($class, $name)) {
            return false;
        }

        $method = new ReflectionMethod($class, $name);

        return $method->isPublic() && ! $method->isStatic() && $method->getNumberOfRequiredParameters() === 0;
    }

    /**
     * A method of a Laravel parent class (Model itself among them) or of a Laravel trait (01-21 triage):
     * SoftDeletes::restore() and Prunable::pruneAll() write, MustVerifyEmail::sendEmailVerificationNotification()
     * sends mail. Notifiable's relations go too: an app wraps one in a method of its own.
     */
    private static function isLaravelMethod(string $class, string $name): bool
    {
        foreach ([...(class_parents($class) ?: []), ...class_uses_recursive($class)] as $source) {
            if (str_starts_with($source, 'Illuminate\\') && method_exists($source, $name)) {
                return true;
            }
        }

        return false;
    }
}
