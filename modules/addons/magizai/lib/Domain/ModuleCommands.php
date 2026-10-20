<?php

namespace MagizAI\Whmcs\Domain;

use MagizAI\Whmcs\Install\Tables;
use MagizAI\Whmcs\Persistence\AuditLog;
use MagizAI\Whmcs\Settings;
use WHMCS\Database\Capsule;

/**
 * The server commands a client may run from the chat (1.10.0): a list the admin writes on the Actions
 * tab, empty on every install until they do. Each entry is a server module's custom function, as
 * WHMCS's ModuleCustom runs it ("reboot"), or one module's ("virtualizor:reboot").
 *
 * NEVER THE LIFECYCLE. ModuleCustom runs whatever function name it is given, so the standard
 * provisioning functions (create, suspend, unsuspend, terminate, change package, change password,
 * renew and the rest of WHMCS's list) and any name that sounds like creating, suspending,
 * terminating, deleting, reinstalling, rebuilding, wiping, restoring or changing a password, package or plan
 * are refused, when the list is saved and again when a command runs.
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class ModuleCommands
{
    /** The setting that holds the list, one entry a line or separated by commas. */
    const SETTING = 'module_commands';

    /** How many commands one client may run from the chat in an hour, and the bounds of that setting. */
    const PER_HOUR = 'module_commands_per_hour';
    const PER_HOUR_DEFAULT = 3;
    const PER_HOUR_MAX = 20;

    /**
     * WHMCS's standard provisioning functions (developers.whmcs.com, Provisioning Modules, Supported
     * Functions), lower case: none of them is ever a command from the chat.
     */
    const STANDARD = array(
        'createaccount', 'suspendaccount', 'unsuspendaccount', 'terminateaccount', 'renew', 'changepassword',
        'changepackage', 'clientarea', 'adminlink', 'loginlink', 'clientareacustombuttonarray',
        'clientareaallowedfunctions', 'admincustombuttonarray', 'usageupdate', 'metricprovider',
        'adminservicestabfields', 'adminservicestabfieldssave', 'metadata', 'customactions', 'configoptions',
        'testconnection', 'servicesinglesignon', 'adminsinglesignon', 'listaccounts',
    );

    /** Words that make a name too dangerous to run from a chat, whatever the module means by it. */
    const DANGEROUS = '/create|suspend|terminat|delet|destroy|remov|cancel|reinstall|rebuild|reimage|wipe|format|factory|password|passwd|package|upgrade|downgrade|renew|plan|migrat|transfer|restore/i';

    /**
     * Why an entry cannot be on the list, or null when it can.
     *
     * @return string|null
     */
    public static function refusal($entry)
    {
        $parts = self::split($entry);
        if ($parts === null) {
            return '"' . substr((string) $entry, 0, 60) . '" is not a command name. Use the server module\'s function name, such as reboot, or module:function, such as virtualizor:reboot.';
        }
        $name = strtolower($parts['command']);
        if (in_array($name, self::STANDARD, true) || preg_match(self::DANGEROUS, $name)) {
            return '"' . $parts['command'] . '" cannot be run from the chat: creating, suspending, terminating, deleting, reinstalling, renewing or changing a password, package or plan is never offered this way.';
        }

        return null;
    }

    /**
     * The list as saved, entries that may not be run left out.
     *
     * @return array<int, array{module: string, command: string}>
     */
    public static function configured()
    {
        $out = array();
        foreach (preg_split('/[\s,]+/', (string) Settings::get(self::SETTING, '')) as $entry) {
            if ($entry === '' || self::refusal($entry) !== null) {
                continue;
            }
            $out[] = self::split($entry);
        }

        return $out;
    }

    /**
     * The commands offered for a service run by this server module, in the admin's spelling.
     *
     * @return string[]
     */
    public static function offeredFor($module)
    {
        $module = strtolower(trim((string) $module));
        $out = array();
        foreach (self::configured() as $entry) {
            if ($module !== '' && ($entry['module'] === '' || $entry['module'] === $module) && !in_array($entry['command'], $out, true)) {
                $out[] = $entry['command'];
            }
        }

        return $out;
    }

    /** The offered command the client asked for, compared without case (PHP function names have none), or null. */
    public static function pick($asked, array $offered)
    {
        $asked = strtolower(trim((string) $asked));
        foreach ($offered as $command) {
            if (strtolower($command) === $asked) {
                return $command;
            }
        }

        return null;
    }

    /** How many commands a client may run from the chat in an hour (Actions tab, 1 to 20, 3 by default). */
    public static function perHour()
    {
        $raw = trim((string) Settings::get(self::PER_HOUR, (string) self::PER_HOUR_DEFAULT));

        return ctype_digit($raw) ? max(1, min(self::PER_HOUR_MAX, (int) $raw)) : self::PER_HOUR_DEFAULT;
    }

    /**
     * Commands that reached the server in the last hour for this client account, and for this user
     * wherever they are signed in: the ones that worked and the ones the server refused, since both ran.
     * Throws when the log cannot be read: a limit that cannot be counted is not a limit that passed.
     *
     * @return array{client: int, user: int}
     */
    public static function recentRuns($action, $clientId, $userId)
    {
        $since = date('Y-m-d H:i:s', time() - 3600);
        $rows = function () use ($action, $since) {
            return Capsule::table(Tables::AUDIT)->where('action', (string) $action)->where('created_at', '>=', $since)
                ->where(function ($ran) {
                    $ran->where('outcome', 'ok')->orWhere(function ($failed) {
                        $failed->where('outcome', 'refused')->where('error', 'server_error');
                    });
                });
        };

        return array(
            'client' => (int) $rows()->where('client_id', (int) $clientId)->count(),
            'user' => (int) $userId > 0 && AuditLog::has('user_id') ? (int) $rows()->where('user_id', (int) $userId)->count() : 0,
        );
    }

    /** @return array{module: string, command: string}|null */
    private static function split($entry)
    {
        $entry = trim((string) $entry);
        if (!preg_match('/^(?:([a-z0-9_]{1,64}):)?([A-Za-z][A-Za-z0-9_]{0,63})$/i', $entry, $m)) {
            return null;
        }

        return array('module' => strtolower((string) $m[1]), 'command' => (string) $m[2]);
    }
}
