<?php

namespace MagizAI\Whmcs\Staff;

use MagizAI\Whmcs\ActionError;
use MagizAI\Whmcs\Support\WhmcsApi;
use MagizAI\Whmcs\Text;

/**
 * A ticket's status, set from the MagizAI inbox (1.9.0): Close and Reopen there, or any status this
 * WHMCS has, its own custom ones included. The status must be one WHMCS lists (GetSupportStatuses),
 * matched without regard to case, and is sent in WHMCS's own spelling.
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class TicketStatus
{
    /** @return array{ticketid: int, status: string, changed: bool} */
    public static function set(array $args)
    {
        $ticketId = isset($args['ticketid']) ? (int) $args['ticketid'] : 0;
        $wanted = isset($args['status']) && is_string($args['status']) ? trim($args['status']) : '';
        if ($ticketId < 1 || $wanted === '') {
            throw new ActionError('invalid_args', 'A ticket and a status are needed.');
        }

        $known = self::titles();
        $status = null;
        foreach ($known as $title) {
            if (strcasecmp($title, $wanted) === 0) {
                $status = $title;
                break;
            }
        }
        if ($status === null) {
            throw new ActionError('invalid_args', 'This WHMCS has no ticket status called "' . Text::cut($wanted, 40) . '". It has: ' . implode(', ', $known) . '.');
        }

        $current = WhmcsApi::api('GetTicket', array('ticketid' => $ticketId), false);
        if (($current['result'] ?? '') !== 'success') {
            throw new ActionError('not_found', 'That ticket was not found in WHMCS. It may have been deleted or merged.');
        }
        if (strcasecmp((string) ($current['status'] ?? ''), $status) === 0) {
            return array('ticketid' => $ticketId, 'status' => $status, 'changed' => false);
        }

        TicketInbox::write('UpdateTicket', array('ticketid' => $ticketId, 'status' => $status), 'the status change');

        return array('ticketid' => $ticketId, 'status' => $status, 'changed' => true);
    }

    /** @return string[] the ticket statuses this WHMCS has, its own custom ones included */
    public static function titles()
    {
        $r = WhmcsApi::api('GetSupportStatuses', array(), false);
        $rows = isset($r['statuses']['status']) && is_array($r['statuses']['status']) ? $r['statuses']['status'] : array();
        if (isset($rows['title'])) {
            $rows = array($rows);
        }
        $out = array();
        foreach ($rows as $row) {
            $title = is_array($row) ? trim((string) ($row['title'] ?? '')) : '';
            if ($title !== '' && !in_array($title, $out, true)) {
                $out[] = $title;
            }
        }

        // An install whose list cannot be read still knows WHMCS's own statuses.
        return $out !== array() ? $out : array('Open', 'Answered', 'Customer-Reply', 'Closed', 'On Hold', 'In Progress');
    }
}
