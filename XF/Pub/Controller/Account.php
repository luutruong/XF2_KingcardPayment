<?php

namespace Truonglv\PaymentKingCard\XF\Pub\Controller;

class Account extends XFCP_Account
{
    public function actionTPKThanks()
    {
        return $this->message(\XF::phrase('tpk_kingcard_thanks_you_for_purchasing_your_payment_under_process'));
    }
}
