<?php

namespace MagizAI\Whmcs\Action\Account;

use MagizAI\Whmcs\ActionError;
use MagizAI\Whmcs\Action\ActionContext;
use MagizAI\Whmcs\Action\ActionInterface;
use MagizAI\Whmcs\Action\Orders\OrderPlace;
use MagizAI\Whmcs\Domain\Signups;
use MagizAI\Whmcs\Schema;
use MagizAI\Whmcs\Settings;
use MagizAI\Whmcs\Support\Args;
use MagizAI\Whmcs\Support\WhmcsApi;
use WHMCS\Config\Setting;
use WHMCS\Database\Capsule;

/**
 * Opens an account for a new client who is buying in the chat, and places their order on it (1.8.0).
 *
 * The one change the chat makes for somebody who is not signed in, so it follows WHMCS's own rules for
 * a new client and nothing looser:
 *  - an account on its own only where WHMCS allows registering without an order ("Allow Client
 *    Registration"); with an order, the way the cart lets a new client register at checkout;
 *  - the terms of service, when WHMCS asks for them ("Enable TOS Acceptance"): MagizAI shows the link
 *    and the client agrees by confirming, and without that agreement nothing is created;
 *  - the country, when WHMCS charges tax: it decides the tax on the invoice, so it is asked, never guessed;
 *  - never a second account for an email address WHMCS already has: they are told to sign in.
 *
 * EVERY ORDER CHECK RUNS BEFORE THE ACCOUNT EXISTS (the plan is on sale, the term is sold, a domain to
 * register is free, a payment method is on), so no account is left behind for an order that could never
 * be placed. MagizAI calls this once with check_only first, to show the terms and the price before the
 * client confirms; that call creates nothing.
 *
 * The account gets name, email, country and phone only; the client completes the rest of their
 * profile in the client area. WHMCS sends its own welcome email, and its password reset email so
 * the client chooses their own password. The new account can be signed in to once from the chat,
 * to pay. @see SignupSignIn
 *
 * Sensitive: off until an admin ticks it, and never switched on by an update. Limited per
 * conversation, per visitor address and per hour. @see \MagizAI\Whmcs\Domain\Signups
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class ClientSignup implements ActionInterface
{
    public function spec()
    {
        return array('identity' => false, 'write' => true, 'group' => 'sensitive', 'label' => 'Open an account for a new client and place their order');
    }

    public function handle(ActionContext $context)
    {
        $args = $context->args();
        $checkOnly = Args::text($args, 'check_only', 3) === '1';
        $ordering = Args::text($args, 'product', 150) !== '';

        if (!$ordering && !Setting::getValue('AllowClientRegister')) {
            throw new ActionError('not_allowed', 'An account is opened here together with an order. Ask what they would like to order, '
                . 'or point them to the store: ' . Settings::systemUrl() . 'cart.php');
        }

        $email = mb_strtolower(Args::text($args, 'email', 191));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new ActionError('invalid_args', 'Ask for the email address the new account should use.');
        }
        $first = Args::text($args, 'first_name', 60);
        $last = Args::text($args, 'last_name', 60);
        if ($first === '') {
            throw new ActionError('invalid_args', 'Ask for their name.');
        }
        $country = self::country($args);
        $ip = (string) filter_var(Args::text($args, 'ip', 45), FILTER_VALIDATE_IP);
        $terms = self::terms();

        Signups::mustNotBeProbing($context->conversation());
        if (self::emailTaken($email)) {
            throw new ActionError('account_exists', 'There is already an account with ' . $email . ', so no new one was opened. '
                . 'They can sign in at ' . Settings::systemUrl() . 'clientarea.php (with "Forgot password?" there if they need it) and ask again here.');
        }

        Signups::mustBeUnderLimits($context->conversation(), $ip);

        // Every order check, before anything exists.
        $check = $ordering ? OrderPlace::place(null, $args, true) : null;

        if ($checkOnly) {
            return array(
                'summary' => 'An account can be opened for ' . $email . ($check ? ', with ' . $check['price'] : '') . '.'
                    . ($terms['required'] ? ' Opening it means agreeing to the terms of service: ' . $terms['url'] : ''),
                'changed' => false,
                'data' => array('terms_required' => $terms['required'], 'terms_url' => $terms['url'], 'order' => $check ? $check['price'] : ''),
            );
        }

        if ($terms['required'] && Args::text($args, 'accepted_terms', 3) !== '1') {
            throw new ActionError('invalid_args', 'They need to agree to the terms of service first: ' . $terms['url']);
        }

        $clientId = self::open($first, $last, $email, $country, Args::text($args, 'phone', 32), $ip);
        $signup = Signups::record($context->conversation(), $clientId, $ip);

        // Their own password, through WHMCS's own reset email to the address they gave.
        $reset = WhmcsApi::api('ResetPassword', array('email' => $email), false);
        $emailed = ($reset['result'] ?? '') === 'success' ? ' WHMCS emailed ' . $email . ' a link to choose a password.' : '';

        if (!$ordering) {
            return self::answer('An account is open for ' . $email . ' (client #' . $clientId . ').' . $emailed, $clientId, null);
        }

        // The account was made here, by this module, a moment ago: placed as its owner, not as a sign-in.
        $previous = ActionContext::current();
        ActionContext::enter(new ActionContext($args, $clientId, array(), false, $context->conversation()));
        try {
            $order = OrderPlace::place($clientId, $args);
        } catch (ActionError $e) {
            // The account stays: it is theirs. Said exactly as it is.
            return self::answer('An account is open for ' . $email . ' (client #' . $clientId . '), but the order could not be placed: '
                . $e->getMessage() . $emailed, $clientId, null);
        } finally {
            ActionContext::enter($previous);
        }
        Signups::attachInvoice($signup, $order['data']['invoice_id']);

        return self::answer('An account is open for ' . $email . ' (client #' . $clientId . '), and order #' . $order['data']['order_id']
            . ' is placed: ' . $order['price'] . '. ' . ((int) $order['data']['invoice_id'] > 0 ? 'Invoice #' . $order['data']['invoice_id']
            . ' is waiting to be paid; the service starts once it is.' : 'It is waiting in the client area.') . $emailed, $clientId, $order);
    }

    /** The answer: what MagizAI tells the client, and what it needs for the sign-in link. Never a password. */
    private static function answer($summary, $clientId, $order)
    {
        return array(
            'summary' => $summary,
            'audit' => 'client #' . $clientId . ' opened' . ($order ? '; ' . $order['audit'] : ''),
            'data' => array(
                'client_id' => (int) $clientId,
                'order_id' => $order ? (int) $order['data']['order_id'] : 0,
                'invoice_id' => $order ? (int) $order['data']['invoice_id'] : 0,
                'product' => $order ? $order['product'] : '',
                'domain' => $order ? $order['data']['domain'] : '',
            ),
        );
    }

    /** @return int the new client's id */
    private static function open($first, $last, $email, $country, $phone, $ip)
    {
        $params = array(
            'firstname' => $first,
            'lastname' => $last,
            'email' => $email,
            'country' => $country,
            // Never told to anyone: they choose their own through the reset email.
            'password2' => bin2hex(random_bytes(16)),
            // Name, email and country are what the chat asks for; the rest of the profile is theirs to complete.
            'skipvalidation' => true,
            'notes' => 'Opened through live chat (MagizAI).',
        );
        if ($phone !== '') {
            $params['phonenumber'] = $phone;
        }
        if ($ip !== '') {
            $params['clientip'] = $ip;
        }

        $r = WhmcsApi::api('AddClient', $params, false);
        if (($r['result'] ?? '') !== 'success' || empty($r['clientid'])) {
            $message = trim((string) ($r['message'] ?? ''));
            throw new ActionError('not_allowed', 'The account could not be opened, so nothing was ordered. Offer to pass this to the team.',
                $message !== '' ? 'AddClient: ' . $message : null);
        }

        return (int) $r['clientid'];
    }

    /** Asked when WHMCS charges tax, because it decides the tax on the invoice; WHMCS's default country otherwise. */
    private static function country(array $args)
    {
        $country = strtoupper(Args::text($args, 'country', 2));
        if (preg_match('/^[A-Z]{2}$/', $country)) {
            return $country;
        }
        if (Setting::getValue('TaxEnabled')) {
            throw new ActionError('invalid_args', 'Ask which country they are in: the tax on the invoice depends on it.');
        }
        $default = strtoupper((string) Setting::getValue('DefaultCountry'));

        return preg_match('/^[A-Z]{2}$/', $default) ? $default : 'US';
    }

    /** @return array{required: bool, url: string} WHMCS's own terms of service setting. */
    private static function terms()
    {
        $url = trim((string) Setting::getValue('TermsOfService'));

        return array(
            'required' => (bool) Setting::getValue('EnableTOSAccept') && preg_match('#^https?://#i', $url),
            'url' => preg_match('#^https?://#i', $url) ? $url : '',
        );
    }

    /** Does WHMCS already have this address, as a client or (WHMCS 8) as a user who signs in? */
    private static function emailTaken($email)
    {
        if (Capsule::table('tblclients')->whereRaw('LOWER(email) = ?', array($email))->exists()) {
            return true;
        }

        return Schema::hasTable('tblusers') && Capsule::table('tblusers')->whereRaw('LOWER(email) = ?', array($email))->exists();
    }
}
