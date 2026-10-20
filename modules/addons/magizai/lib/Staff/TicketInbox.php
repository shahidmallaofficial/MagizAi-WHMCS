<?php

namespace MagizAI\Whmcs\Staff;

use MagizAI\Whmcs\ActionError;
use MagizAI\Whmcs\Settings;
use MagizAI\Whmcs\Support\WhmcsApi;
use MagizAI\Whmcs\Text;

/**
 * Tickets in the MagizAI inbox (module 1.7.0, extended in 1.9.0). What MagizAI's ticket sync may ask
 * for, and nothing else: the tickets with recent activity, one ticket's thread, a reply posted as a
 * staff member, a private staff note, (1.9.0) a ticket's status and (1.10.0) its priority and
 * department.
 *
 * These are STAFF powers, so they are kept apart from the chat actions on purpose:
 *   - they are not in the action registry, so they are never in the catalogue the chat AI is built
 *     from, and no visitor can talk the assistant into reading other clients' tickets;
 *   - the bridge runs them only for a request that says `scope: staff` (MagizAI's ticket sync and its
 *     inbox are the only code that sends it) and only while "Show tickets in the MagizAI inbox" is on;
 *   - replies are posted as the staff member the admin chose on the Setup tab (@see ReplyAs).
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class TicketInbox
{
    const ACTIONS = array('tickets_awaiting', 'ticket_thread', 'ticket_reply', 'ticket_note', 'ticket_status', 'ticket_update');

    /** The staff actions that change a ticket. 1.10.0: ticket_update, its priority and department. */
    const WRITES = array('ticket_reply', 'ticket_note', 'ticket_status', 'ticket_update');

    const MAX_TICKETS = 25;
    const MAX_REPLIES = 30;
    const MAX_MESSAGE = 4000;

    /** Switched on, and there is a staff member to post replies as. */
    public static function enabled()
    {
        return Settings::get('tickets_inbox') === 'on' && ReplyAs::username() !== '';
    }

    /**
     * Why the inbox is refused, in words an admin can act on, or null when it is on.
     *
     * @return string|null
     */
    public static function refusal()
    {
        if (Settings::get('tickets_inbox') !== 'on') {
            return 'Tickets are not switched on for the MagizAI inbox in this WHMCS.';
        }
        if (ReplyAs::username() === '') {
            return 'No active WHMCS admin to post ticket replies as. Choose one on the module\'s Setup tab.';
        }

        return null;
    }

    /**
     * @return array the data MagizAI reads
     * @throws ActionError
     */
    public static function run($action, array $args)
    {
        if ($action === 'tickets_awaiting') {
            return self::recent($args);
        }
        if ($action === 'ticket_thread') {
            return self::thread($args);
        }
        if ($action === 'ticket_reply') {
            return self::reply($args);
        }
        if ($action === 'ticket_note') {
            return self::note($args);
        }
        if ($action === 'ticket_status') {
            return TicketStatus::set($args);
        }
        if ($action === 'ticket_update') {
            return TicketUpdate::set($args);
        }
        throw new ActionError('unknown_action', 'This WHMCS module does not support that.');
    }

    /**
     * Tickets whose last reply is newer than `since` (a Unix time): those waiting for a staff reply, or
     * (1.9.0, `status: any`) every ticket, so a reply typed in WHMCS admin, which makes a ticket
     * Answered, still reaches the inbox. Every department: without ignore_dept_assignments WHMCS lists
     * only the departments of the admin the API runs as, and the rest were never seen.
     */
    private static function recent(array $args)
    {
        $since = isset($args['since']) ? (int) $args['since'] : 0;
        $limit = max(1, min(self::MAX_TICKETS, isset($args['limit']) ? (int) $args['limit'] : self::MAX_TICKETS));
        $any = isset($args['status']) && $args['status'] === 'any';
        $params = array('limitstart' => 0, 'limitnum' => 100, 'ignore_dept_assignments' => true);
        if (!$any) {
            $params['status'] = 'Awaiting Reply';
        }
        $r = WhmcsApi::api('GetTickets', $params);
        $rows = array();
        $list = isset($r['tickets']['ticket']) && is_array($r['tickets']['ticket']) ? $r['tickets']['ticket'] : array();
        if (isset($list['id'])) {
            $list = array($list);
        }
        foreach ($list as $t) {
            if (!is_array($t)) {
                continue;
            }
            $last = strtotime((string) ($t['lastreply'] ?? $t['date'] ?? '')) ?: 0;
            if ($last <= $since) {
                continue;
            }
            $rows[] = array(
                'ticketid' => (int) ($t['id'] ?? 0),
                'tid' => (string) ($t['tid'] ?? ''),
                'subject' => Text::cut((string) ($t['subject'] ?? ''), 200),
                'status' => (string) ($t['status'] ?? ''),
                'priority' => (string) ($t['priority'] ?? ''),
                'department' => (string) ($t['deptname'] ?? ''),
                'client_id' => (int) ($t['userid'] ?? 0),
                'name' => Text::cut((string) ($t['name'] ?? ''), 120),
                'email' => (string) ($t['email'] ?? ''),
                'last_reply_at' => $last,
            );
        }
        usort($rows, function ($a, $b) {
            return $a['last_reply_at'] - $b['last_reply_at'];
        });

        return array('tickets' => array_slice($rows, 0, $limit));
    }

    /** One ticket: what it is about, and its messages, oldest first, after a reply id MagizAI holds. */
    private static function thread(array $args)
    {
        $ticketId = isset($args['ticketid']) ? (int) $args['ticketid'] : 0;
        if ($ticketId < 1) {
            throw new ActionError('invalid_args', 'Which ticket?');
        }
        $after = isset($args['after_reply']) ? (int) $args['after_reply'] : 0;
        $r = self::ticket($ticketId);
        $replies = isset($r['replies']['reply']) && is_array($r['replies']['reply']) ? $r['replies']['reply'] : array();
        if (isset($replies['replyid']) || isset($replies['message'])) {
            $replies = array($replies);
        }
        $out = array();
        foreach ($replies as $reply) {
            if (!is_array($reply)) {
                continue;
            }
            // WHMCS lists the opening message as a reply with id 0.
            $id = (int) ($reply['replyid'] ?? 0);
            if ($id <= $after && !($after === 0 && $id === 0)) {
                continue;
            }
            $admin = trim((string) ($reply['admin'] ?? ''));
            $out[] = array(
                'replyid' => $id,
                'by' => $admin !== '' ? 'staff' : 'client',
                'name' => Text::cut($admin !== '' ? $admin : (string) ($reply['name'] ?? ''), 120),
                'message' => Text::cut(html_entity_decode(strip_tags((string) ($reply['message'] ?? '')), ENT_QUOTES, 'UTF-8'), self::MAX_MESSAGE),
                'at' => strtotime((string) ($reply['date'] ?? '')) ?: 0,
            );
        }

        return array(
            'ticketid' => $ticketId,
            'tid' => (string) ($r['tid'] ?? ''),
            'subject' => Text::cut((string) ($r['subject'] ?? ''), 200),
            'status' => (string) ($r['status'] ?? ''),
            // 1.9.0: what the inbox shows on the ticket's label, and who the ticket is from.
            'priority' => Text::cut((string) ($r['priority'] ?? ''), 40),
            'department' => Text::cut((string) ($r['deptname'] ?? ''), 120),
            'client_id' => (int) ($r['userid'] ?? 0),
            'name' => Text::cut((string) ($r['name'] ?? ''), 120),
            'email' => (string) ($r['email'] ?? ''),
            'admin_url' => self::adminUrl($ticketId),
            'replies' => array_slice($out, -self::MAX_REPLIES),
        );
    }

    /** GetTicket, with an unknown ticket refused as not found rather than as a fault. */
    private static function ticket($ticketId)
    {
        $r = WhmcsApi::api('GetTicket', array('ticketid' => (int) $ticketId, 'repliessort' => 'ASC'), false);
        if (($r['result'] ?? '') !== 'success') {
            $message = (string) ($r['message'] ?? '');
            if (stripos($message, 'not found') !== false) {
                throw new ActionError('not_found', 'That ticket was not found in WHMCS. It may have been deleted or merged.');
            }
            throw new \RuntimeException('GetTicket: ' . Text::cut(strip_tags($message), 300));
        }

        return $r;
    }

    /**
     * The ticket in this WHMCS's admin area. The admin folder is "admin" unless configuration.php
     * names another one ($customadminpath).
     */
    public static function adminUrl($ticketId)
    {
        $dir = isset($GLOBALS['customadminpath']) && is_string($GLOBALS['customadminpath'])
            && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $GLOBALS['customadminpath']) ? $GLOBALS['customadminpath'] : 'admin';

        return Settings::systemUrl() . $dir . '/supporttickets.php?action=view&id=' . (int) $ticketId;
    }

    /** A private staff note: the AI's draft, or a note typed in the inbox. Never sent to the client. */
    private static function note(array $args)
    {
        list($ticketId, $message) = self::ticketAndMessage($args);
        self::ticket($ticketId);
        self::write('AddTicketNote', array(
            'ticketid' => $ticketId,
            'message' => $message,
            'markdown' => true,
        ), 'the note');

        return array('ticketid' => $ticketId, 'noted' => true);
    }

    /** A reply posted as the staff member chosen on the Setup tab; WHMCS emails it to the client. */
    private static function reply(array $args)
    {
        list($ticketId, $message) = self::ticketAndMessage($args);
        self::ticket($ticketId);
        $r = self::write('AddTicketReply', array(
            'ticketid' => $ticketId,
            'message' => $message,
            'adminusername' => ReplyAs::username(),
            'markdown' => true,
        ), 'the reply');

        return array('ticketid' => $ticketId, 'replied' => true, 'result' => (string) ($r['result'] ?? ''));
    }

    /**
     * A change WHMCS may refuse. Its own words go back to MagizAI: the inbox shows them to the staff
     * member whose reply or note did not arrive, so they know what to fix.
     */
    public static function write($command, array $params, $what)
    {
        $r = WhmcsApi::api($command, $params, false);
        if (($r['result'] ?? '') !== 'success') {
            $said = Text::cut(trim(strip_tags((string) ($r['message'] ?? ''))), 200);
            if (function_exists('logModuleCall')) {
                logModuleCall('magizai', $command, array('ticketid' => $params['ticketid'] ?? null), $r, '', array());
            }
            throw new ActionError('refused', 'WHMCS refused ' . $what . ($said !== '' ? ': ' . $said : '.'));
        }

        return $r;
    }

    /** @return array{0: int, 1: string} */
    private static function ticketAndMessage(array $args)
    {
        $ticketId = isset($args['ticketid']) ? (int) $args['ticketid'] : 0;
        $message = isset($args['message']) && is_string($args['message']) ? trim($args['message']) : '';
        if ($ticketId < 1 || mb_strlen($message) < 2) {
            throw new ActionError('invalid_args', 'A ticket and a message are needed.');
        }

        return array($ticketId, mb_substr($message, 0, 10000));
    }
}
