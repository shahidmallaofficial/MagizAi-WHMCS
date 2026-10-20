<?php

namespace MagizAI\Whmcs\Action\Account;

use MagizAI\Whmcs\Action\ActionContext;
use MagizAI\Whmcs\Action\ActionInterface;
use MagizAI\Whmcs\Install\EmailTemplate;
use MagizAI\Whmcs\Settings;
use MagizAI\Whmcs\Summary;
use MagizAI\Whmcs\Support\Args;
use MagizAI\Whmcs\Support\Dates;
use MagizAI\Whmcs\Support\Format;
use MagizAI\Whmcs\Support\WhmcsApi;

/**
 * "Did you send me my welcome email?", "I never got the invoice."
 *
 * The emails WHMCS sent this account: the date and subject of each, and whether it failed or is
 * still waiting to go. NEVER THE BODY: a welcome email carries passwords, and an email body is
 * not a thing for a chat transcript. Never the addresses or the reason a send failed either.
 *
 * NEVER A VERIFICATION CODE (1.7.0). The code the chat asks a client to type goes in the body of the
 * "MagizAI verification code" email. A merchant who also puts it in the subject would have it read
 * back into the chat from here, so whenever that template's subject names the code, every 6-digit
 * number in a subject is masked, and taken out of a search, so the search cannot be used to guess
 * one either. @see EmailTemplate::subjectCarriesCode
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class EmailHistory implements ActionInterface
{
    /** A number that could be a verification code: six digits on their own. */
    const CODE_SHAPED = '/(?<!\d)\d{6}(?!\d)/';

    public function spec()
    {
        return array('identity' => true, 'write' => false, 'group' => 'read', 'label' => 'Emails sent to the client (date and subject only)');
    }

    public function handle(ActionContext $context)
    {
        $args = $context->args();
        $clientId = $context->clientId();

        $subject = Args::text($args, 'subject', 100);
        $masking = EmailTemplate::subjectCarriesCode();
        if ($masking) {
            $subject = trim((string) preg_replace(self::CODE_SHAPED, '', $subject));
        }

        $params = array('clientid' => (int) $clientId, 'limitnum' => 10);
        if ($subject !== '') {
            $params['subject'] = $subject;
        }
        $r = WhmcsApi::api('GetEmails', $params);

        $emails = array();
        $filtered = false;
        foreach (WhmcsApi::rows($r, 'emails', 'email') as $e) {
            // Another client's email is never this client's business, whatever WHMCS answered.
            if (isset($e['userid']) && (string) $e['userid'] !== '' && (int) $e['userid'] !== (int) $clientId) {
                $filtered = true;
                continue;
            }
            $title = Format::plain((string) ($e['subject'] ?? ''));
            // Masked before it is cut, so a cut can never leave five digits of a code behind.
            $title = Format::cut($masking ? (string) preg_replace(self::CODE_SHAPED, '******', $title) : $title, 120);
            if ($subject !== '' && mb_stripos($title, $subject) === false) {
                $filtered = true;
                continue;
            }
            $emails[] = array('at' => Dates::timestamp($e['date'] ?? ''), 'subject' => $title,
                'state' => !empty($e['failed']) ? 'failed' : (!empty($e['pending']) ? 'queued' : 'sent'));
        }
        usort($emails, function ($a, $b) {
            return $b['at'] - $a['at'];
        });

        $total = $filtered ? count($emails) : max(count($emails), (int) (isset($r['totalresults']) ? $r['totalresults'] : 0));
        $about = $subject !== '' ? ' with "' . $subject . '" in the subject' : '';

        if (!$emails) {
            return array(
                'summary' => 'No email' . $about . ' was found for this account. If one is expected, it may not have been sent yet, '
                    . 'or it may have gone to a different address. Offer to pass this to the team.',
                'data' => array('total' => 0, 'emails' => array()),
            );
        }

        $items = array();
        $data = array();
        foreach ($emails as $e) {
            $items[] = '- ' . ($e['at'] > 0 ? date('j M Y, H:i', $e['at']) : 'date unknown') . ': ' . $e['subject']
                . ($e['state'] === 'failed' ? ' (NOT delivered: sending it failed)' : ($e['state'] === 'queued' ? ' (waiting to be sent)' : ''));
            $data[] = array('date' => $e['at'] > 0 ? date('Y-m-d H:i', $e['at']) : '', 'subject' => $e['subject'], 'state' => $e['state']);
        }

        $before = array(Summary::count($total, 'email') . $about . ' sent to this account'
            . ($total > count($emails) ? ', the ' . count($emails) . ' newest listed' : '')
            . '. The date and subject only; the emails themselves are in the client area: ' . Settings::systemUrl() . 'clientarea.php?action=emails');
        $after = array('If one is not in their inbox, it may be in the spam folder.');

        return array(
            'summary' => Summary::fit($before, array(array('items' => $items, 'more' => $total - count($emails))), $after),
            'data' => array('total' => $total, 'emails' => $data),
        );
    }
}
