<?php

namespace MagizAI\Whmcs\Action\Billing;

use MagizAI\Whmcs\ActionError;
use MagizAI\Whmcs\Action\ActionContext;
use MagizAI\Whmcs\Action\ActionInterface;
use MagizAI\Whmcs\Settings;
use MagizAI\Whmcs\Support\Args;
use MagizAI\Whmcs\Support\Dates;
use MagizAI\Whmcs\Support\Format;
use MagizAI\Whmcs\Support\Money;
use MagizAI\Whmcs\Support\WhmcsApi;
use WHMCS\Database\Capsule;

/**
 * One invoice, by the number the client sees or by its id: status, totals, dates, items and the
 * step that helps.
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class InvoiceDetails implements ActionInterface
{
    public function spec()
    {
        return array('identity' => true, 'write' => false, 'group' => 'read', 'label' => 'Invoice details');
    }

    public function handle(ActionContext $context)
    {
        $args = $context->args();
        $clientId = $context->clientId();

        $asked = Args::digits($args, 'invoice_id');

        /*
         * THE NUMBER THE CLIENT SEES, NOT ONLY THE INTERNAL ID.
         *
         * With sequential invoice numbering on — common with EU hosts — a paid invoice has an
         * internal id of, say, 1523 and a printed number of 37, and invoices_list shows "#37".
         * Looking "37" up as an id either found nothing or, worse, found a DIFFERENT invoice of the
         * same client and presented its items and total as #37. So the printed number is tried first,
         * then the id — and both only ever among this client's own invoices.
         */
        $id = (int) Capsule::table('tblinvoices')->where('userid', (int) $clientId)->where('invoicenum', (string) $asked)->value('id');
        if ($id === 0) {
            $id = (int) Capsule::table('tblinvoices')->where('userid', (int) $clientId)->where('id', (int) $asked)->value('id');
        }
        if ($id === 0) {
            throw new ActionError('not_owner', 'No invoice with that number was found on this account.');
        }

        $i = WhmcsApi::api('GetInvoice', array('invoiceid' => $id), false);

        if (($i['result'] ?? '') !== 'success' || (int) ($i['userid'] ?? 0) !== (int) $clientId) {
            throw new ActionError('not_owner', 'No invoice with that number was found on this account.');
        }

        $currency = Money::clientCurrency($clientId);
        $items = array();
        foreach (WhmcsApi::rows($i, 'items', 'item') as $item) {
            $items[] = Format::cut(Format::plain($item['description']), 120) . ' ' . Money::money($item['amount'], $currency);
        }

        // A pay link only where paying is the right thing to do. @see \MagizAI\Whmcs\Domain\Invoices::invoiceState
        $status = (string) $i['status'];
        $link = Settings::systemUrl() . 'viewinvoice.php?id=' . (int) $i['invoiceid'];
        if ($status === 'Unpaid') {
            $next = 'View or pay: ' . $link;
        } elseif ($status === 'Payment Pending') {
            $next = 'The payment for this invoice was received and is still clearing, so it must not be paid again. View: ' . $link;
        } elseif ($status === 'Collections') {
            $next = 'This invoice is with collections, so the client should contact the billing team about it rather than pay it here. View: ' . $link;
        } else {
            $next = 'View: ' . $link;
        }

        return array('summary' => 'Invoice #' . ($i['invoicenum'] ?: $i['invoiceid']) . ' — ' . $status
            . ', total ' . Money::money($i['total'], $currency) . ', balance ' . Money::money($i['balance'], $currency)
            . ', dated ' . Dates::date($i['date']) . ', due ' . Dates::date($i['duedate'])
            . ($i['datepaid'] && strpos((string) $i['datepaid'], '0000') !== 0 ? ', paid ' . Dates::date($i['datepaid']) : '') . ".\n"
            . 'Items: ' . implode('; ', $items) . "\n"
            . $next);
    }
}
