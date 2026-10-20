<?php

namespace MagizAI\Whmcs;

/**
 * The cryptography the module depends on, kept small enough to read in one sitting.
 *
 * 1. Request/response signing with the bridge secret (HMAC-SHA256 over timestamp, nonce and body).
 * 2. The visitor identity token (compact JWS), minted by the client-area hook and the token endpoint
 *    and verified again by the bridge, which never takes a request's word for who is asking. Two
 *    algorithms:
 *     - HS256 with the identity secret. MagizAI holds the same secret, so an HS256 token guards
 *       against forged and expired sign-ins, not against MagizAI itself. (Replayed requests are
 *       stopped by the request signature and nonce, not by the token.)
 *     - EdDSA (Ed25519) with a key pair this module generates and keeps. Only the public half ever
 *       leaves WHMCS, and a public key can check a signature but never make one. MagizAI can still
 *       use a real token it was given until it expires: a token is not used up by a request.
 *    Which one signs is decided in Identity\Signing; this class only does the arithmetic.
 *
 * PHP 7.4+ compatible on purpose: WHMCS installs run a wide range of PHP versions.
 */
class Crypto
{
    const SIGNATURE_VERSION = 'v1';

    /** Longest identity token lifetime honoured, whatever `exp` says (matches MagizAI). */
    const MAX_TOKEN_LIFETIME = 86400;

    /** How far ahead of this server's clock a token's issue time may be (matches MagizAI). */
    const MAX_SKEW = 60;

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
     * Mint an HS256 identity token. Kept for callers that only need the token string.
     *
     * @param array $claims the identity claims (sub, external_id, ...)
     * @throws \RuntimeException
     */
    public static function mintIdentity($secret, array $claims, $ttlSeconds)
    {
        $issued = self::issue($claims, $ttlSeconds, array('alg' => 'HS256', 'secret' => $secret));

        return $issued['token'];
    }

    /**
     * Mint an identity token, and say when it was issued and when it expires.
     *
     * Every token carries, beside the caller's claims:
     *  - iat and exp, in seconds;
     *  - iatm, the issue time in milliseconds. A sign-out revokes the tokens issued before it, and
     *    seconds are too coarse for that: a client who signs out and in again within one second
     *    would have the new token refused (or the old one accepted);
     *  - jti, 128 random bits, so two tokens are never the same string.
     *
     * @param array    $signer array('alg' => 'HS256', 'secret' => string), or
     *                         array('alg' => 'EdDSA', 'kid' => string, 'secret_key' => 64 raw bytes)
     * @param int|null $nowMs  the issue time in milliseconds; null for now
     * @return array{token: string, iat: int, exp: int, iatm: int, jti: string}
     * @throws \RuntimeException when the claims cannot be encoded or there is nothing to sign with
     */
    public static function issue(array $claims, $ttlSeconds, array $signer, $nowMs = null)
    {
        $ms = $nowMs === null ? self::nowMs() : (int) $nowMs;
        $iat = intdiv($ms, 1000);
        $exp = $iat + max(60, min((int) $ttlSeconds, self::MAX_TOKEN_LIFETIME));
        $jti = bin2hex(random_bytes(16));

        $alg = isset($signer['alg']) ? (string) $signer['alg'] : '';

        if ($alg === 'HS256') {
            $secret = isset($signer['secret']) ? (string) $signer['secret'] : '';
            if ($secret === '') {
                throw new \RuntimeException('There is no identity secret to sign the token with.');
            }
            $header = array('alg' => 'HS256', 'typ' => 'JWT');
        } elseif ($alg === 'EdDSA') {
            $kid = isset($signer['kid']) ? (string) $signer['kid'] : '';
            $secretKey = isset($signer['secret_key']) ? (string) $signer['secret_key'] : '';
            if (!self::sodiumAvailable() || $kid === '' || strlen($secretKey) !== 64) {
                throw new \RuntimeException('There is no usable Ed25519 key to sign the token with.');
            }
            $header = array('alg' => 'EdDSA', 'typ' => 'JWT', 'kid' => $kid);
        } else {
            throw new \RuntimeException('Unknown signing algorithm.');
        }

        // The caller's claims cannot overrule when the token was issued.
        $payload = array_merge($claims, array('iat' => $iat, 'exp' => $exp, 'iatm' => $ms, 'jti' => $jti));

        $input = self::base64url(self::json($header)) . '.' . self::base64url(self::json($payload));
        $signature = $alg === 'HS256'
            ? hash_hmac('sha256', $input, $secret, true)
            : sodium_crypto_sign_detached($input, $secretKey);

        return array('token' => $input . '.' . self::base64url($signature), 'iat' => $iat, 'exp' => $exp, 'iatm' => $ms, 'jti' => $jti);
    }

    /**
     * JSON for a token, never silently empty.
     *
     * A client whose name held one byte that is not valid UTF-8 (common in installs older than
     * utf8 tables) produced json_encode() === false, and the token went out with an EMPTY payload:
     * signed, well-formed and unusable, on every page they opened. Bad bytes are now replaced, and
     * if encoding still fails this throws, so the caller can say so instead of minting garbage.
     *
     * @throws \RuntimeException
     */
    public static function json($value)
    {
        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if (!is_string($json)) {
            throw new \RuntimeException('The identity claims could not be encoded (' . json_last_error_msg() . ').');
        }

        return $json;
    }

    /**
     * Verify an HS256 identity token. Returns its claims, or null.
     *
     * @see verifyIdentityDetailed for why a token was refused
     */
    public static function verifyIdentity($secret, $token)
    {
        $result = self::verifyIdentityDetailed($secret, $token);

        return $result[0];
    }

