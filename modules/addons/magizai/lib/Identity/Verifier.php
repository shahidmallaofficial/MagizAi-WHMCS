<?php

namespace MagizAI\Whmcs\Identity;

use MagizAI\Whmcs\Settings;

/**
 * The bridge's own check of who a request is for. MagizAI's word is never taken: the token is
 * verified here, against this install's keys, before any account is touched.
 *
 * EACH FAILURE HAS ITS OWN CODE. A missing token, a bad signature, an expired token and a token for
 * another client all used to come back as `identity_expired`, "ask them to refresh the page", which
 * never helps when the identity secrets of WHMCS and MagizAI differ. The codes:
 *
 *   identity_missing    no token came with a request that needs one
 *   identity_signature  the token was not made by this install (wrong secret, unknown key,
 *                       tampered, or an HS256 token after the switch to Ed25519)
 *   identity_expired    the token's time is up (or it was issued in the future)
 *   identity_mismatch   the token is for another client than the request names, or names none
 *   identity_revoked    the user signed out after the token was issued, or staff view was
 *                       switched off (or the admin's Login as Owner session ended) after a staff
 *                       token was issued
 *
 * (`identity_stale` and `staff_write_refused` are decided later, by the bridge, per action.)
 * MagizAI treats every code that starts with `identity_` as the visitor's own sign-in, so a module
 * newer than MagizAI's list still gets the right answer to the client.
 *
 * Every message is written for the client, through the assistant: plain words, no internals. The
 * detail, for the activity log only, says what happened in staff terms, never a secret or a token.
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class Verifier
{
    /** What the client is told, by code. */
    const MESSAGES = array(
        'identity_missing' => 'The client\'s sign-in did not reach WHMCS with this request. Ask them to refresh the client area page and try again.',
        'identity_signature' => 'The client\'s sign-in could not be confirmed. This points to a set-up problem on this site rather than anything the client did, so asking them to sign in again will not help. They can use the client area directly, or the team can help.',
        'identity_expired' => 'The client\'s sign-in could not be confirmed or has expired. Ask them to refresh the client area page and try again.',
        'identity_mismatch' => 'This request does not match the client\'s sign-in. Ask them to refresh the client area page and try again.',
        'identity_revoked' => 'The client signed out of the client area after this page was opened, so this sign-in no longer counts. Ask them to refresh the page and sign in again if asked.',
        'staff_write_refused' => 'This is a staff member looking at the client\'s account in staff view, where nothing is changed or sent from the chat. Make the change in the WHMCS admin area instead.',
    );

    /** A few reasons have a message of their own, under the same code. */
    const REASON_MESSAGES = array(
        'hs256_retired' => 'The client\'s page was opened before this site changed how sign-ins are signed. Ask them to refresh the client area page and try again.',
        'staff_view_off' => 'Staff view has been switched off in WHMCS, so this staff sign-in no longer counts. Account look-ups need a client\'s own sign-in.',
        'staff_revoked' => 'This staff view ended when the staff member left the client\'s account, so it no longer counts. Open the account with Login as Owner again to keep looking it up.',
    );

    /** For the activity log: what happened, in staff terms. */
    const DETAILS = array(
        'missing' => 'no sign-in token came with the request',
        'malformed' => 'the sign-in token is not in a form this module makes',
        'algorithm' => 'the sign-in token uses a signing method this module never uses',
        'no_secret' => 'no identity secret is set in this module, so the sign-in cannot be checked',
        'signature' => 'the sign-in token was not signed by this module: check that the identity secret matches the one in MagizAI',
        'hs256_retired' => 'a shared-secret sign-in token arrived after the change-over to this module\'s own Ed25519 key had ended (two hours after the switch, or one token lifetime if that is longer)',
        'unknown_key' => 'the sign-in token names a key this module does not hold',
        'no_sodium' => 'the sign-in token is Ed25519, but this server\'s PHP no longer has sodium to check it',
        'payload' => 'the sign-in token carries no readable claims',
        'lifetime' => 'the sign-in token claims a lifetime longer than any this module issues',
        'not_yet_valid' => 'the sign-in token was issued in the future: check this server\'s clock',
        'expired' => 'the sign-in token has expired',
        'client' => 'the sign-in token is for another client, or names none',
        'staff_claims' => 'the sign-in token carries staff fields this module never writes',
        'staff_view_off' => 'a staff view sign-in arrived after staff view was switched off',
        'revoked' => 'the user signed out after this sign-in token was issued',
        'staff_revoked' => 'the staff member\'s Login as Owner session ended after this staff view sign-in token was issued',
    );

    /**
     * Check the token a request carries for the client it names.
     *
     * @param mixed $token            the token from the request
     * @param mixed $expectedClientId the client id the request names (identity.client_id)
     * @return array{claims: array|null, code: string|null, reason: string, message: string,
     *               detail: string, actor: string|null}
     * @throws \Exception when the revocation table cannot be read
     */
    public static function check($token, $expectedClientId)
    {
        if (!is_string($token) || trim($token) === '') {
            return self::refusal('identity_missing', 'missing');
        }

        $verified = Signing::verify($token);
        $claims = $verified[0];
        if ($claims === null) {
            return self::refusal(self::codeFor($verified[1]), $verified[1]);
        }

        // One client, named the same way everywhere in the token, and the one the request is for.
        $claimed = isset($claims['external_id']) ? $claims['external_id'] : (isset($claims['sub']) ? $claims['sub'] : null);
        $claimed = is_scalar($claimed) ? (string) $claimed : '';
        $cid = isset($claims['cid']) && is_scalar($claims['cid']) ? (string) $claims['cid'] : '';
        $sub = isset($claims['sub']) && is_scalar($claims['sub']) ? (string) $claims['sub'] : '';
        if (!self::isClientId($claimed) || ($cid !== '' && $cid !== $claimed) || ($sub !== '' && $sub !== $claimed)
            || $claimed !== (is_scalar($expectedClientId) ? (string) $expectedClientId : '')) {
            return self::refusal('identity_mismatch', 'client');
        }

        // Staff view: both flat fields, in exactly the form the module writes them, or neither.
        $actor = null;
        if (isset($claims['actor']) || isset($claims['amr'])) {
            $actor = self::staffActor($claims);
            if ($actor === null) {
                return self::refusal('identity_signature', 'staff_claims');
            }
            if (Settings::get('staff_mode', 'off') !== 'read_only') {
                return self::refusal('identity_revoked', 'staff_view_off');
            }
        }

        $revocation = Revocations::revocation($claims);
        if ($revocation !== '') {
            return self::refusal('identity_revoked', $revocation);
        }

        return array('claims' => $claims, 'code' => null, 'reason' => 'ok', 'message' => '', 'detail' => '', 'actor' => $actor);
    }

    /** The bridge code for a reason Crypto::verifyIdentityDetailed gave. */
    public static function codeFor($reason)
    {
        if ($reason === 'missing') {
            return 'identity_missing';
        }
        if ($reason === 'expired' || $reason === 'not_yet_valid') {
            return 'identity_expired';
        }

        return 'identity_signature';
    }

    /** What the client is told for a code (and, for a few reasons, a message of their own). */
    public static function message($code, $reason = '')
    {
        if (isset(self::REASON_MESSAGES[$reason])) {
            return self::REASON_MESSAGES[$reason];
        }

        return isset(self::MESSAGES[$code]) ? self::MESSAGES[$code] : self::MESSAGES['identity_signature'];
    }

    /**
     * "admin:<id>" when the claims are a staff view token in the exact form the module mints, or
     * null. Any other combination of the two fields is not one this module wrote.
     */
    public static function staffActor(array $claims)
    {
        $actor = isset($claims['actor']) && is_string($claims['actor']) ? $claims['actor'] : '';
        $amr = isset($claims['amr']) && is_string($claims['amr']) ? $claims['amr'] : '';

        return preg_match('/^admin:[1-9][0-9]{0,9}$/', $actor) === 1 && $amr === 'masquerade' ? $actor : null;
    }

    /** A client id as an identity may carry it: a positive whole number, written plainly. Never "0". */
    public static function isClientId($value)
    {
        return is_string($value) && preg_match('/^[1-9][0-9]{0,9}$/', $value) === 1;
    }

    private static function refusal($code, $reason)
    {
        return array(
            'claims' => null,
            'code' => $code,
            'reason' => $reason,
            'message' => self::message($code, $reason),
            'detail' => isset(self::DETAILS[$reason]) ? self::DETAILS[$reason] : $reason,
            'actor' => null,
        );
    }
}
