<?php

namespace MagizAI\Whmcs\Hooks;

use MagizAI\Whmcs\Identity\Revocations;

/**
 * UserLogout and ClientLogout: signing out of the client area ends the sign-ins the chat holds.
 * @see Revocations
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class SessionHooks
{
    /**
     * WHMCS 8 and later: a user signed out, so every sign-in token issued to them before now is
     * refused. A Login as Owner session that ends ends only that staff member's staff view, never the
     * client's own sign-ins. @see Revocations::afterUserLogout
     */
    public static function userLogout(array $vars)
    {
        Revocations::afterUserLogout($vars);
    }

    /** Before WHMCS 8, which has no users: the client signed out. @see Revocations::afterClientLogout */
    public static function clientLogout(array $vars)
    {
        Revocations::afterClientLogout($vars);
    }
}
