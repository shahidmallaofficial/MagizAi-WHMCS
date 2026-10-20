<?php

namespace MagizAI\Whmcs\Action\Billing;

use MagizAI\Whmcs\Action\ActionContext;
use MagizAI\Whmcs\Action\ActionInterface;
use MagizAI\Whmcs\Domain\Invoices;
use MagizAI\Whmcs\Domain\Statuses;
use MagizAI\Whmcs\Summary;
use MagizAI\Whmcs\Support\Dates;
use MagizAI\Whmcs\Support\WhmcsApi;
use WHMCS\Database\Capsule;

/**
 * The client's open invoices by status, the most overdue first, then the last few paid ones.
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class InvoicesList implements ActionInterface
{
    public function spec()
    {
        return array('identity' => true, 'write' => false, 'group' => 'read', 'label' => 'List invoices');
    }

    public function handle(ActionContext $context)
    {
        $clientId = $context->clientId();

        $seen = array();
        $open = array();
        $paid = array();
        $counts = array('Unpaid' => 0, 'Payment Pending' => 0, 'Collections' => 0);
        $overdue = 0;
        $today = strtotime('today');

        /*
         * Open invoices first, by status, then the last few paid ones.
         *
         * "Payment Pending" and "Collections" were never asked for, so an invoice paid through a
         * gateway that clears slowly vanished from the list while it cleared, and the client could
         * only be told there was nothing to see. Each is labelled with what it means, and only an
         * unpaid invoice gets a pay link. Those two are asked for without letting an error stop the
         * list, and a row is only taken under the status it was asked for, so a WHMCS that ignored
         * the filter could never label a paid invoice as clearing.
         *
         * Unpaid ones oldest due first: when there are more than fit, the most overdue are the ones
         * that must not be left out.
         */
        $passes = array(
            array('Unpaid', 20, true, 'asc'), array('Payment Pending', 10, false, 'desc'),
            array('Collections', 10, false, 'desc'), array('Paid', 5, true, 'desc'),
        );

        foreach ($passes as $pass) {
            list($status, $limit, $strict, $order) = $pass;
            $r = WhmcsApi::api('GetInvoices', array('userid' => $clientId, 'status' => $status, 'limitnum' => $limit, 'orderby' => 'duedate', 'order' => $order), $strict);

            $taken = 0;
            $foreign = false;
            foreach (WhmcsApi::rows($r, 'invoices', 'invoice') as $i) {
                $rowStatus = isset($i['status']) && (string) $i['status'] !== '' ? (string) $i['status'] : $status;
                if ($rowStatus !== $status) {
                    // WHMCS answered with invoices it was not asked for: its total is not this
                    // status's total either.
                    $foreign = true;
                    if (!$strict) {
                        continue;
                    }
                }
                if (isset($seen[(int) $i['id']])) {
                    continue;
                }
                $seen[(int) $i['id']] = true;

                $line = 'Invoice #' . ($i['invoicenum'] ?: $i['id']) . ', ' . $i['currencyprefix'] . $i['total'] . $i['currencysuffix'] . ', ';
                if (in_array($rowStatus, Statuses::OPEN_INVOICE_STATUSES, true)) {
                    $open[] = $line . Invoices::invoiceState($rowStatus, (string) $i['duedate'], (int) $i['id']);
                    if ($rowStatus === $status) {
                        $taken++;
                    }
                    $due = Dates::timestamp(isset($i['duedate']) ? $i['duedate'] : '');
                    if ($rowStatus === 'Unpaid' && $due > 0 && $due < $today) {
                        $overdue++;
                    }
                } else {
                    $paid[] = $line . strtolower($rowStatus) . ', due ' . Dates::date($i['duedate']);
                }
            }

            /*
             * HOW MANY THERE ARE, not how many came back: a client with thirty unpaid invoices has
             * thirty, whatever the page size. WHMCS's own total, when it answered only with what it
             * was asked for; this client's own rows in tblinvoices otherwise. Never fewer than listed.
             */
            if (isset($counts[$status])) {
                $total = $taken;
                if (!$foreign) {
                    $total = max($total, (int) (isset($r['totalresults']) ? $r['totalresults'] : 0));
                }
                $counts[$status] = max($total, (int) Capsule::table('tblinvoices')
                    ->where('userid', (int) $clientId)->where('status', $status)->count());
            }
        }

        if (!$open && !$paid && !array_sum($counts)) {
            return array('summary' => 'This client has no invoices.', 'data' => array('unpaid' => 0, 'overdue' => 0, 'payment_pending' => 0, 'collections' => 0));
        }

        $overdue = max($overdue, (int) Capsule::table('tblinvoices')->where('userid', (int) $clientId)
            ->where('status', 'Unpaid')->where('duedate', '>=', '1000-01-01')->where('duedate', '<', date('Y-m-d'))->count());

        $parts = array();
        if ($counts['Unpaid'] > 0) {
            $parts[] = $counts['Unpaid'] . ' unpaid' . ($overdue > 0 ? ' (' . min($overdue, $counts['Unpaid']) . ' overdue)' : '');
        }
        if ($counts['Payment Pending'] > 0) {
            $parts[] = $counts['Payment Pending'] . ' paid and still clearing';
        }
        if ($counts['Collections'] > 0) {
            $parts[] = $counts['Collections'] . ' with collections';
        }
        $openTotal = array_sum($counts);

        $head = $openTotal > 0 ? Summary::count($openTotal, 'open invoice') . ': ' . implode(', ', $parts) . '.' : 'No open invoices.';
        if ($paid) {
            $head .= ' Then the last ' . (count($paid) === 1 ? 'paid invoice.' : count($paid) . ' paid invoices.');
        }

        return array(
            'summary' => Summary::fit(array($head), array(array('items' => array_merge($open, $paid), 'more' => max(0, $openTotal - count($open))))),
            'data' => array(
                'unpaid' => $counts['Unpaid'], 'overdue' => min($overdue, $counts['Unpaid']),
                'payment_pending' => $counts['Payment Pending'], 'collections' => $counts['Collections'],
            ),
        );
    }
}
