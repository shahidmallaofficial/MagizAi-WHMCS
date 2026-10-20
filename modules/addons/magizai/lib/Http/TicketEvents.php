<?php

namespace MagizAI\Whmcs\Http;

use MagizAI\Whmcs\Crypto;
use MagizAI\Whmcs\Settings;
use MagizAI\Whmcs\Staff\TicketInbox;
use MagizAI\Whmcs\Text;

/**
 * Tells MagizAI that a ticket changed (1.9.0), so it shows in the inbox at once instead of at the
 * next five-minute check, which stays as the backup.
 *
 * The event is small and carries no message: the ticket's number, what happened, and a status when
 * WHMCS gave one. MagizAI then reads the ticket through the bridge's staff path, as it always has.
 * It is signed exactly as the bridge signs its replies (HMAC-SHA256 with the bridge secret over the
 * timestamp, a nonce and the body), and names the chat widget's key, so MagizAI knows which workspace
 * it is for and can check the signature with that workspace's copy of the secret.
 *
 * NEVER IN WHMCS'S WAY. A short timeout (three seconds in all), no retries, and nothing thrown: a
 * MagizAI that is down or slow costs a client's reply at most those three seconds, once, and after a
 * failure nothing is sent for a minute. Nothing is sent from inside the bridge (MagizAI made that
 * change itself), or while tickets are not switched on.
 *
 * VISIBLE (1.10.0). Every attempt is recorded in one setting (health()): when it was, what came back
 * (the HTTP status, or why nothing did, such as "could not connect" or "timed out"), how many failed
 * in a row and when one last got through. The Setup tab shows it with a test button (test()), and the
 * ping hands it to MagizAI (report()). Failures also go to WHMCS's module log, as before.
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class TicketEvents
{
    const PATH = '/api/integrations/whmcs/tickets/events';

    const TIMEOUT = 3;
    const CONNECT_TIMEOUT = 2;

    /** Seconds nothing is sent after a failure. */
    const PAUSE = 60;

    /** The setting the health is kept in, as JSON. Not kept through a deactivation. */
    const HEALTH = 'ticket_events_health';

    /** The test event (1.10.0): MagizAI checks it like any event and reads no ticket. */
    const TEST_EVENT = 'ticket.test';

    /**
     * Tests only: replaces the HTTP request, given the url, headers and body, and returns the HTTP
     * status (0 when nothing answered). Null in every real install.
     *
     * @var callable|null
     */
    public static $post = null;

    /**
     * Why nothing would be sent now, or null when an event can go: bridge, tickets_off, not_connected
     * or paused. $inBridge false asks as if outside the bridge (the ping, which runs inside it).
     */
    public static function blocked($inBridge = true)
    {
        if ($inBridge && defined('MAGIZAI_BRIDGE')) {
            return 'bridge';
        }
        if (!TicketInbox::enabled()) {
            return 'tickets_off';
        }
        if (Settings::get('widget_key') === '' || Settings::get('bridge_secret') === '') {
            return 'not_connected';
        }
        $failed = (int) self::health()['failed_at'];
        if ($failed > 0 && time() - $failed < self::PAUSE) {
            return 'paused';
        }

        return null;
    }

    /**
     * What the last attempts came to. Times are Unix seconds, 0 for never.
     *
     * @return array{last_attempt_at: int, last_ok_at: int, last_result: string, failures: int, failed_at: int, blocked: string, blocked_at: int}
     */
    public static function health()
    {
        $raw = json_decode((string) Settings::get(self::HEALTH, ''), true);
        $raw = is_array($raw) ? $raw : array();
        $out = array('last_attempt_at' => 0, 'last_ok_at' => 0, 'last_result' => '', 'failures' => 0, 'failed_at' => 0, 'blocked' => '', 'blocked_at' => 0);
        foreach ($out as $key => $default) {
            if (isset($raw[$key]) && is_scalar($raw[$key])) {
                $out[$key] = is_int($default) ? max(0, (int) $raw[$key]) : Text::cut((string) $raw[$key], 160);
            }
        }
        // 1.9.0 kept only the time of the last failure, for the pause.
        if ($out['failed_at'] === 0) {
            $out['failed_at'] = max(0, (int) Settings::get('ticket_events_failed_at', '0'));
        }

        return $out;
    }

    /**
     * The health as the ping reports it to MagizAI: ISO times, never a secret.
     *
     * @return array{last_attempt_at: string|null, last_ok_at: string|null, last_result: string, failures: int, paused: bool, blocked: string|null, magizai_host: string}
     */
    public static function report()
    {
        $h = self::health();
        $iso = function ($t) {
            return $t > 0 ? gmdate('Y-m-d\TH:i:s\Z', $t) : null;
        };
        $blocked = self::blocked(false);

        return array(
            'last_attempt_at' => $iso($h['last_attempt_at']),
            'last_ok_at' => $iso($h['last_ok_at']),
            'last_result' => $h['last_result'],
            'failures' => $h['failures'],
            'paused' => $blocked === 'paused',
            'blocked' => $blocked,
            'magizai_host' => (string) parse_url(Settings::magizaiBase(), PHP_URL_HOST),
        );
    }

    /**
     * The request for one event: where it goes, its headers and the exact bytes signed.
     *
     * @param array<string, int|string> $fields ticket_id, and reply_id or status when WHMCS gave them
     * @return array{url: string, headers: string[], body: string}
     */
    public static function build($event, $hook, array $fields)
    {
        $body = json_encode(array(
            'v' => 1,
            'event' => (string) $event,
            'hook' => (string) $hook,
            'ticket_id' => (int) ($fields['ticket_id'] ?? 0),
            'reply_id' => isset($fields['reply_id']) ? (int) $fields['reply_id'] : null,
            'status' => isset($fields['status']) ? (string) $fields['status'] : null,
            'widget_key' => (string) Settings::get('widget_key'),
            'whmcs_url' => Settings::systemUrl(),
            'module_version' => (string) Settings::get('version'),
            'at' => time(),
        ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        $ts = (string) time();
        $nonce = bin2hex(random_bytes(16));

        return array(
            'url' => Settings::magizaiBase() . self::PATH,
            'headers' => array(
                'Content-Type: application/json',
                'Accept: application/json',
                'User-Agent: MagizAI-WHMCS/' . (string) Settings::get('version'),
                'X-MagizAI-Key: ' . (string) Settings::get('widget_key'),
                'X-MagizAI-Timestamp: ' . $ts,
                'X-MagizAI-Nonce: ' . $nonce,
                'X-MagizAI-Signature: ' . Crypto::sign(Settings::get('bridge_secret'), $ts, $nonce, (string) $body),
            ),
            'body' => (string) $body,
        );
    }

    /**
     * Send one event. Never throws.
     *
     * @return bool whether MagizAI took it
     */
    public static function send($event, $hook, array $fields)
    {
        try {
            if ((int) ($fields['ticket_id'] ?? 0) < 1) {
                return false;
            }
            $why = self::blocked();
            if ($why !== null) {
                // Not connected is worth seeing on the Setup tab and in the ping; the rest are choices or a pause.
                if ($why === 'not_connected') {
                    self::noteBlocked($why);
                }

                return false;
            }

            return self::deliver(self::build($event, $hook, $fields), $event, (int) $fields['ticket_id'])['ok'];
        } catch (\Throwable $e) {
            try {
                self::failed($event, (int) ($fields['ticket_id'] ?? 0), $e->getMessage(), 'error: ' . Text::cut($e->getMessage(), 80));
            } catch (\Throwable $ignored) {
                // Even the log is not worth breaking WHMCS for.
            }
        }

        return false;
    }

    /**
     * The Setup tab's "Send a test ticket update" (1.10.0): one signed test event, sent whatever the
     * pause, with the answer in words an admin can act on. MagizAI reads no ticket for it.
     *
     * @return array{ok: bool, message: string}
     */
    public static function test()
    {
        if (Settings::get('widget_key') === '' || Settings::get('bridge_secret') === '') {
            return array('ok' => false, 'message' => 'Not sent: this module is not connected to MagizAI (the widget key or the bridge secret is empty). '
                . 'Connect it with a code on this tab first. Ticket updates need the widget key to tell MagizAI which workspace they are for.');
        }
        $host = (string) parse_url(Settings::magizaiBase(), PHP_URL_HOST);
        try {
            $answer = self::deliver(self::build(self::TEST_EVENT, 'test', array('ticket_id' => 0)), self::TEST_EVENT, 0);
        } catch (\Throwable $e) {
            return array('ok' => false, 'message' => 'The test could not be sent: ' . Text::cut($e->getMessage(), 200));
        }
        $status = $answer['status'];
        if ($answer['ok']) {
            return array('ok' => true, 'message' => 'MagizAI at ' . $host . ' accepted the test (HTTP ' . $status . ').'
                . (TicketInbox::enabled() ? ' Ticket changes are sent to MagizAI as they happen.' : ' Tick "Show tickets in the MagizAI inbox" above, or no ticket changes are sent.'));
        }
        if ($status === 401) {
            return array('ok' => false, 'message' => 'MagizAI at ' . $host . ' refused the test (HTTP 401): it does not know this widget key with this bridge secret. '
                . 'Check the widget key is the one of the chatbot this WHMCS is connected to, or connect again with a code.');
        }
        if ($status >= 300 && $status < 400) {
            return array('ok' => false, 'message' => 'MagizAI at ' . $host . ' answered with a redirect (' . $answer['result'] . '), so nothing arrived. '
                . 'The MagizAI address on this tab may be out of date: it should be https://magiz.ai unless you use a white-label address.');
        }
        if ($status === 0) {
            return array('ok' => false, 'message' => 'The test did not reach MagizAI at ' . $host . ': ' . $answer['result'] . '. '
                . 'Check that this server can make outbound HTTPS requests to ' . $host . ' (a firewall, a proxy, or an old certificate bundle can stop them).');
        }

        return array('ok' => false, 'message' => 'MagizAI at ' . $host . ' answered ' . $answer['result'] . ', so the test did not get through.');
    }

    /**
     * One request, its health recorded with a single settings write.
     *
     * @return array{ok: bool, status: int, result: string}
     */
    private static function deliver(array $request, $event, $ticketId)
    {
        if (self::$post !== null) {
            $status = (int) call_user_func(self::$post, $request['url'], $request['headers'], $request['body']);
            $reason = '';
        } else {
            list($status, $reason) = self::post($request);
        }
        if ($status >= 200 && $status < 300) {
            self::note(true, 'HTTP ' . $status);

            return array('ok' => true, 'status' => $status, 'result' => 'HTTP ' . $status);
        }
        $result = $status === 0 ? ($reason !== '' ? $reason : 'no answer') : 'HTTP ' . $status . ($reason !== '' ? ' ' . $reason : '');
        self::failed($event, $ticketId, $status === 0 ? 'MagizAI did not answer' . ($reason !== '' ? ': ' . $reason : '') : 'MagizAI answered HTTP ' . $status . ($reason !== '' ? ' ' . $reason : ''), $result);

        return array('ok' => false, 'status' => $status, 'result' => $result);
    }

    /** @return array{0: int, 1: string} the HTTP status (0 when nothing answered in time) and why, when it says more */
    private static function post(array $request)
    {
        if (!function_exists('curl_init')) {
            return array(0, 'curl missing');
        }
        $ch = curl_init($request['url']);
        curl_setopt_array($ch, array(
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $request['body'],
            CURLOPT_HTTPHEADER => $request['headers'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_FOLLOWLOCATION => false,
        ));
        $ok = curl_exec($ch);
        $status = $ok === false ? 0 : (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $reason = '';
        if ($ok === false) {
            $reason = self::curlReason((int) curl_errno($ch));
        } elseif ($status >= 300 && $status < 400) {
            $to = (string) parse_url((string) curl_getinfo($ch, CURLINFO_REDIRECT_URL), PHP_URL_HOST);
            $reason = $to !== '' ? 'to ' . $to : '';
        }
        if (PHP_VERSION_ID < 80000) {
            curl_close($ch);
        }

        return array($status, $reason);
    }

    /** A curl error in plain words. */
    public static function curlReason($errno)
    {
        if ($errno === 6) {
            return 'could not find the address (DNS)';
        }
        if ($errno === 7) {
            return 'could not connect';
        }
        if ($errno === 28) {
            return 'timed out';
        }
        if (in_array($errno, array(35, 51, 53, 54, 58, 59, 60, 64, 66, 77, 80, 82, 83, 90, 91), true)) {
            return 'TLS or certificate problem (curl ' . $errno . ')';
        }

        return 'network error (curl ' . $errno . ')';
    }

    /** Logged for staff, recorded, and a minute's pause so an outage is not paid for on every ticket reply. */
    private static function failed($event, $ticketId, $why, $result)
    {
        self::note(false, $result);
        if (function_exists('logModuleCall')) {
            logModuleCall('magizai', 'ticket_event', array('event' => $event, 'ticket' => $ticketId), $why, '', array());
        }
    }

    /** One settings write per attempt. */
    private static function note($ok, $result)
    {
        $h = self::health();
        $h['last_attempt_at'] = time();
        $h['last_result'] = Text::cut((string) $result, 160);
        $h['blocked'] = '';
        $h['blocked_at'] = 0;
        if ($ok) {
            $h['last_ok_at'] = time();
            $h['failures'] = 0;
            $h['failed_at'] = 0;
        } else {
            $h['failures'] = (int) $h['failures'] + 1;
            $h['failed_at'] = time();
        }
        Settings::setTransient(self::HEALTH, (string) json_encode($h));
    }

    /** Why an event was not even tried, at most once an hour while it stays the same. */
    private static function noteBlocked($why)
    {
        $h = self::health();
        if ($h['blocked'] === $why && time() - (int) $h['blocked_at'] < 3600) {
            return;
        }
        $h['blocked'] = $why;
        $h['blocked_at'] = time();
        Settings::setTransient(self::HEALTH, (string) json_encode($h));
    }
}
