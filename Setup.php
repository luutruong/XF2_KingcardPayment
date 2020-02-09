<?php

namespace Truonglv\PaymentKingCard;

use XF\AddOn\AbstractSetup;
use XF\Entity\PaymentProvider;
use XF\AddOn\StepRunnerInstallTrait;
use XF\AddOn\StepRunnerUpgradeTrait;
use XF\AddOn\StepRunnerUninstallTrait;

class Setup extends AbstractSetup
{
    use StepRunnerInstallTrait;
    use StepRunnerUpgradeTrait;
    use StepRunnerUninstallTrait;

    public function installStep1()
    {
        /** @var PaymentProvider $provider */
        $provider = $this->app()->em()->create('XF:PaymentProvider');
        $provider->provider_id = 'tpk_kingcard';
        $provider->provider_class = 'Truonglv\PaymentKingCard:KingCard';
        $provider->addon_id = 'Truonglv/PaymentKingCard';
        $provider->save();
    }

    public function uninstallStep1()
    {
        $this->db()
            ->delete('xf_payment_provider', 'addon_id = ?', 'Truonglv/PaymentKingCard');
    }
}
