<?php

class ElasticQueryHelper
{
    private static $_isNestedPrepared = false;

    /**
     * CDbCriteria ->compare() compatible condition builder
     *
     * @param $column
     * @param $value
     * @param string $type integer|boolean|double|string (anything else is handled as string)
     * @param bool|false $partialMatch
     * @param float $boost
     * @return array|bool
     */
    public static function compare($column, $value, $type='string', $partialMatch=false, $boost=null)
    {
        if (strchr($column, '.', false)!==false && !self::$_isNestedPrepared) {
            return self::nestedCompare($column, $value, $type, $partialMatch, $boost);
        }
        self::$_isNestedPrepared = false;

        $type = in_array($type, ['integer', 'boolean', 'double', 'string']) ? $type : 'string';
        $partialMatch = $type=='string' ? $partialMatch : false;

        if(is_array($value)) {
            if($value===[])
                return false;
            return self::buildInCondition($column,$value);
        } else
            $value="{$value}";

        if(preg_match('/^(?:\s*(\!=|<>|<=|>=|<|>|=))?(.*)$/',$value,$matches)) {
            $value=$matches[2];
            $op=$matches[1];
        } else
            $op='';

        if($value==='')
            return false;

        if($type=='string' && $partialMatch) {
            return self::buildPartialMatchCondition($column,$value,$type,in_array($op, ['<>', '!=']),$boost);
        }

        if($op==='')
            $op='=';

//        self::addCondition($column.$op.'ycp'.'##','AND');
//        self::$params['ycp'.'##']=$value;
        return self::buildCondition($column, $value, $op, $boost);
    }

    /**
     * CDbCriteria->compare() compatible condition builder for nested documents
     *
     * @param $column
     * @param $value
     * @param string $type integer|boolean|double|string (anything else is handled as string)
     * @param bool|false $partialMatch
     * @param float $boost
     * @return array|bool
     */
    public static function nestedCompare($column, $value, $type='string', $partialMatch=false, $boost=null)
    {
        $matches = [];
        preg_match('#^(.*)\.(\w+)$#', $column, $matches);
        self::$_isNestedPrepared = true;
        return [
            'bool'=>[
                'must'=>[
                    'nested' => [
                        'path' => $matches[1],
                        'query' => [
                            self::compare($column, $value, $type, $partialMatch, $boost)
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * CDbCriteria->addInCondition() compatible condition builder
     *
     * @param $col
     * @param $val
     * @return array
     */
    public static function buildInCondition($col,$val)
    {
        return ['terms' => [$col => $val]];
    }

    /**
     * CDbCriteria->addSearchCondition() compatible condition builder
     *
     * @param $col
     * @param $val
     * @param $type
     * @param bool|false $not
     * @param float $boost
     * @return array
     */
    public static function buildPartialMatchCondition($col,$val,$type,$not=false,$boost=null)
    {
        if ($type=='string') {
            $values = explode(' ', $val);
            $query = [];
            foreach ($values as $value) {
                $query[] = [
                    'wildcard' => [
                        $col => $boost===null ? "*{$value}*" : [
                            'value' => "*{$value}*",
                            'boost'=>$boost,
                        ]
                    ]
                ];
            }
            return $not ? ['bool' => ['must_not' => $query]] : ['bool' => ['must' => $query]];
        } else {
            return self::buildCondition($col, $val, $not?'!=':'=',$boost);
        }
    }

    /**
     * builds condition for yii supported search operators
     * @todo support date cols
     *
     * @param $col
     * @param $val
     * @param $op
     * @param $boost
     * @return array
     */
    public static function buildCondition($col, $val, $op, $boost=null)
    {
        $query = [
            'match' => [
                $col => $boost===null ? $val : [
                    'query' => $val,
                    'boost' => $boost,
                ],
            ],
        ];
        switch ($op) {
            case '\!=':
            case '!=':
            case '<>':
                $ret = ['bool' => ['must_not' => $query]];
                break;
            case '<=':
                $ret = ['range' => [$col =>['lte'=>$val]]];
                $boost!==null && $ret['range'][$col]['boost'] = $boost;
                break;
            case '>=':
                $ret = ['range' => [$col =>['gte'=>$val]]];
                $boost!==null && $ret['range'][$col]['boost'] = $boost;
                break;
            case '<':
                $ret = ['range' => [$col =>['lt'=>$val]]];
                $boost!==null && $ret['range'][$col]['boost'] = $boost;
                break;
            case '>':
                $ret = ['range' => [$col =>['gt'=>$val]]];
                $boost!==null && $ret['range'][$col]['boost'] = $boost;
                break;
            case '=':
            default:
            $ret = $query;
        }
        return $ret;
    }
}
