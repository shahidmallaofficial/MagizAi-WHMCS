<?php

namespace MagizAI\Whmcs\Action\Domains;

use MagizAI\Whmcs\ActionError;
use MagizAI\Whmcs\Action\ActionContext;
use MagizAI\Whmcs\Action\ActionInterface;
use MagizAI\Whmcs\Domain\Clients;
use MagizAI\Whmcs\Domain\Ownership;
use MagizAI\Whmcs\Mailer;
use MagizAI\Whmcs\Support\WhmcsApi;
use MagizAI\Whmcs\Text;

/**
 * Has a domain's transfer (EPP) code sent by email to the address on the account, or by the
 * registrar to the registrant. Never into the chat.
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class DomainSendEpp implements ActionInterface
{
    public function spec()
    {
        return array('identity' => true, 'write' => true, 'group' => 'sensitive', 'label' => 'Email the domain transfer (EPP) code to the client');
    }

    public function handle(ActionContext $context)
    {
        $args = $context->args();
        $clientId = $context->clientId();

        $d = Ownership::ownedDomain($clientId, $args);

        if ($d['status'] !== 'Active' || !$d['registrar']) {
            throw new ActionError('not_allowed', 'A transfer code is only available for an active domain registered with us.');
        }

        $r = WhmcsApi::api('DomainRequestEPP', array('domainid' => $d['id']), true, 'registrar_error');

        if (!empty($r['eppcode'])) {
            // Sent to the email on record, never returned to the chat — and never written into
            // WHMCS's module log either, if the send fails.
            $code = html_entity_decode((string) $r['eppcode'], ENT_QUOTES, 'UTF-8');
            $sent = Mailer::send('domain', (int) $d['id'], 'Transfer code for ' . $d['domainname'],
                '<p>You asked for the transfer (EPP) code of <strong>' . Text::mail($d['domainname']) . '</strong> in live chat.</p>'
                . '<p>Code: <code>' . Text::mail($code) . '</code></p>'
                . '<p>If you did not ask for this, contact us right away.</p>',
                array($code, (string) $r['eppcode']));

            if (!$sent) {
                throw new ActionError('not_allowed', 'The transfer code for ' . $d['domainname'] . ' could not be emailed just now, so it was not sent. '
                    . 'It is never shown in chat. Offer to pass this to the team.', 'transfer code email failed for ' . $d['domainname']);
            }

            // The masked address when it can be read, so the client knows which inbox to open.
            // Without it the sentence names the account address once, not twice.
            $masked = Clients::maskedAccountEmail($clientId);

            return array(
                'summary' => 'The transfer code for ' . $d['domainname'] . ' has been emailed to '
                    . ($masked !== '' ? $masked . ', the address on the account' : 'the address on the account')
                    . '. It is never shown in chat.',
                'audit' => $d['domainname'] . ' (emailed by WHMCS)',
                'data' => array('sent_by' => 'account_email'),
            );
        }

        /*
         * NO CODE CAME BACK, AND NO ERROR: the registrar sends it itself, straight to the domain's
         * registrant contact (WHMCS's own documentation says as much). That address can differ from
         * the one on the account, an old agency mailbox for example, and the client used to be sent
         * to look in the wrong inbox.
         */
        return array(
            'summary' => 'The registrar is sending the transfer code for ' . $d['domainname'] . ' straight to the domain\'s registrant contact: '
                . 'the owner email in the domain\'s registration (WHOIS) details, which can differ from the address on the account. '
                . 'If it does not arrive there, offer to pass this to the team. It is never shown in chat.',
            'audit' => $d['domainname'] . ' (sent by the registrar)',
            'data' => array('sent_by' => 'registrar'),
        );
    }
}
