<?php

namespace MagizAI\Whmcs\Identity;

use MagizAI\Whmcs\Crypto;
use MagizAI\Whmcs\Install\Tables;
use MagizAI\Whmcs\Schema;
use WHMCS\Database\Capsule;

/**
 * Signing out of the client area ends the sign-ins the chat holds.
 *
 * WHY. A token is a bearer credential that lives for its whole lifetime. A client who signed out in
 * one tab left the chat in another tab, or a later visitor at the same computer, holding a token
 * that still opened their account until it expired.
 *
 * HOW. When a user signs out, the moment is recorded for that user. The bridge then refuses any
 * token for that user issued at or before it, with `identity_revoked`, and MagizAI drops the token it
 * holds for that conversation. A new sign-in mints a new token, issued after the sign-out, which
 * works. The comparison is in milliseconds (the token's `iatm`): a client who signs out and in again
 * within the same second keeps the new sign-in and loses the old one.
 *
 * Who is revoked:
 *  - WHMCS 8 and later: the USER who signed out (UserLogout). Every token names its user, so other
 *    users of the same account are not signed out by it.
 *  - Before WHMCS 8, which has no users: the CLIENT (ClientLogout). Tokens without a user are only
 *    ever compared with these.
 *  - Login as Owner ending: the STAFF MEMBER's staff view, never the client. If WHMCS reports the
 *    end of a Login as Owner session as a sign-out of the owner, the session still says an admin was
 *    masquerading, and only the staff view tokens minted for that admin are ended ("staff:<admin
 *    id>"). The owner did not sign out anywhere: their own sign-ins on their own devices stay.
 *
 * Rows only need to outlive the longest token (24 hours); the daily cron removes older ones.
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class Revocations
{
    /** Rows older than this can no longer matter: no token lives longer than MAX_TOKEN_LIFETIME. */
    const KEEP_SECONDS = 90000; // 25 hours

    /**
     * The UserLogout hook (WHMCS 8 and later): $vars['user'] is the user who signed out.
     *
     * @return bool whether a sign-out was recorded
     */
    public static function afterUserLogout(array $vars)
    {
        try {
            $session = Session::current();
        } catch (\Throwable $e) {
            // Not knowing who this session was is not a reason to lose the sign-out: the user named
            // below is revoked, the safe side.
            $session = Session::nobody();
        }
        if (!empty($session['masquerading'])) {
            // Login as Owner ended. Only this admin's staff view ends; with no admin to name, no
            // staff view token can have been minted in this session, so there is nothing to end.
            return $session['admin_id'] > 0 ? self::revoke('staff', $session['admin_id']) : false;
        }

        $user = isset($vars['user']) ? $vars['user'] : null;
        $userId = 0;
        if (is_object($user) && isset($user->id)) {
            $userId = (int) $user->id;
        } elseif (is_array($user) && isset($user['id'])) {
            $userId = (int) $user['id'];
        } elseif (isset($vars['userid']) && is_numeric($vars['userid'])) {
            $userId = (int) $vars['userid'];
        }

        if ($userId > 0) {
            return self::revoke('uid', $userId);
        }

        // No user named: the account this session was using, so the sign-out is not lost.
        if ($session['user_id'] > 0) {
            return self::revoke('uid', $session['user_id']);
        }

        return $session['client_id'] > 0 ? self::revoke('client', $session['client_id']) : false;
    }

    /**
     * The ClientLogout hook. Only before WHMCS 8: from 8 on, tokens name their user and UserLogout
     * revokes exactly that user, never every user of the account.
     */
    public static function afterClientLogout(array $vars)
    {
        if (class_exists('\WHMCS\Authentication\CurrentUser')) {
            return false;
        }

        $clientId = isset($vars['userid']) ? (int) $vars['userid'] : 0;

        return $clientId > 0 ? self::revoke('client', $clientId) : false;
    }

    /**
     * Record a sign-out: tokens for this user (or client, or this admin's staff view) issued at or
     * before now are refused. Never throws: a sign-out must work whatever happens here.
     *
     * @param string $kind 'uid', 'client' or 'staff' (an admin id)
     * @return bool whether it was recorded
     */
    public static function revoke($kind, $id, $nowMs = null)
    {
        $subject = self::subject($kind, $id);
        if ($subject === '') {
            return false;
        }

        $ms = $nowMs === null ? Crypto::nowMs() : (int) $nowMs;

        try {
            // The upgrade step may not have run yet: a sign-out is not lost for that reason.
            if (!Schema::hasTable(Tables::REVOCATIONS)) {
                Tables::installIdentityTables();
            }

            $existing = Capsule::table(Tables::REVOCATIONS)->where('subject', $subject)->value('revoked_before_ms');
            $row = array('revoked_before_ms' => max($ms, (int) $existing), 'updated_at' => time());

            if ($existing !== null) {
                Capsule::table(Tables::REVOCATIONS)->where('subject', $subject)->update($row);
            } else {
                try {
                    Capsule::table(Tables::REVOCATIONS)->insert(array('subject' => $subject) + $row);
                } catch (\Exception $e) {
                    // Signed out twice at once: the other request inserted the row first.
                    Capsule::table(Tables::REVOCATIONS)->where('subject', $subject)->update($row);
                }
            }

            return true;
        } catch (\Exception $e) {
            if (function_exists('logModuleCall')) {
                logModuleCall('magizai', 'identity_revoke', array('subject' => $subject), $e->getMessage(), '', array());
            }

            return false;
        }
    }

    /**
     * Was this token issued at or before its user (or, for a token without a user, its client)
     * signed out, or, for a staff view token, before its admin's Login as Owner session ended?
     *
     * A missing table means nothing was ever revoked (a sign-out creates it). A database that cannot
     * be read is not an answer either way, and is thrown for the bridge to report.
     *
     * @param array $claims verified claims
     * @throws \Exception when the table cannot be read
     */
    public static function revokes(array $claims)
    {
        return self::revocation($claims) !== '';
    }

    /**
     * Why this token no longer counts: 'revoked' (its user or client signed out after it was issued),
     * 'staff_revoked' (the staff view it was minted for ended after it was issued), or '' when it
     * still counts.
     *
     * @param array $claims verified claims
     * @return string
     * @throws \Exception when the table cannot be read
     */
    public static function revocation(array $claims)
    {
        $uid = isset($claims['uid']) && is_scalar($claims['uid']) && ctype_digit((string) $claims['uid']) ? (int) $claims['uid'] : 0;
        $client = isset($claims['external_id']) ? $claims['external_id'] : (isset($claims['sub']) ? $claims['sub'] : '');
        $clientId = is_scalar($client) && ctype_digit((string) $client) ? (int) $client : 0;

        // reason => subject, the user's own sign-out first.
        $subjects = array();
        $user = $uid > 0 ? self::subject('uid', $uid) : self::subject('client', $clientId);
        if ($user !== '') {
            $subjects['revoked'] = $user;
        }
        $actor = Verifier::staffActor($claims);
        if ($actor !== null) {
            $subjects['staff_revoked'] = self::subject('staff', (int) substr($actor, strlen('admin:')));
        }

        if (!$subjects || !Schema::hasTable(Tables::REVOCATIONS)) {
            return '';
        }

        $before = array();
        foreach (Capsule::table(Tables::REVOCATIONS)->whereIn('subject', array_values($subjects))->get(array('subject', 'revoked_before_ms')) as $row) {
            $before[(string) $row->subject] = (int) $row->revoked_before_ms;
        }
        $issued = self::issuedMs($claims);

        foreach ($subjects as $reason => $subject) {
            if (isset($before[$subject]) && $issued <= $before[$subject]) {
                return $reason;
            }
        }

        return '';
    }

    /**
     * When the token was issued, in milliseconds: `iatm` when it agrees with `iat` (module 1.6.0),
     * otherwise `iat` to the second. A token from an older module issued in the same second as a
     * sign-out is therefore refused; that is the safe side, and its page only needs a refresh.
     */
    public static function issuedMs(array $claims)
    {
        $iat = isset($claims['iat']) && is_numeric($claims['iat']) ? (int) $claims['iat'] : 0;

        if (isset($claims['iatm']) && is_numeric($claims['iatm'])) {
            $iatm = (int) $claims['iatm'];
            if ($iatm >= 0 && intdiv($iatm, 1000) === $iat) {
                return $iatm;
            }
        }

        return $iat * 1000;
    }

    /** Remove rows no token can still be affected by. Run by the daily cron. @return int */
    public static function prune($now = null)
    {
        $now = $now === null ? time() : (int) $now;

        try {
            return (int) Capsule::table(Tables::REVOCATIONS)->where('updated_at', '<', $now - self::KEEP_SECONDS)->delete();
        } catch (\Exception $e) {
            return 0;
        }
    }

    private static function subject($kind, $id)
    {
        $id = (int) $id;

        return in_array($kind, array('uid', 'client', 'staff'), true) && $id > 0 ? $kind . ':' . $id : '';
    }
}
