<?php

namespace MagizAI\Whmcs;

use MagizAI\Whmcs\Install\Tables;
use MagizAI\Whmcs\Persistence\AuditLog;
use MagizAI\Whmcs\Persistence\KeptSettings;
use MagizAI\Whmcs\Persistence\NonceStore;

/**
 * The module's own tables: replay protection, the audit log, what has been synced, a copy of the
 * settings that survives deactivation, and (1.6.0) identity telemetry and sign-out revocations.
 *
 * The tables themselves are Install\Tables; what is kept in them is lib/Persistence. This facade
 * keeps every name the module published here.
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class Store
{
    const NONCES = Install\Tables::NONCES;
    const AUDIT = Install\Tables::AUDIT;
    const SYNC = Install\Tables::SYNC;
    const IDENTITY_STATS = Install\Tables::IDENTITY_STATS;
    const REVOCATIONS = Install\Tables::REVOCATIONS;
    const KEPT = Install\Tables::KEPT;
    const WHMCS_OWNED = Persistence\KeptSettings::WHMCS_OWNED;
    const NONCE_TTL = Persistence\NonceStore::TTL;
    const STAFF_DETAIL_PREFIX = Persistence\AuditLog::STAFF_DETAIL_PREFIX;

    public static function install()
    {
        Tables::install();
    }

    public static function installIdentityTables()
    {
        Tables::installIdentityTables();
    }

    public static function uninstall($dropKept = false)
    {
        Tables::uninstall($dropKept);
    }

    public static function keeping()
    {
        return KeptSettings::keeping();
    }

    public static function keep($setting, $storedValue)
    {
        KeptSettings::keep($setting, $storedValue);
    }

    public static function kept()
    {
        return KeptSettings::kept();
    }

    public static function forgetKept()
    {
        return KeptSettings::forget();
    }

    public static function claimNonce($nonce)
    {
        return NonceStore::claim($nonce);
    }

    public static function audit($action, $isWrite, $clientId, $conversation, $outcome, $error, $detail, $userId = null, $actor = null)
    {
        AuditLog::audit($action, $isWrite, $clientId, $conversation, $outcome, $error, $detail, $userId, $actor);
    }

    public static function auditHasUser()
    {
        return AuditLog::has('user_id');
    }

    public static function auditHas($column)
    {
        return AuditLog::has($column);
    }

    public static function recentWrites($clientId, $seconds)
    {
        return AuditLog::recentWrites($clientId, $seconds);
    }

    public static function recentSuccesses($action, $clientId, $userId, $seconds, array $detailMarkers = array())
    {
        return AuditLog::recentSuccesses($action, $clientId, $userId, $seconds, $detailMarkers);
    }

    public static function recentRuns($action, $seconds, $conversation = null)
    {
        return AuditLog::recentRuns($action, $seconds, $conversation);
    }

    public static function recentRefusal($action, $clientId, $marker, $seconds)
    {
        return AuditLog::recentRefusal($action, $clientId, $marker, $seconds);
    }

    public static function auditPage($limit = 100)
    {
        return AuditLog::page($limit);
    }
}
