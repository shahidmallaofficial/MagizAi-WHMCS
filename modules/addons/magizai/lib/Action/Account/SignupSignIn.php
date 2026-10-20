<?php

namespace MagizAI\Whmcs\Action\Account;

use MagizAI\Whmcs\ActionError;
use MagizAI\Whmcs\Action\ActionContext;
use MagizAI\Whmcs\Action\ActionInterface;
use MagizAI\Whmcs\Domain\Signups;
use MagizAI\Whmcs\Settings;
use MagizAI\Whmcs\Support\WhmcsApi;

/**
 * Signs a new client in to the account the chat just opened for them, on their invoice, so they can
 * pay (1.8.0). They have no password yet: WHMCS emailed them a link to choose one.
 *
 * WHMCS's own single sign-on (CreateSsoToken): a link that works once and for at most 60 seconds.
 * MagizAI asks for it at the moment the client taps "Pay", and sends them straight on; it is never
 * shown in the chat, given to the AI, or stored.
 *
 * Only for an account its OWN conversation opened (the bridge records each request's conversation,
 * and ClientSignup recorded which account it opened), in the last few hours, a few times. Never for
 * any other account: there is no client id in the request to change.
 *
 * Not in the chat's catalogue on the MagizAI side, and on exactly when "Open an account" is. @see
 * \MagizAI\Whmcs\Action\Registry::FOLLOWS
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class SignupSignIn implements ActionInterface
{
    public function spec()
    {
        return array('identity' => false, 'write' => false, 'group' => 'sensitive', 'label' => 'Sign a new client in to pay their first invoice');
    }

    public function handle(ActionContext $context)
    {
        $signup = Signups::openedBy($context->conversation());
        $signIn = Settings::systemUrl() . 'clientarea.php';
        if ($signup === null || !Signups::useSignIn($signup->id)) {
            throw new ActionError('not_found', 'This link has expired. They can sign in at ' . $signIn . ' to pay, with "Forgot password?" there if they need a password.');
        }

        $invoiceId = (int) $signup->invoice_id;
        $r = WhmcsApi::api('CreateSsoToken', array(
            'client_id' => (int) $signup->client_id,
            'destination' => 'sso:custom_redirect',
            'sso_redirect_path' => $invoiceId > 0 ? 'viewinvoice.php?id=' . $invoiceId : 'clientarea.php',
        ), false);

        $url = (string) ($r['redirect_url'] ?? '');
        if (($r['result'] ?? '') !== 'success' || !preg_match('#^https?://#i', $url)) {
            throw new ActionError('not_allowed', 'They could not be signed in from here just now. They can sign in at ' . $signIn . ' to pay.',
                'CreateSsoToken: ' . (string) ($r['message'] ?? 'no link'));
        }

        return array(
            'summary' => 'A sign-in link for client #' . (int) $signup->client_id . '.',
            'audit' => 'sign-in link for client #' . (int) $signup->client_id . ($invoiceId > 0 ? ' to invoice #' . $invoiceId : ''),
            'data' => array('url' => $url, 'invoice_id' => $invoiceId),
        );
    }
}
