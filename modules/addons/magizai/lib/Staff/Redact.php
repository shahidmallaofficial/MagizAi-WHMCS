<?php

namespace MagizAI\Whmcs\Staff;

/**
 * What never leaves WHMCS in a ticket's text (1.10.0): payment card numbers, private keys, and the value
 * after a password, PIN, CVV, API key, token, secret or transfer (EPP) code label. Applied to every
 * message and to the staff member's draft before the ticket assistant sends them to MagizAI, which
 * removes the same again before anything reaches a model.
 *
 * Text only ever loses what looks secret: a card-shaped number must pass the Luhn check, so order and
 * invoice numbers stay.
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class Redact
{
    const LABELLED = '/\b(pass(?:word|wd|phrase)?|pwd|pin|cvv|cvc|security code|api[ _-]?key|secret|token|auth(?:orisation|orization)? code|epp(?: code)?|private key)\b(\s*(?:is|:|=|-)\s*)\S+/iu';

    const KEY_BLOCK = '/-----BEGIN [A-Z ]*PRIVATE KEY-----.*?-----END [A-Z ]*PRIVATE KEY-----/s';

    public static function text($text)
    {
        $text = (string) preg_replace(self::KEY_BLOCK, '[private key removed]', (string) $text);
        $text = (string) preg_replace_callback('/\b(?:\d[ -]?){12,18}\d\b/', function ($m) {
            $digits = preg_replace('/\D/', '', $m[0]);

            return strlen($digits) >= 13 && strlen($digits) <= 19 && self::luhn($digits) ? '[card ending ' . substr($digits, -4) . ']' : $m[0];
        }, $text);

        return (string) preg_replace(self::LABELLED, '$1$2[removed]', $text);
    }

    private static function luhn($digits)
    {
        $sum = 0;
        $alt = false;
        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            $n = (int) $digits[$i];
            if ($alt) {
                $n *= 2;
                if ($n > 9) {
                    $n -= 9;
                }
            }
            $sum += $n;
            $alt = !$alt;
        }

        return $sum % 10 === 0;
    }
}
