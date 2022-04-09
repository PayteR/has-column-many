<?php

namespace PayteR;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Concerns\InteractsWithDictionary;
use Illuminate\Database\Eloquent\Relations\Relation;

class HasColumnMany extends Relation
{
    use InteractsWithDictionary;

    /**
     * The foreign key of the parent model.
     *
     * @var string
     */
    protected $foreignKey;

    /**
     * The local key of the parent model.
     *
     * @var string|array
     */
    protected $localKey;

    /**
     * Create a new has one or many relationship instance.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  \Illuminate\Database\Eloquent\Model  $parent
     * @param  string|array $localKey  Meaning column on current table
     * @param  string $foreignKey
     * @return void
     */
    public function __construct(Builder $query, Model $parent, $localKey, $foreignKey)
    {
        $this->localKey = is_array($localKey) ? $localKey : [$localKey];
        $this->foreignKey = $foreignKey;

        parent::__construct($query, $parent);
    }

    /**
     * Get the results of the relationship.
     *
     * @return mixed
     */
    public function getResults()
    {
        $models = $this->query->get();

        $ids = $this->getKeysFromColumns([$this->parent], $this->getLocalKeyNames());
        $models = \Arr::sort($models, function($model) use ($ids){
            return array_search($model->getKey(), $ids);
        });

        return $models;
    }

    /**
     * Initialize the relation on a set of models.
     *
     * @param  array  $models
     * @param  string  $relation
     * @return array
     */
    public function initRelation(array $models, $relation)
    {
        $foreignKey = $this->getForeignKeyName();
        foreach ($models as $model) {
            $values = $this->getKeysFromColumns([$model], $this->getLocalKeyNames());

            $relatedModels = $this->related->whereIn($foreignKey, $values);
            $model->setRelation($relation, $relatedModels);
        }

        return $models;
    }

    /**
     * Match the eagerly loaded results to their parents.
     *
     * @param  array  $models
     * @param  \Illuminate\Database\Eloquent\Collection  $results
     * @param  string  $relation
     * @return array
     */
    public function match(array $models, Collection $results, $relation)
    {
        return $this->matchMany($models, $results, $relation);
    }

    /**
     * Set the base constraints on the relation query.
     *
     * @return void
     */
    public function addConstraints()
    {
        if (static::$constraints) {
            $query = $this->getRelationQuery();

            $foreignKey = $this->getForeignKeyName();
            $values = $this->getParentKey();

            $query->whereIn($foreignKey, $values);
        }
    }

    /**
     * Set the constraints for an eager load of the relation.
     *
     * @param  array  $models
     * @return void
     */
    public function addEagerConstraints(array $models)
    {
        $values = $this->getKeysFromColumns($models, $this->getLocalKeyNames());

        $this->getRelationQuery()->whereIntegerInRaw($this->foreignKey, $values);
    }

    /**
     * Match the eagerly loaded results to their single parents.
     *
     * @param  array  $models
     * @param  \Illuminate\Database\Eloquent\Collection  $results
     * @param  string  $relation
     * @return array
     */
    public function matchOne(array $models, Collection $results, $relation)
    {
        return $this->matchOneOrMany($models, $results, $relation, 'one');
    }

    /**
     * Match the eagerly loaded results to their many parents.
     *
     * @param  array  $models
     * @param  \Illuminate\Database\Eloquent\Collection  $results
     * @param  string  $relation
     * @return array
     */
    public function matchMany(array $models, Collection $results, $relation)
    {
        return $this->matchOneOrMany($models, $results, $relation, 'many');
    }

    /**
     * Match the eagerly loaded results to their many parents.
     *
     * @param  array  $models
     * @param  \Illuminate\Database\Eloquent\Collection  $results
     * @param  string  $relation
     * @param  string  $type
     * @return array
     */
    protected function matchOneOrMany(array $models, Collection $results, $relation, $type)
    {
        $dictionary = $this->buildDictionary($results);

        // Once we have the dictionary we can simply spin through the parent models to
        // link them up with their children using the keyed dictionary to make the
        // matching very convenient and easy work. Then we'll just return them.
        foreach ($models as $model) {
            $model->setRelation(
                $relation, $this->getRelationValue($dictionary, $model)
            );
        }

        return $models;
    }

