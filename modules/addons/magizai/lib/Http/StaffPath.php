<?php

namespace MagizAI\Whmcs\Http;

use MagizAI\Whmcs\ActionError;
use MagizAI\Whmcs\Persistence\AuditLog;
use MagizAI\Whmcs\Staff\TicketInbox;

/**
 * The bridge's staff path (1.7.0): MagizAI's ticket sync and its inbox, never the chat. A signed
 * request that says `scope: staff`, for one of TicketInbox::ACTIONS, while tickets are switched on.
 * Audited like everything else. Split out of BridgeKernel in 1.9.0.
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class StaffPath
{
    /** @var OutputGuard */
    private $guard;

    public function __construct(OutputGuard $guard)
    {
        $this->guard = $guard;
    }

    /** @return BridgeResponse */
    public function answer($secret, $nonce, $action, array $args, $conversation)
    {
        $write = in_array($action, TicketInbox::WRITES, true);
        if (!in_array($action, TicketInbox::ACTIONS, true)) {
            return BridgeResponse::signed($secret, $nonce, array('ok' => false, 'error' => 'unknown_action', 'message' => 'This WHMCS module does not support that.'), 404);
        }
        $refusal = TicketInbox::refusal();
        if ($refusal !== null) {
            AuditLog::audit($action, $write, null, $conversation, 'refused', 'not_enabled', $this->guard->detail(null));

            return BridgeResponse::signed($secret, $nonce, array('ok' => false, 'error' => 'not_enabled', 'message' => $refusal));
        }

        try {
            $data = TicketInbox::run($action, $args);
            $ticket = isset($args['ticketid']) ? ' ticket ' . (int) $args['ticketid'] : '';
            AuditLog::audit($action, $write, null, $conversation, 'ok', '', $this->guard->detail('ticket inbox' . $ticket));

            return BridgeResponse::signed($secret, $nonce, array('ok' => true, 'data' => $data));
        } catch (ActionError $e) {
            AuditLog::audit($action, $write, null, $conversation, 'refused', $e->code(), $this->guard->detail($e->detail() !== null ? $e->detail() : $e->getMessage()));

            return BridgeResponse::signed($secret, $nonce, array('ok' => false, 'error' => $e->code(), 'message' => $e->getMessage()));
        } catch (\Throwable $e) {
            AuditLog::audit($action, $write, null, $conversation, 'error', 'internal', $this->guard->detail($e->getMessage()));
            if (function_exists('logModuleCall')) {
                logModuleCall('magizai', $action, array(), $e->getMessage(), '', array());
            }

            return BridgeResponse::signed($secret, $nonce, array('ok' => false, 'error' => 'internal', 'message' => 'WHMCS could not complete this request.'), 500);
        }
    }
}
