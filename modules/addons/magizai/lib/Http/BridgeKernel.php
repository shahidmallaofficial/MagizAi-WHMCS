<?php

namespace MagizAI\Whmcs\Http;

use MagizAI\Whmcs\Action\Registry;
use MagizAI\Whmcs\Action\Runner;
use MagizAI\Whmcs\ActionError;
use MagizAI\Whmcs\Crypto;
use MagizAI\Whmcs\Domain\Clients;
use MagizAI\Whmcs\Identity\Signing;
use MagizAI\Whmcs\Identity\Verifier;
use MagizAI\Whmcs\Persistence\AuditLog;
use MagizAI\Whmcs\Persistence\NonceStore;
use MagizAI\Whmcs\Rollout;
use MagizAI\Whmcs\Settings;
use MagizAI\Whmcs\Text;
use WHMCS\Database\Capsule;

/**
 * The single endpoint MagizAI calls, from the request to the answer. bridge.php finds WHMCS, runs
 * this, sends what it returns and ends the request; nothing here sends or exits.
 *
 * Every request must be:
 *   - a POST, signed with the bridge secret (X-MagizAI-Signature over timestamp, nonce and body),
 *   - no older than five minutes, and never seen before (nonce),
 *   - for an action the WHMCS admin has switched on,
 *   - and, for anything about a client's account, carry that client's identity token, which is
 *     verified HERE: a request naming a client without a valid, current token for that client is
 *     refused, with a code that says why (Identity\Verifier). An HS256 token is signed with a secret
 *     MagizAI also holds, so on its own it protects against forged and expired sign-ins (the request
 *     signature and nonce above are what stop replayed requests), not against MagizAI itself. Once
 *     this install signs with its own Ed25519 key (Identity\Signing), MagizAI can check a token but
 *     cannot make one. It can still USE a real token it was given, on any number of requests, until
 *     the token expires or its user signs out: a token is not used up by a request.
 *   - A staff member in staff view may look things up, and never change anything.
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class BridgeKernel
{
    const MAX_BODY = 65536;

    const CLOCK_SKEW = 300;

    /** @var OutputGuard */
    private $guard;

    /** @var string the version bridge.php says the module is */
    private $version;

    public function __construct(OutputGuard $guard, $version)
    {
        $this->guard = $guard;
        $this->version = (string) $version;
    }

    /** @return BridgeResponse */
    public function handle(BridgeRequest $request)
    {
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');

        if ($request->method() !== 'POST') {
            return BridgeResponse::unsigned(405, 'method_not_allowed');
        }

        $addon = Capsule::table('tbladdonmodules')->where('module', 'magizai')->where('setting', 'version')->exists();
        if (!$addon) {
            return BridgeResponse::unsigned(404, 'module_not_active');
        }

        $secret = Settings::get('bridge_secret');
        if ($secret === '') {
            return BridgeResponse::unsigned(503, 'not_configured');
        }

        $body = $request->body(self::MAX_BODY);
        if ($body === false || strlen($body) > self::MAX_BODY) {
            return BridgeResponse::unsigned(413, 'too_large');
        }

        $ts = $request->timestamp();
        $nonce = $request->nonce();

        if (!ctype_digit($ts) || !preg_match('/^[a-f0-9]{32,64}$/', $nonce)) {
            return BridgeResponse::unsigned(401, 'bad_headers');
        }

        // Signature before anything else is looked at — including the clock, so an unsigned caller learns nothing.
        if (!Crypto::verifySignature($secret, $ts, $nonce, $body, $request->signature())) {
            return BridgeResponse::unsigned(401, 'bad_signature');
        }

        if (abs(time() - (int) $ts) > self::CLOCK_SKEW) {
            return BridgeResponse::signed($secret, $nonce, array('ok' => false, 'error' => 'clock_skew', 'message' => 'The request timestamp is too far from this server\'s clock. Check the server time (NTP).'), 401);
        }

        // A replay is a duplicate nonce, and only that. A database that cannot record the nonce at all is
        // a fault on this side, reported as one, so MagizAI flags the connection instead of shrugging.
        try {
            $fresh = NonceStore::claim($nonce);
        } catch (\Throwable $e) {
            if (function_exists('logModuleCall')) {
                logModuleCall('magizai', 'nonce_store', array(), $e->getMessage(), '', array());
            }

            return BridgeResponse::signed($secret, $nonce, array('ok' => false, 'error' => 'store_unavailable', 'message' => 'WHMCS could not record this request, so it was not processed. Try again shortly.'), 503);
        }
        if (!$fresh) {
            return BridgeResponse::signed($secret, $nonce, array('ok' => false, 'error' => 'replay', 'message' => 'This request was already processed.'), 409);
        }

        $payload = json_decode($body, true);
        if (!is_array($payload) || (int) ($payload['v'] ?? 0) !== 1 || !is_string($payload['action'] ?? null)) {
            return BridgeResponse::signed($secret, $nonce, array('ok' => false, 'error' => 'bad_request', 'message' => 'Unreadable request.'), 400);
        }

        return $this->answer($secret, $nonce, $payload);
    }

    /** A signed, readable request: what it asks for, if it may have it. */
    private function answer($secret, $nonce, array $request)
    {
        $action = $request['action'];
        $args = is_array($request['args'] ?? null) ? $request['args'] : array();
        $conversation = is_string($request['conversation'] ?? null) ? substr($request['conversation'], 0, 64) : '';

        /*
         * WHAT MAGIZAI CAN CHECK. A MagizAI that can verify Ed25519 identity tokens says so in the signed
         * body of every request. Recorded here, so tokens move to this install's own key only once MagizAI
         * can check them. Never the reason a request fails. @see Signing
         *
         * Whether THIS request said so is kept for the ping below: only a caller that can check Ed25519
         * keeps the key a ping hands out, so only its ping counts as delivering it.
         */
        $capable = false;
        try {
            $capable = Signing::noteCapability($request);
        } catch (\Throwable $e) {
            // Recorded on a later request instead.
        }

        /*
         * New read actions reach this install even when nobody has opened the admin page since the new files
         * were uploaded, which is the only thing that runs the upgrade step. Only for a signed request, and
         * a no-op once every action has been offered. Never the reason a request fails. @see Rollout
         */
        try {
            Rollout::whenNeeded();
        } catch (\Throwable $e) {
            // Tried again on the next request.
        }

        if ($action === 'ping') {
            /*
             * AUDITED, like everything else. The admin page's "Connected to MagizAI" badge asks this table
             * whether MagizAI has made any request recently; a ping that recorded nothing read as
             * "Not connected yet" on a perfectly connected install. A signed ping IS contact.
             */
            AuditLog::audit('ping', false, null, $conversation, 'ok', '', $this->guard->detail(null));

            /*
             * What the module says about itself, and this install's Ed25519 public key. When output held in
             * a buffer other code opened cannot be thrown away (detail() just measured it), it goes out
             * ahead of this reply and MagizAI cannot verify the reply, so it keeps nothing from it: the key
             * in it is not counted as delivered. Nor is it when this ping did not say it can check Ed25519
             * (an older MagizAI worker during a deploy, or any other caller holding the bridge secret):
             * that caller has no reason to keep the key. It still goes out in the reply.
             * @see Ping, Signing::publish
             */
            try {
                $data = Ping::data($this->version, $this->guard->held() === 0 && $capable);
            } catch (\Throwable $e) {
                if (function_exists('logModuleCall')) {
                    logModuleCall('magizai', 'ping', array(), $e->getMessage(), '', array());
                }
                // A ping always answers: the connection works even when a report about it cannot be read.
                $data = array('module_version' => $this->version);
            }

            return BridgeResponse::signed($secret, $nonce, array('ok' => true, 'summary' => 'pong', 'data' => $data));
        }

        /*
         * TICKETS IN THE MAGIZAI INBOX (1.7.0). Staff powers, on their own path: never in the action
         * registry, so never in the catalogue the chat AI is built from, and only for a request that says
         * so. Refused unless tickets are switched on. @see StaffPath, TicketInbox
         */
        if (($request['scope'] ?? '') === 'staff') {
            return (new StaffPath($this->guard))->answer($secret, $nonce, $action, $args, $conversation);
        }

        $spec = Registry::spec($action);
        if ($spec === null) {
            return BridgeResponse::signed($secret, $nonce, array('ok' => false, 'error' => 'unknown_action', 'message' => 'This WHMCS module does not support that.'), 404);
        }

        if (!Settings::isEnabled($action)) {
            // An action a Setup tab setting switches (1.7.0: emailed verification codes) is "not_enabled",
            // which MagizAI's confirmation step reads as "this install does not do it".
            if (isset(Registry::SETTING_SWITCHES[$action])) {
                AuditLog::audit($action, $spec['write'], null, $conversation, 'refused', 'not_enabled', $this->guard->detail(null));

                return BridgeResponse::signed($secret, $nonce, array('ok' => false, 'error' => 'not_enabled', 'message' => 'This billing system does not email chat verification codes.'));
            }

            AuditLog::audit($action, $spec['write'], null, $conversation, 'refused', 'action_disabled', $this->guard->detail(null));

            return BridgeResponse::signed($secret, $nonce, array('ok' => false, 'error' => 'action_disabled', 'message' => 'This is not available through chat. The client can do it in the client area or open a ticket.'));
        }

        $who = $this->identity($secret, $nonce, $request, $action, $spec, $conversation);
        if ($who instanceof BridgeResponse) {
            return $who;
        }

        return $this->run($secret, $nonce, $action, $args, $spec, $conversation, $who);
    }

    /**
     * Who the request is for: a BridgeResponse refusing it, or the verified client, user, claims and
     * staff member (all empty for a public action).
     *
     * @return BridgeResponse|array{client: int|null, user: int|null, claims: array, actor: string|null}
     */
    private function identity($secret, $nonce, array $request, $action, array $spec, $conversation)
    {
        $clientId = null;
        $userId = null;
        $claims = array();
        $actor = null;

        // 'optional' identity: verified when a token is sent, anonymous when none is. A token that IS sent
        // but does not verify is still refused below, never silently downgraded to anonymous.
        $identityGiven = is_array($request['identity'] ?? null) && !empty($request['identity']['token']);
        $needsIdentity = $spec['identity'] === true || ($spec['identity'] === 'optional' && $identityGiven);

        if (!$needsIdentity) {
            return array('client' => $clientId, 'user' => $userId, 'claims' => $claims, 'actor' => $actor);
        }

        $identity = is_array($request['identity'] ?? null) ? $request['identity'] : array();

        /*
         * VERIFIED HERE, with this install's own keys, and each way a sign-in can fail has its own code:
         * identity_missing, identity_signature, identity_expired, identity_mismatch, identity_revoked.
         * All five used to be "identity_expired: ask them to refresh", which never helps when the two
         * identity secrets differ. A database that cannot say whether a sign-in was revoked is a fault
         * on this side, reported as one. @see Verifier
         */
        try {
            $check = Verifier::check(isset($identity['token']) ? $identity['token'] : null, isset($identity['client_id']) ? $identity['client_id'] : '');
        } catch (\Throwable $e) {
            if (function_exists('logModuleCall')) {
                logModuleCall('magizai', 'identity_check', array(), $e->getMessage(), '', array());
            }

            return BridgeResponse::signed($secret, $nonce, array('ok' => false, 'error' => 'store_unavailable', 'message' => 'WHMCS could not check this sign-in just now, so nothing was done. Try again shortly.'), 503);
        }

        /*
         * A PUBLIC READ NEVER ASKS ANYONE TO SIGN IN AGAIN. The catalogue takes the sign-in only to show
         * prices in the client's own currency; if that sign-in has lapsed (or was revoked, or is not
         * usable for any other reason), the right answer is the public price list, not "your sign-in
         * could not be confirmed" in reply to "what are your prices?". Everything that reads or changes
         * an account is still refused, exactly as before.
         */
        $publicRead = $spec['identity'] === 'optional' && !$spec['write'] && $spec['group'] === 'public';
        if ($publicRead && $check['code'] !== null) {
            $needsIdentity = false;
        }

        if ($needsIdentity && $check['code'] !== null) {
            AuditLog::audit($action, $spec['write'], null, $conversation, 'refused', $check['code'], $this->guard->detail($check['detail']));

            return BridgeResponse::signed($secret, $nonce, array('ok' => false, 'error' => $check['code'], 'message' => $check['message']));
        }

        if ($needsIdentity) {
            $claims = $check['claims'];
            $clientId = (int) (isset($claims['external_id']) ? $claims['external_id'] : $claims['sub']);
            $userId = isset($claims['uid']) && ctype_digit((string) $claims['uid']) && (int) $claims['uid'] > 0 ? (int) $claims['uid'] : null;
            $actor = $check['actor'];
        }

        /*
         * STAFF VIEW LOOKS AND NEVER TOUCHES. A staff member using the chat as a client (Login as Owner,
         * or an admin session in the same browser, with staff view switched on) may look things up. Every
         * change, and every read that would send the client something, is refused, and recorded against
         * the admin like every staff request. @see Registry::staffMayRun
         */
        if ($actor !== null && !Registry::staffMayRun($action)) {
            AuditLog::audit($action, $spec['write'], $clientId, $conversation, 'refused', 'staff_write_refused',
                $this->guard->detail('staff view: nothing is changed or sent from the chat'), $userId, $actor);

            return BridgeResponse::signed($secret, $nonce, array('ok' => false, 'error' => 'staff_write_refused', 'message' => Verifier::message('staff_write_refused')));
        }

        if ($needsIdentity && $spec['write']) {
            $freshness = Settings::minutes('write_freshness_minutes', 60, 5, 1440) * 60;
            if (time() - (int) $claims['iat'] > $freshness) {
                AuditLog::audit($action, true, $clientId, $conversation, 'refused', 'identity_stale', $this->guard->detail(null), $userId);

                return BridgeResponse::signed($secret, $nonce, array('ok' => false, 'error' => 'identity_stale', 'message' => 'For account changes the client must have signed in recently. Ask them to refresh the client area page, then ask again.'));
            }

            $limit = max(1, (int) Settings::get('writes_per_client_per_hour', '10'));
            if (AuditLog::recentWrites($clientId, 3600) >= $limit) {
                AuditLog::audit($action, true, $clientId, $conversation, 'refused', 'rate_limited', $this->guard->detail(null), $userId);

                return BridgeResponse::signed($secret, $nonce, array('ok' => false, 'error' => 'not_allowed', 'message' => 'Too many account changes through chat in the last hour. The client can make this change in the client area, or a staff member can help.'));
            }
        }

        $client = $clientId ? Capsule::table('tblclients')->where('id', $clientId)->first(array('id', 'status')) : null;
        if ($clientId && (!$client || $client->status === 'Closed')) {
            AuditLog::audit($action, $spec['write'], $clientId, $conversation, 'refused', 'not_found', $this->guard->detail(null), $userId, $actor);

            return BridgeResponse::signed($secret, $nonce, array('ok' => false, 'error' => 'not_found', 'message' => 'No active client account was found for this sign-in.'));
        }

        return array('client' => $clientId, 'user' => $userId, 'claims' => $claims, 'actor' => $actor);
    }

    /** Run the action for who the request is for, record what happened, and answer. */
    private function run($secret, $nonce, $action, array $args, array $spec, $conversation, array $who)
    {
        $clientId = $who['client'];
        $userId = $who['user'];
        $actor = $who['actor'];

        // A secret argument (the verification code) is masked out of every line written below, and
        // never logged as an argument, whatever went wrong. @see Registry::SECRET_ARGS
        $secrets = Registry::secretValues($action, $args);

        try {
            // The verified claims, always as an array: an identity action with no claims is refused there.
            // The conversation is the only per-visitor key a public read has. @see \MagizAI\Whmcs\Action\ReadLimits
            $result = Runner::run($action, $args, $clientId, $who['claims'], array('conversation' => $conversation));

            // A write that changed nothing (a renewal an open invoice already covers) is recorded as a
            // look-up: it uses none of the client's hourly changes and is not copied to WHMCS's activity
            // log as a change. @see Registry::changedAnything
            $changed = Registry::changedAnything($action, $result);

            AuditLog::audit($action, $changed, $clientId, $conversation, 'ok', '', $this->guard->detail(isset($result['audit']) ? Text::mask($result['audit'], $secrets) : null), $userId, $actor);

            if ($changed && $clientId && Settings::get('notify_client_on_change') === 'on' && !empty($result['notify'])) {
                Clients::notifyClient($clientId, $result['notify']);
            }

            // One place decides what leaves WHMCS, so it can be tested. @see BridgeResponse::replyFor
            return BridgeResponse::signed($secret, $nonce, $secrets ? Text::maskDeep(BridgeResponse::replyFor($result), $secrets) : BridgeResponse::replyFor($result));
        } catch (ActionError $e) {
            // The detail, when the action gave one, is what staff need (a registrar's own words); the
            // message is what the client is told.
            AuditLog::audit($action, $spec['write'], $clientId, $conversation, 'refused', $e->code(),
                $this->guard->detail(Text::mask($e->detail() !== null ? $e->detail() : $e->getMessage(), $secrets)), $userId, $actor);

            return BridgeResponse::signed($secret, $nonce, array('ok' => false, 'error' => $e->code(), 'message' => Text::mask($e->getMessage(), $secrets)));
        } catch (\Throwable $e) {
            AuditLog::audit($action, $spec['write'], $clientId, $conversation, 'error', 'internal', $this->guard->detail(Text::mask($e->getMessage(), $secrets)), $userId, $actor);
            if (function_exists('logModuleCall')) {
                logModuleCall('magizai', $action, Registry::argsForTheLog($action, $args), Text::mask($e->getMessage(), $secrets), '', array());
            }

            return BridgeResponse::signed($secret, $nonce, array('ok' => false, 'error' => 'internal', 'message' => 'WHMCS could not complete this request.'), 500);
        }
    }
}