    /**
     * Get the value of a relationship by one or many type.
     *
     * @param  array  $dictionary
     * @param  Model $parentModel
     * @return mixed
     */
    protected function getRelationValue(array $dictionary, $parentModel)
    {
        $models = (new Collection($dictionary))->flatten();

        $ids = $this->getKeysFromColumns([$parentModel], $this->getLocalKeyNames());
        $models = $this->sortModelsByIds($models, $ids);

        return $this->related->newCollection($models);
    }

    /**
     * @param Collection|\Illuminate\Support\Collection $models
     * @param array $ids
     * @return mixed
     */
    protected function sortModelsByIds($models, array $ids)
    {
        return $models->filter(function($model) use ($ids){
            return in_array($model->getKey(), $ids);
        })->sortBy(function($model) use ($ids){
            return array_search($model->getKey(), $ids);
        })->all();
    }

    /**
     * Build model dictionary keyed by the relation's foreign key.
     *
     * @param  \Illuminate\Database\Eloquent\Collection  $results
     * @return array
     */
    protected function buildDictionary(Collection $results)
    {
        $foreign = $this->getForeignKeyName();

        return $results->mapToDictionary(function ($result) use ($foreign) {
            return [$this->getDictionaryKey($result->{$foreign}) => $result];
        })->all();
    }

    /**
     * Find a model by its primary key or return a new instance of the related model.
     *
     * @param  mixed  $id
     * @param  array  $columns
     * @return \Illuminate\Support\Collection|\Illuminate\Database\Eloquent\Model
     */
    public function findOrNew($id, $columns = ['*'])
    {
        if (is_null($instance = $this->find($id, $columns))) {
            $instance = $this->related->newInstance();

            $this->setForeignAttributesForCreate($instance);
        }

        return $instance;
    }

    /**
     * Get the first related model record matching the attributes or instantiate it.
     *
     * @param  array  $attributes
     * @param  array  $values
     * @return \Illuminate\Database\Eloquent\Model
     */
    public function firstOrNew(array $attributes = [], array $values = [])
    {
        if (is_null($instance = $this->where($attributes)->first())) {
            $instance = $this->related->newInstance(array_merge($attributes, $values));

            $this->setForeignAttributesForCreate($instance);
        }

        return $instance;
    }

    /**
     * Get the first related record matching the attributes or create it.
     *
     * @param  array  $attributes
     * @param  array  $values
     * @return \Illuminate\Database\Eloquent\Model
     */
    public function firstOrCreate(array $attributes = [], array $values = [])
    {
        if (is_null($instance = $this->where($attributes)->first())) {
            $instance = $this->create(array_merge($attributes, $values));
        }

        return $instance;
    }

    /**
     * Create or update a related record matching the attributes, and fill it with values.
     *
     * @param  array  $attributes
     * @param  array  $values
     * @return \Illuminate\Database\Eloquent\Model
     */
    public function updateOrCreate(array $attributes, array $values = [])
    {
        return tap($this->firstOrNew($attributes), function ($instance) use ($values) {
            $instance->fill($values);

            $instance->save();
        });
    }

    /**
     * Attach a model instance to the parent model.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return \Illuminate\Database\Eloquent\Model|false
     */
    public function save(Model $model)
    {
        $this->setForeignAttributesForCreate($model);

        return $model->save() ? $model : false;
    }

    /**
     * Attach a collection of models to the parent instance.
     *
     * @param  iterable  $models
     * @return iterable
     */
    public function saveMany($models)
    {
        foreach ($models as $model) {
            $this->save($model);
        }

        return $models;
    }

    /**
     * Create a new instance of the related model.
     *
     * @param  array  $attributes
     * @return \Illuminate\Database\Eloquent\Model
     */
    public function create(array $attributes = [])
    {
        return tap($this->related->newInstance($attributes), function ($instance) {
            $this->setForeignAttributesForCreate($instance);

            $instance->save();
        });
    }

    /**
     * Create a Collection of new instances of the related model.
     *
     * @param  iterable  $records
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function createMany(iterable $records)
    {
        $instances = $this->related->newCollection();

        foreach ($records as $record) {
            $instances->push($this->create($record));
        }

        return $instances;
    }

    /**
     * Set the foreign ID for creating a related model.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return void
     */
    protected function setForeignAttributesForCreate(Model $model)
    {
        $model->setAttribute($this->getForeignKeyName(), $this->getParentKey());
    }

