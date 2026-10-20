<?php

namespace MagizAI\Whmcs\Identity;

use MagizAI\Whmcs\Install\Tables;
use MagizAI\Whmcs\Persistence\AuditLog;
use WHMCS\Database\Capsule;

/**
 * How often a client-area page went out WITHOUT an identity, and why.
 *
 * Incident #242 could only be diagnosed by piecing together page views in MagizAI's own data: the
 * module decided "nobody is signed in" for a staff browser and recorded nothing. These counters are
 * what the ping reports, so MagizAI's connection test can say "N page views in the last day had a
 * staff session, so account questions were not answered there".
 *
 * ONLY THE OUTCOMES THAT MINTED NO TOKEN for a reason worth knowing are counted: staff, no_account,
 * account_closed and module_error. A minted token or a signed-out page writes nothing, so a busy
 * client area never turns this table into one hot row updated on every page view. Counts go into
 * one row per hour and reason.
 *
 * A MISSING TABLE IS A NO-OP. The table comes with the 1.6.0 upgrade step, which only runs when an
 * admin opens the module; until then the footer must work exactly as before.
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class Telemetry
{
    /** The reasons counted. Anything else is ignored. */
    const COUNTED = array('staff', 'no_account', 'account_closed', 'module_error');

    /** The window the ping reports, in hours. */
    const WINDOW_HOURS = 24;

    /** Rows older than this are removed by the daily cron. */
    const KEEP_DAYS = 30;

    /**
     * Count one page view that went out without a token, for this reason. Never throws.
     *
     * @return bool whether it was counted
     */
    public static function count($reason, $now = null)
    {
        if (!in_array($reason, self::COUNTED, true)) {
            return false;
        }

        $bucket = self::bucket($now === null ? time() : (int) $now);

        try {
            $rows = Capsule::table(Tables::IDENTITY_STATS)->where('bucket', $bucket)->where('outcome', $reason)->increment('n');
            if ($rows > 0) {
                return true;
            }

            try {
                Capsule::table(Tables::IDENTITY_STATS)->insert(array('bucket' => $bucket, 'outcome' => $reason, 'n' => 1));
            } catch (\Exception $e) {
                // Another page view of the same hour inserted the row first.
                Capsule::table(Tables::IDENTITY_STATS)->where('bucket', $bucket)->where('outcome', $reason)->increment('n');
            }

            return true;
        } catch (\Exception $e) {
            // No table yet, or no database to write to: the page goes out as it would have anyway.
            return false;
        }
    }

    /**
     * What the ping reports: the counts of the last 24 hours and when a request last arrived with a
     * verified identity. Never throws; a missing table reads as zero.
     *
     * @return array{window_hours: int, counts: array<string, int>, last_identified_request_at: string|null}
     */
    public static function report($now = null)
    {
        return array(
            'window_hours' => self::WINDOW_HOURS,
            'counts' => self::counts($now),
            'last_identified_request_at' => self::lastIdentifiedRequestAt(),
        );
    }

    /** @return array<string, int> every counted reason, zero when none */
    public static function counts($now = null)
    {
        $now = $now === null ? time() : (int) $now;
        $counts = array_fill_keys(self::COUNTED, 0);

        try {
            $rows = Capsule::table(Tables::IDENTITY_STATS)
                ->where('bucket', '>', $now - self::WINDOW_HOURS * 3600)
                ->get(array('outcome', 'n'));
            foreach ($rows as $row) {
                if (isset($counts[$row->outcome])) {
                    $counts[$row->outcome] += (int) $row->n;
                }
            }
        } catch (\Exception $e) {
            // Nothing counted yet.
        }

        return $counts;
    }

    /**
     * When the bridge last answered a request that carried a verified CLIENT identity, in UTC
     * (ISO 8601), or null. Read from the activity log, newest first, so it stops at the first match.
     *
     * Staff view does not count: an install where only staff testing through Login as Owner gets
     * account answers is exactly what this is meant to show, and a staff read would hide it. Staff
     * rows carry the admin in `actor`, or, on an audit table from before 1.6.0 that has no such
     * column yet, at the start of the detail. @see AuditLog::audit
     */
    public static function lastIdentifiedRequestAt()
    {
        try {
            $query = Capsule::table(Tables::AUDIT)->whereNotNull('client_id')->where('outcome', 'ok');
            if (AuditLog::has('actor')) {
                $query->whereNull('actor');
            } else {
                $query->where(function ($notStaff) {
                    $notStaff->whereNull('detail')->orWhere('detail', 'not like', AuditLog::STAFF_DETAIL_PREFIX . '%');
                });
            }
            $at = $query->orderBy('id', 'desc')->value('created_at');
        } catch (\Exception $e) {
            return null;
        }

        $time = $at ? strtotime((string) $at) : false;

        return $time ? gmdate('Y-m-d\TH:i:s\Z', $time) : null;
    }

    /** Remove counts older than KEEP_DAYS. Run by the daily cron. @return int rows removed */
    public static function prune($now = null)
    {
        $now = $now === null ? time() : (int) $now;

        try {
            return (int) Capsule::table(Tables::IDENTITY_STATS)->where('bucket', '<', $now - self::KEEP_DAYS * 86400)->delete();
        } catch (\Exception $e) {
            return 0;
        }
    }

    /** The start of the hour $time falls in. */
    private static function bucket($time)
    {
        return $time - ($time % 3600);
    }
}
