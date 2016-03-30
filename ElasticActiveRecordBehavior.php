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
    public $elastic_update_after_save = true;
    public $elastic_find_criteria = [];

    public $elastic_index;
    public $elastic_type;
    public $elastic_raw_cols = ['caption', 'slug', 'label', 'name'];
    public $elastic_relations = [];
    public $elastica;
    public $elastic_documents_queue = [];
    public $elastic_bulk_size = 1000;

    /**
     * @var callable
     */
    public $elastic_transliterate = null;

    /**
     * @return string
     */
    public function getElasticIndexName()
    {
        return $this->owner->elastic_index
            ?: preg_replace('#^.*;.*?name=(\w+).*$#', '$1', Yii::app()->db->connectionString);
    }

    /**
     * @return string
     */
    public function getElasticTypeName()
    {
        return $this->owner->elastic_type ?: $this->owner->tableName();
    }

    /**
     * @return array
     */
    public function getElasticRawCols()
    {
        return $this->owner->elastic_raw_cols ?: $this->elastic_raw_cols;
    }

    /**
     * @return array
     */
    public function getElasticRelations()
    {
        return $this->owner->elastic_relations ?: $this->elastic_relations;
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
    public function elasticSearch($query = [], $dataProviderOptions = [])
    {
        $this->owner->unsetAttributes();
        $filters = !empty($_REQUEST[get_class($this->owner)]) ? $_REQUEST[get_class($this->owner)] : [];
        $auto = [];
        $colSchema = $this->owner->tableSchema->columns;
        foreach ($filters as $col => $val) {
            if (!$val) {
                continue;
            }

            if (in_array($col, $this->owner->safeAttributeNames) && !property_exists($this->owner, $col)) {
                $val = $this->owner->{$col};
                if ($val !== null) {
                    /** @var CMysqlColumnSchema $desc */
                    $desc = isset($colSchema[$col]) ? $colSchema[$col] : null;//integer, boolean, double, string
                    $colType = $desc ? $desc->type : 'string';
                    $temp = ElasticQueryHelper::compare($col, $val, $colType, true);
                    if ($temp) {
                        $auto[] = $temp;
                    }
                }
            } elseif (strchr($col, '.') !== false) {
                $temp = ElasticQueryHelper::nestedCompare($col, $val, 'string', false);
                if ($temp) {
                    $auto[] = $temp;
                }
            }
        }

        if (!empty($auto)) {
            $auto = [
                'bool' => [
                    'must' => $auto,
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
            $options = CMap::mergeArray(['criteria' => $query], $dataProviderOptions);
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
     * @return Elastica
     */
    public function getElastica()
    {
        !$this->elastica && $this->elastica = Yii::app()->elastica;
        return $this->elastica;
    }

    /**
     * @return \Elastica\Client
     */
    public function getElasticDbConnection()
    {
        return $this->getElastica()->getClient();
    }

    /**
     * @return \Elastica\Index
     */
    public function getElasticIndex()
    {
        $ret = $this->getElasticDbConnection()->getIndex($this->getElasticIndexName());
        if (!$ret->exists()) {
            $this->createElasticIndex($ret);
        }
        return $ret;
    }

    /**
     * @return \Elastica\Type
     */
    public function getElasticType()
    {
        $ret = $this->getElasticIndex()->getType($this->elasticTypeName);
        if (!$ret->exists()) {
            $this->createElasticType($ret);
        }
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
    public function queueElasticDocument($m = null)
    {
        $m === null && $m = $this->owner;
        $this->getElastica()->enQueue(get_class($this->owner));
        $this->elastic_documents_queue[] = new \Elastica\Document($m->primaryKey, $this->createElasticDocument($m));
    }

    /**
     * @param CActiveRecord $m
     */
    public function indexElasticDocument($m = null)
    {
        $m === null && $m = $this->owner;
        $this->queueElasticDocument($m);
        $this->addQueueToElastic(1);
        $this->refreshElasticIndex();
    }

    /**
     * add the documents we have queued so far to elasticsearch
     * @param null $required
     */
    public function addQueueToElastic($required = null)
    {
        $required === null && $required = $this->elastic_bulk_size;
        if (count($this->elastic_documents_queue) && count($this->elastic_documents_queue) >= $required) {
            $this->getElasticType()->addDocuments($this->elastic_documents_queue);
            $this->elastic_documents_queue = [];
        }
    }

    /**
     * batch re-index current model
     * @param int $perPage
     * @param int|null $limit
     * @param array|CDbCriteria $criteria
     * @param bool $resetType
     * @param bool $resetIndex
     * @throws CDbException
     */
    public function elasticRebuild(
        $perPage = 10000,
        $limit = null,
        $criteria = [],
        $resetType = false,
        $resetIndex = false
    ) {
        $index = $this->getElasticIndex();
        if ($resetIndex) {
            $this->getElasticIndex()->delete();
            $this->createElasticIndex($index);
        } elseif ($resetType) {
            $this->createElasticType(null, true);
        }

        $i = 0;
        $id = 0;
        $with = $this->buildRelationsWithCriteria();
        do {
            $temp = new CDbCriteria([
                'with' => $with,
                'together' => true,
                'limit' => $perPage,
                'order' => 't.id desc',
                'condition' => ($id ? "t.id<={$id}" : '')
            ]);
            $temp->mergeWith($criteria);

            /** @var CActiveRecord[] $models */
            $models = $this->owner->findAll($temp);

            $transaction = $this->owner->getDbConnection()->beginTransaction();
            foreach ($models as $model) {
                $this->queueElasticDocument($model);
                $this->addQueueToElastic();
                $id = $model->id;
            }
            $transaction->commit();
            $i = $i + $perPage;
        } while (count($models) > 1 && (!$limit || ($limit && $i < $limit)));

        $this->addQueueToElastic(1);
        $this->refreshElasticIndex();
    }

    public function buildRelationsWithCriteria($relations = null, $i = 0)
    {
        $relations === null && $relations = $this->elasticRelationsToArray($this->elasticRelations);
        $ret = [];

        foreach ($relations as $k => $v) {
            $ret[$k] = ['alias' => "rel{$i}"];
            if (!empty($v)) {
                $ret[$k] = CMap::mergeArray($ret[$k], ['with' => $this->buildRelationsWithCriteria($v, $i * 100)]);
            }
            $i++;
        }

        return $ret;
    }

    /**
     * @param \Elastica\Index $index
     */
    public function createElasticIndex($index)
    {
        if (method_exists($this->owner, 'createElasticIndex')) {
            $this->owner->createElasticIndex($index);
            return;
        }

        $index->create([
            'number_of_shards' => 4,
            'number_of_replicas' => 1,
//            'analysis' => [
//                'analyzer' => [
//                    'indexAnalyzer' => [
//                        'type' => 'snowball',
//                        'filter' => ['lowercase'],
//                        'language' => 'English',
//                    ],
//                    'searchAnalyzer' => [
//                        'type' => 'snowball',
//                        'filter' => ['lowercase'],
//                        'language' => 'English',
//                        'type' => 'custom',
//                        'tokenizer' => 'standard',
//                        'filter' => ['standard', 'lowercase'],
//                    ]
//                ],
//            ]
        ], true);
    }

    /**
     * @param \Elastica\Type $type
     * @param bool $reset
     */
    public function createElasticType($type = null, $reset = false)
    {
        if (method_exists($this->owner, 'createElasticType')) {
            $this->owner->createElasticType($type, $reset);
            return;
        }

        if ($type && $type->exists() && $reset) {
            $type->delete();
        }
        !$type && $type = $this->getElasticIndex()->getType($this->elasticTypeName);

        $mapping = new \Elastica\Type\Mapping();

        $mapping
            ->setType($type)
            ->setProperties($this->elasticProperties())
            ->setParam('dynamic', 'strict')
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
            $ret[$matches[1]] = !empty($matches[2])
                ? CMap::mergeArray($ret[$matches[1]], $this->elasticRelationsToArray($matches[2]))
                : [];
        }
        return $ret;
    }

    public function elasticProperty(&$properties, $col, $colType)
    {
        switch ($colType) {
            case 'boolean':
            case 'integer':
            case 'double':
                $properties[$col] = ['type' => $colType, 'null_value' => 0, 'include_in_all' => true];
                break;
            default:
                if ($colType == 'string' && preg_match('#_at$#', $col)) {
                    $properties[$col] = [
                        'type' => 'date',
                        'null_value' => '',
                        'include_in_all' => true,
                        'format' => 'YYYY-MM-dd HH:mm:ss||YYYY-MM-dd||epoch_second',
                    ];
                } else {
                    $properties[$col] = ['type' => $colType, 'null_value' => '', 'include_in_all' => true];
                }
                if (in_array($col, $this->elasticRawCols)) {
                    $properties[$col]['fields'] = ['raw' => ['type' => 'string', 'index' => 'not_analyzed']];
                }
        }
    }

    /**
     * @param CActiveRecord $m
     * @param array $nestedRelations
     * @return array
     */
    public function elasticProperties($m = null, $nestedRelations = null)
    {
        $m === null && $m = $this->owner;
        $nestedRelations === null && $nestedRelations = !empty($this->elasticRelations)
            ? $this->elasticRelationsToArray($this->elasticRelations)
            : [];

        $properties = [];
        foreach ($m->tableSchema->columns as $col => $desc) {//integer, boolean, double, string
            $this->elasticProperty($properties, $col, $desc->type);
        }

        if (!empty($nestedRelations)) {
            $mRelations = $m->relations();
            foreach ($nestedRelations as $relation => $childNestedRelations) {
                if (empty($mRelations[$relation])) {
                    continue;
                }

                $relationModel = new $mRelations[$relation][1];
                if ($mRelations[$relation][0]==CActiveRecord::STAT) {
                    $this->elasticProperty($properties, $relation, 'double');
                } else {
                    $properties[$relation] = [
                        'type' => 'nested',
                        'include_in_parent' => false,
                        'properties' => $this->elasticProperties($relationModel, $childNestedRelations),
                    ];
                }
            }
        }
        return $properties;
    }

    /**
     * @param CActiveRecord $m
     * @param array $nestedRelations
     * @return array
     */
    public function createElasticDocument($m = null, $nestedRelations = null)
    {
        $m === null && $m = $this->owner;
        $nestedRelations === null && $nestedRelations = !empty($this->elasticRelations)
            ? $this->elasticRelationsToArray($this->elasticRelations)
            : [];

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
                    $transliterator = $this->elastic_transliterate ? [$this, 'elastic_transliterate'] : 'mb_strtolower';
                    $val = call_user_func_array($transliterator, [$val]);
                    if ($colType == 'string' && preg_match('#_at$#', $col)) {
                        $document[$col] = strtotime($val) > 0 ? strtotime($val) : 0;
                    } else {
                        $document[$col] = $val;
                    }
            }
        }
        if (!empty($nestedRelations)) {
            foreach ($nestedRelations as $relation => $childNestedRelations) {
                $related = $m->{$relation};

                if ($related instanceof CActiveRecord) {
                    $document[$relation] = $this->createElasticDocument($related, $childNestedRelations);
                } elseif (is_array($related)) {
                    $document[$relation] = [];
                    foreach ($related as $r) {
                        /** @var CActiveRecord $r */
                        $document[$relation][] = $this->createElasticDocument($r, $childNestedRelations);
                    }
                } else if ($m->relations()[$relation][0]==CActiveRecord::STAT) {//STAT relation
                    $document[$relation] = $m->{$relation} ?: 0;
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
        if (empty($this->owner->elastic_update_after_save) || $this->owner->elastic_update_after_save) {
            $this->indexElasticDocument();
        }
        parent::afterSave($event);
    }

    /**
     * @param CEvent $event
     */
    public function afterDelete($event)
    {
        $this->getElasticIndex()->deleteByQuery(new \Elastica\Query([
            'query' => [
                'term' => [$this->owner->tableSchema->primaryKey => $this->owner->primaryKey],
            ],
        ]));
        parent::afterDelete($event);
    }
}
