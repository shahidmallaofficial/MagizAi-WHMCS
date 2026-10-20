<?php

namespace MagizAI\Whmcs\Action\Domains;

use MagizAI\Whmcs\ActionError;
use MagizAI\Whmcs\Action\ActionContext;
use MagizAI\Whmcs\Action\ActionInterface;
use MagizAI\Whmcs\Domain\Ownership;
use MagizAI\Whmcs\Domain\WhoisContacts;
use MagizAI\Whmcs\Support\WhmcsApi;
use MagizAI\Whmcs\Text;

/**
 * Changes a domain's contact (WHOIS) details at the registrar (1.10.0): the email, phone, name,
 * organisation or address of the registrant, admin, tech or billing contact.
 *
 * Sensitive, so off until an admin ticks it: the registrant contact is who owns the domain. Only the
 * details the client named change, written into the fields the registrar itself returned, and every
 * other field goes back exactly as read (WhoisContacts). MagizAI shows the new values in its Confirm
 * step and checks they are the client's own words; this side checks each one again.
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class DomainUpdateContacts implements ActionInterface
{
    public function spec()
    {
        return array('identity' => true, 'write' => true, 'group' => 'sensitive', 'label' => 'Change a domain\'s contact (WHOIS) details');
    }

    public function handle(ActionContext $context)
    {
        $args = $context->args();
        $clientId = $context->clientId();

        $d = Ownership::ownedDomain($clientId, $args);

        if ($d['status'] !== 'Active' || !$d['registrar']) {
            throw new ActionError('not_allowed', 'Contact details can only be changed on an active domain registered with us. ' . $d['domainname'] . ' is ' . strtolower($d['status']) . '.');
        }

        $changes = WhoisContacts::requested($args);
        $kinds = WhoisContacts::kinds($args);

        $current = WhmcsApi::api('DomainGetWhoisInfo', array('domainid' => (int) $d['id']), false);
        $contacts = ($current['result'] ?? '') === 'success' ? WhoisContacts::parse($current) : array();
        if (!$contacts) {
            throw new ActionError('registrar_error', 'The registrar did not return the current contact details of ' . $d['domainname'] . ', so nothing was changed. Offer to pass this to the team.',
                'DomainGetWhoisInfo: ' . Text::cut(strip_tags((string) ($current['message'] ?? 'no contacts in the answer')), 300));
        }

        $next = WhoisContacts::apply($contacts, $kinds, $changes, $d['domainname']);

        WhmcsApi::api('DomainUpdateWhoisInfo', array('domainid' => (int) $d['id'], 'xml' => WhoisContacts::xml($next['contacts'])), true, 'registrar_error');

        $kinds = array_map('strtolower', array_keys($next['changed']));
        $last = array_pop($kinds);
        $which = $kinds ? implode(', ', $kinds) . ' and ' . $last : $last;
        $summary = 'The registrar accepted the new ' . $which . ' contact details for ' . $d['domainname'] . ': ' . WhoisContacts::describe($changes) . '.'
            . ' If the registrar emails the domain owner to approve the change, it waits for that approval.';

        return array(
            'summary' => $summary,
            // Which details changed, never their values: the activity log is read by every admin.
            'audit' => $d['domainname'] . ' ' . $which . ' contact: ' . implode(', ', array_keys($changes)) . ' changed',
            'notify' => $summary,
            'data' => array('domain_id' => (int) $d['id'], 'contacts' => array_keys($next['changed']), 'fields' => array_keys($changes)),
        );
    }
}
