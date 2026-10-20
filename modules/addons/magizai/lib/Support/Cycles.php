<?php

namespace MagizAI\Whmcs\Support;

/**
 * WHMCS billing terms: the pricing columns of each, how a person names one, and how the client
 * hears it.
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class Cycles
{
    /** WHMCS billing cycle keys, their length in months, and the setup-fee column that goes with each. */
    const TERMS = array(
        'monthly' => array(1, 'msetupfee'),
        'quarterly' => array(3, 'qsetupfee'),
        'semiannually' => array(6, 'ssetupfee'),
        'annually' => array(12, 'asetupfee'),
        'biennially' => array(24, 'bsetupfee'),
        'triennially' => array(36, 'tsetupfee'),
    );

    public static function cycleLabel($cycle)
    {
        $labels = array('monthly' => 'per month', 'quarterly' => 'per 3 months', 'semiannually' => 'per 6 months',
            'annually' => 'per year', 'biennially' => 'per 2 years', 'triennially' => 'per 3 years',
            'onetime' => 'one time', 'free' => 'free');

        return isset($labels[$cycle]) ? $labels[$cycle] : $cycle;
    }

    /**
     * Any way a person names a billing term, as the WHMCS cycle key. Kept identical to MagizAI's
     * WhmcsConnector::billingCycle, so "One time", "one-time", "per year" and "6 months" all land
     * on the right term whichever side reads them first.
     */
    public static function billingCycle($value)
    {
        $v = strtolower(trim((string) $value));
        $v = preg_replace('/^(per|every|each|a|an|for)\s+/', '', $v);
        $v = preg_replace('/[\s_\-]+/', '', (string) $v);

        $aliases = array(
            'onetime' => 'onetime', 'once' => 'onetime', 'oneoff' => 'onetime', 'single' => 'onetime', 'lifetime' => 'onetime',
            'free' => 'free', 'freeaccount' => 'free',
            'monthly' => 'monthly', 'month' => 'monthly', '1month' => 'monthly', '1' => 'monthly', 'mo' => 'monthly',
            'quarterly' => 'quarterly', 'quarter' => 'quarterly', '3months' => 'quarterly', '3month' => 'quarterly', '3' => 'quarterly',
            'semiannually' => 'semiannually', 'semiannual' => 'semiannually', 'halfyearly' => 'semiannually',
            'halfyear' => 'semiannually', '6months' => 'semiannually', '6month' => 'semiannually', '6' => 'semiannually',
            'annually' => 'annually', 'annual' => 'annually', 'yearly' => 'annually', 'year' => 'annually',
            '1year' => 'annually', '12months' => 'annually', '12' => 'annually',
            'biennially' => 'biennially', 'biennial' => 'biennially', '2years' => 'biennially', '2year' => 'biennially', '24months' => 'biennially', '24' => 'biennially',
            'triennially' => 'triennially', 'triennial' => 'triennially', '3years' => 'triennially', '3year' => 'triennially', '36months' => 'triennially', '36' => 'triennially',
        );

        return $v === '' ? '' : (isset($aliases[$v]) ? $aliases[$v] : $v);
    }

    /** The pricing column for a WHMCS billing cycle name ("Monthly" -> "monthly"), or null. */
    public static function cycleKey($billingCycle)
    {
        $map = array(
            'monthly' => 'monthly', 'quarterly' => 'quarterly', 'semi-annually' => 'semiannually',
            'semiannually' => 'semiannually', 'annually' => 'annually', 'biennially' => 'biennially',
            'triennially' => 'triennially',
        );
        $key = mb_strtolower(trim((string) $billingCycle));

        return isset($map[$key]) ? $map[$key] : null;
    }

    /**
     * The term an addon is sold on: the one its product says, falling back to the cheapest
     * enabled term. WHMCS marks a term it does not sell with -1.
     *
     * @return array{0: string, 1: string, 2: string}|null [price column, setup column, label]
     */
    public static function addonCycle($addon, $pricing)
    {
        $columns = array(
            'monthly' => array('monthly', 'msetupfee'),
            'quarterly' => array('quarterly', 'qsetupfee'),
            'semiannually' => array('semiannually', 'ssetupfee'),
            'annually' => array('annually', 'asetupfee'),
            'biennially' => array('biennially', 'bsetupfee'),
            'triennially' => array('triennially', 'tsetupfee'),
            'onetime' => array('monthly', 'msetupfee'),
        );

        $preferred = mb_strtolower(str_replace(array(' ', '-'), '', (string) $addon->billingcycle));
        if (isset($columns[$preferred]) && (float) $pricing->{$columns[$preferred][0]} >= 0) {
            return array($columns[$preferred][0], $columns[$preferred][1], $preferred);
        }

        foreach ($columns as $name => $pair) {
            if ($name !== 'onetime' && (float) $pricing->{$pair[0]} >= 0) {
                return array($pair[0], $pair[1], $name);
            }
        }

        return null;
    }
}
