<?php

namespace MagizAI\Whmcs;

use WHMCS\Database\Capsule;

/**
 * The module's own tables: replay protection, the audit log, and what has been synced.
 */
class Store
{
    const NONCES = 'mod_magizai_nonces';
    const AUDIT = 'mod_magizai_audit';
    const SYNC = 'mod_magizai_sync';

    /** Nonces older than this are purged; requests older than this are already refused by timestamp. */
    const NONCE_TTL = 900;

    public static function install()
    {
        $schema = Capsule::schema();

        if (!$schema->hasTable(self::NONCES)) {
            $schema->create(self::NONCES, function ($table) {
                $table->string('nonce', 64)->primary();
                $table->integer('created_at')->index();
            });
        }

        if (!$schema->hasTable(self::AUDIT)) {
            $schema->create(self::AUDIT, function ($table) {
                $table->increments('id');
                $table->dateTime('created_at')->index();
                $table->string('action', 48);
                $table->boolean('is_write')->default(false);
                $table->integer('client_id')->nullable()->index();
                $table->string('conversation', 64)->default('');
                $table->string('outcome', 16);
                $table->string('error', 40)->default('');
                $table->text('detail')->nullable();
                $table->string('ip', 45)->default('');
            });
        }

        if (!$schema->hasTable(self::SYNC)) {
            $schema->create(self::SYNC, function ($table) {
                $table->string('source_url', 191)->primary();
                $table->string('kind', 20)->index();
                $table->string('hash', 40);
                $table->dateTime('synced_at');
            });
        }
    }

    public static function uninstall()
    {
        $schema = Capsule::schema();
        foreach (array(self::NONCES, self::SYNC) as $table) {
            $schema->dropIfExists($table);
        }
        // The audit log is kept on purpose: it is the record of what changed client accounts, and
        // deactivating a module must not be a way to erase that.
    }

    /**
     * Record a nonce. False when it has been seen before — the request is a replay.
     */
    public static function claimNonce($nonce)
    {
        Capsule::table(self::NONCES)->where('created_at', '<', time() - self::NONCE_TTL)->delete();

        try {
            Capsule::table(self::NONCES)->insert(array('nonce' => $nonce, 'created_at' => time()));

            return true;
        } catch (\Exception $e) {
            return false; // primary key collision
        }
    }

    public static function audit($action, $isWrite, $clientId, $conversation, $outcome, $error, $detail)
    {
        try {
            Capsule::table(self::AUDIT)->insert(array(
                'created_at' => date('Y-m-d H:i:s'),
                'action' => substr((string) $action, 0, 48),
                'is_write' => $isWrite ? 1 : 0,
                'client_id' => $clientId ? (int) $clientId : null,
                'conversation' => substr((string) $conversation, 0, 64),
                'outcome' => substr((string) $outcome, 0, 16),
                'error' => substr((string) $error, 0, 40),
                'detail' => $detail === null ? null : substr((string) $detail, 0, 2000),
                'ip' => substr(isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '', 0, 45),
            ));
        } catch (\Exception $e) {
            // The audit log must never be the reason a client's request fails.
        }

        // Changes also land in WHMCS's own activity log, next to everything else staff already watch.
        if ($isWrite && function_exists('logActivity')) {
            logActivity('MagizAI live chat: ' . $action . ' ' . $outcome . ($error !== '' ? ' (' . $error . ')' : '') . ($detail ? ' — ' . $detail : ''), $clientId ? (int) $clientId : 0);
        }
    }

    public static function recentWrites($clientId, $seconds)
    {
        return (int) Capsule::table(self::AUDIT)
            ->where('client_id', (int) $clientId)
            ->where('is_write', 1)
            ->where('outcome', 'ok')
            ->where('created_at', '>=', date('Y-m-d H:i:s', time() - $seconds))
            ->count();
    }

    public static function auditPage($limit = 100)
    {
        return Capsule::table(self::AUDIT)->orderBy('id', 'desc')->limit($limit)->get();
    }
}
