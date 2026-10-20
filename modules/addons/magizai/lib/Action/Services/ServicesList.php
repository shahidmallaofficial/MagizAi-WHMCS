<?php

namespace MagizAI\Whmcs\Action\Services;

use MagizAI\Whmcs\ActionError;
use MagizAI\Whmcs\Action\ActionContext;
use MagizAI\Whmcs\Action\ActionInterface;
use MagizAI\Whmcs\Domain\Ownership;
use MagizAI\Whmcs\Domain\Services;
use MagizAI\Whmcs\Summary;
use MagizAI\Whmcs\Support\Money;

/**
 * The client's live services, the one due soonest first, under a line with the counts.
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class ServicesList implements ActionInterface
{
    /** How many services the service list reads in full. The rest is counted. */
    const SERVICES_SHOWN = 15;

    public function spec()
    {
        return array('identity' => true, 'write' => false, 'group' => 'read', 'label' => 'List services');
    }

    public function handle(ActionContext $context)
    {
        $clientId = $context->clientId();

        $currency = Money::clientCurrency($clientId);

        /*
         * LIVE SERVICES FIRST, found in tblhosting, not paged out of the API, and counted.
         *
         * GetClientsProducts pages oldest first with no status filter, and this used to print every
         * row it returned. For a long-standing client (an IPTV customer on a fresh line each month)
         * that was dozens of terminated lines, the summary was cut at 1,500 characters on its way to
         * the model, and the one live service was the part that got cut. Ended services are counted,
         * not listed; the live ones are listed soonest due first, under a line with the totals, and
         * the list says how many more there are when it cannot show them all.
         */
        $services = Services::liveServices($clientId, self::SERVICES_SHOWN);
        $live = $services['live'];
        $liveTotal = $services['live_total'];

        if ($liveTotal === 0 && !$services['ended']) {
            return array('summary' => 'This client has no services.', 'data' => array('live' => 0, 'ended' => 0));
        }

        $items = array();
        foreach (array_slice($live, 0, self::SERVICES_SHOWN) as $serviceId) {
            try {
                $items[] = Services::serviceLine(Ownership::serviceById($clientId, $serviceId), $currency, true);
            } catch (ActionError $e) {
                continue;
            }
        }

        return array(
            'summary' => Summary::fit(array(Services::servicesCounts($services, true) . '.'), array(array('items' => $items, 'more' => $liveTotal - count($items)))),
            'data' => array(
                'live' => $liveTotal, 'ended' => $services['ended'], 'due_soon' => $services['due_soon'],
                'overdue' => $services['overdue'], 'suspended' => $services['suspended'], 'pending' => $services['pending'],
            ),
        );
    }
}
