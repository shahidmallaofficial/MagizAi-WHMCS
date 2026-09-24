<?php
/**
 * MagizAI bridge — the single endpoint MagizAI calls.
 *
 * Every request must be:
 *   - a POST, signed with the bridge secret (X-MagizAI-Signature over timestamp, nonce and body),
 *   - no older than five minutes, and never seen before (nonce),
 *   - for an action the WHMCS admin has switched on,
 *   - and, for anything about a client's account, carry that client's identity token, which is
 *     verified HERE with the identity secret — not taken on MagizAI's word.
 *
 * Every reply to an authenticated request is signed and bound to the request nonce, so MagizAI can
 * tell a real answer from a cached page, a proxy error or a replayed response.
 */

use MagizAI\Whmcs\Actions;
use MagizAI\Whmcs\ActionError;
use MagizAI\Whmcs\Crypto;
use MagizAI\Whmcs\Settings;
use MagizAI\Whmcs\Store;

define('MAGIZAI_BRIDGE', true);

// WHMCS root is three levels up: modules/addons/magizai/bridge.php. Checked via the requested path
// too, because __DIR__ resolves symlinks and some installs symlink the module folder in.
$init = null;
foreach (array(__DIR__, isset($_SERVER['SCRIPT_FILENAME']) ? dirname($_SERVER['SCRIPT_FILENAME']) : null) as $dir) {
    if ($dir && is_file(dirname($dir, 3) . '/init.php')) {
        $init = dirname($dir, 3) . '/init.php';
        break;
    }
}
if ($init === null) {
    http_response_code(500);
    exit;
}
require_once $init;
require_once __DIR__ . '/lib/autoload.php';

const MAGIZAI_MAX_BODY = 65536;
const MAGIZAI_CLOCK_SKEW = 300;
const MAGIZAI_MODULE_VERSION = '1.4.0';

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

/** Unsigned reply — only for requests that never proved they hold the secret. */
function magizai_reject($status, $error)
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode(array('ok' => false, 'error' => $error));
    exit;
}

/** Signed reply, bound to the request nonce. */
function magizai_reply($secret, $nonce, array $payload, $status = 200)
{
    $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    $ts = (string) time();

    http_response_code($status);
    header('Content-Type: application/json');
    header('X-MagizAI-Timestamp: ' . $ts);
    header('X-MagizAI-Signature: ' . Crypto::sign($secret, $ts, $nonce, $body));
    echo $body;
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    magizai_reject(405, 'method_not_allowed');
}

$addon = \WHMCS\Database\Capsule::table('tbladdonmodules')->where('module', 'magizai')->where('setting', 'version')->exists();
if (!$addon) {
    magizai_reject(404, 'module_not_active');
}

$secret = Settings::get('bridge_secret');
if ($secret === '') {
    magizai_reject(503, 'not_configured');
}

$body = file_get_contents('php://input', false, null, 0, MAGIZAI_MAX_BODY + 1);
if ($body === false || strlen($body) > MAGIZAI_MAX_BODY) {
    magizai_reject(413, 'too_large');
}

$ts = isset($_SERVER['HTTP_X_MAGIZAI_TIMESTAMP']) ? (string) $_SERVER['HTTP_X_MAGIZAI_TIMESTAMP'] : '';
$nonce = isset($_SERVER['HTTP_X_MAGIZAI_NONCE']) ? (string) $_SERVER['HTTP_X_MAGIZAI_NONCE'] : '';
$signature = isset($_SERVER['HTTP_X_MAGIZAI_SIGNATURE']) ? (string) $_SERVER['HTTP_X_MAGIZAI_SIGNATURE'] : '';

if (!ctype_digit($ts) || !preg_match('/^[a-f0-9]{32,64}$/', $nonce)) {
    magizai_reject(401, 'bad_headers');
}

// Signature before anything else is looked at — including the clock, so an unsigned caller learns nothing.
if (!Crypto::verifySignature($secret, $ts, $nonce, $body, $signature)) {
    magizai_reject(401, 'bad_signature');
}

if (abs(time() - (int) $ts) > MAGIZAI_CLOCK_SKEW) {
    magizai_reply($secret, $nonce, array('ok' => false, 'error' => 'clock_skew', 'message' => 'The request timestamp is too far from this server\'s clock. Check the server time (NTP).'), 401);
}

if (!Store::claimNonce($nonce)) {
    magizai_reply($secret, $nonce, array('ok' => false, 'error' => 'replay', 'message' => 'This request was already processed.'), 409);
}

$request = json_decode($body, true);
if (!is_array($request) || (int) ($request['v'] ?? 0) !== 1 || !is_string($request['action'] ?? null)) {
    magizai_reply($secret, $nonce, array('ok' => false, 'error' => 'bad_request', 'message' => 'Unreadable request.'), 400);
}

$action = $request['action'];
$args = is_array($request['args'] ?? null) ? $request['args'] : array();
$conversation = is_string($request['conversation'] ?? null) ? substr($request['conversation'], 0, 64) : '';

