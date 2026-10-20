<?php

namespace MagizAI\Whmcs\Action\Tickets;

use MagizAI\Whmcs\ActionError;
use MagizAI\Whmcs\Action\ActionContext;
use MagizAI\Whmcs\Action\ActionInterface;
use MagizAI\Whmcs\Domain\Ownership;
use MagizAI\Whmcs\Schema;
use MagizAI\Whmcs\Support\WhmcsApi;
use WHMCS\Database\Capsule;

/**
 * Closes one of the signed-in client's own support tickets, when they say it is sorted (1.8.0).
 *
 * Only a ticket on their account, found by the number they see (Ownership::ownedTicket), and never
 * one the team has marked "prevent client closure" (WHMCS 8.11 and later): the client could not
 * close that one in the client area either. A closed ticket opens again as soon as anyone replies to
 * it, so the summary says so.
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class TicketClose implements ActionInterface
{
    public function spec()
    {
        return array('identity' => true, 'write' => true, 'group' => 'write', 'label' => 'Close a support ticket');
    }

    public function handle(ActionContext $context)
    {
        $t = Ownership::ownedTicket($context->clientId(), $context->args());
        $tid = (string) $t['tid'];

        if (strcasecmp((string) ($t['status'] ?? ''), 'Closed') === 0) {
            return array(
                'summary' => 'Ticket #' . $tid . ' is already closed. A reply to it opens it again.',
                'changed' => false,
                'data' => array('ticket' => $tid, 'status' => 'Closed'),
            );
        }

        if (Schema::hasColumn('tbltickets', 'prevent_client_closure')
            && (int) Capsule::table('tbltickets')->where('id', (int) $t['ticketid'])->value('prevent_client_closure') === 1) {
            throw new ActionError('not_allowed', 'Ticket #' . $tid . ' can only be closed by the team. Nothing was changed. '
                . 'If it is sorted, they can say so in a reply and the team will close it.');
        }

        WhmcsApi::api('UpdateTicket', array('ticketid' => (int) $t['ticketid'], 'status' => 'Closed'));

        return array(
            'summary' => 'Ticket #' . $tid . ' is closed. If they need more help with it, a reply opens it again.',
            'audit' => 'ticket #' . $tid . ' closed',
            'data' => array('ticket' => $tid, 'status' => 'Closed'),
        );
    }
}
