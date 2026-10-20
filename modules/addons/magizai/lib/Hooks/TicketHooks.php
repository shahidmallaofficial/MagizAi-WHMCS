<?php

namespace MagizAI\Whmcs\Hooks;

use MagizAI\Whmcs\Http\TicketEvents;

/**
 * WHMCS's ticket hooks (1.9.0): a ticket opened, a reply from the client or from staff, a status
 * changed, a ticket closed. Each tells MagizAI which ticket changed (Http\TicketEvents), so it shows
 * in the inbox at once. The names and the parameters are WHMCS's own (developers.whmcs.com, hooks
 * reference, Ticket): TicketOpen and TicketOpenAdmin (ticketid, ticketmask, userid, deptid, deptname,
 * subject, message, priority), TicketUserReply and TicketAdminReply (ticketid, replyid, ..., status),
 * TicketStatusChange (adminid, status, ticketid) and TicketClose (ticketid).
 *
 * Never in WHMCS's way: nothing is thrown, and nothing waits longer than TicketEvents allows.
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class TicketHooks
{
    /** WHMCS hook => the event MagizAI is told about. */
    const EVENTS = array(
        'TicketOpen' => 'ticket.opened',
        'TicketOpenAdmin' => 'ticket.opened',
        'TicketUserReply' => 'ticket.client_reply',
        'TicketAdminReply' => 'ticket.staff_reply',
        'TicketStatusChange' => 'ticket.status',
        'TicketClose' => 'ticket.closed',
    );

    /** @return bool whether MagizAI was told */
    public static function run($hook, array $vars)
    {
        try {
            if (!isset(self::EVENTS[$hook])) {
                return false;
            }
            $ticketId = isset($vars['ticketid']) && is_numeric($vars['ticketid']) ? (int) $vars['ticketid'] : 0;
            if ($ticketId < 1) {
                return false;
            }
            $fields = array('ticket_id' => $ticketId);
            if (isset($vars['replyid']) && is_numeric($vars['replyid'])) {
                $fields['reply_id'] = (int) $vars['replyid'];
            }
            if ($hook === 'TicketClose') {
                $fields['status'] = 'Closed';
            } elseif (isset($vars['status']) && is_scalar($vars['status']) && trim((string) $vars['status']) !== '') {
                $fields['status'] = substr(trim((string) $vars['status']), 0, 64);
            }

            return TicketEvents::send(self::EVENTS[$hook], $hook, $fields);
        } catch (\Throwable $e) {
            return false;
        }
    }
}
