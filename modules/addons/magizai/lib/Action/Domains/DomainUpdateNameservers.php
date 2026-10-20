<?php

namespace MagizAI\Whmcs\Action\Domains;

use MagizAI\Whmcs\ActionError;
use MagizAI\Whmcs\Action\ActionContext;
use MagizAI\Whmcs\Action\ActionInterface;
use MagizAI\Whmcs\Domain\Nameservers;
use MagizAI\Whmcs\Domain\Ownership;
use MagizAI\Whmcs\Support\Args;
use MagizAI\Whmcs\Support\Format;
use MagizAI\Whmcs\Support\WhmcsApi;

/**
 * Changes a domain's nameservers at the registrar.
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class DomainUpdateNameservers implements ActionInterface
{
    public function spec()
    {
        return array('identity' => true, 'write' => true, 'group' => 'write', 'label' => 'Change domain nameservers');
    }

    public function handle(ActionContext $context)
    {
        $args = $context->args();
        $clientId = $context->clientId();

        $d = Ownership::ownedDomain($clientId, $args);

        if ($d['status'] !== 'Active' || !$d['registrar']) {
            throw new ActionError('not_allowed', 'Nameservers can only be changed on an active domain registered with us. ' . $d['domainname'] . ' is ' . strtolower($d['status']) . '.');
        }

        $new = array();
        foreach (array('ns1', 'ns2', 'ns3', 'ns4', 'ns5') as $key) {
            if (isset($args[$key]) && $args[$key] !== '') {
                $host = Args::hostname($args[$key]);
                if ($host === null) {
                    throw new ActionError('invalid_args', '"' . Format::cut((string) $args[$key], 60) . '" is not a valid nameserver hostname.');
                }
                if (!in_array($host, $new, true)) {
                    $new[] = $host;
                }
            }
        }

        if (count($new) < 2) {
            throw new ActionError('invalid_args', 'At least two different nameservers are needed, for example ns1.example.com and ns2.example.com.');
        }

        $old = WhmcsApi::api('DomainGetNameservers', array('domainid' => $d['id']), false);
        $oldList = ($old['result'] ?? '') === 'success' ? Nameservers::nsList($old) : array();

        $params = array('domainid' => $d['id']);
        foreach ($new as $i => $host) {
            $params['ns' . ($i + 1)] = $host;
        }

        WhmcsApi::api('DomainUpdateNameservers', $params, true, 'registrar_error');

        $summary = 'Nameservers for ' . $d['domainname'] . ' are now ' . implode(', ', $new) . '.'
            . ($oldList ? ' Previously: ' . implode(', ', $oldList) . '.' : '')
            . ' Changes can take a few hours (occasionally up to 24) to reach everyone.';

        return array(
            'summary' => $summary,
            'audit' => $d['domainname'] . ': ' . ($oldList ? implode(',', $oldList) : '?') . ' -> ' . implode(',', $new),
            'notify' => $summary,
        );
    }
}
