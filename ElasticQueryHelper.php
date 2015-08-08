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
     * @return array|bool
     */
    public static function compare($column, $value, $type='string', $partialMatch=false)
    {
        if (strchr($column, '.', false)!==false && !self::$_isNestedPrepared) {
            return self::nestedCompare($column, $value, $type, $partialMatch);
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

        if($type=='string' && $partialMatch)
            return self::buildPartialMatchCondition($column,$value,$type,in_array($op, ['<>', '!=']));
        elseif($op==='')
            $op='=';

//        self::addCondition($column.$op.'ycp'.'##','AND');
//        self::$params['ycp'.'##']=$value;
        return self::buildCondition($column, $value, $op);
    }

    /**
     * CDbCriteria->compare() compatible condition builder for nested documents
     *
     * @param $column
     * @param $value
     * @param string $type integer|boolean|double|string (anything else is handled as string)
     * @param bool|false $partialMatch
     * @return array|bool
     */
    public static function nestedCompare($column, $value, $type='string', $partialMatch=false)
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
                            self::compare($column, $value, $type, $partialMatch)
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
     * @return array
     */
    public static function buildPartialMatchCondition($col,$val,$type,$not=false)
    {
        if ($type=='string') {
            $query = ['wildcard' => [$col => "*{$val}*"]];
            return $not ? ['bool' => ['must_not' => $query]] : $query;
        } else {
            return self::buildCondition($col, $val, $not?'!=':'=');
        }
    }

    /**
     * builds condition for yii supported search operators
     * @todo support date cols
     *
     * @param $col
     * @param $val
     * @param $op
     * @return array
     */
    public static function buildCondition($col, $val, $op)
    {
        switch ($op) {
            case '\!=':
            case '<>':
                $ret = ['bool' => ['must_not' => ['term' => [$col=>$val]]]];
                break;
            case '<=':
                $ret = ['range' => [$col =>['lte'=>$val]]];
                break;
            case '>=':
                $ret = ['range' => [$col =>['gte'=>$val]]];
                break;
            case '<':
                $ret = ['range' => [$col =>['lt'=>$val]]];
                break;
            case '>':
                $ret = ['range' => [$col =>['gt'=>$val]]];
                break;
            case '=':
            default:
            $ret = ['term' => [$col=>$val]];
        }
        return $ret;
    }
}
