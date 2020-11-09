<?php
namespace exchange;

use app\helpers\ArrayHelper;
use app\models\panels\ExchangeRates;
use Exception;
use Yii;
use yii\db\Query;

/**
 * Class Convert
 * @package exchange
 */
class Convert {

    /**
     * @var string
     */
    protected $_incomingCurrency;

    /**
     * @var string
     */
    protected $_resultCurrency;

    /**
     * @var string
     */
    protected $_service;

    /**
     * @var integer
     */
    protected $_decimals = 5;

    /**
     * @var array
     */
    protected static $rates;

    /**
     * @var array
     */
    protected static $allRates;

    /**
     * @param string $incomingCurrency
     * @return $this
     */
    public function setIncomingCurrency(string $incomingCurrency)
    {
        $this->_incomingCurrency = strtoupper($incomingCurrency);

        return $this;
    }

    /**
     * @param string $resultCurrency
     * @return $this
     */
    public function setResultCurrency(string $resultCurrency)
    {
        $this->_resultCurrency = strtoupper($resultCurrency);

        return $this;
    }

    /**
     * @param string $service
     * @return $this
     */
    public function setService(string $service)
    {
        $this->_service = $service;

        return $this;
    }

    /**
     * @param null|integer $decimals
     */
    public function setDecimals($decimals = null)
    {
        $this->_decimals = $decimals;
    }

    /**
     * @param float|integer $amount
     * @return float|integer
     * @throws Exception
     */
    public function get($amount)
    {
        if (empty($this->_incomingCurrency) || empty($this->_resultCurrency)) {
            throw new Exception('Wrong input settings');
        }

        if (!is_numeric($amount) || 0 >= $amount) {
            return 0;
        }

        if ($this->_incomingCurrency == $this->_resultCurrency) {
            return $amount;
        }

        $rates = $this->getLastRates();

        return $this->convert($amount, $rates);
    }

    /**
     * @param float|integer $amount
     * @return float|integer
     * @throws Exception
     */
    public function getFromCache($amount)
    {
        if (empty($this->_incomingCurrency) || empty($this->_resultCurrency)) {
            throw new Exception('Wrong input settings');
        }

        if (!is_numeric($amount) || 0 >= $amount) {
            return 0;
        }

        if ($this->_incomingCurrency == $this->_resultCurrency) {
            return $amount;
        }

        $rates = $this->getFromCacheLastRates();

        return $this->convert($amount, $rates);
    }



    /**
     * @return array
     */
    public function getLastRates()
    {
        if (isset(static::$rates[$this->_incomingCurrency], static::$rates[$this->_resultCurrency])) {
            return static::$rates;
        }

        $lastRowDateQuery = (new Query())
            ->select('created_at')
            ->from(ExchangeRates::tableName())
            ->andWhere([
                'currency' => [
                    $this->_incomingCurrency,
                    $this->_resultCurrency,
                ],
            ])
            ->having('COUNT(id) = 2')
            ->groupBy('created_at')
            ->orderBy([
                'id' => SORT_DESC
            ]);

        if ($this->_service) {
            $lastRowDateQuery->andWhere([
                'source' => $this->_service
            ]);
        }

        $ratesQuery = (new Query())
            ->select(['id', 'source_currency', 'currency', 'rate'])
            ->from(ExchangeRates::tableName())
            ->andWhere([
                'currency' => [
                    $this->_incomingCurrency,
                    $this->_resultCurrency,
                ],
            ])
            ->andWhere(['created_at' => $lastRowDateQuery->scalar()]);

        static::$rates = [];
        foreach ($ratesQuery->all() as $rate) {
            static::$rates[$rate['source_currency']] = [
                'id' => $rate['id'],
                'rate' => 1,
            ];
            static::$rates[$rate['currency']] = [
                'id' => $rate['id'],
                'rate' => $rate['rate'],
            ];
        }


        return static::$rates;
    }

    /**
     * @return array
     */
    public function getFromCacheLastRates()
    {
        if (!static::$allRates) {

            $lastRowDateQuery = (new Query())
                ->select('MAX(created_at)')
                ->from(ExchangeRates::tableName());

            $ratesQuery = (new Query())
                ->select(['id', 'source_currency', 'currency', 'rate'])
                ->from(ExchangeRates::tableName())
                ->andWhere(['created_at' => $lastRowDateQuery]);

            if ($this->_service) {
                $ratesQuery->andWhere([
                    'source' => $this->_service
                ]);
            }

            static::$allRates = [];
            foreach ($ratesQuery->all() as $rate) {
                static::$allRates[$rate['source_currency']] = [
                    'id' => $rate['id'],
                    'rate' => 1,
                ];
                static::$allRates[$rate['currency']] = [
                    'id' => $rate['id'],
                    'rate' => $rate['rate'],
                ];
            }
        }

        if (isset(static::$allRates[$this->_incomingCurrency], static::$allRates[$this->_resultCurrency])) {
            return static::$allRates;
        }

        return $this->getLastRates();
    }


    /**
     * @param float|integer $amount
     * @param array $rates
     * @return float|int
     * @throws Exception
     */
    protected function convert($amount, $rates)
    {
        if (empty($rates[$this->_incomingCurrency]) || empty($rates[$this->_resultCurrency])) {
            throw new Exception('Invalid exchange rates');
        }

        $incomingCurrencyRate = $rates[$this->_incomingCurrency]['rate'];
        $resultCurrencyRate = $rates[$this->_resultCurrency]['rate'];
        $convertedAmount = ($amount / $incomingCurrencyRate * $resultCurrencyRate);

        if (0 > $convertedAmount) {
            return 0;
        }

        if (null !== $this->_decimals) {
            $convertedAmount = round($convertedAmount, (int)$this->_decimals);
        }

        return $convertedAmount;
    }
}