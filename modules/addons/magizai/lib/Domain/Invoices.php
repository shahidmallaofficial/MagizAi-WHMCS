<?php

namespace MagizAI\Whmcs\Domain;

use MagizAI\Whmcs\Action\Permissions;
use MagizAI\Whmcs\Settings;
use MagizAI\Whmcs\Support\Dates;
use WHMCS\Database\Capsule;

/**
 * Open invoices: which one already bills a service or a domain, how its state is said, and where
 * it is paid. Only an unpaid invoice ever gets a pay link.
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class Invoices
{
    /**
     * The open invoice that already renews something, or that sets up an order, as one sentence:
     * unpaid with its pay link, paid and still clearing (never to be paid again), or with
     * collections. Only for a user who may see invoices. @see invoiceNotShown
     *
     * @param array{id: int, status: string} $open
     * @param string $kind 'renewal' or 'order'
     */
    public static function openInvoiceLine(array $open, $kind)
    {
        $id = (int) $open['id'];
        $row = Capsule::table('tblinvoices')->where('id', $id)->first(array('invoicenum', 'duedate'));
        $number = $row && (string) $row->invoicenum !== '' ? (string) $row->invoicenum : (string) $id;
        $name = $kind === 'order' ? 'Order invoice' : 'Renewal invoice';

        if ($open['status'] === 'Payment Pending') {
            return $name . ': #' . $number . ' is paid and the payment is still clearing, so it must not be paid again. '
                . ($kind === 'order' ? 'It is set up once the payment clears.' : 'The renewal applies once the payment clears.');
        }

        if ($open['status'] === 'Collections') {
            return $name . ': #' . $number . ' is with collections, so offer to pass this to the billing team.';
        }

        return $name . ': #' . $number . ' is raised and unpaid' . ($row ? ', due ' . Dates::dueLabel($row->duedate) : '')
            . '. ' . ($kind === 'order' ? 'Paying it is what gets it set up: ' : 'Paying it is the renewal: ') . self::invoiceUrl($id);
    }

    /**
     * The open invoice as data, for a user who may see invoices: its pay link only when it is unpaid.
     *
     * @param array{id: int, status: string} $open
     * @return array{id: int, status: string, pay_url: string}
     */
    public static function openInvoiceData(array $open)
    {
        return array(
            'id' => (int) $open['id'], 'status' => (string) $open['status'],
            'pay_url' => $open['status'] === 'Unpaid' ? self::invoiceUrl($open['id']) : '',
        );
    }

    /**
     * What a team member who may not see invoices is told about one that exists: that it is raised
     * and who can pay it. Never its number, amount, dates, status or the link that pays it: WHMCS
     * asks for "View & Pay Invoices" before it shows any of those in the client area, and the chat
     * can never do more than the client area. @see \MagizAI\Whmcs\Action\Permissions::userMay
     *
     * @param string $what what the invoice is, as the subject of the sentence ("A renewal invoice for it")
     */
    public static function invoiceNotShown($what)
    {
        return $what . ' is already raised. This user is not allowed to see invoices, so the account owner, or a team member '
            . 'who may see invoices, can view it in the client area and pay it if it is still unpaid.';
    }

    /**
     * The invoice a change has just raised (an order, a renewal, an upgrade, an extra), as the end
     * of its answer: its number and pay link to a user who may see invoices.
     *
     * A team member who may order but not see invoices was handed the new invoice's number and the
     * link that pays it, which WHMCS's own client area refuses to show them. They now hear that an
     * invoice is raised and who can pay it, as every other answer tells them. @see invoiceNotShown
     *
     * @param string $then what happens once it is paid ("The renewal applies once that invoice is paid.")
     * @param string $ready how the answer says it can be paid, before the link
     * @return array{text: string, invoice_id: int, pay_url: string}
     */
    public static function raisedInvoice($clientId, $invoiceId, $then, $ready = 'is ready to pay')
    {
        $invoiceId = (int) $invoiceId;
        if ($invoiceId <= 0) {
            return array('text' => 'It is waiting in the client area: ' . Settings::systemUrl() . 'clientarea.php', 'invoice_id' => 0, 'pay_url' => '');
        }

        if (!Permissions::userMay($clientId, 'invoices')) {
            return array(
                'text' => 'An invoice for it is raised. This user is not allowed to see invoices, so the account owner, or a team member '
                    . 'who may see invoices, can view it in the client area and pay it. ' . $then,
                'invoice_id' => 0,
                'pay_url' => '',
            );
        }

        return array(
            'text' => 'Invoice #' . $invoiceId . ' ' . $ready . ': ' . self::invoiceUrl($invoiceId) . ' ' . $then,
            'invoice_id' => $invoiceId,
            'pay_url' => self::invoiceUrl($invoiceId),
        );
    }

    /**
     * An open invoice that already covers this thing, so a second one is not raised for it: unpaid,
     * paid and still clearing, or with collections. WHMCS itself refuses an early renewal while any
     * of those exists.
     *
     * @return array{id: int, status: string}|null
     */
    public static function openInvoiceFor($clientId, $needle, $fallbackNeedle = '', $relId = 0, $type = 'Hosting')
    {
        $statuses = array();
        foreach (Capsule::table('tblinvoices')->where('userid', (int) $clientId)
            ->whereIn('status', Statuses::OPEN_INVOICE_STATUSES)->get(array('id', 'status')) as $row) {
            $statuses[(int) $row->id] = (string) $row->status;
        }

        $id = self::coveringInvoice(array_keys($statuses), $needle, $fallbackNeedle, $relId, $type);

        return $id > 0 && isset($statuses[$id]) ? array('id' => $id, 'status' => $statuses[$id]) : null;
    }

    /**
     * Which of these invoices bills this thing, newest first, or 0.
     *
     * @param int[] $ids open invoices of this client
     */
    public static function coveringInvoice(array $ids, $needle, $fallbackNeedle, $relId, $type)
    {
        if (!$ids) {
            return 0;
        }

        /*
         * 1. BY THE RECORD WHMCS KEEPS. Every line WHMCS generates carries the `type` and `relid` of
         * what it bills (Hosting + service id, Domain + domain id), which is exact. Matching the
         * description text alone meant ANY unpaid line mentioning the product name or the domain
         * counted: an unpaid hosting invoice blocked a domain renewal, and a line for one
         * "3 Months" plan blocked the renewal of a different "3 Months" plan.
         */
        if ((int) $relId > 0) {
            $exact = (int) Capsule::table('tblinvoiceitems')
                ->whereIn('invoiceid', $ids)
                ->where('type', (string) $type)
                ->where('relid', (int) $relId)
                ->orderBy('invoiceid', 'desc')
                ->value('invoiceid');
            if ($exact) {
                return $exact;
            }
        }

        /*
         * 2. BY THE TEXT, for lines that point at nothing. A line a member of staff typed into the
         * invoice editor has no type and no relid, and is still a bill for this period: skipping it
         * raises a second invoice the client can pay twice. Only UNLINKED lines are read this way
         * when the record is known, so a line that belongs to another service or to a domain can
         * never be mistaken for this one.
         *
         * The fallback needle is the whole point for anything without a domain (a VPS, a licence,
         * a streaming line): their search term is the product name.
         */
        $needle = trim((string) $needle);
        if ($needle === '') {
            $needle = trim((string) $fallbackNeedle);
        }
        if ($needle === '') {
            return 0;
        }

        $query = Capsule::table('tblinvoiceitems')
            ->whereIn('invoiceid', $ids)
            ->where('description', 'like', '%' . str_replace(array('%', '_'), array('\%', '\_'), $needle) . '%');

        if ((int) $relId > 0) {
            $query->where(function ($w) {
                $w->where('relid', 0)->orWhereNull('relid');
            });
        }

        return (int) $query->orderBy('invoiceid', 'desc')->value('invoiceid');
    }

    public static function invoiceUrl($invoiceId)
    {
        return Settings::systemUrl() . 'viewinvoice.php?id=' . (int) $invoiceId;
    }

    /**
     * What one open invoice means for the client, by its WHMCS status. Only an unpaid invoice gets a
     * pay link: a payment that is still clearing must not be paid twice, and an invoice in
     * collections is a matter for the billing team. @see Statuses::OPEN_INVOICE_STATUSES
     */
    public static function invoiceState($status, $dueDate, $invoiceId)
    {
        if ($status === 'Payment Pending') {
            return 'payment received and still clearing, so it must not be paid again';
        }

        if ($status === 'Collections') {
            return 'with collections, so the client should contact the billing team about it rather than pay it here';
        }

        $due = strtotime((string) $dueDate);
        $overdue = $due && strpos((string) $dueDate, '0000') !== 0 && $due < strtotime('today');

        return ($overdue ? 'OVERDUE, ' : '') . 'unpaid, due ' . Dates::date($dueDate) . ', pay: ' . self::invoiceUrl($invoiceId);
    }
}
