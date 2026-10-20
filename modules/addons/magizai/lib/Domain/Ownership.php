<?php

namespace MagizAI\Whmcs\Domain;

use MagizAI\Whmcs\ActionError;
use MagizAI\Whmcs\Schema;
use MagizAI\Whmcs\Settings;
use MagizAI\Whmcs\Support\Args;
use MagizAI\Whmcs\Support\Cycles;
use MagizAI\Whmcs\Support\WhmcsApi;
use WHMCS\Database\Capsule;

/**
 * The one service, domain, invoice, ticket or order a question is about, found for THIS client only.
 *
 * Anything named in the arguments is looked up UNDER the client the bridge verified, never by an id
 * from the arguments alone. Not found and not yours give the same answer, so the chat cannot be
 * used to probe what exists on other accounts.
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class Ownership
{
    /**
     * One of this client's invoices, by the number the client sees or by its id, never a draft: the
     * client area does not show a draft invoice, so neither does the chat.
     *
     * @param int $asked
     * @return array{id: int, status: string, number: string}
     */
    public static function ownedInvoice($clientId, $asked)
    {
        $mine = function () use ($clientId) {
            return Capsule::table('tblinvoices')->where('userid', (int) $clientId)->where('status', '!=', 'Draft');
        };

        // The printed number first (with sequential numbering it differs from the id), then the id.
        $row = (int) $asked > 0 ? $mine()->where('invoicenum', (string) (int) $asked)->first(array('id', 'status', 'invoicenum')) : null;
        if (!$row && (int) $asked > 0) {
            $row = $mine()->where('id', (int) $asked)->first(array('id', 'status', 'invoicenum'));
        }

        if (!$row) {
            throw new ActionError('not_owner', 'No invoice with that number was found on this account.');
        }

        return array('id' => (int) $row->id, 'status' => (string) $row->status, 'number' => (string) $row->invoicenum);
    }

    /**
     * The invoice "it" most likely means when the client named none: the newest open one, or the
     * newest paid one when nothing is open. Never a draft.
     *
     * @return array{id: int, status: string, number: string}|null
     */
    public static function latestInvoice($clientId)
    {
        $mine = function () use ($clientId) {
            return Capsule::table('tblinvoices')->where('userid', (int) $clientId);
        };

        $row = $mine()->whereIn('status', Statuses::OPEN_INVOICE_STATUSES)->orderBy('id', 'desc')->first(array('id', 'status', 'invoicenum'));
        if (!$row) {
            $row = $mine()->where('status', 'Paid')->orderBy('id', 'desc')->first(array('id', 'status', 'invoicenum'));
        }

        return $row ? array('id' => (int) $row->id, 'status' => (string) $row->status, 'number' => (string) $row->invoicenum) : null;
    }

    /**
     * One of this client's orders, by the order number the client was shown or by its id.
     *
     * @return int
     */
    public static function ownedOrderId($clientId, $asked)
    {
        $mine = function () use ($clientId) {
            return Capsule::table('tblorders')->where('userid', (int) $clientId);
        };

        $id = (int) $mine()->where('ordernum', (string) $asked)->value('id');
        if ($id === 0 && strlen((string) $asked) <= 10) {
            $id = (int) $mine()->where('id', (int) $asked)->value('id');
        }

        if ($id === 0) {
            throw new ActionError('not_owner', 'No order with that number was found on this account.');
        }

        return $id;
    }

    public static function ownedDomain($clientId, array $args)
    {
        $domain = Args::domainArg($args);
        $r = WhmcsApi::api('GetClientsDomains', array('clientid' => $clientId, 'domain' => $domain, 'limitnum' => 5), false);

        foreach (WhmcsApi::rows($r, 'domains', 'domain') as $d) {
            if ((int) $d['userid'] === (int) $clientId && strtolower($d['domainname']) === $domain) {
                return $d;
            }
        }

        throw new ActionError('not_owner', $domain . ' is not a domain on this account.');
    }

    /**
     * The one service a question is about, for THIS client only.
     *
     * THREE BUGS LIVED HERE, all from reading GetClientsProducts as if it were a sorted, filtered list:
     *
     *  - It pages OLDEST first, 25 at a time, with no status filter. A long-standing client — an IPTV
     *    customer who buys a fresh line each month and whose old ones are terminated — could have
     *    their one live service fall off the page, and be told they had no active service.
     *  - Looked up by domain, it took the FIRST matching row, which is the oldest. A client who
     *    re-ordered on the same domain got the terminated one: "this one is terminated", refused to
     *    renew, refused a password, on a site that was live.
     *  - Pending services (paid, not yet provisioned) were invisible to the single-service shortcut.
     *
     * So live services are found in tblhosting directly — this client's rows only, newest first, by
     * status — and only the chosen one is then read in full through the API. A dead row is used only
     * when nothing live matches, and only when the client named it.
     */
    public static function ownedService($clientId, array $args, $purpose = '')
    {
        $rank = array('Active' => 0, 'Suspended' => 1, 'Pending' => 2);

        // Which rows are even candidates, best first: live before dead, then newest.
        $query = Capsule::table('tblhosting')->where('userid', (int) $clientId);

        $named = false;
        if (!empty($args['service_id']) && ctype_digit((string) $args['service_id'])) {
            $query->where('id', (int) $args['service_id']);
            $named = true;
        } elseif (!empty($args['domain'])) {
            $query->where('domain', Args::domainArg($args));
            $named = true;
        }

        // The billing term too, so "renew" can tell a paid line from a free trial without asking.
        $columns = array('id', 'domainstatus');
        $hasCycle = Schema::hasColumn('tblhosting', 'billingcycle');
        if ($hasCycle) {
            $columns[] = 'billingcycle';
        }

        $rows = array();
        foreach ($query->orderBy('id', 'desc')->limit(200)->get($columns) as $r) {
            $rows[] = array(
                'id' => (int) $r->id,
                'status' => (string) $r->domainstatus,
                'cycle' => $hasCycle ? strtolower(trim((string) $r->billingcycle)) : '',
            );
        }

        usort($rows, function ($a, $b) use ($rank) {
            $ra = isset($rank[$a['status']]) ? $rank[$a['status']] : 9;
            $rb = isset($rank[$b['status']]) ? $rank[$b['status']] : 9;

            return $ra !== $rb ? $ra - $rb : $b['id'] - $a['id'];
        });

        $live = array_values(array_filter($rows, function ($r) use ($rank) {
            return isset($rank[$r['status']]);
        }));

        if ($named) {
            // The client named it: the live one if there is one, otherwise the most recent — they
            // asked about THAT service, and saying it is terminated is a true answer.
            $pick = $live ? $live[0] : ($rows ? $rows[0] : null);
            if ($pick === null) {
                throw new ActionError('not_owner', 'That service was not found on this account.');
            }

            return self::serviceById($clientId, $pick['id']);
        }

        if (count($live) === 1) {
            return self::serviceById($clientId, $live[0]['id']);
        }

        if (!$live) {
            throw new ActionError('not_found', 'This client has no active service.');
        }

        /*
         * WHAT IT IS FOR narrows which of several live services can be meant.
         *
         * A client with a paid line and a free trial who asked to renew was asked "which service?",
         * although a free trial has nothing to renew, and the conversation drifted into manual steps.
         * Only the automatic choice is narrowed: a service the client named is always the one used.
         * Only for changes that raise an invoice (renew, upgrade, an extra), which the client can
         * still decline to pay. Never for a cancellation, which is never given a purpose.
         */
        $leftOut = '';
        if ($purpose !== '') {
            $fit = self::servicesFor($purpose, $live);

            if (count($fit['candidates']) === 1) {
                return self::serviceById($clientId, $fit['candidates'][0]['id']);
            }

            if (!$fit['candidates']) {
                throw new ActionError('not_allowed', self::nothingFitsPurpose($purpose, $clientId, $live));
            }

            $live = $fit['candidates'];
            $leftOut = $fit['left_out'];
        }

        /*
         * NAME THEM. "Which service do you mean?" with nothing to choose from makes the client go
         * and find a service number; listing what they actually have lets the assistant offer them
         * as buttons. Capped: nobody picks from forty.
         */
        $names = array();
        foreach (array_slice($live, 0, 8) as $one) {
            $p = self::serviceById($clientId, $one['id']);
            $names[] = '#' . $p['id'] . ' ' . $p['name'] . ($p['domain'] ? ' (' . $p['domain'] . ')' : '') . ' — ' . $p['status'];
        }
        throw new ActionError('invalid_args', 'The client has ' . count($live) . ' active services'
            . ($purpose !== '' ? ' this could apply to' : '') . '. Offer them as choices and ask which one: '
            . implode('; ', $names) . '.' . ($leftOut !== '' ? ' ' . $leftOut : ''));
    }

    /**
     * Which live services a change of this kind can apply to, best first.
     *
     *  - renew, upgrade: a service on a recurring term. Failing that, a one-time purchase (its own
     *    answer is "order the same plan again"). Never a free trial.
     *  - addon: anything but a free trial; if there is nothing else, everything.
     *
     * There is no "cancel": a cancellation is never resolved by elimination. @see Args::purposeArg
     *
     * @param array<int, array{id: int, status: string, cycle: string}> $live
     * @return array{candidates: array, left_out: string}
     */
    public static function servicesFor($purpose, array $live)
    {
        $free = array();
        $once = array();
        $recurring = array();
        foreach ($live as $row) {
            if ($row['cycle'] === 'free account' || $row['cycle'] === 'free') {
                $free[] = $row;
            } elseif (Cycles::cycleKey($row['cycle']) !== null) {
                $recurring[] = $row;
            } else {
                $once[] = $row; // one time, or a term this cannot read
            }
        }

        $notes = array();

        if ($purpose === 'renew' || $purpose === 'upgrade') {
            $candidates = $recurring ? $recurring : $once;
            if ($recurring && $once) {
                $notes[] = count($once) . ' one-time purchase' . (count($once) === 1 ? '' : 's')
                    . ($purpose === 'renew' ? ', which are ordered again rather than renewed' : ', which are not upgraded in place');
            }
        } else {
            $candidates = array_merge($recurring, $once);
            if (!$candidates) {
                return array('candidates' => $live, 'left_out' => '');
            }
        }

        if ($free && $candidates) {
            $notes[] = count($free) . ' free trial' . (count($free) === 1 ? '' : 's');
        }

        return array(
            'candidates' => array_values($candidates),
            'left_out' => $notes ? 'Not listed: ' . implode(' and ', $notes) . '.' : '',
        );
    }

    /** Why none of the client's live services can be renewed or upgraded: they are all free trials. */
    public static function nothingFitsPurpose($purpose, $clientId, array $live)
    {
        $names = array();
        foreach (array_slice($live, 0, 5) as $one) {
            try {
                $p = self::serviceById($clientId, $one['id']);
                $names[] = '#' . $p['id'] . ' ' . $p['name'];
            } catch (ActionError $e) {
                continue;
            }
        }

        return 'The client\'s services are free trials' . ($names ? ' (' . implode('; ', $names) . ')' : '')
            . ($purpose === 'upgrade' ? ', which are not upgraded in place.' : ', which have nothing to renew.')
            . ' To keep using the service, the client orders a paid plan: ' . Settings::systemUrl() . 'cart.php'
            . ' Offer to show them the plans.';
    }

    /**
     * This client's LIVE service ids, newest first, straight from tblhosting.
     *
     * The single source for "what does this client have running". GetClientsProducts pages oldest
     * first with no status filter, so reading it for this meant a long-standing client's live service
     * could fall off the page. @see ownedService, ServicesList, ClientSummary
     *
     * @return array{live: int[], ended: int}
     */
    public static function liveServiceIds($clientId)
    {
        $live = array();
        $ended = 0;
        foreach (Capsule::table('tblhosting')->where('userid', (int) $clientId)->orderBy('id', 'desc')->limit(200)->get(array('id', 'domainstatus')) as $row) {
            if (in_array((string) $row->domainstatus, array('Active', 'Suspended', 'Pending'), true)) {
                $live[] = (int) $row->id;
            } else {
                $ended++;
            }
        }

        return array('live' => $live, 'ended' => $ended);
    }

    /** One service, in full, through the API — only after the row has been proved to be this client's. */
    public static function serviceById($clientId, $serviceId)
    {
        $r = WhmcsApi::api('GetClientsProducts', array('clientid' => (int) $clientId, 'serviceid' => (int) $serviceId), false);

        foreach (WhmcsApi::rows($r, 'products', 'product') as $p) {
            if ((int) $p['clientid'] === (int) $clientId && (int) $p['id'] === (int) $serviceId) {
                return $p;
            }
        }

        throw new ActionError('not_owner', 'That service was not found on this account.');
    }

    public static function ownedTicket($clientId, array $args)
    {
        $tid = isset($args['ticket_id']) ? strtoupper(ltrim(trim((string) $args['ticket_id']), '#')) : '';
        if (!preg_match('/^[A-Z0-9-]{1,32}$/', $tid)) {
            throw new ActionError('invalid_args', 'Ask for the ticket number, as shown in the ticket email or the client area.');
        }

        // By the client-facing number only. The internal id is sequential and would let the chat walk
        // through tickets by counting.
        $t = WhmcsApi::api('GetTicket', array('ticketnum' => $tid), false);

        if (($t['result'] ?? '') !== 'success' || (int) ($t['userid'] ?? 0) !== (int) $clientId) {
            throw new ActionError('not_owner', 'No ticket #' . $tid . ' was found on this account.');
        }

        return $t;
    }
}
