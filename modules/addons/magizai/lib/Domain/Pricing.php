<?php

namespace MagizAI\Whmcs\Domain;

use MagizAI\Whmcs\Support\Cycles;
use MagizAI\Whmcs\Support\Money;
use MagizAI\Whmcs\Support\WhmcsApi;

/**
 * Prices as WHMCS holds them at the moment an action runs, in the client's own currency where
 * there is one: products, domain extensions and renewals. A figure the assistant mentioned earlier
 * in the conversation is not a price.
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class Pricing
{
    /**
     * What renewing a domain costs for each term it is sold for, in the client's currency.
     *
     * @return array{currency: string, prices: array<string, array{amount: string, price: string}>}
     */
    public static function domainRenewalPrices($domain, $clientId)
    {
        $table = self::tldPricing($clientId);
        $dot = strpos((string) $domain, '.');
        $key = $dot === false ? '' : ltrim(strtolower(substr((string) $domain, $dot)), '.');
        $c = $table['currency'];

        $prices = array();
        $byYears = isset($table['pricing'][$key]['renew']) && is_array($table['pricing'][$key]['renew']) ? $table['pricing'][$key]['renew'] : array();
        foreach ($byYears as $years => $value) {
            if (ctype_digit((string) $years) && (int) $years >= 1 && (int) $years <= 10 && is_numeric($value) && (float) $value >= 0) {
                $prices[(string) (int) $years] = array(
                    'amount' => (string) $value,
                    'price' => (isset($c['prefix']) ? $c['prefix'] : '') . $value . (isset($c['suffix']) ? $c['suffix'] : ''),
                );
            }
        }
        ksort($prices, SORT_NUMERIC);

        return array('currency' => isset($c['code']) ? (string) $c['code'] : '', 'prices' => $prices);
    }

    /**
     * What renewing a service costs, in the client's currency, for the confirmation MagizAI shows
     * before the client agrees. Null for a service with no recurring term.
     *
     * @return array{amount: string, price: string, currency: string, cycle: string}|null
     */
    public static function serviceRenewalPrice(array $p, array $currency)
    {
        $cycle = Cycles::cycleKey((string) $p['billingcycle']);
        if ($cycle === null || !isset($p['recurringamount']) || !is_numeric($p['recurringamount'])) {
            return null;
        }

        $amount = number_format((float) $p['recurringamount'], 2, '.', '');

        return array('amount' => $amount, 'price' => Money::money($amount, $currency), 'currency' => (string) $currency['code'], 'cycle' => $cycle);
    }

    public static function productPrice(array $p, $code)
    {
        if ($p['paytype'] === 'free') {
            return 'free';
        }
        if (!isset($p['pricing'][$code]) || !is_array($p['pricing'][$code])) {
            return null;
        }
        $pr = $p['pricing'][$code];

        if ($p['paytype'] === 'onetime') {
            return (float) $pr['monthly'] >= 0 ? $pr['prefix'] . $pr['monthly'] . $pr['suffix'] . ' one time' : null;
        }

        $cycles = array('monthly' => 'month', 'quarterly' => '3 months', 'semiannually' => '6 months', 'annually' => 'year', 'biennially' => '2 years', 'triennially' => '3 years');
        $parts = array();
        foreach ($cycles as $key => $label) {
            if (isset($pr[$key]) && (float) $pr[$key] >= 0) {
                $parts[] = $pr['prefix'] . $pr[$key] . $pr['suffix'] . '/' . $label;
            }
        }

        return $parts ? implode(' or ', array_slice($parts, 0, 2)) : null;
    }

    /**
     * GetTLDPricing answers already fetched in this request, by whom they were priced for.
     *
     * @var array<string, array{pricing: array, currency: array}>
     */
    private static $tldPricing = array();

    /**
     * Domain prices for this client, or the public ones.
     *
     * They always came in the host's DEFAULT currency, so a client billed in euros was quoted the
     * dollar price for a domain whose invoice then arrived in euros, for a different amount. Given
     * the client, WHMCS prices for them: their currency, and their client group's prices.
     *
     * The key needs no lookup, so the many prices one answer reads (three per extension, up to six
     * extensions for a domain check) cost one GetTLDPricing call and one currency lookup, not a
     * currency lookup each. WHMCS takes `clientid` or `currencyid`, "not both": a client brings
     * their own currency, and anyone else gets the default one.
     */
    public static function tldPricing($clientId = null)
    {
        $key = $clientId ? 'client:' . (int) $clientId : 'public';

        if (!isset(self::$tldPricing[$key])) {
            $currency = $clientId ? Money::clientCurrency($clientId) : Money::defaultCurrency();
            $params = $clientId ? array('clientid' => (int) $clientId) : array('currencyid' => (int) $currency['id']);

            $r = WhmcsApi::api('GetTLDPricing', $params, false);
            self::$tldPricing[$key] = array(
                'pricing' => isset($r['pricing']) && is_array($r['pricing']) ? $r['pricing'] : array(),
                'currency' => isset($r['currency']) && is_array($r['currency']) ? $r['currency'] + $currency : $currency,
            );
        }

        return self::$tldPricing[$key];
    }

    /**
     * One extension's price as a number string ("12.00") for $years years, or null when it is not
     * sold for that term. For one year, the shortest term sold stands in, as WHMCS's own cart does
     * for an extension sold for two years at least.
     */
    public static function tldAmount($tld, $kind, $clientId = null, $years = 1)
    {
        $table = self::tldPricing($clientId);
        $key = ltrim(strtolower((string) $tld), '.');

        if (!isset($table['pricing'][$key][$kind]) || !is_array($table['pricing'][$key][$kind])) {
            return null;
        }

        $byYears = $table['pricing'][$key][$kind];
        if (isset($byYears[(string) (int) $years])) {
            $value = $byYears[(string) (int) $years];
        } else {
            $value = (int) $years === 1 ? reset($byYears) : null;
        }

        return is_numeric($value) && (float) $value >= 0 ? (string) $value : null;
    }

    /** One extension's price, formatted in the currency it was priced in, or null. */
    public static function tldPrice($tld, $kind, $clientId = null, $years = 1)
    {
        $amount = self::tldAmount($tld, $kind, $clientId, $years);
        if ($amount === null) {
            return null;
        }

        $c = self::tldPricing($clientId)['currency'];

        return (isset($c['prefix']) ? $c['prefix'] : '') . $amount . (isset($c['suffix']) ? $c['suffix'] : '');
    }

    /** Forget what was remembered for the request that ran last. @see \MagizAI\Whmcs\Action\Runner::forget */
    public static function forget()
    {
        self::$tldPricing = array();
    }
}
