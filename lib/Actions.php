<?php

namespace MagizAI\Whmcs;

use WHMCS\Database\Capsule;

/**
 * Everything the assistant can ask WHMCS for, and the ownership rules around each.
 *
 * Rules every action follows:
 *  - A client action takes the client id the BRIDGE verified, never an id from the arguments.
 *  - Anything named in the arguments (a domain, a service, an invoice, a ticket) is looked up UNDER
 *    that client. Not found and not yours give the same answer, so the chat cannot be used to probe
 *    which domains exist on other accounts.
 *  - Nothing returns a password, a service username, an EPP code or payment details.
 *  - `summary` is what the assistant reads: short, factual, no HTML.
 *
 * The catalogue must stay in step with WhmcsConnector::ACTIONS on the MagizAI side.
 */
class Actions
{
    /** @return array<string, array{identity: bool, write: bool, label: string, group: string}> */
    public static function catalog()
    {
        return array(
            'kb_search' => array('identity' => false, 'write' => false, 'group' => 'public', 'label' => 'Search the knowledgebase'),
            'announcements' => array('identity' => false, 'write' => false, 'group' => 'public', 'label' => 'Latest announcements'),
            'network_status' => array('identity' => false, 'write' => false, 'group' => 'public', 'label' => 'Network status'),
            'product_catalog' => array('identity' => false, 'write' => false, 'group' => 'public', 'label' => 'Products and prices'),
            'domain_check' => array('identity' => false, 'write' => false, 'group' => 'public', 'label' => 'Domain availability'),
            'tld_pricing' => array('identity' => false, 'write' => false, 'group' => 'public', 'label' => 'Domain prices'),

            'client_summary' => array('identity' => true, 'write' => false, 'group' => 'read', 'label' => 'Account overview'),
            'services_list' => array('identity' => true, 'write' => false, 'group' => 'read', 'label' => 'List services'),
            'service_details' => array('identity' => true, 'write' => false, 'group' => 'read', 'label' => 'Service details'),
            'domains_list' => array('identity' => true, 'write' => false, 'group' => 'read', 'label' => 'List domains'),
            'domain_details' => array('identity' => true, 'write' => false, 'group' => 'read', 'label' => 'Domain details (status, expiry, nameservers, lock)'),
            'invoices_list' => array('identity' => true, 'write' => false, 'group' => 'read', 'label' => 'List invoices'),
            'invoice_details' => array('identity' => true, 'write' => false, 'group' => 'read', 'label' => 'Invoice details'),
            'tickets_list' => array('identity' => true, 'write' => false, 'group' => 'read', 'label' => 'List tickets'),
            'ticket_details' => array('identity' => true, 'write' => false, 'group' => 'read', 'label' => 'Ticket details'),

            'domain_update_nameservers' => array('identity' => true, 'write' => true, 'group' => 'write', 'label' => 'Change domain nameservers'),
            'domain_set_autorenew' => array('identity' => true, 'write' => true, 'group' => 'write', 'label' => 'Turn domain auto-renew on/off'),
            'ticket_open' => array('identity' => true, 'write' => true, 'group' => 'write', 'label' => 'Open a support ticket'),
            'ticket_reply' => array('identity' => true, 'write' => true, 'group' => 'write', 'label' => 'Reply to a support ticket'),
            'service_cancel_request' => array('identity' => true, 'write' => true, 'group' => 'sensitive', 'label' => 'Submit a cancellation request'),
            'domain_send_epp' => array('identity' => true, 'write' => true, 'group' => 'sensitive', 'label' => 'Email the domain transfer (EPP) code to the client'),
        );
    }

    /** Switched on at activation. Cancellations and transfer codes are left for the admin to decide. */
    public static function defaultEnabled()
    {
        $out = array();
        foreach (self::catalog() as $key => $spec) {
            if ($spec['group'] !== 'sensitive') {
                $out[] = $key;
            }
        }

        return $out;
    }

    public static function run($action, array $args, $clientId)
    {
        $method = 'do_' . $action;
        if (!method_exists(__CLASS__, $method)) {
            throw new ActionError('invalid_args', 'This is not supported.');
        }

        return self::$method($args, $clientId);
    }

    // =========================================================================================
    // Public
    // =========================================================================================

    private static function do_kb_search(array $args, $clientId)
    {
        $query = self::text($args, 'query', 120);
        $words = array_slice(array_filter(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($query)), function ($w) {
            return mb_strlen($w) >= 3;
        }), 0, 6);

        if (!$words) {
            throw new ActionError('invalid_args', 'Ask the visitor what they need help with, in a few words.');
        }

        $q = Capsule::table('tblknowledgebase as kb')->where('kb.parentid', 0);

        if (Capsule::schema()->hasColumn('tblknowledgebase', 'private')) {
            $q->where(function ($w) {
                $w->whereNull('kb.private')->orWhere('kb.private', '!=', 'on');
            });
        }

        // Articles only in hidden categories are not public.
        $hiddenCats = Capsule::table('tblknowledgebasecats')->where('hidden', 'on')->pluck('id');
        if (count($hiddenCats)) {
            $q->whereNotIn('kb.id', function ($sub) use ($hiddenCats) {
                $sub->select('articleid')->from('tblknowledgebaselinks')->whereIn('categoryid', $hiddenCats);
            });
        }

        $q->where(function ($w) use ($words) {
            foreach ($words as $word) {
                $like = '%' . str_replace(array('%', '_'), array('\\%', '\\_'), $word) . '%';
                $w->orWhere('kb.title', 'like', $like)->orWhere('kb.article', 'like', $like);
            }
        });

