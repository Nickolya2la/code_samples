<?php
namespace exchange;

use Yii;
use app\helpers\ArrayHelper;

/**
 * Class BaseExchange
 * @package exchange
 */
abstract class BaseExchange {

    /**
     * Get rate
     * @param string $source
     * @param array $currencies
     * @return mixed
     */
    abstract public function get($source, $currencies = []);

    protected function getConfig()
    {
        return ArrayHelper::getValue(Yii::$app->params, ['exchange_rates', strtolower((new \ReflectionClass($this))->getShortName())]);
    }
}