    /**
     * Add the constraints for a relationship query.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  \Illuminate\Database\Eloquent\Builder  $parentQuery
     * @param  array|mixed  $columns
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function getRelationExistenceQuery(Builder $query, Builder $parentQuery, $columns = ['*'])
    {
        if ($query->getQuery()->from == $parentQuery->getQuery()->from) {
            return $this->getRelationExistenceQueryForSelfRelation($query, $parentQuery, $columns);
        }

        return parent::getRelationExistenceQuery($query, $parentQuery, $columns);
    }

    /**
     * Add the constraints for a relationship query on the same table.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  \Illuminate\Database\Eloquent\Builder  $parentQuery
     * @param  array|mixed  $columns
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function getRelationExistenceQueryForSelfRelation(Builder $query, Builder $parentQuery, $columns = ['*'])
    {
        $query->from($query->getModel()->getTable().' as '.$hash = $this->getRelationCountHash());

        $query->getModel()->setTable($hash);

        return $query->select($columns)->whereColumn(
            $this->getQualifiedParentKeyName(), '=', $hash.'.'.$this->getForeignKeyName()
        );
    }

    /**
     * Get the key for comparing against the parent key in "has" query.
     *
     * @return string
     */
    public function getExistenceCompareKey()
    {
        return $this->getQualifiedForeignKeyName();
    }

    /**
     * Get the key value of the parent's local key.
     *
     * @return array
     */
    public function getParentKey(): array
    {
        $return = [];
        $localKeys = $this->getLocalKeyNames();
        foreach ($localKeys as $key) {
            if($value = $this->parent->getAttribute($this->parseColumnName($key))) {
                $return = array_merge($return, $this->formatAttributeToArray($value, $key));
            }
        }

        return \Arr::flatten($return);
    }

    /**
     * Format data from column to array, decode or unserialize, if
     * it's JSON or Serialized
     *
     * @param $value
     * @param $key
     * @return array
     */
    protected function formatAttributeToArray($value, $key) {

        if(stripos($key, '.') === false) {
            return array_map('intval', explode(',', $value));
        }

        if($this->isJson($value)) {
            $value = json_decode($value);
        } else {
            $value = unserialize($value);
        }

        // remove first element from array because it's column name
        $key = explode('.', $key);
        array_shift($key);
        $key = implode('.', $key);

        $value = data_get($value, '*.' . $key);

        return array_map('intval', $value);
    }

    protected function isJson($string) {
        json_decode($string);
        return json_last_error() === JSON_ERROR_NONE;
    }

    /**
     * Get the fully qualified parent key name.
     *
     * @return string
     */
    public function getQualifiedParentKeyName()
    {
        $localKey = $this->getLocalKeyNames(); // this is wrong, maybe fix later, don't know what that's even do
        return $this->parent->qualifyColumn($localKey[0]);
    }

    /**
     * Get the plain foreign key.
     *
     * @return string
     */
    public function getForeignKeyName()
    {
        $segments = explode('.', $this->getQualifiedForeignKeyName());

        return end($segments);
    }

    /**
     * Get the foreign key for the relationship.
     *
     * @return string
     */
    public function getQualifiedForeignKeyName(): string
    {
        return $this->foreignKey;
    }

    /**
     * Get the local key parsed without array path
     * for the relationship.
     *
     * @return array
     */
    public function parseColumnName($localKey): string
    {
        $columnName = explode('.', $localKey);
        return reset($columnName);
    }

    /**
     * Get raw paths for local keys
     *
     * @return array
     */
    public function getLocalKeyNames(): array
    {
        return $this->localKey;
    }

    /**
     * Get all of the primary keys for an array of models.
     *
     * @param  array  $models
     * @param  array  $keys
     * @return array
     */
    protected function getKeysFromColumns(array $models, array $keys)
    {
        return collect($models)->map(function ($model) use ($keys) {
            $return = [];
            foreach ($keys as $key) {
                $attribute = $model->getAttribute($this->parseColumnName($key));
                if(!$attribute) continue;

                $return = array_merge($return, $this->formatAttributeToArray($attribute, $key));
            }
            return $return;
        })->flatten()->unique(null, true)->all();
    }
}
