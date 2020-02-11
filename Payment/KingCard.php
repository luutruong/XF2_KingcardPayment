<?php

namespace Truonglv\PaymentKingCard\Payment;

use XF\Mvc\Controller;
use XF\Purchasable\Purchase;
use XF\Entity\PaymentProfile;
use XF\Payment\CallbackState;
use XF\Entity\PurchaseRequest;
use XF\Payment\AbstractProvider;
use XF\Entity\PaymentProviderLog;

if (!class_exists('\Firebase\JWT\JWT')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}

class KingCard extends AbstractProvider
{
    const ALGO_HS256 = 'HS256';
    const TOKEN_EXPIRE = 60; // token expires in seconds

    /**
     * @return string
     */
    public function getTitle()
    {
        return 'kingcard.online';
    }

    /**
     * @return string
     */
    public function getApiEndpoint()
    {
        $enabled = (bool) \XF::config('enableLivePayments');
        if ($enabled) {
            return 'https://api.baokim.vn';
        }

        return 'https://sandbox-api.baokim.vn';
    }

    /**
     * @param PurchaseRequest $purchaseRequest
     * @param Purchase $purchase
     * @return array
     */
    protected function getPaymentParams(PurchaseRequest $purchaseRequest, Purchase $purchase)
    {
        return [
            'mrc_order_id' => $purchaseRequest->request_key,
            'telco' => '',
            'amount' => $purchase->cost,
            'code' => '',
            'serial' => '',
            'webhooks' => $this->getCallbackUrl()
        ];
    }

    /**
     * @return array
     */
    protected function getTelecomProviders()
    {
        return [
            'mobi' => \XF::phrase('tpk_provider_mobiphone'),
            'viettel' => \XF::phrase('tpk_provider_viettel'),
            'vina' => \XF::phrase('tpk_provider_vinaphone'),
        ];
    }

    /**
     * @param Controller $controller
     * @param PurchaseRequest $purchaseRequest
     * @param Purchase $purchase
     * @return \XF\Mvc\Reply\View
     */
    public function initiatePayment(Controller $controller, PurchaseRequest $purchaseRequest, Purchase $purchase)
    {
        return $controller->view(
            'Truonglv\PaymentKingCard:Form',
            'tpk_payment_kingcard_initiate',
            [
                'providers' => $this->getTelecomProviders(),
                'purchaseRequest' => $purchaseRequest,
                'purchase' => $purchase
            ]
        );
    }

    /**
     * @param Controller $controller
     * @param PurchaseRequest $purchaseRequest
     * @param PaymentProfile $paymentProfile
     * @param Purchase $purchase
     * @return \XF\Mvc\Reply\Error|\XF\Mvc\Reply\Message
     * @throws \XF\PrintableException
     */
    public function processPayment(
        Controller $controller,
        PurchaseRequest $purchaseRequest,
        PaymentProfile $paymentProfile,
        Purchase $purchase
    ) {
        $params = $this->getPaymentParams($purchaseRequest, $purchase);
        $input = $controller->request()->filter([
            'telecom' => 'str',
            'code' => 'str',
            'serial' => 'str'
        ]);

        $providers = $this->getTelecomProviders();
        if (!isset($providers[$input['telecom']])) {
            return $controller->error(\XF::phrase('tpk_kingcard_please_select_valid_telecom_provider'));
        }

        if ($input['code'] === ''
            || \preg_match('/[^0-9]+/', $input['code']) === 1
        ) {
            return $controller->error(\XF::phrase('tpk_kingcard_please_enter_valid_card_code'));
        }

        if ($input['serial'] === ''
            || \preg_match('/[^0-9]+/', $input['serial']) === 1
        ) {
            return $controller->error(\XF::phrase('tpk_kingcard_please_enter_valid_card_serial'));
        }

        $params = \array_replace($params, [
            'telco' => $input['telecom'],
            'code' => $input['code'],
            'serial' => $input['serial']
        ]);

        $client = $controller->app()->http()->client();
        $response = null;

        try {
            $response = $client->post($this->getApiEndpoint() . '/kingcard/api/v1/strike-card', [
                'query' => [
                    'jwt' => $this->getToken($paymentProfile, $params)
                ],
                'form_params' => $params,
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json'
                ]
            ]);
        } catch (\Exception $e) {
            \XF::logException($e, false, '[tl] Payment KingCard: ');
        }

        if ($response === null) {
            return $controller->error(\XF::phrase('tpk_kingcard_error_occurred_while_processing_payment'));
        }

        $json = \json_decode($response->getBody()->getContents(), true);

        /** @var PaymentProviderLog $log */
        $log = $controller->em()->create('XF:PaymentProviderLog');
        $log->purchase_request_key = $purchaseRequest->request_key;
        $log->provider_id = $this->providerId;
        $log->transaction_id = isset($json['data'], $json['data']['order_id'])
            ? $json['data']['order_id']
            : '';

        $log->subscriber_id = '';
        $log->log_type = 'info';
        $log->log_message = 'Submit API response.';
        $log->log_details = [
            'responseData' => $json,
            'responseCode' => $response->getStatusCode(),
            'requestData' => $params
        ];

        $log->save();

        if ($response->getStatusCode() === 200 && isset($json['code']) && $json['code'] == 0) {
            return $controller->message(\XF::phrase('tpk_kingcard_your_payment_under_processing_please_wait'));
        }

