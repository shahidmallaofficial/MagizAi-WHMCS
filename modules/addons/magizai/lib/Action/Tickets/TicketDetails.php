<?php

namespace MagizAI\Whmcs\Action\Tickets;

use MagizAI\Whmcs\Action\ActionContext;
use MagizAI\Whmcs\Action\ActionInterface;
use MagizAI\Whmcs\Domain\Ownership;
use MagizAI\Whmcs\Settings;
use MagizAI\Whmcs\Support\Format;
use MagizAI\Whmcs\Support\WhmcsApi;

/**
 * One support ticket by its number: status, department, the latest staff reply and who spoke last.
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class TicketDetails implements ActionInterface
{
    public function spec()
    {
        return array('identity' => true, 'write' => false, 'group' => 'read', 'label' => 'Ticket details');
    }

    public function handle(ActionContext $context)
    {
        $args = $context->args();
        $clientId = $context->clientId();

        $t = Ownership::ownedTicket($clientId, $args);

        $lastStaff = null;
        $lastClient = null;
        foreach (WhmcsApi::rows($t, 'replies', 'reply') as $reply) {
            if (!empty($reply['admin'])) {
                $lastStaff = $reply;
            } else {
                $lastClient = $reply;
            }
        }

        $lines = array('#' . $t['tid'] . ' "' . $t['subject'] . '" — ' . $t['status'] . ', department ' . $t['deptname'] . ', opened ' . $t['date'] . ', last reply ' . $t['lastreply']);
        if ($lastStaff) {
            $lines[] = 'Latest staff reply (' . $lastStaff['date'] . '): ' . Format::cut(Format::plain($lastStaff['message']), 600);
        } else {
            $lines[] = 'No staff reply yet.';
        }
        if ($lastClient && (!$lastStaff || strtotime($lastClient['date']) > strtotime($lastStaff['date']))) {
            $lines[] = 'The client replied last, so it is waiting for the team.';
        }
        $lines[] = 'View: ' . Settings::systemUrl() . 'viewticket.php?tid=' . rawurlencode($t['tid']) . '&c=' . rawurlencode($t['c']);

        return array('summary' => implode("\n", $lines));
    }
}
