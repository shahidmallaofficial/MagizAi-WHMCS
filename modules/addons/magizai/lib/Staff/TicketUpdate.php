<?php

namespace MagizAI\Whmcs\Staff;

use MagizAI\Whmcs\ActionError;
use MagizAI\Whmcs\Support\WhmcsApi;
use MagizAI\Whmcs\Text;
use WHMCS\Database\Capsule;

/**
 * A ticket's priority and department, set from the MagizAI inbox (1.10.0), with WHMCS's UpdateTicket
 * (`priority` Low, Medium or High; `deptid`). A staff action like the status (TicketStatus): the
 * bridge's staff path only, while tickets are switched on, and audited.
 *
 * The department is given by its name, as the inbox shows it, matched without regard to case among
 * this WHMCS's own support departments (hidden ones included: moving a ticket is staff work), or by
 * its id. A name that matches none, or two, is refused with the list. Nothing already as asked is sent.
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class TicketUpdate
{
    /** WHMCS's own ticket priorities (UpdateTicket: "Valid priorities are: Low,Medium,High"). */
    const PRIORITIES = array('Low', 'Medium', 'High');

    /** @return array{ticketid: int, priority: string, department: string, deptid: int, changed: bool} */
    public static function set(array $args)
    {
        $ticketId = isset($args['ticketid']) ? (int) $args['ticketid'] : 0;
        $priority = isset($args['priority']) && is_string($args['priority']) ? trim($args['priority']) : '';
        $department = isset($args['department']) && is_scalar($args['department']) ? trim((string) $args['department']) : '';
        if ($ticketId < 1 || ($priority === '' && $department === '')) {
            throw new ActionError('invalid_args', 'A ticket and a priority or a department are needed.');
        }

        $wantedPriority = null;
        if ($priority !== '') {
            foreach (self::PRIORITIES as $known) {
                if (strcasecmp($known, $priority) === 0) {
                    $wantedPriority = $known;
                }
            }
            if ($wantedPriority === null) {
                throw new ActionError('invalid_args', 'A ticket priority is Low, Medium or High, not "' . Text::cut($priority, 30) . '".');
            }
        }
        $wantedDept = $department !== '' ? self::department($department) : null;

        $current = WhmcsApi::api('GetTicket', array('ticketid' => $ticketId), false);
        if (($current['result'] ?? '') !== 'success') {
            throw new ActionError('not_found', 'That ticket was not found in WHMCS. It may have been deleted or merged.');
        }

        $params = array('ticketid' => $ticketId);
        if ($wantedPriority !== null && strcasecmp((string) ($current['priority'] ?? ''), $wantedPriority) !== 0) {
            $params['priority'] = $wantedPriority;
        }
        if ($wantedDept !== null && (int) ($current['deptid'] ?? 0) !== $wantedDept['id']) {
            $params['deptid'] = $wantedDept['id'];
        }

        $result = array(
            'ticketid' => $ticketId,
            'priority' => $wantedPriority !== null ? $wantedPriority : (string) ($current['priority'] ?? ''),
            'department' => $wantedDept !== null ? $wantedDept['name'] : (string) ($current['deptname'] ?? ''),
            'deptid' => $wantedDept !== null ? $wantedDept['id'] : (int) ($current['deptid'] ?? 0),
            'changed' => count($params) > 1,
        );
        if ($result['changed']) {
            TicketInbox::write('UpdateTicket', $params, 'the ticket change');
        }

        return $result;
    }

    /**
     * One of this WHMCS's support departments, by name (any case) or id.
     *
     * @return array{id: int, name: string}
     * @throws ActionError
     */
    private static function department($asked)
    {
        $rows = array();
        foreach (Capsule::table('tblticketdepartments')->orderBy('id')->get(array('id', 'name')) as $row) {
            $rows[] = array('id' => (int) $row->id, 'name' => (string) $row->name);
        }
        $matches = array();
        foreach ($rows as $row) {
            if ((ctype_digit($asked) && (int) $asked === $row['id']) || strcasecmp(trim($row['name']), $asked) === 0) {
                $matches[] = $row;
            }
        }
        if (count($matches) === 1) {
            return $matches[0];
        }
        $names = array_map(function ($row) {
            return $row['name'];
        }, $rows);

        throw new ActionError('invalid_args', ($matches ? 'Two support departments are called "' : 'This WHMCS has no support department called "')
            . Text::cut($asked, 60) . '". It has: ' . implode(', ', $names) . '.');
    }
}
