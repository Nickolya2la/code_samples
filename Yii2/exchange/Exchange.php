<?php
namespace exchange;

use yii\base\UnknownClassException;

/**
 * Class Exchange
 * @package exchange
 */
class Exchange extends BaseExchange {

    /**
     * @var array - services
     */
    protected static $services = [];

    /**
     * @var string
     */
    protected $service;

    /**
     * Exchange constructor.
     * @param string $service
     */
    public function __construct(string $service)
    {
        $this->service = $service;
    }

    /**
     * @param $service
     * @return mixed
     * @throws UnknownClassException
     */
    public function getExchange($service)
    {
        if (!empty(static::$services[$service])) {
            return static::$services[$service];
        }

        $className = '\exchange\services\\' . (ucfirst($service));

        if (!class_exists($className)) {
            throw new UnknownClassException();
        }

        static::$services[$service] = new $className();

        return static::$services[$service];
    }

    /**
     * @param string $source
     * @param array $currencies
     * @return mixed
     */
    public function get($source, $currencies = [])
    {
        return $this->getExchange($this->service)->get($source, $currencies);
    }
}