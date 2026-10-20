<?php

namespace MagizAI\Whmcs\Identity;

use MagizAI\Whmcs\Settings;

/**
 * What the module puts on client-area pages, as the admin set it: where the chat is shown, staff
 * view, how long a sign-in token lives, and where the chat fetches a fresh one.
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class PageSettings
{
    /** What a widget key looks like. Anything else is never written into a page. */
    const KEY_PATTERN = '/^[A-Za-z0-9_-]{4,80}$/';

    /**
     * Does the module put anything on client-area pages? The footer, the token endpoint and the
     * ping's `token_endpoint` all ask this, so a fresh sign-in is only ever handed out where the page
     * itself would carry one.
     *  - "self": the sign-in alone, for the merchant's own widget, so no widget key is needed;
     *  - "all" and "loggedin": the widget and the sign-in, only with a widget key that is one;
     *  - "off": nothing.
     */
    public static function onPages()
    {
        $placement = self::placement();
        if ($placement === 'off') {
            return false;
        }
        if ($placement === 'self') {
            return true;
        }

        return preg_match(self::KEY_PATTERN, trim(Settings::get('widget_key'))) === 1;
    }

    /**
     * Where the chat is shown: all | loggedin | self | off. A stored value from older versions that
     * is none of these ("clientarea") reads as "all", as it always did.
     */
    public static function placement()
    {
        $placement = (string) Settings::get('widget_placement', 'all');

        return in_array($placement, array('all', 'loggedin', 'self', 'off'), true) ? $placement : 'all';
    }

    /** Staff view: off | read_only. Anything else is off. */
    public static function staffMode()
    {
        return Settings::get('staff_mode', 'off') === 'read_only' ? 'read_only' : 'off';
    }

    /** The token lifetime the admin set (5 to 240 minutes). */
    public static function ttlMinutes()
    {
        return Settings::minutes('identity_ttl_minutes', 60, 5, 240);
    }

    /**
     * Where the chat fetches a fresh token, without the origin: WHMCS's own path (from the System
     * URL), e.g. "/billing/index.php?m=magizai&action=identity".
     *
     * The page puts its OWN origin in front of it (location.origin). A client area reached as
     * www.example.com while the System URL says example.com, or over https while it says http, would
     * otherwise send every refresh to another origin, where the session cookie does not go and this
     * module's headers forbid reading the answer: the refresh would never work, without a word.
     *
     * ALWAYS PLAIN ASCII. Every byte above 127 is percent-encoded, exactly as a browser sends it, so
     * "/facturación/" still reaches the same folder. A byte that is not valid UTF-8 used to make
     * json_encode() fail, which turned the page's whole identity script into a syntax error: no
     * sign-in, no state, on every client page, without a word. @see \MagizAI\Whmcs\Hooks\FooterHook::identityScript
     */
    public static function endpointPath()
    {
        $path = (string) parse_url(Settings::systemUrl(), PHP_URL_PATH);
        if ($path === '' || $path[0] !== '/') {
            $path = '/' . $path;
        }
        if (substr($path, -1) !== '/') {
            $path .= '/';
        }
        $path = (string) preg_replace_callback('/[\x80-\xFF]/', function ($byte) {
            return rawurlencode($byte[0]);
        }, $path);

        return $path . 'index.php?m=magizai&action=identity';
    }
}
