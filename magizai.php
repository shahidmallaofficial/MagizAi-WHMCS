<?php
/**
 * MagizAI Live Chat for WHMCS.
 *
 * An AI live-chat assistant that knows your WHMCS: it answers from your knowledgebase, products and
 * prices, and — for clients signed in to the client area — checks their services, domains, invoices
 * and tickets, and can change nameservers, toggle auto-renew or open tickets after the client
 * confirms in chat.
 */

use MagizAI\Whmcs\Actions;
use MagizAI\Whmcs\Crypto;
use MagizAI\Whmcs\Settings;
use MagizAI\Whmcs\Store;
use MagizAI\Whmcs\Sync;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/lib/autoload.php';

function magizai_config()
{
    return array(
        'name' => 'MagizAI Live Chat',
        'description' => 'AI live chat that knows your WHMCS: answers from your knowledgebase and prices, and helps signed-in clients with their services, domains, invoices and tickets.',
        'version' => '1.0.0',
        'author' => 'MagizAI',
        'language' => 'english',
        'fields' => array(),
    );
}

function magizai_activate()
{
    try {
        Store::install();

        // Only settings never stored before get their default: an admin who switched something off
        // and re-activates the module keeps it off.
        $stored = \WHMCS\Database\Capsule::table('tbladdonmodules')->where('module', Settings::MODULE)->pluck('setting');
        $stored = is_array($stored) ? $stored : $stored->all();
        foreach (Settings::defaults() as $key => $value) {
            if (!in_array($key, $stored, true)) {
                Settings::set($key, $value);
            }
        }

        if (Settings::get('bridge_secret') === '') {
            Settings::set('bridge_secret', Crypto::newBridgeSecret());
        }

        return array('status' => 'success', 'description' => 'MagizAI is active. Open Addons → MagizAI Live Chat to finish setup.');
    } catch (\Throwable $e) {
        return array('status' => 'error', 'description' => 'MagizAI could not be activated: ' . $e->getMessage());
    }
}

function magizai_deactivate()
{
    try {
        Store::uninstall();

        return array('status' => 'success', 'description' => 'MagizAI is deactivated. The activity log was kept.');
    } catch (\Throwable $e) {
        return array('status' => 'error', 'description' => $e->getMessage());
    }
}

function magizai_upgrade($vars)
{
    Store::install();
}

