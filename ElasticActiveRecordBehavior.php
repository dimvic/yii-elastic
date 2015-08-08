<?php

/**
 * Class ElasticActiveRecordBehavior
 *
 * @property CActiveRecord $owner
 *
 * @property string $elasticIndexName
 * @property string $elasticTypeName
 * @property array $elasticRawCols
 * @property array $elasticRelations
 */
class ElasticActiveRecordBehavior extends CActiveRecordBehavior
{
    public $elastic_index;
    public $elastic_type;
    public $elastic_raw_cols;
    public $elastic_relations = [];
    public $_elastic_documents_queue = [];
    public $_elastic_bulk_size = 1000;

    /**
     * @return string
     */
    public function getElasticIndexName()
    {
        return $this->elastic_index ?: preg_replace('#^.*;.*?name=(\w+).*$#', '$1', Yii::app()->db->connectionString);
    }

    /**
     * @return string
     */
    public function getElasticTypeName()
    {
        return $this->elastic_type ?: $this->owner->tableName();
    }

    /**
     * @return array
     */
    public function getElasticRawCols()
    {
        return $this->elastic_raw_cols ?: ['caption', 'slug', 'label', 'name'];
    }

    /**
     * @return array
     */
    public function getElasticRelations()
    {
        return $this->elastic_relations ?: [];
    }

    /**
     * @param array $query elastic search query:
     * [
     *    'query' => [],
     *    'aggs' => [],
     *    ...
     * ]
     * @param array $dataProviderOptions
     * [
     *    'pagination' => [],
     *    'sort' => [],
     *    ...
     * ]
     * @return ElasticActiveDataProvider
     */
    public function elasticSearch($query=[], $dataProviderOptions=[])
    {
        $this->owner->unsetAttributes();
        $filters = !empty($_REQUEST[get_class($this->owner)]) ? $_REQUEST[get_class($this->owner)] : [];
        $auto = [];
        $colSchema = $this->owner->tableSchema->columns;
        foreach ($filters as $col=>$val) {
            if (!$val)
                continue;
            if (in_array($col, $this->owner->safeAttributeNames)) {
                $desc = $colSchema[$col];//integer, boolean, double, string
                $val = $this->owner->{$col};
                if ($val!==null) {
                    $colType = $desc->type;
                    $temp = ElasticQueryHelper::compare($col, $val, $colType, true);
                    if ($temp) {
                        $auto[] = $temp;
                    }
                }
            } else if (strchr($col, '.')!==false) {
                $temp = ElasticQueryHelper::nestedCompare($col, $val, 'string', false);
                if ($temp) {
                    $auto[] = $temp;
                }
            }
        }
        if (!empty($auto)) {
            $auto = [
                'bool'=>[
                    'must'=>$auto,
                ],
            ];
        }

        if (empty($query['query'])) {
            $qry = $auto;
        } else {
            $qry = CMap::mergeArray($auto, [$query['query']]);
            unset($query['query']);
        }
        if (!empty($qry)) {
            $options = CMap::mergeArray([
                'criteria' => CMap::mergeArray([
                    'query' => [
                        'bool' => [
                            'must' => $qry,
                        ],
                    ],
                ], $query),
            ], $dataProviderOptions);
        } else {
            $options = CMap::mergeArray(['criteria'=>$query], $dataProviderOptions);
        }
        if (empty($options['criteria']['query']) && isset($options['criteria']['query'])) {
            unset($options['criteria']['query']);
        }

        return new ElasticActiveDataProvider(get_class($this->owner), $options);
    }

    /**
     * Create a text field you can use as a filter for searches on relations' fields
     *
     * @param $attribute
     * @return string
     */
    public function nestedFilterInput($attribute)
    {
        $class = get_class($this->owner);
        $name = "{$class}[{$attribute}]";
        $val = empty($_REQUEST[$class][$attribute]) ? null : $_REQUEST[$class][$attribute];
        $ret = CHtml::textField($name, $val);
        return $ret;
    }

    /**
     * @return \Elastica\Client
     */
    public function getElasticDbConnection()
    {
        return Yii::app()->elastica->getClient();
    }

