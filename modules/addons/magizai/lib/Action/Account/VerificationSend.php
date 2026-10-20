<?php

namespace MagizAI\Whmcs\Action\Account;

use MagizAI\Whmcs\ActionError;
use MagizAI\Whmcs\Action\ActionContext;
use MagizAI\Whmcs\Action\ActionInterface;
use MagizAI\Whmcs\Action\Permissions;
use MagizAI\Whmcs\Domain\Clients;
use MagizAI\Whmcs\Identity\Verifier;
use MagizAI\Whmcs\Install\EmailTemplate;
use MagizAI\Whmcs\Persistence\AuditLog;
use MagizAI\Whmcs\Settings;
use MagizAI\Whmcs\Support\WhmcsApi;
use MagizAI\Whmcs\Text;

/**
 * Emails the signed-in client a 6-digit code from WHMCS itself, to type in the chat (1.7.0).
 *
 * WHY. Before a change (nameservers, a cancellation, an order) or a login reveal, MagizAI asks the
 * visitor to type a code. Shown in the chat, that code proves the visitor meant it, not that they
 * control the account. Sent by WHMCS to the address on the account, it proves both.
 *
 * MAGIZAI MAKES THE CODE AND KEEPS ONLY ITS HASH; THIS MODULE ONLY DELIVERS IT. The code arrives in
 * the signed request and leaves in the email, through the merchant's own "MagizAI verification code"
 * template (WHMCS adds their header, footer and branding). It is never stored here, never written to
 * this module's activity log, WHMCS's activity log or its module log, and never sent back: the reply
 * says only where the email went, masked. WHMCS's own email log keeps the sent email, as it keeps
 * every email WHMCS sends.
 *
 * THE ADDRESS IS THE ACCOUNT'S OWN, from the client the bridge verified. Nothing in the arguments can
 * choose it. The template is a "general" email, so WHMCS also copies it to any contact the client
 * set to receive general emails, as it does for every general email.
 *
 * Refused in staff view (a staff member testing the chat must not email the client), to a team member
 * without the permission the code's purpose needs, past five codes an hour per account and per user,
 * and when the merchant has switched the setting off or disabled the template. MagizAI's confirmation
 * step reads the refusal and decides what the visitor is told.
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class VerificationSend implements ActionInterface
{
    /** How many codes may be emailed for one account (and one user) in an hour. */
    const PER_HOUR = 5;

    /** What a code may be for. Anything else reads as "other", so a newer MagizAI still works. */
    const PURPOSES = array('nameservers', 'credentials', 'order', 'cancel', 'password_reset', 'renew', 'other');

    /**
     * The refusals besides identity_*, staff_write_refused and not_allowed (the user may not do what
     * the code is for). MagizAI's confirmation step reads them.
     */
    const REFUSALS = array('not_enabled', 'rate_limited', 'send_failed', 'invalid_args');

    /**
     * WHMCS's own permissions the signed-in USER needs for what the code is for, the same ones the
     * action itself needs (Permissions::NEEDED). A team member the owner kept away from domains gets
     * no code for a nameserver change: it could only fill the owner's mailbox. A purpose that covers
     * actions needing different permissions (a password reset: the user's own sign-in or a service)
     * needs none here; the action checks its own when it runs.
     */
    const PURPOSE_PERMISSIONS = array(
        'nameservers' => array('domains', 'managedomains'), 'credentials' => array('products'), 'order' => array('orders'),
        'cancel' => array('products', 'orders'), 'password_reset' => array(), 'renew' => array('orders'), 'other' => array(),
    );

    const CHANNEL = 'whmcs_email';

    /** What the activity row says when a code went: the hourly limit counts these rows. Never the code. */
    const SENT = 'verification code emailed';

    const LABEL_MAX = 120;

    /** What the email says the code is for when MagizAI sends no label, by chat language. */
    const LABELS = array(
        'en' => array('nameservers' => 'Change the nameservers of your domain', 'credentials' => 'Show the login details of your service',
            'order' => 'Place an order', 'cancel' => 'Cancel a service', 'password_reset' => 'Reset a password',
            'renew' => 'Renew a service', 'other' => 'Make a change to your account'),
        'fr' => array('nameservers' => 'Modifier les serveurs de noms de votre domaine', 'credentials' => 'Afficher les identifiants de votre service',
            'order' => 'Passer une commande', 'cancel' => 'Annuler un service', 'password_reset' => 'Réinitialiser un mot de passe',
            'renew' => 'Renouveler un service', 'other' => 'Modifier votre compte'),
        'ar' => array('nameservers' => 'تغيير خوادم الأسماء لنطاقك', 'credentials' => 'عرض بيانات تسجيل الدخول لخدمتك',
            'order' => 'تقديم طلب شراء', 'cancel' => 'إلغاء خدمة', 'password_reset' => 'إعادة تعيين كلمة مرور',
            'renew' => 'تجديد خدمة', 'other' => 'إجراء تغيير على حسابك'),
    );

    public function spec()
    {
        return array('identity' => true, 'write' => false, 'group' => 'read', 'label' => 'Email the client a 6-digit code from WHMCS to confirm a change or a login reveal in the chat (switched on and off on the Setup tab)');
    }

    /** Is "Send chat verification codes by email" on? On unless an admin unticked it. */
    public static function enabled()
    {
        return Settings::get('verification_email', 'on') !== 'off';
    }

    public function handle(ActionContext $context)
    {
        $args = $context->args();
        $clientId = (int) $context->clientId();

        // The bridge refuses it in staff view before it gets here. This is the second lock.
        if ($context->staffView()) {
            throw new ActionError('staff_write_refused', Verifier::message('staff_write_refused'));
        }
        if (!self::enabled()) {
            throw new ActionError('not_enabled', 'This billing system does not email chat verification codes.');
        }

        $code = isset($args['code']) && is_scalar($args['code']) ? (string) $args['code'] : '';
        if (!preg_match('/^\d{6}$/', $code)) {
            throw new ActionError('invalid_args', 'No verification code was emailed: the request did not carry a 6-digit code.');
        }

        $purpose = isset($args['purpose']) && is_scalar($args['purpose']) ? strtolower(trim((string) $args['purpose'])) : '';
        $purpose = in_array($purpose, self::PURPOSES, true) ? $purpose : 'other';
        $locale = strtolower(substr(isset($args['locale']) && is_scalar($args['locale']) ? trim((string) $args['locale']) : '', 0, 2));
        $locale = isset(self::LABELS[$locale]) ? $locale : 'en';
        $minutes = isset($args['expires_minutes']) && is_numeric($args['expires_minutes']) ? (int) $args['expires_minutes'] : 10;
        $minutes = max(1, min(60, $minutes));
        $label = self::label(isset($args['action_label']) && is_scalar($args['action_label']) ? (string) $args['action_label'] : '', $purpose, $locale);

        if (self::PURPOSE_PERMISSIONS[$purpose]) {
            Permissions::mustMay($clientId, self::PURPOSE_PERMISSIONS[$purpose]);
        }

        try {
            $recent = AuditLog::recentSuccesses('verification_send', $clientId, $context->claimedUserId(), 3600);
        } catch (\Throwable $e) {
            throw new ActionError('send_failed', 'The verification email could not be sent just now, so nothing went out.',
                'the activity log could not be read, so the hourly limit could not be counted: ' . $e->getMessage());
        }
        if ($recent['client'] >= self::PER_HOUR || $recent['user'] >= self::PER_HOUR) {
            throw new ActionError('rate_limited', 'Verification codes can be emailed at most ' . self::PER_HOUR . ' times an hour for an account, '
                . 'and that limit has been reached, so nothing was sent. The latest code may be in the inbox or the spam folder.');
        }

        $masked = Clients::maskedAccountEmail($clientId);
        if ($masked === '') {
            throw new ActionError('send_failed', 'The verification email could not be sent: no email address on the account could be read.',
                'GetClientsDetails gave no email address for client #' . $clientId);
        }

        self::mustHaveTemplate();

        $failure = self::send($clientId, $code, array(
            'magizai_code' => $code,
            'magizai_expires_minutes' => (string) $minutes,
            // Escaped once, here: WHMCS puts merge fields into the HTML as they are.
            'magizai_action' => Text::mail($label),
            'magizai_purpose' => $purpose,
            'magizai_locale' => $locale,
        ));
        if ($failure !== null) {
            if (function_exists('logActivity')) {
                logActivity('MagizAI live chat: a verification code email could not be sent to this client: ' . $failure
                    . '. Check the mail settings and the "' . EmailTemplate::NAME . '" email template.', $clientId);
            }
            throw new ActionError('send_failed', 'The verification email could not be sent just now, so nothing went out.',
                'WHMCS did not send the verification email: ' . $failure);
        }

        return array(
            'summary' => 'A 6-digit verification code was emailed to ' . $masked . ' by the billing system. It works once and expires in '
                . $minutes . ' minutes. Ask the client to type it in the chat. The code is not shown here, and it may take a minute or land in spam.',
            'audit' => self::SENT . ' (' . $purpose . ') to ' . $masked,
            'data' => array('sent_to' => $masked, 'channel' => self::CHANNEL),
        );
    }

    /** The template, usable: created again when missing, refused when the merchant switched it off. */
    private static function mustHaveTemplate()
    {
        $status = EmailTemplate::status();
        if ($status === 'missing') {
            try {
                EmailTemplate::install();
            } catch (\Throwable $e) {
                // Reported as missing just below.
            }
            $status = EmailTemplate::status();
        }

        if ($status === 'disabled') {
            throw new ActionError('not_enabled', 'This billing system does not email chat verification codes.',
                'the "' . EmailTemplate::NAME . '" email template is disabled under System Settings, Email Templates');
        }
        if ($status !== 'ok') {
            throw new ActionError('send_failed', 'The verification email could not be sent just now, so nothing went out.',
                'the "' . EmailTemplate::NAME . '" email template is ' . $status . ' and could not be created');
        }
    }

    /**
     * The label the email shows: one line of plain text, no markup, no web address (an email from the
     * merchant's own system must never carry a link the chat put there). MagizAI's when it is usable,
     * otherwise this purpose's own words in the chat's language.
     */
    private static function label($raw, $purpose, $locale)
    {
        $text = (string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', strip_tags($raw));
        $text = trim((string) preg_replace('/\s+/u', ' ', str_replace(array('<', '>', '{', '}'), '', $text)));

        if ($text === '' || preg_match('~(://|\bwww\.|\bhttps?\b)~i', $text)) {
            $text = self::LABELS[$locale][$purpose];
        }

        return function_exists('mb_substr') ? mb_substr($text, 0, self::LABEL_MAX) : substr($text, 0, self::LABEL_MAX);
    }

    /**
     * Send the template with WHMCS's own SendEmail and believe its answer.
     *
     * The custom variables go as WHMCS documents them (a base64 serialized array) and are never
     * handed to a log: on a failure the module log gets the template and the client, and WHMCS's
     * answer with the code masked out of it.
     *
     * @return string|null null when WHMCS says it went, otherwise what WHMCS said, without the code
     */
    private static function send($clientId, $code, array $vars)
    {
        $params = array('messagename' => EmailTemplate::NAME, 'id' => (int) $clientId, 'customvars' => base64_encode(serialize($vars)));

        try {
            $response = WhmcsApi::api('SendEmail', $params, false);
        } catch (\Throwable $e) {
            $response = array('result' => 'error', 'message' => $e->getMessage());
        }

        if (is_array($response) && isset($response['result']) && $response['result'] === 'success') {
            return null;
        }

        $said = is_array($response) && isset($response['message']) ? (string) $response['message'] : 'no answer';
        $said = Text::mask(Text::cut(strip_tags($said), 300), array($code));

        if (function_exists('logModuleCall')) {
            logModuleCall('magizai', 'SendEmail', array('messagename' => EmailTemplate::NAME, 'id' => (int) $clientId,
                'customvars' => '[not logged: it carries the verification code]'), array('result' => 'error', 'message' => $said), '', array());
        }

        return $said;
    }
}
