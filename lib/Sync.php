<?php

namespace MagizAI\Whmcs;

use WHMCS\Database\Capsule;

/**
 * Pushes WHMCS's public knowledge into the MagizAI assistant: help articles, announcements, products
 * and prices, and the company facts clients ask about (nameservers, departments, domain prices).
 *
 * Runs from the daily cron and from "Sync now". Only changed documents are sent (content hashes are
 * kept locally), and documents deleted in WHMCS are removed from the assistant — but only after a
 * complete pass, so a half-finished run can never delete what it simply did not reach.
 *
 * Nothing private is synced: no client data, no private or hidden articles, no hidden products.
 */
class Sync
{
    const TIME_BUDGET = 240;

    private $started;
    private $sent = 0;
    private $unchanged = 0;
    private $removed = 0;
    private $errors = array();

    public static function runIfConfigured()
    {
        if (Settings::get('api_token') === '' || Settings::get('widget_key') === '') {
            return null;
        }

        $sync = new self();

        return $sync->run();
    }

    public function run()
    {
        $this->started = time();
        @set_time_limit(self::TIME_BUDGET + 60);

        $collections = array(
            'kb' => array('sync_kb', 'articles', 'index.php?rp=/knowledgebase/'),
            'announcements' => array('sync_announcements', 'announcements', 'index.php?rp=/announcements/'),
            'products' => array('sync_products', 'products', 'cart.php?gid='),
            'facts' => array('sync_company_facts', 'facts', null),
        );

        foreach ($collections as $kind => $def) {
            if (Settings::get($def[0]) !== 'on') {
                continue;
            }

            try {
                $method = $def[1];
                $docs = $this->$method();
            } catch (\Throwable $e) {
                $this->errors[] = $kind . ': ' . $e->getMessage();
                continue;
            }

            $complete = $this->pushAll($kind, $docs);

            if ($complete && $def[2] !== null) {
                $this->prune($kind, Settings::systemUrl() . $def[2], array_keys($docs));
            }
        }

        $result = sprintf('%d sent, %d unchanged, %d removed', $this->sent, $this->unchanged, $this->removed)
            . ($this->errors ? '. Problems: ' . implode(' | ', array_slice($this->errors, 0, 3)) : '');

        Settings::set('last_sync_at', date('Y-m-d H:i:s'));
        Settings::set('last_sync_result', mb_substr($result, 0, 900));

        return $result;
    }

    // ---- collections: source_url => [title, content] --------------------------------------------

    private function articles()
    {
        $q = Capsule::table('tblknowledgebase')->where('parentid', 0);
        if (Capsule::schema()->hasColumn('tblknowledgebase', 'private')) {
            $q->where(function ($w) {
                $w->whereNull('private')->orWhere('private', '!=', 'on');
            });
        }

        $hiddenCats = Capsule::table('tblknowledgebasecats')->where('hidden', 'on')->pluck('id');
        if (count($hiddenCats)) {
            $q->whereNotIn('id', function ($sub) use ($hiddenCats) {
                $sub->select('articleid')->from('tblknowledgebaselinks')->whereIn('categoryid', $hiddenCats);
            });
        }

        $docs = array();
        foreach ($q->orderBy('id')->limit(2000)->get(array('id', 'title', 'article')) as $a) {
            $content = Actions::plain($a->article);
            if (mb_strlen($content) < 20) {
                continue;
            }
            $url = Actions::kbUrl($a->id, $a->title);
            $docs[$url] = array($a->title, $a->title . "\n\n" . mb_substr($content, 0, 60000) . "\n\nFull article: " . $url);
        }

        return $docs;
    }

