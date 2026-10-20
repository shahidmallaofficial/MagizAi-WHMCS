<?php

namespace MagizAI\Whmcs\Action\Services;

use MagizAI\Whmcs\ActionError;
use MagizAI\Whmcs\Action\ActionContext;
use MagizAI\Whmcs\Action\ActionInterface;
use MagizAI\Whmcs\Domain\Invoices;
use MagizAI\Whmcs\Domain\OrderRules;
use MagizAI\Whmcs\Domain\Ownership;
use MagizAI\Whmcs\Domain\Upgrades;
use MagizAI\Whmcs\Support\Args;
use MagizAI\Whmcs\Support\Cycles;
use MagizAI\Whmcs\Support\Money;
use MagizAI\Whmcs\Support\WhmcsApi;
use MagizAI\Whmcs\Text;

/**
 * Add one extra to a service.
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class AddonOrder implements ActionInterface
{
    public function spec()
    {
        return array('identity' => true, 'write' => true, 'group' => 'sensitive', 'label' => 'Add an extra to a service (raises an invoice)');
    }

    public function handle(ActionContext $context)
    {
        $args = $context->args();
        $clientId = $context->clientId();

        $p = Ownership::ownedService($clientId, $args, 'addon');

        if ($p['status'] !== 'Active') {
            throw new ActionError('not_allowed', 'An extra can only be added to an active service. This one is ' . strtolower($p['status']) . '.');
        }

        $wanted = Args::text($args, 'addon', 120);
        if ($wanted === '') {
            throw new ActionError('invalid_args', 'Ask which extra they want, then add it by its exact name.');
        }

        $currency = Money::clientCurrency($clientId);
        $addon = Upgrades::matchOne(Upgrades::addonsFor((int) $p['pid'], $currency), $wanted, 'extra');

        $r = WhmcsApi::api('AddOrder', array(
            'clientid' => (int) $clientId,
            'paymentmethod' => OrderRules::orderPaymentMethod($clientId),
            'serviceid' => (int) $p['id'],
            'addonid' => (int) $addon['id'],
        ), false);

        if (($r['result'] ?? '') !== 'success' || empty($r['orderid'])) {
            $message = trim((string) ($r['message'] ?? ''));
            $said = Text::customerSafe($message, '');
            throw new ActionError('not_allowed', $said !== ''
                ? 'The extra could not be added: ' . $said
                : 'The extra could not be added. Offer to pass this to the team.',
                $message !== '' ? 'AddOrder: ' . $message : null);
        }

        $invoice = Invoices::raisedInvoice($clientId, isset($r['invoiceid']) ? (int) $r['invoiceid'] : 0, 'It is set up once that invoice is paid.');
        $summary = $addon['name'] . ' added to ' . $p['name'] . ' at ' . Money::money($addon['price'], $currency) . ' ' . Cycles::cycleLabel($addon['cycle']) . '. '
            . $invoice['text'];

        return array(
            'summary' => $summary,
            'audit' => 'addon ' . $addon['id'] . ' on service #' . $p['id'],
            'notify' => $addon['name'] . ' was added to ' . $p['name'] . ' through live chat. It is set up once the invoice is paid.',
            'data' => array('invoice_id' => $invoice['invoice_id'], 'pay_url' => $invoice['pay_url']),
        );
    }
}
