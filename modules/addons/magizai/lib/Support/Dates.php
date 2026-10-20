<?php

namespace MagizAI\Whmcs\Support;

/**
 * WHMCS dates as a person should hear them, and as timestamps for comparing them.
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class Dates
{
    /** A WHMCS date as a timestamp, or 0 when it is empty or WHMCS's "0000-00-00". */
    public static function timestamp($value)
    {
        $value = (string) $value;
        $ts = strtotime($value);

        return $ts && strpos($value, '0000') !== 0 ? (int) $ts : 0;
    }

    /**
     * A due date as a person should hear it: "1 Mar 2026 — PASSED, overdue" rather than a bare date.
     *
     * A bare past date was read out as "your next renewal date is 1 March 2026" — in September, to
     * a customer whose service had lapsed six months earlier. The assistant has no clock of its own to
     * catch that; the data has to say it.
     */
    public static function dueLabel($value)
    {
        $label = self::date($value);
        $ts = strtotime((string) $value);

        if ($label === 'n/a' || !$ts) {
            return $label;
        }

        return $ts < strtotime('today') ? $label . ' — PASSED, overdue' : $label;
    }

    public static function date($value)
    {
        $ts = strtotime((string) $value);

        return $ts && strpos((string) $value, '0000') !== 0 ? date('j M Y', $ts) : 'n/a';
    }
}
