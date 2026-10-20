<?php

namespace MagizAI\Whmcs\Admin\Tabs;

use MagizAI\Whmcs\Admin\View;

/**
 * The Knowledge sync tab: the API token, what is synced, and the last sync.
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class KnowledgeTab
{
    /** Echo the tab, exactly as the page shows it. */
    public static function render(array $s, $link, $tab, $token)
    {
        $e = View::escaper();
        $form = View::form($link, $tab, $token);

        $body = '<div class="mz-row"><label>MagizAI API token</label><input type="password" class="form-control" name="api_token" value="" autocomplete="off" placeholder="' . ($s['api_token'] !== '' ? 'saved — leave blank to keep' : '') . '"><p class="mz-help">In MagizAI: Settings → API tokens → create a token. Create it while signed in as the owner or an admin (it needs permission to manage content).</p></div>';
        foreach (array('sync_kb' => 'Knowledgebase articles (public ones only)', 'sync_announcements' => 'Announcements', 'sync_products' => 'Products and prices (not hidden or retired)', 'sync_company_facts' => 'Company facts: your nameservers, support departments, domain prices, useful links') as $key => $label) {
            $body .= '<div><label><input type="checkbox" name="' . $e($key) . '" value="on"' . ($s[$key] === 'on' ? ' checked' : '') . '> ' . $e($label) . '</label></div>';
        }
        echo '<div class="mz-card"><h3>Teach the assistant about your business</h3><p class="mz-help">Runs every night with the WHMCS daily cron. Only changed items are sent; items you delete in WHMCS are removed from the assistant. No client data is ever synced.</p>'
            . $form('save_sync', $body) . '</div>';

        echo '<div class="mz-card"><h3>Sync now</h3><p>Last sync: ' . ($s['last_sync_at'] !== '' ? $e($s['last_sync_at']) . ' — ' . $e($s['last_sync_result']) : 'never') . '</p>'
            . $form('sync_now', '', 'Sync now') . '</div>';
    }
}
