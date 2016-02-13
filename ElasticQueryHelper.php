<?php

class ElasticQueryHelper
{
    private static $_is_nested_prepared = false;
    public static $raw_cols = ['raw'];

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
        $col = preg_replace('/\.('.implode(self::$raw_cols, '|').')$/', '', $column);
        if (strchr($col, '.', false)!==false && !self::$_is_nested_prepared) {
            return self::nestedCompare($column, $value, $type, $partialMatch, $boost);
        }
        self::$_is_nested_prepared = false;

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
        $cnt = count($matches);
        if (in_array($matches[$cnt-1], self::$raw_cols)) {
            $matches[$cnt-2] = $matches[$cnt-2].$matches[$cnt-1];
            unset($matches[$cnt-1]);
        }
        self::$_is_nested_prepared = true;
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
     * fuzzy condition builder
     *
     * @param $column
     * @param $value
     * @param float $boost
     * @param integer $fuzziness
     * @return array|bool
     */
    public static function fuzzy($column, $value, $boost=null, $fuzziness=5)
    {
        $rawRegEx = '/\.('.implode(self::$raw_cols, '|').')$/';
        $raw = '';
        $matches = [];
        if (preg_match($rawRegEx, $column, $matches)) {
            $col = preg_replace('/\.('.implode(self::$raw_cols, '|').')$/', '', $column);
            $raw = $matches[0];
        } else {
            $col = $column;
        }

        if (strchr($col, '.', false)!==false && !self::$_is_nested_prepared) {
            return self::nestedFuzzy($column, $value, $boost, $fuzziness);
        }
        self::$_is_nested_prepared = false;

        if($value==='')
            return false;

        $col = "{$col}{$raw}";
        $query = [
            'fuzzy' => [
                $col => [
                    'value' => $value,
                    'fuzziness' => $fuzziness,
                    'prefix_length' => 1,
                    'max_expansions' => 100,
                ],
            ],
        ];
        $boost && $query['fuzzy'][$col]['boost'] = $boost;

        return $query;
    }

    /**
     * fuzzy condition builder for nested documents
     *
     * @param $column
     * @param $value
     * @param float $boost
     * @param integer $fuzziness
     * @return array|bool
     */
    public static function nestedFuzzy($column, $value, $boost=null, $fuzziness=5)
    {
        $matches = [];
        preg_match('#^(.*)\.(\w+)$#', $column, $matches);
        $cnt = count($matches);
        if (in_array($matches[$cnt-1], self::$raw_cols)) {
            $matches[$cnt-2] = $matches[$cnt-2].$matches[$cnt-1];
            unset($matches[$cnt-1]);
        }
        self::$_is_nested_prepared = true;
        return [
            'bool'=>[
                'must'=>[
                    'nested' => [
                        'path' => $matches[1],
                        'query' => [
                            self::fuzzy($column, $value, $boost, $fuzziness)
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
