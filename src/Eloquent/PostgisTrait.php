<?php namespace Phaza\LaravelPostgis\Eloquent;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Support\Arr;
use Phaza\LaravelPostgis\Exceptions\PostgisFieldsNotDefinedException;
use Phaza\LaravelPostgis\Geometries\Geometry;
use Phaza\LaravelPostgis\Geometries\GeometryInterface;
use Phaza\LaravelPostgis\Schema\Grammars\PostgisGrammar;

trait PostgisTrait
{

    public $geometries = [];
    /**
     * Create a new Eloquent query builder for the model.
     *
     * @param  \Illuminate\Database\Query\Builder $query
     * @return \Phaza\LaravelPostgis\Eloquent\Builder
     */
    public function newEloquentBuilder($query)
    {
        return new Builder($query);
    }

    protected function performInsert(EloquentBuilder $query, array $options = [])
    {
        foreach ($this->attributes as $key => $value) {
            if ($value instanceof GeometryInterface) {
                $attrs = $this->getPostgisType($key);
                $this->geometries[$key] = $value; //Preserve the geometry objects prior to the insert
                if (! $value instanceof GeometryCollection) {
                    switch (strtoupper($attrs['geomtype'])) {
                        case 'GEOMETRY':
                            $this->attributes[$key] = $this->getConnection()->raw(sprintf("public.ST_GeomFromText('%s', '%d')", $value->toWKT(), $attrs['srid']));
                            break;
                        case 'GEOGRAPHY':
                        default:
                            $this->attributes[$key] = $this->getConnection()->raw(sprintf("public.ST_GeogFromText('%s')", $value->toWKT()));
                            break;
                    }
                } else {
                    $this->attributes[$key] = $this->getConnection()->raw(sprintf("public.ST_GeomFromText('%s', 4326)", $value->toWKT()));
                }
            }
        }

        $insert = parent::performInsert($query, $options);

        foreach($this->geometries as $key => $value){
            $this->attributes[$key] = $value; //Retrieve the geometry objects so they can be used in the model
        }

        return $insert; //Return the result of the parent insert
    }

    public function setRawAttributes(array $attributes, $sync = false)
    {
        $pgfields = $this->getPostgisFields();

        foreach ($attributes as $attribute => &$value) {
            if (in_array($attribute, $pgfields) && is_string($value) && strlen($value) >= 15) {
                $value = Geometry::fromWKB($value);
            }
        }

        return parent::setRawAttributes($attributes, $sync);
    }

    public function getPostgisType($key)
    {
        if (property_exists($this, 'postgisFields')) {
            if (Arr::isAssoc($this->postgisFields)) {
                $column = $this->postgisFields[$key];
                if (in_array(strtoupper($column['geomtype']), PostgisGrammar::$allowed_geom_types)) {
                    return $column;
                }
            }
            return [
                'geomtype' => 'geography',
                'srid' => 4326
            ];
        } else {
            throw new PostgisFieldsNotDefinedException(__CLASS__ . ' has to define $postgisFields');
        }
    }

    public function getPostgisFields()
    {
        if (property_exists($this, 'postgisFields')) {
            return Arr::isAssoc($this->postgisFields) ? //Is the array associative?
                array_keys($this->postgisFields) : //Returns just the keys to preserve compatibility with previous versions
                $this->postgisFields; //Returns the non-associative array that doesn't define the geometry type.
        } else {
            throw new PostgisFieldsNotDefinedException(__CLASS__ . ' has to define $postgisFields');
        }

    }
}
