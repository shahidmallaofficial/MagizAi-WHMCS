<?php

namespace MagizAI\Whmcs\Admin;

use MagizAI\Whmcs\Admin\Tabs\ActionsTab;
use MagizAI\Whmcs\Admin\Tabs\ActivityTab;
use MagizAI\Whmcs\Admin\Tabs\KnowledgeTab;
use MagizAI\Whmcs\Admin\Tabs\SetupTab;
use MagizAI\Whmcs\Compat;
use MagizAI\Whmcs\Install\Tables;
use MagizAI\Whmcs\Settings;
use MagizAI\Whmcs\Updates;
use WHMCS\Database\Capsule;

/**
 * The module's admin page (magizai_output): the header with the connection badge, an update or
 * WHMCS security notice, the tabs, and the answer to a form that was posted.
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class AdminPage
{
    /** Echo the page for the tab asked for. @param array $vars what WHMCS passes: modulelink, version */
    public static function render($vars)
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
                $notice = PostHandler::handle(isset($_POST['do']) ? (string) $_POST['do'] : '');
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
        }

        $e = View::escaper();
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
        $logo = is_file(dirname(__DIR__) . '/logo-96.png') ? 'data:image/png;base64,' . base64_encode((string) file_get_contents(dirname(__DIR__) . '/logo-96.png')) : '';
        $connected = (bool) Capsule::table(Tables::AUDIT)->where('created_at', '>=', date('Y-m-d H:i:s', time() - 86400 * 7))->exists();
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

        /*
         * An update notice, above the tabs so it is seen rather than found.
         *
         * Checked at most once a day and wrapped in its own try/catch: a version check is the last
         * thing that should be able to take down a billing system's admin page.
         */
        try {
            $latest = Updates::latest();

            if (Updates::isNewer($latest)) {
                echo '<div class="mz-warn" style="margin-bottom:16px">'
                    . '<strong>Version ' . $e($latest['version']) . ' is available.</strong> '
                    . 'You are running ' . $e(Updates::runningVersion()) . '. '
                    . 'Upload the new files over the old ones, then open this page once so the upgrade runs. '
                    . 'Your settings and your action choices are kept.';

                if (!empty($latest['download_url'])) {
                    echo ' <a href="' . $e($latest['download_url']) . '" rel="noopener">Download ' . $e($latest['version']) . '</a>';
                }
                if (!empty($latest['notes_url'])) {
                    echo ' &middot; <a href="' . $e($latest['notes_url']) . '" target="_blank" rel="noopener">What changed</a>';
                }
                // Shown so an admin can verify the file before unzipping it into their billing system.
                if (!empty($latest['sha256'])) {
                    echo '<div class="mz-help" style="margin-top:6px">SHA-256: <span class="mz-code">' . $e($latest['sha256']) . '</span></div>';
                }

                echo '</div>';
            }
        } catch (\Throwable $e2) {
            // No news. Never a broken page.
        }

        /*
         * WHMCS ITSELF NEEDS AN UPDATE. Builds before 8.13.7 (LTS) and 9.0.8 have a serious flaw
         * (CVE-2026-67399) that only upgrading WHMCS fixes. Shown as advice: the module keeps working
         * on every WHMCS from 8.0, because refusing would only cut merchants off, not patch them.
         */
        try {
            if (Compat::hasSecurityFix() === false) {
                echo '<div class="mz-warn" style="margin-bottom:16px"><strong>Your WHMCS ' . $e(Compat::whmcsVersion()) . ' needs a security update.</strong> '
                    . 'WHMCS 8.13.7 (long-term support) and 9.0.8 fix a serious security issue (CVE-2026-67399) found in every earlier build. '
                    . 'MagizAI keeps working either way, but please update WHMCS.</div>';
            }
        } catch (\Throwable $e3) {
            // Advice only. Never a broken page.
        }

        $tabs = array('setup' => 'Setup', 'actions' => 'What the assistant may do', 'knowledge' => 'Knowledge sync', 'activity' => 'Activity log');
        echo '<div class="mz-tabs">';
        foreach ($tabs as $key => $label) {
            echo '<a class="' . ($tab === $key ? 'on' : '') . '" href="' . $e($link . '&tab=' . $key) . '">' . $e($label) . '</a>';
        }
        echo '</div>';

        if ($tab === 'setup') {
            SetupTab::render($s, $link, $tab, $token, $bridgeUrl);
        }

        if ($tab === 'actions') {
            ActionsTab::render($link, $tab, $token);
        }

        if ($tab === 'knowledge') {
            KnowledgeTab::render($s, $link, $tab, $token);
        }

        if ($tab === 'activity') {
            ActivityTab::render($link, $tab, $token);
        }

        echo '</div>';
    }
}
