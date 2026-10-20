<?php

namespace MagizAI\Whmcs\Staff;

use MagizAI\Whmcs\Settings;
use WHMCS\Database\Capsule;

/**
 * The WHMCS admin a reply from the MagizAI inbox is posted as (1.9.0).
 *
 * The one chosen on the Setup tab, while that admin is active. Otherwise the first active Full
 * Administrator: "Show tickets in the MagizAI inbox" is on by default from 1.9.0, and a reply must
 * never be posted as nobody, which WHMCS would record as the client's own words. Empty only when this
 * WHMCS has no active admin at all, and then the inbox is refused.
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class ReplyAs
{
    /** WHMCS's built-in Full Administrator role. */
    const FULL_ADMIN_ROLE = 1;

    public static function username()
    {
        $chosen = trim((string) Settings::get('ticket_admin_username'));
        try {
            if ($chosen !== '' && Capsule::table('tbladmins')->where('username', $chosen)->where('disabled', 0)->exists()) {
                return $chosen;
            }
            $first = Capsule::table('tbladmins')->where('roleid', self::FULL_ADMIN_ROLE)->where('disabled', 0)->orderBy('id')->value('username');

            return is_string($first) ? trim($first) : '';
        } catch (\Throwable $e) {
            // tbladmins could not be read: the admin's own choice is all there is to go on.
            return $chosen;
        }
    }

    /** Whether the reply-as admin is the Setup tab's choice (true) or the fallback (false). */
    public static function isChosen()
    {
        $chosen = trim((string) Settings::get('ticket_admin_username'));

        return $chosen !== '' && $chosen === self::username();
    }
}
