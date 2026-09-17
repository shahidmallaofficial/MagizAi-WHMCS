<?php
/**
 * MagizAI hooks: the chat widget on client-area pages, and the nightly knowledge sync.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/lib/autoload.php';

add_hook('ClientAreaFooterOutput', 1, function ($vars) {
    try {
        return \MagizAI\Whmcs\Widget::footer(is_array($vars) ? $vars : array());
    } catch (\Throwable $e) {
        // The chat widget must never break a client-area page.
        return '';
    }
});

add_hook('DailyCronJob', 1, function ($vars) {
    try {
        \MagizAI\Whmcs\Sync::runIfConfigured();
    } catch (\Throwable $e) {
        if (function_exists('logActivity')) {
            logActivity('MagizAI knowledge sync failed: ' . $e->getMessage());
        }
    }
});
