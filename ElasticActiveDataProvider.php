<?php

/**
 * Class ElasticActiveDataProvider
 */
class ElasticActiveDataProvider extends CActiveDataProvider
{
    //this is an instance of CActiveRecord really, defined as mixed only for the IDE not to show errors
    /** @var mixed */
    public $model;
    private $_criteria;
    private $_countCriteria;

    /** @var \Elastica\ResultSet */
    private $_resultSet = [];

    /**
     * @param bool $count whether the count or results \Elastica\ResultSet should be returned
     * @return \Elastica\ResultSet
     */
    public function getResultSet($count=false)
    {
        $cnt = (int)$count;
        if (!isset($this->_resultSet[$cnt])) {
            $search = new Elastica\Search($this->model->getElasticDbConnection());
            $search
                ->addIndex($this->model->getElasticIndex())
                ->addType($this->model->getElasticType())
                ->setQuery($this->getQuery($count))
            ;
            $this->_resultSet[$cnt] = $search->search();
        }
        return $this->_resultSet[$cnt];
    }

    /**
     * @param bool $count whether the count or results criteria should be returned
     * @return \Elastica\Query
     */
    public function getQuery($count=false)
    {
        $criteria = $count ? $this->getCountCriteria() : $this->getCriteria();
        empty($criteria['query']) && $criteria['query'] = null;
        $query = new Elastica\Query($criteria);
        if (!$count) {
            if(($pagination=$this->getPagination())!==false) {
                $pagination->setItemCount($this->getTotalItemCount());
                $query->setFrom($pagination->getOffset());
                $query->setSize($pagination->getLimit());
            }
            $query->setSort(CMap::mergeArray(!empty($criteria['order']) ? $criteria['order'] : [], $this->getSortCriteria()));
        }
        return $query;
    }

    /**
     * @return array
     */
    public function getCriteria()
    {
        return $this->_criteria;
    }

    /**
     * @return array
     */
    public function getCountCriteria()
    {
        return $this->_countCriteria ?: $this->_criteria;
    }

    /**
     * @param array $value
     */
    public function setCriteria($value)
    {
        $this->_criteria=$value;
    }

    /**
     * @param array $value
     */
    public function setCountCriteria($value)
    {
        $this->_countCriteria=$value;
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
        return empty($keys) ? [] : $this->model->findAll([
            'condition' => "{$pkAlias} in ($implodedKeys)",
            'order' => "field({$pkAlias},{$implodedKeys})",
        ]);
    }

    /**
     * @return array
     */
    protected function fetchKeys()
    {
        $keys=[];
        foreach ($this->getResultSet(false)->getResults() as $result) {
            $keys[] = $result->getData()['id'];
        }
        return $keys;
    }

    /**
     * @return int
     */
    protected function calculateTotalItemCount()
    {
        return $this->getResultSet(true)->getTotalHits();
    }

    /**
     * @return array
     */
    public function getSortCriteria()
    {
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
                        if ($descending)
                            $ret[] = isset($definition['desc'])
                                ? (is_array($definition['desc']) ? $definition['desc'] : $this->parseOrderBy($definition['desc']))
                                : [$attribute => 'desc'];
                        else
                            $ret[] = isset($definition['asc'])
                                ? (is_array($definition['asc']) ? $definition['asc'] : $this->parseOrderBy($definition['asc']))
                                : [$attribute => 'asc'];
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
}
