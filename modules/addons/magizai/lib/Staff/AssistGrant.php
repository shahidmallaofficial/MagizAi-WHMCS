<?php

namespace MagizAI\Whmcs\Staff;

use MagizAI\Whmcs\Settings;

/**
 * The ticket assistant's pass (1.10.0): made when WHMCS shows an admin a ticket page, for that admin
 * and that ticket, and good for four hours. WHMCS shows the page only to staff it lets open that
 * ticket, so a request carrying a pass proves this admin was shown this ticket. The pass also stops
 * another site making the request for them: only the ticket page has it.
 *
 * Signed with the bridge secret (HMAC-SHA256, a label of its own so it can never be mistaken for a
 * bridge signature). Nothing secret is in it: an admin id, a ticket id and when it ends.
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class AssistGrant
{
    const LIFETIME = 14400;

    const LABEL = 'magizai-ticket-assist-v1';

    /** @return string '' when there is no bridge secret to sign with */
    public static function mint($adminId, $ticketId)
    {
        $secret = Settings::get('bridge_secret');
        if ($secret === '' || (int) $adminId < 1 || (int) $ticketId < 1) {
            return '';
        }
        $payload = (int) $adminId . '.' . (int) $ticketId . '.' . (time() + self::LIFETIME);

        return $payload . '.' . hash_hmac('sha256', self::LABEL . '|' . $payload, $secret);
    }

    /** Is this pass this admin's, for this ticket, and still good? */
    public static function valid($grant, $adminId, $ticketId)
    {
        $secret = Settings::get('bridge_secret');
        if ($secret === '' || !is_string($grant) || !preg_match('/^(\d{1,10})\.(\d{1,10})\.(\d{9,12})\.([a-f0-9]{64})$/', $grant, $m)) {
            return false;
        }
        $payload = $m[1] . '.' . $m[2] . '.' . $m[3];

        return hash_equals(hash_hmac('sha256', self::LABEL . '|' . $payload, $secret), $m[4])
            && (int) $m[1] === (int) $adminId && (int) $adminId > 0
            && (int) $m[2] === (int) $ticketId && (int) $ticketId > 0
            && (int) $m[3] >= time();
    }

    /** The ticket a pass is for, or 0. Read before the pass is checked against it. */
    public static function ticketOf($grant)
    {
        return is_string($grant) && preg_match('/^\d{1,10}\.(\d{1,10})\./', $grant, $m) ? (int) $m[1] : 0;
    }
}