    /**
     * Verify an identity token and say why it was refused.
     *
     * The algorithm is pinned by what this module signs with: "none", and anything but HS256 and
     * EdDSA, is refused before a signature is looked at, and each algorithm is only ever checked
     * with its own kind of key.
     *
     * @param array $options 'keys'  => array(kid => raw 32-byte Ed25519 public key): EdDSA tokens
     *                                  are accepted only for these keys (none by default);
     *                       'hs256' => false once HS256 tokens are no longer accepted;
     *                       'now'   => unix time to check against (tests).
     * @return array{0: array|null, 1: string} the claims or null, and the reason: 'ok', 'missing',
     *         'malformed', 'algorithm', 'no_secret', 'signature', 'hs256_retired', 'unknown_key',
     *         'no_sodium', 'payload', 'lifetime', 'not_yet_valid' or 'expired'
     */
    public static function verifyIdentityDetailed($secret, $token, array $options = array())
    {
        if (!is_string($token) || trim($token) === '') {
            return array(null, 'missing');
        }

        $parts = explode('.', trim($token));
        if (count($parts) !== 3) {
            return array(null, 'malformed');
        }

        $header = json_decode((string) self::fromBase64url($parts[0]), true);
        $given = self::fromBase64url($parts[2]);
        if (!is_array($header) || !isset($header['alg']) || !is_string($header['alg']) || $given === null) {
            return array(null, 'malformed');
        }

        $input = $parts[0] . '.' . $parts[1];

        if ($header['alg'] === 'HS256') {
            if (!is_string($secret) || $secret === '') {
                return array(null, 'no_secret');
            }
            if (!hash_equals(hash_hmac('sha256', $input, $secret, true), $given)) {
                return array(null, 'signature');
            }
            // Checked after the signature, so the reason says what really happened: a genuine
            // HS256 token from before the switch, rather than any string that says "HS256".
            if (isset($options['hs256']) && $options['hs256'] === false) {
                return array(null, 'hs256_retired');
            }
        } elseif ($header['alg'] === 'EdDSA') {
            $keys = isset($options['keys']) && is_array($options['keys']) ? $options['keys'] : array();
            $kid = isset($header['kid']) && is_string($header['kid']) ? $header['kid'] : '';
            if ($kid === '' || !isset($keys[$kid]) || !is_string($keys[$kid])) {
                return array(null, 'unknown_key');
            }
            if (!self::sodiumAvailable()) {
                return array(null, 'no_sodium');
            }
            if (!self::ed25519Verifies($given, $input, $keys[$kid])) {
                return array(null, 'signature');
            }
        } else {
            return array(null, 'algorithm');
        }

        $claims = json_decode((string) self::fromBase64url($parts[1]), true);
        if (!is_array($claims) || !isset($claims['exp'], $claims['iat']) || !is_numeric($claims['exp']) || !is_numeric($claims['iat'])) {
            return array(null, 'payload');
        }

        $now = isset($options['now']) ? (int) $options['now'] : time();
        $exp = (int) $claims['exp'];
        $iat = (int) $claims['iat'];

        if ($exp - $iat > self::MAX_TOKEN_LIFETIME) {
            return array(null, 'lifetime');
        }
        if ($iat > $now + self::MAX_SKEW) {
            return array(null, 'not_yet_valid');
        }
        if ($exp <= $now) {
            return array(null, 'expired');
        }

        return array($claims, 'ok');
    }

    /** Can this PHP sign and check Ed25519? (ext/sodium, or a polyfill that provides it.) */
    public static function sodiumAvailable()
    {
        foreach (array('sodium_crypto_sign_keypair', 'sodium_crypto_sign_secretkey', 'sodium_crypto_sign_publickey',
            'sodium_crypto_sign_publickey_from_secretkey', 'sodium_crypto_sign_detached', 'sodium_crypto_sign_verify_detached') as $function) {
            if (!function_exists($function)) {
                return false;
            }
        }

        return true;
    }

    /** A public key's id: the first 16 hex characters of sha256 over its raw 32 bytes. */
    public static function kid($rawPublicKey)
    {
        return substr(hash('sha256', (string) $rawPublicKey), 0, 16);
    }

    /**
     * The identity secret's fingerprint: proves two secrets are the same without showing either.
     * Empty when there is no secret.
     */
    public static function fingerprint($secret)
    {
        return is_string($secret) && $secret !== '' ? substr(hash_hmac('sha256', 'magizai-identity-fp-v1', $secret), 0, 16) : '';
    }

    /** The current time in milliseconds. */
    public static function nowMs()
    {
        return (int) floor(microtime(true) * 1000);
    }

    public static function base64url($raw)
    {
        return rtrim(strtr(base64_encode((string) $raw), '+/', '-_'), '=');
    }

    /** @return string|null null when the value is not base64url */
    public static function fromBase64url($value)
    {
        $padded = strtr((string) $value, '-_', '+/');
        $padded .= str_repeat('=', (4 - strlen($padded) % 4) % 4);
        $decoded = base64_decode($padded, true);

        return $decoded === false ? null : $decoded;
    }

    private static function ed25519Verifies($signature, $message, $publicKey)
    {
        if (strlen($signature) !== 64 || strlen($publicKey) !== 32) {
            return false;
        }

        try {
            return sodium_crypto_sign_verify_detached($signature, $message, $publicKey);
        } catch (\Throwable $e) {
            return false;
        }
    }
}
