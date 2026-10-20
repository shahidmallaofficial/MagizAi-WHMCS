<?php

namespace MagizAI\Whmcs\Support;

use WHMCS\Database\Capsule;

/**
 * Currencies and amounts: the host's default currency, the client's own, and a price written the
 * way the client's currency writes it.
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class Money
{
    public static function defaultCurrency()
    {
        $c = Capsule::table('tblcurrencies')->where('default', 1)->first();

        return $c ? self::currencyRow($c) : array('id' => 1, 'code' => 'USD', 'prefix' => '$', 'suffix' => '', 'format' => 0);
    }

    public static function clientCurrency($clientId)
    {
        $id = (int) Capsule::table('tblclients')->where('id', $clientId)->value('currency');
        $c = $id ? Capsule::table('tblcurrencies')->where('id', $id)->first() : null;

        return $c ? self::currencyRow($c) : self::defaultCurrency();
    }

    /** One tblcurrencies row, with the number format WHMCS writes its amounts in (0 when unknown). */
    public static function currencyRow($c)
    {
        return array(
            'id' => (int) $c->id, 'code' => $c->code, 'prefix' => $c->prefix, 'suffix' => $c->suffix,
            'format' => isset($c->format) ? (int) $c->format : 0,
        );
    }

    public static function money($amount, array $currency)
    {
        return $currency['prefix'] . number_format((float) $amount, 2) . $currency['suffix'];
    }
}