        return $controller->error(\XF::phrase('tpk_kingcard_error_occurred_while_processing_payment'));
    }

    /**
     * @param PaymentProfile $paymentProfile
     * @param mixed $unit
     * @param mixed $amount
     * @param mixed $result
     * @return bool
     */
    public function supportsRecurring(PaymentProfile $paymentProfile, $unit, $amount, &$result = self::ERR_NO_RECURRING)
    {
        return false;
    }

    /**
     * @param \XF\Http\Request $request
     * @return CallbackState
     */
    public function setupCallback(\XF\Http\Request $request)
    {
        $inputRaw = $request->getInputRaw();
        $json = (array) \json_decode($inputRaw, true);

        $filtered = $request->getInputFilterer()->filterArray($json, [
            'order' => 'array',
            'txn' => 'array',
            'sign' => 'str'
        ]);

        $state =  new CallbackState();

        if (isset($filtered['order']['mrc_order_id'])) {
            $state->requestKey = $filtered['order']['mrc_order_id'];
        }

        if (isset($filtered['order']['txn_id'])) {
            $state->transactionId = $filtered['order']['txn_id'];
        }

        $state->signature = $filtered['sign'];
        $state->inputFiltered = $filtered;
        $state->inputRaw = $inputRaw;

        $state->ip = $request->getIp();
        $state->_POST = $json;

        return $state;
    }

    /**
     * @param CallbackState $state
     * @return bool
     */
    protected function validateExpectedValues(CallbackState $state)
    {
        return ($state->getPurchaseRequest() && $state->getPaymentProfile());
    }

    /**
     * @param CallbackState $state
     * @return bool
     */
    public function validateCallback(CallbackState $state)
    {
        if (!$this->validateExpectedValues($state)) {
            $state->logType = 'error';
            $state->logMessage = 'Data received from BaoKim does not contain the expected values.';

            if (!$state->requestKey) {
                $state->httpCode = 200;
            }

            return false;
        }

        $client = \XF::app()->http()->client();
        $inputFiltered = $state->inputFiltered;

        try {
            $response = $client->get($this->getApiEndpoint() . '/payment/api/v4/order/detail', [
                'query' => [
                    'id' => $inputFiltered['order']['id'],
                    'mrc_order_id' => $state->requestKey,
                    'jwt' => $this->getToken($state->paymentProfile, [])
                ]
            ]);
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            $state->logType = 'error';
            $state->logMessage = $e->getMessage();
            $state->httpCode = 400;

            return false;
        }

        if ($response->getStatusCode() !== 200) {
            $state->logType = 'error';
            $state->logMessage = $response->getReasonPhrase();

            $state->httpCode = 400;

            return false;
        }

        $data = \json_decode(\strval($response->getBody()), true);
        $order = isset($data['data']) ? $data['data'] : [];

        $mrcOrderId = isset($order['mrc_order_id']) ? $order['mrc_order_id'] : null;
        if (!$mrcOrderId || $mrcOrderId !== $state->requestKey) {
            return false;
        }

        $inputFiltered = \array_replace_recursive($inputFiltered, [
            'order' => $order
        ]);
        $state->inputFiltered = $inputFiltered;

        return true;
    }

    /**
     * @param CallbackState $state
     * @return bool
     */
    public function validateCost(CallbackState $state)
    {
        $totalAmount = $state->inputFiltered['order']['total_amount'];
        $taxFee = $state->inputFiltered['order']['tax_fee'];

        $cost = \round($state->purchaseRequest->cost_amount, 2);
        $totalPaid = \round($totalAmount - $taxFee, 2);

        if ($cost !== $totalPaid) {
            $state->logType = 'error';
            $state->logMessage = 'Invalid cost amount';

            return false;
        }

        return true;
    }

    /**
     * @param CallbackState $state
     * @return void
     */
    public function getPaymentResult(CallbackState $state)
    {
        $result = $state->inputFiltered['order']['stat'];
        if ($result === 'c') {
            $state->paymentResult = CallbackState::PAYMENT_RECEIVED;
        }
    }

    /**
     * @param CallbackState $state
     * @return void
     */
    public function prepareLogData(CallbackState $state)
    {
        $state->logDetails = $state->_POST + [
                'raw' => $state->inputRaw
            ];
    }

    /**
     * @param CallbackState $state
     * @return void
     */
    public function completeTransaction(CallbackState $state)
    {
        parent::completeTransaction($state);

        if ($state->paymentResult === CallbackState::PAYMENT_RECEIVED
            || $state->paymentResult === CallbackState::PAYMENT_REINSTATED
        ) {
            $state->logMessage = \json_encode([
                'err_code' => 0,
                'message' => 'ok'
            ]);
        }
    }

    /**
     * @param PaymentProfile $paymentProfile
     * @param array $formData
     * @return string
     */
    private function getToken(PaymentProfile $paymentProfile, array $formData)
    {
        $tokenId = \base64_encode(\XF::generateRandomString(32, true));
        $issueAt = \XF::$time;
        $expiresAt = \XF::$time + self::TOKEN_EXPIRE;

        $payload = [
            'iat' => $issueAt,
            'jti' => $tokenId,
            'iss' => $paymentProfile->options['api_key'],
            'nbf' => \XF::$time,
            'exp' => $expiresAt,
            'form_params' => $formData
        ];

        return \Firebase\JWT\JWT::encode(
            $payload,
            $paymentProfile->options['api_secret'],
            self::ALGO_HS256
        );
    }
}
