<?php

namespace MagizAI\Whmcs\Action\Services;

use MagizAI\Whmcs\ActionError;
use MagizAI\Whmcs\Action\ActionContext;
use MagizAI\Whmcs\Action\ActionInterface;
use MagizAI\Whmcs\Domain\ControlPanel;
use MagizAI\Whmcs\Domain\Credentials;
use MagizAI\Whmcs\Domain\Ownership;
use MagizAI\Whmcs\Mailer;
use MagizAI\Whmcs\Settings;
use MagizAI\Whmcs\Support\WhmcsApi;
use MagizAI\Whmcs\Text;

/**
 * Reset the password on a hosting account.
 *
 * THE PASSWORD IS NEVER IN THE CHAT. It is generated here, set on the server, and emailed to
 * the address on the account — the same rule as the domain transfer code. A password typed or
 * shown in a conversation is stored in the transcript, visible to every agent who opens it, and
 * still there a year later.
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class ServicePasswordReset implements ActionInterface
{
    public function spec()
    {
        return array('identity' => true, 'write' => true, 'group' => 'sensitive', 'label' => 'Reset a service password and email it to the client');
    }

    public function handle(ActionContext $context)
    {
        $args = $context->args();
        $clientId = $context->clientId();

        $p = Ownership::ownedService($clientId, $args);

        if ($p['status'] !== 'Active') {
            throw new ActionError('not_allowed', 'The password can only be reset on an active service. This one is ' . strtolower($p['status']) . '.');
        }

        $password = Credentials::strongPassword();

        $r = WhmcsApi::api('ModuleChangePw', array(
            'serviceid' => (int) $p['id'],
            'servicepassword' => $password,
        ), false);

        if (($r['result'] ?? '') !== 'success') {
            $message = trim((string) ($r['message'] ?? ''));
            // The panel's own words when they are about the request ("Account not found on
            // server"); when they are about the host's systems, staff get them in the activity
            // log and the client gets the plain version.
            $said = Text::customerSafe($message, '');
            throw new ActionError('registrar_error', 'The server refused the password change'
                . ($said !== '' ? ': ' . $said : '.')
                . ' Nothing was changed. Offer to pass this to the team.',
                $message !== '' ? 'ModuleChangePw: ' . Text::mask(Text::cut(strip_tags($message), 500), array($password)) : null);
        }

        $label = (string) $p['name'] . ($p['domain'] ? ' (' . $p['domain'] . ')' : '');
        $username = isset($p['username']) ? (string) $p['username'] : '';

        $sent = Mailer::send('general', (int) $clientId, 'Your new hosting password',
            '<p>The password for <strong>' . Text::mail($label) . '</strong> has been reset, as you asked in live chat.</p>'
            . '<p>Username: <strong>' . Text::mail($username) . '</strong><br>'
            . 'New password: <strong>' . Text::mail($password) . '</strong></p>'
            . '<p>Sign in and change it to something you will remember. If you did not ask for this, contact us straight away.</p>',
            array($password));

        if (!$sent) {
            /*
             * THE PASSWORD WAS CHANGED AND THE EMAIL DID NOT GO. This used to be reported as
             * "emailed", so the client went looking for an email that never came, with the old
             * password already dead. Say what happened, alert the team in WHMCS's activity log, and
             * when the host allows passwords in chat, offer the new one behind the reveal button.
             */
            if (function_exists('logActivity')) {
                logActivity('MagizAI live chat: the password for service #' . (int) $p['id'] . ' was reset at the client\'s request, '
                    . 'but the email carrying the new password could not be sent. Send the client their login details from the service page.', (int) $clientId);
            }

            $inChat = Settings::get('credentials_in_chat', 'off') === 'on'
                && $context->signedInWithin(Settings::minutes('credentials_freshness_minutes', 15, 5, 240));

            $result = array(
                'summary' => 'The password for ' . $label . ' was changed, but the email with the new one could not be sent. '
                    . 'The team has been alerted' . ($inChat
                        ? '. The new password is on the "Show my login details" button under your reply, which only they can open. '
                            . 'NEVER write a password into your reply yourself. You have not been given it.'
                        : ' and can send it to them. Offer to pass this to the team.'),
                'audit' => 'service #' . $p['id'] . ' password reset; mail_failed',
                'data' => array('delivery' => $inChat ? 'chat' : 'none', 'mail_failed' => true),
            );

            if ($inChat) {
                // STRIPPED BY MAGIZAI BEFORE ANYTHING IS STORED OR SENT TO THE MODEL.
                $result['secret'] = array(
                    'kind' => 'service_credentials',
                    'title' => $label,
                    'username' => $username,
                    'password' => $password,
                    'panel_url' => ControlPanel::panelUrl($p),
                );
            }

            return $result;
        }

        return array(
            'summary' => 'The password for ' . $label
                . ' has been reset and the new one emailed to the address on the account. It is never shown in this chat.'
                . ' Tell them to check their email, including the spam folder.',
            'audit' => 'service #' . $p['id'] . ' password reset',
            // No notify: the email above already says it, and a second one saying the same thing
            // without the password reads like a duplicate.
        );
    }
}
