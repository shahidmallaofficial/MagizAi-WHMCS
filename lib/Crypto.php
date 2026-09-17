<?php

namespace MagizAI\Whmcs;

/**
 * The two pieces of cryptography the module depends on, kept small enough to read in one sitting.
 *
 * 1. Request/response signing with the bridge secret (HMAC-SHA256 over timestamp, nonce and body).
 * 2. The visitor identity token (compact JWS, HS256) — minted by the client-area hook, verified again
 *    by the bridge. The bridge re-verifies rather than trusting MagizAI's word for who is asking: if
 *    MagizAI were ever compromised, it still could not act as a client who has not signed in here.
 *
 * PHP 7.4+ compatible on purpose — WHMCS installs run a wide range of PHP versions.
 */
class Crypto
{
    const SIGNATURE_VERSION = 'v1';

    /** Longest identity token lifetime honoured, whatever `exp` says (matches MagizAI). */
    const MAX_TOKEN_LIFETIME = 86400;

    public static function sign($secret, $timestamp, $nonce, $body)
    {
        return self::SIGNATURE_VERSION . '=' . hash_hmac('sha256', $timestamp . "\n" . $nonce . "\n" . $body, $secret);
    }

    public static function verifySignature($secret, $timestamp, $nonce, $body, $signature)
    {
        if (!is_string($signature) || $signature === '' || !is_string($secret) || $secret === '') {
            return false;
        }

        return hash_equals(self::sign($secret, $timestamp, $nonce, $body), trim($signature));
    }

    /** A new bridge secret. The prefix lets MagizAI's form reject a pasted value of the wrong kind. */
    public static function newBridgeSecret()
    {
        return 'mzb_' . bin2hex(random_bytes(24));
    }

    /**
     * Mint an identity token for a signed-in client.
     *
     * @param array $claims sub, external_id, email, name, company
     */
    public static function mintIdentity($secret, array $claims, $ttlSeconds)
    {
        $now = time();
        $ttl = max(60, min((int) $ttlSeconds, self::MAX_TOKEN_LIFETIME));

        $payload = array_merge($claims, array('iat' => $now, 'exp' => $now + $ttl));

        $header = self::b64(json_encode(array('alg' => 'HS256', 'typ' => 'JWT')));
        $body = self::b64(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $header . '.' . $body . '.' . self::b64(hash_hmac('sha256', $header . '.' . $body, $secret, true));
    }

    /**
     * Verify an identity token. Returns its claims, or null.
     *
     * The algorithm is pinned: a token asking for "none" or anything but HS256 is rejected before its
     * signature is even looked at.
     */
    public static function verifyIdentity($secret, $token)
    {
        if (!is_string($token) || !is_string($secret) || $secret === '') {
            return null;
        }

        $parts = explode('.', trim($token));
        if (count($parts) !== 3) {
            return null;
        }

        $header = json_decode((string) self::unb64($parts[0]), true);
        if (!is_array($header) || !isset($header['alg']) || $header['alg'] !== 'HS256') {
            return null;
        }

        $expected = hash_hmac('sha256', $parts[0] . '.' . $parts[1], $secret, true);
        $given = self::unb64($parts[2]);
        if ($given === null || !hash_equals($expected, $given)) {
            return null;
        }

        $claims = json_decode((string) self::unb64($parts[1]), true);
        if (!is_array($claims) || !isset($claims['exp'], $claims['iat'])) {
            return null;
        }

        $now = time();
        $exp = (int) $claims['exp'];
        $iat = (int) $claims['iat'];

        if ($exp <= $now || $iat > $now + 60 || $exp - $iat > self::MAX_TOKEN_LIFETIME) {
            return null;
        }

        return $claims;
    }

    private static function b64($raw)
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function unb64($value)
    {
        $padded = strtr((string) $value, '-_', '+/');
        $padded .= str_repeat('=', (4 - strlen($padded) % 4) % 4);
        $decoded = base64_decode($padded, true);

        return $decoded === false ? null : $decoded;
    }
}
