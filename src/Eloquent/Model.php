<?php

namespace lroman242\LaravelCassandra\Eloquent;

use Carbon\Carbon;
use Cassandra\Rows;
use Cassandra\Timestamp;
use lroman242\LaravelCassandra\Collection;
use lroman242\LaravelCassandra\Query\Builder as QueryBuilder;
use Illuminate\Database\Eloquent\Model as BaseModel;
use Illuminate\Support\Str;

abstract class Model extends BaseModel
{
    /**
     * The connection name for the model.
     *
     * @var string
     */
    protected $connection = 'cassandra';

    /**
     * Indicates if the IDs are auto-incrementing.
     * This is not possible in cassandra so we override this
     *
     * @var bool
     */
    public $incrementing = false;

    /**
     * @inheritdoc
     */
    public function newEloquentBuilder($query)
    {
        return new Builder($query);
    }

    /**
     * @inheritdoc
     */
    protected function newBaseQueryBuilder()
    {
        $connection = $this->getConnection();

        return new QueryBuilder($connection, null, $connection->getPostProcessor());
    }

    /**
     * @inheritdoc
     */
    public function freshTimestamp()
    {
        return new Timestamp();
    }

    /**
     * @inheritdoc
     */
    public function fromDateTime($value)
    {
        // If the value is already a Timestamp instance, we don't need to parse it.
        if ($value instanceof Timestamp) {
            return $value;
        }

        // Let Eloquent convert the value to a DateTime instance.
        if (!$value instanceof \DateTime) {
            $value = parent::asDateTime($value);
        }

        return new Timestamp($value->getTimestamp() * 1000);
    }

    /**
     * @inheritdoc
     */
    protected function asDateTime($value)
    {
        // Convert UTCDateTime instances.
        if ($value instanceof Timestamp) {
            return Carbon::instance($value->toDateTime());
        }

        return parent::asDateTime($value);
    }

    /**
     * @inheritdoc
     */
    protected function originalIsNumericallyEquivalent($key)
    {
        $current = $this->attributes[$key];
        $original = $this->original[$key];

        // Date comparison.
        if (in_array($key, $this->getDates())) {
            $current = $current instanceof Timestamp ? $this->asDateTime($current) : $current;
            $original = $original instanceof Timestamp ? $this->asDateTime($original) : $original;

            return $current == $original;
        }

        return parent::originalIsNumericallyEquivalent($key);
    }

    /**
     * Get the table qualified key name.
     * Cassandra does not support the table.column annotation so
     * we override this
     *
     * @return string
     */
    public function getQualifiedKeyName()
    {
        return $this->getKeyName();
    }

    /**
     * Qualify the given column name by the model's table.
     *
     * @param  string  $column
     * @return string
     */
    public function qualifyColumn($column)
    {
        return $column;
    }

     /**
     * Set a given attribute on the model.
     *
     * @param  string  $key
     * @param  mixed  $value
     * @return $this
     */
    public function setAttribute($key, $value)
    {
        // First we will check for the presence of a mutator for the set operation
        // which simply lets the developers tweak the attribute as it is set on
        // the model, such as "json_encoding" an listing of data for storage.
        if ($this->hasSetMutator($key)) {
            $method = 'set' . Str::studly($key) . 'Attribute';

            return $this->{$method}($value);
        }

        // If an attribute is listed as a "date", we'll convert it from a DateTime
        // instance into a form proper for storage on the database tables using
        // the connection grammar's date format. We will auto set the values.
        elseif ($value !== null && $this->isDateAttribute($key)) {
            $value = $this->fromDateTime($value);
        }

        if ($this->isJsonCastable($key) && !is_null($value)) {
            $value = $this->castAttributeAsJson($key, $value);
        }

        // If this attribute contains a JSON ->, we'll set the proper value in the
        // attribute's underlying array. This takes care of properly nesting an
        // attribute in the array's value in the case of deeply nested items.
        if (Str::contains($key, '->')) {
            return $this->fillJsonAttribute($key, $value);
        }

        $this->attributes[$key] = $value;

        return $this;
    }
    
    /**
     * @inheritdoc
     */
    public function __call($method, $parameters)
    {
        // Unset method
        if ($method == 'unset') {
            return call_user_func_array([$this, 'drop'], $parameters);
        }

        return parent::__call($method, $parameters);
    }

    /**
     * Create a new Eloquent Collection instance.
     *
     * @param  Rows|array  $rows
     *
     * @return Collection
     *
     * @throws \Exception
     */
    public function newCassandraCollection($rows)
    {
        if (!is_array($rows) && !$rows instanceof \Cassandra\Rows) {
            throw new \Exception('Wrong type to create collection');//TODO: customize error
        }

        $items = [];
        foreach ($rows as $row) {
            $items[] = $this->newFromBuilder($row);
        }

        $collection = new Collection($items);

        if ($rows instanceof \Cassandra\Rows) {
            $collection->setRowsInstance($rows);
        }

        return $collection;
    }

    /**
     * Determine if the new and old values for a given key are equivalent.
     *
     * @param  string $key
     * @param  mixed $current
     * @return bool
     */
    public function originalIsEquivalent($key, $current)
    {
        if (!array_key_exists($key, $this->original)) {
            return false;
        }

        $original = $this->getOriginal($key);

        if ($current === $original) {
            return true;
        } elseif (is_null($current)) {
            return false;
        } elseif ($this->isDateAttribute($key)) {
            return $this->fromDateTime($current) ===
                $this->fromDateTime($original);
        } elseif ($this->hasCast($key)) {
            return $this->castAttribute($key, $current) ===
                $this->castAttribute($key, $original);
        } elseif ($this->isCassandraObject($current)) {
            return $this->valueFromCassandraObject($current) ===
                $this->valueFromCassandraObject($original);
        }

        return is_numeric($current) && is_numeric($original)
            && strcmp((string) $current, (string) $original) === 0;
    }

    /**
     * Check if object is instance of any cassandra object types
     *
     * @param $obj
     * @return bool
     */
    protected function isCassandraObject($obj)
    {
        if ($obj instanceof \Cassandra\Uuid ||
            $obj instanceof \Cassandra\Date ||
            $obj instanceof \Cassandra\Float ||
            $obj instanceof \Cassandra\Decimal ||
            $obj instanceof \Cassandra\Timestamp ||
            $obj instanceof \Cassandra\Inet ||
            $obj instanceof \Cassandra\Time
        ) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * Check if object is instance of any cassandra object types
     *
     * @param $obj
     * @return bool
     */
    protected function isCompareableCassandraObject($obj)
    {
        if ($obj instanceof \Cassandra\Uuid ||
            $obj instanceof \Cassandra\Inet
        ) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * Returns comparable value from cassandra object type
     *
     * @param $obj
     * @return mixed
     */
    protected function valueFromCassandraObject($obj)
    {
        $class = get_class($obj);
        $value = '';
        switch ($class) {
            case 'Cassandra\Date':
                $value = $obj->seconds();
                break;
            case 'Cassandra\Time':
                $value = $obj->__toString();
                break;
            case 'Cassandra\Timestamp':
                $value = $obj->time();
                break;
            case 'Cassandra\Float':
                $value = $obj->value();
                break;
            case 'Cassandra\Decimal':
                $value = $obj->value();
                break;
            case 'Cassandra\Inet':
                $value = $obj->address();
                break;
            case 'Cassandra\Uuid':
                $value = $obj->uuid();
                break;
        }

        return $value;
    }

}
