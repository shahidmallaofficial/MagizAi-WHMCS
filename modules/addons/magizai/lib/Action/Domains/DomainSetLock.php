<?php

namespace MagizAI\Whmcs\Action\Domains;

use MagizAI\Whmcs\ActionError;
use MagizAI\Whmcs\Action\ActionContext;
use MagizAI\Whmcs\Action\ActionInterface;
use MagizAI\Whmcs\Domain\Ownership;
use MagizAI\Whmcs\Support\WhmcsApi;

/**
 * Turns a domain's registrar lock on or off (1.10.0), with WHMCS's DomainUpdateLockingStatus.
 *
 * The lock as the registrar reports it (DomainGetLockingStatus) is read before and after. A lock that
 * is already as asked is not sent again, and the answer says what the registrar reports afterwards,
 * not what was hoped for: a registrar can accept the request and still report the old state for a few
 * minutes.
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class DomainSetLock implements ActionInterface
{
    public function spec()
    {
        return array('identity' => true, 'write' => true, 'group' => 'write', 'label' => 'Turn the domain registrar lock on/off');
    }

    public function handle(ActionContext $context)
    {
        $args = $context->args();
        $clientId = $context->clientId();

        $d = Ownership::ownedDomain($clientId, $args);

        if ($d['status'] !== 'Active' || !$d['registrar']) {
            throw new ActionError('not_allowed', 'The registrar lock can only be changed on an active domain registered with us. ' . $d['domainname'] . ' is ' . strtolower($d['status']) . '.');
        }

        if (!array_key_exists('locked', $args) || !is_bool($args['locked'])) {
            throw new ActionError('invalid_args', 'Ask whether they want the registrar lock turned on or off.');
        }
        $locked = $args['locked'];
        $word = $locked ? 'ON' : 'OFF';

        $before = self::lockState($d['id']);
        if ($before === ($locked ? 'locked' : 'unlocked')) {
            return array(
                'summary' => 'The registrar lock for ' . $d['domainname'] . ' is already ' . $word . '. Nothing was changed.',
                'audit' => $d['domainname'] . ' lock already ' . $before,
                'changed' => false,
            );
        }

        WhmcsApi::api('DomainUpdateLockingStatus', array('domainid' => (int) $d['id'], 'lockstatus' => $locked), true, 'registrar_error');

        $after = self::lockState($d['id']);
        if ($after === null || $after === ($locked ? 'locked' : 'unlocked')) {
            $summary = 'The registrar lock for ' . $d['domainname'] . ' is now ' . $word . '.';
        } else {
            $summary = 'The registrar accepted the request to turn the lock ' . $word . ' for ' . $d['domainname']
                . ', but still reports it as ' . $after . '. It can take a few minutes to show: ask them to check again shortly.';
        }
        $summary .= $locked
            ? ' The domain cannot be transferred to another registrar while it is locked.'
            : ' While it is unlocked, the domain can be transferred to another registrar with its transfer code.';

        return array(
            'summary' => $summary,
            'audit' => $d['domainname'] . ' lock: ' . ($before !== null ? $before : '?') . ' -> ' . ($locked ? 'locked' : 'unlocked')
                . ($after !== null ? ' (registrar now reports ' . $after . ')' : ''),
            'notify' => $summary,
            'data' => array('domain_id' => (int) $d['id'], 'locked' => $locked, 'registrar_reports' => $after),
        );
    }

    /**
     * What the registrar reports: 'locked', 'unlocked', or null when it cannot say (an extension with
     * no lock, a registrar that did not answer). Never a guess.
     */
    private static function lockState($domainId)
    {
        $r = WhmcsApi::api('DomainGetLockingStatus', array('domainid' => (int) $domainId), false);
        if (($r['result'] ?? '') !== 'success' || !isset($r['lockstatus']) || !is_scalar($r['lockstatus'])) {
            return null;
        }
        $state = strtolower(trim((string) $r['lockstatus']));

        return $state === 'locked' || $state === 'unlocked' ? $state : null;
    }
}
