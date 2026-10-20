<?php

namespace MagizAI\Whmcs\Action\Services;

use MagizAI\Whmcs\ActionError;
use MagizAI\Whmcs\Action\ActionContext;
use MagizAI\Whmcs\Action\ActionInterface;
use MagizAI\Whmcs\Domain\Invoices;
use MagizAI\Whmcs\Domain\OrderRules;
use MagizAI\Whmcs\Domain\Ownership;
use MagizAI\Whmcs\Domain\Pricing;
use MagizAI\Whmcs\Domain\Renewals;
use MagizAI\Whmcs\Support\Money;
use MagizAI\Whmcs\Support\WhmcsApi;
use MagizAI\Whmcs\Text;

/**
 * Renew a service before it is due. WHMCS raises the invoice; paying it moves the due date.
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class ServiceRenew implements ActionInterface
{
    public function spec()
    {
        return array('identity' => true, 'write' => true, 'group' => 'sensitive', 'label' => 'Renew a service early (raises an invoice)');
    }

    public function handle(ActionContext $context)
    {
        $args = $context->args();
        $clientId = $context->clientId();

        // "Renew" with a paid line and a free trial on the account means the paid line. @see Ownership::ownedService
        $p = Ownership::ownedService($clientId, $args, 'renew');
        $label = $p['name'] . ($p['domain'] ? ' (' . $p['domain'] . ')' : '');

        $check = Renewals::renewability($clientId, $p);

        /*
         * AN INVOICE ALREADY COVERS IT. That invoice is the renewal, so it is the answer, and nothing
         * new is ordered. Given back as a result with its pay link, not as a refusal: a refusal read as
         * a dead end, and MagizAI added "or order the same plan again" to it, which for a recurring
         * service is a second subscription. The same for a user who may not see which invoice it is.
         */
        if ($check['covered_by'] !== null) {
            return self::existingRenewal($p, $label, $check);
        }

        if (!$check['renewable']) {
            throw new ActionError('not_allowed', $check['reason']);
        }

        $price = Pricing::serviceRenewalPrice($p, Money::clientCurrency($clientId));
        OrderRules::mustMatchQuote($args, $price ? $price['amount'] : null, $price ? $price['price'] : '');

        $r = WhmcsApi::api('AddOrder', array(
            'clientid' => (int) $clientId,
            'paymentmethod' => OrderRules::orderPaymentMethod($clientId),
            'servicerenewals' => array((int) $p['id']),
        ), false);

        /*
         * WHMCS SAID NO TO THE RENEWAL ITSELF. Some of its reasons cannot be read from here, so the
         * refusal is recorded against this service and due date (the bridge writes the detail to the
         * activity log), and service_details then reports it as not renewable: no second code is
         * offered for a renewal WHMCS has just refused. @see Renewals::renewability
         */
        if (($r['result'] ?? '') !== 'success' || empty($r['orderid'])) {
            $message = trim((string) ($r['message'] ?? ''));
            if (Renewals::isRenewalRefusal($message)) {
                throw new ActionError('not_allowed', 'WHMCS did not accept an early renewal of ' . $label . ' just now, so nothing was ordered. '
                    . Renewals::renewsThroughInvoice($p),
                    Renewals::renewalRefusedMarker($p) . ': AddOrder: ' . Text::cut(strip_tags($message), 300));
            }
        }

        return Renewals::renewalResult($clientId, $r, $label, 'service #' . $p['id'], $price ? $price['price'] : '');
    }

    /**
     * service_renew when an open invoice already covers the service: what that invoice means, and
     * nothing ordered. `ordered: false` says so to MagizAI in so many words, and `changed: false`
     * to the bridge, which then does not count it as an account change. @see Registry::changedAnything
     */
    private static function existingRenewal(array $p, $label, array $check)
    {
        $id = (int) $check['unpaid_invoice'];
        $status = (string) $check['invoice_status'];
        $covering = $check['covered_by'];

        if ($id === 0) {
            // A user who may not see invoices: that one is raised, never which. @see Renewals::renewability
            $summary = 'Nothing new was ordered. ' . Invoices::invoiceNotShown('An invoice for ' . $label);
        } elseif ($status === 'Payment Pending') {
            $summary = 'Nothing new was ordered: the payment for invoice #' . $id . ', which covers ' . $label
                . ', was received and is still clearing. It must not be paid again. The renewal applies once the payment clears.';
        } elseif ($status === 'Collections') {
            $summary = 'Nothing new was ordered: invoice #' . $id . ', which covers ' . $label
                . ', is with collections. Offer to pass this to the billing team.';
        } else {
            $summary = 'Nothing new was ordered: invoice #' . $id . ' already covers ' . $label
                . ' and is waiting to be paid. Paying it is what keeps the service running: ' . Invoices::invoiceUrl($id);
        }

        return array(
            'summary' => $summary,
            // The activity log is the host's own: it names the invoice whoever asked.
            'audit' => 'service #' . (int) $p['id'] . ': invoice #' . (int) $covering['id'] . ' (' . $covering['status'] . ') already covers it, nothing ordered',
            'changed' => false,
            'data' => array(
                'ordered' => false,
                'invoice_id' => $id,
                'invoice_status' => $status,
                'pay_url' => $check['pay_url'],
            ),
        );
    }
}
