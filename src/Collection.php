<?php

namespace lroman242\LaravelCassandra;

use \Cassandra\Rows;
use lroman242\LaravelCassandra\Eloquent\Model;
use Illuminate\Database\Eloquent\Collection as BaseCollection;

class Collection extends BaseCollection
{
    /**
     * Cassandra rows instance
     *
     * @var \Cassandra\Rows
     */
    private $rows;

    /**
     * Set Cassandra rows instance related to the
     * collection items.
     *
     * Required for fetching next pages
     *
     * @param Rows $rows
     *
     * @return $this
     */
    public function setRowsInstance(Rows $rows)
    {
        $this->rows = $rows;

        return $this;
    }

    /**
     * Next page token
     *
     * @return mixed
     */
    public function getNextPageToken()
    {
        if ($this->rows === null) {
            return null;
        }

        return $this->rows->pagingStateToken();
    }

    /**
     * Last page indicator
     * @return bool
     */
    public function isLastPage()
    {
        if ($this->rows === null) {
            return true;
        }

        return $this->rows->isLastPage();
    }

    /**
     * Get next page
     *
     * @return Collection
     */
    public function nextPage()
    {
        if ($this->rows !== null && !$this->isLastPage()) {
            /** @var Model $instance */
            $model = $this->first();

            $nextPageRows = $nextPageItems = $this->rows->nextPage();
            $nextPageCollection = $model->newCassandraCollection($nextPageRows);

            return $nextPageCollection;
        }

        return new self;
    }

    /**
     * Get rows instance
     *
     * @return \Cassandra\Rows
     */
    public function getRows()
    {
        return $this->rows;
    }

    /**
     * Update current collection with results from
     * the next page
     *
     * @return Collection
     */
    public function appendNextPage()
    {
        $nextPage = $this->nextPage();

        if (!$nextPage->isEmpty()) {
            $this->items = array_merge($this->items, $nextPage->toArray());
            $this->rows = $nextPage->getRows();
        }

        return $this;
    }

    /**
     * Merge the collection with the given items.
     *
     * @param  \ArrayAccess|array  $items
     * @return static
     */
    public function merge($items)
    {
        $dictionary = $this->getDictionary();

        foreach ($items as $item) {
            $dictionary[(string) $item->getKey()] = $item;
        }

        return new static(array_values($dictionary));
    }

    /**
     * Reload a fresh model instance from the database for all the entities.
     *
     * @param  array|string  $with
     * @return static
     */
    public function fresh($with = [])
    {
        if ($this->isEmpty()) {
            return new static([]);
        }

        $model = $this->first();

        $freshModels = $model->newQueryWithoutScopes()
            ->whereIn($model->getKeyName(), $this->modelKeys())
            ->get()
            ->getDictionary();

        return $this->map(function ($model) use ($freshModels) {
            if ($model->exists && isset($freshModels[(string) $model->getKey()])) {
                return $freshModels[(string) $model->getKey()];
            } else {
                return null;
            }
        });
    }

    /**
     * Diff the collection with the given items.
     *
     * @param  \ArrayAccess|array  $items
     * @return static
     */
    public function diff($items)
    {
        $diff = new static;

        $dictionary = $this->getDictionary($items);

        foreach ($this->items as $item) {
            if (!isset($dictionary[(string) $item->getKey()])) {
                $diff->add($item);
            }
        }

        return $diff;
    }

    /**
     * Intersect the collection with the given items.
     *
     * @param  \ArrayAccess|array  $items
     * @return static
     */
    public function intersect($items)
    {
        $intersect = new static;

        $dictionary = $this->getDictionary($items);

        foreach ($this->items as $item) {
            if (isset($dictionary[(string) $item->getKey()])) {
                $intersect->add($item);
            }
        }

        return $intersect;
    }

    /**
     * Get a dictionary keyed by primary keys.
     *
     * @param  \ArrayAccess|array|null  $items
     * @return array
     */
    public function getDictionary($items = null)
    {
        $items = is_null($items) ? $this->items : $items;

        $dictionary = [];

        foreach ($items as $value) {
            $dictionary[(string) $value->getKey()] = $value;
        }

        return $dictionary;
    }
}
