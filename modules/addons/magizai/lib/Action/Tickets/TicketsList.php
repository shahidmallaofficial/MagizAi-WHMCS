<?php

namespace MagizAI\Whmcs\Action\Tickets;

use MagizAI\Whmcs\Action\ActionContext;
use MagizAI\Whmcs\Action\ActionInterface;
use MagizAI\Whmcs\Settings;
use MagizAI\Whmcs\Summary;
use MagizAI\Whmcs\Support\Format;
use MagizAI\Whmcs\Support\WhmcsApi;

/**
 * The client's support tickets, open ones first, under a line with the counts.
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class TicketsList implements ActionInterface
{
    public function spec()
    {
        return array('identity' => true, 'write' => false, 'group' => 'read', 'label' => 'List tickets');
    }

    public function handle(ActionContext $context)
    {
        $clientId = $context->clientId();

        // Every department's tickets, not only those of the admin the API runs as.
        $r = WhmcsApi::api('GetTickets', array('clientid' => $clientId, 'limitnum' => 10, 'ignore_dept_assignments' => true));
        $rows = WhmcsApi::rows($r, 'tickets', 'ticket');

        if (!$rows) {
            return array('summary' => 'This client has no support tickets.', 'data' => array('total' => 0));
        }

        /*
         * OPEN ONES FIRST, under a line with the counts. One request, as before: the totals are
         * WHMCS's own for all of the client's tickets, the open and closed counts are for the ones
         * read, and the line says which is which when they differ.
         */
        $open = array();
        $closed = array();
        $waiting = 0;
        foreach ($rows as $t) {
            $status = (string) (isset($t['status']) ? $t['status'] : '');
            $line = '#' . $t['tid'] . ' "' . Format::cut((string) $t['subject'], 100) . '" — ' . $status . ', last reply ' . $t['lastreply']
                . ', ' . Settings::systemUrl() . 'viewticket.php?tid=' . rawurlencode($t['tid']) . '&c=' . rawurlencode($t['c']);

            if (strcasecmp($status, 'Closed') === 0) {
                $closed[] = $line;
            } else {
                $open[] = $line;
                // WHMCS's own words for "the client spoke last".
                if (strcasecmp($status, 'Open') === 0 || strcasecmp($status, 'Customer-Reply') === 0) {
                    $waiting++;
                }
            }
        }

        $total = max(count($rows), (int) (isset($r['totalresults']) ? $r['totalresults'] : 0));
        $split = count($open) . ' open' . ($waiting > 0 ? ' (' . $waiting . ' waiting for a reply from the team)' : '') . ' and ' . count($closed) . ' closed';
        $head = $total > count($rows)
            ? Summary::count($total, 'support ticket') . '. Of the ' . count($rows) . ' most recent, ' . $split . '.'
            : Summary::count($total, 'support ticket') . ': ' . $split . '.';

        return array(
            'summary' => Summary::fit(array($head), array(array('items' => array_merge($open, $closed), 'more' => $total - count($rows)))),
            'data' => array('total' => $total, 'open' => count($open), 'waiting_for_team' => $waiting),
        );
    }
}
