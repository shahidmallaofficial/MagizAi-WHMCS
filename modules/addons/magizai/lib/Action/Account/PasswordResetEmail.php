<?php

namespace MagizAI\Whmcs\Action\Account;

use MagizAI\Whmcs\ActionError;
use MagizAI\Whmcs\Action\ActionContext;
use MagizAI\Whmcs\Action\ActionInterface;
use MagizAI\Whmcs\Install\Tables;
use MagizAI\Whmcs\Settings;
use MagizAI\Whmcs\Support\WhmcsApi;
use WHMCS\Database\Capsule;

/**
 * Emails a password reset link to the signed-in user's own address, or explains how to reset a
 * password when nobody is signed in.
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class PasswordResetEmail implements ActionInterface
{
    public function spec()
    {
        // identity 'optional': a visitor who forgot their password cannot sign in first.
        return array('identity' => 'optional', 'write' => false, 'group' => 'read', 'label' => 'Password reset (email a reset link to the signed-in user, or explain how)');
    }

    public function handle(ActionContext $context)
    {
        $clientId = $context->clientId();

        $signIn = Settings::systemUrl() . 'clientarea.php';

        if (!$clientId) {
            return array('summary' => 'The visitor is not signed in, so no email was sent from the chat. Tell them to open ' . $signIn
                . ', choose the forgotten password option on the sign-in page and enter their email address. WHMCS then emails them a secure link to set a new password.');
        }

        // The person chatting is the USER in the signed identity (WHMCS 8 separates users from client
        // accounts). The reset goes to that user's own login address, and only if they belong to this client.
        $claims = $context->claims();
        $email = strtolower(trim(isset($claims['email']) ? (string) $claims['email'] : ''));
        if ($email === '') {
            throw new ActionError('not_allowed', 'The sign-in does not include an email address, so a reset email cannot be sent from the chat. The client can use the forgotten password option on the sign-in page: ' . $signIn);
        }

        $details = WhmcsApi::api('GetClientsDetails', array('clientid' => (int) $clientId), false);
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
        $recent = (int) Capsule::table(Tables::AUDIT)
            ->where('client_id', (int) $clientId)->where('action', 'password_reset_email')->where('outcome', 'ok')
            ->where('created_at', '>=', date('Y-m-d H:i:s', time() - 3600))->count();
        if ($recent >= 3) {
            throw new ActionError('not_allowed', 'Several reset emails were already sent in the last hour. Ask the client to check their inbox and spam folder, or wait a little and try again.');
        }

        WhmcsApi::api('ResetPassword', array('id' => $userId));

        $at = strpos($email, '@');
        $masked = $at > 1 ? substr($email, 0, 1) . str_repeat('*', max(1, $at - 1)) . substr($email, $at) : $email;

        return array(
            'summary' => 'WHMCS has started a password reset for the signed-in user. A link to choose a new password is being emailed to ' . $masked
                . '. It can take a few minutes, and it may land in spam. The password is never set or shown in the chat.',
            'audit' => 'user #' . $userId,
        );
    }
}
