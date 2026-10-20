<?php

namespace MagizAI\Whmcs\Admin\Tabs;

use MagizAI\Whmcs\Admin\View;
use MagizAI\Whmcs\Http\TicketEvents;
use MagizAI\Whmcs\Identity\PageSettings;
use MagizAI\Whmcs\Install\EmailTemplate;
use MagizAI\Whmcs\Install\Tables;
use MagizAI\Whmcs\Persistence\KeptSettings;
use MagizAI\Whmcs\Settings;
use MagizAI\Whmcs\Staff\ReplyAs;
use WHMCS\Database\Capsule;

/**
 * The Setup tab: the checklist, connecting MagizAI, the chat widget and signed-in clients, and the
 * secrets.
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class SetupTab
{
    /** Echo the tab, exactly as the page shows it. */
    public static function render(array $s, $link, $tab, $token, $bridgeUrl)
    {
        $e = View::escaper();
        $form = View::form($link, $tab, $token);

        $lastCall = Capsule::table(Tables::AUDIT)->orderBy('id', 'desc')->first();
        $mark = function ($ok, $text) use ($e) {
            return '<li><span class="' . ($ok ? 'mz-ok' : 'mz-no') . '">' . ($ok ? '✔' : '✘') . '</span> ' . $text . '</li>';
        };

        /*
         * A secret that is saved but cannot be decrypted reads as empty everywhere else, which looks
         * exactly like "never entered". It happens when WHMCS's own encryption key changes (a server
         * move, a restored configuration.php), and identity then stops working without a word.
         */
        $health = array();
        try {
            $health = Settings::secretHealth();
        } catch (\Throwable $e4) {
            $health = array();
        }
        $unreadable = function ($key) use ($health) {
            return isset($health[$key]) && $health[$key] === 'unreadable';
        };
        $reenter = ' <span class="mz-no">It is saved but can no longer be read, which happens when WHMCS\'s encryption key changes (after a server move, for example). Enter it again.</span>';

        echo '<div class="mz-card"><h3>Checklist</h3><ul class="mz-check">'
            . $mark($s['widget_key'] !== '', 'Widget key entered — the chat appears on client-area pages')
            . $mark($s['identity_secret'] !== '', 'Identity secret entered — the assistant knows which client is signed in' . ($unreadable('identity_secret') ? $reenter : ''))
            . $mark((bool) $lastCall, 'MagizAI has connected to this WHMCS' . ($lastCall ? ' (last request ' . $e($lastCall->created_at) . ')' : ' — press "Test connection" on the MagizAI Connectors page, then reload this page'))
            . $mark($s['api_token'] !== '', 'API token entered — your knowledgebase and prices can be synced' . ($unreadable('api_token') ? $reenter : ''))
            . ($unreadable('bridge_secret') ? $mark(false, 'The bridge secret is saved but can no longer be read, so MagizAI cannot connect. Use "Rotate all secrets" below, then paste the new bridge secret into MagizAI.') : '')
            . '</ul></div>';

        echo '<div class="mz-card" style="border-color:#16a34a"><h3>Fastest: connect with one code</h3>'
            . '<p>In MagizAI open <strong>Connectors, WHMCS</strong> and press <strong>Create a connection code</strong>. Paste it here and press Connect: '
            . 'the widget key, the identity secret, the API token and the bridge are all set up for you, and you can skip steps 1 and 2 below.</p>'
            . $form('pair',
                '<div class="mz-row"><label>Connection code</label><input type="text" class="form-control" name="pair_code" value="" autocomplete="off" placeholder="mzp_..." style="font-family:monospace"><p class="mz-help">It works once and expires 30 minutes after it was created.</p></div>'
                . '<div class="mz-row"><label>MagizAI address</label><input type="text" class="form-control" name="pair_magizai_url" value="' . $e($s['magizai_url']) . '"><p class="mz-help">Leave as https://magiz.ai unless you use a white-label address.</p></div>',
                'Connect')
            . '</div>';

        echo '<div class="mz-card"><h3>1. Connect MagizAI to this WHMCS</h3>'
            . '<p>In MagizAI open <strong>Connectors → WHMCS</strong> and paste these two values:</p>'
            . '<div class="mz-row"><label>WHMCS address</label><span class="mz-code">' . $e(Settings::systemUrl()) . '</span></div>'
            . '<div class="mz-row"><label>Bridge secret</label><span class="mz-code" id="mz-secret" data-secret="' . $e($s['bridge_secret']) . '">' . $e(substr($s['bridge_secret'], 0, 8)) . '••••••••••••</span> '
            . '<button type="button" class="btn btn-default btn-sm" onclick="var el=document.getElementById(\'mz-secret\');el.textContent=el.dataset.secret;">Show</button>'
            . '<p class="mz-help">Anyone with this secret can call the module. Keep it private; rotate it if it leaks.</p></div>'
            . '<p class="mz-help">MagizAI calls ' . $e($bridgeUrl) . '. If your firewall or Cloudflare blocks unknown POST requests, allow that path.</p>'
            . '</div>';

        /*
         * "NOWHERE" MEANS NOTHING AT ALL. The module then adds neither the widget nor the sign-in, so
         * a widget pasted into the theme by hand treats every client as a guest. That was the only
         * choice for a hand-pasted widget before 1.6.0, so it is pointed out here, not changed.
         */
        $placementNotice = $s['widget_placement'] === 'off' && $s['identity_secret'] !== ''
            ? '<div class="mz-warn" style="margin-bottom:12px"><strong>The chat is set to "Nowhere", so the module adds nothing to client-area pages, not even the sign-in.</strong> '
                . 'If MagizAI\'s embed code is in your theme, choose "I add the embed code myself" below: your widget then knows which client is signed in.</div>'
            : '';

        echo '<div class="mz-card"><h3>2. Chat widget and signed-in clients</h3>' . $placementNotice
            . $form('save_setup',
                '<div class="mz-row"><label>MagizAI address</label><input type="text" class="form-control" name="magizai_url" value="' . $e($s['magizai_url']) . '"><p class="mz-help">Leave as https://magiz.ai unless you use a white-label address.</p></div>'
                . '<div class="mz-row"><label>Widget key</label><input type="text" class="form-control" name="widget_key" value="' . $e($s['widget_key']) . '" placeholder="cv_..."><p class="mz-help">In MagizAI: Chatbots → your bot → Install. It is the data-key value in the embed code.</p></div>'
                . '<div class="mz-row"><label>Identity secret</label><input type="password" class="form-control" name="identity_secret" value="" autocomplete="off" placeholder="' . ($s['identity_secret'] !== '' ? 'saved — leave blank to keep' : '') . '"><p class="mz-help">In MagizAI: Chatbots → your bot → Install → Signed visitor identity → Generate secret. It is shown once — copy it straight here. Without it the assistant treats everyone as a guest.</p></div>'
                . '<div class="mz-row"><label>Show the chat</label><select name="widget_placement" class="form-control">'
                . View::options(array(
                    'all' => 'On every client-area page',
                    'loggedin' => 'Only to signed-in clients',
                    'self' => 'I add the embed code myself (the module adds only the sign-in)',
                    'off' => 'Nowhere: MagizAI is not used on WHMCS pages',
                ), PageSettings::placement())
                . '</select><p class="mz-help">Choose "I add the embed code myself" if MagizAI\'s embed code is in your theme or added by a tag manager: '
                . 'the module then adds only what tells the chat who is signed in. "Nowhere" adds nothing at all, so a widget you embed yourself treats every client as a guest.</p></div>'
                . '<div class="mz-row"><label>Default ticket department</label><select name="ticket_department_id" class="form-control">' . View::departmentOptions($s['ticket_department_id']) . '</select><p class="mz-help">Used when the client does not say which department a ticket is for.</p></div>'
                . '<div class="mz-row"><label>Identity token lifetime (minutes)</label><input type="text" class="form-control" name="identity_ttl_minutes" value="' . $e(PageSettings::ttlMinutes()) . '" style="max-width:120px"><p class="mz-help">How long one sign-in token for the chat lasts, from 5 to 240 minutes. The chat asks this module for a fresh one before it runs out, for as long as the client is still signed in, so a short lifetime costs the client nothing. New installs start at 15.</p></div>'
                . '<div class="mz-row"><label>Staff view</label><select name="staff_mode" class="form-control">'
                . View::options(array(
                    'off' => 'Off: staff sessions get no account answers in the chat',
                    'read_only' => 'Read only: staff can look up the client\'s account, and change nothing',
                ), PageSettings::staffMode())
                . '</select><div class="mz-warn" style="margin-top:6px">Staff view lets anyone signed in to your WHMCS admin area in the same browser, or using Login as Owner, '
                . 'see a client\'s services, domains, invoices and tickets through the chat on that client\'s pages. Nothing can be changed and nothing is sent to the client from the chat in staff view, '
                . 'and every look-up is recorded against the staff member, in the activity log here and in WHMCS\'s own. Leave it off unless your team tests the chat this way. '
                . 'With it off, the chat tells staff that account answers need a client\'s own sign-in, for example in a private browser window.</div></div>'
                . '<div class="mz-row"><label>How sign-ins are signed</label><select name="identity_signing" class="form-control">'
                . View::options(array(
                    'auto' => 'Automatic: this install\'s own key (Ed25519) once MagizAI can check it (recommended)',
                    'hs256' => 'The shared identity secret only (HS256)',
                ), Settings::get('identity_signing', 'auto') === 'hs256' ? 'hs256' : 'auto')
                . '</select><p class="mz-help">' . View::signingStatus() . ' With the shared secret, MagizAI holds the same secret that signs a sign-in, so it could make one. '
                . 'With this install\'s own key, only your WHMCS can make them. MagizAI can check them, and can use a sign-in it was given until it expires, but cannot make one.</p></div>'
                . '<div class="mz-row"><label>Account changes need a sign-in within (minutes)</label><input type="text" class="form-control" name="write_freshness_minutes" value="' . $e($s['write_freshness_minutes']) . '" style="max-width:120px"><p class="mz-help">A client whose sign-in was last confirmed longer ago than this (by loading a client-area page, or by the chat fetching a fresh sign-in) is asked to refresh before anything is changed. From 5 to 1440 minutes. New installs start at 15.</p></div>'
                . '<div class="mz-row"><label>Show a password in the chat only within (minutes of signing in)</label><input type="text" class="form-control" name="credentials_freshness_minutes" value="' . $e(Settings::minutes('credentials_freshness_minutes', 15, 5, 240)) . '" style="max-width:120px"><p class="mz-help">Only used when passwords may be shown in the chat. If the client\'s sign-in was last confirmed longer ago than this, the password is emailed instead. From 5 to 240 minutes.</p></div>'
                . '<div class="mz-row"><label>Account changes per client per hour</label><input type="text" class="form-control" name="writes_per_client_per_hour" value="' . $e($s['writes_per_client_per_hour']) . '" style="max-width:120px"></div>'
                . '<div class="mz-row"><label>New accounts the chat may open per hour</label><input type="text" class="form-control" name="signups_per_hour" value="' . $e($s['signups_per_hour']) . '" style="max-width:120px"><p class="mz-help">Only used when "Open an account for a new client" is ticked on the Actions tab. Across everyone chatting; each conversation opens at most one. From 1 to 50.</p></div>'
                . '<div class="mz-row"><label><input type="checkbox" name="notify_client_on_change" value="on"' . ($s['notify_client_on_change'] === 'on' ? ' checked' : '') . '> Email the client whenever their account is changed through chat</label></div>'
                . '<div class="mz-row"><label><input type="checkbox" name="credentials_in_chat" value="on"' . ($s['credentials_in_chat'] === 'on' ? ' checked' : '') . '> Show a service password in the chat window, as well as emailing it</label>'
                . '<div class="mz-help">Off by default. With it off, "what is my cPanel password" emails the details to the address on the account, '
                . 'and the chat only ever says the username. With it on, the client can also reveal the password in the chat, once they type a code '
                . 'emailed to the address on the account (keep "Send chat verification codes by email" below switched on): it is shown behind a '
                . 'button, in their browser only, once, and it is never written into the conversation, never stored with the transcript and never '
                . 'sent to the AI. Even so, a password on screen can be read by whoever is looking at that screen.</div></div>'
                . self::verificationRow($s)
            ) . '</div>';

        /*
         * TICKETS IN THE MAGIZAI INBOX (1.7.0; on by default from 1.9.0). Replies go out as the staff
         * member chosen here, or the first active Full Administrator. The chat AI never gets these
         * powers. @see \MagizAI\Whmcs\Staff\TicketInbox
         */
        $ticketsOn = Settings::get('tickets_inbox') === 'on';
        $fallback = '';
        $admins = array();
        try {
            foreach (Capsule::table('tbladmins')->where('disabled', 0)->orderBy('username')->get(array('username', 'firstname', 'lastname')) as $a) {
                $admins[$a->username] = trim($a->firstname . ' ' . $a->lastname) . ' (' . $a->username . ')';
            }
            $first = Capsule::table('tbladmins')->where('roleid', ReplyAs::FULL_ADMIN_ROLE)->where('disabled', 0)->orderBy('id')->value('username');
            $fallback = is_string($first) && isset($admins[$first]) ? $admins[$first] : '';
        } catch (\Throwable $e5) {
            // The list stays empty; the first Full Administrator is used.
        }
        $admins = array('' => $fallback !== '' ? 'The first Full Administrator: ' . $fallback : 'The first Full Administrator') + $admins;
        $eventsNote = $ticketsOn ? self::ticketEventsLine($e) : '';
        echo '<div class="mz-card"><h3>Tickets in the MagizAI inbox</h3>'
            . '<p class="mz-help">Support tickets show in the MagizAI inbox next to your chats, marked as tickets, and your team answers them there. '
            . 'A reply is posted to the ticket as the staff member chosen below, and WHMCS emails it to the client as usual. '
            . 'A note written there is added to the ticket as a staff note, and Close and Reopen change the ticket\'s status. '
            . 'Tickets are shared only with the MagizAI workspace this module is connected to. '
            . 'MagizAI posts nothing to a ticket by itself unless that workspace switches on AI suggestions (private notes) or AI replies.</p>'
            . $eventsNote
            . $form('tickets',
                '<div class="mz-row"><label><input type="checkbox" name="tickets_inbox" value="on"' . ($ticketsOn ? ' checked' : '') . '> Show tickets in the MagizAI inbox</label></div>'
                . '<div class="mz-row"><label>Replies are posted as</label><select name="ticket_admin_username" class="form-control">'
                . View::options($admins, (string) Settings::get('ticket_admin_username'))
                . '</select><p class="mz-help">Pick a real person or a shared "Support" admin account. Tickets stay in WHMCS: nothing is deleted from MagizAI.</p></div>'
                . '<div class="mz-row"><label><input type="checkbox" name="ticket_assist" value="on"' . (Settings::get('ticket_assist', 'on') !== 'off' ? ' checked' : '') . '> Show MagizAI on the ticket page in the admin area</label>'
                . '<p class="mz-help">Buttons on the ticket page to draft a reply from the ticket and your MagizAI knowledge, improve your own text, translate it into the client\'s language and summarise the ticket. '
                . 'A result goes into the reply box only when you press Apply, and nothing is sent until you send the reply yourself. '
                . 'Only staff signed in to this admin area who are assigned to the ticket\'s department can use it. When a button is pressed, the ticket\'s messages and your text are sent to the MagizAI workspace this module is connected to, '
                . 'with card numbers, private keys and anything labelled as a password, PIN, token, API key or transfer code removed first. Staff notes are never sent. Each use counts as AI usage in that workspace, as the MagizAI inbox does.</p></div>',
                'Save ticket settings')
            . $form('test_ticket_event', '<p class="mz-help" style="margin-top:12px">Sends one signed test update to MagizAI and shows what came back. No ticket is read or changed.</p>', 'Send a test ticket update')
            . '</div>';

        echo '<div class="mz-card"><h3>Rotate the bridge secret</h3><p class="mz-help">MagizAI stops working with this WHMCS until you paste the new secret into MagizAI.</p>'
            . $form('rotate_secret', '', 'Generate a new secret') . '</div>';

        /*
         * SECRETS AND DEACTIVATION. Since 1.5.7 deactivating keeps a copy of the settings, secrets
         * included, so an update by deactivate-upload-activate changes nothing. That also means
         * deactivating is no longer a way to replace a secret that leaked, so both choices are here.
         */
        $keeping = KeptSettings::keeping();
        echo '<div class="mz-card"><h3>Replace every secret</h3>'
            . '<p class="mz-help">Use this if a secret may have leaked. It makes a new bridge secret, clears the identity secret and the API token, and replaces this install\'s own signing key (MagizAI gets the new one with its next connection check). '
            . 'Paste the new bridge secret into MagizAI, then generate a new identity secret in MagizAI (Chatbots, your bot, Install) and a new API token, and enter both here. '
            . 'Also revoke the old API token in MagizAI (Settings, API tokens): clearing it here does not stop it working in MagizAI. '
            . 'Until then, signed-in clients are treated as guests and the knowledge sync stops. Deactivating the module does not replace the secrets.</p>'
            . $form('rotate_all', '', 'Rotate all secrets') . '</div>';

        echo '<div class="mz-card"><h3>When the module is deactivated</h3>'
            . ($keeping
                ? '<p class="mz-help">A copy of these settings, secrets included and still encrypted with your WHMCS key, is kept so that activating the module again restores them. '
                    . 'Forget it if you are removing the module for good: deactivating then removes every setting and secret.</p>'
                    . $form('forget_kept', '', 'Forget the kept copy')
                : '<p class="mz-help"><strong>No copy is kept.</strong> Deactivating the module now removes every setting and secret, and activating it again starts as a new install.</p>'
                    . $form('keep_kept', '', 'Keep a copy again'))
            . '</div>';
    }

    /**
     * How the ticket updates to MagizAI are getting on (1.10.0), from the last attempt. Updates that
     * fail are not lost: MagizAI also checks for ticket changes every five minutes.
     * @see \MagizAI\Whmcs\Http\TicketEvents::health
     */
    private static function ticketEventsLine($e)
    {
        $h = TicketEvents::health();
        $at = function ($t) use ($e) {
            return $e(gmdate('Y-m-d H:i', (int) $t)) . ' UTC';
        };
        if ($h['blocked'] === 'not_connected' && $h['blocked_at'] > $h['last_attempt_at']) {
            return '<p class="mz-help"><span class="mz-no">Ticket updates are not being sent: the widget key or the bridge secret is empty.</span> '
                . 'Connect with a code above. MagizAI still checks for ticket changes every five minutes.</p>';
        }
        if ($h['last_attempt_at'] === 0) {
            return '<p class="mz-help">No ticket update has been sent to MagizAI yet. Use the test below to check this server can reach it.</p>';
        }
        if ($h['failures'] === 0) {
            return '<p class="mz-help"><span class="mz-ok">✔</span> The last ticket update reached MagizAI at ' . $at($h['last_attempt_at']) . ' (' . $e($h['last_result']) . ').</p>';
        }

        return '<p class="mz-help"><span class="mz-no">The last ticket update did not reach MagizAI, at ' . $at($h['last_attempt_at']) . ': ' . $e($h['last_result'])
            . ($h['failures'] > 1 ? ' (' . (int) $h['failures'] . ' in a row)' : '') . '.</span> '
            . ($h['last_ok_at'] > 0 ? 'The last one that did was at ' . $at($h['last_ok_at']) . '. ' : 'None has got through yet. ')
            . 'Nothing is lost: MagizAI also checks for ticket changes every five minutes. Use the test below to see why.</p>';
    }

    /**
     * "Send chat verification codes by email" (1.7.0), with the state of the template that carries the
     * code. @see \MagizAI\Whmcs\Action\Account\VerificationSend
     */
    private static function verificationRow(array $s)
    {
        $on = (isset($s['verification_email']) ? $s['verification_email'] : 'on') !== 'off';
        $status = EmailTemplate::status();
        $state = array(
            'ok' => '',
            'missing' => ' <span class="mz-no">The email template is missing. It is created again when you save this page with the box ticked, or on the next code sent.</span>',
            'disabled' => ' <span class="mz-no">The email template is switched off under Email Templates, so no code can be emailed. Switch it on there, or untick this box.</span>',
            'unreadable' => ' <span class="mz-no">The email templates could not be read just now.</span>',
        );

        return '<div class="mz-row"><label><input type="checkbox" name="verification_email" value="on"' . ($on ? ' checked' : '') . '> Send chat verification codes by email</label>'
            . '<div class="mz-help">Before the chat shows a service password, the client types a 6-digit code (every other change needs one tap on Confirm). '
            . 'With this on, WHMCS emails that code to the address on the account, so the person chatting must also be able to read that mailbox. '
            . 'It uses your email template "' . EmailTemplate::NAME . '" (System Settings, Email Templates, General Messages), in English, French and Arabic: change the wording there, and WHMCS adds your usual header, footer and signature. '
            . 'Your edits are never overwritten by an update. The code is never written to this module\'s logs. WHMCS keeps the sent email in its email log, as it does for every email it sends. '
            . 'With this off, a password is only sent by email, never shown in the chat.'
            . (isset($state[$status]) ? $state[$status] : '') . '</div></div>';
    }
}
