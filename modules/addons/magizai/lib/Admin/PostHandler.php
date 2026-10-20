<?php

namespace MagizAI\Whmcs\Admin;

use MagizAI\Whmcs\Action\Registry;
use MagizAI\Whmcs\Crypto;
use MagizAI\Whmcs\Domain\ModuleCommands;
use MagizAI\Whmcs\Http\TicketEvents;
use MagizAI\Whmcs\Pairing;
use MagizAI\Whmcs\Identity\Signing;
use MagizAI\Whmcs\Install\EmailTemplate;
use MagizAI\Whmcs\Persistence\KeptSettings;
use MagizAI\Whmcs\Settings;
use MagizAI\Whmcs\Staff\ReplyAs;
use MagizAI\Whmcs\Sync;
use WHMCS\Database\Capsule;

/**
 * What each form on the admin page does when it is posted (magizai_handle_post).
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class PostHandler
{
    /**
     * @param string $do the form's `do`
     * @return string the notice the page shows
     * @throws \RuntimeException with the message the page shows
     */
    public static function handle($do)
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
            Settings::set('widget_placement', in_array($_POST['widget_placement'] ?? '', array('all', 'loggedin', 'self', 'off'), true) ? $_POST['widget_placement'] : 'all');
            // Staff view is on only when chosen here, every time the form is saved.
            Settings::set('staff_mode', ($_POST['staff_mode'] ?? '') === 'read_only' ? 'read_only' : 'off');
            $signing = ($_POST['identity_signing'] ?? '') === 'hs256' ? 'hs256' : 'auto';
            if ($signing === 'hs256' && Settings::get('identity_signing', 'auto') !== 'hs256') {
                // Back to the shared secret: sign-ins are HS256 from now on, and accepted again.
                Signing::endEddsaPeriod();
            }
            Settings::set('identity_signing', $signing);
            Settings::set('ticket_department_id', (string) (int) ($_POST['ticket_department_id'] ?? 0));
            Settings::set('identity_ttl_minutes', (string) self::minutesField('identity_ttl_minutes', 5, 240));
            Settings::set('write_freshness_minutes', (string) self::minutesField('write_freshness_minutes', 5, 1440));
            Settings::set('credentials_freshness_minutes', (string) self::minutesField('credentials_freshness_minutes', 5, 240));
            Settings::set('writes_per_client_per_hour', (string) max(1, min(100, (int) ($_POST['writes_per_client_per_hour'] ?? 10))));
            Settings::set('signups_per_hour', (string) max(1, min(50, (int) ($_POST['signups_per_hour'] ?? 5))));
            Settings::set('notify_client_on_change', isset($_POST['notify_client_on_change']) ? 'on' : '');
            Settings::set('credentials_in_chat', isset($_POST['credentials_in_chat']) ? 'on' : 'off');
            Settings::set('verification_email', isset($_POST['verification_email']) ? 'on' : 'off');
            if (isset($_POST['verification_email'])) {
                // Put back a template that was deleted. One the merchant edited is never touched.
                try {
                    EmailTemplate::install();
                } catch (\Throwable $e) {
                    // The Setup tab says the template is missing; the next send tries again.
                }
            }

            $secret = trim((string) ($_POST['identity_secret'] ?? ''));
            if ($secret !== '') {
                if (strlen($secret) < 32) {
                    throw new \RuntimeException('The identity secret is too short. Copy the whole value from MagizAI.');
                }
                Settings::set('identity_secret', $secret);
            }

            return 'Settings saved.';
        }

        if ($do === 'pair') {
            $version = function_exists('magizai_config') ? (string) (magizai_config()['version'] ?? '') : '';
            $done = Pairing::connect($_POST['pair_code'] ?? '', $_POST['pair_magizai_url'] ?? '', $version);
            $who = trim($done['workspace'] . ($done['chatbot'] !== '' ? ' (' . $done['chatbot'] . ')' : ''));

            return 'Connected to MagizAI' . ($who !== '' ? ': ' . $who : '') . '. The widget key, identity secret and API token are saved. '
                . ($done['bridge_ok']
                    ? 'MagizAI reached this WHMCS, so the assistant can answer from it now.'
                    : 'MagizAI could not reach this WHMCS yet' . ($done['bridge_message'] !== '' ? ' (' . $done['bridge_message'] . ')' : '') . '. If a firewall or Cloudflare blocks unknown POST requests, allow the bridge path shown in step 1, then press Test connection in MagizAI.');
        }

        if ($do === 'tickets') {
            $on = isset($_POST['tickets_inbox']);
            $admin = trim((string) ($_POST['ticket_admin_username'] ?? ''));
            // Replies are posted as this person, so it must be a real, active admin of this WHMCS. None
            // chosen is the first active Full Administrator (1.9.0). @see Staff\ReplyAs
            if ($admin !== '' && !Capsule::table('tbladmins')->where('username', $admin)->where('disabled', 0)->exists()) {
                throw new \RuntimeException('The staff member MagizAI replies as must be an active WHMCS admin.');
            }
            Settings::set('tickets_inbox', $on ? 'on' : 'off');
            Settings::set('ticket_admin_username', $admin);
            // 1.10.0: the MagizAI panel on the admin ticket page. @see Staff\TicketAssist
            Settings::set('ticket_assist', isset($_POST['ticket_assist']) ? 'on' : 'off');
            if ($on && ReplyAs::username() === '') {
                throw new \RuntimeException('Saved, but tickets cannot show yet: this WHMCS has no active admin to post replies as. Choose one above.');
            }

            return $on
                ? 'Tickets will show in the MagizAI inbox, marked as tickets, as soon as they change. MagizAI connects the inbox by itself on its next connection check.'
                : 'Tickets are off. MagizAI can no longer read or answer them.';
        }

        if ($do === 'test_ticket_event') {
            // 1.10.0: one signed test update, and what came back. @see Http\TicketEvents::test
            $result = TicketEvents::test();
            if (!$result['ok']) {
                throw new \RuntimeException($result['message']);
            }

            return $result['message'];
        }

        if ($do === 'rotate_secret') {
            Settings::set('bridge_secret', Crypto::newBridgeSecret());

            return 'A new bridge secret was generated. Paste it into MagizAI → Connectors → WHMCS.';
        }

        if ($do === 'rotate_all') {
            // Settings::set updates the kept copy too, so the old secrets cannot come back on reactivation.
            Settings::set('bridge_secret', Crypto::newBridgeSecret());
            Settings::set('identity_secret', '');
            Settings::set('api_token', '');
            // The install's own signing key too. A new one is made on MagizAI's next connection check,
            // and sign-ins use the shared secret until MagizAI has it.
            Signing::forgetKeyPair();

            return 'Every secret was replaced, including this install\'s own signing key. Paste the new bridge secret into MagizAI → Connectors → WHMCS. '
                . 'Then generate a new identity secret in MagizAI (Chatbots, your bot, Install) and enter it here, and create a new API token for the knowledge sync. '
                . 'Revoke the old API token in MagizAI too (Settings, API tokens): it still works there until you do.';
        }

        if ($do === 'forget_kept') {
            Settings::set('keep_on_deactivate', 'off');
            KeptSettings::forget();

            return 'The kept copy was removed. Deactivating the module now removes every setting and secret.';
        }

        if ($do === 'keep_kept') {
            Settings::set('keep_on_deactivate', 'on');
            Settings::snapshot();

            return 'A copy of the settings is kept again, so deactivating and activating the module restores them.';
        }

        if ($do === 'save_actions') {
            $chosen = isset($_POST['actions']) && is_array($_POST['actions']) ? $_POST['actions'] : array();
            // An action a Setup tab setting switches is never part of the list. @see Registry::SETTING_SWITCHES
            $valid = array_values(array_diff(array_intersect(array_keys(Registry::catalog()), $chosen), array_keys(Registry::SETTING_SWITCHES), array_keys(Registry::FOLLOWS)));
            Settings::set('enabled_actions', json_encode($valid));

            return count($valid) . ' action(s) enabled.';
        }

        if ($do === 'save_module_commands') {
            // 1.10.0: the server commands a client may run from the chat. @see Domain\ModuleCommands
            $entries = array();
            foreach (preg_split('/[\s,]+/', trim((string) ($_POST['module_commands'] ?? ''))) as $entry) {
                if ($entry === '') {
                    continue;
                }
                $why = ModuleCommands::refusal($entry);
                if ($why !== null) {
                    throw new \RuntimeException('Nothing was saved. ' . $why);
                }
                if (!in_array($entry, $entries, true)) {
                    $entries[] = $entry;
                }
            }
            Settings::set(ModuleCommands::SETTING, implode("\n", array_slice($entries, 0, 30)));
            $raw = trim((string) ($_POST['module_commands_per_hour'] ?? ''));
            Settings::set(ModuleCommands::PER_HOUR, (string) (ctype_digit($raw) ? max(1, min(ModuleCommands::PER_HOUR_MAX, (int) $raw)) : ModuleCommands::perHour()));

            return $entries ? count($entries) . ' server command(s) saved.' : 'No server commands are offered.';
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

    /**
     * A minutes field from the setup form, kept inside [$min, $max]. Left empty or not a number, the
     * current value stays: an empty box is not a decision to make a limit as short as it can be.
     */
    public static function minutesField($name, $min, $max)
    {
        $raw = trim((string) ($_POST[$name] ?? ''));
        $defaults = Settings::defaults();
        $value = ctype_digit($raw) ? (int) $raw : (int) Settings::get($name, isset($defaults[$name]) ? $defaults[$name] : (string) $min);

        return max((int) $min, min((int) $max, $value));
    }
}
