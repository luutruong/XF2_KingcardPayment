<?php

namespace Truonglv\PaymentKingCard\Payment;

use XF\Mvc\Controller;
use XF\Purchasable\Purchase;
use XF\Entity\PaymentProfile;
use XF\Payment\CallbackState;
use XF\Entity\PurchaseRequest;
use XF\Payment\AbstractProvider;
use XF\Entity\PaymentProviderLog;

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
        return 'http://summocard.net';
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
            'amount' => \intval($purchase->cost),
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
            'MOBI' => \XF::phrase('tpk_provider_mobiphone'),
            'VIETTEL' => \XF::phrase('tpk_provider_viettel'),
            'VINA' => \XF::phrase('tpk_provider_vinaphone'),
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
     * @return \XF\Mvc\Reply\AbstractReply
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
            $response = $client->post($this->getApiEndpoint() . '/s-card/api/v1/strike-card', [
                'query' => [
                    'jwt' => $this->getToken($paymentProfile, $params)
                ],
                'form_params' => $params,
                'headers' => [
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
        $log->transaction_id = isset($json['data'], $json['data']['id'])
            ? $json['data']['id']
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

        if ($response->getStatusCode() === 200
            && isset($json['code']) && $json['code'] == 0
            && $log->transaction_id !== ''
        ) {
            return $controller->redirect($controller->buildLink('account/tpk-thanks'));
        }

        if ($json['code'] == 6) {
            // duplicate order record error
            return $controller->error(\XF::phrase('tpk_kingcard_error_duplicate_order_try_again'));
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
            $state->logMessage = 'Data received from BaoKim/KingCard does not contain the expected values.';

            if (!$state->requestKey) {
                $state->httpCode = 200;
            }

            return false;
        }

        $inputRaw = $state->inputRaw;
        $json = \json_decode($inputRaw, true);

        $sign = $json['sign'];
        unset($json['sign']);

        $computed = \hash_hmac('sha256', \strval(\json_encode($json)), $state->paymentProfile->options['api_secret']);
        if ($sign !== $computed) {
            $state->logType = 'error';
            $state->logMessage = 'Invalid signature!';

            return false;
        }

        return true;
    }

    /**
     * @param CallbackState $state
     * @return bool
     */
    public function validateCost(CallbackState $state)
    {
        $cost = \round($state->purchaseRequest->cost_amount, 2);

        $amount = isset($state->inputFiltered['txn']['amount']) ? $state->inputFiltered['txn']['amount'] : 0;
        $feeAmount = isset($state->inputFiltered['txn']['fee_amount']) ? $state->inputFiltered['txn']['fee_amount'] : 0;
        $totalPaid = \round($amount + $feeAmount, 2);

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
        if ($result === 2) {
            $state->paymentResult = CallbackState::PAYMENT_RECEIVED;
        }
    }

    /**
     * @param CallbackState $state
     * @return void
     */
    public function prepareLogData(CallbackState $state)
    {
        $state->logDetails = \array_merge($state->_POST, [
            'raw' => $state->inputRaw
        ]);
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
