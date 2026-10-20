<?php

namespace MagizAI\Whmcs\Hooks;

use MagizAI\Whmcs\Identity\IdentityResolver;
use MagizAI\Whmcs\Identity\PageSettings;
use MagizAI\Whmcs\Identity\Telemetry;
use MagizAI\Whmcs\Settings;
use MagizAI\Whmcs\Text;

/**
 * ClientAreaFooterOutput: the chat widget on client-area pages, with a signed identity for whoever
 * is signed in, and the reason when there is none.
 *
 * The identity token is what lets the assistant answer "when does my domain expire?" for THIS client
 * and nobody else. It is minted server-side for each page (and again by the token endpoint when it
 * is about to run out), and only the finished token reaches the browser, never a secret. The page
 * says what was decided, in reason codes only:
 *
 *   <script>window.MagizAIIdentity=<token|null>;window.MagizAIIdentityState={"v":1,"status":...,
 *   "reason":...};window.MagizAIIdentityEndpoint=location.origin+"<SystemURL's path>index.php?m=magizai&action=identity";</script>
 *
 * The state is a hint for wording. MagizAI never treats it as proof of anything; only a token is.
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class FooterHook
{
    /** What the footer adds to one client-area page. */
    public static function run(array $vars)
    {
        if (!PageSettings::onPages()) {
            // "Nowhere", or no usable widget key for the widget the module would add: nothing at all.
            // A bearer token on every page of a merchant who switched MagizAI off would only be
            // something to steal.
            return '';
        }

        $placement = PageSettings::placement();
        // With "self" the merchant embeds the widget, so the module adds only the sign-in.
        $withWidget = $placement !== 'self';

        $identity = IdentityResolver::issue();

        // Counted only when no token went out, and only for the reasons worth knowing.
        if ($identity['token'] === null) {
            Telemetry::count($identity['reason']);
        }

        /*
         * "ONLY TO SIGNED-IN CLIENTS" MEANS A CLIENT IS SIGNED IN, not that a token could be made.
         * A signed-in client still gets the chat when there is no identity secret yet or the token
         * failed (the page says why), exactly as before 1.6.0. Signed out, no account chosen, a closed
         * account, and staff with staff view off: no chat.
         */
        if ($placement === 'loggedin' && !$identity['signed_in']) {
            return '';
        }

        $html = self::identityScript($identity);

        if ($withWidget) {
            $html .= '<script src="' . Text::html(Settings::magizaiBase() . '/widget.js') . '" data-key="' . Text::html(trim(Settings::get('widget_key'))) . '" defer></script>';
        }

        return $html;
    }

    /**
     * The globals the chat reads, in one script tag.
     *
     * - window.MagizAIIdentity: the token, or null ("nobody is identified on this page"). Not set at
     *   all when there is no identity secret: then nothing can be identified anywhere, and the page
     *   is not "signed out", it is not set up.
     * - window.MagizAIIdentityState: why, in codes.
     * - window.MagizAIIdentityEndpoint: only when a token was issued, so a page with nobody to
     *   identify never invites the chat to ask for one. Built in the page from its own origin, so it
     *   is always the same origin as the page. @see PageSettings::endpointPath
     *
     * JSON_HEX_* so nothing in a value can close the script tag. JSON_INVALID_UTF8_SUBSTITUTE, and a
     * value that still cannot be written is left out, so one bad byte can never turn the script into
     * a syntax error that takes every global with it.
     */
    private static function identityScript(array $identity)
    {
        $flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;
        $parts = array();

        if ($identity['reason'] !== 'not_configured') {
            $token = $identity['token'] !== null ? json_encode($identity['token'], $flags) : 'null';
            $parts[] = 'window.MagizAIIdentity=' . (is_string($token) ? $token : 'null');
        }
        $state = json_encode(IdentityResolver::state($identity), $flags);
        if (is_string($state)) {
            $parts[] = 'window.MagizAIIdentityState=' . $state;
        }
        if ($identity['token'] !== null) {
            $path = json_encode(PageSettings::endpointPath(), $flags);
            if (is_string($path)) {
                $parts[] = 'window.MagizAIIdentityEndpoint=location.origin+' . $path;
            }
        }

        return '<script>' . implode(';', $parts) . ';</script>';
    }
}
