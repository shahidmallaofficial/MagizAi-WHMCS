<?php

namespace MagizAI\Whmcs\Action\Billing;

use MagizAI\Whmcs\ActionError;
use MagizAI\Whmcs\Action\ActionContext;
use MagizAI\Whmcs\Action\ActionInterface;
use MagizAI\Whmcs\Domain\Invoices;
use MagizAI\Whmcs\Domain\Ownership;
use MagizAI\Whmcs\Support\Args;
use MagizAI\Whmcs\Support\Money;
use MagizAI\Whmcs\Support\WhmcsApi;
use MagizAI\Whmcs\Text;
use WHMCS\Database\Capsule;

/**
 * Pays one of the client's own unpaid invoices from the credit on their account (1.10.0), with
 * WHMCS's ApplyCredit.
 *
 * Sensitive, so off until an admin ticks it: it moves the client's money. Never more than the credit
 * the account holds and never more than the invoice still owes, both read from WHMCS at the moment it
 * runs, not from the conversation. MagizAI first asks with `check_only`, which changes nothing and
 * says the amount, so the client confirms that exact amount; the run then refuses if the credit or
 * the invoice changed in between. Nothing else about billing (late fees, due dates, discounts) is
 * done here.
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class InvoiceApplyCredit implements ActionInterface
{
    public function spec()
    {
        return array('identity' => true, 'write' => true, 'group' => 'sensitive', 'label' => 'Pay an invoice from the account credit');
    }

    public function handle(ActionContext $context)
    {
        $args = $context->args();
        $clientId = $context->clientId();

        $owned = Ownership::ownedInvoice($clientId, Args::digits($args, 'invoice_id'));
        $number = $owned['number'] !== '' ? $owned['number'] : (string) $owned['id'];
        if ($owned['status'] !== 'Unpaid') {
            throw new ActionError('not_allowed', 'Invoice #' . $number . ' is ' . strtolower($owned['status']) . ', so there is nothing to pay from credit.');
        }

        $invoice = WhmcsApi::api('GetInvoice', array('invoiceid' => $owned['id']), false);
        if (($invoice['result'] ?? '') !== 'success' || (int) ($invoice['userid'] ?? 0) !== (int) $clientId) {
            throw new ActionError('not_owner', 'No invoice with that number was found on this account.');
        }

        $currency = Money::clientCurrency($clientId);
        $balance = self::cents($invoice['balance'] ?? 0);
        $credit = self::cents(Capsule::table('tblclients')->where('id', (int) $clientId)->value('credit'));
        if ($balance <= 0) {
            throw new ActionError('not_allowed', 'Invoice #' . $number . ' has nothing left to pay.');
        }
        if ($credit <= 0) {
            throw new ActionError('not_allowed', 'There is no credit on the account to pay invoice #' . $number . ' with. It can be paid here: ' . Invoices::invoiceUrl($owned['id']));
        }

        $most = min($credit, $balance);
        $asked = self::askedCents($args);
        if ($asked !== null && $asked > $credit) {
            throw new ActionError('not_allowed', 'The account has ' . Money::money($credit / 100, $currency) . ' of credit, less than the ' . Money::money($asked / 100, $currency)
                . ' asked for. Up to ' . Money::money($most / 100, $currency) . ' can be applied to invoice #' . $number . '.');
        }
        if ($asked !== null && $asked > $balance) {
            throw new ActionError('not_allowed', 'Invoice #' . $number . ' has ' . Money::money($balance / 100, $currency) . ' left to pay, less than the ' . Money::money($asked / 100, $currency)
                . ' asked for. Up to ' . Money::money($most / 100, $currency) . ' can be applied.');
        }
        $amount = $asked !== null ? $asked : $most;
        $words = Money::money($amount / 100, $currency);

        // The check before the client confirms: what would be applied, and nothing changed.
        if (!empty($args['check_only'])) {
            return array(
                'summary' => $words . ' of the account credit (' . Money::money($credit / 100, $currency) . ') can be applied to invoice #' . $number . ', which has '
                    . Money::money($balance / 100, $currency) . ' left to pay.' . ($amount < $balance ? ' The rest stays to pay.' : ' That pays it in full.'),
                'data' => array('invoice_id' => $owned['id'], 'amount' => self::plain($amount), 'amount_words' => $words, 'credit' => self::plain($credit),
                    'balance' => self::plain($balance), 'pays_in_full' => $amount >= $balance),
                'changed' => false,
            );
        }

        $r = WhmcsApi::api('ApplyCredit', array('invoiceid' => $owned['id'], 'amount' => self::plain($amount)), false);
        if (($r['result'] ?? '') !== 'success') {
            $said = Text::customerSafe((string) ($r['message'] ?? ''), '');
            throw new ActionError('not_allowed', 'WHMCS did not apply the credit to invoice #' . $number . ($said !== '' ? ': ' . rtrim($said, '. ') . '.' : '.') . ' Nothing was paid.',
                'ApplyCredit: ' . Text::cut(strip_tags((string) ($r['message'] ?? 'no answer')), 300));
        }

        $applied = self::cents($r['amount'] ?? self::plain($amount));
        $paid = strtolower((string) ($r['invoicepaid'] ?? '')) === 'true';
        $left = self::cents(Capsule::table('tblclients')->where('id', (int) $clientId)->value('credit'));
        $summary = Money::money($applied / 100, $currency) . ' of the account credit was applied to invoice #' . $number . '. '
            . ($paid ? 'The invoice is now paid.' : 'The rest of the invoice is still to pay: ' . Invoices::invoiceUrl($owned['id']))
            . ' Credit left on the account: ' . Money::money($left / 100, $currency) . '.';

        return array(
            'summary' => $summary,
            'audit' => 'invoice #' . $number . ': ' . self::plain($applied) . ' of credit applied' . ($paid ? ', now paid' : ''),
            'notify' => $summary,
            'data' => array('invoice_id' => $owned['id'], 'amount' => self::plain($applied), 'paid' => $paid, 'credit_left' => self::plain($left)),
        );
    }

    /** An amount the chat sent, in cents, or null when none was. A malformed one is a question. */
    private static function askedCents(array $args)
    {
        if (!isset($args['amount']) || !is_scalar($args['amount']) || trim((string) $args['amount']) === '') {
            return null;
        }
        $raw = trim((string) $args['amount']);
        if (!preg_match('/^\d{1,9}(\.\d{1,2})?$/', $raw) || self::cents($raw) <= 0) {
            throw new ActionError('invalid_args', 'Ask how much of the credit to apply, as an amount such as 12.50, or leave it to apply as much as covers the invoice.');
        }

        return self::cents($raw);
    }

    /** Money in whole cents, so no comparison is lost to floating point. */
    private static function cents($value)
    {
        return (int) round(((float) (is_scalar($value) ? $value : 0)) * 100);
    }

    private static function plain($cents)
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
