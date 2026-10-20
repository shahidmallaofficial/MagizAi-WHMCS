<?php

namespace MagizAI\Whmcs\Domain;

use MagizAI\Whmcs\Schema;
use MagizAI\Whmcs\Summary;
use MagizAI\Whmcs\Support\Dates;
use MagizAI\Whmcs\Support\Format;
use MagizAI\Whmcs\Support\Money;
use WHMCS\Database\Capsule;

/**
 * A client's live services as an answer lists and counts them, and how a service's status is said
 * to the client.
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class Services
{
    /**
     * One live service as a list line: what it is, its status, its term and its date.
     *
     * THE DUE DATE WHENEVER WHMCS HAS ONE. It used to be printed only for recurring cycles, so every
     * service sold as a one-off payment (how most IPTV and streaming subscriptions are set up) came
     * back with no date at all. A date already passed says so. @see Dates::dueLabel
     *
     * @param bool $withPrice the recurring price too (the service list); the overview leaves it out
     */
    public static function serviceLine(array $p, array $currency, $withPrice)
    {
        $cycle = (string) $p['billingcycle'];
        $recurring = $cycle !== 'Free Account' && $cycle !== 'One Time';
        $due = Dates::dueLabel($p['nextduedate']);

        return '#' . $p['id'] . ' ' . $p['name'] . ($p['domain'] ? ' (' . $p['domain'] . ')' : '')
            . ' — ' . $p['status']
            . ($p['status'] === 'Suspended' && !empty($p['suspensionreason']) ? ' (reason: ' . Format::cut((string) $p['suspensionreason'], 80) . ')' : '')
            . ($withPrice && $recurring ? ', ' . Money::money($p['recurringamount'], $currency) . ' ' . strtolower($cycle) : ', ' . strtolower($cycle))
            . ($due !== 'n/a' ? ', next due / expires ' . $due : '');
    }

    /**
     * This client's live services, the one needing attention first, with the counts an answer leads
     * with. From tblhosting, this client's rows only: GetClientsProducts pages oldest first and
     * cannot be counted without reading every page.
     *
     * Order: the soonest next due date first (one already passed comes before one still ahead), a
     * service with no date after the dated ones, the newest first among equals.
     *
     * EVERY FIGURE IS A COUNT OF ALL OF THEM. The live services used to be read 500 at a time and
     * counted from what was read, so a reseller with 520 was told "500 live services", and the
     * suspended, overdue and due-soon figures covered only the newest 500. Each figure is now its own
     * count, and the order is the database's, so only the services an answer lists are read.
     *
     * @param int $limit how many ids `live` holds, best first; `live_total` counts all of them
     * @return array{live: int[], live_total: int, ended: int, due_soon: int, overdue: int, suspended: int, pending: int}
     */
    public static function liveServices($clientId, $limit = 50)
    {
        $hasDue = Schema::hasColumn('tblhosting', 'nextduedate');
        $mine = function () use ($clientId) {
            return Capsule::table('tblhosting')->where('userid', (int) $clientId);
        };
        $live = function () use ($mine) {
            return $mine()->whereIn('domainstatus', Statuses::LIVE_SERVICE_STATUSES);
        };

        $total = (int) $mine()->count();
        $liveTotal = (int) $live()->count();
        $out = array(
            'live' => array(), 'live_total' => $liveTotal, 'ended' => max(0, $total - $liveTotal),
            'due_soon' => 0, 'overdue' => 0,
            'suspended' => (int) $live()->where('domainstatus', 'Suspended')->count(),
            'pending' => (int) $live()->where('domainstatus', 'Pending')->count(),
        );
        if ($liveTotal === 0) {
            return $out;
        }

        // A service not yet set up has no running term to be late on. A date string compares the
        // same way in MySQL and SQLite; NULL and WHMCS's "0000-00-00" never pass.
        if ($hasDue) {
            $today = date('Y-m-d');
            $running = function () use ($live) {
                return $live()->where('domainstatus', '!=', 'Pending')->where('nextduedate', '>=', '1000-01-01');
            };
            $out['overdue'] = (int) $running()->where('nextduedate', '<', $today)->count();
            $out['due_soon'] = (int) $running()->where('nextduedate', '>=', $today)
                ->where('nextduedate', '<=', date('Y-m-d', strtotime('+' . Statuses::SERVICE_DUE_SOON_DAYS . ' days', strtotime('today'))))->count();
        }

        $query = $live();
        if ($hasDue) {
            $query->orderByRaw("CASE WHEN nextduedate IS NULL OR nextduedate < '1000-01-01' THEN 1 ELSE 0 END")->orderBy('nextduedate');
        }
        foreach ($query->orderBy('id', 'desc')->limit(max(1, (int) $limit))->pluck('id') as $id) {
            $out['live'][] = (int) $id;
        }

        return $out;
    }

    /**
     * "3 live services (1 due within 14 days, 1 suspended)", for the overview's first line, or, with
     * $withEnded, followed by the ended ones for the service list's.
     *
     * @param array{live: int[], live_total: int, ended: int, due_soon: int, overdue: int, suspended: int, pending: int} $s
     */
    public static function servicesCounts(array $s, $withEnded = false)
    {
        $live = (int) $s['live_total'];

        $details = array();
        if ($s['overdue'] > 0) {
            $details[] = $s['overdue'] . ' past ' . ($s['overdue'] === 1 ? 'its' : 'their') . ' due date';
        }
        if ($s['due_soon'] > 0) {
            $details[] = $s['due_soon'] . ' due within ' . Statuses::SERVICE_DUE_SOON_DAYS . ' days';
        }
        if ($s['suspended'] > 0) {
            $details[] = $s['suspended'] . ' suspended';
        }
        if ($s['pending'] > 0) {
            $details[] = $s['pending'] . ' waiting to be set up';
        }

        $text = $live > 0
            ? Summary::count($live, 'live service') . ($details ? ' (' . implode(', ', $details) . ')' : '')
            : ($withEnded ? 'No active services' : 'no live services');

        if ($withEnded && $s['ended'] > 0) {
            $text .= ($live > 0 ? ', ' : '; ') . $s['ended'] . ' ended (terminated or cancelled)';
        }

        return $text;
    }

    /** A service's status as the client hears it: a service WHMCS holds as fraud is being reviewed. */
    public static function serviceStatusWord($status)
    {
        return stripos((string) $status, 'fraud') !== false ? 'being reviewed by the team' : (string) $status;
    }

    /** "has ended (terminated)", or "is being reviewed by the team" for one WHMCS holds as fraud. */
    public static function endedPhrase($status)
    {
        return stripos((string) $status, 'fraud') !== false ? 'is being reviewed by the team' : 'has ended (' . strtolower((string) $status) . ')';
    }
}
