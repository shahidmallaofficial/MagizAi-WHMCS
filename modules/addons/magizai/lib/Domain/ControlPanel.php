<?php

namespace MagizAI\Whmcs\Domain;

use MagizAI\Whmcs\Action\Permissions;
use MagizAI\Whmcs\Settings;
use MagizAI\Whmcs\Support\Format;
use WHMCS\Database\Capsule;

/**
 * A service's control panel: where the client signs in, the server module behind it, and WHMCS's
 * one-click single sign-on to it.
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class ControlPanel
{
    /** Product and server modules already looked up in this run. @see serverModule */
    private static $serverModules = array();

    /**
     * Where the client signs in to their own control panel.
     *
     * Derived from the server hostname and the provisioning module, because those are the two
     * things WHMCS actually knows. An unrecognised module returns an empty string rather than a
     * guessed port: a login link that goes nowhere is worse than no link, and the client area link
     * is always offered alongside.
     */
    public static function panelUrl(array $p)
    {
        $host = strtolower(trim((string) (isset($p['serverhostname']) ? $p['serverhostname'] : '')));

        if ($host === '') {
            return '';
        }

        $module = strtolower(trim((string) (isset($p['moduleName']) ? $p['moduleName'] : (isset($p['module']) ? $p['module'] : ''))));

        // GetClientsProducts has no module field at all, so the line above found nothing on every
        // real install and no client was ever given a control-panel link. WHMCS keeps it on the
        // product (tblproducts.servertype), and on the server as a fallback.
        if ($module === '') {
            $module = self::serverModule(isset($p['pid']) ? (int) $p['pid'] : 0, isset($p['serverid']) ? (int) $p['serverid'] : 0);
        }

        $ports = array(
            'cpanel' => 2083,
            'plesk' => 8443,
            'directadmin' => 2222,
            'interworx' => 2443,
            'cyberpanel' => 8090,
            'virtualmin' => 10000,
            'webmin' => 10000,
        );

        foreach ($ports as $needle => $port) {
            if ($module !== '' && strpos($module, $needle) !== false) {
                return 'https://' . $host . ':' . $port;
            }
        }

        return '';
    }

    /**
     * The provisioning module behind a service: the product's server type, or the server's.
     * Looked up once per product and server in a request.
     */
    public static function serverModule($productId, $serverId)
    {
        $key = (int) $productId . ':' . (int) $serverId;
        if (isset(self::$serverModules[$key])) {
            return self::$serverModules[$key];
        }

        $module = '';
        try {
            if ((int) $productId > 0) {
                $module = strtolower(trim((string) Capsule::table('tblproducts')->where('id', (int) $productId)->value('servertype')));
            }
            if ($module === '' && (int) $serverId > 0) {
                $module = strtolower(trim((string) Capsule::table('tblservers')->where('id', (int) $serverId)->value('type')));
            }
        } catch (\Exception $e) {
            $module = '';
        }

        return self::$serverModules[$key] = $module;
    }

    /** The server modules whose control panel WHMCS opens in one click from the service page. */
    const SSO_MODULES = array('cpanel', 'plesk', 'directadmin', 'interworx', 'cyberpanel');

    /**
     * Why this service gets no one-click control panel link, or '' when it does:
     *  - module:       its server module has no single sign-on (an IPTV panel, a licence, none);
     *  - status:       it is not active, and WHMCS opens a control panel only for an active service;
     *  - permission:   the signed-in team member lacks WHMCS's "Perform Single Sign-On";
     *  - switched_off: the account switched single sign-on off in its security settings.
     */
    public static function ssoBlock($clientId, array $p)
    {
        $module = self::serverModule(isset($p['pid']) ? (int) $p['pid'] : 0, isset($p['serverid']) ? (int) $p['serverid'] : 0);
        if (!in_array($module, self::SSO_MODULES, true)) {
            return 'module';
        }

        if ((string) $p['status'] !== 'Active') {
            return 'status';
        }

        if (!Permissions::userMay($clientId, array('products', 'productsso'))) {
            return 'permission';
        }

        return self::ssoSwitchedOff($clientId) ? 'switched_off' : '';
    }

    /** WHMCS's own trigger for the module's single sign-on, on this service's page. */
    public static function ssoUrl(array $p)
    {
        return Settings::systemUrl() . 'clientarea.php?action=productdetails&id=' . (int) $p['id'] . '&dosinglesignon=1';
    }

    /** The one-click control panel link for this service, or '' when there is none to give. */
    public static function ssoLink($clientId, array $p)
    {
        return self::ssoBlock($clientId, $p) === '' ? self::ssoUrl($p) : '';
    }

    /**
     * Has the account switched single sign-on off (Security Settings in the client area)? Only a
     * plain "off" from WHMCS counts. An answer that cannot be read leaves the link in place: WHMCS
     * itself refuses the sign-on when it is off, so the worst case is the client reading WHMCS's
     * own message.
     */
    public static function ssoSwitchedOff($clientId)
    {
        $details = Clients::clientDetails($clientId);

        $flag = null;
        if (isset($details['client']) && is_array($details['client']) && array_key_exists('allowSingleSignOn', $details['client'])) {
            $flag = $details['client']['allowSingleSignOn'];
        } elseif (array_key_exists('allowSingleSignOn', $details)) {
            $flag = $details['allowSingleSignOn'];
        }

        return $flag !== null && $flag !== '' && !Format::flagOn($flag);
    }

    /** Forget what was remembered for the request that ran last. @see \MagizAI\Whmcs\Action\Runner::forget */
    public static function forget()
    {
        self::$serverModules = array();
    }
}
