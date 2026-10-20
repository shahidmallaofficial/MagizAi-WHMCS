<?php

namespace MagizAI\Whmcs;

use MagizAI\Whmcs\Action\Registry;
use MagizAI\Whmcs\Install\Defaults;
use WHMCS\Database\Capsule;

/**
 * Module settings, stored in WHMCS's own tbladdonmodules rows for this module.
 *
 * Secrets go through WHMCS's EncryptPassword/DecryptPassword API, so they are protected by the same
 * key (configuration.php's cc_encryption_hash) as the rest of WHMCS's stored credentials, and never
 * appear in a database dump in the clear.
 */
class Settings
{
    const MODULE = 'magizai';

    /** Settings stored encrypted that an admin enters or copies: their health is reported. */
    const SECRETS = array('bridge_secret', 'identity_secret', 'api_token');

    /**
     * Also stored encrypted, but made by the module itself and never shown or entered: the private
     * half of the Ed25519 key pair identity tokens are signed with. Not in SECRETS, so the admin page
     * and the connection test never ask anyone to "enter it again": an unreadable one is replaced.
     * @see Identity\Signing
     */
    const PRIVATE_KEYS = array('identity_signing_key');

    /** Defaults for everything the module reads. */
    public static function defaults()
    {
        return self::baseDefaults() + array('enabled_actions' => json_encode(Registry::defaultEnabled()));
    }

    /**
     * Every default except the action list.
     *
     * The action list is built from every action's own description, which loads every action class.
     * Every client-area page reads the settings (the chat widget), and an activated install always
     * has its own stored list, so the actions are only loaded when no list is stored.
     */
    private static function baseDefaults()
    {
        return Defaults::base();
    }

    /**
     * The stored settings over the defaults, as stored: secrets are still encrypted here. Loaded
     * once per request; null means "read them again" (after a save, or between tests).
     */
    private static $cache = null;

    /**
     * Secrets decrypted so far in this request.
     *
     * Each one is decrypted the first time it is read, not all three every time: the chat widget on
     * every client-area page needs the identity secret only, and each decrypt is a call into WHMCS.
     * Forgotten whenever the settings are read again.
     */
    private static $plain = array();

    /**
     * Every setting, secrets decrypted. For the admin page, which shows them all. The private
     * signing key is left out: nothing on the page needs it, and it is never shown.
     */
    public static function all()
    {
        $values = self::stored();

        foreach (self::SECRETS as $key) {
            $values[$key] = self::secret($key);
        }
        foreach (self::PRIVATE_KEYS as $key) {
            unset($values[$key]);
        }

        return $values;
    }

    /** Is this setting stored encrypted? */
    private static function isEncrypted($key)
    {
        return in_array($key, self::SECRETS, true) || in_array($key, self::PRIVATE_KEYS, true);
    }

    public static function get($key, $default = '')
    {
        if (self::isEncrypted($key)) {
            return self::secret($key);
        }

        $stored = self::stored();

        return array_key_exists($key, $stored) ? $stored[$key] : $default;
    }

    /** @return array<string, string> the stored rows over the defaults, secrets still encrypted */
    private static function stored()
    {
        if (self::$cache === null) {
            $values = self::baseDefaults();

            $rows = Capsule::table('tbladdonmodules')->where('module', self::MODULE)->get(array('setting', 'value'));
            foreach ($rows as $row) {
                $values[$row->setting] = (string) $row->value;
            }

            if (!array_key_exists('enabled_actions', $values)) {
                $values['enabled_actions'] = json_encode(Registry::defaultEnabled());
            }

            // Read again, so anything decrypted from the old rows is stale.
            self::$plain = array();
            self::$cache = $values;
        }

        return self::$cache;
    }

    /** One secret, decrypted the first time it is asked for in this request. */
    private static function secret($key)
    {
        $stored = self::stored();

        if (!array_key_exists($key, self::$plain)) {
            $raw = isset($stored[$key]) ? (string) $stored[$key] : '';
            self::$plain[$key] = $raw !== '' ? self::decrypt($raw) : '';
        }

        return self::$plain[$key];
    }

    /**
     * A setting that holds a number of minutes, kept inside [$min, $max]. A stored value that is
     * empty or not a number reads as $fallback, never as zero: "0 minutes" would refuse everything,
     * and an unreadable value is not a decision anybody made.
     */
    public static function minutes($key, $fallback, $min, $max)
    {
        $raw = trim((string) self::get($key, (string) $fallback));
        $value = ctype_digit($raw) ? (int) $raw : (int) $fallback;

        return max((int) $min, min((int) $max, $value));
    }

