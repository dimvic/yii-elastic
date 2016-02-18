<?php

/**
 * https://github.com/anasAsh/Yii-Elastica
 */
class Elastica extends CApplicationComponent
{
    private $client;

    public $host;
    public $port;
    public $servers;
    public $debug;

    public function init()
    {
        // @todo find a miraculous way for this to run before redirects (implement queue in session?)
        Yii::app()->attachEventHandler('onEndRequest', [$this, 'commit']);
    }

    public function getClient()
    {
        if (!$this->client) {
            if ($this->debug) {
                define('DEBUG', true);
            }
            if ($this->servers) {
                $this->client = new \Elastica\Client([$this->servers]);
            } elseif ($this->host && $this->port) {
                $this->client = new \Elastica\Client([
                    'host' => $this->host,
                    'port' => $this->port
                ]);
            } else {
                throw new Exception("Error loading Elastica client", 1);
            }
        }

        return $this->client;
    }

    public $queue = [];//which models to commit

    public function enQueue($class)
    {
        $this->queue[$class] = $class;
    }

    public function commit()
    {
        foreach ($this->queue as $class) {
            /** @var UActiveRecord $m */
            $m = new $class;
            $m->addQueueToElastic(1);
            $m->refreshElasticIndex();
        }
    }
}
