<?php

namespace MagizAI\Whmcs\Http;

use MagizAI\Whmcs\Identity\IdentityResolver;
use MagizAI\Whmcs\Identity\PageSettings;

/**
 * A fresh identity token for the page the chat is on: GET index.php?m=magizai&action=identity.
 *
 * WHY. A token used to be minted once, when the page rendered. A client who left the client area
 * open past the token's lifetime was a stranger to the chat on the very page that had identified
 * them. The chat now asks here for a new token when it has none or its token is about to expire, and
 * when the tab is shown again, which also notices a sign-out or another user signing in elsewhere.
 *
 * A WHMCS ROUTE, NOT A FILE. It runs through magizai_clientarea(), so it lives at an address WHMCS
 * already serves, in the client's own session. A file under modules/ can be missing or blocked
 * (one merchant's bridge.php answers 404).
 *
 * SAME ORIGIN ONLY. The answer is a bearer credential for the signed-in client, so:
 *  - GET only;
 *  - Cache-Control: no-store, private, so no cache keeps one person's token for the next;
 *  - X-Content-Type-Options: nosniff and Cross-Origin-Resource-Policy: same-origin, so another site
 *    cannot load it as a script or an image;
 *  - no CORS headers at all, so another site's scripts cannot read it;
 *  - and no token at all when something already sent the page's headers, since none of the above
 *    can be added then: {"v":1,"error":"headers_sent"} instead, and the cause in the module log.
 * It decides exactly as the footer does (IdentityResolver::issue), so a refresh can never get a token the page
 * itself would not have had, and it counts nothing: a refresh is not another page view.
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class TokenEndpoint
{
    /** The headers every answer carries, and nothing that would let another origin read it. */
    const HEADERS = array(
        'Content-Type' => 'application/json; charset=utf-8',
        'Cache-Control' => 'no-store, private',
        'X-Content-Type-Options' => 'nosniff',
        'Cross-Origin-Resource-Policy' => 'same-origin',
    );

    /** Removed if anything before this sent them: this answer is never readable cross-origin. */
    const CORS_HEADERS = array(
        'Access-Control-Allow-Origin', 'Access-Control-Allow-Credentials', 'Access-Control-Allow-Methods',
        'Access-Control-Allow-Headers', 'Access-Control-Expose-Headers', 'Access-Control-Max-Age',
    );

    /**
     * The answer to one request, built without sending anything.
     *
     * @param string $method the HTTP method
     * @param string $action the `action` query parameter
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    public static function respond($method, $action)
    {
        // 1.10.0: the ticket page's MagizAI panel, on the same route and with the same headers.
        if ((string) $action === 'ticket_assist') {
            return TicketAssistEndpoint::respond($method, isset($_POST) && is_array($_POST) ? $_POST : array());
        }
        if ((string) $action !== 'identity') {
            return self::response(404, array('v' => 1, 'error' => 'not_found'));
        }

        if (strtoupper((string) $method) !== 'GET') {
            return self::response(405, array('v' => 1, 'error' => 'method_not_allowed'), array('Allow' => 'GET'));
        }

        // No page carries a sign-in (the chat is set to "Nowhere", or the module would add a widget
        // but has no usable widget key), and neither does this. @see PageSettings::onPages
        if (!PageSettings::onPages()) {
            return self::response(404, array('v' => 1, 'error' => 'not_available'));
        }

        $identity = IdentityResolver::issue();

        return self::response(200, array(
            'v' => 1,
            'token' => $identity['token'],
            'state' => IdentityResolver::state($identity),
            'exp' => $identity['exp'],
        ));
    }

    /**
     * Answer the current request. Called from magizai_clientarea(), which then ends the request; the
     * only place in this class that sends anything.
     *
     * NOTHING BUT THE JSON, the same rule as bridge.php: a PHP notice printed on the way (with
     * "Display Errors" on), or anything a shutdown function prints after the answer, would turn the
     * body into something that is not JSON, and the chat would treat every refresh as failed without
     * a word. Errors are still logged the way WHMCS is configured; they are never printed here.
     */
    public static function serve($action)
    {
        if (function_exists('ini_set')) {
            @ini_set('display_errors', '0');
        }
        // Anything printed while deciding (where ini_set is disabled) is thrown away with the rest.
        ob_start();

        /*
         * Something already sent the page's headers (output flushed before this ran), so no-store,
         * nosniff and the same-origin rule can no longer be added, and a CORS header another module
         * set may already have gone out. A bearer credential never leaves without them, so none is
         * even made: the answer says why, the chat keeps what it has, and the module log says where
         * the output came from. (Nothing below can send them: the buffer above holds whatever is
         * printed while deciding, and the drain only discards.)
         */
        $file = '';
        $line = 0;
        $sent = headers_sent($file, $line);
        if ($sent) {
            // The status line has already gone out; this one is only what it would have been.
            $response = self::response(503, array('v' => 1, 'error' => 'headers_sent'));
            if (function_exists('logModuleCall')) {
                logModuleCall('magizai', 'identity_endpoint', array(),
                    'Output was already sent' . ($file !== '' ? ' from ' . $file . ':' . (int) $line : '') . ', so no fresh sign-in was handed out.', '', array());
            }
        } else {
            $response = self::respond(isset($_SERVER['REQUEST_METHOD']) ? (string) $_SERVER['REQUEST_METHOD'] : '', $action);
        }

        /*
         * Whatever WHMCS or another module printed before this point is not part of the answer. The
         * same rule as bridge.php's drain: a buffer that can be removed is removed; one that cannot be
         * removed is emptied when it allows that, and the drain stops there, never looping on it.
         */
        while (($level = ob_get_level()) > 0) {
            $status = ob_get_status();
            $flags = is_array($status) && isset($status['flags']) ? (int) $status['flags'] : 0;
            $cleanable = ($flags & PHP_OUTPUT_HANDLER_CLEANABLE) !== 0;

            if ($cleanable && ($flags & PHP_OUTPUT_HANDLER_REMOVABLE) !== 0) {
                @ob_end_clean();
                if (ob_get_level() < $level) {
                    continue;
                }
                break;
            }
            if ($cleanable) {
                @ob_clean();
            }
            break;
        }

        if (!$sent) {
            foreach (self::CORS_HEADERS as $name) {
                header_remove($name);
            }
            http_response_code($response['status']);
            foreach ($response['headers'] as $name => $value) {
                header($name . ': ' . $value);
            }
        }

        echo $response['body'];
        // Whatever a shutdown function or a destructor prints from here on is thrown away.
        ob_start(function () {
            return '';
        });
    }

    private static function response($status, array $payload, array $extraHeaders = array())
    {
        return array(
            'status' => (int) $status,
            'headers' => self::HEADERS + $extraHeaders,
            'body' => (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP),
        );
    }
}
