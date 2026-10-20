<?php

namespace MagizAI\Whmcs\Action\Services;

use MagizAI\Whmcs\ActionError;
use MagizAI\Whmcs\Action\ActionContext;
use MagizAI\Whmcs\Action\ActionInterface;
use MagizAI\Whmcs\Domain\Ownership;
use MagizAI\Whmcs\Domain\Upgrades;
use MagizAI\Whmcs\Support\Cycles;
use MagizAI\Whmcs\Support\Format;
use MagizAI\Whmcs\Support\Money;

/**
 * The extras the host sells alongside this service, at the prices WHMCS holds now.
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class AddonCatalog implements ActionInterface
{
    public function spec()
    {
        return array('identity' => true, 'write' => false, 'group' => 'read', 'label' => 'Extras that can be added to a service');
    }

    public function handle(ActionContext $context)
    {
        $args = $context->args();
        $clientId = $context->clientId();

        $p = Ownership::ownedService($clientId, $args, 'addon');
        $currency = Money::clientCurrency($clientId);
        $addons = Upgrades::addonsFor((int) $p['pid'], $currency);

        if (!$addons) {
            throw new ActionError('no_results', 'There are no extras on sale for ' . $p['name'] . '.');
        }

        $lines = array('Extras available for ' . $p['name'] . ($p['domain'] ? ' (' . $p['domain'] . ')' : '') . ':');
        foreach ($addons as $a) {
            $lines[] = '- ' . $a['name'] . ': ' . Money::money($a['price'], $currency) . ' ' . Cycles::cycleLabel($a['cycle'])
                . ((float) $a['setup'] > 0 ? ' plus ' . Money::money($a['setup'], $currency) . ' setup' : '')
                . ($a['description'] !== '' ? ' — ' . Format::cut($a['description'], 140) : '');
        }
        $lines[] = 'Ask which one they want, then add it. An invoice is raised and the extra is set up once it is paid.';

        return array('summary' => implode("\n", $lines));
    }
}
