<?php

namespace MagizAI\Whmcs\Domain;

use MagizAI\Whmcs\ActionError;
use MagizAI\Whmcs\Schema;
use MagizAI\Whmcs\Support\Args;
use MagizAI\Whmcs\Support\Cycles;
use MagizAI\Whmcs\Support\Money;
use MagizAI\Whmcs\Support\WhmcsApi;
use WHMCS\Database\Capsule;

/**
 * What an order may be placed for: a product on sale right now, a billing term it is really sold
 * on, a payment method the host has switched on, and the price the client was quoted.
 *
 * The product, term and price are re-read from WHMCS when the order runs, never trusted from the
 * conversation.
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class OrderRules
{
    /**
     * The product being ordered, resolved from WHMCS and checked to be orderable right now.
     *
     * Accepts an id when the model has one from the catalogue, and a name when it does not. A name
     * that matches more than one product is refused rather than guessed: ordering the wrong plan
     * costs the client money and the host a refund.
     */
    public static function orderableProduct(array $args, array $currency = array())
    {
        $wanted = Args::text($args, 'product', 120);
        if ($wanted === '') {
            throw new ActionError('invalid_args', 'Ask the client which plan they want, then order it by name.');
        }

        /*
         * THE CLIENT'S CURRENCY, because that is the one WHMCS will invoice in.
         *
         * WHMCS stores a separate price row per currency, so this is a matter of reading the right
         * row — never of converting one. A converted figure is a number nobody will ever be
         * charged, and it would sit in the sentence the client confirms.
         */
        $currency = $currency ? $currency : Money::defaultCurrency();
        $pricedElsewhere = false;
        $hasRetired = Schema::hasColumn('tblproducts', 'retired');
        $hidden = Capsule::table('tblproducts')->where(function ($w) use ($hasRetired) {
            $w->where('hidden', 1);
            if ($hasRetired) {
                $w->orWhere('retired', 1);
            }
        })->pluck('id');
        $hidden = array_map('intval', is_array($hidden) ? $hidden : $hidden->all());

        $hiddenGroups = array();
        foreach (Capsule::table('tblproductgroups')->get(array('id', 'hidden')) as $g) {
            if ($g->hidden) {
                $hiddenGroups[] = (int) $g->id;
            }
        }

        $wantedId = ctype_digit($wanted) ? (int) $wanted : 0;
        $needle = mb_strtolower($wanted);
        $matches = array();

        foreach (WhmcsApi::rows(WhmcsApi::api('GetProducts', array()), 'products', 'product') as $p) {
            $pid = (int) $p['pid'];
            if (in_array($pid, $hidden, true) || in_array((int) $p['gid'], $hiddenGroups, true)) {
                continue;
            }

            $name = mb_strtolower((string) $p['name']);
            $isMatch = $wantedId > 0 ? ($pid === $wantedId) : ($name === $needle || mb_strpos($name, $needle) !== false);
            if (!$isMatch) {
                continue;
            }

            $price = Pricing::productPrice($p, $currency['code']);
            if ($price === null) {
                // The plan exists and matches the name; it simply has no price in this client's
                // currency. Saying "there is no such plan" would be a lie, so remember and explain.
                $pricedElsewhere = true;

                continue;
            }

            $p['currency'] = $currency;
            /*
             * DOES THIS PRODUCT NEED A DOMAIN AT ALL?
             *
             * WHMCS already knows — `tblproducts.showdomainoptions` is the "Require Domain" tick on
             * the product. Everything here used to assume yes, which is true of shared hosting and
             * false of a very large amount of what WHMCS is actually used to sell: IPTV and
             * streaming subscriptions, licences, VPN plans, SSL, support retainers, top-ups. For all
             * of those an order could never be placed, because the assistant was made to ask for a
             * domain the customer does not have and the product does not want.
             *
             * Read per product rather than per merchant: a host that sells hosting AND IPTV needs
             * both answers on the same install, so a vertical setting could never be right.
             */
            $p['requires_domain'] = Products::productRequiresDomain($pid);
            $matches[] = $p;

            if ($wantedId > 0) {
                break;
            }
        }

        if (!$matches) {
            if ($pricedElsewhere) {
                throw new ActionError('not_allowed', '"' . $wanted . '" is not priced in ' . $currency['code']
                    . ', which is the currency on this account, so it cannot be ordered here. '
                    . 'Offer to pass this to the team, who can price it or change the account currency.');
            }

            throw new ActionError('invalid_args', 'There is no plan on sale called "' . $wanted . '". Show the client the catalogue and order the one they pick.');
        }

        if (count($matches) > 1) {
            $names = array();
            foreach (array_slice($matches, 0, 5) as $m) {
                $names[] = $m['name'];
            }
            throw new ActionError('invalid_args', 'That matches more than one plan (' . implode(', ', $names) . '). Ask the client which one, then order it by its exact name.');
        }

        /*
         * OUT OF STOCK, by WHMCS's own stock control: refused here, before a domain is looked up or a
         * term asked for, rather than by WHMCS after the client confirmed. @see Offers::offer
         */
        $flags = Products::productFlags((int) $matches[0]['pid']);
        if ($flags['stock_control'] && $flags['quantity'] !== null && $flags['quantity'] <= 0) {
            throw new ActionError('not_allowed', $matches[0]['name'] . ' is out of stock right now, so it cannot be ordered. '
                . 'Offer another plan from the catalogue, or to pass this to the team.');
        }

        return $matches[0];
    }

    /** The billing term being ordered, checked against the terms this product actually offers. */
    public static function orderableCycle(array $product, array $args)
    {
        $offer = Offers::offer($product, null, $product['currency']);
        if (!$offer || empty($offer['cycles'])) {
            throw new ActionError('not_allowed', $product['name'] . ' cannot be ordered online. Offer to pass this to the team.');
        }

        $wanted = Cycles::billingCycle(Args::text($args, 'billing_cycle', 30));

        if ($wanted !== '') {
            foreach ($offer['cycles'] as $c) {
                if ($c['cycle'] === $wanted) {
                    return $c;
                }
            }

            $available = array();
            foreach ($offer['cycles'] as $c) {
                $available[] = Cycles::cycleLabel($c['cycle']);
            }
            throw new ActionError('invalid_args', $product['name'] . ' is not sold ' . Cycles::cycleLabel($wanted)
                . '. It is available ' . implode(', ', $available) . '. Ask the client which they want.');
        }

        /*
         * No term asked for. With exactly ONE on sale — a one-off payment, say — that is the term;
         * asking "how long do you want to pay for?" with a single answer is a question with no
         * question in it. With several, the term decides the bill (a year is twelve months' money),
         * so it is asked for, with the real terms to choose from, and never picked for them.
         */
        if (count($offer['cycles']) === 1) {
            return $offer['cycles'][0];
        }

        $available = array();
        foreach ($offer['cycles'] as $c) {
            $available[] = Cycles::cycleLabel($c['cycle']) . ' (' . Money::money($c['price'], $product['currency']) . ')';
        }
        throw new ActionError('invalid_args', $product['name'] . ' is sold ' . implode(', ', $available)
            . '. Offer these as choices and ask which one they want.');
    }

    /**
     * The gateway the invoice should be raised against.
     *
     * The client's own default first, because that is the one they have used before. WHMCS requires
     * a payment method on every order, and an order raised against a gateway the host has switched
     * off produces an invoice nobody can pay.
     */
    public static function orderPaymentMethod($clientId)
    {
        $active = array();
        foreach (Capsule::table('tblpaymentgateways')->where('setting', 'visible')->where('value', 'on')->pluck('gateway') as $g) {
            $active[] = (string) $g;
        }
        $active = array_values(array_unique($active));

        $clientDefault = (string) Capsule::table('tblclients')->where('id', (int) $clientId)->value('defaultgateway');
        if ($clientDefault !== '' && in_array($clientDefault, $active, true)) {
            return $clientDefault;
        }

        if ($active) {
            return $active[0];
        }

        throw new ActionError('not_allowed', 'No payment method is switched on, so an order cannot be raised. Offer to pass this to the team.');
    }

    /**
     * Refuse when the price has changed since the client was shown it.
     *
     * MagizAI may pass the figure the confirmation showed (`quoted_amount`, a plain number in the
     * client's currency). WHMCS invoices at the price it holds when the order is placed, and a client
     * who agreed to one figure has not agreed to another. Without it, nothing is compared.
     */
    public static function mustMatchQuote(array $args, $amount, $shown)
    {
        $quoted = isset($args['quoted_amount']) && is_scalar($args['quoted_amount']) ? trim((string) $args['quoted_amount']) : '';
        if ($quoted === '' || !is_numeric($quoted)) {
            return;
        }

        if ($amount === null) {
            throw new ActionError('not_allowed', 'The price could not be confirmed just now, so nothing was ordered. Offer to pass this to the billing team.');
        }

        if (abs((float) $quoted - (float) $amount) >= 0.005) {
            throw new ActionError('not_allowed', 'The price has changed since it was quoted: it is now ' . $shown . '. Nothing was ordered. '
                . 'Tell the client the new price and ask whether they still want it.');
        }
    }
}
