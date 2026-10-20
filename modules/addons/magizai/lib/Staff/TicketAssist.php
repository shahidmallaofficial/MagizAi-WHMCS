<?php

namespace MagizAI\Whmcs\Staff;

use MagizAI\Whmcs\ActionError;
use MagizAI\Whmcs\Crypto;
use MagizAI\Whmcs\Persistence\AuditLog;
use MagizAI\Whmcs\Settings;
use MagizAI\Whmcs\Support\WhmcsApi;
use MagizAI\Whmcs\Text;
use WHMCS\Database\Capsule;

/**
 * MagizAI on the WHMCS admin ticket page (1.10.0): a staff member asks for a draft reply, a better
 * version of their own text, a translation of it into the client's language, or a summary of the
 * ticket. The answer is text for them to read and, if they choose, put in the reply box. Nothing is
 * posted to the ticket and nothing is sent to the client from here.
 *
 * WHO. Only an admin signed in to this WHMCS, not disabled, who was shown this ticket's page (the pass,
 * AssistGrant) and is assigned to its support department, as WHMCS itself requires for answering a
 * ticket (a role with "Access All Tickets Directly" may use it on any department, as in WHMCS).
 *
 * WHAT IS SENT. To the MagizAI workspace this module is connected to only, signed with the bridge
 * secret like the ticket events: the ticket's number, subject, department and status, its messages
 * (who wrote each, client or staff, and the text, without names, addresses or attachments), the
 * client's language as WHMCS records it, and the staff member's draft. Card numbers, private keys and
 * the value after a password, PIN, token, API key or transfer code label are removed first (Redact).
 * Staff notes are never sent.
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class TicketAssist
{
    const PATH = '/api/integrations/whmcs/tickets/assist';

    const TASKS = array('draft', 'rewrite', 'translate', 'summary');
    const STYLES = array('shorter', 'friendly', 'formal', 'fix');
    const LANGUAGES = array('client', 'en', 'ar', 'fr', 'es', 'de', 'pt', 'it', 'hi', 'ur', 'tr', 'nl', 'bn');

    /** Requests one admin may make in ten minutes. */
    const PER_TEN_MINUTES = 30;

    const MAX_MESSAGES = 30;
    const MAX_MESSAGE = 4000;

    /** An AI answer takes seconds, not milliseconds; WHMCS waits for it only while the admin does. */
    const TIMEOUT = 45;
    const CONNECT_TIMEOUT = 5;

    /**
     * Tests only: replaces the HTTP request, given the url, headers and body; returns
     * array(status, body). Null in every real install.
     *
     * @var callable|null
     */
    public static $post = null;

    /** Switched on (Setup tab, Tickets) and connected to MagizAI. */
    public static function enabled()
    {
        return Settings::get('ticket_assist', 'on') !== 'off' && Settings::get('widget_key') !== '' && Settings::get('bridge_secret') !== '';
    }

    /**
     * The ticket, when this admin may work on it. @see the class comment
     *
     * @return array GetTicket's answer
     * @throws ActionError
     */
    public static function access($adminId, $ticketId)
    {
        $admin = Capsule::table('tbladmins')->where('id', (int) $adminId)->first();
        if (!$admin || !empty($admin->disabled)) {
            throw new ActionError('not_allowed', 'Sign in to the WHMCS admin area again.');
        }
        $ticket = WhmcsApi::api('GetTicket', array('ticketid' => (int) $ticketId, 'repliessort' => 'ASC'), false);
        if (($ticket['result'] ?? '') !== 'success') {
            throw new ActionError('not_found', 'That ticket was not found. It may have been deleted or merged.');
        }
        $departments = array_filter(array_map('intval', explode(',', isset($admin->supportdepts) ? (string) $admin->supportdepts : '')));
        if (!in_array((int) ($ticket['deptid'] ?? 0), $departments, true) && !self::seesAllTickets((int) $adminId)) {
            throw new ActionError('not_allowed', 'You are not assigned to this ticket\'s department.');
        }

        return $ticket;
    }

    /**
     * WHMCS's own "Access All Tickets Directly" role permission, through its admin model (WHMCS 7.10 and
     * later). Not knowing is no: the department rule then decides.
     */
    private static function seesAllTickets($adminId)
    {
        try {
            if (class_exists('\WHMCS\User\Admin') && method_exists('\WHMCS\User\Admin', 'getAuthenticatedUser')) {
                $admin = \WHMCS\User\Admin::getAuthenticatedUser();
                if (is_object($admin) && (int) $admin->id === (int) $adminId && method_exists($admin, 'hasPermission')) {
                    return (bool) $admin->hasPermission('Access All Tickets Directly');
                }
            }
        } catch (\Throwable $e) {
            return false;
        }

        return false;
    }

    /**
     * Ask MagizAI. Never posts to the ticket.
     *
     * @param array $input task, style, language, draft (from the panel)
     * @return array{text: string, task: string}
     * @throws ActionError
     */
    public static function ask($adminId, $ticketId, array $input)
    {
        $task = isset($input['task']) && in_array($input['task'], self::TASKS, true) ? $input['task'] : '';
        if ($task === '') {
            throw new ActionError('invalid_args', 'Choose what MagizAI should do.');
        }
        $style = isset($input['style']) && in_array($input['style'], self::STYLES, true) ? $input['style'] : null;
        $language = isset($input['language']) && in_array($input['language'], self::LANGUAGES, true) ? $input['language'] : null;
        $draft = isset($input['draft']) && is_string($input['draft']) ? Redact::text(mb_substr(trim($input['draft']), 0, self::MAX_MESSAGE)) : '';
        if (($task === 'rewrite' && $style === null) || ($task === 'translate' && $language === null)) {
            throw new ActionError('invalid_args', $task === 'rewrite' ? 'Choose how to improve the text.' : 'Choose a language.');
        }
        if (($task === 'rewrite' || $task === 'translate') && $draft === '') {
            throw new ActionError('invalid_args', 'Write something in the reply box first.');
        }

        $who = 'admin:' . (int) $adminId;
        if (AuditLog::recentRuns('ticket_assist', 600, $who) >= self::PER_TEN_MINUTES) {
            throw new ActionError('rate_limited', 'That is a lot of requests in ten minutes. Wait a few minutes and try again.');
        }

        $ticket = self::access($adminId, $ticketId);
        $clientId = (int) ($ticket['userid'] ?? 0);
        $body = array(
            'v' => 1,
            'task' => $task,
            'style' => $style,
            'language' => $language,
            'client_language' => $clientId > 0 ? (string) Capsule::table('tblclients')->where('id', $clientId)->value('language') : '',
            'ticket_id' => (int) $ticketId,
            'ticket' => array(
                'number' => (string) ($ticket['tid'] ?? ''),
                'subject' => Text::cut(Redact::text((string) ($ticket['subject'] ?? '')), 200),
                'department' => Text::cut((string) ($ticket['deptname'] ?? ''), 120),
                'status' => Text::cut((string) ($ticket['status'] ?? ''), 40),
            ),
            'thread' => self::thread($ticket),
            'draft' => $draft,
            'widget_key' => (string) Settings::get('widget_key'),
            'module_version' => (string) Settings::get('version'),
            'at' => time(),
        );

        $answer = self::send($body);
        $outcome = $answer['ok'] ? 'ok' : 'refused';
        AuditLog::audit('ticket_assist', false, $clientId > 0 ? $clientId : null, $who, $outcome, $answer['ok'] ? '' : $answer['error'],
            'ticket #' . (string) ($ticket['tid'] ?? $ticketId) . ': ' . $task . ($style ? ' ' . $style : '') . ($language ? ' ' . $language : ''));
        if (!$answer['ok']) {
            throw new ActionError($answer['error'], $answer['message']);
        }

        return array('text' => $answer['text'], 'task' => $task);
    }

    /**
     * The messages, oldest first, each as who wrote it (client or staff) and its text, secrets removed.
     * Staff notes are not replies, and are never read.
     *
     * @return array<int, array{from: string, text: string}>
     */
    public static function thread(array $ticket)
    {
        $replies = isset($ticket['replies']['reply']) && is_array($ticket['replies']['reply']) ? $ticket['replies']['reply'] : array();
        if (isset($replies['message'])) {
            $replies = array($replies);
        }
        $out = array();
        foreach ($replies as $reply) {
            if (!is_array($reply)) {
                continue;
            }
            $text = trim(html_entity_decode(strip_tags((string) ($reply['message'] ?? '')), ENT_QUOTES, 'UTF-8'));
            if ($text === '') {
                continue;
            }
            $out[] = array(
                'from' => trim((string) ($reply['admin'] ?? '')) !== '' ? 'staff' : 'client',
                'text' => Text::cut(Redact::text($text), self::MAX_MESSAGE),
            );
        }

        return array_slice($out, -self::MAX_MESSAGES);
    }

    /**
     * One signed request to MagizAI, as the ticket events are signed. Never throws.
     *
     * @return array{ok: bool, text: string, error: string, message: string}
     */
    private static function send(array $payload)
    {
        $body = (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        $ts = (string) time();
        $nonce = bin2hex(random_bytes(16));
        $url = Settings::magizaiBase() . self::PATH;
        $headers = array(
            'Content-Type: application/json',
            'Accept: application/json',
            'User-Agent: MagizAI-WHMCS/' . (string) Settings::get('version'),
            'X-MagizAI-Key: ' . (string) Settings::get('widget_key'),
            'X-MagizAI-Timestamp: ' . $ts,
            'X-MagizAI-Nonce: ' . $nonce,
            'X-MagizAI-Signature: ' . Crypto::sign(Settings::get('bridge_secret'), $ts, $nonce, $body),
        );
        $unreachable = array('ok' => false, 'text' => '', 'error' => 'unavailable', 'message' => 'MagizAI could not be reached just now. Try again in a minute.');

        try {
            $answer = self::$post !== null ? call_user_func(self::$post, $url, $headers, $body) : self::post($url, $headers, $body);
        } catch (\Throwable $e) {
            return $unreachable;
        }
        $json = is_array($answer) && isset($answer[1]) ? json_decode((string) $answer[1], true) : null;
        if (!is_array($json)) {
            if (function_exists('logModuleCall')) {
                logModuleCall('magizai', 'ticket_assist', array('url' => $url), 'HTTP ' . (is_array($answer) ? (int) $answer[0] : 0), '', array());
            }

            return $unreachable;
        }
        if (!empty($json['ok']) && isset($json['text']) && is_string($json['text']) && trim($json['text']) !== '') {
            return array('ok' => true, 'text' => mb_substr($json['text'], 0, 8000), 'error' => '', 'message' => '');
        }
        $error = isset($json['error']) && is_string($json['error']) && preg_match('/^[a-z_]{1,40}$/', $json['error']) ? $json['error'] : 'failed';
        $message = isset($json['message']) && is_string($json['message']) ? Text::cut(strip_tags($json['message']), 300) : '';
        if ($error === 'unauthorized') {
            $message = 'MagizAI did not accept this WHMCS. Check the connection on the Setup tab (Connected to MagizAI).';
        }

        return array('ok' => false, 'text' => '', 'error' => $error, 'message' => $message !== '' ? $message : 'MagizAI could not answer just now. Try again.');
    }

    /** @return array{0: int, 1: string} the HTTP status and body */
    private static function post($url, array $headers, $body)
    {
        if (!function_exists('curl_init')) {
            return array(0, '');
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_FOLLOWLOCATION => false,
        ));
        $out = curl_exec($ch);
        $status = $out === false ? 0 : (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if (PHP_VERSION_ID < 80000) {
            curl_close($ch);
        }

        return array($status, $out === false ? '' : (string) $out);
    }
}
