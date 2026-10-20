<?php

namespace MagizAI\Whmcs\Identity;

use MagizAI\Whmcs\Crypto;
use MagizAI\Whmcs\Settings;

/**
 * Which key signs a client's identity token: the shared identity secret, or a key only this WHMCS
 * holds.
 *
 * WHY. An HS256 token is signed with the identity secret, and MagizAI holds the same secret: it can
 * check a token, and it could also make one for any client. With Ed25519 the module keeps the private
 * key and MagizAI only ever gets the public half, which can check a signature and never make one.
 *
 * WHAT IT DOES NOT STOP. MagizAI still receives real tokens: its chat runs on client-area pages, and
 * each page (and each refresh, Http\TokenEndpoint) hands it the signed-in client's token. A token
 * works on any number of requests until it expires or its user signs out; there is no one-use
 * registry. Ed25519 takes away MagizAI's ability to MAKE a sign-in, not to USE one it was given. The
 * README says so.
 *
 * WHEN TOKENS SWITCH TO Ed25519. Only when every one of these holds, so a sign-in is never signed in
 * a way MagizAI cannot check:
 *  - this server's PHP has sodium;
 *  - MagizAI said it can check Ed25519 ("identity_eddsa" in the signed body of a request) within
 *    the last 30 days;
 *  - this key's public half went out in two signed ping replies at least an hour apart, and in one
 *    within the last 29 days. One delivery is not enough: a reply can be lost on the way (a timeout
 *    after this side finished) and nothing here would know, and switching to a key MagizAI never
 *    received makes every signed-in client a stranger to the chat until its next ping, which may be
 *    a day away. A reply this side knows will not verify is not a delivery at all (@see publish).
 *    MagizAI trusts a key for 30 days after it last saw one, so the module stops a day sooner, not
 *    a moment later;
 *  - the admin has not chosen the shared secret ("identity_signing" = "hs256").
 * Otherwise tokens are HS256, exactly as before, and the bridge accepts them.
 *
 * AFTER THE SWITCH. Two hours after the switch (the ping that completes the second delivery), the
 * bridge stops accepting HS256 tokens:
 * a page opened before the switch has had time to be refreshed, and from then on an HS256 token is
 * not one this module made. An admin who set a token lifetime longer than two hours gets one token
 * lifetime instead, so an HS256 token minted just before the switch is never refused while it is still
 * valid. If any condition above stops holding, tokens go back to HS256, the bridge accepts them
 * again, and a later switch starts a fresh window.
 *
 * THE KEY PAIR is made once, on the first ping after MagizAI says it can check Ed25519. The private
 * half is stored encrypted with WHMCS's own key, like every other secret here; a private key that can
 * no longer be read (WHMCS decrypts it, to something that is not the key: its encryption key changed)
 * is replaced, and tokens are HS256 until the new public key has been delivered twice, like the
 * first. WHMCS refusing to decrypt at all is not that: the pair is kept, that page's sign-in is a
 * module error, and the next page tries again.
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class Signing
{
    /** What MagizAI sends in `platform.capabilities` when it can check Ed25519 tokens. */
    const CAPABILITY = 'identity_eddsa';

    /** How recently MagizAI must have said so. */
    const CAPABILITY_WINDOW = 2592000; // 30 days

    /** How recently the public key must have gone out in a ping reply. */
    const PUBLISHED_WINDOW = 2505600; // 29 days

    /** How long after the key first went out a second delivery must come before tokens switch. */
    const CONFIRM_AFTER = 3600; // 1 hour

    /** How long HS256 tokens are still accepted after the first Ed25519 one, at least. @see transitionSeconds */
    const TRANSITION = 7200; // 2 hours

    /** The capability is written down at most this often: every bridge request carries it. */
    const NOTE_EVERY = 3600;

    // Settings this class keeps. @see Settings::baseDefaults
    const OVERRIDE = 'identity_signing';
    const PRIVATE_KEY = KeyPair::PRIVATE_KEY;
    const PUBLIC_KEY = KeyPair::PUBLIC_KEY;
    const SEEN = 'identity_capability_seen_at';
    /** When the current key first went out in a ping reply, waiting for its second delivery. */
    const OFFERED = 'identity_key_offered_at';
    /** When the current key last went out, once it counts as delivered (two deliveries an hour apart). */
    const PUBLISHED = 'identity_key_published_at';
    const SINCE = 'identity_eddsa_since';

    /**
     * Record that MagizAI can check Ed25519, when this signed request says so.
     *
     * @param mixed $request the decoded, signature-checked request body
     * @return bool whether the request carried the capability
     */
    public static function noteCapability($request)
    {
        $capabilities = is_array($request) && isset($request['platform']['capabilities']) && is_array($request['platform']['capabilities'])
            ? $request['platform']['capabilities'] : array();

        if (!in_array(self::CAPABILITY, $capabilities, true)) {
            return false;
        }

        $now = time();
        $last = (int) Settings::get(self::SEEN, '0');
        if ($last > 0 && $now - $last < self::NOTE_EVERY && $now >= $last) {
            return true;
        }

        // MagizAI was silent long enough for tokens to have gone back to HS256: whatever switch
        // comes next starts its own two hours.
        if ($last > 0 && $now - $last > self::CAPABILITY_WINDOW) {
            self::endEddsaPeriod();
        }

        Settings::set(self::SEEN, (string) $now);

        return true;
    }

    /** Would this install sign with Ed25519 if it had a key MagizAI knows? (No key is needed.) */
    public static function wanted()
    {
        if (Settings::get(self::OVERRIDE, 'auto') === 'hs256' || !Crypto::sodiumAvailable()) {
            return false;
        }

        $seen = (int) Settings::get(self::SEEN, '0');

        return $seen > 0 && time() - $seen <= self::CAPABILITY_WINDOW;
    }

    /** How tokens are signed right now: 'eddsa' or 'hs256'. Decrypts nothing. */
    public static function mode()
    {
        if (!self::wanted() || self::keyPair() === null) {
            return 'hs256';
        }

        $published = (int) Settings::get(self::PUBLISHED, '0');

        return $published > 0 && time() - $published <= self::PUBLISHED_WINDOW ? 'eddsa' : 'hs256';
    }

    /**
     * Why tokens are not Ed25519 yet, for the admin page: '' when they are, or 'override',
     * 'no_sodium', 'no_capability' or 'not_published' (the public key has not gone out in two pings an
     * hour apart yet, or not within the last 29 days).
     */
    public static function whyNotEddsa()
    {
        if (Settings::get(self::OVERRIDE, 'auto') === 'hs256') {
            return 'override';
        }
        if (!Crypto::sodiumAvailable()) {
            return 'no_sodium';
        }
        if (!self::wanted()) {
            return 'no_capability';
        }

        return self::mode() === 'eddsa' ? '' : 'not_published';
    }

    /**
     * The key pair, or null when there is none. @see KeyPair::read
     *
     * @param bool $withSecret also read (decrypt) the private half
     * @return array{kid: string, public: string, secret?: string}|null raw bytes
     * @throws \RuntimeException when WHMCS does not decrypt the private half right now. That is not an
     *                           answer about the key, so the pair is kept, never replaced.
     */
    public static function keyPair($withSecret = false)
    {
        return KeyPair::read($withSecret);
    }

    /**
     * The key pair, made now if there is no usable one. Only when Ed25519 is wanted.
     *
     * @return array{kid: string, public: string, secret: string}|null
     * @throws \RuntimeException when WHMCS does not decrypt the private half right now: the pair is
     *                           kept, and nothing is made in its place (@see keyPair)
     */
    public static function ensureKeyPair()
    {
        if (!self::wanted()) {
            return null;
        }

        $pair = self::keyPair(true);
        if ($pair !== null) {
            return $pair;
        }

        $pair = KeyPair::make();
        // A new key has not reached MagizAI yet, so tokens stay HS256 until it has been delivered
        // twice. Nothing an older key was sent counts for this one.
        Settings::set(self::OFFERED, '');
        Settings::set(self::PUBLISHED, '');
        self::endEddsaPeriod();

        return $pair;
    }

    /**
     * For the ping reply: make the key pair if it is wanted and missing, and record that its public
     * half is going out to MagizAI now.
     *
     * TWO DELIVERIES, AN HOUR APART. The first ping that carries a key only notes when it went out.
     * Tokens switch on a later ping at least an hour after that: by then MagizAI has been sent the key
     * twice, and only one of the two replies had to arrive. (Nothing here can know whether MagizAI
     * kept it; until MagizAI says which keys it holds, this is the hedge.) Once switched, every ping
     * keeps the delivery fresh.
     *
     * @param bool $delivered false when this reply is known not to reach MagizAI intact (output that
     *                        other code printed is held in a buffer that cannot be emptied, so the
     *                        reply fails its signature and MagizAI keeps nothing from it), or when the
     *                        ping did not say it can check Ed25519 (that caller does not keep keys).
     *                        Then nothing is recorded, and the key still goes out in the reply.
     * @return array<int, array{kid: string, alg: string, crv: string, x: string}>
     */
    public static function publish($delivered = true)
    {
        self::ensureKeyPair();

        $keys = self::publicKeys();
        if ($keys && $delivered) {
            self::recordDelivery();
        }

        /*
         * THE SWITCH STARTS THE CHANGE-OVER, not the first Ed25519 token. From this moment the module
         * makes no HS256 token, so one arriving after the window can only come from a holder of the
         * identity secret. Waiting for a page view instead left HS256 accepted for as long as no client
         * opened a page. The earliest start wins, and an install that switched before this rule
         * existed starts its clock on its next ping.
         */
        if ($keys && self::mode() === 'eddsa') {
            self::startEddsaPeriod();
        }

        return $keys;
    }

    /** The key went out in a reply MagizAI can verify: count it as a delivery. @see publish */
    private static function recordDelivery()
    {
        $now = time();
        $published = (int) Settings::get(self::PUBLISHED, '0');
        $offered = (int) Settings::get(self::OFFERED, '0');

        if ($published > 0 && $now - $published > self::PUBLISHED_WINDOW) {
            // MagizAI may have stopped trusting the key in the meantime: it is delivered afresh,
            // twice, and the switch after that starts a fresh change-over.
            self::endEddsaPeriod();
            Settings::set(self::PUBLISHED, '');
            $published = 0;
            $offered = 0;
        }

        if ($published > 0) {
            Settings::set(self::PUBLISHED, (string) $now);
        } elseif ($offered <= 0 || $offered > $now || $now - $offered > self::PUBLISHED_WINDOW) {
            // The first delivery, or one so long ago that MagizAI may no longer hold the key.
            Settings::set(self::OFFERED, (string) $now);
        } elseif ($now - $offered >= self::CONFIRM_AFTER) {
            Settings::set(self::PUBLISHED, (string) $now);
        }
        // A second ping within the hour of the first changes nothing.
    }

    /**
     * The public keys, as the ping reports them. Public only. @see KeyPair::published
     *
     * @return array<int, array{kid: string, alg: string, crv: string, x: string}>
     */
    public static function publicKeys()
    {
        return KeyPair::published();
    }

    /**
     * Mint a token for these claims with the key the rules above choose.
     *
     * @return array{token: string, iat: int, exp: int, iatm: int, jti: string}
     * @throws \RuntimeException when no token can be made, including when WHMCS does not decrypt the
     *                           private half right now. The page then says "module_error" and the
     *                           key pair is kept: falling back to HS256 here would be a token the
     *                           bridge refuses once HS256 is retired, and forgetting the pair would
     *                           refuse every Ed25519 sign-in already handed out.
     */
    public static function mint(array $claims, $ttlSeconds, $nowMs = null)
    {
        if (self::mode() === 'eddsa') {
            $pair = self::keyPair(true);
            if ($pair !== null) {
                $issued = Crypto::issue($claims, $ttlSeconds, array('alg' => 'EdDSA', 'kid' => $pair['kid'], 'secret_key' => $pair['secret']), $nowMs);
                self::startEddsaPeriod();

                return $issued;
            }

            // WHMCS decrypted the private half, and it is not this key: it can no longer be read
            // (WHMCS's encryption key changed). Start again: the next ping makes a new pair, and
            // until MagizAI has it, tokens are HS256 and the bridge accepts them.
            self::forgetKeyPair();
        }

        self::endEddsaPeriod();

        return Crypto::issue($claims, $ttlSeconds, array('alg' => 'HS256', 'secret' => Settings::get('identity_secret')), $nowMs);
    }

    /**
     * Verify a token with whatever this install accepts right now.
     *
     * @return array{0: array|null, 1: string} @see Crypto::verifyIdentityDetailed
     */
    public static function verify($token)
    {
        $pair = self::keyPair();

        return Crypto::verifyIdentityDetailed(Settings::get('identity_secret'), $token, array(
            'keys' => $pair !== null ? array($pair['kid'] => $pair['public']) : array(),
            'hs256' => !self::hs256Retired(),
        ));
    }

    /** Is an HS256 token refused now? Only once the change-over window after the switch has passed. */
    public static function hs256Retired()
    {
        $since = (int) Settings::get(self::SINCE, '0');

        return $since > 0 && time() - $since >= self::transitionSeconds() && self::mode() === 'eddsa';
    }

    /**
     * How long HS256 tokens are still accepted after the switch: two hours, or the token lifetime the
     * admin set when that is longer (up to 240 minutes). A token minted just before the switch lives
     * one lifetime, and the chat only asks for a fresh one shortly before it runs out.
     */
    public static function transitionSeconds()
    {
        return max(self::TRANSITION, 60 * PageSettings::ttlMinutes());
    }

    /**
     * Forget the key pair, e.g. when every secret is rotated, or when the private half can no longer
     * be read. Cleared whether or not it still decrypts. The next ping makes a new pair.
     */
    public static function forgetKeyPair()
    {
        KeyPair::forget();
        foreach (array(self::OFFERED, self::PUBLISHED, self::SINCE) as $key) {
            Settings::set($key, '');
        }
    }

    /** Tokens are Ed25519 from now on: remember when that started, once. */
    private static function startEddsaPeriod()
    {
        if ((int) Settings::get(self::SINCE, '0') <= 0) {
            self::bestEffort(self::SINCE, (string) time());
        }
    }

    /** Tokens are HS256 again: the next switch starts its own two hours. */
    public static function endEddsaPeriod()
    {
        if ((string) Settings::get(self::SINCE, '') !== '') {
            self::bestEffort(self::SINCE, '');
        }
    }

    /**
     * Writing down when tokens switched never stops a token being minted: the page still gets its
     * sign-in, and the write is simply tried again on the next one.
     */
    private static function bestEffort($key, $value)
    {
        try {
            Settings::set($key, $value);
        } catch (\Exception $e) {
            // Tried again next time.
        }
    }
}
