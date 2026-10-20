<?php

namespace MagizAI\Whmcs;

/**
 * What differs between the WHMCS versions the module runs on, decided in one place.
 *
 * WHMCS reports its version as "9.0.8-release.1", and version_compare('9.0.8-release.1', '9.0.8',
 * '>=') is FALSE: every comparison on the raw string is wrong for the release it names. So the
 * version is always normalised first, and an UNKNOWN version is never treated as new enough.
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class Compat
{
    /** The first WHMCS release whose AddOrder takes `servicerenewals` (renewing a service early). */
    const SERVICE_RENEWALS_SINCE = '8.8.0';

    /** The first WHMCS release whose OpenTicket takes `userid`: the ticket is the user's, not only the account's. */
    const TICKET_USERID_SINCE = '8.5.0';

    /**
     * The WHMCS builds that fix CVE-2026-67399 (an unauthenticated remote code execution). There is
     * no fix for 8.0 to 8.12 short of upgrading, so this is a recommendation shown to the admin and
     * never a reason to stop working.
     */
    const SECURE_LTS = '8.13.7';
    const SECURE_CURRENT = '9.0.8';

    /** WHMCS's version as plain x.y.z ("9.0.8-release.1" becomes "9.0.8"), or '' when unknown. */
    public static function whmcsVersion()
    {
        try {
            $raw = (string) \WHMCS\Config\Setting::getValue('Version');
        } catch (\Throwable $e) {
            $raw = '';
        }

        return self::normaliseVersion($raw);
    }

    /** "9.0.8-release.1" → "9.0.8", "8.13" → "8.13.0", anything unreadable → ''. */
    public static function normaliseVersion($raw)
    {
        if (!preg_match('/^\s*v?(\d+)\.(\d+)(?:\.(\d+))?/', (string) $raw, $m)) {
            return '';
        }

        return (int) $m[1] . '.' . (int) $m[2] . '.' . (isset($m[3]) && $m[3] !== '' ? (int) $m[3] : 0);
    }

    /** True only when the version is KNOWN and at least $minimum. Unknown is not "new enough". */
    public static function whmcsAtLeast($minimum)
    {
        $version = self::whmcsVersion();

        return $version !== '' && version_compare($version, (string) $minimum, '>=');
    }

    /**
     * Does this WHMCS build carry the fix for CVE-2026-67399?
     *
     * @return bool|null null when the version is unknown
     */
    public static function hasSecurityFix($version = null)
    {
        $version = $version === null ? self::whmcsVersion() : self::normaliseVersion($version);
        if ($version === '') {
            return null;
        }

        return version_compare($version, '9.0.0', '>=')
            ? version_compare($version, self::SECURE_CURRENT, '>=')
            : version_compare($version, self::SECURE_LTS, '>=');
    }

    /**
     * A price WHMCS formatted for display ("$-8.67 USD", "1.234,50 €", "₹1,250.00 INR"), as a
     * number — or null when it cannot be read with certainty. NEVER 0 on failure: a quote of
     * "$0.00 to change now" is a promise the invoice will break.
     *
     * UpgradeProduct returns its pro-rata `price` this way (WHMCS's own example is "$-8.67 USD"),
     * and a (float) cast of that string is 0.0. WHMCS 9 may hand back a Price object instead; its
     * getValue() is a plain number, while toNumeric() is still formatted and is parsed like a
     * string.
     *
     * @param mixed $price
     * @param array $currency the client's currency: prefix, suffix, code and tblcurrencies.format
     *                        (1 = 1234.56, 2 = 1,234.56, 3 = 1.234,56, 4 = 1,234)
     * @return float|null
     */
    public static function priceValue($price, array $currency = array())
    {
        if (is_object($price)) {
            if (method_exists($price, 'getValue')) {
                $price = $price->getValue();
            } elseif (method_exists($price, 'toNumeric')) {
                $price = $price->toNumeric();
            } elseif (method_exists($price, '__toString')) {
                $price = (string) $price;
            } else {
                return null;
            }
        }

        if (is_int($price) || is_float($price)) {
            return is_finite((float) $price) ? (float) $price : null;
        }

        if (!is_string($price)) {
            return null;
        }

        $text = html_entity_decode(strip_tags($price), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\xC2\xA0", ' ', $text);

        /*
         * The client's own currency decoration, wherever WHMCS put it: "$-8.67" and "-$8.67" both.
         * Longest first, so a suffix " ZAR" goes before a prefix "R" can bite a letter out of it.
         *
         * Decoded like the price itself: many installs keep the symbol in tblcurrencies as an
         * entity ("&pound;", "&euro;"), while the decoded price says "£". The mark never matched,
         * so every quote in that currency was unreadable and every upgrade was refused.
         */
        $marks = array();
        foreach (array('suffix', 'prefix', 'code') as $key) {
            $raw = isset($currency[$key]) ? (string) $currency[$key] : '';
            foreach (array(html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8'), $raw) as $mark) {
                $mark = trim(str_replace("\xC2\xA0", ' ', $mark));
                if ($mark !== '') {
                    $marks[] = $mark;
                }
            }
        }
        $marks = array_values(array_unique($marks));
        usort($marks, function ($a, $b) {
            return strlen($b) - strlen($a);
        });
        $marked = 0;
        foreach ($marks as $mark) {
            $found = 0;
            $text = str_replace($mark, '', $text, $found);
            $marked += $found;
        }

        $text = (string) preg_replace('/\s+/', '', $text);

        // What is left must be a sign and a number. Anything else — another currency's symbol, a
        // word — means this is not a price in the client's currency, and guessing is how a quote
        // ends up wrong.
        if (!preg_match('/^([+-]?)(\d[\d.,]*)$/', $text, $m)) {
            return null;
        }

        /*
         * A BARE DECIMAL IS A DECIMAL. "7.50" with no currency mark is a plain number (an
         * unformatted amount), not a figure in the currency's display format: read by format 3
         * (1.234,56) it became 750. A grouped "1.234" still follows the format.
         */
        if ($marked === 0 && preg_match('/^\d+\.\d{1,2}$/', $m[2])) {
            $digits = $m[2];
        } else {
            $digits = self::decimalPoint($m[2], isset($currency['format']) ? (int) $currency['format'] : 0);
        }
        if ($digits === null) {
            return null;
        }

        $value = (float) $digits;

        return $m[1] === '-' ? -$value : $value;
    }

    /**
     * Rewrite "1.234,56" / "1,234.56" / "1,234" as "1234.56" / "1234.56" / "1234" by the
     * currency's WHMCS format, or by the separators themselves when the format is unknown.
     *
     * @return string|null
     */
    private static function decimalPoint($number, $format)
    {
        switch ($format) {
            case 1:
            case 2:
                $number = str_replace(',', '', $number);
                break;
            case 3:
                $number = str_replace(array('.', ','), array('', '.'), $number);
                break;
            case 4:
                $number = str_replace(array(',', '.'), '', $number);
                break;
            default:
                $lastDot = strrpos($number, '.');
                $lastComma = strrpos($number, ',');
                if ($lastDot !== false && $lastComma !== false) {
                    // Both: whichever comes last is the decimal separator.
                    $number = $lastComma > $lastDot
                        ? str_replace(array('.', ','), array('', '.'), $number)
                        : str_replace(',', '', $number);
                } elseif ($lastComma !== false) {
                    // Only commas: "7,50" is a decimal comma, "1,250" a thousands separator.
                    $number = preg_match('/^\d+,\d{1,2}$/', $number)
                        ? str_replace(',', '.', $number)
                        : str_replace(',', '', $number);
                }
        }

        return preg_match('/^\d+(\.\d+)?$/', $number) ? $number : null;
    }
}
