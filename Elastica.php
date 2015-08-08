<?php
/**
 * https://github.com/anasAsh/Yii-Elastica
 */
class Elastica extends CApplicationComponent
{
    private $_client;

    public $host;
    public $port;
    public $servers;
    public $debug;

    public function getClient() {
        if (!$this->_client) {
            if ($this->debug) {
                define('DEBUG',true);
            }
            if ($this->servers) {
                $this->_client = new \Elastica\Client([$this->servers]);
            } else if ($this->host && $this->port) {
                $this->_client = new \Elastica\Client([
                    'host' => $this->host,
                    'port' => $this->port
                ]);
            } else {
                throw new Exception("Error loading Elastica client", 1);
            }
        }

        return $this->_client;

    }

}

