<?php

namespace MagizAI\Whmcs\Install;

/**
 * The value of every setting nobody has saved (split out of Settings in 1.9.0). A new install is
 * activated with these; an upgrade keeps what the install runs with (Upgrade).
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class Defaults
{
    /** @return array<string, string> every default except the action list (Settings::defaults) */
    public static function base()
    {
        return array(
            'magizai_url' => 'https://magiz.ai',
            'widget_key' => '',
            'identity_secret' => '',
            'bridge_secret' => '',
            'api_token' => '',
            /*
             * all       the chat on every client-area page
             * loggedin  the chat only for a signed-in client
             * self      the merchant embeds the chat; the module adds only the sign-in
             * off       MagizAI is not used on WHMCS pages: nothing at all
             * (An older stored "clientarea" reads as "all".) @see Widget::placement
             */
            'widget_placement' => 'all',
            /*
             * How long one identity token lives. 15 for a NEW install: the chat now asks the module
             * for a fresh token before one runs out (Http\TokenEndpoint), so a short life costs a
             * client nothing. Installs from before keep what they run with (the 1.6.0 upgrade step
             * writes 60 if none was ever stored).
             */
            'identity_ttl_minutes' => '15',
            /*
             * STAFF VIEW. "off": a staff member signed in to the admin area, or using Login as Owner,
             * gets no identity and the chat is told why. "read_only": they get a token marked as
             * staff, the bridge answers look-ups and refuses every change, and each look-up is
             * recorded against the admin. Off unless an admin here chooses otherwise; an upgrade
             * never switches it on.
             */
            'staff_mode' => 'off',
            /*
             * How identity tokens are signed. "auto": with the shared identity secret (HS256) until
             * MagizAI can check this install's own Ed25519 key, then with that key. "hs256": always
             * the shared secret (an admin's override). @see Identity\Signing
             */
            'identity_signing' => 'auto',
            // Kept by Identity\Signing: the key pair (the private half encrypted) and when MagizAI
            // last said it can check Ed25519, was first sent the public key, last received it once
            // it counts as delivered (two pings an hour apart), and when tokens switched.
            'identity_signing_key' => '',
            'identity_public_key' => '',
            'identity_capability_seen_at' => '',
            'identity_key_offered_at' => '',
            'identity_key_published_at' => '',
            'identity_eddsa_since' => '',
            /*
             * How recently a client's sign-in must have been confirmed before a change is accepted: the
             * token's issue time, set by a client-area page or by the chat fetching a fresh token
             * while the client was still signed in (Http\TokenEndpoint). Not a page load as such.
             *
             * 15 for a NEW install. It used to be 60, the same as the token lifetime, so it added
             * nothing: any token still alive was "fresh". Installs from before keep the value they
             * were activated with (the 1.6.0 upgrade step writes 60 if none was ever stored).
             */
            'write_freshness_minutes' => '15',
            /*
             * How recently the sign-in must have been made for a service PASSWORD to be shown in the
             * chat. Older than this and it is emailed instead. A password on screen is the one read
             * that cannot be taken back, so it asks for more than an ordinary account lookup.
             */
            'credentials_freshness_minutes' => '15',
            'writes_per_client_per_hour' => '10',
            // 1.8.0: accounts the chat may open for new clients in an hour, across the install. @see Domain\Signups
            'signups_per_hour' => '5',
            'notify_client_on_change' => 'on',
            /*
             * May a service PASSWORD be shown in the chat window?
             *
             * OFF, and it stays off until a human here ticks it. Emailing the details to the address
             * on the account answers the same question with none of the exposure, so that is what an
             * unconfigured install does. Turning this on puts a live password on a screen that may be
             * in an office, on a shared machine or being screen-shared — which is a decision about
             * your clients' security, and not one this module should make for you.
             */
            'credentials_in_chat' => 'off',
            // 1.7.0: the code a client types in the chat goes by email, from WHMCS. @see Action\Account\VerificationSend
            'verification_email' => 'on',
            'ticket_department_id' => '',
            /*
             * TICKETS IN THE MAGIZAI INBOX. 1.7.0 added them switched off. From 1.9.0 they are on for
             * a new install and for an upgrade from a version without the setting (Install\Upgrade):
             * tickets are shared only with the MagizAI workspace this module is connected to, and
             * MagizAI never posts to a ticket unless that workspace chose AI suggestions or AI replies.
             * Replies from the inbox go out as the admin chosen here, or the first active Full
             * Administrator. @see Staff\TicketInbox, Staff\ReplyAs
             */
            'tickets_inbox' => 'on',
            /*
             * 1.10.0: the MagizAI panel on the admin ticket page (draft, improve, translate, summarise).
             * On unless an admin unticks it on the Setup tab. It sends a ticket to MagizAI only when a
             * staff member presses one of its buttons. @see Staff\TicketAssist
             */
            'ticket_assist' => 'on',
            'ticket_admin_username' => '',
            /*
             * 1.10.0: the server commands a client may run from the chat (reboot and the like), empty
             * until an admin lists them, and how many one client may run in an hour.
             * @see \MagizAI\Whmcs\Domain\ModuleCommands
             */
            'module_commands' => '',
            'module_commands_per_hour' => '3',
            'sync_kb' => 'on',
            'sync_announcements' => 'on',
            'sync_products' => 'on',
            'sync_company_facts' => 'on',
            'last_sync_at' => '',
            'last_sync_result' => '',
            /*
             * Keep a copy of these settings, secrets included, that survives deactivation (on), or
             * let deactivation remove everything (off, chosen with "Forget the kept copy"). Back to
             * on whenever the module is activated afresh. @see Store::KEPT
             */
            'keep_on_deactivate' => 'on',
            // The daily update check. Remembered so opening the admin page twenty times in an
            // afternoon is one outbound request, and so an unreachable server is retried tomorrow
            // rather than on every page load.
            'update_checked_at' => '0',
            'update_check_result' => '',
        );
    }
}