    /**
     * @return \Elastica\Index
     */
    public function getElasticIndex()
    {
        $ret = $this->getElasticDbConnection()->getIndex($this->elasticIndexName);
        if (!$ret->exists()) $this->createElasticIndex($ret);
        return $ret;
    }

    /**
     * @return \Elastica\Type
     */
    public function getElasticType()
    {
        $ret = $this->getElasticIndex()->getType($this->elasticTypeName);
        if (!$ret->exists()) $this->createElasticType($ret);
        return $ret;
    }

    /**
     * refresh the elasticsearch index we are using
     */
    public function refreshElasticIndex()
    {
        $this->getElasticIndex()->refresh();
    }

    /**
     * @param CActiveRecord $m
     */
    public function queueElasticDocument($m=null)
    {
        $m===null && $m = $this->owner;
        $this->_elastic_documents_queue[] = new \Elastica\Document($m->primaryKey, $this->createElasticDocument($m));
    }

    /**
     * @param CActiveRecord $m
     */
    public function indexElasticDocument($m=null)
    {
        $m===null && $m = $this->owner;
        $this->queueElasticDocument($m);
        $this->addQueueToElastic(0);
        $this->refreshElasticIndex();
    }

    /**
     * add the documents we have queued so far to elasticsearch
     * @param null $required
     */
    public function addQueueToElastic($required=null)
    {
        $required===null && $required = $this->_elastic_bulk_size;
        if (count($this->_elastic_documents_queue)>$required) {
            $this->getElasticType()->addDocuments($this->_elastic_documents_queue);
            $this->_elastic_documents_queue = [];
        }
    }

    /**
     * batch re-index current model
     * @param int $perPage
     * @param int|null $limit
     * @param array|CDbCriteria $criteria
     * @throws CDbException
     */
    public function elasticRebuild($perPage=10000, $limit=null, $criteria=[])
    {
        $start = time();
        $index = $this->getElasticIndex();
        $this->createElasticIndex($index);

        $i = 0;
        do {
            $temp = new CDbCriteria(['offset'=>$i,'limit'=>$perPage]);
            $temp->mergeWith($criteria);

            /** @var CActiveRecord[] $models */
            $models = $this->owner->findAll($temp);

            $transaction = $this->owner->getDbConnection()->beginTransaction();
            foreach ($models as $model) {
                $this->queueElasticDocument($model);
                $this->addQueueToElastic();
            }
            $transaction->commit();

            $i += $perPage;
        } while (!empty($models) && (!$limit || ($limit && $i<$limit)));

        $this->addQueueToElastic(0);
        $this->refreshElasticIndex();
        echo (time()-$start).'s';
    }

    /**
     * @param \Elastica\Index $index
     */
    public function createElasticIndex($index)
    {
        $index->create([
            'number_of_shards' => 4,
            'number_of_replicas' => 1,
            'analysis' => [
                'analyzer' => [
                    'indexAnalyzer' => [
                        'type' => 'custom',
                        'tokenizer' => 'standard',
                        'filter' => ['lowercase'],
                    ],
                    'searchAnalyzer' => [
                        'type' => 'custom',
                        'tokenizer' => 'standard',
                        'filter' => ['standard', 'lowercase'],
                    ]
                ],
            ]
        ], true);
    }

    /**
     * @param \Elastica\Type $type
     */
    public function createElasticType($type)
    {
        $mapping = new \Elastica\Type\Mapping();

        $mapping
            ->setType($type)
            ->setProperties($this->elasticProperties())
            ->setParam('dynamic', 'strict')
            ->setParam('index_analyzer', 'indexAnalyzer')
            ->setParam('search_analyzer', 'searchAnalyzer')
            ->setParam('_boost', ['name' => '_boost', 'null_value' => 1.0])
            ->send();
    }

    public function elasticRelationsToArray($relations)
    {
        !is_array($relations) && $relations = [$relations];

        $ret = [];
        foreach ($relations as $relation) {
            $matches = [];
            preg_match('#^([^.]+)\.?(.*)#', $relation, $matches);
            $ret[$matches[1]] = !empty($ret[$matches[1]]) ? $ret[$matches[1]] : [];
            $ret[$matches[1]] = !empty($matches[2]) ? CMap::mergeArray($ret[$matches[1]], $this->elasticRelationsToArray($matches[2])) : [];
        }
        return $ret;
    }

