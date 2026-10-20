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
 * Move a service to another plan. WHMCS raises the invoice; the change applies when it is paid.
 *
 * Deliberately allows a DOWNGRADE too, because refusing one only sends an unhappy client to
 * the cancellation form. The confirmation MagizAI shows says which way it is going.
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class ServiceUpgrade implements ActionInterface
{
    public function spec()
    {
        return array('identity' => true, 'write' => true, 'group' => 'sensitive', 'label' => 'Upgrade or downgrade a service (raises an invoice)');
    }

    public function handle(ActionContext $context)
    {
        $args = $context->args();
        $clientId = $context->clientId();

        $p = Ownership::ownedService($clientId, $args, 'upgrade');

        if (!in_array($p['status'], array('Active', 'Suspended'), true)) {
            throw new ActionError('not_allowed', 'Only an active service can be changed. This one is ' . strtolower($p['status']) . '.');
        }

        Upgrades::mustBeUpgradeable($p);

        $wanted = Args::text($args, 'product', 120);
        if ($wanted === '') {
            throw new ActionError('invalid_args', 'Ask which plan they want to move to, then use its exact name.');
        }

        $currency = Money::clientCurrency($clientId);
        $target = Upgrades::matchOne(Upgrades::upgradeTargets($p, $currency), $wanted, 'plan');
        $cycle = (string) $p['billingcycle'];

        // One gateway for the price check and for the order itself. @see Upgrades::upgradeQuote
        $gateway = OrderRules::orderPaymentMethod($clientId);

        // Priced again here, immediately before ordering it, so the invoice can never disagree with
        // the figure the client just agreed to.
        $error = '';
        $quote = Upgrades::upgradeQuote((int) $p['id'], (int) $target['pid'], $cycle, $currency, $gateway, $error);
        if ($quote === null) {
            $said = Text::customerSafe($error, '');
            throw new ActionError('not_allowed', 'That change could not be priced' . ($said !== '' ? ': ' . $said : '')
                . ', so it has not been ordered. Offer to pass this to the team.',
                $error !== '' ? 'UpgradeProduct: ' . Text::cut(strip_tags($error), 300) : null);
        }

        $r = WhmcsApi::api('UpgradeProduct', array(
            'serviceid' => (int) $p['id'],
            'type' => 'product',
            'newproductid' => (int) $target['pid'],
            // WHMCS's own key ("semiannually"), as its documentation sends it — not the label the
            // service row shows ("Semi-Annually"). upgradeTargets() only offers cycles that have one.
            'newproductbillingcycle' => Cycles::cycleKey($cycle),
            'paymentmethod' => $gateway,
        ), false);

        if (($r['result'] ?? '') !== 'success') {
            $message = trim((string) ($r['message'] ?? ''));
            $said = Text::customerSafe($message, '');
            throw new ActionError('not_allowed', $said !== ''
                ? 'The change could not be made: ' . $said
                : 'The change could not be made. Offer to pass this to the team.',
                $message !== '' ? 'UpgradeProduct: ' . $message : null);
        }

        $invoice = Invoices::raisedInvoice($clientId, isset($r['invoiceid']) ? (int) $r['invoiceid'] : 0, 'The change applies once that invoice is paid.');
        $direction = (float) $target['recurring'] >= (float) $p['recurringamount'] ? 'Upgrade' : 'Downgrade';

        $summary = $direction . ' ordered: ' . $p['name'] . ' becomes ' . $target['name']
            . '. ' . Upgrades::quoteText($quote, $currency) . ', then ' . Money::money($target['recurring'], $currency) . ' ' . strtolower($cycle) . '.'
            . ' ' . $invoice['text'];

        return array(
            'summary' => $summary,
            'audit' => 'service #' . $p['id'] . ' -> pid ' . $target['pid'],
            'notify' => $direction . ' ordered for ' . $p['name'] . ' to ' . $target['name'] . ' through live chat. It applies once the invoice is paid.',
            'data' => array('invoice_id' => $invoice['invoice_id'], 'pay_url' => $invoice['pay_url']),
        );
    }
}
