<?php

namespace LaravelFillableRelations\Eloquent\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use RuntimeException;
use ReflectionObject;

/**
 * Mix this in to your model class to enable fillable relations.
 * Usage:
 *     use Illuminate\Database\Eloquent\Model;
 *     use LaravelFillableRelations\Eloquent\Concerns\HasFillableRelations;
 *
 *     class Foo extends Model
 *     {
 *         use HasFillableRelations;
 *         protected $fillable_relations = ['bar'];
 *
 *         function bar()
 *         {
 *             return $this->hasOne(Bar::class);
 *         }
 *     }
 *
 *     $foo = new Foo(['bar' => ['id' => 42]]);
 *     // or perhaps:
 *     $foo = new Foo(['bar' => ['name' => "Ye Olde Pubbe"]]);
 *
 * @mixin Model
 */
trait HasFillableRelations
{
    ///**
    // * The relations that should be mass assignable.
    // *
    // * @var array
    // */
    // protected $fillable_relations = [];

    public function fillableRelations()
    {
        return isset($this->fillable_relations) ? $this->fillable_relations : [];
    }

    public function extractFillableRelations(array $attributes)
    {
        $relationsAttributes = [];

        foreach ($this->fillableRelations() as $relationName) {
            $val = array_pull($attributes, $relationName);
            if ($val) {
                $relationsAttributes[$relationName] = $val;
            }
        }

        return [$relationsAttributes, $attributes];
    }

    public function fillRelations(array $relations)
    {
        foreach ($relations as $relationName => $attributes) {
            $relation = $this->{camel_case($relationName)}();

            $relationType = (new ReflectionObject($relation))->getShortName();
            $method = "fill{$relationType}Relation";
            if (!method_exists($this, $method)) {
                throw new RuntimeException("Unknown or unfillable relation type {$relationName}");
            }
            $this->{$method}($relation, $attributes, $relationName);
        }
    }

    public function fill(array $attributes)
    {
        list($relations, $attributes) = $this->extractFillableRelations($attributes);

        parent::fill($attributes);

        $this->fillRelations($relations);

        return $this;
    }

    public static function create(array $attributes = [])
    {
        list($relations, $attributes) = (new static)->extractFillableRelations($attributes);

        $model = new static($attributes);
        $model->fillRelations($relations);
        $model->save();

        return $model;
    }

    /**
     * @param BelongsTo $relation
     * @param array|Model $attributes
     */
    public function fillBelongsToRelation(BelongsTo $relation, $attributes, $relationName)
    {
        $entity = $attributes;
        if (!$attributes instanceof Model) {
            $entity = $relation->getRelated()
                ->where($attributes)->firstOrFail();
        }

        $relation->associate($entity);
    }

    /**
     * @param HasOne $relation
     * @param array|Model $attributes
     */
    public function fillHasOneRelation(HasOne $relation, $attributes, $relationName)
    {
        $related = $attributes;
        if (!$attributes instanceof Model) {
            $related = $relation->getRelated()->firstOrCreate($attributes);
        }

        $foreign_key = str_after($relation->getForeignKey(), '.');
        $local_key = str_after($relation->getQualifiedParentKeyName(), '.');

        $this->{$local_key} = $related->{$foreign_key};
    }

    /**
     * @param HasMany $relation
     * @param array $attributes
     */
    public function fillHasManyRelation(HasMany $relation, array $attributes, $relationName)
    {
        if (!$this->exists) {
            $this->save();
            $relation = $this->{camel_case($relationName)}();
        }

        $relation->delete();

        foreach ($attributes as $related) {
            if (!$related instanceof Model) {
                $related[$relation->getForeignKeyName()] = $relation->getParentKey();
                $related = $relation->getRelated()->newInstance($related);
                $related->exists = $related->getKey() != null;
            }

            $relation->save($related);
        }
    }

    /**
     * @param BelongsToMany $relation
     * @param array $attributes
     */
    public function fillBelongsToManyRelation(BelongsToMany $relation, array $attributes, $relationName)
    {
        if (!$this->exists) {
            $this->save();
            $relation = $this->{camel_case($relationName)}();
        }

        $relation->detach();

        foreach ($attributes as $related) {
            if (!$related instanceof Model) {
                $related = $relation->getRelated()
                    ->where($related)->firstOrFail();
            }

            $relation->attach($related);
        }
    }

    /**
     * @param MorphTo $relation
     * @param array|Model $attributes
     */
    public function fillMorphToRelation(MorphTo $relation, $attributes, $relationName)
    {
        $entity = $attributes;

        if (! $entity instanceof Model) {
            $entity = $relation->getRelated()->firstOrCreate($entity);
        }

        $relation->associate($entity);
    }
}
