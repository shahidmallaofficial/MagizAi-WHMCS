<?php

namespace MagizAI\Whmcs\Hooks;

use MagizAI\Whmcs\Identity\Revocations;
use MagizAI\Whmcs\Identity\Telemetry;
use MagizAI\Whmcs\Install\EmailTemplate;
use MagizAI\Whmcs\Install\Tables;
use MagizAI\Whmcs\Sync;

/**
 * DailyCronJob: the nightly knowledge sync, and the identity counters and old sign-outs kept short.
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class CronHook
{
    public static function run(array $vars)
    {
        try {
            Sync::runIfConfigured();
        } catch (\Throwable $e) {
            if (function_exists('logActivity')) {
                logActivity('MagizAI knowledge sync failed: ' . $e->getMessage());
            }
        }

        // The identity counters and old sign-outs: created if an upload was never followed by opening
        // the module, and kept short.
        try {
            Tables::installIdentityTables();
            Telemetry::prune();
            Revocations::prune();
        } catch (\Throwable $e) {
            // Tried again tomorrow.
        }

        // The verification code email template (1.7.0), for an install whose files were uploaded
        // without opening the module. Never overwrites one the merchant edited.
        try {
            EmailTemplate::install();
        } catch (\Throwable $e) {
            // Tried again tomorrow, and by the next send.
        }
    }
}