if ($action === 'ping') {
    magizai_reply($secret, $nonce, array('ok' => true, 'summary' => 'pong', 'data' => array(
        'module_version' => MAGIZAI_MODULE_VERSION,
        'whmcs_version' => (string) \WHMCS\Config\Setting::getValue('Version'),
        'enabled_actions' => Settings::enabledActions(),
        'identity_configured' => Settings::get('identity_secret') !== '',
    )));
}

$catalog = Actions::catalog();
if (!isset($catalog[$action])) {
    magizai_reply($secret, $nonce, array('ok' => false, 'error' => 'unknown_action', 'message' => 'This WHMCS module does not support that.'), 404);
}
$spec = $catalog[$action];

if (!Settings::isEnabled($action)) {
    Store::audit($action, $spec['write'], null, $conversation, 'refused', 'action_disabled', null);
    magizai_reply($secret, $nonce, array('ok' => false, 'error' => 'action_disabled', 'message' => 'This is not available through chat. The client can do it in the client area or open a ticket.'));
}

// ---- identity ----------------------------------------------------------------------------------
$clientId = null;
$claims = array();

// 'optional' identity: verified when a token is sent, anonymous when none is. A token that IS sent
// but does not verify is still refused below, never silently downgraded to anonymous.
$identityGiven = is_array($request['identity'] ?? null) && !empty($request['identity']['token']);
$needsIdentity = $spec['identity'] === true || ($spec['identity'] === 'optional' && $identityGiven);

if ($needsIdentity) {
    $identity = is_array($request['identity'] ?? null) ? $request['identity'] : array();
    $claims = Crypto::verifyIdentity(Settings::get('identity_secret'), isset($identity['token']) ? $identity['token'] : null);

    $claimed = $claims ? (string) ($claims['external_id'] ?? ($claims['sub'] ?? '')) : '';

    if (!$claims || !ctype_digit($claimed) || $claimed !== (string) ($identity['client_id'] ?? '')) {
        Store::audit($action, $spec['write'], null, $conversation, 'refused', 'identity_expired', null);
        magizai_reply($secret, $nonce, array('ok' => false, 'error' => 'identity_expired', 'message' => 'The client\'s sign-in could not be confirmed or has expired. Ask them to refresh the client area page and try again.'));
    }

    $clientId = (int) $claimed;
    $claims = $claims ?: array();

    if ($spec['write']) {
        $freshness = max(5, (int) Settings::get('write_freshness_minutes', '60')) * 60;
        if (time() - (int) $claims['iat'] > $freshness) {
            Store::audit($action, true, $clientId, $conversation, 'refused', 'identity_stale', null);
            magizai_reply($secret, $nonce, array('ok' => false, 'error' => 'identity_stale', 'message' => 'For account changes the client must have signed in recently. Ask them to refresh the client area page, then ask again.'));
        }

        $limit = max(1, (int) Settings::get('writes_per_client_per_hour', '10'));
        if (Store::recentWrites($clientId, 3600) >= $limit) {
            Store::audit($action, true, $clientId, $conversation, 'refused', 'rate_limited', null);
            magizai_reply($secret, $nonce, array('ok' => false, 'error' => 'not_allowed', 'message' => 'Too many account changes through chat in the last hour. The client can make this change in the client area, or a staff member can help.'));
        }
    }

    $client = \WHMCS\Database\Capsule::table('tblclients')->where('id', $clientId)->first(array('id', 'status'));
    if (!$client || $client->status === 'Closed') {
        Store::audit($action, $spec['write'], $clientId, $conversation, 'refused', 'not_found', null);
        magizai_reply($secret, $nonce, array('ok' => false, 'error' => 'not_found', 'message' => 'No active client account was found for this sign-in.'));
    }
}

// ---- run ---------------------------------------------------------------------------------------
try {
    $result = Actions::run($action, $args, $clientId, is_array($claims) ? $claims : array());

    Store::audit($action, $spec['write'], $clientId, $conversation, 'ok', '', isset($result['audit']) ? $result['audit'] : null);

    if ($spec['write'] && $clientId && Settings::get('notify_client_on_change') === 'on' && !empty($result['notify'])) {
        Actions::notifyClient($clientId, $result['notify']);
    }

    magizai_reply($secret, $nonce, array(
        'ok' => true,
        'summary' => (string) $result['summary'],
        'data' => isset($result['data']) && is_array($result['data']) ? $result['data'] : array(),
    ));
} catch (ActionError $e) {
    Store::audit($action, $spec['write'], $clientId, $conversation, 'refused', $e->code(), $e->getMessage());
    magizai_reply($secret, $nonce, array('ok' => false, 'error' => $e->code(), 'message' => $e->getMessage()));
} catch (\Throwable $e) {
    Store::audit($action, $spec['write'], $clientId, $conversation, 'error', 'internal', $e->getMessage());
    if (function_exists('logModuleCall')) {
        logModuleCall('magizai', $action, $args, $e->getMessage(), '', array());
    }
    magizai_reply($secret, $nonce, array('ok' => false, 'error' => 'internal', 'message' => 'WHMCS could not complete this request.'), 500);
}
