<?php

namespace MagizAI\Whmcs\Action\Services;

use MagizAI\Whmcs\ActionError;
use MagizAI\Whmcs\Action\ActionContext;
use MagizAI\Whmcs\Action\ActionInterface;
use MagizAI\Whmcs\Action\Permissions;
use MagizAI\Whmcs\Domain\Invoices;
use MagizAI\Whmcs\Domain\Ownership;
use MagizAI\Whmcs\Domain\Pricing;
use MagizAI\Whmcs\Domain\Renewals;
use MagizAI\Whmcs\Domain\Services;
use MagizAI\Whmcs\Domain\Statuses;
use MagizAI\Whmcs\Schema;
use MagizAI\Whmcs\Settings;
use MagizAI\Whmcs\Summary;
use MagizAI\Whmcs\Support\Args;
use MagizAI\Whmcs\Support\Cycles;
use MagizAI\Whmcs\Support\Dates;
use MagizAI\Whmcs\Support\Money;
use WHMCS\Database\Capsule;

/**
 * "When does it renew?", "why is there no invoice?", "can I renew it early?"
 *
 * One service: its next due date (a date already passed says so), when WHMCS raises the next
 * renewal invoice, the open invoice that already renews it (with its pay link when it is unpaid),
 * and whether it can be renewed early from the chat, or why not. The same rules service_renew
 * keeps, so this never says yes to a renewal that would be refused. @see Renewals::renewability
 *
 * A domain named here that is not a hosting service is read as a domain registration, for a user
 * who may see domains. So is a domain whose hosting has ended while its registration lives on.
 *
 * ONLY A SERVICE THAT IS BILLED RENEWS. A terminated or cancelled service used to get a due date
 * marked "PASSED, overdue" and "its next renewal invoice is due to be raised now", an invoice
 * WHMCS never raises for a service that has ended. A pending one is not set up yet: its order
 * invoice comes first.
 *
 * THE INVOICE ONLY TO A USER WHO MAY SEE INVOICES. The action needs "View Products & Services";
 * the renewal invoice's number, date, status and pay link also need "View & Pay Invoices", the
 * permission WHMCS asks before it shows them in the client area. Anyone else hears that one is
 * raised and who can pay it. @see Invoices::invoiceNotShown
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class RenewalStatus implements ActionInterface
{
    public function spec()
    {
        return array('identity' => true, 'write' => false, 'group' => 'read', 'label' => 'Renewal status: next due date, next invoice, and whether it can be renewed early');
    }

    public function handle(ActionContext $context)
    {
        $args = $context->args();
        $clientId = $context->clientId();

        $domainNamed = empty($args['service_id']) && !empty($args['domain']);

        try {
            // "When does it renew?" with a paid line and a free trial is about the paid line.
            $p = Ownership::ownedService($clientId, $args, 'renew');
        } catch (ActionError $e) {
            if ($e->code() === 'not_owner' && $domainNamed && Permissions::userMay($clientId, 'domains')) {
                return self::domainRenewalStatus($clientId, $args);
            }

            throw $e;
        }

        $status = (string) $p['status'];
        // Named by the client: that one is the answer. Chosen for them: the others are named too.
        $also = $domainNamed || (!empty($args['service_id']) && ctype_digit((string) $args['service_id']))
            ? array('lines' => array(), 'total' => 0) : self::otherLiveServices($clientId, (int) $p['id']);
        $label = $p['name'] . ($p['domain'] ? ' (' . $p['domain'] . ')' : '') . ', service #' . (int) $p['id'];

        /*
         * A NAME CAN BE TWO THINGS. A client who cancelled the hosting on brand.org but kept the domain
         * asks when brand.org renews: the answer is the registration, with a word on the hosting. The
         * ended hosting row used to win only because it carried the name, and the registration that
         * does renew was never looked at.
         */
        if (!in_array($status, Statuses::LIVE_SERVICE_STATUSES, true) && $domainNamed && Permissions::userMay($clientId, 'domains')
            && self::domainRegistrationKept($clientId, Args::domainArg($args))) {
            try {
                return self::domainRenewalStatus($clientId, $args, 'The hosting service on ' . $p['domain'] . ' (' . $p['name']
                    . ', service #' . (int) $p['id'] . ') ' . Services::endedPhrase($status) . ', so only the domain registration renews.');
            } catch (ActionError $e) {
                // WHMCS did not give the registration back after all: the hosting is still a true answer.
            }
        }

        $currency = Money::clientCurrency($clientId);
        $cycle = strtolower(trim((string) $p['billingcycle']));
        $cycleKey = Cycles::cycleKey($cycle);
        $billed = in_array($status, array('Active', 'Suspended'), true);
        $due = Dates::timestamp($p['nextduedate']);
        $data = array(
            'service_id' => (int) $p['id'],
            'status' => $status,
            'billingcycle' => (string) $p['billingcycle'],
            'next_due_date' => $due > 0 ? date('Y-m-d', $due) : '',
            // Overdue only while it is billed: a service that has ended is not overdue on anything.
            'due_passed' => $billed && $due > 0 && $due < strtotime('today'),
            'next_invoice_date' => '',
            'open_invoice' => null,
            'renewable' => false,
            'renew_block_reason' => '',
            'renewal' => Pricing::serviceRenewalPrice($p, $currency),
        );

        $lines = array($label . ': ' . Services::serviceStatusWord($status) . ', '
            . ($cycleKey !== null ? Money::money($p['recurringamount'], $currency) . ' ' . $cycle : $cycle) . '.');

        if ($status === 'Pending') {
            $data['renew_block_reason'] = 'It is not set up yet, so there is nothing to renew: the invoice for its order comes first, '
                . 'and it renews from its due date once it is running.';
            $lines[] = $data['renew_block_reason'];

            $open = Invoices::openInvoiceFor($clientId, (string) $p['domain'], (string) $p['name'], (int) $p['id']);
            if ($open && Permissions::userMay($clientId, 'invoices')) {
                $data['open_invoice'] = Invoices::openInvoiceData($open);
                $lines[] = Invoices::openInvoiceLine($open, 'order');
            } elseif ($open) {
                $lines[] = Invoices::invoiceNotShown('The invoice for its order');
            } else {
                $lines[] = 'If its order is paid and it is still not set up after a day, offer to pass it to the team.';
            }

            return self::answer($lines, $data, $also);
        }

        if (!$billed) {
            // Terminated, cancelled, completed, or held by the team: nothing is invoiced again.
            $data['renew_block_reason'] = stripos($status, 'fraud') !== false
                ? 'It is being reviewed by the team, so it is not renewed and no renewal invoice is raised for it while that lasts. '
                    . 'Offer to pass a question about it to the team.'
                : 'It has ended (' . strtolower($status) . '), so it is not renewed and no renewal invoice is raised for it. '
                    . 'To have it again, the client orders it again: ' . Settings::systemUrl() . 'cart.php'
                    . ' If they think it ended by mistake, offer to pass this to the team.';
            $lines[] = $data['renew_block_reason'];

            return self::answer($lines, $data, $also);
        }

        if ($cycle === 'free account' || $cycle === 'free') {
            $lines[] = 'It is a free account (a free trial, for example), so there is nothing to renew'
                . ($due > 0 ? '. It ends on ' . Dates::dueLabel($p['nextduedate']) : '')
                . '. To keep using the service, the client orders a paid plan: ' . Settings::systemUrl() . 'cart.php';

            return self::answer($lines, $data, $also);
        }

        if ($cycleKey === null) {
            // One time, or a term WHMCS never invoices again.
            $lines[] = ($cycle === 'one time' || $cycle === 'onetime'
                    ? 'It was bought as a one-time payment, so WHMCS never raises a renewal invoice for it'
                    : 'It is billed on a term that WHMCS does not renew (' . $p['billingcycle'] . ')')
                . ($due > 0 ? '. It ends on ' . Dates::dueLabel($p['nextduedate']) : '')
                . '. To carry on, the client orders the same plan again, which raises an invoice they pay on the website: '
                . Settings::systemUrl() . 'cart.php';

            return self::answer($lines, $data, $also);
        }

        $lines[] = 'Next due date: ' . Dates::dueLabel($p['nextduedate']) . '.';

        $open = Invoices::openInvoiceFor($clientId, (string) $p['domain'], (string) $p['name'], (int) $p['id']);
        if ($open && Permissions::userMay($clientId, 'invoices')) {
            $data['open_invoice'] = Invoices::openInvoiceData($open);
            $lines[] = Invoices::openInvoiceLine($open, 'renewal');
        } elseif ($open) {
            // Raised, so nothing is renewed early either; said without its number or its link.
            $data['renew_block_reason'] = Invoices::invoiceNotShown('A renewal invoice for it');
            $lines[] = $data['renew_block_reason'];
        } else {
            $next = self::nextInvoice($p);
            $data['next_invoice_date'] = $next['date'];
            $lines[] = $next['text'];

            // The same answer service_renew would give, before anyone is offered a code for it.
            $renew = Renewals::renewability($clientId, $p);
            $data['renewable'] = $renew['renewable'];
            $data['renew_block_reason'] = $renew['reason'];
            $price = $data['renewal'];
            $lines[] = 'Renewing early from the chat: ' . ($renew['renewable']
                ? 'possible now' . ($price ? ', at ' . $price['price'] : '') . '. It raises an invoice now, and the renewal applies once that invoice is paid.'
                : $renew['reason']);
        }

        return self::answer($lines, $data, $also);
    }

    /**
     * The answer, with the client's other live services named after it when the client named none.
     *
     * A READ NAMES WHAT IT LEFT OUT. With nothing named, the service is chosen the way a renewal
     * chooses it: a plan that renews before a one-time purchase or a free trial. That is right for a
     * renewal, but "when does my line end?" from a client with a monthly plan and a one-time IPTV
     * line was answered with the plan's dates, and nothing said the line existed. The others are
     * named now, with their dates, so the assistant can see which one the client meant.
     *
     * @param array{lines: string[], total: int} $also
     */
    private static function answer(array $lines, array $data, array $also)
    {
        $total = isset($also['total']) ? (int) $also['total'] : 0;
        if ($total > 0) {
            $more = $total - count($also['lines']);
            $lines[] = 'This answer is about the service above. The client also has ' . Summary::count($total, 'other live service') . ': '
                . implode('; ', $also['lines']) . ($more > 0 ? '; and ' . $more . ' more' : '')
                . '. If they meant another one, look that one up by its number.';
        }
        $data['other_live_services'] = $total;

        return array('summary' => implode("\n", $lines), 'data' => $data);
    }

    /**
     * The client's other live services: how many, and the newest three, each as "#4400 IPTV 1 Month,
     * Active, a one-time purchase, next due / ends 3 Nov 2026". Read under this client only.
     *
     * @return array{lines: string[], total: int}
     */
    private static function otherLiveServices($clientId, $chosenId)
    {
        $others = function () use ($clientId, $chosenId) {
            return Capsule::table('tblhosting')->where('userid', (int) $clientId)->where('id', '!=', (int) $chosenId)
                ->whereIn('domainstatus', Statuses::LIVE_SERVICE_STATUSES);
        };

        $out = array('lines' => array(), 'total' => (int) $others()->count());
        if ($out['total'] === 0) {
            return $out;
        }

        foreach ($others()->orderBy('id', 'desc')->limit(3)->pluck('id') as $id) {
            try {
                $o = Ownership::serviceById($clientId, (int) $id);
            } catch (ActionError $e) {
                continue;
            }
            $cycle = strtolower(trim((string) $o['billingcycle']));
            $kind = $cycle === 'free account' || $cycle === 'free' ? 'a free trial'
                : ($cycle === 'one time' || $cycle === 'onetime' ? 'a one-time purchase' : $cycle);
            $due = Dates::dueLabel($o['nextduedate'] ?? '');
            $out['lines'][] = '#' . (int) $o['id'] . ' ' . $o['name'] . ((string) $o['domain'] !== '' ? ' (' . $o['domain'] . ')' : '')
                . ', ' . Services::serviceStatusWord($o['status']) . ', ' . $kind . ($due !== 'n/a' ? ', next due / ends ' . $due : '');
        }

        return $out;
    }

    /**
     * When WHMCS raises this service's next renewal invoice: on its next invoice date (or due date),
     * less the days before that the host invoices this term. Said as "about", because the daily cron
     * is what raises it.
     *
     * @return array{date: string, text: string}
     */
    private static function nextInvoice(array $p)
    {
        $base = 0;
        if (!empty($p['id']) && Schema::hasColumn('tblhosting', 'nextinvoicedate')) {
            $base = Dates::timestamp(Capsule::table('tblhosting')->where('id', (int) $p['id'])->value('nextinvoicedate'));
        }
        if ($base === 0) {
            $base = Dates::timestamp($p['nextduedate']);
        }

        if ($base === 0) {
            return array('date' => '', 'text' => 'WHMCS holds no next due date for it, so when the next invoice comes cannot be said. Offer to pass this to the billing team.');
        }

        $days = Renewals::invoiceDaysBefore(Cycles::cycleKey((string) $p['billingcycle']));
        if ($days === null) {
            return array('date' => '', 'text' => 'Next renewal invoice: WHMCS raises it some days before ' . date('j M Y', $base)
                . '; how many days before is set by the host.');
        }

        $raise = $base - $days * 86400;
        if ($raise > time()) {
            return array('date' => date('Y-m-d', $raise), 'text' => 'Next renewal invoice: WHMCS raises it on about ' . date('j M Y', $raise)
                . ', ' . $days . ' days before ' . date('j M Y', $base) . '.');
        }

        return array('date' => date('Y-m-d', strtotime('today')), 'text' => 'Next renewal invoice: it is due to be raised now, on WHMCS\'s next daily run. '
            . 'If none appears within a day, offer to pass this to the billing team.');
    }

    /**
     * Does this client keep a registration for this domain that can still renew? Live, or expired
     * and still renewable. Read under this client only.
     */
    private static function domainRegistrationKept($clientId, $domain)
    {
        if ((string) $domain === '') {
            return false;
        }

        try {
            return Capsule::table('tbldomains')->where('userid', (int) $clientId)->where('domain', (string) $domain)
                ->whereIn('status', array_merge(Statuses::LIVE_DOMAIN_STATUSES, array('Expired')))->exists();
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * renewal_status for a domain registration: expiry, auto-renew, the invoice that renews it (to a
     * user who may see invoices), the price.
     *
     * @param string $note one more line, before the link: what became of the hosting on the same name
     */
    private static function domainRenewalStatus($clientId, array $args, $note = '')
    {
        $d = Ownership::ownedDomain($clientId, $args);
        $expires = Dates::timestamp($d['expirydate'] ?? '');
        $autoRenew = empty($d['donotrenew']);

        $lines = array(
            'The domain ' . $d['domainname'] . ': ' . $d['status'] . ', expires ' . Dates::dueLabel($d['expirydate'] ?? '')
                . ', next due ' . Dates::date($d['nextduedate'] ?? '') . '.',
            $autoRenew
                ? 'Auto-renew is on: WHMCS raises its renewal invoice before it expires.'
                : 'Auto-renew is off, so it is not renewed by itself: it expires unless it is renewed by hand.',
        );

        $data = array(
            'domain_id' => (int) $d['id'], 'status' => (string) $d['status'],
            'expires' => $expires > 0 ? date('Y-m-d', $expires) : '', 'auto_renew' => $autoRenew, 'open_invoice' => null,
        );

        $open = Invoices::openInvoiceFor($clientId, (string) $d['domainname'], 'renew', (int) $d['id'], 'Domain');
        if ($open && Permissions::userMay($clientId, 'invoices')) {
            $data['open_invoice'] = Invoices::openInvoiceData($open);
            $lines[] = Invoices::openInvoiceLine($open, 'renewal');
        } elseif ($open) {
            $lines[] = Invoices::invoiceNotShown('A renewal invoice for it');
        }

        $renewal = Pricing::domainRenewalPrices((string) $d['domainname'], $clientId);
        if ($renewal['prices']) {
            $terms = array();
            foreach (array_slice($renewal['prices'], 0, 3, true) as $years => $one) {
                $terms[] = $one['price'] . ' for ' . $years . ' year' . ((int) $years === 1 ? '' : 's');
            }
            $lines[] = 'Renewal price: ' . implode(', ', $terms) . '.';
        }

        if ((string) $note !== '') {
            $lines[] = (string) $note;
        }

        $lines[] = 'Manage: ' . Settings::systemUrl() . 'clientarea.php?action=domaindetails&id=' . (int) $d['id'];

        return array('summary' => implode("\n", $lines), 'data' => $data);
    }
}
