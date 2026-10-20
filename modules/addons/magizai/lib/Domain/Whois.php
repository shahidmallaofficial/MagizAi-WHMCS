<?php

namespace MagizAI\Whmcs\Domain;

use MagizAI\Whmcs\Settings;
use MagizAI\Whmcs\Support\WhmcsApi;

/**
 * Whether a domain is free to register: available, unavailable or unknown, never a guess, and the
 * store page where the host's own lookup applies.
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class Whois
{
    /**
     * Is this domain free to register: available, unavailable, or unknown — never a guess.
     *
     * WHMCS's DomainWhois answers result=error, with no status, when the lookup fails or the
     * extension cannot be looked up. That is "unknown", which nothing may read as "free".
     *
     * @return array{status: string, reason: string} reason is '' | failed | unsupported_tld
     */
    public static function domainLookup($domain)
    {
        $r = WhmcsApi::api('DomainWhois', array('domain' => $domain), false);

        if (($r['result'] ?? '') !== 'success') {
            $message = strtolower((string) ($r['message'] ?? ''));

            return array('status' => 'unknown', 'reason' => strpos($message, 'not supported') !== false ? 'unsupported_tld' : 'failed');
        }

        $status = strtolower(trim((string) ($r['status'] ?? '')));
        if ($status === 'available' || $status === 'unavailable') {
            return array('status' => $status, 'reason' => '');
        }

        return array('status' => 'unknown', 'reason' => 'failed');
    }

    /** The store page that registers this domain, where the host's own lookup and rules apply. */
    public static function registerUrl($domain)
    {
        return Settings::systemUrl() . 'cart.php?a=add&domain=register&query=' . rawurlencode((string) $domain);
    }
}
