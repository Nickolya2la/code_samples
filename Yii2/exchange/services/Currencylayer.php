<?php
namespace exchange\services;

use app\helpers\CurlHelper;
use Exception;
use app\helpers\ArrayHelper;
use exchange\BaseExchange;

/**
 * Class Currencylayer
 * @package exchange\services
 */
class Currencylayer extends BaseExchange {

    /**
     * @var string
     */
    private $_apikey;

    /**
     * @var string
     */
    private $action = 'http://apilayer.net/api/';

    public function __construct()
    {
        $this->_apikey = ArrayHelper::getValue($this->getConfig(), 'apikey');

        if (empty($this->_apikey)) {
            throw new Exception('Empty required parameter apikey');
        }
    }

    /**
     * @param string $source
     * @param array $currencies
     * @return array
     */
    public function get($source, $currencies = [])
    {
        $options = [
            'source' => $source,
        ];

        if (!empty($currencies)) {
            $options['currencies'] = implode(',', $currencies);
        }

        $response = $this->request('live', $options);
        $response = (array)ArrayHelper::getValue($response, 'quotes', []);

        if (empty($response)) {
            return [];
        }

        $returnData = [
            $source => 1
        ];

        foreach ($response as $code => $value) {
            $returnData[str_replace($source, '', $code)] = $value;
        }

        return $returnData;
    }

    public function request($method, $params = [])
    {
        $url = $this->action . $method;

        $params['access_key'] = $this->_apikey;

        $response = CurlHelper::getContent($url . '?' . http_build_query($params));

        if (!$response) {
            return [];
        }

        return json_decode($response, true);
    }
}