<?php

namespace MagizAI\Whmcs\Support;

use MagizAI\Whmcs\ActionError;
use MagizAI\Whmcs\Text;

/**
 * WHMCS's internal API, as every action calls it: failures turned into refusals or exceptions, and
 * secrets kept out of WHMCS's module log.
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class WhmcsApi
{
    /**
     * localAPI with errors turned into exceptions.
     *
     * @param bool     $throw   false to get the raw response back even on error
     * @param string[] $secrets values that must never reach WHMCS's module log, on top of the
     *                          password and transfer-code fields this always masks
     */
    public static function api($command, array $params, $throw = true, $errorCode = 'internal', array $secrets = array())
    {
        $r = localAPI($command, $params);
        if (!is_array($r)) {
            $r = array('result' => 'error', 'message' => 'No response');
        }

        if ($throw && ($r['result'] ?? '') !== 'success') {
            $message = (string) ($r['message'] ?? 'WHMCS error');
            $mask = self::secretValues($params, $secrets);
            if (function_exists('logModuleCall')) {
                // WHMCS's module log masks by EXACT value: each string listed here is replaced with
                // asterisks wherever it appears. The request and the answer are masked here first,
                // in every escaped form (Text::secretForms), so a password or transfer code never
                // sits in the log in clear whatever WHMCS's own masking does.
                logModuleCall('magizai', $command, Text::maskDeep($params, $mask), Text::maskDeep($r, $mask), '', $mask);
            }

            /*
             * A registrar's refusal ("domain is locked", "invalid nameserver") is meaningful to the
             * client and is passed on. One about the host's own systems (a reseller account, an IP
             * allow-list, credentials, a timeout) is not: the client is told it did not work, and
             * staff get the registrar's own words in the activity log.
             */
            if ($errorCode === 'registrar_error') {
                $said = Text::customerSafe($message, '');
                throw new ActionError('registrar_error', $said !== ''
                    ? 'The registrar refused the request: ' . $said
                    : 'The registrar could not complete the request just now. Nothing was changed. Offer to pass this to the team.',
                    $command . ': ' . Text::mask(Text::cut(strip_tags($message), 500), $mask));
            }
            throw new \RuntimeException($command . ': ' . Text::mask($message, $mask));
        }

        return $r;
    }

    /**
     * The values in a request that must never be logged: known secret fields, plus the caller's,
     * each in every form it can take (raw, entity-decoded, escaped).
     */
    public static function secretValues(array $params, array $extra)
    {
        $values = $extra;
        foreach (array('password', 'password2', 'servicepassword', 'eppcode') as $key) {
            if (isset($params[$key]) && is_scalar($params[$key])) {
                $values[] = (string) $params[$key];
            }
        }

        return Text::secretForms($values);
    }

    /** WHMCS nests lists as {"domains": {"domain": [...]}}; an empty list may be "" or missing. */
    public static function rows($response, $outer, $inner)
    {
        if (!is_array($response) || !isset($response[$outer][$inner]) || !is_array($response[$outer][$inner])) {
            return array();
        }

        return array_values($response[$outer][$inner]);
    }
}
