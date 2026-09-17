<?php

namespace MagizAI\Whmcs;

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

    /** Settings stored encrypted. */
    const SECRETS = array('bridge_secret', 'identity_secret', 'api_token');

    /** Defaults for everything the module reads. */
    public static function defaults()
    {
        return array(
            'magizai_url' => 'https://magiz.ai',
            'widget_key' => '',
            'identity_secret' => '',
            'bridge_secret' => '',
            'api_token' => '',
            // all | clientarea | loggedin | off
            'widget_placement' => 'all',
            'identity_ttl_minutes' => '60',
            // How recently a client must have loaded a client-area page before a change is accepted.
            'write_freshness_minutes' => '60',
            'writes_per_client_per_hour' => '10',
            'notify_client_on_change' => 'on',
            'enabled_actions' => json_encode(Actions::defaultEnabled()),
            'ticket_department_id' => '',
            'sync_kb' => 'on',
            'sync_announcements' => 'on',
            'sync_products' => 'on',
            'sync_company_facts' => 'on',
            'last_sync_at' => '',
            'last_sync_result' => '',
        );
    }

    private static $cache = null;

    public static function all()
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $values = self::defaults();

        $rows = Capsule::table('tbladdonmodules')->where('module', self::MODULE)->get(array('setting', 'value'));
        foreach ($rows as $row) {
            $values[$row->setting] = (string) $row->value;
        }

        foreach (self::SECRETS as $key) {
            $values[$key] = $values[$key] !== '' ? self::decrypt($values[$key]) : '';
        }

        return self::$cache = $values;
    }

    public static function get($key, $default = '')
    {
        $all = self::all();

        return array_key_exists($key, $all) ? $all[$key] : $default;
    }

    public static function set($key, $value)
    {
        $value = (string) $value;
        $stored = in_array($key, self::SECRETS, true) && $value !== '' ? self::encrypt($value) : $value;

        $exists = Capsule::table('tbladdonmodules')->where('module', self::MODULE)->where('setting', $key)->exists();

        if ($exists) {
            Capsule::table('tbladdonmodules')->where('module', self::MODULE)->where('setting', $key)->update(array('value' => $stored));
        } else {
            Capsule::table('tbladdonmodules')->insert(array('module' => self::MODULE, 'setting' => $key, 'value' => $stored));
        }

        self::$cache = null;
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

        return is_array($list) ? array_values(array_intersect($list, array_keys(Actions::catalog()))) : array();
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