    /**
     * @param CActiveRecord $m
     * @param array $nestedRelations
     * @return array
     */
    public function elasticProperties($m=null, $nestedRelations=null)
    {
        $m===null && $m = $this->owner;
        $nestedRelations===null && $nestedRelations = !empty($this->elasticRelations) ? $this->elasticRelationsToArray($this->elasticRelations) : [];

        $properties = [];
        foreach ($m->tableSchema->columns as $col => $desc) {//integer, boolean, double, string
            $colType = $desc->type;
            switch ($colType) {
                case 'boolean':
                case 'integer':
                case 'double':
                    $properties[$col] = ['type'=>$colType,  'null_value'=>0, 'include_in_all'=>true];
                    break;
                default:
                    if ($colType=='string' && preg_match('#_at$#', $col)) {
                        $properties[$col] = ['type'=>'date', 'null_value'=>0, 'include_in_all'=>true, 'format'=>'YYYY-MM-dd HH:mm:ss||YYYY-MM-dd'];
                    } else {
                        $properties[$col] = ['type'=>$colType,  'null_value'=>'', 'include_in_all'=>true];
                    }
                    if (in_array($col, $this->elasticRawCols)) {
                        $properties[$col]['fields'] = ['raw' => ['type'=>'string', 'index'=>'not_analyzed']];
                    }
            }
        }
        $properties['_boost'] = ['type'=>'float',  'null_value'=>1.0, 'include_in_all'=>false];
        if (!empty($nestedRelations)) {
            $mRelations = $m->relations();
            foreach ($nestedRelations as $relation=>$childNestedRelations) {
                if (empty($mRelations[$relation])) continue;

                $relationModel = new $mRelations[$relation][1];
                $properties[$relation] = [
                    'type' => 'nested',
                    'include_in_parent' => false,
                    'properties' => $this->elasticProperties($relationModel, $childNestedRelations),
                ];
            }
        }
        return $properties;
    }

    /**
     * @param CActiveRecord $m
     * @param array $nestedRelations
     * @return array
     */
    public function createElasticDocument($m=null, $nestedRelations=null)
    {
        $m===null && $m = $this->owner;
        $nestedRelations===null && $nestedRelations = !empty($this->elasticRelations) ? $this->elasticRelationsToArray($this->elasticRelations) : [];

        $document = [];
        foreach ($m->tableSchema->columns as $col => $desc) {//integer, boolean, double, string
            $colType = $desc->type;
            $val = $m->{$col};
            switch ($colType) {
                case 'boolean':
                case 'integer':
                    $document[$col] = (int)$val;
                    break;
                case 'double':
                    $document[$col] = (double)$val;
                    break;
                default:
                    if ($colType=='string' && preg_match('#_at$#', $col)) {
                        $document[$col] = strtotime($val)>0 ? strtotime($val) : 0;
                    } else {
                        $document[$col] = $val;
                    }
            }
        }
        if (!empty($nestedRelations)) {
            foreach ($nestedRelations as $relation=>$childNestedRelations) {
                $related = $m->{$relation};

                if (empty($related)) {
                    continue;
                } else if ($related instanceof CActiveRecord) {
                    $document[$relation] = $related->attributes;
                } else if (is_array($related)) {
                    $document[$relation] = [];
                    foreach ($related as $r) {
                        /** @var CActiveRecord $r */
                        $document[$relation][] = $this->createElasticDocument($r, $childNestedRelations);
                    }
                }
            }
        }
        return $document;
    }

    /**
     * @param CEvent $event
     */
    public function afterSave($event)
    {
        $this->indexElasticDocument();
        parent::afterSave($event);
    }

    /**
     * @param CEvent $event
     */
    public function afterDelete($event)
    {
        $this->getElasticIndex()->deleteByQuery([
            'query'=>[
                'term'=>[$this->owner->tableSchema->primaryKey => $this->owner->primaryKey],
            ],
        ]);
        parent::afterDelete($event);
    }
}
