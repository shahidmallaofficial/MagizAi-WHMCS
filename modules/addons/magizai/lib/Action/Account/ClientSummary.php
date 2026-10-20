<?php

namespace MagizAI\Whmcs\Action\Account;

use MagizAI\Whmcs\ActionError;
use MagizAI\Whmcs\Action\ActionContext;
use MagizAI\Whmcs\Action\ActionInterface;
use MagizAI\Whmcs\Action\Permissions;
use MagizAI\Whmcs\Domain\Invoices;
use MagizAI\Whmcs\Domain\Ownership;
use MagizAI\Whmcs\Domain\Services;
use MagizAI\Whmcs\Domain\Statuses;
use MagizAI\Whmcs\Settings;
use MagizAI\Whmcs\Summary;
use MagizAI\Whmcs\Support\Dates;
use MagizAI\Whmcs\Support\Format;
use MagizAI\Whmcs\Support\Money;
use MagizAI\Whmcs\Support\WhmcsApi;
use WHMCS\Database\Capsule;

/**
 * The whole account in one answer: what needs doing first, then everything else, each part with
 * its count.
 *
 * WHY THIS IS NOT JUST COUNTS. It used to say "Services: 1 active. Unpaid invoices: 1.", true
 * and useless. Asked "you don't know my active service?", the assistant could only answer "you
 * have one active service, but I can't see its renewal date", because it had been handed the
 * number and nothing else. This is the action that fires for the broad questions ("what do I
 * have with you", "what's going on with my account", "when does it run out"), so it carries
 * enough to answer them outright.
 *
 * WHY IT LEADS WITH COUNTS (1.6.0). A long account ran past the length MagizAI passes on, and was
 * cut wherever that fell: the totals, the domains and the tickets were simply gone, and the
 * assistant answered as if they did not exist. The counts come first now, the sections follow in
 * the order a person needs them (open invoices with the most overdue first, services with the
 * soonest due first, domains, tickets, credit), and each section that could not list everything
 * says how many more there are. @see Summary
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class ClientSummary implements ActionInterface
{
    /** How many lines each section of the overview lists at most. The rest is counted. */
    const OVERVIEW_ITEMS = 6;

    public function spec()
    {
        return array('identity' => true, 'write' => false, 'group' => 'read', 'label' => 'Account overview');
    }

    public function handle(ActionContext $context)
    {
        $clientId = $context->clientId();

        $client = Capsule::table('tblclients')->where('id', $clientId)->first(array('firstname', 'companyname', 'credit', 'currency', 'status'));
        if (!$client) {
            throw new ActionError('not_found', 'No client account was found for this sign-in.');
        }
        $currency = Money::clientCurrency($clientId);

        // Every section is shown only to a user allowed to see it in the client area: the overview
        // must not become the way around the per-action permissions. @see Permissions::NEEDED
        $mayServices = Permissions::userMay($clientId, 'products');
        $mayInvoices = Permissions::userMay($clientId, 'invoices');
        $mayDomains = Permissions::userMay($clientId, 'domains');
        $mayTickets = Permissions::userMay($clientId, 'tickets');

        $glance = array();
        $sections = array();
        $data = array();

        /*
         * 1. OPEN INVOICES, the most overdue first. An unpaid one carries the link that pays it.
         *
         * "Payment Pending" (paid, and still clearing: direct debit and bank gateways) and
         * "Collections" used to be left out altogether, so a client asking "did my payment go
         * through?" heard that nothing was open. Neither gets a pay link: a payment that is still
         * clearing must not be paid twice, and an invoice in collections is for the billing team.
         */
        if ($mayInvoices) {
            $open = function () use ($clientId) {
                return Capsule::table('tblinvoices')->where('userid', (int) $clientId)->whereIn('status', Statuses::OPEN_INVOICE_STATUSES);
            };
            $openTotal = (int) $open()->count();
            $overdue = (int) $open()->where('status', 'Unpaid')->where('duedate', '<', date('Y-m-d'))->count();

            $glance[] = $openTotal > 0
                ? Summary::count($openTotal, 'open invoice') . ($overdue > 0 ? ' (' . $overdue . ' overdue)' : '')
                : 'no open invoices';
            $data['open_invoices'] = $openTotal;
            $data['overdue_invoices'] = $overdue;

            if ($openTotal > 0) {
                $items = array();
                foreach ($open()->orderBy('duedate')->orderBy('id')->limit(self::OVERVIEW_ITEMS)->get(array('id', 'invoicenum', 'total', 'duedate', 'status')) as $inv) {
                    $items[] = '- Invoice #' . ((string) $inv->invoicenum !== '' ? $inv->invoicenum : $inv->id) . ', ' . Money::money($inv->total, $currency) . ', '
                        . Invoices::invoiceState((string) $inv->status, (string) $inv->duedate, (int) $inv->id);
                }
                $sections[] = array('head' => 'Open invoices (' . $openTotal . '):', 'items' => $items, 'more' => $openTotal - count($items));
            }
        } else {
            $sections[] = array('head' => 'Invoices: not visible to this user.');
        }

        // 2. SERVICES, the soonest due first: what runs out next is what is asked about.
        if ($mayServices) {
            $services = Services::liveServices($clientId, self::OVERVIEW_ITEMS);
            $liveTotal = $services['live_total'];
            $glance[] = Services::servicesCounts($services);
            $data['live_services'] = $liveTotal;

            $items = array();
            foreach (array_slice($services['live'], 0, self::OVERVIEW_ITEMS) as $serviceId) {
                try {
                    $items[] = '- ' . Services::serviceLine(Ownership::serviceById($clientId, $serviceId), $currency, false);
                } catch (ActionError $e) {
                    continue;
                }
            }

            $sections[] = $liveTotal > 0
                ? array('head' => 'Services (' . $liveTotal . ' live):', 'items' => $items, 'more' => $liveTotal - count($items))
                : array('head' => 'Services: none active.');
        } else {
            $sections[] = array('head' => 'Services: not visible to this user.');
        }

        // 3. DOMAINS, the soonest expiry first. Not mentioned to a user who may not see them.
        if ($mayDomains) {
            $domains = function () use ($clientId) {
                return Capsule::table('tbldomains')->where('userid', (int) $clientId)->whereIn('status', Statuses::LIVE_DOMAIN_STATUSES);
            };
            $liveDomains = (int) $domains()->count();
            // The same window the domain list counts and flags with. @see Statuses::domainSoonWindow
            list($soonFrom, $soonTo) = Statuses::domainSoonWindow();
            $expiring = (int) $domains()->where('status', 'Active')->where('expirydate', '>=', $soonFrom)
                ->where('expirydate', '<=', $soonTo)->count();

            $glance[] = $liveDomains > 0
                ? Summary::count($liveDomains, 'live domain') . ($expiring > 0 ? ' (' . $expiring . ' expiring within ' . Statuses::DOMAIN_EXPIRES_SOON_DAYS . ' days)' : '')
                : 'no live domains';
            $data['live_domains'] = $liveDomains;

            if ($liveDomains > 0) {
                $items = array();
                foreach ($domains()->orderByRaw("CASE WHEN expirydate IS NULL OR expirydate < '1000-01-01' THEN 1 ELSE 0 END")
                    ->orderBy('expirydate')->orderBy('id', 'desc')->limit(self::OVERVIEW_ITEMS)->get(array('domain', 'status', 'expirydate')) as $d) {
                    $items[] = '- ' . $d->domain . ' — ' . $d->status . ', expires ' . Dates::date($d->expirydate);
                }
                $sections[] = array('head' => 'Domains (' . $liveDomains . ' live):', 'items' => $items, 'more' => $liveDomains - count($items));
            }
        }

        // 4. TICKETS still open, in every department: without ignore_dept_assignments, WHMCS returns
        // only those in the departments of the admin the API runs as, and the rest read as "none".
        if ($mayTickets) {
            $tickets = WhmcsApi::api('GetTickets', array(
                'clientid' => $clientId, 'status' => 'All Active Tickets', 'limitnum' => 3, 'ignore_dept_assignments' => true,
            ), false);
            $rows = WhmcsApi::rows($tickets, 'tickets', 'ticket');
            $openTickets = max(count($rows), (int) ($tickets['totalresults'] ?? 0));

            $glance[] = $openTickets > 0 ? Summary::count($openTickets, 'open ticket') : 'no open tickets';
            $data['open_tickets'] = $openTickets;

            if ($openTickets > 0) {
                $items = array();
                foreach ($rows as $t) {
                    $items[] = '- #' . ($t['tid'] ?? '') . ' ' . Format::cut((string) ($t['subject'] ?? ''), 80) . ' — ' . ($t['status'] ?? '');
                }
                $sections[] = array('head' => 'Open tickets (' . $openTickets . '):', 'items' => $items, 'more' => $openTickets - count($items));
            }
        }

        $before = array('Account: ' . Format::cut((string) $client->firstname, 60)
            . ((string) $client->companyname !== '' ? ' (' . Format::cut((string) $client->companyname, 80) . ')' : '') . ', status ' . $client->status . '.');
        if ($glance) {
            $before[] = 'At a glance: ' . implode(', ', $glance) . '.';
        }

        // 5. CREDIT, always, to a user who may see invoices: "do I have any credit?" is answered
        // by a figure, $0.00 included, never by its absence. It is billing information, so a user
        // barred from invoices does not see it.
        $after = array();
        if ($mayInvoices) {
            $after[] = 'Credit balance: ' . Money::money($client->credit, $currency) . '.';
            $data['credit'] = number_format((float) $client->credit, 2, '.', '');
        }
        $after[] = 'Client area: ' . Settings::systemUrl() . 'clientarea.php';

        return array('summary' => Summary::fit($before, $sections, $after), 'data' => $data);
    }
}
