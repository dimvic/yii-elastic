<?php

/**
 * Class ElasticaLoader
 */
class ElasticaLoader extends CApplicationComponent
{
    public function elastica_autoload($class)
    {
        $class = strtr($class, ['\\'=>'.']);
        $file = 'ext.elastic-yii.Elastica.lib.'.$class;
        if (is_file(Yii::getPathOfAlias($file).'.php')) {
            Yii::import($file, true);
        }
    }

    public function init()
    {
       Yii::registerAutoloader([$this, 'elastica_autoload'],true);
    }
}
