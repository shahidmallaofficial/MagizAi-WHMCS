<?php

namespace MagizAI\Whmcs\Action\Orders;

use MagizAI\Whmcs\ActionError;
use MagizAI\Whmcs\Action\ActionContext;
use MagizAI\Whmcs\Action\ActionInterface;
use MagizAI\Whmcs\Action\Permissions;
use MagizAI\Whmcs\Domain\Invoices;
use MagizAI\Whmcs\Domain\Ownership;
use MagizAI\Whmcs\Settings;
use MagizAI\Whmcs\Summary;
use MagizAI\Whmcs\Support\Dates;
use MagizAI\Whmcs\Support\Format;
use MagizAI\Whmcs\Support\WhmcsApi;
use WHMCS\Database\Capsule;

/**
 * "My order is still pending."
 *
 * This client's five newest orders, or one by the order number the client was shown (or its id),
 * each with its payment, what is still waiting, and the one step that moves it on.
 *
 * NOTHING FROM WHMCS'S FRAUD CHECKS LEAVES. GetOrders answers with the fraud module, its output
 * and its data, the validation data and the address the order came from. None of them is read
 * here. An order WHMCS holds as fraud is "being reviewed by the team", which is what the client
 * may know. A transfer's EPP code (transfersecret) is never read either.
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class OrderStatus implements ActionInterface
{
    public function spec()
    {
        return array('identity' => true, 'write' => false, 'group' => 'read', 'label' => 'Order status: what is waiting for payment or for setup');
    }

    public function handle(ActionContext $context)
    {
        $args = $context->args();
        $clientId = $context->clientId();

        $asked = isset($args['order_id']) && is_scalar($args['order_id']) ? ltrim(trim((string) $args['order_id']), '#') : '';

        if ($asked !== '') {
            if (!ctype_digit($asked) || strlen($asked) > 20) {
                throw new ActionError('invalid_args', 'Ask for the order number, as shown on the order confirmation or in its email.');
            }

            $id = Ownership::ownedOrderId($clientId, $asked);
            $r = WhmcsApi::api('GetOrders', array('id' => $id, 'userid' => (int) $clientId), false);
            $orders = array();
            foreach (WhmcsApi::rows($r, 'orders', 'order') as $o) {
                if ((int) ($o['id'] ?? 0) === $id && (int) ($o['userid'] ?? 0) === (int) $clientId) {
                    $orders[] = $o;
                }
            }
            if (!$orders) {
                throw new ActionError('not_owner', 'No order with that number was found on this account.');
            }
            $total = 1;
        } else {
            $r = WhmcsApi::api('GetOrders', array('userid' => (int) $clientId, 'limitnum' => 5));
            $orders = array();
            $foreign = false;
            foreach (WhmcsApi::rows($r, 'orders', 'order') as $o) {
                if ((int) ($o['userid'] ?? 0) !== (int) $clientId) {
                    $foreign = true; // never shown, and WHMCS's own total no longer counts
                    continue;
                }
                $orders[] = $o;
            }
            usort($orders, function ($a, $b) {
                return (int) ($b['id'] ?? 0) - (int) ($a['id'] ?? 0);
            });
            $orders = array_slice($orders, 0, 5);
            $total = $foreign ? count($orders) : max(count($orders), (int) (isset($r['totalresults']) ? $r['totalresults'] : 0));

            if (!$orders) {
                return array('summary' => 'This client has no orders.', 'data' => array('total' => 0, 'orders' => array()));
            }
        }

        /*
         * ONE LINE PER ORDER, its items folded in, and what each state means said ONCE at the end.
         * Each order used to be a section of its own: a long head (the advice for its state, every
         * time) over a line per item. Five paid orders waiting for setup filled the budget with their
         * heads, each item became "... and 1 more", and the answer was then cut in the middle of a
         * line. Now an order that does not fit is left out whole and counted.
         */
        $mayInvoices = Permissions::userMay($clientId, 'invoices');

        $lines = array();
        $data = array();
        $guides = array();
        foreach ($orders as $o) {
            $one = self::orderAnswer($clientId, $o, $mayInvoices);
            $lines[] = $one['line'];
            $data[] = $one['data'];
            if ($one['guide'] !== '') {
                $guides[$one['guide']] = true;
            }
        }

        $before = $asked !== '' ? array() : array(Summary::count($total, 'order') . ($total > 1 ? ', newest first:' : ':'));

        return array(
            'summary' => Summary::fit($before, array(array('items' => $lines, 'more' => $total - count($orders))), self::orderGuidance(array_keys($guides))),
            'data' => array('total' => $total, 'orders' => $data),
        );
    }

    /**
     * What an order's state means and the step that helps, once for every order in that state.
     *
     * @param string[] $guides
     * @return string[]
     */
    private static function orderGuidance(array $guides)
    {
        $text = array(
            'setup' => 'Paid and waiting to be set up: some plans are set up by the team rather than automatically. '
                . 'If one has been waiting for more than a day, offer to pass it to the team.',
            'pending' => 'Waiting to be processed: if one has been waiting for more than a day, offer to pass it to the team.',
            'review' => 'Being reviewed by the team: nothing is set up until that is done. Offer to pass a question about it to the team.',
            'invoice_hidden' => 'This user is not allowed to see invoices, so the account owner, or a team member who may see invoices, '
                . 'can view and pay the invoice in the client area.',
        );

        $out = array();
        foreach ($guides as $guide) {
            if (isset($text[$guide])) {
                $out[] = $text[$guide];
            }
        }

        return $out;
    }

    /**
     * One order, as the client may hear it, on one line. Built from these fields only: id,
     * ordernum, date, status, paymentstatus, invoiceid, amount and its currency marks, and each line
     * item's name, domain, term and status.
     *
     * The invoice's number and the link that pays it only for a user who may see invoices ("View &
     * Pay Invoices"), as in the client area. Anyone else hears that the order waits for its invoice.
     * @see Invoices::invoiceNotShown
     *
     * @param bool $mayInvoices whether the signed-in user may see invoices
     * @return array{line: string, guide: string, data: array<string, mixed>}
     */
    private static function orderAnswer($clientId, array $o, $mayInvoices)
    {
        $status = (string) ($o['status'] ?? '');
        $invoiceId = (int) ($o['invoiceid'] ?? 0);
        $number = (string) ($o['ordernum'] ?? '') !== '' ? (string) $o['ordernum'] : (string) (int) ($o['id'] ?? 0);

        // The invoice, read under this client: only an unpaid one of theirs gets a pay link, by the
        // number the client sees on it.
        $invoiceStatus = '';
        $invoiceNumber = '';
        if ($invoiceId > 0) {
            $row = Capsule::table('tblinvoices')->where('id', $invoiceId)->where('userid', (int) $clientId)->first(array('status', 'invoicenum'));
            if ($row) {
                $invoiceStatus = (string) $row->status;
                $invoiceNumber = (string) $row->invoicenum !== '' ? (string) $row->invoicenum : (string) $invoiceId;
            }
        }
        $payUrl = $mayInvoices && $invoiceStatus === 'Unpaid' ? Invoices::invoiceUrl($invoiceId) : '';

        /*
         * PAID ONLY ON EVIDENCE. The client's own invoice says so, or, when it cannot be read here,
         * WHMCS's payment status for the order; an order with no invoice and nothing to pay is paid.
         * Anything else is not known, and "it is paid" is never said on a guess.
         */
        $paymentStatus = strtolower(trim((string) ($o['paymentstatus'] ?? '')));
        if ($invoiceStatus !== '') {
            $paid = $invoiceStatus === 'Paid' ? true : ($invoiceStatus === 'Unpaid' ? false : null);
        } elseif ($paymentStatus === 'paid') {
            $paid = true;
        } elseif ($paymentStatus === 'unpaid') {
            $paid = false;
        } else {
            $paid = $invoiceId === 0 && is_numeric($o['amount'] ?? null) && (float) $o['amount'] <= 0 ? true : null;
        }

        // Short, because it is said for every order; what a state means is said once. @see orderGuidance
        $guide = '';
        if (stripos($status, 'fraud') !== false) {
            $state = 'under_review';
            $next = 'It is being reviewed by the team before anything is set up.';
            $guide = 'review';
        } elseif ($status === 'Pending' && $invoiceStatus === 'Payment Pending') {
            $state = 'payment_clearing';
            $next = 'The payment for it is still clearing, so it must not be paid again. It is set up once the payment clears.';
        } elseif ($status === 'Pending' && $paid === false) {
            $state = 'awaiting_payment';
            if ($payUrl !== '') {
                $next = 'It is waiting for payment, and it is set up once invoice #' . $invoiceNumber . ' is paid: ' . $payUrl;
            } elseif ($mayInvoices) {
                $next = 'It is waiting for payment, and it is set up once its invoice is paid. The client\'s invoices are here: '
                    . Settings::systemUrl() . 'clientarea.php?action=invoices';
            } else {
                $next = 'It is waiting for payment, and it is set up once its invoice is paid.';
                $guide = 'invoice_hidden';
            }
        } elseif ($status === 'Pending' && $paid === true) {
            $state = 'awaiting_setup';
            $next = 'It is paid and waiting to be set up.';
            $guide = 'setup';
        } elseif ($status === 'Pending') {
            $state = 'pending';
            $next = 'It is waiting to be processed.';
            $guide = 'pending';
        } elseif ($status === 'Active') {
            $state = 'complete';
            $next = 'It is complete.';
        } elseif ($status === 'Cancelled') {
            $state = 'cancelled';
            $next = 'It was cancelled.';
        } else {
            $state = strtolower(str_replace(' ', '_', $status));
            $next = 'Its status is ' . strtolower($status) . '.';
        }

        // What it is for, named in the line itself: at most three items, then how many more.
        $names = array();
        $itemData = array();
        foreach (WhmcsApi::rows($o, 'lineitems', 'lineitem') as $li) {
            $product = Format::cut(Format::plain((string) ($li['product'] ?? '')), 80);
            $domain = Format::cut(trim((string) ($li['domain'] ?? '')), 80);
            if ($product === '' && $domain === '') {
                continue;
            }
            $cycle = strtolower(trim((string) ($li['billingcycle'] ?? '')));
            $itemStatus = (string) ($li['status'] ?? '');
            $shown = stripos($itemStatus, 'fraud') !== false ? 'being reviewed' : strtolower(trim($itemStatus));

            $detail = array();
            foreach (array($product !== '' ? $domain : '', $cycle, $shown) as $part) {
                if ($part !== '') {
                    $detail[] = $part;
                }
            }
            $names[] = ($product !== '' ? $product : $domain) . ($detail ? ' (' . implode(', ', $detail) . ')' : '');
            $itemData[] = array('name' => $product !== '' ? $product . ($domain !== '' ? ' (' . $domain . ')' : '') : $domain, 'status' => $shown);
        }
        $listed = array_slice($names, 0, 3);
        if (count($names) > count($listed)) {
            $listed[] = Summary::count(count($names) - count($listed), 'more item');
        }
        $last = array_pop($listed);
        $for = $listed ? implode(', ', $listed) . ' and ' . $last : (string) $last;

        $amount = (string) ($o['amount'] ?? '');
        $placed = Dates::timestamp($o['date'] ?? '');
        $line = 'Order #' . $number . ($for !== '' ? ' for ' . $for : '') . ($placed > 0 ? ', placed ' . date('j M Y', $placed) : '')
            . (is_numeric($amount) ? ', total ' . ($o['currencyprefix'] ?? '') . number_format((float) $amount, 2) . ($o['currencysuffix'] ?? '') : '')
            . '. ' . $next;

        return array(
            'line' => $line,
            'guide' => $guide,
            'data' => array(
                'id' => (int) ($o['id'] ?? 0), 'number' => $number, 'state' => $state,
                'date' => $placed > 0 ? date('Y-m-d', $placed) : '',
                // Which invoice, only to a user who may see it, and only one of this client's.
                'invoice_id' => $mayInvoices && $invoiceStatus !== '' ? $invoiceId : 0,
                'pay_url' => $payUrl, 'items' => $itemData,
            ),
        );
    }
}