/** Admin page. */
function magizai_output($vars)
{
    $link = $vars['modulelink'];
    $tab = isset($_GET['tab']) ? preg_replace('/[^a-z]/', '', $_GET['tab']) : 'setup';
    $notice = '';
    $error = '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (function_exists('check_token')) {
            check_token('WHMCS.admin.default');
        }

        try {
            $notice = magizai_handle_post(isset($_POST['do']) ? (string) $_POST['do'] : '');
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }
    }

    $e = function ($v) {
        return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    };
    $token = function_exists('generate_token') ? generate_token('plain') : '';
    $s = Settings::all();
    $bridgeUrl = Settings::systemUrl() . 'modules/addons/magizai/bridge.php';

    echo '<style>
        .mz-wrap{max-width:980px}
        .mz-head{display:flex;align-items:center;gap:14px;margin:4px 0 18px}
        .mz-head img{border-radius:12px;flex-shrink:0;border:1px solid #e3e3e3;background:#fff}
        .mz-title{font-size:20px;font-weight:700;line-height:1.2}
        .mz-sub{color:#666;font-size:12.5px;margin-top:2px}
        .mz-muted{color:#888}
        .mz-tabs{display:flex;gap:4px;border-bottom:1px solid #ddd;margin-bottom:18px;flex-wrap:wrap}
        .mz-tabs a{padding:8px 14px;border:1px solid transparent;border-bottom:0;border-radius:4px 4px 0 0;text-decoration:none}
        .mz-tabs a.on{border-color:#ddd;background:#fff;font-weight:600;margin-bottom:-1px}
        .mz-card{background:#fff;border:1px solid #e3e3e3;border-radius:6px;padding:16px 18px;margin-bottom:16px}
        .mz-card h3{margin:0 0 10px;font-size:15px}
        .mz-help{color:#666;font-size:12px;margin:3px 0 0}
        .mz-row{margin-bottom:12px}
        .mz-row label{font-weight:600;display:block;margin-bottom:3px}
        .mz-row input[type=text],.mz-row input[type=password],.mz-row select{max-width:520px}
        .mz-check li{margin:4px 0;list-style:none}
        .mz-ok{color:#1a7f37}.mz-no{color:#b42318}
        .mz-code{font-family:monospace;background:#f6f6f6;border:1px solid #e3e3e3;padding:6px 8px;border-radius:4px;word-break:break-all;display:inline-block}
        .mz-grp{margin:10px 0 4px;font-weight:600}
        .mz-warn{background:#fff8e6;border:1px solid #f5d27a;padding:8px 10px;border-radius:4px;font-size:12px}
        table.mz-log td,table.mz-log th{padding:5px 8px;font-size:12px;vertical-align:top}
    </style>';

    echo '<div class="mz-wrap">';

    // The logo is embedded rather than linked: some WHMCS installs block direct web access to the
    // modules folder, and a broken image would be the first thing an admin sees.
    $logo = is_file(__DIR__ . '/lib/logo-96.png') ? 'data:image/png;base64,' . base64_encode((string) file_get_contents(__DIR__ . '/lib/logo-96.png')) : '';
    $connected = (bool) \WHMCS\Database\Capsule::table(Store::AUDIT)->where('created_at', '>=', date('Y-m-d H:i:s', time() - 86400 * 7))->exists();
    echo '<div class="mz-head">'
        . ($logo !== '' ? '<img src="' . $logo . '" alt="MagizAI" width="48" height="48">' : '')
        . '<div><div class="mz-title">MagizAI Live Chat</div><div class="mz-sub">Version ' . $e($vars['version'] ?? '') . ' · '
        . ($connected ? '<span class="mz-ok">● Connected to MagizAI</span>' : '<span class="mz-muted">● Not connected yet</span>')
        . '</div></div></div>';

    if ($notice !== '') {
        echo '<div class="successbox"><strong>' . $e($notice) . '</strong></div>';
    }
    if ($error !== '') {
        echo '<div class="errorbox"><strong>' . $e($error) . '</strong></div>';
    }

    $tabs = array('setup' => 'Setup', 'actions' => 'What the assistant may do', 'knowledge' => 'Knowledge sync', 'activity' => 'Activity log');
    echo '<div class="mz-tabs">';
    foreach ($tabs as $key => $label) {
        echo '<a class="' . ($tab === $key ? 'on' : '') . '" href="' . $e($link . '&tab=' . $key) . '">' . $e($label) . '</a>';
    }
    echo '</div>';

    $form = function ($do, $body, $button = 'Save') use ($link, $tab, $token, $e) {
        return '<form method="post" action="' . $e($link . '&tab=' . $tab) . '">'
            . ($token !== '' ? '<input type="hidden" name="token" value="' . $e($token) . '">' : '')
            . '<input type="hidden" name="do" value="' . $e($do) . '">' . $body
            . '<button type="submit" class="btn btn-primary">' . $e($button) . '</button></form>';
    };

    if ($tab === 'setup') {
        $lastCall = \WHMCS\Database\Capsule::table(Store::AUDIT)->orderBy('id', 'desc')->first();
        $mark = function ($ok, $text) use ($e) {
            return '<li><span class="' . ($ok ? 'mz-ok' : 'mz-no') . '">' . ($ok ? '✔' : '✘') . '</span> ' . $text . '</li>';
        };

        echo '<div class="mz-card"><h3>Checklist</h3><ul class="mz-check">'
            . $mark($s['widget_key'] !== '', 'Widget key entered — the chat appears on client-area pages')
            . $mark($s['identity_secret'] !== '', 'Identity secret entered — the assistant knows which client is signed in')
            . $mark((bool) $lastCall, 'MagizAI has connected to this WHMCS' . ($lastCall ? ' (last request ' . $e($lastCall->created_at) . ')' : ' — press "Test connection" on the MagizAI Connectors page'))
            . $mark($s['api_token'] !== '', 'API token entered — your knowledgebase and prices can be synced')
            . '</ul></div>';

        echo '<div class="mz-card"><h3>1. Connect MagizAI to this WHMCS</h3>'
            . '<p>In MagizAI open <strong>Connectors → WHMCS</strong> and paste these two values:</p>'
            . '<div class="mz-row"><label>WHMCS address</label><span class="mz-code">' . $e(Settings::systemUrl()) . '</span></div>'
            . '<div class="mz-row"><label>Bridge secret</label><span class="mz-code" id="mz-secret" data-secret="' . $e($s['bridge_secret']) . '">' . $e(substr($s['bridge_secret'], 0, 8)) . '••••••••••••</span> '
            . '<button type="button" class="btn btn-default btn-sm" onclick="var el=document.getElementById(\'mz-secret\');el.textContent=el.dataset.secret;">Show</button>'
            . '<p class="mz-help">Anyone with this secret can call the module. Keep it private; rotate it if it leaks.</p></div>'
            . '<p class="mz-help">MagizAI calls ' . $e($bridgeUrl) . '. If your firewall or Cloudflare blocks unknown POST requests, allow that path.</p>'
            . '</div>';

        echo '<div class="mz-card"><h3>2. Chat widget and signed-in clients</h3>'
            . $form('save_setup',
                '<div class="mz-row"><label>MagizAI address</label><input type="text" class="form-control" name="magizai_url" value="' . $e($s['magizai_url']) . '"><p class="mz-help">Leave as https://magiz.ai unless you use a white-label address.</p></div>'
                . '<div class="mz-row"><label>Widget key</label><input type="text" class="form-control" name="widget_key" value="' . $e($s['widget_key']) . '" placeholder="cv_..."><p class="mz-help">In MagizAI: Chatbots → your bot → Install. It is the data-key value in the embed code.</p></div>'
                . '<div class="mz-row"><label>Identity secret</label><input type="password" class="form-control" name="identity_secret" value="" autocomplete="off" placeholder="' . ($s['identity_secret'] !== '' ? 'saved — leave blank to keep' : '') . '"><p class="mz-help">In MagizAI: Chatbots → your bot → Install → Signed visitor identity → Generate secret. It is shown once — copy it straight here. Without it the assistant treats everyone as a guest.</p></div>'
                . '<div class="mz-row"><label>Show the chat</label><select name="widget_placement" class="form-control">'
                . magizai_options(array('all' => 'On every client-area page', 'loggedin' => 'Only to signed-in clients', 'off' => 'Nowhere (I add the embed code myself)'), $s['widget_placement'])
                . '</select></div>'
                . '<div class="mz-row"><label>Default ticket department</label><select name="ticket_department_id" class="form-control">' . magizai_department_options($s['ticket_department_id']) . '</select><p class="mz-help">Used when the client does not say which department a ticket is for.</p></div>'
                . '<div class="mz-row"><label>Account changes need a sign-in within (minutes)</label><input type="text" class="form-control" name="write_freshness_minutes" value="' . $e($s['write_freshness_minutes']) . '" style="max-width:120px"><p class="mz-help">A client who loaded a client-area page longer ago than this is asked to refresh before anything is changed.</p></div>'
                . '<div class="mz-row"><label>Account changes per client per hour</label><input type="text" class="form-control" name="writes_per_client_per_hour" value="' . $e($s['writes_per_client_per_hour']) . '" style="max-width:120px"></div>'
                . '<div class="mz-row"><label><input type="checkbox" name="notify_client_on_change" value="on"' . ($s['notify_client_on_change'] === 'on' ? ' checked' : '') . '> Email the client whenever their account is changed through chat</label></div>'
            ) . '</div>';

        echo '<div class="mz-card"><h3>Rotate the bridge secret</h3><p class="mz-help">MagizAI stops working with this WHMCS until you paste the new secret into MagizAI.</p>'
            . $form('rotate_secret', '', 'Generate a new secret') . '</div>';
    }

    if ($tab === 'actions') {
        $enabled = Settings::enabledActions();
        $groups = array(
            'public' => 'Public answers (anyone chatting)',
            'read' => 'Signed-in client: look up their own account',
            'write' => 'Signed-in client: change their own account (always confirmed by the client with a code first)',
            'sensitive' => 'Sensitive — off by default',
        );
        $body = '';
        foreach ($groups as $group => $title) {
            $body .= '<div class="mz-grp">' . $e($title) . '</div>';
            if ($group === 'sensitive') {
                $body .= '<p class="mz-warn">Cancellation requests cannot be undone by the client. Transfer codes are emailed to the address on the account and never shown in chat — but a transfer code is the key to moving a domain away, so enable this only if your team is comfortable with it.</p>';
            }
            foreach (Actions::catalog() as $key => $spec) {
                if ($spec['group'] !== $group) {
                    continue;
                }
                $body .= '<div><label><input type="checkbox" name="actions[]" value="' . $e($key) . '"' . (in_array($key, $enabled, true) ? ' checked' : '') . '> ' . $e($spec['label']) . '</label></div>';
            }
        }
        echo '<div class="mz-card"><h3>What the assistant may do</h3><p class="mz-help">This is the final say: an action switched off here is refused whatever is configured in MagizAI. Every request is recorded in the activity log, and changes also appear in the WHMCS activity log.</p>'
            . $form('save_actions', $body) . '</div>';
    }

    if ($tab === 'knowledge') {
        $body = '<div class="mz-row"><label>MagizAI API token</label><input type="password" class="form-control" name="api_token" value="" autocomplete="off" placeholder="' . ($s['api_token'] !== '' ? 'saved — leave blank to keep' : '') . '"><p class="mz-help">In MagizAI: Settings → API tokens → create a token. Create it while signed in as the owner or an admin (it needs permission to manage content).</p></div>';
        foreach (array('sync_kb' => 'Knowledgebase articles (public ones only)', 'sync_announcements' => 'Announcements', 'sync_products' => 'Products and prices (not hidden or retired)', 'sync_company_facts' => 'Company facts: your nameservers, support departments, domain prices, useful links') as $key => $label) {
            $body .= '<div><label><input type="checkbox" name="' . $e($key) . '" value="on"' . ($s[$key] === 'on' ? ' checked' : '') . '> ' . $e($label) . '</label></div>';
        }
        echo '<div class="mz-card"><h3>Teach the assistant about your business</h3><p class="mz-help">Runs every night with the WHMCS daily cron. Only changed items are sent; items you delete in WHMCS are removed from the assistant. No client data is ever synced.</p>'
            . $form('save_sync', $body) . '</div>';

        echo '<div class="mz-card"><h3>Sync now</h3><p>Last sync: ' . ($s['last_sync_at'] !== '' ? $e($s['last_sync_at']) . ' — ' . $e($s['last_sync_result']) : 'never') . '</p>'
            . $form('sync_now', '', 'Sync now') . '</div>';
    }

    if ($tab === 'activity') {
        echo '<div class="mz-card"><h3>Last 100 requests from MagizAI</h3><div class="table-responsive"><table class="table table-striped mz-log"><tr><th>Time</th><th>Action</th><th>Client</th><th>Result</th><th>Detail</th></tr>';
        foreach (Store::auditPage(100) as $row) {
            echo '<tr><td>' . $e($row->created_at) . '</td><td>' . $e($row->action) . ($row->is_write ? ' <span class="label label-warning">change</span>' : '') . '</td><td>'
                . ($row->client_id ? '<a href="clientssummary.php?userid=' . (int) $row->client_id . '">#' . (int) $row->client_id . '</a>' : '—')
                . '</td><td>' . $e($row->outcome) . ($row->error !== '' ? ' (' . $e($row->error) . ')' : '') . '</td><td>' . $e($row->detail) . '</td></tr>';
        }
        echo '</table></div></div>';
    }

    echo '</div>';
}

function magizai_handle_post($do)
{
    if ($do === 'save_setup') {
        $url = trim((string) ($_POST['magizai_url'] ?? ''));
        if ($url !== '' && !preg_match('#^https://[a-z0-9.-]+(:\d+)?/?$#i', $url)) {
            throw new \RuntimeException('The MagizAI address must be an https address with no path, e.g. https://magiz.ai');
        }
        $key = trim((string) ($_POST['widget_key'] ?? ''));
        if ($key !== '' && !preg_match('/^[A-Za-z0-9_-]{4,80}$/', $key)) {
            throw new \RuntimeException('That widget key does not look right. Copy the data-key value from the embed code.');
        }

        Settings::set('magizai_url', $url !== '' ? rtrim($url, '/') : 'https://magiz.ai');
        Settings::set('widget_key', $key);
        Settings::set('widget_placement', in_array($_POST['widget_placement'] ?? '', array('all', 'loggedin', 'off'), true) ? $_POST['widget_placement'] : 'all');
        Settings::set('ticket_department_id', (string) (int) ($_POST['ticket_department_id'] ?? 0));
        Settings::set('write_freshness_minutes', (string) max(5, min(1440, (int) ($_POST['write_freshness_minutes'] ?? 60))));
        Settings::set('writes_per_client_per_hour', (string) max(1, min(100, (int) ($_POST['writes_per_client_per_hour'] ?? 10))));
        Settings::set('notify_client_on_change', isset($_POST['notify_client_on_change']) ? 'on' : '');

        $secret = trim((string) ($_POST['identity_secret'] ?? ''));
        if ($secret !== '') {
            if (strlen($secret) < 32) {
                throw new \RuntimeException('The identity secret is too short. Copy the whole value from MagizAI.');
            }
            Settings::set('identity_secret', $secret);
        }

        return 'Settings saved.';
    }

    if ($do === 'rotate_secret') {
        Settings::set('bridge_secret', Crypto::newBridgeSecret());

        return 'A new bridge secret was generated. Paste it into MagizAI → Connectors → WHMCS.';
    }

    if ($do === 'save_actions') {
        $chosen = isset($_POST['actions']) && is_array($_POST['actions']) ? $_POST['actions'] : array();
        $valid = array_values(array_intersect(array_keys(Actions::catalog()), $chosen));
        Settings::set('enabled_actions', json_encode($valid));

        return count($valid) . ' action(s) enabled.';
    }

    if ($do === 'save_sync') {
        foreach (array('sync_kb', 'sync_announcements', 'sync_products', 'sync_company_facts') as $key) {
            Settings::set($key, isset($_POST[$key]) ? 'on' : '');
        }
        $token = trim((string) ($_POST['api_token'] ?? ''));
        if ($token !== '') {
            Settings::set('api_token', $token);
        }

        return 'Sync settings saved.';
    }

    if ($do === 'sync_now') {
        $result = Sync::runIfConfigured();
        if ($result === null) {
            throw new \RuntimeException('Enter the widget key (Setup) and an API token (Knowledge sync) first.');
        }

        return 'Sync finished: ' . $result;
    }

    return '';
}

function magizai_options(array $options, $current)
{
    $out = '';
    foreach ($options as $value => $label) {
        $out .= '<option value="' . htmlspecialchars($value, ENT_QUOTES) . '"' . ((string) $current === (string) $value ? ' selected' : '') . '>' . htmlspecialchars($label) . '</option>';
    }

    return $out;
}

function magizai_department_options($current)
{
    $r = localAPI('GetSupportDepartments', array('ignore_dept_assignments' => true));
    $options = array('0' => 'First department');
    if (isset($r['departments']['department']) && is_array($r['departments']['department'])) {
        foreach ($r['departments']['department'] as $d) {
            $options[(string) (int) $d['id']] = $d['name'];
        }
    }

    return magizai_options($options, $current);
}
