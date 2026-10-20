<?php

namespace MagizAI\Whmcs;

/**
 * Tells the admin when a newer module exists, and where to get it from us.
 *
 * WHY THE MODULE HAS TO ASK. Nothing else ever tells a host they are out of date. They install it
 * once, it keeps working, and every later fix stays where it was released. That is not a
 * theoretical loss: a live customer ran 1.0.0 for months and lost their plan cards to a feature
 * that arrived in 1.2.0 — the connection tested green the whole time, the assistant answered every
 * question, and the only symptom was that one hosting company's chat showed tidy plan cards and
 * theirs showed prose the model had written itself. Nobody could have guessed that from the
 * outside.
 *
 * WHAT IT WILL NOT DO
 * ---------------------
 *  - It never downloads or installs anything. It shows a version, a link and a checksum, and a
 *    human decides. A module that could replace its own code on a billing system is a far larger
 *    thing to trust than one that cannot.
 *  - It never sends anything about this install. The request is a plain GET with no query string,
 *    no client data, no licence key and no identifier: the answer is the same for everybody, which
 *    is why it can be cached and why there is nothing here to leak.
 *  - It never breaks the page. Every failure — offline, DNS, a proxy returning HTML, malformed
 *    JSON, an ancient curl — ends as "no news", because an admin screen that dies because a
 *    version check timed out is a worse bug than being out of date.
 *
 * Checked at most once a day and remembered in the module's own settings, so opening the admin
 * page twenty times in an afternoon is one request.
 */
class Updates
{
    /** How long a check is trusted before we ask again. */
    const TTL_SECONDS = 86400;

    /** Deliberately short: this runs while an admin waits for a page to render. */
    const TIMEOUT_SECONDS = 5;

    /**
     * The newest release we know of, or null when we do not know.
     *
     * @return array{version: string, download_url: string, sha256: string, notes_url: string}|null
     */
    public static function latest($force = false)
    {
        $cached = json_decode((string) Settings::get('update_check_result', ''), true);
        $checkedAt = (int) Settings::get('update_checked_at', '0');

        if (!$force && is_array($cached) && (time() - $checkedAt) < self::TTL_SECONDS) {
            return isset($cached['version']) ? $cached : null;
        }

        $fetched = self::fetch();

        // The timestamp is written even on failure, so an unreachable server is retried tomorrow
        // rather than on every page load.
        Settings::set('update_checked_at', (string) time());
        Settings::set('update_check_result', json_encode(is_array($fetched) ? $fetched : array()));

        return $fetched;
    }

    /** Is $latest newer than what is running? */
    public static function isNewer($latest)
    {
        if (!is_array($latest) || empty($latest['version'])) {
            return false;
        }

        $running = defined('MAGIZAI_BRIDGE_VERSION') ? MAGIZAI_BRIDGE_VERSION : self::runningVersion();

        return version_compare((string) $latest['version'], $running, '>');
    }

    /** The version of the module currently installed, read from its own config. */
    public static function runningVersion()
    {
        if (function_exists('magizai_config')) {
            $config = magizai_config();

            if (is_array($config) && !empty($config['version'])) {
                return (string) $config['version'];
            }
        }

        return '0';
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function fetch()
    {
        $url = rtrim(trim((string) Settings::get('magizai_url', 'https://magiz.ai')), '/').'/whmcs/latest.json';

        if (!function_exists('curl_init')) {
            return null;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => array('Accept: application/json'),
            CURLOPT_USERAGENT => 'MagizAI-WHMCS/'.self::runningVersion(),
        ));
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if (PHP_VERSION_ID < 80000) {
            // Does nothing since PHP 8.0 and is deprecated in 8.5; the handle is freed with $ch.
            curl_close($ch);
        }

        if ($status !== 200 || !is_string($body) || $body === '') {
            return null;
        }

        $data = json_decode($body, true);
        if (!is_array($data) || empty($data['version'])) {
            return null;
        }

        // A version string is the only thing here that decides anything, so it is the one thing
        // checked properly. Everything else is displayed, and displayed escaped.
        if (!preg_match('/^\d+(\.\d+){0,3}$/', (string) $data['version'])) {
            return null;
        }

        $download = isset($data['download_url']) ? (string) $data['download_url'] : '';

        // Only ever offer a download over https, from the host the admin configured. A redirect to
        // somewhere else is exactly how an update mechanism becomes the way in.
        if ($download !== '' && !self::sameHostHttps($download, $url)) {
            $download = '';
        }

        return array(
            'version' => (string) $data['version'],
            'download_url' => $download,
            'sha256' => isset($data['sha256']) && preg_match('/^[a-f0-9]{64}$/i', (string) $data['sha256'])
                ? (string) $data['sha256'] : '',
            'notes_url' => isset($data['notes_url']) && self::sameHostHttps((string) $data['notes_url'], $url)
                ? (string) $data['notes_url'] : '',
        );
    }

    /** https, and the same host we asked. */
    private static function sameHostHttps($candidate, $reference)
    {
        $a = parse_url($candidate);
        $b = parse_url($reference);

        if (!is_array($a) || !is_array($b) || empty($a['host']) || empty($b['host'])) {
            return false;
        }

        return strtolower((string) $a['scheme']) === 'https'
            && strtolower((string) $a['host']) === strtolower((string) $b['host']);
    }
}
