<?php

namespace MagizAI\Whmcs\Action\Domains;

use MagizAI\Whmcs\ActionError;
use MagizAI\Whmcs\Action\ActionContext;
use MagizAI\Whmcs\Action\ActionInterface;
use MagizAI\Whmcs\Domain\Ownership;
use MagizAI\Whmcs\Support\Dates;
use MagizAI\Whmcs\Support\WhmcsApi;

/**
 * Turns a domain's auto-renew on or off.
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class DomainSetAutorenew implements ActionInterface
{
    public function spec()
    {
        return array('identity' => true, 'write' => true, 'group' => 'write', 'label' => 'Turn domain auto-renew on/off');
    }

    public function handle(ActionContext $context)
    {
        $args = $context->args();
        $clientId = $context->clientId();

        $d = Ownership::ownedDomain($clientId, $args);

        if (!array_key_exists('enabled', $args) || !is_bool($args['enabled'])) {
            throw new ActionError('invalid_args', 'Ask whether they want auto-renew turned on or off.');
        }
        $enabled = $args['enabled'];

        WhmcsApi::api('UpdateClientDomain', array('domainid' => $d['id'], 'donotrenew' => !$enabled));

        $summary = 'Auto-renew for ' . $d['domainname'] . ' is now ' . ($enabled ? 'ON' : 'OFF') . '. It expires ' . Dates::date($d['expirydate']) . '.'
            . ($enabled ? '' : ' It will not be renewed automatically, so it will expire unless renewed by hand.');

        return array('summary' => $summary, 'audit' => $d['domainname'] . ' autorenew=' . ($enabled ? 'on' : 'off'), 'notify' => $summary);
    }
}
