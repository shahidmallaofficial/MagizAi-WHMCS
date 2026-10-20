<?php

namespace MagizAI\Whmcs\Action\Billing;

use MagizAI\Whmcs\ActionError;
use MagizAI\Whmcs\Action\ActionContext;
use MagizAI\Whmcs\Action\ActionInterface;
use MagizAI\Whmcs\Domain\Invoices;
use MagizAI\Whmcs\Domain\Ownership;
use MagizAI\Whmcs\Domain\Payments;
use MagizAI\Whmcs\Domain\Statuses;
use MagizAI\Whmcs\Summary;
use MagizAI\Whmcs\Support\Args;
use MagizAI\Whmcs\Support\Dates;
use MagizAI\Whmcs\Support\Money;
use MagizAI\Whmcs\Support\WhmcsApi;
use WHMCS\Database\Capsule;

/**
 * "I paid, so why does it still say unpaid (or suspended)?"
 *
 * One invoice of this client's: its status and balance as WHMCS holds them, every payment
 * recorded against it, and what each service or domain it bills is doing now, with the step that
 * helps. Asked for by the number the client sees or by its id; with neither, the newest open
 * invoice, or the newest paid one when nothing is open.
 *
 * WHAT NEVER LEAVES WHMCS. Card details of any kind; a payment's own description, which gateways
 * fill with card types and endings; and the whole transaction reference: only its end, at most
 * the last six characters, enough to match a bank statement line and for staff to find the
 * payment. @see Payments::referenceEnd
 *
 * The services and domains an invoice pays for are shown only to a user who may see services or
 * domains: "invoices" lets a team member see the invoice, not the state of everything on it.
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class PaymentStatus implements ActionInterface
{
    /** How many payments payment_status lists: the latest ones. The rest are counted. */
    const PAYMENTS_LISTED = 5;

    public function spec()
    {
        // 1.6.0: "I paid, why does it still say unpaid?", "my order is still pending", "when does
        // it renew?" and "I never got the email".
        return array('identity' => true, 'write' => false, 'group' => 'read', 'label' => 'Payment status of an invoice: payments recorded and what they paid for');
    }

    public function handle(ActionContext $context)
    {
        $args = $context->args();
        $clientId = $context->clientId();

        $currency = Money::clientCurrency($clientId);

        if (isset($args['invoice_id']) && is_scalar($args['invoice_id']) && trim((string) $args['invoice_id']) !== '') {
            $invoice = Ownership::ownedInvoice($clientId, Args::digits($args, 'invoice_id'));
        } else {
            $invoice = Ownership::latestInvoice($clientId);
            if ($invoice === null) {
                return array('summary' => 'This client has no invoices.', 'data' => array('invoice_id' => 0));
            }
        }

        $i = WhmcsApi::api('GetInvoice', array('invoiceid' => $invoice['id']), false);
        if (($i['result'] ?? '') !== 'success' || (int) ($i['userid'] ?? 0) !== (int) $clientId) {
            throw new ActionError('not_owner', 'No invoice with that number was found on this account.');
        }

        $status = (string) $i['status'];
        $number = (string) ($i['invoicenum'] ?? '') !== '' ? (string) $i['invoicenum'] : (string) $invoice['id'];
        $payUrl = $status === 'Unpaid' ? Invoices::invoiceUrl($invoice['id']) : '';

        // Payments recorded against THIS invoice, for THIS client, oldest first.
        $t = WhmcsApi::api('GetTransactions', array('invoiceid' => (int) $invoice['id'], 'clientid' => (int) $clientId), false);
        $payments = array();
        foreach (WhmcsApi::rows($t, 'transactions', 'transaction') as $row) {
            if ((int) ($row['invoiceid'] ?? 0) !== (int) $invoice['id']
                || (isset($row['userid']) && (string) $row['userid'] !== '' && (int) $row['userid'] !== (int) $clientId)) {
                continue;
            }
            $payments[] = $row;
        }
        usort($payments, function ($a, $b) {
            return strcmp((string) ($a['date'] ?? ''), (string) ($b['date'] ?? ''));
        });

        $paidIn = 0.0;
        $paymentLines = array();
        $paymentData = array();
        foreach ($payments as $row) {
            $in = (float) ($row['amountin'] ?? 0);
            $out = (float) ($row['amountout'] ?? 0);
            $refund = $out > 0 && $in <= 0;
            $paidIn += $in - $out;

            $method = Payments::gatewayName(isset($row['gateway']) ? $row['gateway'] : '');
            $ref = Payments::referenceEnd(isset($row['transid']) ? $row['transid'] : '');
            $paymentLines[] = '- ' . Dates::date($row['date'] ?? '') . ': ' . Money::money($refund ? $out : $in, $currency)
                . ($refund ? ' refunded through ' : ' paid by ') . $method
                . ($ref !== '' ? ', reference ending ' . $ref : '');
            $paymentData[] = array(
                'date' => Dates::timestamp($row['date'] ?? '') ? date('Y-m-d', Dates::timestamp($row['date'] ?? '')) : '',
                'amount' => number_format($refund ? $out : $in, 2, '.', ''),
                'refund' => $refund,
                'method' => $method,
                'reference_end' => $ref,
            );
        }

        $before = array(
            'Invoice #' . $number . ': ' . $status . ', total ' . Money::money($i['total'] ?? 0, $currency)
                . ', balance ' . Money::money($i['balance'] ?? 0, $currency)
                // Only an unpaid invoice can be overdue: a paid one past its date is just paid.
                . ', dated ' . Dates::date($i['date'] ?? '') . ', due ' . ($status === 'Unpaid' ? Dates::dueLabel($i['duedate'] ?? '') : Dates::date($i['duedate'] ?? '')) . '.',
            Payments::paymentExplanation($status, $i, $paidIn, $payUrl, $currency, count($paymentLines)),
        );

        /*
         * The latest payments, counted in full. Forty part payments used to fill the answer, and the
         * state of what the invoice pays for, which is what "why is it still suspended?" asks about,
         * was left with no line at all.
         */
        $listed = array_slice($paymentLines, -self::PAYMENTS_LISTED);
        $sections = array(
            $paymentLines
                ? array(
                    'head' => 'Payments recorded (' . count($paymentLines) . ')'
                        . (count($paymentLines) > count($listed) ? ', the latest ' . count($listed) : '') . ':',
                    'items' => $listed,
                    'more' => count($paymentLines) - count($listed),
                )
                : array('head' => 'Payments recorded: none.'),
        );

        // What it pays for, and what each of those is doing now: every one counted, none dropped unsaid.
        $covers = Payments::invoiceCovers($clientId, $invoice['id'], $status);
        if ($covers['total'] > 0) {
            $sections[] = array('head' => 'It pays for:', 'items' => $covers['lines'], 'more' => $covers['total'] - count($covers['lines']));
        }

        // Other open invoices, by number only: the client may be asking about a different one.
        $after = array();
        $others = array();
        foreach (Capsule::table('tblinvoices')->where('userid', (int) $clientId)->whereIn('status', Statuses::OPEN_INVOICE_STATUSES)
            ->where('id', '!=', (int) $invoice['id'])->orderBy('id', 'desc')->limit(3)->get(array('id', 'invoicenum')) as $other) {
            $others[] = '#' . ((string) $other->invoicenum !== '' ? $other->invoicenum : $other->id);
        }
        $otherCount = (int) Capsule::table('tblinvoices')->where('userid', (int) $clientId)
            ->whereIn('status', Statuses::OPEN_INVOICE_STATUSES)->where('id', '!=', (int) $invoice['id'])->count();
        if ($otherCount > 0) {
            $after[] = 'This account has ' . Summary::count($otherCount, 'other open invoice') . ' (' . implode(', ', $others)
                . ($otherCount > count($others) ? ' and more' : '') . '). If the client means another one, ask which.';
        }
        if ($payUrl === '') {
            $after[] = 'View: ' . Invoices::invoiceUrl($invoice['id']);
        }

        return array(
            'summary' => Summary::fit($before, $sections, $after),
            'data' => array(
                'invoice_id' => (int) $invoice['id'],
                'invoice_number' => $number,
                'status' => $status,
                'total' => number_format((float) ($i['total'] ?? 0), 2, '.', ''),
                'balance' => number_format((float) ($i['balance'] ?? 0), 2, '.', ''),
                'currency' => (string) $currency['code'],
                'pay_url' => $payUrl,
                'payments' => $paymentData,
                'covers' => $covers['data'],
                // How many it pays for in all: `covers` holds at most COVERS_IN_DATA of them.
                'covers_total' => $covers['total'],
                'other_open_invoices' => $otherCount,
            ),
        );
    }
}