    /**
     * Can each stored secret actually be read?
     *
     * A secret that is saved but cannot be decrypted (WHMCS's cc_encryption_hash changed, after a
     * server move for example) reads as an empty string everywhere else, and empty looks exactly
     * like "never entered": identity silently stopped working and nothing said why. This tells the
     * two apart, for the checklist and for MagizAI's connection test.
     *
     * @return array<string, string> secret name => ok | missing | unreadable
     */
    public static function secretHealth()
    {
        $out = array();

        foreach (self::SECRETS as $key) {
            try {
                $stored = (string) Capsule::table('tbladdonmodules')
                    ->where('module', self::MODULE)->where('setting', $key)->value('value');
            } catch (\Exception $e) {
                $out[$key] = 'unreadable';
                continue;
            }

            if ($stored === '') {
                $out[$key] = 'missing';
                continue;
            }

            $out[$key] = self::decrypt($stored) !== '' ? 'ok' : 'unreadable';
        }

        return $out;
    }

    public static function set($key, $value)
    {
        $value = (string) $value;
        $stored = self::isEncrypted($key) && $value !== '' ? self::encrypt($value) : $value;

        $exists = Capsule::table('tbladdonmodules')->where('module', self::MODULE)->where('setting', $key)->exists();

        if ($exists) {
            Capsule::table('tbladdonmodules')->where('module', self::MODULE)->where('setting', $key)->update(array('value' => $stored));
        } else {
            Capsule::table('tbladdonmodules')->insert(array('module' => self::MODULE, 'setting' => $key, 'value' => $stored));
        }

        // A copy that survives deactivation. @see Store::KEPT
        Store::keep($key, $stored);

        self::$cache = null;
    }

    /**
     * Write one setting that is not worth keeping through a deactivation (1.10.0: the ticket events'
     * health, written on every attempt): the one row in tbladdonmodules, nothing else.
     */
    public static function setTransient($key, $value)
    {
        $value = (string) $value;
        $updated = Capsule::table('tbladdonmodules')->where('module', self::MODULE)->where('setting', $key)->update(array('value' => $value));
        if (!$updated && !Capsule::table('tbladdonmodules')->where('module', self::MODULE)->where('setting', $key)->exists()) {
            Capsule::table('tbladdonmodules')->insert(array('module' => self::MODULE, 'setting' => $key, 'value' => $value));
        }
        self::$cache = null;
    }

    /**
     * Copy every current setting into the kept table.
     *
     * Run on upgrade and on deactivation, so an install whose settings were saved by a version that
     * kept no copy (anything before 1.5.7) is covered from the moment the new files are opened.
     */
    public static function snapshot()
    {
        // The admin chose "Forget the kept copy": deactivation is then meant to remove everything.
        if (!Store::keeping()) {
            return 0;
        }

        try {
            $rows = Capsule::table('tbladdonmodules')->where('module', self::MODULE)->get(array('setting', 'value'));
        } catch (\Exception $e) {
            return 0;
        }

        $n = 0;
        foreach ($rows as $row) {
            if (!in_array((string) $row->setting, Store::WHMCS_OWNED, true)) {
                Store::keep((string) $row->setting, (string) $row->value);
                $n++;
            }
        }

        return $n;
    }

    /**
     * Put back every kept setting that WHMCS deleted on deactivation.
     *
     * Only missing rows are written: a setting WHMCS still holds is newer than the copy by
     * definition. Values go back exactly as stored, so secrets stay encrypted throughout.
     *
     * @return int how many settings were restored
     */
    public static function restoreKept()
    {
        $kept = Store::kept();
        if (!$kept) {
            return 0;
        }

        $stored = Capsule::table('tbladdonmodules')->where('module', self::MODULE)->pluck('setting');
        $stored = is_array($stored) ? $stored : $stored->all();

        $n = 0;
        foreach ($kept as $setting => $value) {
            if (in_array($setting, Store::WHMCS_OWNED, true) || in_array($setting, $stored, true)) {
                continue;
            }
            Capsule::table('tbladdonmodules')->insert(array('module' => self::MODULE, 'setting' => $setting, 'value' => $value));
            $n++;
        }

        self::$cache = null;

        return $n;
    }

    private static function encrypt($plain)
    {
        $r = localAPI('EncryptPassword', array('password2' => $plain));
        if (!is_array($r) || ($r['result'] ?? '') !== 'success' || !isset($r['password'])) {
            throw new \RuntimeException('WHMCS could not encrypt the setting.');
        }

        return (string) $r['password'];
    }

    private static function decrypt($stored)
    {
        $r = localAPI('DecryptPassword', array('password2' => $stored));

        return is_array($r) && ($r['result'] ?? '') === 'success' ? (string) ($r['password'] ?? '') : '';
    }

    /** @return string[] */
    public static function enabledActions()
    {
        $list = json_decode(self::get('enabled_actions', '[]'), true);

        return Registry::switchedOn(is_array($list) ? $list : array(), self::stored());
    }

    public static function isEnabled($action)
    {
        return in_array($action, self::enabledActions(), true);
    }

    public static function magizaiBase()
    {
        $url = rtrim(trim(self::get('magizai_url', 'https://magiz.ai')), '/');

        return preg_match('#^https://[a-z0-9.-]+(:\d+)?$#i', $url) ? $url : 'https://magiz.ai';
    }

    /** The WHMCS System URL, with a trailing slash. */
    public static function systemUrl()
    {
        $url = (string) \WHMCS\Config\Setting::getValue('SystemURL');

        return rtrim($url, '/') . '/';
    }
}
