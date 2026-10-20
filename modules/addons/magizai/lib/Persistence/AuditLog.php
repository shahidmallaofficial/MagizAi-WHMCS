<?php

namespace MagizAI\Whmcs\Persistence;

use MagizAI\Whmcs\Install\Tables;
use WHMCS\Database\Capsule;

/**
 * The module's activity log: every request MagizAI made and what came of it, which the admin page
 * shows and the hourly limits count. Account changes, and every request made in staff view, are
 * also copied to WHMCS's own activity log.
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class AuditLog
{
    /**
     * How a staff view row starts its detail on an audit table that has no `actor` column yet
     * (before the 1.6.0 upgrade step ran). Plain characters only: it is matched with LIKE.
     */
    const STAFF_DETAIL_PREFIX = '[staff view, ';

    /** Which optional audit columns exist, asked once per request. @see has */
    private static $columns = array();

    /**
     * @param int|null    $userId the WHMCS user (not account) who asked, when the sign-in names one
     * @param string|null $actor  "admin:<id>" when a staff member asked in staff view
     */
    public static function audit($action, $isWrite, $clientId, $conversation, $outcome, $error, $detail, $userId = null, $actor = null)
    {
        $actor = is_string($actor) && $actor !== '' ? substr($actor, 0, 40) : null;

        try {
            $row = array(
                'created_at' => date('Y-m-d H:i:s'),
                'action' => substr((string) $action, 0, 48),
                'is_write' => $isWrite ? 1 : 0,
                'client_id' => $clientId ? (int) $clientId : null,
                'conversation' => substr((string) $conversation, 0, 64),
                'outcome' => substr((string) $outcome, 0, 16),
                'error' => substr((string) $error, 0, 40),
                'detail' => $detail === null ? null : substr((string) $detail, 0, 2000),
                'ip' => substr(isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '', 0, 45),
            );
            // Only once the upgrade has added each column: until an admin opens the module after
            // uploading new files, the old table is still the one in use, and a row it cannot take
            // would be a row lost.
            if (self::has('user_id')) {
                $row['user_id'] = $userId ? (int) $userId : null;
            }
            if (self::has('actor')) {
                $row['actor'] = $actor;
            } elseif ($actor !== null) {
                // Not lost on an old table either: the admin goes into the detail instead.
                $row['detail'] = substr(trim(self::STAFF_DETAIL_PREFIX . $actor . '] ' . (string) $row['detail']), 0, 2000);
            }

            Capsule::table(Tables::AUDIT)->insert($row);
        } catch (\Exception $e) {
            // The audit log must never be the reason a client's request fails.
        }

        if (!function_exists('logActivity')) {
            return;
        }

        /*
         * Changes land in WHMCS's own activity log, next to everything else staff already watch. So
         * does every request made in staff view, look-ups included, named after the admin: an
         * account that was looked at on a client's behalf is something the team should be able to
         * see, whoever did it.
         */
        if ($actor !== null) {
            logActivity('MagizAI live chat (staff view by admin #' . (int) substr($actor, 6) . '): ' . $action . ' ' . $outcome
                . ($error !== '' ? ' (' . $error . ')' : '') . ($detail ? '. ' . $detail : ''), $clientId ? (int) $clientId : 0);
        } elseif ($isWrite) {
            logActivity('MagizAI live chat: ' . $action . ' ' . $outcome . ($error !== '' ? ' (' . $error . ')' : '') . ($detail ? ' — ' . $detail : ''), $clientId ? (int) $clientId : 0);
        }
    }

    /** Has the audit table got this optional column (user_id, actor)? Asked once per request. */
    public static function has($column)
    {
        if (!array_key_exists($column, self::$columns)) {
            try {
                self::$columns[$column] = (bool) Capsule::schema()->hasColumn(Tables::AUDIT, (string) $column);
            } catch (\Exception $e) {
                return false;
            }
        }

        return self::$columns[$column];
    }

    /** Forget which columns the table has: it was just created or changed. */
    public static function forgetColumns()
    {
        self::$columns = array();
    }

    public static function recentWrites($clientId, $seconds)
    {
        return (int) Capsule::table(Tables::AUDIT)
            ->where('client_id', (int) $clientId)
            ->where('is_write', 1)
            ->where('outcome', 'ok')
            ->where('created_at', '>=', date('Y-m-d H:i:s', time() - $seconds))
            ->count();
    }

    /**
     * How often one action succeeded in the last $seconds: for this client account, and for this
     * user wherever they are signed in. A user who manages several accounts is still one person.
     *
     * Throws when the log cannot be read: a limit that cannot be counted is not a limit that passed.
     *
     * @param string[] $detailMarkers when given, only rows whose detail contains one of these count
     *                                (for service_credentials: the ones that really sent a password).
     *                                Plain words: no % or _, which LIKE would read as wildcards.
     * @return array{client: int, user: int}
     */
    public static function recentSuccesses($action, $clientId, $userId, $seconds, array $detailMarkers = array())
    {
        $since = date('Y-m-d H:i:s', time() - (int) $seconds);

        $rows = function () use ($action, $since, $detailMarkers) {
            $query = Capsule::table(Tables::AUDIT)
                ->where('action', (string) $action)->where('outcome', 'ok')->where('created_at', '>=', $since);

            if ($detailMarkers) {
                $query->where(function ($any) use ($detailMarkers) {
                    foreach ($detailMarkers as $marker) {
                        $any->orWhere('detail', 'like', '%' . (string) $marker . '%');
                    }
                });
            }

            return $query;
        };

        $client = (int) $rows()->where('client_id', (int) $clientId)->count();

        $user = 0;
        if ((int) $userId > 0 && self::has('user_id')) {
            $user = (int) $rows()->where('user_id', (int) $userId)->count();
        }

        return array('client' => $client, 'user' => $user);
    }

    /**
     * How many times one action ran successfully in the last $seconds, across the install, or in
     * one conversation when $conversation is given. For reads whose side effects cost something
     * outside WHMCS (a registry lookup), whoever asks.
     *
     * Throws when the log cannot be read: a limit that cannot be counted is not a limit that passed.
     *
     * @param string|null $conversation MagizAI's conversation id, as the bridge records it
     * @return int
     */
    public static function recentRuns($action, $seconds, $conversation = null)
    {
        $query = Capsule::table(Tables::AUDIT)
            ->where('action', (string) $action)->where('outcome', 'ok')
            ->where('created_at', '>=', date('Y-m-d H:i:s', time() - (int) $seconds));

        if ($conversation !== null) {
            $query->where('conversation', substr((string) $conversation, 0, 64));
        }

        return (int) $query->count();
    }

    /**
     * Was this action refused for this client in the last $seconds with $marker in the detail?
     *
     * The refusal WHMCS itself gave is written to the activity log by the bridge, with a marker that
     * names what was refused. Reading it back is how an answer WHMCS has already given is not asked
     * for again: a renewal WHMCS refused is not offered with a fresh confirmation code an hour later.
     *
     * A log that cannot be read answers no. This is a memory of a refusal, not a guard: the action
     * itself is still refused by WHMCS if it is tried again.
     *
     * @param string $marker plain words, no % or _ (LIKE would read them as wildcards)
     * @return bool
     */
    public static function recentRefusal($action, $clientId, $marker, $seconds)
    {
        try {
            return (bool) Capsule::table(Tables::AUDIT)
                ->where('action', (string) $action)->where('outcome', 'refused')
                ->where('client_id', (int) $clientId)
                ->where('created_at', '>=', date('Y-m-d H:i:s', time() - (int) $seconds))
                ->where('detail', 'like', '%' . (string) $marker . '%')
                ->exists();
        } catch (\Exception $e) {
            return false;
        }
    }

    public static function page($limit = 100)
    {
        return Capsule::table(Tables::AUDIT)->orderBy('id', 'desc')->limit($limit)->get();
    }
}
