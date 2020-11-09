<?php
namespace payments\methods;

use app\helpers\AssetsHelper;
use app\models\panel\Payments;
use app\models\panels\PaymentMethods;
use Razorpay\Api\Api;
use payments\BasePayment;
use yii\base\Security;
use yii\helpers\ArrayHelper;


/**
 * Class Razorpay
 * @package payments\methods
 */
class Razorpay extends BasePayment {

    /**
     * @var int
     */
    protected $_method_id = PaymentMethods::METHOD_RAZORPAY;


    /**
     * @inheritdoc
     * @throws
     */
    public function checkouting($payment)
    {
        $paymentMethodOptions = $this->getPaymentMethod()['options'];
        $options = $payment->getUserDetails();

        $amount = $this->getCheckoutAmountCourse();
        $payment->amount_course = $amount;
        $payment->save(false);

        $api = new Api(
            ArrayHelper::getValue($paymentMethodOptions, 'api_key'),
            ArrayHelper::getValue($paymentMethodOptions, 'secret_key')
        );

        $currency = $this->getPaymentMethodCurrencyCode();

        if (!ArrayHelper::getValue($options, 'razorpay_signature')) {
            try {
                $security = new Security();
                $orderData = [
                    'receipt' => $security->generateRandomString(),
                    'amount' => $amount * 100,
                    'currency' => $currency,
                    'payment_capture' => 1
                ];

                $razorpayOrder = $api->order->create($orderData);
                $razorpayOrderId = $razorpayOrder['id'];
                $data = $payment->getUserDetails();
                $data['razorpay_order_id'] = $razorpayOrderId;
                $payment->setUserDetails($data);
                $payment->transaction_id = $orderData['receipt'] . $payment->id;
                $payment->save(false);

                $this->dbLog($payment, [
                    'action' => 'Create order',
                    'payment_details' => $data,
                    'order_data' => $orderData,
                    'razor_order' => (array)$razorpayOrder,
                ], true);

                $this->fileLog($razorpayOrder);

                $data = [
                    'options' => [
                        "key" => ArrayHelper::getValue($paymentMethodOptions, 'api_key'),
                        "amount" => $amount * 100,
                        "name" => $this->getUser()->login,
                        "description" => $this->getDescription(),
                        "order_id" => $razorpayOrderId,
                        'merchant_order_id' => $orderData['receipt'],
                        "theme" => [
                            "color" => "#F37254"
                        ],
                    ],
                    'transactionId' => $payment->transaction_id,
                    "currency" => $currency,
                ];

                $this->dbLog($payment, [
                    'action' => 'Configure checkout',
                    'data' => $data,
                ], true);

                return $this->returnSuccess($data);
            } catch (\Exception $exception) {

                $this->dbLog($payment, [
                    'error' => 'Checkout: bad request',
                    'message' => $exception->getMessage(),
                ], true);

                return $this->returnSuccess(['message' => $exception->getMessage()]);
            }
        }


        try {
            $attributes = [
                'razorpay_order_id' => ArrayHelper::getValue($options, 'razorpay_order_id'),
                'razorpay_payment_id' => ArrayHelper::getValue($options, 'razorpay_payment_id'),
                'razorpay_signature' => ArrayHelper::getValue($options, 'razorpay_signature')
            ];

            $api->utility->verifyPaymentSignature($attributes);


            $payment->response_status = 'success';
            $payment->status = Payments::STATUS_PENDING;
            $payment->save(false);

            $result = [
                'result' => 1,
                'transaction_id' => $payment->transaction_id,
                'fee' => 0,
                'amount' => $payment->amount,
                'payment_id' => $payment->id,
                'content' => 'Ok'
            ];
            $this->fileLog($result);
            $this->success($result);
        }  catch (\Exception $e) {
            $this->dbLog($payment, [
                'error' => 'CardTransaction fail',
                'message' => $e->getMessage(),
                'exception' => $e->getTrace(),
            ], true);

            $payment->response_status = 'fail';
            $payment->status = Payments::STATUS_FAIL;
            $payment->save(false);

            return static::returnError();
        }

        return static::returnRedirect($this->getReturnUrl());
    }

    /**
     * @inheritdoc
     */
    public function getJsEnvironments()
    {

        AssetsHelper::addCustomScriptFile([
            'src' =>  'https://checkout.razorpay.com/v1/checkout.js',
        ]);

        return [
            'code' => 'razorpay',
            'type' => $this->_method_id
        ];
    }

    /**
     * @inheritdoc
     */
    public function processing()
    {
    }
}