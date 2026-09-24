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
            'service_upgrade_options' => array('identity' => true, 'write' => false, 'group' => 'read', 'label' => 'What a service can be upgraded to, and what it costs'),
            'addon_catalog' => array('identity' => true, 'write' => false, 'group' => 'read', 'label' => 'Extras that can be added to a service'),
            'client_area_link' => array('identity' => true, 'write' => false, 'group' => 'read', 'label' => 'Link the client straight to the right client-area page'),

            // identity 'optional': a visitor who forgot their password cannot sign in first.
            'password_reset_email' => array('identity' => 'optional', 'write' => false, 'group' => 'read', 'label' => 'Password reset (email a reset link to the signed-in user, or explain how)'),

            'domain_update_nameservers' => array('identity' => true, 'write' => true, 'group' => 'write', 'label' => 'Change domain nameservers'),
            'domain_set_autorenew' => array('identity' => true, 'write' => true, 'group' => 'write', 'label' => 'Turn domain auto-renew on/off'),
            'ticket_open' => array('identity' => true, 'write' => true, 'group' => 'write', 'label' => 'Open a support ticket'),
            'ticket_reply' => array('identity' => true, 'write' => true, 'group' => 'write', 'label' => 'Reply to a support ticket'),
            'service_cancel_request' => array('identity' => true, 'write' => true, 'group' => 'sensitive', 'label' => 'Submit a cancellation request'),
            'order_place' => array('identity' => true, 'write' => true, 'group' => 'sensitive', 'label' => 'Place an order for a plan and domain'),
            'service_upgrade' => array('identity' => true, 'write' => true, 'group' => 'sensitive', 'label' => 'Upgrade or downgrade a service (raises an invoice)'),
            'service_renew' => array('identity' => true, 'write' => true, 'group' => 'sensitive', 'label' => 'Renew a service early (raises an invoice)'),
            'domain_renew' => array('identity' => true, 'write' => true, 'group' => 'sensitive', 'label' => 'Renew a domain (raises an invoice)'),
            'addon_order' => array('identity' => true, 'write' => true, 'group' => 'sensitive', 'label' => 'Add an extra to a service (raises an invoice)'),
            'service_password_reset' => array('identity' => true, 'write' => true, 'group' => 'sensitive', 'label' => 'Reset a service password and email it to the client'),
            'domain_send_epp' => array('identity' => true, 'write' => true, 'group' => 'sensitive', 'label' => 'Email the domain transfer (EPP) code to the client'),

            // Used only by MagizAI's Site Doctor, to check that a website belongs to the signed-in
            // client before its server is inspected. MagizAI never shows this to the assistant or the
            // client: it is an ownership check, not an answer. Switch it off to stop site checks.
            'hosting_accounts' => array('identity' => true, 'write' => false, 'group' => 'read', 'label' => 'Hosting accounts (Site Doctor ownership check)'),
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

    /** Verified identity claims of the current request, for the few actions that need more than the client id. */
    private static $claims = array();

    public static function run($action, array $args, $clientId, array $claims = array())
    {
        $method = 'do_' . $action;
        if (!method_exists(__CLASS__, $method)) {
            throw new ActionError('invalid_args', 'This is not supported.');
        }

        self::$claims = $claims;

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

        // Structured offers as well as text. The text alone could never become plan cards: every
        // billing term but the first two was dropped, the feature list was run together
        // ("Essential features1 Website 15 GB"), and a host with several product groups lost most
        // of its catalogue to an eight-line cap.
        $currencyOut = $clientId ? self::clientCurrency($clientId) : $currency;
        $offers = array();
        $lines = array();
        // `all` is the catalogue sync asking for everything, so plans can be shown as cards.
        $limit = !empty($args['all']) ? 300 : ($query === '' ? 30 : 12);
        foreach (array_slice($candidates, 0, $limit) as $c) {
            list(, $p, $group, $price) = $c;
            $offer = self::offer($p, $group, $currencyOut);
            if ($offer === null) {
                continue;
            }
            $offers[] = $offer;

            $terms = array();
            foreach ($offer['cycles'] as $cy) {
                $terms[] = self::money($cy['price'], $currencyOut) . ' ' . self::cycleLabel($cy['cycle']);
            }
            $lines[] = ($group ? $group->name . ' — ' : '') . $p['name'] . ': ' . implode(', ', $terms)
                . ($offer['features'] ? '. Features: ' . implode('; ', array_slice($offer['features'], 0, 8)) : '')
                . ($offer['stock'] === 'out' ? '. OUT OF STOCK' : '')
                . '. Order: ' . $offer['order_url'];
        }

        return array(
            'summary' => implode("\n", $lines),
            'data' => array('products' => $offers, 'currency' => $currencyOut['code']),
        );
    }

    /** WHMCS billing cycle keys, their length in months, and the setup-fee column that goes with each. */
    private static $CYCLES = array(
        'monthly' => array(1, 'msetupfee'),
        'quarterly' => array(3, 'qsetupfee'),
        'semiannually' => array(6, 'ssetupfee'),
        'annually' => array(12, 'asetupfee'),
        'biennially' => array(24, 'bsetupfee'),
        'triennially' => array(36, 'tsetupfee'),
    );

    private static function cycleLabel($cycle)
    {
        $labels = array('monthly' => 'per month', 'quarterly' => 'per 3 months', 'semiannually' => 'per 6 months',
            'annually' => 'per year', 'biennially' => 'per 2 years', 'triennially' => 'per 3 years',
            'onetime' => 'one time', 'free' => 'free');

        return isset($labels[$cycle]) ? $labels[$cycle] : $cycle;
    }

    /**
     * One product as a plan card: every orderable billing term, a clean feature list, and the cart
     * link for each term. Prices are in the visitor's currency where WHMCS has one for them.
     */
    private static function offer(array $p, $group, array $currency)
    {
        $base = Settings::systemUrl() . 'cart.php?a=add&pid=' . (int) $p['pid'];
        $cycles = array();

        if ($p['paytype'] === 'free') {
            $cycles[] = array('cycle' => 'free', 'months' => 0, 'price' => 0.0, 'setup' => 0.0, 'order_url' => $base);
        } else {
            $pr = isset($p['pricing'][$currency['code']]) && is_array($p['pricing'][$currency['code']]) ? $p['pricing'][$currency['code']] : null;
            if ($pr === null) {
                return null;
            }

            if ($p['paytype'] === 'onetime') {
                if ((float) $pr['monthly'] >= 0) {
                    $cycles[] = array('cycle' => 'onetime', 'months' => 0, 'price' => (float) $pr['monthly'],
                        'setup' => max(0.0, (float) (isset($pr['msetupfee']) ? $pr['msetupfee'] : 0)), 'order_url' => $base);
                }
            } else {
                foreach (self::$CYCLES as $key => $spec) {
                    // WHMCS marks a disabled term with -1.
                    if (!isset($pr[$key]) || (float) $pr[$key] < 0) {
                        continue;
                    }
                    $cycles[] = array(
                        'cycle' => $key,
                        'months' => $spec[0],
                        'price' => (float) $pr[$key],
                        'setup' => max(0.0, (float) (isset($pr[$spec[1]]) ? $pr[$spec[1]] : 0)),
                        'order_url' => $base . '&billingcycle=' . $key,
                    );
                }
            }
        }

        if (!$cycles) {
            return null;
        }

        list($tagline, $features) = self::describe((string) $p['description']);

        $out = null;
        if (!empty($p['stockcontrol']) && isset($p['stocklevel']) && (int) $p['stocklevel'] <= 0) {
            $out = 'out';
        }

        return array(
            'id' => (int) $p['pid'],
            'name' => (string) $p['name'],
            'group' => $group ? (string) $group->name : '',
            'tagline' => $tagline,
            'features' => $features,
            'cycles' => $cycles,
            'currency' => array('code' => $currency['code'], 'prefix' => $currency['prefix'], 'suffix' => $currency['suffix']),
            'order_url' => $base,
            'stock' => $out,
        );
    }

    /**
     * Split a product description into a one-line tagline and its feature list.
     *
     * Descriptions are free HTML. Lists (<li>) are the usual feature format; line breaks and plain
     * new lines are the other. A short line ending in a colon ("Essential features:") is a heading,
     * not a feature.
     *
     * @return array{0: string, 1: array<int, string>}
     */
    private static function describe($html)
    {
        $html = (string) $html;
        $tagline = '';
        $items = array();

        $plain = function ($fragment) {
            return self::plain($fragment);
        };

        if (preg_match_all('/<li[^>]*>(.*?)<\/li>/is', $html, $m)) {
            $items = $m[1];
            $before = preg_split('/<(ul|ol)[^>]*>/i', $html, 2);
            // The text above the list, minus any heading line ("Essential features") that
            // introduces it.
            $intro = array();
            foreach (preg_split('/\n+/', self::plain($before[0])) as $line) {
                $line = trim($line);
                if ($line !== '' && !self::isHeading($line)) {
                    $intro[] = $line;
                }
            }
            $tagline = implode(' ', $intro);
        } else {
            $parts = preg_split('/<br\s*\/?>|<\/p>|<\/div>|\r?\n/i', $html);
            $parts = array_values(array_filter(array_map($plain, $parts), 'strlen'));
            if ($parts && mb_strlen($parts[0]) > 60) {
                $tagline = array_shift($parts);
            }
            $items = $parts;
        }

        $features = array();
        foreach ($items as $item) {
            $text = trim(preg_replace('/\s+/', ' ', self::plain($item)));
            // Dropped: headings, run-on paragraphs, and fragments left behind by a stripped logo
            // image ("Powered by", "Built with").
            if ($text === '' || mb_strlen($text) > 90 || self::isHeading($text)
                || preg_match('/\b(by|with|from|and|or)$/i', $text)) {
                continue;
            }
            $features[] = $text;
        }

        return array(self::cut($tagline, 160), array_slice(array_values(array_unique($features)), 0, 12));
    }

    /** A short line that introduces a list rather than being part of it. */
    private static function isHeading($line)
    {
        $line = trim((string) $line);

        return $line !== '' && (preg_match('/:$/', $line)
            || (mb_strlen($line) <= 40 && preg_match('/^(key |essential |main |plan |all |what\'s |whats )?(features|includes|included|specs|specifications|highlights)$/i', $line)));
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

    private static function do_password_reset_email(array $args, $clientId)
    {
        $signIn = Settings::systemUrl() . 'clientarea.php';

        if (!$clientId) {
            return array('summary' => 'The visitor is not signed in, so no email was sent from the chat. Tell them to open ' . $signIn
                . ', choose the forgotten password option on the sign-in page and enter their email address. WHMCS then emails them a secure link to set a new password.');
        }

        // The person chatting is the USER in the signed identity (WHMCS 8 separates users from client
        // accounts). The reset goes to that user's own login address, and only if they belong to this client.
        $email = strtolower(trim(isset(self::$claims['email']) ? (string) self::$claims['email'] : ''));
        if ($email === '') {
            throw new ActionError('not_allowed', 'The sign-in does not include an email address, so a reset email cannot be sent from the chat. The client can use the forgotten password option on the sign-in page: ' . $signIn);
        }

        $details = self::api('GetClientsDetails', array('clientid' => (int) $clientId), false);
        $users = isset($details['client']['users']['user']) && is_array($details['client']['users']['user']) ? $details['client']['users']['user'] : array();
        $userId = 0;
        foreach ($users as $user) {
            if (strtolower(trim((string) $user['email'])) === $email) {
                $userId = (int) $user['id'];
                break;
            }
        }
        if ($userId === 0) {
            throw new ActionError('not_allowed', 'A reset email could not be sent from the chat for this sign-in. The client can use the forgotten password option on the sign-in page: ' . $signIn);
        }

        // At most three reset emails an hour per client from the chat: enough for a typo, not for spam.
        $recent = (int) Capsule::table(Store::AUDIT)
            ->where('client_id', (int) $clientId)->where('action', 'password_reset_email')->where('outcome', 'ok')
            ->where('created_at', '>=', date('Y-m-d H:i:s', time() - 3600))->count();
        if ($recent >= 3) {
            throw new ActionError('not_allowed', 'Several reset emails were already sent in the last hour. Ask the client to check their inbox and spam folder, or wait a little and try again.');
        }

        self::api('ResetPassword', array('id' => $userId));

        $at = strpos($email, '@');
        $masked = $at > 1 ? substr($email, 0, 1) . str_repeat('*', max(1, $at - 1)) . substr($email, $at) : $email;

        return array(
            'summary' => 'WHMCS has started a password reset for the signed-in user. A link to choose a new password is being emailed to ' . $masked
                . '. It can take a few minutes, and it may land in spam. The password is never set or shown in the chat.',
            'audit' => 'user #' . $userId,
        );
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


    /**
     * Place an order for the signed-in client: a product, a billing term, and a domain.
     *
     * THE BOT NEVER TAKES MONEY. This creates the order and its invoice in WHMCS and hands back the
     * payment link; the client pays on the host's own checkout with the host's own gateway. A chat
     * assistant charging a card is a different risk class entirely, and nothing about ordering
     * hosting needs it.
     *
     * The product, term and price are re-read from WHMCS here rather than trusted from the
     * conversation. The model may have quoted the catalogue five minutes or five days ago, and an
     * order placed against a remembered price is how a customer ends up on a plan that no longer
     * exists at a price we no longer charge.
     *
     * Sensitive by classification, so MagizAI holds it behind a single-use confirmation code the
     * client types in the chat before anything is created.
     */
    private static function do_order_place(array $args, $clientId)
    {
        $domainAction = mb_strtolower(self::text($args, 'domain_action', 20));
        if (!in_array($domainAction, array('register', 'transfer', 'owned'), true)) {
            $domainAction = 'register';
        }

        $domain = mb_strtolower(trim(self::text($args, 'domain', 253)));
        $domain = preg_replace('/^https?:\/\//', '', (string) $domain);
        $domain = rtrim((string) preg_replace('/\/.*$/', '', $domain), '.');

        if ($domain === '' || !preg_match('/^(?=.{4,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i', $domain)) {
            throw new ActionError('invalid_args', 'Ask the client for the domain they want, including the ending — for example example.com.');
        }

        $product = self::orderableProduct($args);
        $cycle = self::orderableCycle($product, $args);

        // Registering: only place the order if the domain is actually free. WHMCS would accept the
        // order either way and leave the client with an invoice for a name somebody else owns.
        if ($domainAction === 'register') {
            $availability = self::api('DomainWhois', array('domain' => $domain), false);
            $status = isset($availability['status']) ? mb_strtolower((string) $availability['status']) : '';
            if ($status === 'unavailable') {
                throw new ActionError('not_allowed', $domain . ' is already registered. Ask the client for a different name, or order with the domain they already own.');
            }
        }

        $paymentMethod = self::orderPaymentMethod($clientId);

        $params = array(
            'clientid' => (int) $clientId,
            'paymentmethod' => $paymentMethod,
            'pid' => array((int) $product['pid']),
            'domain' => array($domain),
            'billingcycle' => array($cycle['cycle']),
        );

        // A client who already owns the domain gets the hosting alone: no registration, no
        // registration fee, no transfer they did not ask for.
        if ($domainAction !== 'owned') {
            $params['domaintype'] = array($domainAction);
            $params['regperiod'] = array(1);
        }

        $r = self::api('AddOrder', $params, false);

        if (($r['result'] ?? '') !== 'success' || empty($r['orderid'])) {
            $message = trim((string) ($r['message'] ?? ''));
            throw new ActionError('not_allowed', $message !== ''
                ? 'The order could not be placed: ' . $message
                : 'The order could not be placed. Offer to pass this to the team.');
        }

        $orderId = (int) $r['orderid'];
        $invoiceId = isset($r['invoiceid']) ? (int) $r['invoiceid'] : 0;
        $payUrl = $invoiceId > 0 ? Settings::systemUrl() . 'viewinvoice.php?id=' . $invoiceId : Settings::systemUrl() . 'clientarea.php?action=orders';

        $price = self::money($cycle['price'], $product['currency']);
        $setup = (float) $cycle['setup'] > 0 ? ' plus ' . self::money($cycle['setup'], $product['currency']) . ' setup' : '';

        $summary = 'Order #' . $orderId . ' is placed: ' . $product['name'] . ' for ' . $domain
            . ' at ' . $price . ' ' . self::cycleLabel($cycle['cycle']) . $setup . '. '
            . ($invoiceId > 0
                ? 'Invoice #' . $invoiceId . ' is ready to pay here: ' . $payUrl . ' Nothing has been charged yet, and the service starts once the invoice is paid.'
                : 'It is waiting in the client area: ' . $payUrl);

        return array(
            'summary' => $summary,
            'audit' => 'order #' . $orderId . ' pid ' . $product['pid'] . ' ' . $domain . ' ' . $cycle['cycle'],
            'notify' => 'A new order (#' . $orderId . ') for ' . $product['name'] . ' with the domain ' . $domain . ' was placed through live chat.',
            'data' => array(
                'order_id' => $orderId,
                'invoice_id' => $invoiceId,
                'pay_url' => $payUrl,
                'domain' => $domain,
                'product' => $product['name'],
                'cycle' => $cycle['cycle'],
            ),
        );
    }

    /**
     * The product being ordered, resolved from WHMCS and checked to be orderable right now.
     *
     * Accepts an id when the model has one from the catalogue, and a name when it does not. A name
     * that matches more than one product is refused rather than guessed: ordering the wrong plan
     * costs the client money and the host a refund.
     */
    private static function orderableProduct(array $args)
    {
        $wanted = self::text($args, 'product', 120);
        if ($wanted === '') {
            throw new ActionError('invalid_args', 'Ask the client which plan they want, then order it by name.');
        }

        $currency = self::defaultCurrency();
        $hasRetired = Capsule::schema()->hasColumn('tblproducts', 'retired');
        $hidden = Capsule::table('tblproducts')->where(function ($w) use ($hasRetired) {
            $w->where('hidden', 1);
            if ($hasRetired) {
                $w->orWhere('retired', 1);
            }
        })->pluck('id');
        $hidden = array_map('intval', is_array($hidden) ? $hidden : $hidden->all());

        $hiddenGroups = array();
        foreach (Capsule::table('tblproductgroups')->get(array('id', 'hidden')) as $g) {
            if ($g->hidden) {
                $hiddenGroups[] = (int) $g->id;
            }
        }

        $wantedId = ctype_digit($wanted) ? (int) $wanted : 0;
        $needle = mb_strtolower($wanted);
        $matches = array();

        foreach (self::rows(self::api('GetProducts', array()), 'products', 'product') as $p) {
            $pid = (int) $p['pid'];
            if (in_array($pid, $hidden, true) || in_array((int) $p['gid'], $hiddenGroups, true)) {
                continue;
            }

            $name = mb_strtolower((string) $p['name']);
            $isMatch = $wantedId > 0 ? ($pid === $wantedId) : ($name === $needle || mb_strpos($name, $needle) !== false);
            if (!$isMatch) {
                continue;
            }

            $price = self::productPrice($p, $currency['code']);
            if ($price === null) {
                continue;
            }

            $p['currency'] = $currency;
            $matches[] = $p;

            if ($wantedId > 0) {
                break;
            }
        }

        if (!$matches) {
            throw new ActionError('invalid_args', 'There is no plan on sale called "' . $wanted . '". Show the client the catalogue and order the one they pick.');
        }

        if (count($matches) > 1) {
            $names = array();
            foreach (array_slice($matches, 0, 5) as $m) {
                $names[] = $m['name'];
            }
            throw new ActionError('invalid_args', 'That matches more than one plan (' . implode(', ', $names) . '). Ask the client which one, then order it by its exact name.');
        }

        return $matches[0];
    }

    /** The billing term being ordered, checked against the terms this product actually offers. */
    private static function orderableCycle(array $product, array $args)
    {
        $offer = self::offer($product, null, $product['currency']);
        if (!$offer || empty($offer['cycles'])) {
            throw new ActionError('not_allowed', $product['name'] . ' cannot be ordered online. Offer to pass this to the team.');
        }

        $wanted = mb_strtolower(self::text($args, 'billing_cycle', 20));
        $aliases = array(
            'month' => 'monthly', 'monthly' => 'monthly', '1' => 'monthly',
            'quarter' => 'quarterly', 'quarterly' => 'quarterly', '3' => 'quarterly',
            'halfyearly' => 'semiannually', 'semiannually' => 'semiannually', '6' => 'semiannually',
            'year' => 'annually', 'yearly' => 'annually', 'annual' => 'annually', 'annually' => 'annually', '12' => 'annually',
            'biennially' => 'biennially', '24' => 'biennially',
            'triennially' => 'triennially', '36' => 'triennially',
            'onetime' => 'onetime', 'free' => 'free',
        );
        $wanted = isset($aliases[$wanted]) ? $aliases[$wanted] : $wanted;

        if ($wanted !== '') {
            foreach ($offer['cycles'] as $c) {
                if ($c['cycle'] === $wanted) {
                    return $c;
                }
            }

            $available = array();
            foreach ($offer['cycles'] as $c) {
                $available[] = self::cycleLabel($c['cycle']);
            }
            throw new ActionError('invalid_args', $product['name'] . ' is not sold ' . self::cycleLabel($wanted)
                . '. It is available ' . implode(', ', $available) . '. Ask the client which they want.');
        }

        // No term asked for: the cheapest commitment is the safe default to confirm back to them.
        return $offer['cycles'][0];
    }

    /**
     * The gateway the invoice should be raised against.
     *
     * The client's own default first, because that is the one they have used before. WHMCS requires
     * a payment method on every order, and an order raised against a gateway the host has switched
     * off produces an invoice nobody can pay.
     */
    private static function orderPaymentMethod($clientId)
    {
        $active = array();
        foreach (Capsule::table('tblpaymentgateways')->where('setting', 'visible')->where('value', 'on')->pluck('gateway') as $g) {
            $active[] = (string) $g;
        }
        $active = array_values(array_unique($active));

        $clientDefault = (string) Capsule::table('tblclients')->where('id', (int) $clientId)->value('defaultgateway');
        if ($clientDefault !== '' && in_array($clientDefault, $active, true)) {
            return $clientDefault;
        }

        if ($active) {
            return $active[0];
        }

        throw new ActionError('not_allowed', 'No payment method is switched on, so an order cannot be raised. Offer to pass this to the team.');
    }


    /*
    |--------------------------------------------------------------------------
    | Growing an account: upgrades, renewals, extras
    |--------------------------------------------------------------------------
    | Every one of these ends in an invoice the client pays on the host's own site. None of them
    | takes a payment, and none of them changes what the client is charged without an invoice
    | saying so first. The prices always come from WHMCS at the moment the action runs, because a
    | figure the assistant mentioned earlier in the conversation is not a price.
    */

    /**
     * What this service could become, and what the change would cost today.
     *
     * The cost is WHMCS's own pro-rata calculation (`UpgradeProduct` with `calconly`), not a
     * subtraction the module invented: an upgrade part-way through a billing period costs the
     * difference for the days that remain, and only WHMCS knows how the host has configured that.
     */
    private static function do_service_upgrade_options(array $args, $clientId)
    {
        $p = self::ownedService($clientId, $args);

        if (!in_array($p['status'], array('Active', 'Suspended'), true)) {
            throw new ActionError('not_allowed', 'Only an active service can be changed. This one is ' . strtolower($p['status']) . '.');
        }

        $targets = self::upgradeTargets($p);
        if (!$targets) {
            throw new ActionError('no_results', 'There is nothing to upgrade ' . $p['name'] . ' to. Offer to pass this to the team, who can quote a move by hand.');
        }

        $currency = self::clientCurrency($clientId);
        $lines = array('Upgrades available for ' . $p['name'] . ($p['domain'] ? ' (' . $p['domain'] . ')' : '') . ', currently ' . self::money($p['recurringamount'], $currency) . ' ' . strtolower($p['billingcycle']) . ':');
        $found = 0;

        foreach ($targets as $t) {
            $quote = self::upgradeQuote((int) $p['id'], (int) $t['pid'], (string) $p['billingcycle']);
            if ($quote === null) {
                continue;
            }

            $found++;
            $lines[] = '- ' . $t['name'] . ': ' . self::money($quote, $currency) . ' to change now, then '
                . self::money($t['recurring'], $currency) . ' ' . strtolower($p['billingcycle']);
        }

        if (!$found) {
            throw new ActionError('no_results', 'No upgrade could be priced for this service right now. Offer to pass it to the team.');
        }

        $lines[] = 'Ask which one they want, then place the upgrade. An invoice is raised and the change happens once it is paid.';

        return array('summary' => implode("\n", $lines));
    }

    /**
     * Move a service to another plan. WHMCS raises the invoice; the change applies when it is paid.
     *
     * Deliberately allows a DOWNGRADE too, because refusing one only sends an unhappy client to
     * the cancellation form. The confirmation MagizAI shows says which way it is going.
     */
    private static function do_service_upgrade(array $args, $clientId)
    {
        $p = self::ownedService($clientId, $args);

        if (!in_array($p['status'], array('Active', 'Suspended'), true)) {
            throw new ActionError('not_allowed', 'Only an active service can be changed. This one is ' . strtolower($p['status']) . '.');
        }

        $wanted = self::text($args, 'product', 120);
        if ($wanted === '') {
            throw new ActionError('invalid_args', 'Ask which plan they want to move to, then use its exact name.');
        }

        $target = self::matchOne(self::upgradeTargets($p), $wanted, 'plan');
        $cycle = (string) $p['billingcycle'];

        // Priced again here, immediately before ordering it, so the invoice can never disagree with
        // the figure the client just agreed to.
        $quote = self::upgradeQuote((int) $p['id'], (int) $target['pid'], $cycle);
        if ($quote === null) {
            throw new ActionError('not_allowed', 'That change could not be priced, so it has not been ordered. Offer to pass this to the team.');
        }

        $r = self::api('UpgradeProduct', array(
            'serviceid' => (int) $p['id'],
            'type' => 'product',
            'newproductid' => (int) $target['pid'],
            'newproductbillingcycle' => $cycle,
            'paymentmethod' => self::orderPaymentMethod($clientId),
        ), false);

        if (($r['result'] ?? '') !== 'success') {
            $message = trim((string) ($r['message'] ?? ''));
            throw new ActionError('not_allowed', $message !== ''
                ? 'The change could not be made: ' . $message
                : 'The change could not be made. Offer to pass this to the team.');
        }

        $currency = self::clientCurrency($clientId);
        $invoiceId = isset($r['invoiceid']) ? (int) $r['invoiceid'] : 0;
        $direction = (float) $target['recurring'] >= (float) $p['recurringamount'] ? 'Upgrade' : 'Downgrade';

        $summary = $direction . ' ordered: ' . $p['name'] . ' becomes ' . $target['name']
            . '. ' . self::money($quote, $currency) . ' to change now, then ' . self::money($target['recurring'], $currency) . ' ' . strtolower($cycle) . '.'
            . ($invoiceId > 0
                ? ' Invoice #' . $invoiceId . ' is ready to pay: ' . self::invoiceUrl($invoiceId) . ' Nothing has been charged yet, and the change applies once it is paid.'
                : ' It is waiting in the client area: ' . Settings::systemUrl() . 'clientarea.php');

        return array(
            'summary' => $summary,
            'audit' => 'service #' . $p['id'] . ' -> pid ' . $target['pid'],
            'notify' => $direction . ' ordered for ' . $p['name'] . ' to ' . $target['name'] . ' through live chat. It applies once the invoice is paid.',
            'data' => array('invoice_id' => $invoiceId, 'pay_url' => $invoiceId > 0 ? self::invoiceUrl($invoiceId) : ''),
        );
    }

    /** Renew a service before it is due. WHMCS raises the invoice; paying it moves the due date. */
    private static function do_service_renew(array $args, $clientId)
    {
        $p = self::ownedService($clientId, $args);

        if (!in_array($p['status'], array('Active', 'Suspended'), true)) {
            throw new ActionError('not_allowed', 'Only an active service can be renewed. This one is ' . strtolower($p['status']) . '.');
        }

        if (in_array(strtolower((string) $p['billingcycle']), array('free account', 'one time', 'onetime'), true)) {
            throw new ActionError('not_allowed', $p['name'] . ' is not on a recurring term, so there is nothing to renew.');
        }

        // An unpaid renewal invoice already exists more often than not — raising a second one is how
        // a client ends up paying twice for the same period.
        $existing = self::unpaidInvoiceFor($clientId, (string) $p['domain'], (string) $p['name']);
        if ($existing) {
            throw new ActionError('not_allowed', 'There is already an unpaid invoice (#' . $existing . ') covering this service: '
                . self::invoiceUrl($existing) . ' Point them at it rather than raising another.');
        }

        $r = self::api('AddOrder', array(
            'clientid' => (int) $clientId,
            'paymentmethod' => self::orderPaymentMethod($clientId),
            'servicerenewals' => array((int) $p['id']),
        ), false);

        return self::renewalResult($r, $p['name'] . ($p['domain'] ? ' (' . $p['domain'] . ')' : ''), 'service #' . $p['id']);
    }

    /** Renew a domain for a whole number of years. */
    private static function do_domain_renew(array $args, $clientId)
    {
        $d = self::ownedDomain($clientId, $args);

        if (!in_array($d['status'], array('Active', 'Expired', 'Grace'), true)) {
            throw new ActionError('not_allowed', $d['domainname'] . ' is ' . strtolower($d['status']) . ', so it cannot be renewed from here. Offer to pass this to the team.');
        }

        $years = self::text($args, 'years', 2);
        $years = ctype_digit($years) ? (int) $years : 1;
        if ($years < 1 || $years > 10) {
            $years = 1;
        }

        $existing = self::unpaidInvoiceFor($clientId, (string) $d['domainname'], 'renew');
        if ($existing) {
            throw new ActionError('not_allowed', 'There is already an unpaid invoice (#' . $existing . ') for this domain: '
                . self::invoiceUrl($existing) . ' Point them at it rather than raising another.');
        }

        $r = self::api('AddOrder', array(
            'clientid' => (int) $clientId,
            'paymentmethod' => self::orderPaymentMethod($clientId),
            'domainrenewals' => array($d['domainname'] => $years),
        ), false);

        return self::renewalResult($r, $d['domainname'] . ' for ' . $years . ' year' . ($years > 1 ? 's' : '')
            . ' (expires ' . self::date($d['expirydate']) . ')', 'domain ' . $d['domainname'] . ' x' . $years);
    }

    /** The extras the host sells alongside this service, at the prices WHMCS holds now. */
    private static function do_addon_catalog(array $args, $clientId)
    {
        $p = self::ownedService($clientId, $args);
        $currency = self::clientCurrency($clientId);
        $addons = self::addonsFor((int) $p['pid'], $currency);

        if (!$addons) {
            throw new ActionError('no_results', 'There are no extras on sale for ' . $p['name'] . '.');
        }

        $lines = array('Extras available for ' . $p['name'] . ($p['domain'] ? ' (' . $p['domain'] . ')' : '') . ':');
        foreach ($addons as $a) {
            $lines[] = '- ' . $a['name'] . ': ' . self::money($a['price'], $currency) . ' ' . self::cycleLabel($a['cycle'])
                . ((float) $a['setup'] > 0 ? ' plus ' . self::money($a['setup'], $currency) . ' setup' : '')
                . ($a['description'] !== '' ? ' — ' . self::cut($a['description'], 140) : '');
        }
        $lines[] = 'Ask which one they want, then add it. An invoice is raised and the extra is set up once it is paid.';

        return array('summary' => implode("\n", $lines));
    }

    /** Add one extra to a service. */
    private static function do_addon_order(array $args, $clientId)
    {
        $p = self::ownedService($clientId, $args);

        if ($p['status'] !== 'Active') {
            throw new ActionError('not_allowed', 'An extra can only be added to an active service. This one is ' . strtolower($p['status']) . '.');
        }

        $wanted = self::text($args, 'addon', 120);
        if ($wanted === '') {
            throw new ActionError('invalid_args', 'Ask which extra they want, then add it by its exact name.');
        }

        $currency = self::clientCurrency($clientId);
        $addon = self::matchOne(self::addonsFor((int) $p['pid'], $currency), $wanted, 'extra');

        $r = self::api('AddOrder', array(
            'clientid' => (int) $clientId,
            'paymentmethod' => self::orderPaymentMethod($clientId),
            'serviceid' => (int) $p['id'],
            'addonid' => (int) $addon['id'],
        ), false);

        if (($r['result'] ?? '') !== 'success' || empty($r['orderid'])) {
            $message = trim((string) ($r['message'] ?? ''));
            throw new ActionError('not_allowed', $message !== ''
                ? 'The extra could not be added: ' . $message
                : 'The extra could not be added. Offer to pass this to the team.');
        }

        $invoiceId = isset($r['invoiceid']) ? (int) $r['invoiceid'] : 0;
        $summary = $addon['name'] . ' added to ' . $p['name'] . ' at ' . self::money($addon['price'], $currency) . ' ' . self::cycleLabel($addon['cycle']) . '. '
            . ($invoiceId > 0
                ? 'Invoice #' . $invoiceId . ' is ready to pay: ' . self::invoiceUrl($invoiceId) . ' Nothing has been charged yet, and it is set up once the invoice is paid.'
                : 'It is waiting in the client area: ' . Settings::systemUrl() . 'clientarea.php');

        return array(
            'summary' => $summary,
            'audit' => 'addon ' . $addon['id'] . ' on service #' . $p['id'],
            'notify' => $addon['name'] . ' was added to ' . $p['name'] . ' through live chat. It is set up once the invoice is paid.',
            'data' => array('invoice_id' => $invoiceId, 'pay_url' => $invoiceId > 0 ? self::invoiceUrl($invoiceId) : ''),
        );
    }

    /**
     * Reset the password on a hosting account.
     *
     * THE PASSWORD IS NEVER IN THE CHAT. It is generated here, set on the server, and emailed to
     * the address on the account — the same rule as the domain transfer code. A password typed or
     * shown in a conversation is stored in the transcript, visible to every agent who opens it, and
     * still there a year later.
     */
    private static function do_service_password_reset(array $args, $clientId)
    {
        $p = self::ownedService($clientId, $args);

        if ($p['status'] !== 'Active') {
            throw new ActionError('not_allowed', 'The password can only be reset on an active service. This one is ' . strtolower($p['status']) . '.');
        }

        $password = self::strongPassword();

        $r = self::api('ModuleChangePw', array(
            'serviceid' => (int) $p['id'],
            'servicepassword' => $password,
        ), false);

        if (($r['result'] ?? '') !== 'success') {
            $message = trim((string) ($r['message'] ?? ''));
            throw new ActionError('registrar_error', 'The server refused the password change'
                . ($message !== '' ? ': ' . self::cut(strip_tags($message), 160) : '.')
                . ' Nothing was changed. Offer to pass this to the team.');
        }

        self::api('SendEmail', array(
            'customtype' => 'general',
            'id' => (int) $clientId,
            'customsubject' => 'Your new hosting password',
            'custommessage' => '<p>The password for <strong>' . htmlspecialchars((string) $p['name'], ENT_QUOTES, 'UTF-8')
                . ($p['domain'] ? ' (' . htmlspecialchars((string) $p['domain'], ENT_QUOTES, 'UTF-8') . ')' : '')
                . '</strong> has been reset, as you asked in live chat.</p>'
                . '<p>Username: <strong>' . htmlspecialchars((string) $p['username'], ENT_QUOTES, 'UTF-8') . '</strong><br>'
                . 'New password: <strong>' . htmlspecialchars($password, ENT_QUOTES, 'UTF-8') . '</strong></p>'
                . '<p>Sign in and change it to something you will remember. If you did not ask for this, contact us straight away.</p>',
        ), false);

        return array(
            'summary' => 'The password for ' . $p['name'] . ($p['domain'] ? ' (' . $p['domain'] . ')' : '')
                . ' has been reset and the new one emailed to the address on the account. It is never shown in this chat.'
                . ' Tell them to check their email, including the spam folder.',
            'audit' => 'service #' . $p['id'] . ' password reset',
            // No notify: the email above already says it, and a second one saying the same thing
            // without the password reads like a duplicate.
        );
    }

    /**
     * A direct link to the right page of the client area.
     *
     * NOT single sign-on. WHMCS can mint an auto-login token, and it is the wrong tool here: it
     * grants full access to the account, skips two-factor authentication, and anything put in a
     * chat is visible to whoever is watching that chat. This action is only reached when the client
     * is already signed in — that is what the identity token means — so a plain link does the same
     * job with none of that.
     */
    private static function do_client_area_link(array $args, $clientId)
    {
        $base = Settings::systemUrl();
        $where = mb_strtolower(self::text($args, 'destination', 40));

        $destinations = array(
            'invoices' => array('clientarea.php?action=invoices', 'your invoices'),
            'services' => array('clientarea.php?action=services', 'your services'),
            'domains' => array('clientarea.php?action=domains', 'your domains'),
            'tickets' => array('supporttickets.php', 'your support tickets'),
            'details' => array('clientarea.php?action=details', 'your account details'),
            'security' => array('clientarea.php?action=security', 'your security settings, including two-factor'),
            'emails' => array('clientarea.php?action=emails', 'the emails we have sent you'),
            'store' => array('cart.php', 'the store'),
            'affiliate' => array('affiliates.php', 'your affiliate account'),
            'home' => array('clientarea.php', 'your client area'),
        );

        if (!isset($destinations[$where])) {
            $where = 'home';
        }

        // A service or invoice the client named goes straight to that page, which is the whole
        // point — but only after the usual ownership check.
        if ($where === 'services' && (!empty($args['service_id']) || !empty($args['domain']))) {
            $p = self::ownedService($clientId, $args);

            return array('summary' => 'Open ' . $p['name'] . ' here: ' . $base . 'clientarea.php?action=productdetails&id=' . (int) $p['id']);
        }

        if ($where === 'domains' && !empty($args['domain'])) {
            $d = self::ownedDomain($clientId, $args);

            return array('summary' => 'Manage ' . $d['domainname'] . ' here: ' . $base . 'clientarea.php?action=domaindetails&domainid=' . (int) $d['id']);
        }

        return array('summary' => 'Open ' . $destinations[$where][1] . ' here: ' . $base . $destinations[$where][0]);
    }

    // ---- helpers for the actions above -------------------------------------------------------

    /**
     * The plans a service could move to: everything on sale in the same product group, minus itself.
     *
     * The group is the host's own grouping of comparable plans, which is a better answer than any
     * rule the module could invent about what counts as an upgrade.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function upgradeTargets(array $service)
    {
        $gid = (int) Capsule::table('tblproducts')->where('id', (int) $service['pid'])->value('gid');
        if ($gid <= 0) {
            return array();
        }

        if ((int) Capsule::table('tblproductgroups')->where('id', $gid)->value('hidden') === 1) {
            return array();
        }

        $currency = self::defaultCurrency();
        $cycle = self::cycleKey((string) $service['billingcycle']);
        $out = array();

        foreach (self::rows(self::api('GetProducts', array('gid' => $gid)), 'products', 'product') as $p) {
            if ((int) $p['pid'] === (int) $service['pid']) {
                continue;
            }
            if (self::isOffSale((int) $p['pid'])) {
                continue;
            }

            // Only a plan sold on the SAME term can be moved to without also changing the term,
            // and a silent term change is how a monthly client ends up with a yearly invoice.
            $pricing = isset($p['pricing'][$currency['code']]) && is_array($p['pricing'][$currency['code']]) ? $p['pricing'][$currency['code']] : null;
            if ($pricing === null || $cycle === null || !isset($pricing[$cycle]) || (float) $pricing[$cycle] < 0) {
                continue;
            }

            $out[] = array('pid' => (int) $p['pid'], 'name' => (string) $p['name'], 'recurring' => (float) $pricing[$cycle]);
        }

        return $out;
    }

    /** WHMCS's own pro-rata figure for a change, or null when it cannot price it. */
    private static function upgradeQuote($serviceId, $newPid, $cycle)
    {
        $r = self::api('UpgradeProduct', array(
            'serviceid' => (int) $serviceId,
            'type' => 'product',
            'newproductid' => (int) $newPid,
            'newproductbillingcycle' => $cycle,
            'paymentmethod' => 'banktransfer',   // ignored by a calculation, required by the API
            'calconly' => true,
        ), false);

        if (($r['result'] ?? '') !== 'success' || !isset($r['price'])) {
            return null;
        }

        return (float) $r['price'];
    }

    /** Is this product hidden, retired, or in a hidden group? */
    private static function isOffSale($pid)
    {
        $row = Capsule::table('tblproducts')->where('id', (int) $pid)->first();
        if (!$row) {
            return true;
        }

        if ((int) $row->hidden === 1) {
            return true;
        }

        if (Capsule::schema()->hasColumn('tblproducts', 'retired') && (int) $row->retired === 1) {
            return true;
        }

        return (int) Capsule::table('tblproductgroups')->where('id', (int) $row->gid)->value('hidden') === 1;
    }

    /** The pricing column for a WHMCS billing cycle name ("Monthly" -> "monthly"), or null. */
    private static function cycleKey($billingCycle)
    {
        $map = array(
            'monthly' => 'monthly', 'quarterly' => 'quarterly', 'semi-annually' => 'semiannually',
            'semiannually' => 'semiannually', 'annually' => 'annually', 'biennially' => 'biennially',
            'triennially' => 'triennially',
        );
        $key = mb_strtolower(trim((string) $billingCycle));

        return isset($map[$key]) ? $map[$key] : null;
    }

    /**
     * The extras on sale for one product.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function addonsFor($pid, array $currency)
    {
        $out = array();

        foreach (Capsule::table('tbladdons')->where('hidden', 0)->get() as $a) {
            // `packages` is a comma list of the products an addon belongs to; empty means all.
            $packages = array_filter(array_map('intval', explode(',', (string) $a->packages)));
            if ($packages && !in_array((int) $pid, $packages, true)) {
                continue;
            }

            $pricing = Capsule::table('tblpricing')
                ->where('type', 'addon')->where('relid', (int) $a->id)->where('currency', (int) $currency['id'])
                ->first();
            if (!$pricing) {
                continue;
            }

            $cycle = self::addonCycle($a, $pricing);
            if ($cycle === null) {
                continue;
            }

            $out[] = array(
                'id' => (int) $a->id,
                'name' => (string) $a->name,
                'description' => self::plain((string) $a->description),
                'price' => (float) $pricing->{$cycle[0]},
                'setup' => max(0.0, (float) $pricing->{$cycle[1]}),
                'cycle' => $cycle[2],
            );
        }

        return $out;
    }

    /**
     * The term an addon is sold on: the one its product says, falling back to the cheapest
     * enabled term. WHMCS marks a term it does not sell with -1.
     *
     * @return array{0: string, 1: string, 2: string}|null [price column, setup column, label]
     */
    private static function addonCycle($addon, $pricing)
    {
        $columns = array(
            'monthly' => array('monthly', 'msetupfee'),
            'quarterly' => array('quarterly', 'qsetupfee'),
            'semiannually' => array('semiannually', 'ssetupfee'),
            'annually' => array('annually', 'asetupfee'),
            'biennially' => array('biennially', 'bsetupfee'),
            'triennially' => array('triennially', 'tsetupfee'),
            'onetime' => array('monthly', 'msetupfee'),
        );

        $preferred = mb_strtolower(str_replace(array(' ', '-'), '', (string) $addon->billingcycle));
        if (isset($columns[$preferred]) && (float) $pricing->{$columns[$preferred][0]} >= 0) {
            return array($columns[$preferred][0], $columns[$preferred][1], $preferred);
        }

        foreach ($columns as $name => $pair) {
            if ($name !== 'onetime' && (float) $pricing->{$pair[0]} >= 0) {
                return array($pair[0], $pair[1], $name);
            }
        }

        return null;
    }

    /**
     * One match from a list of {name}, or a refusal that asks rather than guesses.
     *
     * @param  array<int, array<string, mixed>>  $candidates
     * @return array<string, mixed>
     */
    private static function matchOne(array $candidates, $wanted, $noun)
    {
        $needle = mb_strtolower(trim((string) $wanted));
        $exact = array();
        $partial = array();

        foreach ($candidates as $c) {
            $name = mb_strtolower((string) $c['name']);
            if ($name === $needle) {
                $exact[] = $c;
            } elseif ($needle !== '' && mb_strpos($name, $needle) !== false) {
                $partial[] = $c;
            }
        }

        $matches = $exact ?: $partial;

        if (!$matches) {
            $names = array();
            foreach (array_slice($candidates, 0, 6) as $c) {
                $names[] = $c['name'];
            }

            throw new ActionError('invalid_args', 'There is no ' . $noun . ' called "' . self::cut($wanted, 60) . '" here.'
                . ($names ? ' The choices are: ' . implode(', ', $names) . '.' : '') . ' Ask which one they mean.');
        }

        if (count($matches) > 1) {
            $names = array();
            foreach (array_slice($matches, 0, 5) as $m) {
                $names[] = $m['name'];
            }

            throw new ActionError('invalid_args', 'That matches more than one ' . $noun . ' (' . implode(', ', $names) . '). Ask which one, then use its exact name.');
        }

        return $matches[0];
    }

    /** An unpaid invoice that already covers this thing, so a second one is not raised for it. */
    private static function unpaidInvoiceFor($clientId, $needle, $alsoNeedle)
    {
        $needle = trim((string) $needle);
        if ($needle === '') {
            return 0;
        }

        $ids = Capsule::table('tblinvoices')->where('userid', (int) $clientId)
            ->whereIn('status', array('Unpaid', 'Payment Pending'))->pluck('id');
        $ids = array_map('intval', is_array($ids) ? $ids : $ids->all());
        if (!$ids) {
            return 0;
        }

        $match = Capsule::table('tblinvoiceitems')
            ->whereIn('invoiceid', $ids)
            ->where('description', 'like', '%' . str_replace(array('%', '_'), array('\%', '\_'), $needle) . '%')
            ->orderBy('invoiceid', 'desc')
            ->value('invoiceid');

        return (int) $match;
    }

    /** The shared tail of the two renewal actions. */
    private static function renewalResult($r, $what, $audit)
    {
        if (($r['result'] ?? '') !== 'success' || empty($r['orderid'])) {
            $message = trim((string) ($r['message'] ?? ''));
            throw new ActionError('not_allowed', $message !== ''
                ? 'The renewal could not be raised: ' . $message
                : 'The renewal could not be raised. Offer to pass this to the team.');
        }

        $invoiceId = isset($r['invoiceid']) ? (int) $r['invoiceid'] : 0;

        $summary = 'Renewal ordered for ' . $what . '. '
            . ($invoiceId > 0
                ? 'Invoice #' . $invoiceId . ' is ready to pay: ' . self::invoiceUrl($invoiceId) . ' Nothing has been charged yet, and the renewal applies once it is paid.'
                : 'It is waiting in the client area: ' . Settings::systemUrl() . 'clientarea.php');

        return array(
            'summary' => $summary,
            'audit' => $audit,
            'notify' => 'A renewal for ' . $what . ' was ordered through live chat. It applies once the invoice is paid.',
            'data' => array('invoice_id' => $invoiceId, 'pay_url' => $invoiceId > 0 ? self::invoiceUrl($invoiceId) : ''),
        );
    }

    private static function invoiceUrl($invoiceId)
    {
        return Settings::systemUrl() . 'viewinvoice.php?id=' . (int) $invoiceId;
    }

    /** A password strong enough for a hosting account, from a source that is actually random. */
    private static function strongPassword()
    {
        $alphabet = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789!@#%^*-_=+';
        $max = strlen($alphabet) - 1;
        $out = '';

        for ($i = 0; $i < 20; $i++) {
            $out .= $alphabet[random_int(0, $max)];
        }

        return $out;
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

    /**
     * The client's own hosting accounts: which domain, which system user, which server.
     *
     * Only what an ownership check needs. No passwords, no disk usage, no billing.
     */
    private static function do_hosting_accounts(array $args, $clientId)
    {
        $rows = self::rows(self::api('GetClientsProducts', array('clientid' => (int) $clientId, 'limitnum' => 100)), 'products', 'product');
        $accounts = array();

        foreach ($rows as $p) {
            if ((int) $p['clientid'] !== (int) $clientId || !in_array($p['status'], array('Active', 'Suspended'), true)) {
                continue;
            }
            $domain = isset($p['domain']) ? strtolower(trim((string) $p['domain'])) : '';
            $username = isset($p['username']) ? trim((string) $p['username']) : '';
            if ($domain === '' && $username === '') {
                continue;
            }
            $accounts[] = array(
                'domain' => $domain,
                'username' => $username,
                'server_hostname' => isset($p['serverhostname']) ? strtolower(trim((string) $p['serverhostname'])) : '',
                'server_name' => isset($p['servername']) ? (string) $p['servername'] : '',
                'status' => (string) $p['status'],
                'product' => isset($p['name']) ? (string) $p['name'] : '',
            );
            if (count($accounts) >= 50) {
                break;
            }
        }

        return array(
            'summary' => count($accounts) . ' hosting account(s) on this client.',
            'data' => array('accounts' => $accounts),
        );
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
