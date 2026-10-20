<?php

namespace MagizAI\Whmcs\Domain;

use MagizAI\Whmcs\Mailer;
use MagizAI\Whmcs\Support\WhmcsApi;
use MagizAI\Whmcs\Text;

/**
 * The client account itself: GetClientsDetails asked once per request, the account's email address
 * masked for an answer, and the email that tells a client their account was changed through chat.
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class Clients
{
    /** GetClientsDetails answers already fetched in this request, by client. @see clientDetails */
    private static $clientDetails = array();

    /**
     * The account's email address, masked for the assistant to repeat ("s***@example.com"), or
     * "the address on the account" when it cannot be read.
     */
    public static function accountEmailMasked($clientId)
    {
        $masked = self::maskedAccountEmail($clientId);

        return $masked !== '' ? $masked : 'the address on the account';
    }

    /**
     * The account's email address, masked ("s***@example.com"), or "" when it cannot be read: for a
     * sentence that reads differently with and without it. @see DomainSendEpp
     *
     * Read from GetClientsDetails' `client` block first. Its top-level copy of the same fields is
     * deprecated by WHMCS and may be removed, after which the address would quietly become vague.
     */
    public static function maskedAccountEmail($clientId)
    {
        $details = self::clientDetails($clientId);

        $address = '';
        if (isset($details['client']) && is_array($details['client']) && isset($details['client']['email'])) {
            $address = (string) $details['client']['email'];
        }
        if (trim($address) === '' && isset($details['email'])) {
            $address = (string) $details['email'];
        }

        $address = strtolower(trim($address));
        $at = strpos($address, '@');

        return $at > 1
            ? substr($address, 0, 1) . str_repeat('*', max(1, $at - 1)) . substr($address, $at)
            : '';
    }

    /**
     * GetClientsDetails for this client, asked once per request, exactly as WHMCS answered (an empty
     * array when it did not answer at all).
     */
    public static function clientDetails($clientId)
    {
        $key = (int) $clientId;

        if (!array_key_exists($key, self::$clientDetails)) {
            $r = WhmcsApi::api('GetClientsDetails', array('clientid' => $key), false);
            self::$clientDetails[$key] = is_array($r) ? $r : array();
        }

        return self::$clientDetails[$key];
    }

    /**
     * Tell the client, by email, that their account was changed through chat.
     *
     * @return bool whether WHMCS sent it; a failure is noted in WHMCS's activity log for staff
     */
    public static function notifyClient($clientId, $text)
    {
        $sent = Mailer::send('general', (int) $clientId, 'A change was made to your account through live chat',
            '<p>' . nl2br(Text::mail($text)) . '</p>'
            . '<p>This was requested and confirmed in live chat while you were signed in. If it was not you, reply to this email or contact us straight away.</p>');

        if (!$sent && function_exists('logActivity')) {
            logActivity('MagizAI live chat: the email telling the client about a change made through chat could not be sent.', (int) $clientId);
        }

        return $sent;
    }

    /** Forget what was remembered for the request that ran last. @see \MagizAI\Whmcs\Action\Runner::forget */
    public static function forget()
    {
        self::$clientDetails = array();
    }
}
