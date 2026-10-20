<?php

namespace MagizAI\Whmcs\Action\Services;

use MagizAI\Whmcs\ActionError;
use MagizAI\Whmcs\Action\ActionContext;
use MagizAI\Whmcs\Action\ActionInterface;
use MagizAI\Whmcs\Domain\Ownership;
use MagizAI\Whmcs\Support\Args;
use MagizAI\Whmcs\Support\Dates;
use MagizAI\Whmcs\Support\WhmcsApi;

/**
 * Submits a cancellation request for a service, at once or at the end of its billing period.
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class ServiceCancelRequest implements ActionInterface
{
    public function spec()
    {
        return array('identity' => true, 'write' => true, 'group' => 'sensitive', 'label' => 'Submit a cancellation request');
    }

    public function handle(ActionContext $context)
    {
        $args = $context->args();
        $clientId = $context->clientId();

        /*
         * NO PURPOSE: a cancellation is the one change never pointed at a service by elimination.
         * With a paid line and a free trial, "cancel my trial" used to cancel the paid line, possibly
         * at once. Unless the client named it, several live services means asking which one, the
         * free trial included. @see Args::purposeArg
         */
        $p = Ownership::ownedService($clientId, $args);

        if (!in_array($p['status'], array('Active', 'Suspended'), true)) {
            throw new ActionError('not_allowed', 'Only an active or suspended service can be cancelled. This one is ' . strtolower($p['status']) . '.');
        }

        $immediate = isset($args['when']) && $args['when'] === 'immediate';
        $type = $immediate ? 'Immediate' : 'End of Billing Period';

        // The reason goes in through the API's own `reason` field, which staff read on the request.
        // (It used to be written into tblcancelrequests directly afterwards, on the belief that the
        // API had no such field. It has had one since 1.0.)
        $reason = Args::text($args, 'reason', 1000);

        $r = WhmcsApi::api('AddCancelRequest', array(
            'serviceid' => (int) $p['id'],
            'type' => $type,
            'reason' => ($reason !== '' ? $reason : 'No reason given') . ' (requested through live chat)',
        ), false);
        if (($r['result'] ?? '') !== 'success') {
            $msg = (string) ($r['message'] ?? '');
            throw new ActionError('not_allowed', stripos($msg, 'exists') !== false
                ? 'A cancellation request for this service already exists.'
                : 'The cancellation request could not be submitted. The client can do it from the service page in the client area.',
                $msg !== '' ? 'AddCancelRequest: ' . $msg : null);
        }

        $summary = 'Cancellation requested for ' . $p['name'] . ($p['domain'] ? ' (' . $p['domain'] . ')' : '') . ' — '
            . ($immediate ? 'immediately' : 'at the end of the billing period (' . Dates::date($p['nextduedate']) . ')') . '.';

        return array('summary' => $summary, 'audit' => 'service #' . $p['id'] . ' ' . $type, 'notify' => $summary);
    }
}
