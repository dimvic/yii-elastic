<?php

/**
 * Class ElasticActiveDataProvider
 */
class ElasticActiveDataProvider extends CActiveDataProvider
{
    /** @var mixed|CActiveRecord */
    public $model;
    public $scores = [];
    protected $criteria;
    protected $countCriteria;
    protected $objectHash;

    public function __construct($modelClass, $config = [])
    {
        parent::__construct($modelClass, $config);
        $this->objectHash = spl_object_hash($this);
    }

    /**
     * @param mixed $className Really a string, defined as mixed only for the IDE not to display errors
     * @return CActiveRecord
     */
    protected function getModel($className)
    {
        return $className::model();
    }

    /**
     * @return CActiveRecord[]
     */
    protected function fetchData()
    {
        $pkAlias = "{$this->model->tableAlias}.{$this->model->tableSchema->primaryKey}";
        $keys = $this->fetchKeys();
        $implodedKeys = implode(',', $keys);
        $criteria = new CDbCriteria([
            'condition' => "{$pkAlias} in ($implodedKeys)",
            'order' => "field({$pkAlias}, {$implodedKeys})",
            'limit' => 5000,
        ]);
        !empty($this->model->elastic_findcriteria) && $criteria->mergeWith($this->model->elastic_findcriteria);
        return empty($keys) ? [] : $this->model->findAll($criteria);
    }

    /**
     * @return array
     */
    protected function fetchKeys()
    {
        return $this->memoize('fetchKeys', function () {
            $keys = [];
            foreach ($this->getResultSet(false)->getResults() as $result) {
                $keys[] = $result->getData()['id'];
                $this->scores[$result->getData()['id']] = $result->getScore();
            }
            return $keys;
        });
    }

    public function memoize(string $id, callable $callable)
    {
        static $cache = [];

        if (!isset($cache[$this->objectHash])) {
            $cache[$this->objectHash] = [];
        }

        return $cache[$this->objectHash][$id] ?? $cache[$this->objectHash][$id] = $callable();
    }

    /**
     * @param bool $count whether the count or results \Elastica\ResultSet should be returned
     * @return \Elastica\ResultSet
     */
    public function getResultSet($count = false)
    {
        return $this->memoize("getResultSet-{$count}", function () use ($count) {
            $search = new Elastica\Search($this->model->getElasticDbConnection());

            return $search
                ->addIndex($this->model->getElasticIndex())
                ->addType($this->model->getElasticType())
                ->setQuery($this->getQuery($count))->search();
        });
    }

    /**
     * @param bool $count whether the count or results criteria should be returned
     * @return \Elastica\Query
     */
    public function getQuery($count = false)
    {
        return $this->memoize("getResultSet-{$count}", function () use ($count) {
            $criteria = $count ? $this->getCountCriteria() : $this->getCriteria();
            empty($criteria['query']) && $criteria['query'] = null;
            $sort = $this->getSortCriteria();
            if (!empty($criteria['order'])) {
                $sort = $criteria['order'];
                unset($criteria['order']);
            }
            if (!$count) {
                $opts['sort'] = $sort;
                if (($pagination = $this->getPagination()) !== false) {
                    $pagination->setItemCount($this->getTotalItemCount());
                    $pagination->getOffset() && $opts['from'] = $pagination->getOffset();
                    $pagination->getLimit() && $opts['size'] = $pagination->getLimit();
                }
                $criteria = $opts + $criteria;
            }
            return new Elastica\Query($criteria);
        });
    }

    /**
     * @return array
     */
    public function getCountCriteria()
    {
        return $this->countCriteria ?: $this->criteria;
    }

    /**
     * @param array $value
     */
    public function setCountCriteria($value)
    {
        $this->countCriteria = $value;
    }

    /**
     * @return array
     */
    public function getCriteria()
    {
        return $this->criteria;
    }

    /**
     * @param array $value
     */
    public function setCriteria($value)
    {
        $this->criteria = $value;
    }

    /**
     * @return array
     */
    public function getSortCriteria()
    {
        return $this->memoize('getSortCriteria', function () {
            $ret = [];
            if (($sort = $this->getSort()) !== false) {
                $directions = $sort->getDirections();
                if (empty($directions)) {
                    if (is_string($sort->defaultOrder)) {
                        $ret[] = $this->parseOrderBy($sort->defaultOrder);
                    }
                } else {
                    foreach ($directions as $attribute => $descending) {
                        $definition = $sort->resolveAttribute($attribute);
                        if (is_array($definition)) {
                            if ($descending) {
                                $ret[] = isset($definition['desc'])
                                    ? (is_array($definition['desc'])
                                        ? $definition['desc']
                                        : $this->parseOrderBy($definition['desc'])
                                    )
                                    : [$attribute => 'desc'];
                            } else {
                                $ret[] = isset($definition['asc'])
                                    ? (is_array($definition['asc'])
                                        ? $definition['asc']
                                        : $this->parseOrderBy($definition['asc'])
                                    )
                                    : [$attribute => 'asc'];
                            }
                        } else if ($definition !== false) {
                            $attribute = $definition;
                            $ret[] = [$attribute => $descending ? 'desc' : 'asc'];
                        }
                    }
                }
            }
            $sort = [];
            foreach ($ret as $r) {
                !empty($r) && $sort[] = $r;
            }
            return CMap::mergeArray($sort, ['_score']);
        });
    }

    /**
     * @param $str
     * @return array
     */
    public function parseOrderBy($str)
    {
        $matches = [];
        preg_match('/^\s*(\w+)[\s.]+(\w+)/', $str, $matches);
        $order = [];
        if (!empty($matches)) {
            $order = !empty($matches[1]) ? [$matches[1] => (!empty($matches[2]) ? $matches[2] : 'asc')] : [];
        } else if (is_string($str)) {
            $order = [$str => 'asc'];
        }
        return $order;
    }

    /**
     * @return int
     */
    protected function calculateTotalItemCount()
    {
        return $this->memoize('calculateTotalItemCount', function () {
            return $this->getResultSet(true)->getTotalHits();
        });
    }
}
