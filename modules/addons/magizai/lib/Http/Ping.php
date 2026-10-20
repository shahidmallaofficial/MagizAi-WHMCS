<?php

namespace MagizAI\Whmcs\Http;

use MagizAI\Whmcs\Crypto;
use MagizAI\Whmcs\Identity\Signing;
use MagizAI\Whmcs\Identity\Telemetry;
use MagizAI\Whmcs\Identity\PageSettings;
use MagizAI\Whmcs\Install\EmailTemplate;
use MagizAI\Whmcs\Settings;
use MagizAI\Whmcs\Staff\TicketAssist;
use MagizAI\Whmcs\Staff\TicketInbox;

/**
 * What the module says about itself in a signed ping: what MagizAI's connection test reports and
 * what its health checks compare against.
 *
 * Every field is optional on MagizAI's side, and nothing here is ever a secret: secrets are reported
 * as "ok | missing | unreadable", and the identity secret as a fingerprint that proves two secrets are
 * the same without showing either.
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class Ping
{
    /**
     * What this version of the module can do, for MagizAI to read. Only what is built: a later stage
     * adds its own names here as it ships them.
     */
    const FEATURES = array(
        'identity_reasons', 'token_endpoint', 'staff_view', 'revocation',
        // What the actions can do (1.6.0). Whether each one is switched on is in enabled_actions.
        'sso_link', 'payment_status', 'order_status', 'renewal_status', 'email_history', 'email_resend',
        // 1.7.0: verification_send emails the chat's confirmation code from WHMCS's own email system.
        'verification_email',
        // 1.8.0: closing a ticket, and opening an account (with the order) for a new client.
        'ticket_close', 'client_signup',
        // 1.8.1: login details without the password (deliver=none). @see Action\Services\ServiceCredentials
        'credentials_overview',
        // 1.9.0: WHMCS tells MagizAI when a ticket changes (Hooks\TicketHooks), and the inbox can set a
        // ticket's status (Staff\TicketStatus).
        'ticket_events', 'ticket_status',
        // 1.10.0: a domain's registrar lock and its contact (WHOIS) details.
        'domain_set_lock', 'domain_update_contacts',
        // 1.10.0: paying an invoice from the account credit.
        'invoice_apply_credit',
        // 1.10.0: the server commands the admin lists (reboot and the like). Which ones is in module_commands.
        'service_module_command',
        // 1.10.0: the MagizAI panel on the admin ticket page (Staff\TicketAssist). Whether it is on: ticket_assist below.
        'ticket_assist',
        // 1.10.0: how the ticket events are getting on (ticket_events_health below), and the test event.
        'ticket_events_health', 'ticket_events_test',
        // 1.10.0: the inbox can set a ticket's priority and department (Staff\TicketUpdate).
        'ticket_update',
    );

    /**
     * The ping's data.
     *
     * Also hands MagizAI this install's Ed25519 public key: made now if MagizAI has said it can check
     * one and there is none yet, and recorded as delivered. @see Signing
     *
     * @param bool $deliverable false when the bridge already knows this reply will not verify at
     *                          MagizAI (output held in another module's buffer goes out ahead of it),
     *                          or when the ping did not say it can check Ed25519: the key still goes
     *                          in the reply, but is not counted as delivered
     * @return array<string, mixed>
     */
    public static function data($moduleVersion, $deliverable = true)
    {
        try {
            $keys = Signing::publish($deliverable);
        } catch (\Throwable $e) {
            // A key that cannot be made or read now is not a reason for the ping to fail.
            $keys = Signing::publicKeys();
        }

        $secret = Settings::get('identity_secret');
        $placement = PageSettings::placement();

        return array(
            'module_version' => (string) $moduleVersion,
            // As WHMCS writes it ("9.0.8-release.1"), unchanged since 1.0: MagizAI reads it this way.
            'whmcs_version' => (string) \WHMCS\Config\Setting::getValue('Version'),
            'enabled_actions' => Settings::enabledActions(),
            'identity_configured' => $secret !== '',
            // So MagizAI's connection test can say whether passwords reach the chat, instead of the
            // merchant having to remember whether they ticked a box on a different screen.
            'credentials_in_chat' => Settings::get('credentials_in_chat', 'off') === 'on',
            // Whether "Send chat verification codes by email" is on, and the state of the email template
            // that carries the code: ok | missing (created again on the next send) | disabled | unreadable.
            'verification_email' => Settings::get('verification_email', 'on') !== 'off',
            'verification_template' => EmailTemplate::status(),
            'identity_ttl_minutes' => PageSettings::ttlMinutes(),
            // A secret saved but no longer readable (WHMCS's encryption key changed) looks exactly like
            // one never entered. ok | missing | unreadable for each, never the secret itself.
            'secret_health' => Settings::secretHealth(),
            // Equal to MagizAI's own fingerprint of its secret exactly when the two secrets are equal.
            'identity_fp' => Crypto::fingerprint($secret),
            'placement' => $placement,
            'staff_mode' => PageSettings::staffMode(),
            // Whether index.php?m=magizai&action=identity hands out sign-ins: exactly when the pages
            // carry one. @see PageSettings::onPages
            'token_endpoint' => PageSettings::onPages(),
            'identity_signing' => Signing::mode(),
            'identity_keys' => $keys,
            'identity_telemetry' => Telemetry::report(),
            'features' => self::features(),
            // 1.9.0: whether tickets show in the MagizAI inbox (switched on, with an admin to reply as).
            // MagizAI connects the inbox by itself when this is true, posting nothing to any ticket.
            'tickets_inbox' => TicketInbox::enabled(),
            // 1.10.0: whether the MagizAI panel shows on the admin ticket page.
            'ticket_assist' => TicketAssist::enabled(),
            // 1.10.0: the last ticket event sent to MagizAI and what came back, so a push that never
            // arrives can be seen from MagizAI too. @see TicketEvents::report
            'ticket_events_health' => TicketEvents::report(),
        );
    }

    /** @return string[] */
    public static function features()
    {
        $features = self::FEATURES;
        if (Crypto::sodiumAvailable()) {
            $features[] = 'eddsa';
        }

        return $features;
    }
}
