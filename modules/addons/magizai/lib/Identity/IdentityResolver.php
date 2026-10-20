<?php

namespace MagizAI\Whmcs\Identity;

use MagizAI\Whmcs\Schema;
use MagizAI\Whmcs\Settings;
use WHMCS\Database\Capsule;

/**
 * Who is signed in on a client-area page, decided once, for the footer and the token endpoint
 * alike, and the signed token that says so.
 *
 * EVERY PAGE SAYS WHAT IT DECIDED. A staff session, an account with no client selected, a closed
 * account, a failure here and a real sign-out all used to reach MagizAI as the same `null`, and the
 * assistant told a visibly signed-in owner to "sign in" (incident #242). The outcome now says why,
 * in reason codes only, never a name, an email or an id.
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class IdentityResolver
{
    /** The reasons a page can give for having no client token. */
    const REASONS = array('signed_out', 'staff', 'staff_view', 'no_account', 'account_closed', 'module_error', 'not_configured');

    /**
     * Who is signed in on this page.
     *
     * Staff: "Login as Owner", or an admin session in the same browser as a client session. With
     * staff view off they get no token, and the reason "staff". With staff view on (read_only) they
     * get a token for the client marked with the admin (`actor`, `amr`), which the bridge only ever
     * reads with, and the reason "staff_view".
     *
     * `signed_in` says whether a client account is signed in on this page for the chat to serve: the
     * client's own session, or staff view of it. It is true also when no token could be made for them
     * (no identity secret, or a failure here), and it decides "Only to signed-in clients". It is not a
     * claim, and it never leaves the module.
     *
     * @param array|null $session a Session snapshot; null reads the current one
     * @return array{claims: array|null, status: string, reason: string|null, signed_in: bool}
     */
    public static function resolve(?array $session = null)
    {
        // Known as soon as the session shows a usable client session: a failure after that still
        // leaves a signed-in client signed in.
        $signedIn = false;
        // Not known until read: a failure to read it is a module error, as any other.
        $configured = true;

        try {
            $configured = Settings::get('identity_secret') !== '';

            $s = $session !== null ? $session + Session::nobody() : Session::current();

            $staff = !empty($s['masquerading']) || (!empty($s['admin']) && ($s['client_id'] > 0 || $s['user_id'] > 0));

            if ($staff && (PageSettings::staffMode() !== 'read_only' || $s['admin_id'] <= 0)) {
                // Staff view is off, or the admin cannot be named: a staff token must always say
                // who is looking.
                return self::nobody('staff', $configured, false);
            }

            if ($s['client_id'] <= 0) {
                return self::nobody($staff ? 'staff' : ($s['user_id'] > 0 ? 'no_account' : 'signed_out'), $configured, false);
            }

            // WHMCS 8 and later: every token names its user, and the bridge refuses one that does not.
            if (!empty($s['whmcs8']) && $s['user_id'] <= 0) {
                return self::nobody($staff ? 'staff' : 'signed_out', $configured, false);
            }

            $signedIn = true;

            $row = Capsule::table('tblclients')->where('id', (int) $s['client_id'])
                ->first(array('firstname', 'lastname', 'companyname', 'email', 'status'));
            if (!$row) {
                return self::nobody('no_account', $configured, false);
            }
            if ((string) $row->status === 'Closed') {
                return self::nobody('account_closed', $configured, false);
            }

            if (!$configured) {
                // Nothing to sign with: say so, rather than "signed out". The client is still
                // signed in, and sees the chat wherever a signed-in client does.
                return self::outcome(null, 'none', 'not_configured', true);
            }

            $claims = self::claims($s, $row);

            if ($staff) {
                // Flat strings: MagizAI keeps only scalar claims.
                $claims['actor'] = 'admin:' . (int) $s['admin_id'];
                $claims['amr'] = 'masquerade';

                return self::outcome($claims, 'staff', 'staff_view', true);
            }

            return self::outcome($claims, 'client', null, true);
        } catch (\Throwable $e) {
            self::logFailure($e);

            return self::nobody('module_error', $configured, $signedIn);
        }
    }

    /**
     * resolve(), and a token minted for it when it names somebody.
     *
     * A token that cannot be made is never sent half-made: the page says nobody is identified, with
     * the reason "module_error", and the cause goes to the WHMCS module log. The client is still
     * signed in (`signed_in` stays true).
     *
     * @return array{claims: array|null, status: string, reason: string|null, signed_in: bool, token: string|null, exp: int|null}
     */
    public static function issue(?array $session = null)
    {
        $outcome = self::resolve($session);

        if ($outcome['claims'] !== null) {
            try {
                $issued = Signing::mint($outcome['claims'], 60 * PageSettings::ttlMinutes());

                return $outcome + array('token' => $issued['token'], 'exp' => $issued['exp']);
            } catch (\Throwable $e) {
                self::logFailure($e);
                $outcome = self::outcome(null, 'none', 'module_error', $outcome['signed_in']);
            }
        }

        return $outcome + array('token' => null, 'exp' => null);
    }

    /**
     * The state object the page carries: {"v":1,"status":...} and, unless the status is "client",
     * the reason. Codes only.
     *
     * @return array{v: int, status: string, reason?: string}
     */
    public static function state(array $outcome)
    {
        $state = array('v' => 1, 'status' => (string) $outcome['status']);
        if ($outcome['status'] !== 'client' && in_array($outcome['reason'], self::REASONS, true)) {
            $state['reason'] = $outcome['reason'];
        }

        return $state;
    }

    /**
     * The identity claims, version 2 (module 1.6.0), all plain strings:
     *  - sub, external_id and cid: the client ACCOUNT, which every lookup is scoped to;
     *  - uid: the USER signed in ("" before WHMCS 8, which has no users), so the bridge applies what
     *    this user may see, and MagizAI can tell two users of one account apart;
     *  - email and name: the user's own (before WHMCS 8, the client's: the client is the person);
     *  - account_name: the account's company name, or its holder's name;
     *  - company: the company name, as before, for skills that read it.
     */
    private static function claims(array $s, $row)
    {
        $clientId = (string) (int) $s['client_id'];
        $holder = trim((string) $row->firstname . ' ' . (string) $row->lastname);
        $company = trim((string) $row->companyname);

        if (!empty($s['whmcs8'])) {
            $email = $s['user_email'] !== '' ? $s['user_email'] : (string) $row->email;
            $name = self::userName($s);
        } else {
            $email = (string) $row->email;
            $name = $holder;
        }

        $claims = array(
            'sub' => $clientId,
            'external_id' => $clientId,
            'cid' => $clientId,
            'uid' => $s['user_id'] > 0 ? (string) (int) $s['user_id'] : '',
        );

        foreach (array('email' => $email, 'name' => $name, 'account_name' => $company !== '' ? $company : $holder, 'company' => $company) as $claim => $value) {
            if (trim($value) !== '') {
                $claims[$claim] = trim($value);
            }
        }

        return $claims;
    }

    /**
     * The signed-in user's own name. From the user WHMCS hands over, else tblusers. Empty when it
     * cannot be read: the account holder's name would be the wrong person for a team member.
     */
    private static function userName(array $s)
    {
        $name = trim($s['user_first'] . ' ' . $s['user_last']);
        if ($name !== '' || $s['user_id'] <= 0) {
            return $name;
        }

        try {
            if (Schema::hasTable('tblusers')) {
                $user = Capsule::table('tblusers')->where('id', (int) $s['user_id'])->first(array('first_name', 'last_name'));
                if ($user) {
                    return trim((string) $user->first_name . ' ' . (string) $user->last_name);
                }
            }
        } catch (\Exception $e) {
            // A name is a courtesy, not a reason to withhold the sign-in.
        }

        return '';
    }

    private static function outcome($claims, $status, $reason, $signedIn)
    {
        return array('claims' => $claims, 'status' => $status, 'reason' => $reason, 'signed_in' => (bool) $signedIn);
    }

    /**
     * No token, for this reason. Without an identity secret nothing could have been signed for
     * anyone, so the page says "not_configured" whatever else is true.
     */
    private static function nobody($reason, $configured, $signedIn)
    {
        return self::outcome(null, 'none', $configured ? $reason : 'not_configured', $signedIn);
    }

    /** Why a token could not be made, for staff: never a claim, a token or a secret. */
    private static function logFailure(\Throwable $e)
    {
        if (function_exists('logModuleCall')) {
            logModuleCall('magizai', 'identity_token', array(), get_class($e) . ': ' . $e->getMessage(), '', array());
        }
    }
}
