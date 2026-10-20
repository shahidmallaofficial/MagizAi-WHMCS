<?php

namespace MagizAI\Whmcs\Action\Tickets;

use MagizAI\Whmcs\ActionError;
use MagizAI\Whmcs\Action\ActionContext;
use MagizAI\Whmcs\Action\ActionInterface;
use MagizAI\Whmcs\Action\Permissions;
use MagizAI\Whmcs\Compat;
use MagizAI\Whmcs\Domain\Ownership;
use MagizAI\Whmcs\Settings;
use MagizAI\Whmcs\Support\Args;
use MagizAI\Whmcs\Support\Departments;
use MagizAI\Whmcs\Support\WhmcsApi;

/**
 * Opens a support ticket for the signed-in user, linked to the service or domain it is about.
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class TicketOpen implements ActionInterface
{
    public function spec()
    {
        return array('identity' => true, 'write' => true, 'group' => 'write', 'label' => 'Open a support ticket');
    }

    public function handle(ActionContext $context)
    {
        $args = $context->args();
        $clientId = $context->clientId();

        $subject = Args::text($args, 'subject', 150);
        $message = Args::text($args, 'message', 5000);

        // 1.8.0: a client who only said what is wrong is not refused for want of a subject; MagizAI
        // asks for the problem, not a title. The first sentence of it, cut at a word.
        if ($subject === '' && mb_strlen($message) >= 10) {
            $subject = self::subjectFrom($message);
        }

        if ($subject === '' || mb_strlen($message) < 10) {
            throw new ActionError('invalid_args', 'A ticket needs a short subject and a description of the problem.');
        }

        $deptId = Departments::department(Args::text($args, 'department', 60));

        $params = array(
            'deptid' => $deptId,
            'clientid' => $clientId,
            'subject' => $subject,
            'message' => $message . "\n\n---\nOpened through live chat at the client's request.",
            'priority' => 'Medium',
            'markdown' => true,
        );

        /*
         * WHO OPENED IT: the signed-in user, not only the account. A team member's ticket used to be
         * filed as the account owner's, so replies went to the owner and staff could not see who had
         * asked. WHMCS 8.5 and later take `userid`. By now the sign-in has been checked to name a
         * user of this account who may open tickets. @see Runner::run, PERMISSIONS
         */
        $uid = $context->claimedUserId();
        if ($context->viaToken() && $uid > 0 && Compat::whmcsAtLeast(Compat::TICKET_USERID_SINCE)) {
            $params['userid'] = $uid;
        }

        // WHAT IT IS ABOUT, when the client named one of their own services or domains.
        $about = self::ticketRelation($clientId, $args);
        $params = $params + $about['param'];

        $r = WhmcsApi::api('OpenTicket', $params);

        return array(
            'summary' => 'Ticket #' . $r['tid'] . ' opened' . ($about['label'] !== '' ? ' about ' . $about['label'] : '')
                . ': "' . $subject . '". The team will reply by email and in the client area: '
                . Settings::systemUrl() . 'viewticket.php?tid=' . rawurlencode($r['tid']) . '&c=' . rawurlencode($r['c']),
            'audit' => 'ticket #' . $r['tid'] . ($about['param'] ? ' (' . key($about['param']) . ' ' . current($about['param']) . ')' : ''),
            'data' => array('ticket' => (string) $r['tid']) + $about['param'],
        );
    }

    /**
     * A subject from the first sentence of the message, at most 80 characters, cut at a word. The same
     * rule as MagizAI's own (ticketSubject in the connector), so the subject the client confirmed is the
     * one the ticket gets.
     */
    public static function subjectFrom($message)
    {
        $flat = trim((string) preg_replace('/\s+/u', ' ', (string) $message));
        $first = preg_match('/^(.+?[.!?])(?=\s|$)/u', $flat, $m) ? $m[1] : $flat;
        $first = trim((string) preg_replace('/[.!?]+$/u', '', $first));
        if ($first === '') {
            return 'Support request';
        }
        if (mb_strlen($first) <= 80) {
            return $first;
        }
        $cut = mb_substr($first, 0, 80);
        $at = mb_strrpos($cut, ' ');

        return trim($at !== false && $at >= 40 ? mb_substr($cut, 0, $at) : $cut) . '...';
    }

    /**
     * The service or domain a new ticket is about, when the client named one: staff then see it on
     * the ticket instead of asking which service it is.
     *
     * Only one of the client's own, found by the usual ownership checks, and only one the signed-in
     * user may see. A service or domain that is not theirs is simply not linked: the ticket still
     * opens, and nothing says whether it exists elsewhere. A domain is matched to the hosting on it
     * first ("my site is down" is about the hosting), then to the domain registration.
     *
     * @return array{param: array<string, int>, label: string}
     */
    private static function ticketRelation($clientId, array $args)
    {
        $serviceId = isset($args['service_id']) && is_scalar($args['service_id']) ? ltrim(trim((string) $args['service_id']), '#') : '';
        $domain = isset($args['domain']) && is_scalar($args['domain']) ? Args::hostname((string) $args['domain']) : null;
        $mayServices = ($serviceId !== '' || $domain !== null) && Permissions::userMay($clientId, 'products');

        try {
            if ($serviceId !== '' && ctype_digit($serviceId) && $mayServices) {
                $p = Ownership::ownedService($clientId, array('service_id' => $serviceId));

                return array('param' => array('serviceid' => (int) $p['id']), 'label' => $p['name'] . ($p['domain'] ? ' (' . $p['domain'] . ')' : ''));
            }
        } catch (ActionError $e) {
            // Not theirs, or not found: no link.
        }

        if ($domain === null) {
            return array('param' => array(), 'label' => '');
        }

        if ($mayServices) {
            try {
                $p = Ownership::ownedService($clientId, array('domain' => $domain));

                return array('param' => array('serviceid' => (int) $p['id']), 'label' => $p['name'] . ' (' . $domain . ')');
            } catch (ActionError $e) {
                // No hosting on it: perhaps the domain registration itself.
            }
        }

        if (Permissions::userMay($clientId, 'domains')) {
            try {
                $d = Ownership::ownedDomain($clientId, array('domain' => $domain));

                return array('param' => array('domainid' => (int) $d['id']), 'label' => 'the domain ' . $d['domainname']);
            } catch (ActionError $e) {
                // Not theirs, or not found: no link.
            }
        }

        return array('param' => array(), 'label' => '');
    }
}
