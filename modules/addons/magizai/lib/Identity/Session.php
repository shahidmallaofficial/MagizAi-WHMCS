<?php

namespace MagizAI\Whmcs\Identity;

/**
 * Who this browser is signed in as, read from WHMCS once: the user, the client account they have
 * selected, and whether a staff member is involved.
 *
 * A plain array comes out, so the decision made from it (IdentityResolver::resolve) can be tested with every
 * combination WHMCS can produce, including ones no test install can.
 *
 * STAFF. WHMCS keeps the admin area and the client area in one PHP session, so a browser can be
 * signed in to both. Two situations are reported:
 *  - masquerading: an admin used "Login as Owner" (CurrentUser::isMasqueradingAdmin). If this WHMCS
 *    has no such method, the answer is "yes": not knowing is not a reason to hand out an identity.
 *  - admin: any admin session in this browser, with or without Login as Owner.
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class Session
{
    /**
     * @return array{whmcs8: bool, user_id: int, user_email: string, user_first: string, user_last: string,
     *               client_id: int, admin_id: int, admin: bool, masquerading: bool}
     */
    public static function current()
    {
        $session = isset($_SESSION) && is_array($_SESSION) ? $_SESSION : array();

        if (class_exists('\WHMCS\Authentication\CurrentUser')) {
            return self::fromCurrentUser(new \WHMCS\Authentication\CurrentUser(), $session);
        }

        return self::fromLegacy($session);
    }

    /**
     * WHMCS 8 and later: the login (a User) is separate from the account (a Client), and one user
     * may manage several accounts.
     *
     * @param object $current \WHMCS\Authentication\CurrentUser
     * @param array  $session the PHP session, for the admin id WHMCS keeps there
     */
    public static function fromCurrentUser($current, array $session)
    {
        $out = self::nobody();
        $out['whmcs8'] = true;

        $out['masquerading'] = method_exists($current, 'isMasqueradingAdmin') ? (bool) $current->isMasqueradingAdmin() : true;

        $adminSignedIn = false;
        if (method_exists($current, 'admin')) {
            $admin = $current->admin();
            if (is_object($admin) && isset($admin->id) && (int) $admin->id > 0) {
                $out['admin_id'] = (int) $admin->id;
                $adminSignedIn = true;
            }
        }
        if (method_exists($current, 'isAuthenticatedAdmin') && $current->isAuthenticatedAdmin()) {
            $adminSignedIn = true;
        }
        if ($out['admin_id'] <= 0 && !empty($session['adminid']) && (int) $session['adminid'] > 0) {
            $out['admin_id'] = (int) $session['adminid'];
            $adminSignedIn = true;
        }
        $out['admin'] = $adminSignedIn || $out['masquerading'];

        $user = $current->user();
        if (is_object($user) && isset($user->id) && (int) $user->id > 0) {
            $out['user_id'] = (int) $user->id;
            $out['user_email'] = self::text($user, 'email');
            $out['user_first'] = self::text($user, 'first_name');
            $out['user_last'] = self::text($user, 'last_name');
        }

        $client = $current->client();
        if (is_object($client) && isset($client->id) && (int) $client->id > 0) {
            $out['client_id'] = (int) $client->id;
        }

        return $out;
    }

    /**
     * Before WHMCS 8 there are no users: the session holds the client ("uid") and, when an admin
     * is signed in in the same browser, the admin ("adminid").
     */
    public static function fromLegacy(array $session)
    {
        $out = self::nobody();
        $out['client_id'] = !empty($session['uid']) ? max(0, (int) $session['uid']) : 0;
        $out['admin_id'] = !empty($session['adminid']) ? max(0, (int) $session['adminid']) : 0;
        $out['admin'] = $out['admin_id'] > 0;

        return $out;
    }

    /** Nobody signed in, nobody on staff. */
    public static function nobody()
    {
        return array(
            'whmcs8' => false, 'user_id' => 0, 'user_email' => '', 'user_first' => '', 'user_last' => '',
            'client_id' => 0, 'admin_id' => 0, 'admin' => false, 'masquerading' => false,
        );
    }

    /** A text attribute of a WHMCS model, or '' when it has none. */
    private static function text($model, $attribute)
    {
        return isset($model->{$attribute}) && is_scalar($model->{$attribute}) ? trim((string) $model->{$attribute}) : '';
    }
}
