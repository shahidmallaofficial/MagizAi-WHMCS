<?php

namespace MagizAI\Whmcs\Support;

use MagizAI\Whmcs\ActionError;

/**
 * The arguments MagizAI sends, read the way every action reads them: trimmed, tag-free, bounded,
 * and refused in the client's terms when they cannot be used.
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class Args
{
    /**
     * The purposes service_details may be asked for. Anything else is ignored. @see \MagizAI\Whmcs\Domain\Ownership::ownedService
     *
     * NOT "cancel". A cancellation is never pointed at a service the client did not name: "cancel
     * my free trial" would otherwise pick the paid line, and MagizAI's confirmation names no service
     * to catch it. With several live services a cancellation always asks which one, the free trial
     * included, so purpose=cancel is ignored like any other unknown purpose.
     */
    public static function purposeArg(array $args)
    {
        $purpose = isset($args['purpose']) && is_scalar($args['purpose']) ? strtolower(trim((string) $args['purpose'])) : '';

        return in_array($purpose, array('renew', 'upgrade', 'addon'), true) ? $purpose : '';
    }

    public static function text(array $args, $key, $max)
    {
        $value = isset($args[$key]) && is_scalar($args[$key]) ? trim(strip_tags((string) $args[$key])) : '';

        return mb_substr($value, 0, $max);
    }

    public static function digits(array $args, $key)
    {
        $value = isset($args[$key]) ? ltrim(trim((string) $args[$key]), '#') : '';
        if (!ctype_digit($value) || strlen($value) > 10) {
            throw new ActionError('invalid_args', 'Ask for the number.');
        }

        return (int) $value;
    }

    public static function domainArg(array $args)
    {
        $host = isset($args['domain']) ? self::hostname((string) $args['domain']) : null;
        if ($host === null) {
            throw new ActionError('invalid_args', 'Ask for the full domain name, for example example.com.');
        }

        return $host;
    }

    public static function hostname($value)
    {
        $v = strtolower(trim((string) $value));
        $v = preg_replace('#^[a-z]+://#', '', $v);
        $v = explode('/', $v);
        $v = rtrim($v[0], '.');

        if (function_exists('idn_to_ascii') && preg_match('/[^\x20-\x7e]/', $v)) {
            $ascii = idn_to_ascii($v, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            $v = is_string($ascii) ? $ascii : $v;
        }

        return strlen($v) <= 253 && preg_match('/^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $v) ? $v : null;
    }
}
