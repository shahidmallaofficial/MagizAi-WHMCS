<?php

namespace MagizAI\Whmcs\Http;

use MagizAI\Whmcs\ActionError;
use MagizAI\Whmcs\Identity\Session;
use MagizAI\Whmcs\Staff\AssistGrant;
use MagizAI\Whmcs\Staff\TicketAssist;

/**
 * POST index.php?m=magizai&action=ticket_assist (1.10.0): the ticket page's MagizAI panel asks here,
 * in the admin's own WHMCS session, and gets JSON back. A WHMCS route like the sign-in refresh, served
 * through magizai_clientarea() with the same headers: no caching, nothing another site can read.
 *
 * Refused unless the panel is switched on, someone is signed in to the admin area in this browser, and
 * the request carries the pass the ticket page was given for that admin and that ticket. Who may work
 * on the ticket is then decided by TicketAssist::access.
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class TicketAssistEndpoint
{
    /**
     * @param string $method the HTTP method
     * @param array  $post   the posted fields: grant, task, style, language, draft
     * @param array|null $session who is signed in (Identity\Session::current), for tests
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    public static function respond($method, array $post, ?array $session = null)
    {
        if (strtoupper((string) $method) !== 'POST') {
            return self::response(405, array('ok' => false, 'error' => 'method_not_allowed'), array('Allow' => 'POST'));
        }
        if (!TicketAssist::enabled()) {
            return self::response(404, array('ok' => false, 'error' => 'not_available', 'message' => 'MagizAI on the ticket page is switched off in this WHMCS.'));
        }
        $who = $session !== null ? $session : Session::current();
        $adminId = isset($who['admin_id']) ? (int) $who['admin_id'] : 0;
        if ($adminId < 1) {
            return self::response(401, array('ok' => false, 'error' => 'not_signed_in', 'message' => 'Sign in to the WHMCS admin area again.'));
        }
        $grant = isset($post['grant']) && is_string($post['grant']) ? $post['grant'] : '';
        $ticketId = AssistGrant::ticketOf($grant);
        if (!AssistGrant::valid($grant, $adminId, $ticketId)) {
            return self::response(403, array('ok' => false, 'error' => 'expired', 'message' => 'Reload the ticket page and try again.'));
        }

        try {
            $answer = TicketAssist::ask($adminId, $ticketId, $post);

            return self::response(200, array('ok' => true, 'task' => $answer['task'], 'text' => $answer['text']));
        } catch (ActionError $e) {
            return self::response(422, array('ok' => false, 'error' => $e->code(), 'message' => $e->getMessage()));
        } catch (\Throwable $e) {
            if (function_exists('logModuleCall')) {
                logModuleCall('magizai', 'ticket_assist', array('ticket' => $ticketId), $e->getMessage(), '', array());
            }

            return self::response(500, array('ok' => false, 'error' => 'internal', 'message' => 'WHMCS could not complete this request.'));
        }
    }

    private static function response($status, array $payload, array $extraHeaders = array())
    {
        return array(
            'status' => (int) $status,
            'headers' => TokenEndpoint::HEADERS + $extraHeaders,
            'body' => (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE),
        );
    }
}
