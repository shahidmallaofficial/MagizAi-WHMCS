<?php

namespace MagizAI\Whmcs\Identity;

use WHMCS\Database\Capsule;
use MagizAI\Whmcs\Crypto;
use MagizAI\Whmcs\Settings;

/**
 * This install's Ed25519 key pair, as stored: the private half encrypted with WHMCS's own key (a
 * Settings secret, @see Settings::PRIVATE_KEYS), the public half plain. When the pair is used, and
 * when it is replaced, is Signing's business; this class only reads, makes and forgets it.
 *
 * TWO DIFFERENT FAILURES, kept apart on purpose.
 *  - WHMCS decrypts the private half, and the bytes are not the key behind the stored public half
 *    (WHMCS's encryption key changed after a server move): the pair is unusable, read() gives null,
 *    and Signing replaces it.
 *  - WHMCS does not decrypt at all: DecryptPassword answers anything but "success". That says nothing
 *    about the key, so read() throws and nobody replaces a pair that is fine. Replacing it would
 *    refuse every Ed25519 sign-in already handed out, and put HS256 back until the new key had gone
 *    out twice. Settings::get() cannot tell these apart (it reads both as ''), so the private half is
 *    read here, strictly.
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class KeyPair
{
    // Settings this class keeps. @see Settings::baseDefaults
    const PRIVATE_KEY = 'identity_signing_key';
    const PUBLIC_KEY = 'identity_public_key';

    /**
     * The key pair, or null when there is none.
     *
     * @param bool $withSecret also read (decrypt) the private half. One WHMCS decrypts to something
     *                         that is not a 64-byte key, or not the key behind the stored public
     *                         half, gives null: the pair is unusable.
     * @return array{kid: string, public: string, secret?: string}|null raw bytes
     * @throws \RuntimeException when WHMCS does not decrypt the private half right now
     */
    public static function read($withSecret = false)
    {
        $public = Crypto::fromBase64url(Settings::get(self::PUBLIC_KEY, ''));
        if (!is_string($public) || strlen($public) !== 32) {
            return null;
        }

        $pair = array('kid' => Crypto::kid($public), 'public' => $public);

        if ($withSecret) {
            $secret = base64_decode(self::privateHalf(), true);
            if (!is_string($secret) || strlen($secret) !== 64 || !Crypto::sodiumAvailable()) {
                return null;
            }
            try {
                $matches = hash_equals($public, sodium_crypto_sign_publickey_from_secretkey($secret));
            } catch (\Throwable $e) {
                $matches = false;
            }
            if (!$matches) {
                return null;
            }
            $pair['secret'] = $secret;
        }

        return $pair;
    }

    /**
     * Make and store a new pair. The private half first: a public key with no private half behind it
     * is never stored.
     *
     * @return array{kid: string, public: string, secret: string}
     */
    public static function make()
    {
        $keys = sodium_crypto_sign_keypair();
        $secret = sodium_crypto_sign_secretkey($keys);
        $public = sodium_crypto_sign_publickey($keys);

        Settings::set(self::PRIVATE_KEY, base64_encode($secret));
        Settings::set(self::PUBLIC_KEY, Crypto::base64url($public));

        return array('kid' => Crypto::kid($public), 'public' => $public, 'secret' => $secret);
    }

    /** Forget both halves, whether or not the private one still decrypts. */
    public static function forget()
    {
        Settings::set(self::PRIVATE_KEY, '');
        Settings::set(self::PUBLIC_KEY, '');
    }

    /**
     * The public key, as the ping reports it (the JWK fields MagizAI reads). Public only: the private
     * half never leaves WHMCS.
     *
     * @return array<int, array{kid: string, alg: string, crv: string, x: string}>
     */
    public static function published()
    {
        $pair = self::read();

        return $pair === null ? array() : array(array(
            'kid' => $pair['kid'],
            'alg' => 'EdDSA',
            'crv' => 'Ed25519',
            'x' => Crypto::base64url($pair['public']),
        ));
    }

    /**
     * The private half, decrypted, or '' when none is stored. Read from the table, not Settings'
     * cache, because a soft read may have cached '' for a refusal.
     *
     * @throws \RuntimeException when WHMCS answers anything but "success"
     */
    private static function privateHalf()
    {
        $stored = (string) Capsule::table('tbladdonmodules')->where('module', Settings::MODULE)->where('setting', self::PRIVATE_KEY)->value('value');
        if ($stored === '') {
            return '';
        }

        $r = localAPI('DecryptPassword', array('password2' => $stored));
        if (!is_array($r) || ($r['result'] ?? '') !== 'success') {
            throw new \RuntimeException('WHMCS could not decrypt the signing key right now.');
        }

        return (string) ($r['password'] ?? '');
    }
}
