<?php

namespace MagizAI\Whmcs\Domain;

use MagizAI\Whmcs\ActionError;
use MagizAI\Whmcs\Action\Permissions;
use MagizAI\Whmcs\Compat;
use MagizAI\Whmcs\Persistence\AuditLog;
use MagizAI\Whmcs\Schema;
use MagizAI\Whmcs\Settings;
use MagizAI\Whmcs\Support\Cycles;
use MagizAI\Whmcs\Support\Dates;
use MagizAI\Whmcs\Text;
use WHMCS\Database\Capsule;

/**
 * Renewing early from the chat: whether a service can be, why not when it cannot, when WHMCS raises
 * the next renewal invoice, and the answer once a renewal is ordered.
 *
 * Every renewal ends in an invoice the client pays on the host's own site. None takes a payment,
 * and none changes what the client is charged without an invoice saying so first.
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class Renewals
{
    /**
     * Can this service be renewed from the chat — and if not, what the client should do instead.
     *
     * Shared by service_renew (which enforces it) and service_details (which reports it), so
     * MagizAI can check BEFORE it offers a confirmation code. It used to find out only after the
     * client had typed the code: a One Time streaming line was offered a renewal, the client
     * confirmed, and was then told there was "nothing to renew" and pointed at a renew option
     * WHMCS does not even show for one-off services.
     *
     * The checks run in this order, and the order matters:
     *  1. a service that is not Active or Suspended is not renewed at all;
     *  2. a user the owner has not allowed to place orders cannot renew, whatever else is true;
     *  3. an open invoice for it comes next, for a suspended service too: its pay link (or, for a
     *     payment still clearing, "do not pay again") is the answer, never a second invoice;
     *  4. a free account, a one-time purchase and any term that does not recur are not renewed,
     *     each with its own next step;
     *  5. then WHMCS's own rules for renewing early: version, Active only, on-demand renewal
     *     switched on, inside the window, and not already refused by WHMCS for this due date.
     *
     * `covered_by` is for this module alone and never goes into an answer. @see notRenewable
     *
     * @return array{renewable: bool, reason: string, unpaid_invoice: int, invoice_status: string, pay_url: string, covered_by: array|null}
     */
    public static function renewability($clientId, array $p)
    {
        $status = (string) $p['status'];
        $cycle = strtolower(trim((string) $p['billingcycle']));

        if (!in_array($status, array('Active', 'Suspended'), true)) {
            return self::notRenewable('Only an active service can be renewed. This one is ' . strtolower(Services::serviceStatusWord($status)) . '.');
        }

        /*
         * A TEAM MEMBER WHO MAY NOT PLACE ORDERS CANNOT RENEW. service_details needs only "View
         * Products & Services", while service_renew also needs "Place New Orders/Upgrades/
         * Cancellations" and is refused without it. Said here too, or MagizAI would offer a code
         * that is refused the moment it is typed. Before any invoice is mentioned: someone who
         * cannot renew is not walked through paying for a renewal. An in-process caller with no
         * sign-in is the account itself. @see Permissions::userMay, PERMISSIONS
         */
        if (!Permissions::userMay($clientId, Permissions::NEEDED['service_renew'])) {
            return self::notRenewable('The account owner has not given this user permission to place orders, which renewing needs. '
                . 'The owner can change it under Account → User Management, or can renew it themselves.');
        }

        /*
         * AN OPEN INVOICE ALREADY COVERS IT. Its number and its pay link only to a user who may see
         * invoices: a team member allowed to order but not to see invoices used to be handed both,
         * here and in service_details, although WHMCS shows them that invoice nowhere. They hear
         * that one is raised and who can pay it. Nothing new is ordered either way. @see Invoices::invoiceNotShown
         */
        $open = Invoices::openInvoiceFor($clientId, (string) $p['domain'], (string) $p['name'], (int) $p['id']);
        if ($open) {
            return Permissions::userMay($clientId, 'invoices')
                ? self::notRenewable(self::serviceInvoiceReason($open), $open)
                : self::notRenewable(Invoices::invoiceNotShown('An invoice for this service'), $open, false);
        }

        // A free trial has nothing to renew. It used to be told it was "bought as a one-time payment".
        if ($cycle === 'free account' || $cycle === 'free') {
            return self::notRenewable($p['name'] . ' is a free account (a free trial, for example), so there is nothing to renew. '
                . 'To keep using the service, the client orders a paid plan: ' . Settings::systemUrl() . 'cart.php'
                . ' Offer to show them the plans.');
        }

        if ($cycle === 'one time' || $cycle === 'onetime') {
            return self::notRenewable($p['name'] . ' was bought as a one-time payment, so it does not renew on its own. '
                . 'To carry on, the client orders the same plan again. Offer to place that order for them '
                . '(it raises an invoice they pay on the website), rather than a renewal.');
        }

        // Recurring terms only: anything else cannot be renewed early, by WHMCS's own rules.
        if (Cycles::cycleKey($cycle) === null) {
            return self::notRenewable($p['name'] . ' is billed on a term that cannot be renewed early from the chat ('
                . (string) $p['billingcycle'] . '). Offer to pass this to the billing team.');
        }

        /*
         * WHMCS'S OWN RULES FOR RENEWING EARLY, checked here so no code is offered for a renewal
         * WHMCS will refuse. It used to be offered on every version and every service: the client
         * typed the code and was then told the renewal "could not be raised". WHMCS renews early
         * only from 8.8 (AddOrder `servicerenewals`), only an Active service, only when on-demand
         * renewal is switched on for that product (or for everything), and only inside the window
         * before the due date. Anything that cannot be determined counts as no.
         */
        $manage = Settings::systemUrl() . 'clientarea.php?action=productdetails&id=' . (int) $p['id'];

        if ($status !== 'Active') {
            return self::notRenewable($p['name'] . ' is suspended, so it cannot be renewed early from the chat. '
                . (Permissions::userMay($clientId, 'invoices')
                    ? 'Paying any overdue invoice usually lifts a suspension: ' . Settings::systemUrl() . 'clientarea.php?action=invoices'
                    : 'Paying the overdue invoice usually lifts a suspension. This user is not allowed to see invoices, so the account owner, '
                        . 'or a team member who may see invoices, can pay it in the client area.'));
        }

        if (!Compat::whmcsAtLeast(Compat::SERVICE_RENEWALS_SINCE)) {
            return self::notRenewable('Renewing early is not available from the chat on this WHMCS. ' . self::renewsThroughInvoice($p)
                . ' The client can see the options for this service in the client area: ' . $manage);
        }

        /*
         * WHMCS HAS ALREADY SAID NO for this service and this due date. Its refusal does not say
         * why (a product without on-demand renewal, metric billing and a closed window all read
         * "can not be renewed at this time"), and some of those rules cannot be read from here. So
         * the refusal itself is remembered, for a day, against the service and its due date: once
         * the due date moves on, the renewal is offered again.
         */
        if (AuditLog::recentRefusal('service_renew', $clientId, self::renewalRefusedMarker($p), 86400)) {
            return self::notRenewable('WHMCS did not accept an early renewal of ' . $p['name'] . ' when it was tried for this due date, '
                . 'so it is not offered again. ' . self::renewsThroughInvoice($p));
        }

        $window = self::onDemandRenewalDays((int) (isset($p['pid']) ? $p['pid'] : 0), Cycles::cycleKey($cycle));
        if ($window === null) {
            return self::notRenewable('Renewing early is not switched on for ' . $p['name'] . '. ' . self::renewsThroughInvoice($p));
        }

        $due = strtotime((string) $p['nextduedate']);
        if (!$due || strpos((string) $p['nextduedate'], '0000') === 0) {
            return self::notRenewable('The next due date of ' . $p['name'] . ' is not known, so it cannot be renewed early from the chat. '
                . 'The client can check it in the client area: ' . $manage);
        }

        $opens = $due - $window * 86400;
        if (time() < $opens) {
            return self::notRenewable('It is too early to renew ' . $p['name'] . '. Renewing early opens on ' . date('j M Y', $opens)
                . ', ' . $window . ' days before it is due on ' . Dates::date($p['nextduedate']) . '.');
        }

        return array('renewable' => true, 'reason' => '', 'unpaid_invoice' => 0, 'invoice_status' => '', 'pay_url' => '', 'covered_by' => null);
    }

    /**
     * @param array{id: int, status: string}|null $invoice the open invoice that already covers it
     * @param bool $shown whether the user may be told which invoice it is (they may see invoices)
     * @return array{renewable: bool, reason: string, unpaid_invoice: int, invoice_status: string, pay_url: string, covered_by: array|null}
     */
    public static function notRenewable($reason, $invoice = null, $shown = true)
    {
        $id = $invoice ? (int) $invoice['id'] : 0;
        $status = $invoice ? (string) $invoice['status'] : '';

        return array(
            'renewable' => false,
            'reason' => (string) $reason,
            // What the user is told about the invoice: nothing at all, to one who may not see invoices.
            'unpaid_invoice' => $shown ? $id : 0,
            'invoice_status' => $shown ? $status : '',
            // Only an unpaid invoice is paid: one still clearing must not be paid twice.
            'pay_url' => $shown && $id > 0 && $status === 'Unpaid' ? Invoices::invoiceUrl($id) : '',
            // For this module alone, never put in an answer: the invoice that covers it, shown or not,
            // so service_renew orders nothing and the activity log still names it. @see \MagizAI\Whmcs\Action\Services\ServiceRenew::existingRenewal
            'covered_by' => $invoice ? array('id' => $id, 'status' => $status) : null,
        );
    }

    /** What to tell the client about the invoice that already covers this service, by its status. */
    public static function serviceInvoiceReason(array $invoice)
    {
        $id = (int) $invoice['id'];

        if ($invoice['status'] === 'Payment Pending') {
            return 'A payment for invoice #' . $id . ', which covers this service, was received and is still clearing. '
                . 'It must not be paid again: the renewal applies once the payment clears. '
                . 'If it has not cleared within a few days, offer to pass this to the billing team.';
        }

        if ($invoice['status'] === 'Collections') {
            return 'Invoice #' . $id . ' for this service is with collections, so it cannot be renewed from the chat. '
                . 'Offer to pass this to the billing team.';
        }

        return 'There is already an unpaid invoice (#' . $id . ') for this service: ' . Invoices::invoiceUrl($id)
            . ' Give them that link rather than raising another: paying it is what keeps the service running.';
    }

    /**
     * What a recurring service that cannot be renewed early does instead, in words the client can
     * act on: its next invoice renews it.
     *
     * NEVER "order it again". For a service that renews, a new order is a second subscription and a
     * second line, and that is what the chat used to suggest after WHMCS refused an early renewal.
     */
    public static function renewsThroughInvoice(array $p)
    {
        $text = $p['name'] . ' renews through its next invoice';

        $due = strtotime((string) $p['nextduedate']);
        if ($due && strpos((string) $p['nextduedate'], '0000') !== 0) {
            $days = self::invoiceDaysBefore(Cycles::cycleKey((string) $p['billingcycle']));
            if ($days === null) {
                $text .= ', which WHMCS raises before the due date (' . Dates::date($p['nextduedate']) . ')';
            } elseif ($due - $days * 86400 > time()) {
                $text .= ', which WHMCS raises on about ' . date('j M Y', $due - $days * 86400)
                    . ' (the service is due on ' . Dates::date($p['nextduedate']) . ')';
            } else {
                $text .= ', which is due to be raised now (the service is due on ' . Dates::date($p['nextduedate']) . ')';
            }
        }

        return $text . '. Paying that invoice renews it. Do not suggest ordering the same plan again: for a service that '
            . 'renews, that would start a second subscription. If they need it renewed sooner, offer to pass this to the billing team.';
    }

    /**
     * How many days before the due date WHMCS raises the invoice for this term: the term's own
     * setting when the admin set one, else the general one. Null when neither can be read.
     */
    public static function invoiceDaysBefore($cycleKey)
    {
        $terms = array(
            'monthly' => 'Monthly', 'quarterly' => 'Quarterly', 'semiannually' => 'SemiAnnually',
            'annually' => 'Annually', 'biennially' => 'Biennially', 'triennially' => 'Triennially',
        );

        try {
            if ($cycleKey !== null && isset($terms[$cycleKey])) {
                $own = trim((string) \WHMCS\Config\Setting::getValue('CreateInvoiceDaysBefore' . $terms[$cycleKey]));
                if ($own !== '' && ctype_digit($own)) {
                    return (int) $own;
                }
            }

            $all = trim((string) \WHMCS\Config\Setting::getValue('CreateInvoiceDaysBefore'));

            return $all !== '' && ctype_digit($all) ? (int) $all : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * The words an early-renewal refusal from WHMCS is recorded under in the activity log: this
     * service, at this due date. @see renewability, ServiceRenew
     */
    public static function renewalRefusedMarker(array $p)
    {
        $due = strtotime((string) $p['nextduedate']);

        return 'early renewal refused by WHMCS for service #' . (int) $p['id']
            . ' due ' . ($due ? date('Y-m-d', $due) : 'unknown');
    }

    /** Is this AddOrder error WHMCS refusing the renewal itself, rather than something else failing? */
    public static function isRenewalRefusal($message)
    {
        return (bool) preg_match('/renew|no items? (were )?added/i', (string) $message);
    }

    /**
     * How many days before its due date a service on this product and term may be renewed early,
     * or null when on-demand renewal is off for it or cannot be determined.
     *
     * WHMCS 8.8 and later: a product may override the system setting (tblondemandrenewals, one row
     * per product, `enabled` plus days per term); a product without a row follows
     * UndefinedProductAddonOnDemandRenewalOption ('global' = the system setting, OnDemandRenewalsEnabled
     * and OnDemandRenewalPeriod<Term>).
     */
    public static function onDemandRenewalDays($pid, $cycleKey)
    {
        $terms = array(
            'monthly' => 'Monthly', 'quarterly' => 'Quarterly', 'semiannually' => 'SemiAnnually',
            'annually' => 'Annually', 'biennially' => 'Biennially', 'triennially' => 'Triennially',
        );
        if ($cycleKey === null || !isset($terms[$cycleKey])) {
            return null;
        }

        try {
            if ($pid > 0 && Schema::hasTable('tblondemandrenewals')) {
                $row = Capsule::table('tblondemandrenewals')->where('rel_type', 'Product')->where('rel_id', $pid)->first();
                if ($row) {
                    $days = isset($row->$cycleKey) ? (int) $row->$cycleKey : 0;

                    return (int) $row->enabled === 1 && $days > 0 ? $days : null;
                }
            }

            $undefined = strtolower(trim((string) \WHMCS\Config\Setting::getValue('UndefinedProductAddonOnDemandRenewalOption')));
            if ($undefined !== '' && $undefined !== 'global') {
                return null;
            }

            if ((int) \WHMCS\Config\Setting::getValue('OnDemandRenewalsEnabled') !== 1) {
                return null;
            }

            $days = (int) \WHMCS\Config\Setting::getValue('OnDemandRenewalPeriod' . $terms[$cycleKey]);

            return $days > 0 ? $days : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * The shared tail of the two renewal actions.
     *
     * @param string $price what the renewal costs, in the client's currency, when it is known: the
     *                      sentence the client reads says the amount, not only that an invoice exists
     */
    public static function renewalResult($clientId, $r, $what, $audit, $price = '')
    {
        if (($r['result'] ?? '') !== 'success' || empty($r['orderid'])) {
            $message = trim((string) ($r['message'] ?? ''));
            $said = Text::customerSafe($message, '');
            throw new ActionError('not_allowed', $said !== ''
                ? 'The renewal could not be raised: ' . $said
                : 'The renewal could not be raised. Offer to pass this to the team.',
                $message !== '' ? 'AddOrder: ' . $message : null);
        }

        $price = (string) $price;
        $invoice = Invoices::raisedInvoice($clientId, isset($r['invoiceid']) ? (int) $r['invoiceid'] : 0, 'The renewal applies once that invoice is paid.');

        $summary = 'Renewal ordered for ' . $what . ($price !== '' ? ' at ' . $price : '') . '. ' . $invoice['text'];

        return array(
            'summary' => $summary,
            'audit' => $audit,
            'notify' => 'A renewal for ' . $what . ($price !== '' ? ' at ' . $price : '') . ' was ordered through live chat. It applies once the invoice is paid.',
            'data' => array(
                'ordered' => true,
                'invoice_id' => $invoice['invoice_id'],
                'pay_url' => $invoice['pay_url'],
                'price' => $price,
            ),
        );
    }
}
