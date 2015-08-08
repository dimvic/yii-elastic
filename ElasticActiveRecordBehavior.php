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
    public $_elastic_index = null;
    public $_elastic_type = null;
    public $_elastic_raw_cols = ['caption', 'slug', 'label'];
    public $_elastic_relations = [];
    public $_elastic_documents_queue = [];
    public $_elastic_bulk_size = 1000;

    /**
     * @return string
     */
    public function getElasticIndexName()
    {
        $this->_elastic_index===null && $this->_elastic_index = $this->_elastic_index ?: preg_replace('#^.*;.*?name=(\w+).*$#', '$1', Yii::app()->db->connectionString);
        return $this->_elastic_index;
    }

    /**
     * @return string
     */
    public function getElasticTypeName()
    {
        return !empty($this->owner->_elastic_type) ? $this->owner->_elastic_type : $this->owner->tableName();
    }

    /**
     * @return array
     */
    public function getElasticRawCols()
    {
        return !empty($this->owner->_elastic_raw_cols) ? $this->owner->_elastic_raw_cols : $this->_elastic_raw_cols;
    }

    /**
     * @return array
     */
    public function getElasticRelations()
    {
        return !empty($this->owner->_elastic_relations) ? $this->owner->_elastic_relations : $this->_elastic_relations;
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
        $auto = [];
        $colSchema = $this->owner->tableSchema->columns;
        foreach ($this->owner->safeAttributeNames as $col) {
            if (!$this->owner->hasAttribute($col))
                continue;

            $desc = $colSchema[$col];//integer, boolean, double, string
            $val = $this->owner->{$col};
            if ($val!==null) {
                $colType = $desc->type;
                $temp = ElasticQueryHelper::compare($col, $val, $colType, true);
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
     * @return \Elastica\Client
     */
    public function getElasticDbConnection()
    {
        return UElastica::client();
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

    /**
     * @param CActiveRecord $m
     * @param array $nestedRelations
     * @return array
     */
    public function elasticProperties($m=null, $nestedRelations=null)
    {
        $m===null && $m = $this->owner;
        $nestedRelations===null && $nestedRelations = !empty($this->elasticRelations) ? $this->elasticRelations : [];

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
            foreach ($nestedRelations as $k=>$v) {
                $relation = is_int($k) ? $v : $k;
                $childNestedRelations = is_int($k) ? [] : $v;
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
        $nestedRelations===null && $nestedRelations = !empty($this->elasticRelations) ? $this->elasticRelations : [];

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
            foreach ($nestedRelations as $k=>$v) {
                $relation = is_int($k) ? $v : $k;
                $childNestedRelations = is_int($k) ? [] : $v;
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
