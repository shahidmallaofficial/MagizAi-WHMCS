<?php

namespace MagizAI\Whmcs\Action\Domains;

use MagizAI\Whmcs\Action\ActionContext;
use MagizAI\Whmcs\Action\ActionInterface;
use MagizAI\Whmcs\Domain\Statuses;
use MagizAI\Whmcs\Schema;
use MagizAI\Whmcs\Summary;
use MagizAI\Whmcs\Support\Dates;
use MagizAI\Whmcs\Support\Format;
use WHMCS\Database\Capsule;

/**
 * The client's domains: the live ones first, soonest expiry first, then the recently expired ones,
 * every group counted.
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class DomainsList implements ActionInterface
{
    /** How many domains one list shows. The rest are counted, never silently dropped. */
    const DOMAINS_SHOWN = 15;

    /** An expired domain is listed for this long after it expired; after that it is counted as ended. */
    const DOMAIN_RECENTLY_EXPIRED_DAYS = 90;

    public function spec()
    {
        return array('identity' => true, 'write' => false, 'group' => 'read', 'label' => 'List domains');
    }

    public function handle(ActionContext $context)
    {
        $clientId = $context->clientId();

        /*
         * LIVE DOMAINS FIRST, SOONEST EXPIRY FIRST, from tbldomains, bounded and counted.
         *
         * This printed every row GetClientsDomains returned (oldest first, up to 100) in every status.
         * MagizAI cuts a summary at a fixed length on its way to the model, so for a reseller with
         * many cancelled or transferred-away domains the live ones that sorted after them were the
         * part that got cut: the same failure services_list had. Live domains come first now, then
         * the ones that expired recently (often still renewable), and the rest is one line with a
         * count.
         *
         * EACH GROUP IS ITS OWN QUERY. One window over live and expired domains together, sorted by
         * expiry date, was filled by the expired ones first, because they always expire earlier: a
         * client with 200 old expired domains was shown none of their live ones. Live domains are
         * read on their own now, then the recently expired ones for whatever room is left, and every
         * figure in the summary is a count, never the size of a window.
         */
        $columns = array('id', 'domain', 'status', 'expirydate');
        if (Schema::hasColumn('tbldomains', 'donotrenew')) {
            $columns[] = 'donotrenew';
        }

        $mine = function () use ($clientId) {
            return Capsule::table('tbldomains')->where('userid', (int) $clientId);
        };

        // A date string compares the same way in MySQL and SQLite; NULL and "0000-00-00" never pass.
        $cutoff = date('Y-m-d', strtotime('-' . self::DOMAIN_RECENTLY_EXPIRED_DAYS . ' days'));
        $recentlyExpired = function () use ($mine, $cutoff) {
            return $mine()->where('status', 'Expired')->where('expirydate', '>=', $cutoff);
        };

        $total = (int) $mine()->count();
        $liveTotal = (int) $mine()->whereIn('status', Statuses::LIVE_DOMAIN_STATUSES)->count();
        $expiredTotal = (int) $recentlyExpired()->count();

        // Soonest expiry first, and a domain with no known date (NULL, or WHMCS's "0000-00-00")
        // after the rest rather than before.
        $live = array();
        foreach ($mine()->whereIn('status', Statuses::LIVE_DOMAIN_STATUSES)
            ->orderByRaw("CASE WHEN expirydate IS NULL OR expirydate < '1000-01-01' THEN 1 ELSE 0 END")
            ->orderBy('expirydate')->orderBy('id', 'desc')
            ->limit(self::DOMAINS_SHOWN)->get($columns) as $row) {
            $live[] = $row;
        }

        // The most recently expired first, in the room the live ones left.
        $expired = array();
        $room = self::DOMAINS_SHOWN - count($live);
        if ($room > 0 && $expiredTotal > 0) {
            foreach ($recentlyExpired()->orderBy('expirydate', 'desc')->orderBy('id', 'desc')
                ->limit($room)->get($columns) as $row) {
                $expired[] = $row;
            }
        }

        $shown = array_merge($live, $expired);
        $lines = array();
        foreach ($shown as $d) {
            $flags = array();
            if (Statuses::domainExpiresSoon($d->status, $d->expirydate)) {
                $flags[] = 'expires within ' . Statuses::DOMAIN_EXPIRES_SOON_DAYS . ' days';
            }
            if (isset($d->donotrenew) && Format::flagOn($d->donotrenew)) {
                $flags[] = 'auto-renew OFF';
            }
            $lines[] = $d->domain . ', ' . $d->status . ', ' . ((string) $d->status === 'Expired' ? 'expired ' : 'expires ')
                . Dates::date($d->expirydate) . ($flags ? ' [' . implode('; ', $flags) . ']' : '');
        }

        if ($total === 0) {
            return array('summary' => 'This client has no domains.', 'data' => array('live' => 0, 'recently_expired' => 0, 'ended' => 0));
        }

        $ended = max(0, $total - $liveTotal - $expiredTotal);

        /*
         * THE COUNTS LEAD (1.6.0), counted with the window the lines are flagged with (and the
         * overview counts with), so "2 expiring" and the two flagged lines always agree. Then the
         * lines, cut to fit with a count of the rest, never cut at a length somewhere in the middle
         * of a line. @see Summary, Statuses::domainSoonWindow
         */
        $details = array();
        if ($liveTotal > 0) {
            list($soonFrom, $soonTo) = Statuses::domainSoonWindow();
            $expiring = (int) $mine()->where('status', 'Active')->where('expirydate', '>=', $soonFrom)
                ->where('expirydate', '<=', $soonTo)->count();
            if ($expiring > 0) {
                $details[] = $expiring . ' expiring within ' . Statuses::DOMAIN_EXPIRES_SOON_DAYS . ' days';
            }
            if (in_array('donotrenew', $columns, true)) {
                $off = (int) $mine()->whereIn('status', Statuses::LIVE_DOMAIN_STATUSES)->whereIn('donotrenew', array(1, '1', 'on'))->count();
                if ($off > 0) {
                    $details[] = $off . ' with auto-renew off';
                }
            }
        }

        $head = array($liveTotal > 0
            ? Summary::count($liveTotal, 'live domain') . ($details ? ' (' . implode(', ', $details) . ')' : '')
            : 'No live domains');
        if ($expiredTotal > 0) {
            $head[] = $expiredTotal . ' expired in the last ' . self::DOMAIN_RECENTLY_EXPIRED_DAYS . ' days, often still renewable';
        }
        if ($ended > 0) {
            $head[] = $ended . ' ended (expired, cancelled or transferred away)';
        }

        return array(
            'summary' => Summary::fit(array(implode('; ', $head) . '.'), array(array('items' => $lines, 'more' => $liveTotal + $expiredTotal - count($shown)))),
            'data' => array('live' => $liveTotal, 'recently_expired' => $expiredTotal, 'ended' => $ended),
        );
    }
}
