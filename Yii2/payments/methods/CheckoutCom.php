<?php

namespace payments\methods;

use app\helpers\CurlHelper;
use app\models\panel\PaymentsLog;
use Checkout\CheckoutApi;
use Checkout\Models\Payments\BillingDescriptor;
use Checkout\Models\Payments\Customer;
use Checkout\Models\Payments\Payment;
use Checkout\Library\Exceptions\CheckoutHttpException;
use app\models\panel\Payments;
use Checkout\Models\Payments\ThreeDs;
use Checkout\Models\Payments\TokenSource;
use payments\exceptions\ValidationException;
use Yii;
use app\models\panels\PaymentMethods;
use payments\BasePayment;
use app\helpers\AssetsHelper;
use yii\helpers\ArrayHelper;

/**
 * Class CheckoutCom
 * https://docs.checkout.com/docs/frames
 * @package payments\methods
 */
class CheckoutCom extends BasePayment
{
    const RESPONSE_CODE_SUCCESS = '10000';

    /**
     * Final status, payment captured
     */
    const CAPTURED_WEBHOOK_EVENT = 'payment_captured';
    /**
     * Intermediate status, payment verified and approved by PG
     */
    const APPROVED_WEBHOOK_EVENT = 'payment_approved';
    /**
     * Payment declined by PG
     */
    const DECLINED_WEBHOOK_EVENT = 'payment_declined';

    protected $_method_id = PaymentMethods::METHOD_CHECKOUT_COM;

    /**
     * @var string - url action
     */
    public $action = 'https://cdn.checkout.com/js/frames.js';

    public $showErrors = true;

    public $redirectProcessing = false;

    protected $scriptCode = 'checkout_com';

    /**
     * @var bool Allow downgrade to non-3ds payments
     */
    protected $non3dsPaymentAllowed = true;

    /**
     * @param Payments $payment
     * @return array|int[]|mixed
     * @throws ValidationException
     */
    public function checkouting($payment)
    {
        $paymentMethodOptions = $this->getPaymentMethod()['options'];
        $options = $payment->getUserDetails();

        $billingName = ArrayHelper::getValue($options, 'billing_name');
        $billingCity = ArrayHelper::getValue($options, 'billing_city');

        if (!isset($options['card-token'])) {
            return static::returnError();
        }

        try {
            $checkoutApi = new CheckoutApi($paymentMethodOptions['secret_key'], $paymentMethodOptions['test_mode']);
            $method = new TokenSource($options['card-token']);
            $threeDs = new ThreeDs(true);

            if ($this->non3dsPaymentAllowed) {
                $threeDs->attempt_n3d = true;
            }

            $customer = new Customer();
            $customer->email = $this->getUser()->email;
            $customer->name = empty($billingName) ? $this->getUser()->login : "{$billingName} ({$this->getUser()->login})";

            $checkoutPayment = new Payment($method, $this->getPaymentMethodCurrencyCode());
            $checkoutPayment->capture = true;
            $checkoutPayment->amount = (int)($this->getCheckoutAmount() * 100);
            $checkoutPayment->reference = (string)$payment->id;
            $checkoutPayment->threeDs = $threeDs;
            $checkoutPayment->customer = $customer;

            if (isset($billingName, $billingCity)) {
                $checkoutPayment->billing_descriptor = new BillingDescriptor($billingName, $billingCity);
            }

            $response = $checkoutApi->payments()->request($checkoutPayment);

            $this->dbLog($payment, (array)$response);

            $redirectionUrl = $response->getRedirection();

            return static::returnRedirect($redirectionUrl ? $redirectionUrl : $this->getReturnUrl());

        } catch (CheckoutHttpException $e) {
            $this->dbLog($payment, [
                'error' => 'Payments: bad request',
                'message' => $e->getMessage(),
                'exception' => $e->getTrace(),
            ], true);
        } catch (\Exception $e) {
            $this->dbLog($payment, [
                'error' => 'Payments: bad request',
                'message' => $e->getMessage(),
                'exception' => $e->getTrace(),
            ], true);
        }

        return static::returnError();
    }

