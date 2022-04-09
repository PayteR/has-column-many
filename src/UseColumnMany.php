<?php

namespace PayteR;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasRelationships;
use Illuminate\Database\Eloquent\Model;

trait UseColumnMany
{
    use HasRelationships;

    /**
     * Define a one-to-many relationship.
     *
     * @param  string  $related
     * @param  string|array $localKey Meaning column on current table
     * @param  string|null  $foreignKey
     * @return HasColumnMany
     */
    public function hasColumnMany($related, $localKey, $foreignKey = null)
    {
        $instance = $this->newRelatedInstance($related);

        $foreignKey = $foreignKey ?: $this->getKeyName();

        return $this->newHasColumnMany(
            $instance->newQuery(), $this, $localKey, $instance->getTable().'.'.$foreignKey
        );
    }

    /**
     * Instantiate a new HasMany relationship.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  \Illuminate\Database\Eloquent\Model  $parent
     * @param  string|array $localKey Meaning column on current table
     * @param  string  $foreignKey
     * @return HasColumnMany
     */
    protected function newHasColumnMany(Builder $query, Model $parent, $localKey, $foreignKey)
    {
        return new HasColumnMany($query, $parent, $localKey, $foreignKey);
    }
}
