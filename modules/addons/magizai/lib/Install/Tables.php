<?php

namespace MagizAI\Whmcs\Install;

use MagizAI\Whmcs\Persistence\AuditLog;
use MagizAI\Whmcs\Schema;
use WHMCS\Database\Capsule;

/**
 * The module's own tables: replay protection, the audit log, what has been synced, a copy of the
 * settings that survives deactivation, and (1.6.0) identity telemetry and sign-out revocations.
 *
 * Every step is idempotent: a table is created only when it is missing, and a column added to an
 * existing table in place. Activation, every upgrade, the daily cron and a sign-out that finds no
 * table all run them, because a merchant who uploads new files may not open the module for weeks.
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class Tables
{
    const NONCES = 'mod_magizai_nonces';
    const AUDIT = 'mod_magizai_audit';
    const SYNC = 'mod_magizai_sync';

    /**
     * Client-area page views that minted no token, counted per hour and reason (staff,
     * no_account, account_closed, module_error). Never one row per page view. @see \MagizAI\Whmcs\Identity\Telemetry
     */
    const IDENTITY_STATS = 'mod_magizai_identity_stats';

    /** When each user (or, before WHMCS 8, each client) last signed out. @see \MagizAI\Whmcs\Identity\Revocations */
    const REVOCATIONS = 'mod_magizai_revocations';

    /*
     * WHMCS DELETES AN ADDON'S SETTINGS WHEN IT IS DEACTIVATED. Many admins update a module by
     * deactivating it, uploading the files and activating it again, and every time that generated a
     * new bridge secret: MagizAI's copy stopped matching, every chat question failed, the actions and
     * the password switch went back to their defaults, and nothing said why. This table is not
     * dropped on deactivation, so activation can put everything back exactly as it was.
     *
     * Because the copy includes the secrets, deactivating is NOT a way to replace them. "Rotate all
     * secrets" on the Setup tab is; "Forget the kept copy" stops the copy being kept at all.
     */
    const KEPT = 'mod_magizai_kept_settings';

    /**
     * Accounts the chat opened for new clients (1.8.0), one row each: which conversation, which client,
     * the invoice of the order placed with it, and how often that conversation has used its one sign-in
     * link. The sign-in link is only ever for an account its own conversation opened, recently.
     * Kept on deactivation like the audit log: it is a record of accounts that were created.
     * @see \MagizAI\Whmcs\Domain\Signups
     */
    const SIGNUPS = 'mod_magizai_signups';

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
                // WHICH USER of the account asked (WHMCS 8 users), so limits can apply per person.
                $table->integer('user_id')->nullable()->index();
                $table->string('conversation', 64)->default('');
                $table->string('outcome', 16);
                $table->string('error', 40)->default('');
                $table->text('detail')->nullable();
                $table->string('ip', 45)->default('');
                // A staff member viewing a client's account (staff view): "admin:<id>".
                $table->string('actor', 40)->nullable();
            });
        } else {
            // Installs from before 1.6.0. Added in place: the log itself is never dropped.
            if (!$schema->hasColumn(self::AUDIT, 'user_id')) {
                $schema->table(self::AUDIT, function ($table) {
                    $table->integer('user_id')->nullable()->index();
                });
            }
            if (!$schema->hasColumn(self::AUDIT, 'actor')) {
                $schema->table(self::AUDIT, function ($table) {
                    $table->string('actor', 40)->nullable();
                });
            }
        }
        AuditLog::forgetColumns();

        self::installIdentityTables();

        if (!$schema->hasTable(self::KEPT)) {
            $schema->create(self::KEPT, function ($table) {
                $table->string('setting', 64)->primary();
                $table->text('value');
                $table->dateTime('updated_at');
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

        if (!$schema->hasTable(self::SIGNUPS)) {
            $schema->create(self::SIGNUPS, function ($table) {
                $table->increments('id');
                $table->string('conversation', 64)->index();
                $table->integer('client_id')->index();
                $table->integer('invoice_id')->nullable();
                // The visitor's address as MagizAI saw it (every request comes from MagizAI's servers).
                $table->string('ip', 45)->default('');
                $table->integer('sign_ins')->default(0);
                $table->dateTime('created_at')->index();
            });
        }

        // The tables just changed, so nothing remembered about them still holds.
        Schema::forget();
    }

    /**
     * The identity telemetry and revocation tables (1.6.0), created when missing.
     *
     * Part of install(), and also run by the daily cron and by a sign-out that finds no table: the
     * upgrade step only runs when an admin opens the module, and a merchant who uploads new files
     * may not do that for weeks. A revocation must not be lost for that reason.
     */
    public static function installIdentityTables()
    {
        $schema = Capsule::schema();

        if (!$schema->hasTable(self::IDENTITY_STATS)) {
            $schema->create(self::IDENTITY_STATS, function ($table) {
                // The hour, as the unix time it started at. 64-bit: a 32-bit column ends in 2038.
                $table->bigInteger('bucket');
                $table->string('outcome', 20);
                $table->integer('n')->default(0);
                $table->primary(array('bucket', 'outcome'));
            });
        }

        if (!$schema->hasTable(self::REVOCATIONS)) {
            $schema->create(self::REVOCATIONS, function ($table) {
                // "uid:<WHMCS user id>", "client:<client id>" before WHMCS 8, or "staff:<admin id>"
                // when a Login as Owner session ended.
                $table->string('subject', 40)->primary();
                // Tokens issued at or before this moment (milliseconds) are refused.
                $table->bigInteger('revoked_before_ms');
                // Unix seconds. 64-bit: a 32-bit column ends in 2038.
                $table->bigInteger('updated_at')->index();
            });
        }

        Schema::forget();
    }

    /**
     * @param bool $dropKept also drop the kept copy of the settings — only when the admin chose
     *                       "Forget the kept copy" before deactivating
     */
    public static function uninstall($dropKept = false)
    {
        $schema = Capsule::schema();
        // The identity counters go; the revocations stay, like the audit log: a sign-out recorded
        // before a deactivation still counts if the module is activated again within a day.
        $tables = array(self::NONCES, self::SYNC, self::IDENTITY_STATS);
        if ($dropKept) {
            $tables[] = self::KEPT;
        }
        foreach ($tables as $table) {
            $schema->dropIfExists($table);
        }
        Schema::forget();
        // The audit log is kept on purpose: it is the record of what changed client accounts, and
        // deactivating a module must not be a way to erase that. So is the record of accounts the chat opened. The kept settings stay too, unless
        // the admin asked for them to go: they are what makes deactivate-upload-activate an update
        // rather than a reinstall.
    }
}
