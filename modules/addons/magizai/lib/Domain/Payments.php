<?php

namespace MagizAI\Whmcs\Domain;

use MagizAI\Whmcs\Action\Permissions;
use MagizAI\Whmcs\Schema;
use MagizAI\Whmcs\Support\Dates;
use MagizAI\Whmcs\Support\Format;
use MagizAI\Whmcs\Support\Money;
use WHMCS\Database\Capsule;

/**
 * What an invoice's status and payments mean for the client, and what each service or domain it
 * bills is doing now, with the step that helps. @see \MagizAI\Whmcs\Action\Billing\PaymentStatus
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class Payments
{
    /**
     * What the client should hear about this invoice's status, and the step that helps. Only an
     * unpaid invoice gets a pay link. @see Invoices::invoiceState
     *
     * "NO PAYMENT" ONLY WHEN THERE IS NONE. The wording used to follow the net amount alone, so an
     * unpaid invoice whose payment was refunded read "No payment has been recorded against it yet"
     * right above a list of that payment and its refund. The rows decide now: none recorded, a part
     * payment, or payments that were refunded and leave nothing paid.
     *
     * @param float $paidIn   what the recorded payments add up to, refunds taken off
     * @param int   $recorded how many payments and refunds are recorded against it
     */
    public static function paymentExplanation($status, array $i, $paidIn, $payUrl, array $currency, $recorded = 0)
    {
        if ($status === 'Unpaid') {
            $due = Dates::timestamp($i['duedate'] ?? '');
            $still = Money::money($i['balance'] ?? 0, $currency) . ' is still to pay.';
            if ($paidIn > 0.004) {
                $text = 'A part payment of ' . Money::money($paidIn, $currency) . ' was recorded, and ' . $still;
            } elseif ((int) $recorded > 0) {
                $text = 'A payment was recorded against it and refunded, so nothing is paid on it now, and ' . $still;
            } else {
                $text = 'No payment has been recorded against it yet.';
            }

            return $text . ($due > 0 && $due < strtotime('today') ? ' It is overdue.' : '') . ' Pay it here: ' . $payUrl
                . ' If they have already paid and it does not show here, the payment may still be on its way: some payment methods take a few days to reach the account. '
                . 'If it has been longer than that, offer to pass it to the billing team with the date and the reference of their payment.';
        }

        if ($status === 'Payment Pending') {
            return 'A payment was received and is still clearing, so it must not be paid again. What it pays for is renewed or set up once the payment clears. '
                . 'If it has not cleared within a few days, offer to pass it to the billing team.';
        }

        if ($status === 'Paid') {
            $paid = Dates::timestamp($i['datepaid'] ?? '');

            return 'It is paid in full' . ($paid > 0 ? ', on ' . date('j M Y', $paid) : '') . '.';
        }

        if ($status === 'Collections') {
            return 'It is with collections, so the client should contact the billing team about it rather than pay it here.';
        }

        if ($status === 'Cancelled') {
            return 'It was cancelled, so there is nothing to pay on it.';
        }

        if ($status === 'Refunded') {
            return 'It was refunded.';
        }

        return 'Its status in the billing system is ' . $status . '.';
    }

    /** How many of the things an invoice pays for are described; the rest are counted. */
    const COVERS_LISTED = 10;

    /** How many of them go into the answer's data, for a reseller's invoice with hundreds of lines. */
    const COVERS_IN_DATA = 50;

    /**
     * The services and domains an invoice bills, by the record WHMCS keeps on each line (type and
     * relid), each read under this client only, with what it is doing now and the step that helps.
     *
     * EVERY ONE IS COUNTED. Only the first eight used to be read, and a reseller's invoice for ten
     * services listed eight with nothing to say two were left out. Now `total` counts every one this
     * user may see, the first COVERS_LISTED are described, and the answer says how many more there
     * are. @see Summary
     *
     * @return array{lines: string[], data: array<int, array{type: string, id: int, status: string}>, total: int}
     */
    public static function invoiceCovers($clientId, $invoiceId, $invoiceStatus)
    {
        $mayServices = Permissions::userMay($clientId, 'products');
        $mayDomains = Permissions::userMay($clientId, 'domains');
        $out = array('lines' => array(), 'data' => array(), 'total' => 0);
        if (!$mayServices && !$mayDomains) {
            return $out;
        }

        // What each line bills, in invoice order, each thing once, of the kinds this user may see.
        $wanted = array();
        foreach (Capsule::table('tblinvoiceitems')->where('invoiceid', (int) $invoiceId)->orderBy('id')->get(array('type', 'relid')) as $item) {
            $type = (string) $item->type;
            $relId = (int) $item->relid;
            if ($relId <= 0) {
                continue;
            }
            if ($mayServices && in_array($type, Statuses::SERVICE_ITEM_TYPES, true)) {
                $wanted['service:' . $relId] = array('service', $relId);
            } elseif ($mayDomains && in_array($type, Statuses::DOMAIN_ITEM_TYPES, true)) {
                $wanted['domain:' . $relId] = array('domain', $relId);
            }
        }
        if (!$wanted) {
            return $out;
        }

        // Read under this client only, in one query for each kind: a line pointing at another
        // client's service or domain (an invoice edited by hand) is never followed, nor counted.
        $ids = array('service' => array(), 'domain' => array());
        foreach ($wanted as $one) {
            $ids[$one[0]][] = $one[1];
        }

        $hasReason = Schema::hasColumn('tblhosting', 'suspendreason');
        $services = array();
        if ($ids['service']) {
            $columns = array('id', 'packageid', 'domain', 'domainstatus');
            if ($hasReason) {
                $columns[] = 'suspendreason';
            }
            foreach (Capsule::table('tblhosting')->where('userid', (int) $clientId)->whereIn('id', $ids['service'])->get($columns) as $s) {
                $services[(int) $s->id] = $s;
            }
        }
        $domains = array();
        if ($ids['domain']) {
            foreach (Capsule::table('tbldomains')->where('userid', (int) $clientId)->whereIn('id', $ids['domain'])->get(array('id', 'domain', 'status')) as $d) {
                $domains[(int) $d->id] = $d;
            }
        }

        $productNames = array();
        foreach ($wanted as $one) {
            list($kind, $id) = $one;

            if ($kind === 'service' && isset($services[$id])) {
                $s = $services[$id];
                $out['total']++;
                if (count($out['data']) < self::COVERS_IN_DATA) {
                    $out['data'][] = array('type' => 'service', 'id' => (int) $s->id, 'status' => (string) $s->domainstatus);
                }
                if (count($out['lines']) >= self::COVERS_LISTED) {
                    continue;
                }

                $pid = (int) $s->packageid;
                if (!isset($productNames[$pid])) {
                    $productNames[$pid] = (string) Capsule::table('tblproducts')->where('id', $pid)->value('name');
                }
                $label = ($productNames[$pid] !== '' ? $productNames[$pid] : 'Service') . ((string) $s->domain !== '' ? ' (' . $s->domain . ')' : '')
                    . ', service #' . (int) $s->id;
                $out['lines'][] = '- ' . $label . ': ' . self::serviceAfterPayment((string) $s->domainstatus, $invoiceStatus,
                    $hasReason ? (string) $s->suspendreason : '', $clientId, $invoiceId);
            } elseif ($kind === 'domain' && isset($domains[$id])) {
                $d = $domains[$id];
                $out['total']++;
                if (count($out['data']) < self::COVERS_IN_DATA) {
                    $out['data'][] = array('type' => 'domain', 'id' => (int) $d->id, 'status' => (string) $d->status);
                }
                if (count($out['lines']) < self::COVERS_LISTED) {
                    $out['lines'][] = '- The domain ' . $d->domain . ': ' . self::domainAfterPayment((string) $d->status, $invoiceStatus);
                }
            }
        }

        return $out;
    }

    /** One service an invoice bills: its state, and what moves it on given the invoice's state. */
    public static function serviceAfterPayment($status, $invoiceStatus, $reason, $clientId, $invoiceId)
    {
        if ($status === 'Active') {
            return 'active.';
        }

        if ($status === 'Pending') {
            if ($invoiceStatus === 'Unpaid') {
                return 'waiting to be set up, which happens once this invoice is paid.';
            }
            if ($invoiceStatus === 'Payment Pending') {
                return 'waiting to be set up, which happens once the payment clears.';
            }

            return 'paid and waiting to be set up. Some plans are set up by the team rather than automatically; '
                . 'if it has been waiting for more than a day, offer to pass it to the team.';
        }

        if ($status === 'Suspended') {
            /*
             * WHMCS lifts a suspension for non-payment by itself once the overdue invoice is paid, if
             * the host has automatic unsuspension on. A suspension with any other reason (terms of
             * service, ID checks) is never lifted by a payment. Its own default reason for
             * non-payment is "Overdue on Payment"; an empty one is treated the same.
             */
            $reason = trim((string) $reason);
            $forPayment = $reason === '' || stripos($reason, 'overdue') !== false || stripos($reason, 'payment') !== false;
            $said = $reason !== '' ? ' (reason: ' . Format::cut($reason, 80) . ')' : '';

            if (!$forPayment) {
                return 'suspended' . $said . '. That kind of suspension is not lifted by paying an invoice, so offer to pass it to the team.';
            }

            if ($invoiceStatus === 'Unpaid') {
                return 'suspended' . $said . '. Paying this invoice is what usually lifts a suspension for non-payment.';
            }
            if ($invoiceStatus === 'Payment Pending') {
                return 'suspended' . $said . '. A suspension for non-payment is usually lifted once the payment clears.';
            }

            $other = Capsule::table('tblinvoices')->where('userid', (int) $clientId)->where('status', 'Unpaid')
                ->where('id', '!=', (int) $invoiceId)->where('duedate', '>=', '1000-01-01')->where('duedate', '<', date('Y-m-d'))
                ->orderBy('duedate')->first(array('id', 'invoicenum'));
            if ($other) {
                return 'still suspended' . $said . '. Another overdue invoice (#' . ((string) $other->invoicenum !== '' ? $other->invoicenum : $other->id)
                    . ') is still unpaid, and paying it may be what lifts the suspension: ' . Invoices::invoiceUrl((int) $other->id)
                    . ' If not, offer to pass it to the team.';
            }

            return 'still suspended' . $said . ', although this invoice is paid. '
                . (self::autoUnsuspends()
                    ? 'This billing system lifts a suspension for non-payment by itself once the invoice is paid, so this one needs the team: offer to pass it to them.'
                    : 'Offer to pass it to the team, who can lift it.');
        }

        if (stripos($status, 'fraud') !== false) {
            return 'being reviewed by the team. Offer to pass a question about it to them.';
        }

        return strtolower($status) . '.';
    }

    /** One domain an invoice bills: its state, and what moves it on given the invoice's state. */
    public static function domainAfterPayment($status, $invoiceStatus)
    {
        if ($status === 'Active') {
            return 'active.';
        }

        if (in_array($status, array('Pending', 'Pending Registration', 'Pending Transfer'), true)) {
            if ($invoiceStatus === 'Unpaid') {
                return strtolower($status) . ': it goes ahead once this invoice is paid.';
            }

            return strtolower($status) . ($invoiceStatus === 'Paid'
                ? '. It is paid and under way: a registration usually completes within a day, and a transfer can take several days.'
                : '.');
        }

        if (in_array($status, array('Grace', 'Redemption', 'Expired'), true) && $invoiceStatus === 'Paid') {
            return strtolower($status) . '. The renewal is paid; if the domain has not changed within a day, offer to pass it to the team.';
        }

        return strtolower($status) . '.';
    }

    /**
     * Does this WHMCS lift a suspension for non-payment by itself when the invoice is paid? Only a
     * setting that plainly says so counts: anything else is "not known", never "no".
     */
    public static function autoUnsuspends()
    {
        try {
            return strtolower(trim((string) \WHMCS\Config\Setting::getValue('AutoUnsuspend'))) === 'on';
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * A payment method's name as the client sees it on the invoice ("PayPal"), not WHMCS's own
     * system name ("paypalcheckout").
     */
    public static function gatewayName($gateway)
    {
        $gateway = trim((string) $gateway);
        if ($gateway === '') {
            return 'a payment method';
        }

        try {
            $name = trim(Format::plain((string) Capsule::table('tblpaymentgateways')
                ->where('gateway', $gateway)->where('setting', 'name')->value('value')));
        } catch (\Exception $e) {
            $name = '';
        }

        return Format::cut($name !== '' ? $name : $gateway, 60);
    }

    /**
     * The end of a payment reference, never all of it: the last six characters of a long one, the
     * last three of a short one, and nothing at all of one so short that three would be most of it.
     */
    public static function referenceEnd($reference)
    {
        $reference = trim((string) $reference);
        $length = function_exists('mb_strlen') ? mb_strlen($reference, 'UTF-8') : strlen($reference);
        $keep = $length >= 10 ? 6 : ($length >= 6 ? 3 : 0);

        if ($keep === 0) {
            return '';
        }

        return function_exists('mb_substr') ? mb_substr($reference, -$keep, null, 'UTF-8') : substr($reference, -$keep);
    }
}
