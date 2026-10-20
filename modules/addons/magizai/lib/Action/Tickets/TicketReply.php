<?php

namespace MagizAI\Whmcs\Action\Tickets;

use MagizAI\Whmcs\ActionError;
use MagizAI\Whmcs\Action\ActionContext;
use MagizAI\Whmcs\Action\ActionInterface;
use MagizAI\Whmcs\Domain\Ownership;
use MagizAI\Whmcs\Support\Args;
use MagizAI\Whmcs\Support\WhmcsApi;

/**
 * Adds the client's reply to one of their own tickets.
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class TicketReply implements ActionInterface
{
    public function spec()
    {
        return array('identity' => true, 'write' => true, 'group' => 'write', 'label' => 'Reply to a support ticket');
    }

    public function handle(ActionContext $context)
    {
        $args = $context->args();
        $clientId = $context->clientId();

        $t = Ownership::ownedTicket($clientId, $args);
        $message = Args::text($args, 'message', 5000);

        if (mb_strlen($message) < 2) {
            throw new ActionError('invalid_args', 'Ask what they want to add to the ticket.');
        }

        WhmcsApi::api('AddTicketReply', array(
            'ticketid' => (int) $t['ticketid'],
            'clientid' => $clientId,
            'message' => $message . "\n\n---\nAdded through live chat.",
            'markdown' => true,
        ));

        return array('summary' => 'The reply was added to ticket #' . $t['tid'] . '.', 'audit' => 'ticket #' . $t['tid']);
    }
}