    private function announcements()
    {
        $r = Actions::api('GetAnnouncements', array('limitnum' => 30));
        $docs = array();
        $list = isset($r['announcements']['announcement']) && is_array($r['announcements']['announcement']) ? $r['announcements']['announcement'] : array();

        foreach ($list as $a) {
            if (empty($a['published']) || (!empty($a['parentid']) && (int) $a['parentid'] !== 0)) {
                continue;
            }
            $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($a['title'])), '-');
            $url = Settings::systemUrl() . 'index.php?rp=/announcements/' . (int) $a['id'] . '/' . ($slug ?: 'announcement') . '.html';
            $docs[$url] = array('Announcement: ' . $a['title'], 'Announcement (' . substr($a['date'], 0, 10) . '): ' . $a['title'] . "\n\n" . mb_substr(Actions::plain($a['announcement']), 0, 20000) . "\n\n" . $url);
        }

        return $docs;
    }

    private function products()
    {
        $currency = Capsule::table('tblcurrencies')->where('default', 1)->first();
        $code = $currency ? $currency->code : 'USD';

        $groups = array();
        foreach (Capsule::table('tblproductgroups')->where('hidden', 0)->orderBy('order')->get(array('id', 'name', 'headline', 'tagline')) as $g) {
            $groups[(int) $g->id] = array('group' => $g, 'lines' => array());
        }

        $hasRetired = Capsule::schema()->hasColumn('tblproducts', 'retired');
        $hidden = Capsule::table('tblproducts')->where(function ($w) use ($hasRetired) {
            $w->where('hidden', 1);
            if ($hasRetired) {
                $w->orWhere('retired', 1);
            }
        })->pluck('id');
        $hidden = array_map('intval', is_array($hidden) ? $hidden : $hidden->all());

        $r = Actions::api('GetProducts', array());
        $list = isset($r['products']['product']) && is_array($r['products']['product']) ? $r['products']['product'] : array();

        foreach ($list as $p) {
            $gid = (int) $p['gid'];
            if (!isset($groups[$gid]) || in_array((int) $p['pid'], $hidden, true)) {
                continue;
            }

            $prices = array();
            if ($p['paytype'] === 'free') {
                $prices[] = 'free';
            } elseif (isset($p['pricing'][$code])) {
                $pr = $p['pricing'][$code];
                foreach (array('monthly' => 'monthly', 'quarterly' => 'every 3 months', 'semiannually' => 'every 6 months', 'annually' => 'yearly', 'biennially' => 'every 2 years', 'triennially' => 'every 3 years') as $k => $label) {
                    if (isset($pr[$k]) && (float) $pr[$k] >= 0) {
                        $prices[] = $pr['prefix'] . $pr[$k] . $pr['suffix'] . ' ' . ($p['paytype'] === 'onetime' ? 'one time' : $label);
                        if ($p['paytype'] === 'onetime') {
                            break;
                        }
                    }
                }
            }

            if (!$prices) {
                continue;
            }

            $groups[$gid]['lines'][] = '## ' . $p['name'] . "\nPrice: " . implode(', ', $prices)
                . "\n" . mb_substr(Actions::plain($p['description']), 0, 3000)
                . "\nOrder: " . Settings::systemUrl() . 'cart.php?a=add&pid=' . (int) $p['pid'];
        }

        $docs = array();
        foreach ($groups as $gid => $g) {
            if (!$g['lines']) {
                continue;
            }
            $url = Settings::systemUrl() . 'cart.php?gid=' . $gid;
            $title = $g['group']->name . ' — plans and prices';
            $intro = trim($g['group']->headline . ' ' . $g['group']->tagline);
            $docs[$url] = array($title, '# ' . $title . "\n" . ($intro !== '' ? $intro . "\n" : '') . "Prices are in " . $code . ".\n\n" . implode("\n\n", $g['lines']) . "\n\nSee all: " . $url);
        }

        return $docs;
    }

    private function facts()
    {
        $system = Settings::systemUrl();
        $company = (string) \WHMCS\Config\Setting::getValue('CompanyName');
        $lines = array('# ' . $company . ' — account and support facts');

        $ns = array();
        for ($i = 1; $i <= 5; $i++) {
            $v = trim((string) \WHMCS\Config\Setting::getValue('DefaultNameserver' . $i));
            if ($v !== '') {
                $ns[] = $v;
            }
        }
        if ($ns) {
            $lines[] = 'Our nameservers (point a domain here to use our hosting): ' . implode(', ', $ns) . '. DNS changes can take a few hours, occasionally up to 24, to spread.';
        }

        $depts = Actions::api('GetSupportDepartments', array('ignore_dept_assignments' => true), false);
        $names = array();
        if (isset($depts['departments']['department']) && is_array($depts['departments']['department'])) {
            foreach ($depts['departments']['department'] as $d) {
                $names[] = $d['name'];
            }
        }
        if ($names) {
            $lines[] = 'Support departments: ' . implode(', ', $names) . '. Open a ticket: ' . $system . 'submitticket.php';
        }

        $lines[] = 'Client area (services, domains, invoices, tickets): ' . $system . 'clientarea.php';
        $lines[] = 'Knowledgebase: ' . $system . 'index.php?rp=/knowledgebase';
        $lines[] = 'Network status: ' . $system . 'serverstatus.php';
        $lines[] = 'Register a domain: ' . $system . 'cart.php?a=add&domain=register — transfer a domain: ' . $system . 'cart.php?a=add&domain=transfer';

        $currency = Capsule::table('tblcurrencies')->where('default', 1)->first();
        $tld = Actions::api('GetTLDPricing', array('currencyid' => $currency ? (int) $currency->id : 1), false);
        if (isset($tld['pricing']) && is_array($tld['pricing'])) {
            $prefix = isset($tld['currency']['prefix']) ? $tld['currency']['prefix'] : '';
            $suffix = isset($tld['currency']['suffix']) ? $tld['currency']['suffix'] : '';
            $rows = array();
            foreach (array_slice($tld['pricing'], 0, 40, true) as $ext => $p) {
                $one = function ($kind) use ($p, $prefix, $suffix) {
                    if (!isset($p[$kind]) || !is_array($p[$kind])) {
                        return '-';
                    }
                    $v = isset($p[$kind]['1']) ? $p[$kind]['1'] : reset($p[$kind]);

                    return $v === false || (float) $v < 0 ? '-' : $prefix . $v . $suffix;
                };
                $rows[] = '.' . $ext . ': register ' . $one('register') . ', renew ' . $one('renew') . ', transfer ' . $one('transfer');
            }
            if ($rows) {
                $lines[] = "Domain prices (1 year):\n" . implode("\n", $rows);
            }
        }

        return array($system . 'index.php?rp=/magizai-facts' => array($company . ' — account and support facts', implode("\n\n", $lines)));
    }

    // ---- transport ------------------------------------------------------------------------------

    private function pushAll($kind, array $docs)
    {
        foreach ($docs as $url => $doc) {
            if (time() - $this->started > self::TIME_BUDGET) {
                $this->errors[] = 'time budget reached; the rest continues on the next run';

                return false;
            }

            $hash = sha1($doc[0] . "\n" . $doc[1]);
            $key = substr($url, 0, 191);
            $known = Capsule::table(Store::SYNC)->where('source_url', $key)->value('hash');

            if ($known === $hash) {
                $this->unchanged++;
                continue;
            }

            list($status, $body) = $this->request('POST', '/knowledge', array(
                'title' => mb_substr($doc[0], 0, 200),
                'content' => $doc[1],
                'source_url' => $url,
                'visibility' => 'public',
            ));

            if ($status === 200 || $status === 201) {
                $this->sent++;
                Capsule::table(Store::SYNC)->updateOrInsert(array('source_url' => $key), array('kind' => $kind, 'hash' => $hash, 'synced_at' => date('Y-m-d H:i:s')));
            } else {
                $this->errors[] = $kind . ' "' . mb_substr($doc[0], 0, 40) . '": HTTP ' . $status . ' ' . mb_substr((string) $body, 0, 120);
                if ($status === 401 || $status === 403 || $status === 404) {
                    return false; // wrong token or widget key — every other document would fail the same way
                }
            }
        }

        return true;
    }

    private function prune($kind, $prefix, array $keep)
    {
        list($status, $body) = $this->request('DELETE', '/knowledge', array('url_prefix' => $prefix, 'keep' => array_values($keep)));

        if ($status === 200) {
            $data = json_decode((string) $body, true);
            $this->removed += isset($data['data']['removed']) ? (int) $data['data']['removed'] : 0;

            $query = Capsule::table(Store::SYNC)->where('kind', $kind);
            if ($keep) {
                $query->whereNotIn('source_url', array_map(function ($u) {
                    return substr($u, 0, 191);
                }, $keep));
            }
            $query->delete();
        } else {
            $this->errors[] = $kind . ' cleanup: HTTP ' . $status;
        }
    }

    private function request($method, $path, array $payload)
    {
        $url = Settings::magizaiBase() . '/api/v1/chatbots/' . rawurlencode(Settings::get('widget_key')) . $path;

        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => array(
                'Authorization: Bearer ' . Settings::get('api_token'),
                'Content-Type: application/json',
                'Accept: application/json',
                'User-Agent: MagizAI-WHMCS/1.0',
            ),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 90,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => false,
        ));
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        return array($status ?: 0, $body === false ? $error : $body);
    }
}