        $rows = $q->limit(40)->get(array('kb.id', 'kb.title', 'kb.article'));

        $scored = array();
        foreach ($rows as $row) {
            $title = mb_strtolower((string) $row->title);
            $body = mb_strtolower(self::plain($row->article));
            $score = 0;
            foreach ($words as $word) {
                $score += (mb_strpos($title, $word) !== false ? 5 : 0) + min(3, mb_substr_count($body, $word));
            }
            $scored[] = array($score, $row);
        }
        usort($scored, function ($a, $b) {
            return $b[0] - $a[0];
        });

        $lines = array();
        $data = array();
        foreach (array_slice($scored, 0, 3) as $pair) {
            $row = $pair[1];
            $url = self::kbUrl($row->id, $row->title);
            $excerpt = self::cut(self::plain($row->article), 450);
            $lines[] = '"' . $row->title . '" (' . $url . "): " . $excerpt;
            $data[] = array('title' => $row->title, 'url' => $url);
        }

        if (!$lines) {
            return array('summary' => 'No help article matches "' . $query . '".', 'data' => array('articles' => array()));
        }

        return array('summary' => implode("\n", $lines), 'data' => array('articles' => $data));
    }

    private static function do_announcements(array $args, $clientId)
    {
        $r = self::api('GetAnnouncements', array('limitnum' => 10));
        $lines = array();
        foreach (self::rows($r, 'announcements', 'announcement') as $a) {
            if (empty($a['published']) || (!empty($a['parentid']) && (int) $a['parentid'] !== 0)) {
                continue;
            }
            $lines[] = substr((string) $a['date'], 0, 10) . ' — ' . $a['title'] . ': ' . self::cut(self::plain($a['announcement']), 220);
            if (count($lines) >= 5) {
                break;
            }
        }

        return array('summary' => $lines ? implode("\n", $lines) : 'There are no announcements.');
    }

    private static function do_network_status(array $args, $clientId)
    {
        $rows = Capsule::table('tblnetworkissues')
            ->where('status', '!=', 'Resolved')
            ->orderBy('startdate', 'desc')
            ->limit(10)
            ->get();

        if (!count($rows)) {
            return array('summary' => 'No open network issues are reported at the moment.', 'data' => array('open_issues' => 0));
        }

        $lines = array();
        foreach ($rows as $i) {
            $lines[] = '[' . $i->status . ', ' . $i->priority . '] ' . $i->title
                . ' — affecting ' . $i->type . ($i->affecting ? ' ' . $i->affecting : '')
                . '. Started ' . $i->startdate . ', last update ' . $i->lastupdate . '. '
                . self::cut(self::plain($i->description), 250);
        }

        return array('summary' => implode("\n", $lines), 'data' => array('open_issues' => count($lines)));
    }

    private static function do_product_catalog(array $args, $clientId)
    {
        $query = mb_strtolower(self::text($args, 'query', 120));
        $currency = self::defaultCurrency();

        // `retired` only exists on newer WHMCS versions.
        $hasRetired = Capsule::schema()->hasColumn('tblproducts', 'retired');
        $hidden = Capsule::table('tblproducts')->where(function ($w) use ($hasRetired) {
            $w->where('hidden', 1);
            if ($hasRetired) {
                $w->orWhere('retired', 1);
            }
        })->pluck('id');
        $hidden = array_map('intval', is_array($hidden) ? $hidden : $hidden->all());

        $groups = array();
        foreach (Capsule::table('tblproductgroups')->get(array('id', 'name', 'hidden')) as $g) {
            $groups[(int) $g->id] = $g;
        }

        $r = self::api('GetProducts', array());
        $candidates = array();
        foreach (self::rows($r, 'products', 'product') as $p) {
            $group = isset($groups[(int) $p['gid']]) ? $groups[(int) $p['gid']] : null;
            if (in_array((int) $p['pid'], $hidden, true) || ($group && $group->hidden)) {
                continue;
            }

            $price = self::productPrice($p, $currency['code']);
            if ($price === null) {
                continue;
            }

            $haystack = mb_strtolower($p['name'] . ' ' . ($group ? $group->name : '') . ' ' . self::plain($p['description']));
            $score = 0;
            foreach (array_filter(preg_split('/\s+/', $query)) as $word) {
                $score += mb_strpos($haystack, $word) !== false ? 1 : 0;
            }

            $candidates[] = array($score, $p, $group, $price);
        }

        if (!$candidates) {
            return array('summary' => 'No products are currently offered for order.');
        }

        usort($candidates, function ($a, $b) {
            return $b[0] - $a[0];
        });

        $lines = array();
        foreach (array_slice($candidates, 0, $query === '' ? 8 : 4) as $c) {
            list(, $p, $group, $price) = $c;
            $lines[] = ($group ? $group->name . ' — ' : '') . $p['name'] . ': ' . $price
                . '. ' . self::cut(self::plain($p['description']), 200)
                . ' Order: ' . Settings::systemUrl() . 'cart.php?a=add&pid=' . (int) $p['pid'];
        }

        return array('summary' => implode("\n", $lines));
    }

    private static function do_domain_check(array $args, $clientId)
    {
        $domain = self::domainArg($args);
        $r = self::api('DomainWhois', array('domain' => $domain));
        $available = isset($r['status']) && $r['status'] === 'available';

        $tld = substr($domain, strpos($domain, '.'));
        $price = self::tldPrice($tld, 'register');

        if ($available) {
            return array(
                'summary' => $domain . ' is available' . ($price ? ' — register for ' . $price . ' for the first year' : '') . '. Register: ' . Settings::systemUrl() . 'cart.php?a=add&domain=register&query=' . rawurlencode($domain),
                'data' => array('available' => true),
            );
        }

        // Taken: suggest the same name on up to three other extensions that have pricing.
        $label = substr($domain, 0, strpos($domain, '.'));
        $alternatives = array();
        foreach (array('.com', '.net', '.org', '.co', '.io') as $alt) {
            if ($alt === $tld || count($alternatives) >= 3 || !self::tldPrice($alt, 'register')) {
                continue;
            }
            $check = self::api('DomainWhois', array('domain' => $label . $alt), false);
            if (isset($check['status']) && $check['status'] === 'available') {
                $alternatives[] = $label . $alt . ' (' . self::tldPrice($alt, 'register') . ')';
            }
        }

        return array(
            'summary' => $domain . ' is already registered.' . ($alternatives ? ' Available alternatives: ' . implode(', ', $alternatives) . '.' : '')
                . ' If they own it, they can transfer it in: ' . Settings::systemUrl() . 'cart.php?a=add&domain=transfer',
            'data' => array('available' => false),
        );
    }

    private static function do_tld_pricing(array $args, $clientId)
    {
        $tld = '.' . ltrim(strtolower(self::text($args, 'tld', 30)), '.');
        if (!preg_match('/^\.[a-z0-9-]{2,24}(\.[a-z0-9-]{2,24})?$/', $tld)) {
            throw new ActionError('invalid_args', 'Ask which domain extension they mean, for example .com.');
        }

        $parts = array();
        foreach (array('register', 'renew', 'transfer') as $kind) {
            $price = self::tldPrice($tld, $kind);
            if ($price) {
                $parts[] = $kind . ' ' . $price;
            }
        }

        if (!$parts) {
            throw new ActionError('no_results', $tld . ' domains are not offered.');
        }

        return array('summary' => $tld . ' (1 year): ' . implode(', ', $parts) . '.');
    }

    // =========================================================================================
    // Client: read
    // =========================================================================================

    private static function do_client_summary(array $args, $clientId)
    {
        $client = Capsule::table('tblclients')->where('id', $clientId)->first(array('firstname', 'companyname', 'credit', 'currency', 'status'));
        $currency = self::clientCurrency($clientId);

        $services = Capsule::table('tblhosting')->where('userid', $clientId)->select('domainstatus', Capsule::raw('count(*) as n'))->groupBy('domainstatus')->get();
        $domains = Capsule::table('tbldomains')->where('userid', $clientId)->select('status', Capsule::raw('count(*) as n'))->groupBy('status')->get();

        $unpaidCount = (int) Capsule::table('tblinvoices')->where('userid', $clientId)->where('status', 'Unpaid')->count();
        $unpaidTotal = (float) Capsule::table('tblinvoices')->where('userid', $clientId)->where('status', 'Unpaid')->sum('total');

        $tickets = self::api('GetTickets', array('clientid' => $clientId, 'status' => 'All Active Tickets', 'limitnum' => 1));

        $fmt = function ($rows, $col) {
            $out = array();
            foreach ($rows as $r) {
                $out[] = $r->n . ' ' . strtolower($r->$col);
            }

            return $out ? implode(', ', $out) : 'none';
        };

        return array('summary' => 'Account: ' . $client->firstname . ($client->companyname ? ' (' . $client->companyname . ')' : '') . ', status ' . $client->status . '.'
            . ' Services: ' . $fmt($services, 'domainstatus') . '.'
            . ' Domains: ' . $fmt($domains, 'status') . '.'
            . ' Unpaid invoices: ' . $unpaidCount . ($unpaidCount ? ' totalling ' . self::money($unpaidTotal, $currency) : '') . '.'
            . ' Open tickets: ' . (int) ($tickets['totalresults'] ?? 0) . '.'
            . ((float) $client->credit > 0 ? ' Credit balance: ' . self::money($client->credit, $currency) . '.' : '')
            . ' Client area: ' . Settings::systemUrl() . 'clientarea.php');
    }

    private static function do_services_list(array $args, $clientId)
    {
        $r = self::api('GetClientsProducts', array('clientid' => $clientId, 'limitnum' => 50));
        $currency = self::clientCurrency($clientId);

        $lines = array();
        foreach (self::rows($r, 'products', 'product') as $p) {
            $lines[] = '#' . $p['id'] . ' ' . $p['name'] . ($p['domain'] ? ' (' . $p['domain'] . ')' : '')
                . ' — ' . $p['status']
                . ($p['billingcycle'] !== 'Free Account' && $p['billingcycle'] !== 'One Time' ? ', ' . self::money($p['recurringamount'], $currency) . ' ' . strtolower($p['billingcycle']) . ', next due ' . self::date($p['nextduedate']) : '');
        }

        return array('summary' => $lines ? implode("\n", $lines) : 'This client has no services.');
    }

    private static function do_service_details(array $args, $clientId)
    {
        $p = self::ownedService($clientId, $args);
        $currency = self::clientCurrency($clientId);

        $lines = array(
            $p['groupname'] . ' — ' . $p['name'] . ' (#' . $p['id'] . ')' . ($p['domain'] ? ' for ' . $p['domain'] : ''),
            'Status: ' . $p['status'] . ($p['status'] === 'Suspended' && $p['suspensionreason'] ? ' (reason: ' . $p['suspensionreason'] . ')' : ''),
            'Billing: ' . self::money($p['recurringamount'], $currency) . ' ' . strtolower($p['billingcycle']) . ', next due ' . self::date($p['nextduedate']) . ', started ' . self::date($p['regdate']),
        );

        if ($p['serverhostname'] || $p['dedicatedip'] || $p['serverip']) {
            $lines[] = 'Server: ' . ($p['serverhostname'] ?: $p['servername']) . ', IP ' . ($p['dedicatedip'] ?: $p['serverip']);
        }
        if ((int) $p['disklimit'] > 0) {
            $lines[] = 'Disk: ' . $p['diskusage'] . ' MB of ' . $p['disklimit'] . ' MB; bandwidth: ' . $p['bwusage'] . ' MB of ' . ((int) $p['bwlimit'] > 0 ? $p['bwlimit'] . ' MB' : 'unlimited') . ' (as of ' . $p['lastupdate'] . ')';
        }

        $ns = self::serverNameservers((int) $p['serverid']);
        if ($ns) {
            $lines[] = 'To use this hosting, point the domain to these nameservers: ' . implode(', ', $ns);
        }

        if ($p['status'] === 'Suspended') {
            $overdue = Capsule::table('tblinvoices')->where('userid', $clientId)->where('status', 'Unpaid')->where('duedate', '<', date('Y-m-d'))->count();
            if ($overdue) {
                $lines[] = 'The client has ' . $overdue . ' overdue invoice(s); paying them usually lifts an overdue suspension: ' . Settings::systemUrl() . 'clientarea.php?action=invoices';
            }
        }

        $lines[] = 'Manage: ' . Settings::systemUrl() . 'clientarea.php?action=productdetails&id=' . (int) $p['id'];

        return array('summary' => implode("\n", $lines));
    }

    private static function do_domains_list(array $args, $clientId)
    {
        $r = self::api('GetClientsDomains', array('clientid' => $clientId, 'limitnum' => 100));

        $lines = array();
        $soon = strtotime('+30 days');
        foreach (self::rows($r, 'domains', 'domain') as $d) {
            $expires = strtotime((string) $d['expirydate']);
            $flags = array();
            if ($d['status'] === 'Active' && $expires && $expires < $soon) {
                $flags[] = 'expires within 30 days';
            }
            if (!empty($d['donotrenew'])) {
                $flags[] = 'auto-renew OFF';
            }
            $lines[] = $d['domainname'] . ' — ' . $d['status'] . ', expires ' . self::date($d['expirydate']) . ($flags ? ' [' . implode('; ', $flags) . ']' : '');
        }

        return array('summary' => $lines ? implode("\n", $lines) : 'This client has no domains.');
    }

    private static function do_domain_details(array $args, $clientId)
    {
        $d = self::ownedDomain($clientId, $args);

        $lines = array(
            $d['domainname'] . ' — ' . $d['status'] . ', registered ' . self::date($d['regdate']) . ', expires ' . self::date($d['expirydate']) . ', next due ' . self::date($d['nextduedate']),
            'Auto-renew: ' . (!empty($d['donotrenew']) ? 'OFF' : 'on'),
        );

        if ($d['status'] === 'Active' && $d['registrar']) {
            $ns = self::api('DomainGetNameservers', array('domainid' => $d['id']), false);
            if (($ns['result'] ?? '') === 'success') {
                $lines[] = 'Nameservers: ' . implode(', ', self::nsList($ns));
            } else {
                $lines[] = 'Nameservers: could not be read from the registrar just now.';
            }

            $lock = self::api('DomainGetLockingStatus', array('domainid' => $d['id']), false);
            if (($lock['result'] ?? '') === 'success' && isset($lock['lockstatus'])) {
                $lines[] = 'Registrar lock: ' . $lock['lockstatus'];
            }
        }

        $hostingNs = self::defaultNameservers();
        if ($hostingNs) {
            $lines[] = 'Our hosting nameservers are: ' . implode(', ', $hostingNs);
        }

        $lines[] = 'Manage: ' . Settings::systemUrl() . 'clientarea.php?action=domaindetails&id=' . (int) $d['id'];

        return array('summary' => implode("\n", $lines));
    }

    private static function do_invoices_list(array $args, $clientId)
    {
        $lines = array();

        foreach (array(array('Unpaid', 20), array('Paid', 5)) as $pass) {
            $r = self::api('GetInvoices', array('userid' => $clientId, 'status' => $pass[0], 'limitnum' => $pass[1], 'orderby' => 'duedate', 'order' => 'desc'));
            foreach (self::rows($r, 'invoices', 'invoice') as $i) {
                $overdue = $i['status'] === 'Unpaid' && strtotime((string) $i['duedate']) < strtotime('today');
                $lines[] = 'Invoice #' . ($i['invoicenum'] ?: $i['id']) . ' — ' . $i['currencyprefix'] . $i['total'] . $i['currencysuffix']
                    . ', ' . ($overdue ? 'OVERDUE' : $i['status']) . ', due ' . self::date($i['duedate'])
                    . ($i['status'] === 'Unpaid' ? ', pay: ' . Settings::systemUrl() . 'viewinvoice.php?id=' . (int) $i['id'] : '');
            }
        }

        return array('summary' => $lines ? implode("\n", $lines) : 'This client has no invoices.');
    }

    private static function do_invoice_details(array $args, $clientId)
    {
        $id = self::digits($args, 'invoice_id');
        $i = self::api('GetInvoice', array('invoiceid' => $id), false);

        if (($i['result'] ?? '') !== 'success' || (int) ($i['userid'] ?? 0) !== (int) $clientId) {
            throw new ActionError('not_owner', 'No invoice with that number was found on this account.');
        }

        $currency = self::clientCurrency($clientId);
        $items = array();
        foreach (self::rows($i, 'items', 'item') as $item) {
            $items[] = self::cut(self::plain($item['description']), 120) . ' ' . self::money($item['amount'], $currency);
        }

        return array('summary' => 'Invoice #' . ($i['invoicenum'] ?: $i['invoiceid']) . ' — ' . $i['status']
            . ', total ' . self::money($i['total'], $currency) . ', balance ' . self::money($i['balance'], $currency)
            . ', dated ' . self::date($i['date']) . ', due ' . self::date($i['duedate'])
            . ($i['datepaid'] && strpos((string) $i['datepaid'], '0000') !== 0 ? ', paid ' . self::date($i['datepaid']) : '') . ".\n"
            . 'Items: ' . implode('; ', $items) . "\n"
            . 'View or pay: ' . Settings::systemUrl() . 'viewinvoice.php?id=' . (int) $i['invoiceid']);
    }

    private static function do_tickets_list(array $args, $clientId)
    {
        $r = self::api('GetTickets', array('clientid' => $clientId, 'limitnum' => 10));

        $lines = array();
        foreach (self::rows($r, 'tickets', 'ticket') as $t) {
            $lines[] = '#' . $t['tid'] . ' "' . $t['subject'] . '" — ' . $t['status'] . ', last reply ' . $t['lastreply']
                . ', ' . Settings::systemUrl() . 'viewticket.php?tid=' . rawurlencode($t['tid']) . '&c=' . rawurlencode($t['c']);
        }

        return array('summary' => $lines ? implode("\n", $lines) : 'This client has no support tickets.');
    }

    private static function do_ticket_details(array $args, $clientId)
    {
        $t = self::ownedTicket($clientId, $args);

        $lastStaff = null;
        $lastClient = null;
        foreach (self::rows($t, 'replies', 'reply') as $reply) {
            if (!empty($reply['admin'])) {
                $lastStaff = $reply;
            } else {
                $lastClient = $reply;
            }
        }

        $lines = array('#' . $t['tid'] . ' "' . $t['subject'] . '" — ' . $t['status'] . ', department ' . $t['deptname'] . ', opened ' . $t['date'] . ', last reply ' . $t['lastreply']);
        if ($lastStaff) {
            $lines[] = 'Latest staff reply (' . $lastStaff['date'] . '): ' . self::cut(self::plain($lastStaff['message']), 600);
        } else {
            $lines[] = 'No staff reply yet.';
        }
        if ($lastClient && (!$lastStaff || strtotime($lastClient['date']) > strtotime($lastStaff['date']))) {
            $lines[] = 'The client replied last, so it is waiting for the team.';
        }
        $lines[] = 'View: ' . Settings::systemUrl() . 'viewticket.php?tid=' . rawurlencode($t['tid']) . '&c=' . rawurlencode($t['c']);

        return array('summary' => implode("\n", $lines));
    }

    // =========================================================================================
    // Client: changes
    // =========================================================================================

    private static function do_domain_update_nameservers(array $args, $clientId)
    {
        $d = self::ownedDomain($clientId, $args);

        if ($d['status'] !== 'Active' || !$d['registrar']) {
            throw new ActionError('not_allowed', 'Nameservers can only be changed on an active domain registered with us. ' . $d['domainname'] . ' is ' . strtolower($d['status']) . '.');
        }

        $new = array();
        foreach (array('ns1', 'ns2', 'ns3', 'ns4', 'ns5') as $key) {
            if (isset($args[$key]) && $args[$key] !== '') {
                $host = self::hostname($args[$key]);
                if ($host === null) {
                    throw new ActionError('invalid_args', '"' . self::cut((string) $args[$key], 60) . '" is not a valid nameserver hostname.');
                }
                if (!in_array($host, $new, true)) {
                    $new[] = $host;
                }
            }
        }

        if (count($new) < 2) {
            throw new ActionError('invalid_args', 'At least two different nameservers are needed, for example ns1.example.com and ns2.example.com.');
        }

        $old = self::api('DomainGetNameservers', array('domainid' => $d['id']), false);
        $oldList = ($old['result'] ?? '') === 'success' ? self::nsList($old) : array();

        $params = array('domainid' => $d['id']);
        foreach ($new as $i => $host) {
            $params['ns' . ($i + 1)] = $host;
        }

        self::api('DomainUpdateNameservers', $params, true, 'registrar_error');

        $summary = 'Nameservers for ' . $d['domainname'] . ' are now ' . implode(', ', $new) . '.'
            . ($oldList ? ' Previously: ' . implode(', ', $oldList) . '.' : '')
            . ' Changes can take a few hours (occasionally up to 24) to reach everyone.';

        return array(
            'summary' => $summary,
            'audit' => $d['domainname'] . ': ' . ($oldList ? implode(',', $oldList) : '?') . ' -> ' . implode(',', $new),
            'notify' => $summary,
        );
    }

    private static function do_domain_set_autorenew(array $args, $clientId)
    {
        $d = self::ownedDomain($clientId, $args);

        if (!array_key_exists('enabled', $args) || !is_bool($args['enabled'])) {
            throw new ActionError('invalid_args', 'Ask whether they want auto-renew turned on or off.');
        }
        $enabled = $args['enabled'];

        self::api('UpdateClientDomain', array('domainid' => $d['id'], 'donotrenew' => !$enabled));

        $summary = 'Auto-renew for ' . $d['domainname'] . ' is now ' . ($enabled ? 'ON' : 'OFF') . '. It expires ' . self::date($d['expirydate']) . '.'
            . ($enabled ? '' : ' It will not be renewed automatically, so it will expire unless renewed by hand.');

        return array('summary' => $summary, 'audit' => $d['domainname'] . ' autorenew=' . ($enabled ? 'on' : 'off'), 'notify' => $summary);
    }

    private static function do_ticket_open(array $args, $clientId)
    {
        $subject = self::text($args, 'subject', 150);
        $message = self::text($args, 'message', 5000);

        if ($subject === '' || mb_strlen($message) < 10) {
            throw new ActionError('invalid_args', 'A ticket needs a short subject and a description of the problem.');
        }

        $deptId = self::department(self::text($args, 'department', 60));

        $r = self::api('OpenTicket', array(
            'deptid' => $deptId,
            'clientid' => $clientId,
            'subject' => $subject,
            'message' => $message . "\n\n---\nOpened through live chat at the client's request.",
            'priority' => 'Medium',
            'markdown' => true,
        ));

        return array(
            'summary' => 'Ticket #' . $r['tid'] . ' opened: "' . $subject . '". The team will reply by email and in the client area: '
                . Settings::systemUrl() . 'viewticket.php?tid=' . rawurlencode($r['tid']) . '&c=' . rawurlencode($r['c']),
            'audit' => 'ticket #' . $r['tid'],
        );
    }

    private static function do_ticket_reply(array $args, $clientId)
    {
        $t = self::ownedTicket($clientId, $args);
        $message = self::text($args, 'message', 5000);

        if (mb_strlen($message) < 2) {
            throw new ActionError('invalid_args', 'Ask what they want to add to the ticket.');
        }

        self::api('AddTicketReply', array(
            'ticketid' => (int) $t['ticketid'],
            'clientid' => $clientId,
            'message' => $message . "\n\n---\nAdded through live chat.",
            'markdown' => true,
        ));

        return array('summary' => 'The reply was added to ticket #' . $t['tid'] . '.', 'audit' => 'ticket #' . $t['tid']);
    }

    private static function do_service_cancel_request(array $args, $clientId)
    {
        $p = self::ownedService($clientId, $args);

        if (!in_array($p['status'], array('Active', 'Suspended'), true)) {
            throw new ActionError('not_allowed', 'Only an active or suspended service can be cancelled. This one is ' . strtolower($p['status']) . '.');
        }

        $immediate = isset($args['when']) && $args['when'] === 'immediate';
        $type = $immediate ? 'Immediate' : 'End of Billing Period';

        $r = self::api('AddCancelRequest', array('serviceid' => (int) $p['id'], 'type' => $type), false);
        if (($r['result'] ?? '') !== 'success') {
            $msg = (string) ($r['message'] ?? '');
            throw new ActionError('not_allowed', stripos($msg, 'exists') !== false
                ? 'A cancellation request for this service already exists.'
                : 'The cancellation request could not be submitted. The client can do it from the service page in the client area.');
        }

        // The API has no reason field; the cancellation row does, and staff read it.
        $reason = self::text($args, 'reason', 1000);
        Capsule::table('tblcancelrequests')->where('relid', (int) $p['id'])->orderBy('id', 'desc')->limit(1)
            ->update(array('reason' => ($reason !== '' ? $reason : 'No reason given') . ' (requested through live chat)'));

        $summary = 'Cancellation requested for ' . $p['name'] . ($p['domain'] ? ' (' . $p['domain'] . ')' : '') . ' — '
            . ($immediate ? 'immediately' : 'at the end of the billing period (' . self::date($p['nextduedate']) . ')') . '.';

        return array('summary' => $summary, 'audit' => 'service #' . $p['id'] . ' ' . $type, 'notify' => $summary);
    }

    private static function do_domain_send_epp(array $args, $clientId)
    {
        $d = self::ownedDomain($clientId, $args);

        if ($d['status'] !== 'Active' || !$d['registrar']) {
            throw new ActionError('not_allowed', 'A transfer code is only available for an active domain registered with us.');
        }

        $r = self::api('DomainRequestEPP', array('domainid' => $d['id']), true, 'registrar_error');

        if (!empty($r['eppcode'])) {
            // Sent to the email on record, never returned to the chat.
            $code = html_entity_decode((string) $r['eppcode'], ENT_QUOTES, 'UTF-8');
            self::api('SendEmail', array(
                'customtype' => 'domain',
                'id' => (int) $d['id'],
                'customsubject' => 'Transfer code for ' . $d['domainname'],
                'custommessage' => '<p>You asked for the transfer (EPP) code of <strong>' . htmlspecialchars($d['domainname']) . '</strong> in live chat.</p>'
                    . '<p>Code: <code>' . htmlspecialchars($code) . '</code></p>'
                    . '<p>If you did not ask for this, contact us right away.</p>',
            ));
        }

        return array(
            'summary' => 'The transfer code for ' . $d['domainname'] . ' has been sent to the email address on the account. It is never shown in chat.',
            'audit' => $d['domainname'],
        );
    }

    /** Tell the client, by email, that their account was changed through chat. */
    public static function notifyClient($clientId, $text)
    {
        self::api('SendEmail', array(
            'customtype' => 'general',
            'id' => (int) $clientId,
            'customsubject' => 'A change was made to your account through live chat',
            'custommessage' => '<p>' . nl2br(htmlspecialchars((string) $text)) . '</p>'
                . '<p>This was requested and confirmed in live chat while you were signed in. If it was not you, reply to this email or contact us straight away.</p>',
        ), false);
    }

    // =========================================================================================
    // Ownership
    // =========================================================================================

    private static function ownedDomain($clientId, array $args)
    {
        $domain = self::domainArg($args);
        $r = self::api('GetClientsDomains', array('clientid' => $clientId, 'domain' => $domain, 'limitnum' => 5), false);

        foreach (self::rows($r, 'domains', 'domain') as $d) {
            if ((int) $d['userid'] === (int) $clientId && strtolower($d['domainname']) === $domain) {
                return $d;
            }
        }

        throw new ActionError('not_owner', $domain . ' is not a domain on this account.');
    }

    private static function ownedService($clientId, array $args)
    {
        $params = array('clientid' => $clientId, 'limitnum' => 25);

        if (!empty($args['service_id']) && ctype_digit((string) $args['service_id'])) {
            $params['serviceid'] = (int) $args['service_id'];
        } elseif (!empty($args['domain'])) {
            $params['domain'] = self::domainArg($args);
        } else {
            $all = self::rows(self::api('GetClientsProducts', $params), 'products', 'product');
            $live = array_values(array_filter($all, function ($p) {
                return in_array($p['status'], array('Active', 'Suspended'), true);
            }));
            if (count($live) === 1) {
                return $live[0];
            }
            throw new ActionError('invalid_args', 'Ask which service they mean — the domain it is for, or its number from the services list.');
        }

        foreach (self::rows(self::api('GetClientsProducts', $params, false), 'products', 'product') as $p) {
            if ((int) $p['clientid'] === (int) $clientId) {
                return $p;
            }
        }

        throw new ActionError('not_owner', 'That service was not found on this account.');
    }

    private static function ownedTicket($clientId, array $args)
    {
        $tid = isset($args['ticket_id']) ? strtoupper(ltrim(trim((string) $args['ticket_id']), '#')) : '';
        if (!preg_match('/^[A-Z0-9-]{1,32}$/', $tid)) {
            throw new ActionError('invalid_args', 'Ask for the ticket number, as shown in the ticket email or the client area.');
        }

        // By the client-facing number only. The internal id is sequential and would let the chat walk
        // through tickets by counting.
        $t = self::api('GetTicket', array('ticketnum' => $tid), false);

        if (($t['result'] ?? '') !== 'success' || (int) ($t['userid'] ?? 0) !== (int) $clientId) {
            throw new ActionError('not_owner', 'No ticket #' . $tid . ' was found on this account.');
        }

        return $t;
    }

    // =========================================================================================
    // Helpers
    // =========================================================================================

    /**
     * localAPI with errors turned into exceptions.
     *
     * @param bool $throw false to get the raw response back even on error
     */
    public static function api($command, array $params, $throw = true, $errorCode = 'internal')
    {
        $r = localAPI($command, $params);
        if (!is_array($r)) {
            $r = array('result' => 'error', 'message' => 'No response');
        }

        if ($throw && ($r['result'] ?? '') !== 'success') {
            $message = (string) ($r['message'] ?? 'WHMCS error');
            if (function_exists('logModuleCall')) {
                logModuleCall('magizai', $command, $params, $r, '', array());
            }

            // A registrar's refusal ("domain is locked", "invalid nameserver") is meaningful to the
            // client; an internal error is not.
            if ($errorCode === 'registrar_error') {
                throw new ActionError('registrar_error', 'The registrar refused the request: ' . self::cut(strip_tags($message), 200));
            }
            throw new \RuntimeException($command . ': ' . $message);
        }

        return $r;
    }

    /** WHMCS nests lists as {"domains": {"domain": [...]}}; an empty list may be "" or missing. */
    private static function rows($response, $outer, $inner)
    {
        if (!is_array($response) || !isset($response[$outer][$inner]) || !is_array($response[$outer][$inner])) {
            return array();
        }

        return array_values($response[$outer][$inner]);
    }

    private static function text(array $args, $key, $max)
    {
        $value = isset($args[$key]) && is_scalar($args[$key]) ? trim(strip_tags((string) $args[$key])) : '';

        return mb_substr($value, 0, $max);
    }

    private static function digits(array $args, $key)
    {
        $value = isset($args[$key]) ? ltrim(trim((string) $args[$key]), '#') : '';
        if (!ctype_digit($value) || strlen($value) > 10) {
            throw new ActionError('invalid_args', 'Ask for the number.');
        }

        return (int) $value;
    }

    private static function domainArg(array $args)
    {
        $host = isset($args['domain']) ? self::hostname((string) $args['domain']) : null;
        if ($host === null) {
            throw new ActionError('invalid_args', 'Ask for the full domain name, for example example.com.');
        }

        return $host;
    }

    private static function hostname($value)
    {
        $v = strtolower(trim((string) $value));
        $v = preg_replace('#^[a-z]+://#', '', $v);
        $v = explode('/', $v);
        $v = rtrim($v[0], '.');

        if (function_exists('idn_to_ascii') && preg_match('/[^\x20-\x7e]/', $v)) {
            $ascii = idn_to_ascii($v, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            $v = is_string($ascii) ? $ascii : $v;
        }

        return strlen($v) <= 253 && preg_match('/^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $v) ? $v : null;
    }

    private static function nsList(array $r)
    {
        $out = array();
        foreach (array('ns1', 'ns2', 'ns3', 'ns4', 'ns5') as $k) {
            if (!empty($r[$k])) {
                $out[] = strtolower(trim($r[$k]));
            }
        }

        return $out;
    }

    private static function defaultNameservers()
    {
        $out = array();
        for ($i = 1; $i <= 5; $i++) {
            $ns = trim((string) \WHMCS\Config\Setting::getValue('DefaultNameserver' . $i));
            if ($ns !== '') {
                $out[] = strtolower($ns);
            }
        }

        return $out;
    }

    private static function serverNameservers($serverId)
    {
        if ($serverId <= 0) {
            return array();
        }
        $s = Capsule::table('tblservers')->where('id', $serverId)->first();
        if (!$s) {
            return array();
        }
        $out = array();
        for ($i = 1; $i <= 5; $i++) {
            $col = 'nameserver' . $i;
            if (!empty($s->$col)) {
                $out[] = strtolower(trim($s->$col));
            }
        }

        return $out;
    }

    private static function department($name)
    {
        $r = self::api('GetSupportDepartments', array('ignore_dept_assignments' => true));
        $depts = self::rows($r, 'departments', 'department');

        if ($name !== '') {
            foreach ($depts as $d) {
                if (stripos($d['name'], $name) !== false || stripos($name, $d['name']) !== false) {
                    return (int) $d['id'];
                }
            }
        }

        $configured = (int) Settings::get('ticket_department_id');
        foreach ($depts as $d) {
            if ((int) $d['id'] === $configured) {
                return $configured;
            }
        }

        if (!$depts) {
            throw new \RuntimeException('No support departments exist.');
        }

        return (int) $depts[0]['id'];
    }

    private static function defaultCurrency()
    {
        $c = Capsule::table('tblcurrencies')->where('default', 1)->first();

        return $c ? array('id' => (int) $c->id, 'code' => $c->code, 'prefix' => $c->prefix, 'suffix' => $c->suffix)
            : array('id' => 1, 'code' => 'USD', 'prefix' => '$', 'suffix' => '');
    }

    private static function clientCurrency($clientId)
    {
        $id = (int) Capsule::table('tblclients')->where('id', $clientId)->value('currency');
        $c = $id ? Capsule::table('tblcurrencies')->where('id', $id)->first() : null;

        return $c ? array('id' => (int) $c->id, 'code' => $c->code, 'prefix' => $c->prefix, 'suffix' => $c->suffix) : self::defaultCurrency();
    }

    private static function money($amount, array $currency)
    {
        return $currency['prefix'] . number_format((float) $amount, 2) . $currency['suffix'];
    }

    private static function productPrice(array $p, $code)
    {
        if ($p['paytype'] === 'free') {
            return 'free';
        }
        if (!isset($p['pricing'][$code]) || !is_array($p['pricing'][$code])) {
            return null;
        }
        $pr = $p['pricing'][$code];

        if ($p['paytype'] === 'onetime') {
            return (float) $pr['monthly'] >= 0 ? $pr['prefix'] . $pr['monthly'] . $pr['suffix'] . ' one time' : null;
        }

        $cycles = array('monthly' => 'month', 'quarterly' => '3 months', 'semiannually' => '6 months', 'annually' => 'year', 'biennially' => '2 years', 'triennially' => '3 years');
        $parts = array();
        foreach ($cycles as $key => $label) {
            if (isset($pr[$key]) && (float) $pr[$key] >= 0) {
                $parts[] = $pr['prefix'] . $pr[$key] . $pr['suffix'] . '/' . $label;
            }
        }

        return $parts ? implode(' or ', array_slice($parts, 0, 2)) : null;
    }

    private static $tldPricing = null;

    private static function tldPrice($tld, $kind)
    {
        if (self::$tldPricing === null) {
            $currency = self::defaultCurrency();
            $r = self::api('GetTLDPricing', array('currencyid' => $currency['id']), false);
            self::$tldPricing = array(
                'pricing' => isset($r['pricing']) && is_array($r['pricing']) ? $r['pricing'] : array(),
                'currency' => isset($r['currency']) && is_array($r['currency']) ? $r['currency'] : $currency,
            );
        }

        $key = ltrim(strtolower($tld), '.');
        if (!isset(self::$tldPricing['pricing'][$key][$kind]) || !is_array(self::$tldPricing['pricing'][$key][$kind])) {
            return null;
        }
        $years = self::$tldPricing['pricing'][$key][$kind];
        $first = isset($years['1']) ? $years['1'] : reset($years);
        if ($first === false || (float) $first < 0) {
            return null;
        }
        $c = self::$tldPricing['currency'];

        return (isset($c['prefix']) ? $c['prefix'] : '') . $first . (isset($c['suffix']) ? $c['suffix'] : '');
    }

    public static function kbUrl($id, $title)
    {
        $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower((string) $title)), '-');

        return Settings::systemUrl() . 'index.php?rp=/knowledgebase/' . (int) $id . '/' . ($slug !== '' ? $slug : 'article') . '.html';
    }

    public static function plain($html)
    {
        $text = html_entity_decode(strip_tags(preg_replace('#<(br|/p|/li|/h[1-6])[^>]*>#i', "\n", (string) $html)), ENT_QUOTES, 'UTF-8');

        return trim(preg_replace("/[ \t]+/", ' ', preg_replace("/\n{3,}/", "\n\n", $text)));
    }

    private static function cut($text, $max)
    {
        $text = trim(preg_replace('/\s+/', ' ', (string) $text));

        return mb_strlen($text) > $max ? rtrim(mb_substr($text, 0, $max - 1)) . '…' : $text;
    }

    private static function date($value)
    {
        $ts = strtotime((string) $value);

        return $ts && strpos((string) $value, '0000') !== 0 ? date('j M Y', $ts) : 'n/a';
    }
}
