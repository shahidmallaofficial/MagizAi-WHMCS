<?php

namespace MagizAI\Whmcs\Domain;

use MagizAI\Whmcs\ActionError;
use MagizAI\Whmcs\Compat;
use MagizAI\Whmcs\Schema;
use MagizAI\Whmcs\Support\Cycles;
use MagizAI\Whmcs\Support\Format;
use MagizAI\Whmcs\Support\Money;
use MagizAI\Whmcs\Support\WhmcsApi;
use MagizAI\Whmcs\Text;
use WHMCS\Database\Capsule;

/**
 * Growing an account: the plans a service could move to, what WHMCS says the move costs today,
 * and the extras sold alongside it.
 *
 * Every one of these ends in an invoice the client pays on the host's own site. None of them takes
 * a payment, and none of them changes what the client is charged without an invoice saying so
 * first. The prices always come from WHMCS at the moment the action runs.
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class Upgrades
{
    /** @return array<string, array{identity: bool, write: bool, label: string, group: string}> */
    /** How many upgrade options one request will price. Each one costs an API call. */
    const MAX_UPGRADE_QUOTES = 6;

    /**
     * The plans a service could move to: everything on sale in the same product group, minus itself.
     *
     * The group is the host's own grouping of comparable plans, which is a better answer than any
     * rule the module could invent about what counts as an upgrade.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function upgradeTargets(array $service, array $currency = array())
    {
        $gid = Products::productFlags((int) $service['pid'])['gid'];
        if ($gid <= 0) {
            return array();
        }

        if (Products::groupHidden($gid)) {
            return array();
        }

        /*
         * THE CLIENT'S OWN CURRENCY, not the host's default.
         *
         * This read the default currency's price row and the callers then printed that number
         * with the CLIENT's symbol, so a client billed in euros was shown the dollar figure
         * labelled as euros — in the sentence they confirm before an invoice is raised. The
         * pro-rata figure beside it came from WHMCS and WAS in their currency, so the two halves
         * of the same sentence were in different units.
         */
        $currency = $currency ? $currency : Money::defaultCurrency();
        $cycle = Cycles::cycleKey((string) $service['billingcycle']);
        $out = array();

        foreach (WhmcsApi::rows(WhmcsApi::api('GetProducts', array('gid' => $gid)), 'products', 'product') as $p) {
            if ((int) $p['pid'] === (int) $service['pid']) {
                continue;
            }
            if (Products::isOffSale((int) $p['pid'])) {
                continue;
            }

            // Only a plan sold on the SAME term can be moved to without also changing the term,
            // and a silent term change is how a monthly client ends up with a yearly invoice.
            $pricing = isset($p['pricing'][$currency['code']]) && is_array($p['pricing'][$currency['code']]) ? $p['pricing'][$currency['code']] : null;
            if ($pricing === null || $cycle === null || !isset($pricing[$cycle]) || (float) $pricing[$cycle] < 0) {
                continue;
            }

            $out[] = array('pid' => (int) $p['pid'], 'name' => (string) $p['name'], 'recurring' => (float) $pricing[$cycle]);
        }

        return $out;
    }

    /**
     * A one-time purchase or a free account is not upgraded in place: the client orders the bigger
     * plan. Said before anything is priced.
     *
     * WHMCS prices an upgrade against the billing cycle, and neither has one, so every candidate
     * failed to price and this reported "there is nothing to upgrade to", to a streaming customer on
     * 3 months while 6- and 12-month plans sat in the same catalogue.
     */
    public static function mustBeUpgradeable(array $p)
    {
        $cycle = strtolower(trim((string) $p['billingcycle']));

        if ($cycle === 'free account' || $cycle === 'free') {
            throw new ActionError('not_allowed', $p['name'] . ' is a free account (a free trial, for example), so it is not upgraded in place. '
                . 'To move to a paid plan, the client orders that plan. Show them the plans and offer to place the order.');
        }

        if ($cycle === 'one time' || $cycle === 'onetime') {
            throw new ActionError('not_allowed', $p['name'] . ' was bought as a one-time payment, so it is not upgraded in place. '
                . 'To move to a bigger plan, the client orders that plan. Show them the plans and offer to place the order.');
        }
    }

    /**
     * WHMCS's own pro-rata figure for a change, or null when it cannot be priced with certainty.
     *
     * WHMCS answers with a FORMATTED price, "$-8.67 USD" in its own documentation, and a (float)
     * cast of that is 0.0: every quote read "$0.00 to change now", and a downgrade credit lost its
     * sign. It is now read with the client's currency (Compat::priceValue), and a figure that
     * cannot be read means no quote at all, never a quote of nothing.
     *
     * THE GATEWAY IS ONE THE HOST HAS SWITCHED ON. This used to send 'banktransfer' on every quote,
     * on the belief that a calculation ignores it. WHMCS's API answers "Invalid Payment Method" for a
     * gateway that is not active, and many hosts (IPTV merchants on Stripe, PayPal or crypto above
     * all) have no Bank Transfer, so every quote could fail and no upgrade could ever be offered.
     *
     * @param string $paymentMethod the gateway the upgrade would be raised against
     * @param string $error         WHMCS's own message when it refused to price the change
     * @return array{amount: float, shown: string}|null shown is WHMCS's own text when it sent one
     */
    public static function upgradeQuote($serviceId, $newPid, $cycle, array $currency, $paymentMethod, &$error = '')
    {
        $error = '';
        $cycleKey = Cycles::cycleKey($cycle);
        if ($cycleKey === null) {
            return null;
        }

        $r = WhmcsApi::api('UpgradeProduct', array(
            'serviceid' => (int) $serviceId,
            'type' => 'product',
            'newproductid' => (int) $newPid,
            'newproductbillingcycle' => $cycleKey,
            'paymentmethod' => (string) $paymentMethod,
            'calconly' => true,
        ), false);

        if (($r['result'] ?? '') !== 'success' || !isset($r['price'])) {
            $error = trim((string) ($r['message'] ?? ''));

            return null;
        }

        $amount = Compat::priceValue($r['price'], $currency);
        if ($amount === null) {
            return null;
        }

        // WHMCS's own wording of the figure, kept when it is a plain formatted price, so the
        // client reads the number exactly as the invoice will show it. Decoded: a symbol stored as
        // "&pound;" is read out as "£".
        $shown = is_string($r['price']) ? Text::cut(html_entity_decode(strip_tags($r['price']), ENT_QUOTES | ENT_HTML5, 'UTF-8'), 40) : '';
        if ($shown === '' || is_numeric($shown) || $amount < 0) {
            $shown = '';
        }

        return array('amount' => $amount, 'shown' => $shown);
    }

    /** "$7.50 USD to change now", or "a credit of $8.67 USD now" for a move that costs less. */
    public static function quoteText(array $quote, array $currency)
    {
        if ($quote['amount'] < 0) {
            return 'a credit of ' . Money::money(abs($quote['amount']), $currency) . ' now';
        }

        return ($quote['shown'] !== '' ? $quote['shown'] : Money::money($quote['amount'], $currency)) . ' to change now';
    }

    /**
     * The extras on sale for one product.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function addonsFor($pid, array $currency)
    {
        $out = array();

        // Hidden and RETIRED extras are both off sale: a retired one is kept only for the services
        // that already have it, and the client area never offers it.
        $query = Capsule::table('tbladdons')->where('hidden', 0);
        if (Schema::hasColumn('tbladdons', 'retired')) {
            $query->where('retired', 0);
        }

        foreach ($query->get() as $a) {
            // `packages` is a comma list of the products an addon belongs to; empty means all.
            $packages = array_filter(array_map('intval', explode(',', (string) $a->packages)));
            if ($packages && !in_array((int) $pid, $packages, true)) {
                continue;
            }

            $pricing = Capsule::table('tblpricing')
                ->where('type', 'addon')->where('relid', (int) $a->id)->where('currency', (int) $currency['id'])
                ->first();
            if (!$pricing) {
                continue;
            }

            $cycle = Cycles::addonCycle($a, $pricing);
            if ($cycle === null) {
                continue;
            }

            $out[] = array(
                'id' => (int) $a->id,
                'name' => (string) $a->name,
                'description' => Format::plain((string) $a->description),
                'price' => (float) $pricing->{$cycle[0]},
                'setup' => max(0.0, (float) $pricing->{$cycle[1]}),
                'cycle' => $cycle[2],
            );
        }

        return $out;
    }

    /**
     * One match from a list of {name}, or a refusal that asks rather than guesses.
     *
     * @param  array<int, array<string, mixed>>  $candidates
     * @return array<string, mixed>
     */
    public static function matchOne(array $candidates, $wanted, $noun)
    {
        $needle = mb_strtolower(trim((string) $wanted));
        $exact = array();
        $partial = array();

        foreach ($candidates as $c) {
            $name = mb_strtolower((string) $c['name']);
            if ($name === $needle) {
                $exact[] = $c;
            } elseif ($needle !== '' && mb_strpos($name, $needle) !== false) {
                $partial[] = $c;
            }
        }

        $matches = $exact ?: $partial;

        if (!$matches) {
            $names = array();
            foreach (array_slice($candidates, 0, 6) as $c) {
                $names[] = $c['name'];
            }

            throw new ActionError('invalid_args', 'There is no ' . $noun . ' called "' . Format::cut($wanted, 60) . '" here.'
                . ($names ? ' The choices are: ' . implode(', ', $names) . '.' : '') . ' Ask which one they mean.');
        }

        if (count($matches) > 1) {
            $names = array();
            foreach (array_slice($matches, 0, 5) as $m) {
                $names[] = $m['name'];
            }

            throw new ActionError('invalid_args', 'That matches more than one ' . $noun . ' (' . implode(', ', $names) . '). Ask which one, then use its exact name.');
        }

        return $matches[0];
    }
}
