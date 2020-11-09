<?php
namespace payments\methods;

use app\models\panels\PaymentMethods;
use payments\BasePayment;
use app\helpers\SiteHelper;
use app\models\panel\Payments;
use yii\helpers\ArrayHelper;

/**
 * Class Perfectmoney
 * @package payments\methods
 */
class Perfectmoney extends BasePayment {

    protected $_method_id = [
        PaymentMethods::METHOD_PERFECT_MONEY_USD,
        PaymentMethods::METHOD_PERFECT_MONEY_EUR
    ];

    /**
     * @var string - url action
     */
    public $action = 'https://perfectmoney.is/api/step1.asp';

    /**
     * @inheritdoc
     */
    public function checkouting($payment)
    {
        $paymentMethodOptions = $this->getPaymentMethod()['options'];

        return static::returnForm($this->getFrom(), [
            'PAYEE_ACCOUNT' => ArrayHelper::getValue($paymentMethodOptions, 'account'),
            'PAYEE_NAME' => SiteHelper::host(),
            'PAYMENT_ID' => $payment->id,
            'PAYMENT_UNITS' => $this->getPaymentMethodCurrencyCode(),
            'STATUS_URL' => $this->getNotifyUrl(),
            'PAYMENT_URL' => $this->getReturnUrl(),
            'PAYMENT_URL_METHOD' => $this->method,
            'NOPAYMENT_URL' => $this->getReturnUrl(),
            'NOPAYMENT_URL_METHOD' => $this->method,
            'SUGGESTED_MEMO' => $this->getDescription(),
            'BAGGAGE_FIELDS' => '',
            'PAYMENT_AMOUNT' => $this->getCheckoutAmount(),
        ]);
    }

    /**
     * @inheritdoc
     */
    public function processing()
    {
        $paymentId = ArrayHelper::getValue($_POST, 'PAYMENT_ID');
        $payeeAccount = ArrayHelper::getValue($_POST, 'PAYEE_ACCOUNT');
        $paymentUnits = ArrayHelper::getValue($_POST, 'PAYMENT_UNITS');
        $paymentBatchNum = ArrayHelper::getValue($_POST, 'PAYMENT_BATCH_NUM');
        $tamestampGmp = ArrayHelper::getValue($_POST, 'TIMESTAMPGMT');
        $v2Hash = ArrayHelper::getValue($_POST, 'V2_HASH');
        $payerAccount = ArrayHelper::getValue($_POST, 'PAYER_ACCOUNT');
        $paymentAmount = ArrayHelper::getValue($_POST, 'PAYMENT_AMOUNT');

        if (empty($paymentId) || empty($payeeAccount) || empty($paymentUnits) || empty($paymentBatchNum)
            || empty($tamestampGmp) || empty($v2Hash) || empty($payerAccount) || empty($paymentAmount)) {
            return [
                'result' => 2,
                'content' => 'no data'
            ];
        }

        $this->getPaymentById($paymentId);

        // заносим запись в таблицу payments_log
        $this->dbLog($this->_payment, array_merge($_POST, $_POST));

        $paymentMethodOptions = $this->getPaymentMethod()['options'];

        $string = implode(":", [
            $paymentId,
            $payeeAccount,
            $paymentAmount,
            $paymentUnits,
            $paymentBatchNum,
            $payerAccount,
            strtoupper(md5(ArrayHelper::getValue($paymentMethodOptions, 'passphrase', ''))),
            $tamestampGmp,
        ]);

        $signature = strtoupper(md5($string));

        if ($signature != $v2Hash) {
            return [
                'result' => 2,
                'content' => 'bad signature'
            ];
        }

        $this->_payment->transaction_id = $payerAccount;
        $this->_payment->status = Payments::STATUS_PENDING;

        if (strtolower($this->getPaymentMethodCurrencyCode()) != strtolower($paymentUnits)) {
            return [
                'result' => 2,
                'content' => 'bad currency'
            ];
        }

        if ($this->getProcessAmount() != $paymentAmount) {
            return [
                'result' => 2,
                'content' => 'bad amount'
            ];
        }

        return [
            'result' => 1,
            'transaction_id' => $payerAccount,
            'fee' => 0,
            'amount' => $this->_payment->amount,
            'payment_id' => $this->_payment->id,
            'content' => 'Ok'
        ];
    }
}