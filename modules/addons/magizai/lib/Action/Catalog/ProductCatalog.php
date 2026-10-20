<?php

namespace MagizAI\Whmcs\Action\Catalog;

use MagizAI\Whmcs\Action\ActionContext;
use MagizAI\Whmcs\Action\ActionInterface;
use MagizAI\Whmcs\Domain\Offers;
use MagizAI\Whmcs\Domain\Pricing;
use MagizAI\Whmcs\Schema;
use MagizAI\Whmcs\Support\Args;
use MagizAI\Whmcs\Support\Cycles;
use MagizAI\Whmcs\Support\Format;
use MagizAI\Whmcs\Support\Money;
use MagizAI\Whmcs\Support\WhmcsApi;
use WHMCS\Database\Capsule;

/**
 * The products on sale, with every billing term as a plan card, in the signed-in client's
 * currency or the host's default one.
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class ProductCatalog implements ActionInterface
{
    public function spec()
    {
        // 'optional': a signed-in client sees prices in THEIR currency, the one order_place charges.
        return array('identity' => 'optional', 'write' => false, 'group' => 'public', 'label' => 'Products and prices');
    }

    public function handle(ActionContext $context)
    {
        $args = $context->args();
        $clientId = $context->clientId();

        $query = mb_strtolower(Args::text($args, 'query', 120));

        /*
         * ONE currency for both the filter and the output.
         *
         * These used to differ: products were kept or dropped on whether they had a price in the
         * HOST's default currency, then printed in the CLIENT's. A plan priced only in the
         * client's own currency disappeared from their catalogue, and one priced only in the
         * default was shown to them with the wrong symbol.
         */
        $currency = $clientId ? Money::clientCurrency($clientId) : Money::defaultCurrency();

        // `retired` only exists on newer WHMCS versions.
        $hasRetired = Schema::hasColumn('tblproducts', 'retired');
        $hidden = Capsule::table('tblproducts')->where(function ($w) use ($hasRetired) {
            $w->where('hidden', 1);
            if ($hasRetired) {
                $w->orWhere('retired', 1);
            }
        })->pluck('id');
        $hidden = array_map('intval', is_array($hidden) ? $hidden : $hidden->all());

        $groups = array();
        foreach (Capsule::table('tblproductgroups')->get(array('id', 'name', 'hidden')) as $g) {
            $groups[(int) $g->id] = $g;
        }

        $r = WhmcsApi::api('GetProducts', array());
        $candidates = array();
        foreach (WhmcsApi::rows($r, 'products', 'product') as $p) {
            $group = isset($groups[(int) $p['gid']]) ? $groups[(int) $p['gid']] : null;
            if (in_array((int) $p['pid'], $hidden, true) || ($group && $group->hidden)) {
                continue;
            }

            $price = Pricing::productPrice($p, $currency['code']);
            if ($price === null) {
                continue;
            }

            $haystack = mb_strtolower($p['name'] . ' ' . ($group ? $group->name : '') . ' ' . Format::plain($p['description']));
            $score = 0;
            foreach (array_filter(preg_split('/\s+/', $query)) as $word) {
                $score += mb_strpos($haystack, $word) !== false ? 1 : 0;
            }

            $candidates[] = array($score, $p, $group, $price);
        }

        if (!$candidates) {
            return array('summary' => 'No products are currently offered for order.');
        }

        usort($candidates, function ($a, $b) {
            return $b[0] - $a[0];
        });

        // Structured offers as well as text. The text alone could never become plan cards: every
        // billing term but the first two was dropped, the feature list was run together
        // ("Essential features1 Website 15 GB"), and a host with several product groups lost most
        // of its catalogue to an eight-line cap.
        $currencyOut = $currency;
        $offers = array();
        $lines = array();
        // `all` is the catalogue sync asking for everything, so plans can be shown as cards.
        $limit = !empty($args['all']) ? 300 : ($query === '' ? 30 : 12);
        foreach (array_slice($candidates, 0, $limit) as $c) {
            list(, $p, $group, $price) = $c;
            $offer = Offers::offer($p, $group, $currencyOut);
            if ($offer === null) {
                continue;
            }
            $offers[] = $offer;

            $terms = array();
            foreach ($offer['cycles'] as $cy) {
                $terms[] = Money::money($cy['price'], $currencyOut) . ' ' . Cycles::cycleLabel($cy['cycle']);
            }
            $lines[] = ($group ? $group->name . ' — ' : '') . $p['name'] . ': ' . implode(', ', $terms)
                . ($offer['features'] ? '. Features: ' . implode('; ', array_slice($offer['features'], 0, 8)) : '')
                . ($offer['stock'] === 'out' ? '. OUT OF STOCK' : '')
                // Spelled out for the model, because "no domain needed" is the difference between a
                // smooth order and asking a streaming customer for a domain name they will never have.
                . ($offer['requires_domain'] ? '. Needs a domain name' : '. NO DOMAIN NEEDED')
                . '. Order: ' . $offer['order_url'];
        }

        return array(
            'summary' => implode("\n", $lines),
            'data' => array('products' => $offers, 'currency' => $currencyOut['code']),
        );
    }
}
