<?php
/**
 * MagizAI bridge — the single endpoint MagizAI calls. What it accepts and answers is
 * Http\BridgeKernel; this file finds WHMCS, sends the answer and ends the request, the only file of
 * the module that ends one this way.
 *
 * NOTHING BUT THE SIGNED REPLY MAY LEAVE THIS FILE, so all output is buffered from the first line
 * and thrown away before the reply goes out. @see \MagizAI\Whmcs\Http\OutputGuard
 */

use MagizAI\Whmcs\Http\BridgeKernel;
use MagizAI\Whmcs\Http\BridgeRequest;
use MagizAI\Whmcs\Http\OutputGuard;

define('MAGIZAI_BRIDGE', true);

$magizaiBufferBase = ob_get_level();
ob_start();

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
    require_once __DIR__ . '/lib/autoload.php';
    (new OutputGuard($magizaiBufferBase))->drain();
    http_response_code(500);
    exit;
}
require_once $init;
require_once __DIR__ . '/lib/autoload.php';

// Errors are still logged the way WHMCS is configured; they are just never printed into a reply.
if (function_exists('ini_set')) {
    ini_set('display_errors', '0');
}

const MAGIZAI_MODULE_VERSION = '1.10.1';

$magizaiGuard = new OutputGuard($magizaiBufferBase);
$magizaiReply = (new BridgeKernel($magizaiGuard, MAGIZAI_MODULE_VERSION))->handle(BridgeRequest::fromGlobals());
$magizaiReply->send($magizaiGuard);
exit;