    /**
     * @inheritdoc
     */
    public function getJsEnvironments()
    {
        $paymentMethodOptions = $this->getPaymentMethod()['options'];

        AssetsHelper::addCustomScriptFile($this->action);

        return [
            'code' => $this->scriptCode,
            'public_key' => ArrayHelper::getValue($paymentMethodOptions, 'public_key'),
            'modal' => [
                'modal_title' => Yii::t('app', 'addfunds.checkout_com.modal.title'),
                'submit_title' =>  Yii::t('app', 'addfunds.checkout_com.modal.submit'),
                'cancel_title' =>  Yii::t('app', 'addfunds.checkout_com.modal.cancel'),
            ],
        ];
    }

    /**
     * @inheritdoc
     */
    public function processing()
    {
        $payload = file_get_contents("php://input");
        $headers = Yii::$app->request->getHeaders();
        $signature = $headers->get('cko-signature');
        $auth = $headers->get('authorization');

        // заносим запись в таблицу payments_log
        $log = new PaymentsLog();
        $log->pid = '-1';
        $log->setResponse(is_string($payload) ? $payload : var_export($payload, true));
        $log->save(false);

        $this->fileLog(['payload' => $payload, 'signaure' => $signature]);

        $paymentMethodOptions = $this->getPaymentMethod()['options'];

        if ($auth != $paymentMethodOptions['private_shared_key']) {
            return [
                'result' => 2,
                'content' => 'unauthorized'
            ];
        }

        if (!$this->isValidSignature($payload, $signature, $paymentMethodOptions['secret_key'])) {
            return [
                'result' => 2,
                'content' => 'invalid signature'
            ];
        }

        $payload = json_decode($payload, true);

        if (json_last_error()) {
            return [
                'result' => 2,
                'content' => 'bad webhook json format'
            ];
        }

        if (!in_array($payload['type'], [
            self::CAPTURED_WEBHOOK_EVENT,
            self::APPROVED_WEBHOOK_EVENT,
            self::DECLINED_WEBHOOK_EVENT,
        ])) {
            return [
                'result' => 2,
                'content' => 'unexpected webhook event'
            ];
        }

        if (!isset(
            $payload['id'],
            $payload['type'],
            $payload['data'],
            $payload['data']['id'],
            $payload['data']['reference'],
            $payload['data']['amount'],
            $payload['data']['currency'],
            $payload['data']['response_code']
        )) {
            return [
                'result' => 2,
                'content' => 'missed data fields'
            ];
        }

        $this->getPaymentById($payload['data']['reference']);

        $this->dbLog($this->_payment, $payload);

        $this->_payment->response_status = $payload['data']['response_code'];
        $this->_payment->transaction_id = $payload['data']['id'];
        $this->_payment->status = Payments::STATUS_PENDING;

        if ($payload['type'] != self::CAPTURED_WEBHOOK_EVENT) {
            return [
                'result' => 2,
                'content' => 'no final status'
            ];
        }

        if ($payload['data']['response_code'] != self::RESPONSE_CODE_SUCCESS) {
            return [
                'result' => 2,
                'content' => 'bad response code',
            ];
        }

        $amount = $payload['data']['amount'] / 100;
        if ($amount < ($this->getProcessAmount() - 0.10)) {
            return [
                'result' => 2,
                'content' => 'bad amount'
            ];
        }

        if (strtolower($payload['data']['currency']) != strtolower($this->getPaymentMethodCurrencyCode())) {
            return [
                'result' => 2,
                'content' => 'bad currency'
            ];
        }

        return [
            'result' => 1,
            'transaction_id' => $this->_payment->transaction_id,
            'fee' => 0,
            'amount' => $this->_payment->amount,
            'payment_id' => $this->_payment->id,
            'content' => 'Ok'
        ];
    }

    /**
     * Check is signature is valid
     * @param $payload
     * @param $signature
     * @param $secretKey
     * @return bool
     */
    protected function isValidSignature($payload, $signature, $secretKey)
    {
        $expectedSignature = hash_hmac('sha256', $payload, $secretKey);

        return $expectedSignature === $signature;
    }
}