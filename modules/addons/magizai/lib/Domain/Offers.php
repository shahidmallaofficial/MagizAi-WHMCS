<?php

namespace MagizAI\Whmcs\Domain;

use MagizAI\Whmcs\Settings;
use MagizAI\Whmcs\Support\Cycles;
use MagizAI\Whmcs\Support\Format;

/**
 * A product as a plan card: every billing term it is sold on, a clean feature list, whether it
 * needs a domain, and the cart link for each term.
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class Offers
{
    /**
     * One product as a plan card: every orderable billing term, a clean feature list, and the cart
     * link for each term. Prices are in the visitor's currency where WHMCS has one for them.
     */
    public static function offer(array $p, $group, array $currency)
    {
        $base = Settings::systemUrl() . 'cart.php?a=add&pid=' . (int) $p['pid'];
        $cycles = array();

        if ($p['paytype'] === 'free') {
            $cycles[] = array('cycle' => 'free', 'months' => 0, 'price' => 0.0, 'setup' => 0.0, 'order_url' => $base);
        } else {
            $pr = isset($p['pricing'][$currency['code']]) && is_array($p['pricing'][$currency['code']]) ? $p['pricing'][$currency['code']] : null;
            if ($pr === null) {
                return null;
            }

            if ($p['paytype'] === 'onetime') {
                if ((float) $pr['monthly'] >= 0) {
                    $cycles[] = array('cycle' => 'onetime', 'months' => 0, 'price' => (float) $pr['monthly'],
                        'setup' => max(0.0, (float) (isset($pr['msetupfee']) ? $pr['msetupfee'] : 0)), 'order_url' => $base);
                }
            } else {
                foreach (Cycles::TERMS as $key => $spec) {
                    // WHMCS marks a disabled term with -1.
                    if (!isset($pr[$key]) || (float) $pr[$key] < 0) {
                        continue;
                    }
                    $cycles[] = array(
                        'cycle' => $key,
                        'months' => $spec[0],
                        'price' => (float) $pr[$key],
                        'setup' => max(0.0, (float) (isset($pr[$spec[1]]) ? $pr[$spec[1]] : 0)),
                        'order_url' => $base . '&billingcycle=' . $key,
                    );
                }
            }
        }

        if (!$cycles) {
            return null;
        }

        list($tagline, $features) = self::describe((string) $p['description']);

        /*
         * OUT OF STOCK, as WHMCS's own product settings say. This read `stockcontrol` and
         * `stocklevel` from the GetProducts answer, which has neither field, so a plan WHMCS would
         * refuse to sell was shown, offered, and only refused by WHMCS after the client confirmed.
         * The product row holds both: the "Stock Control" tick and the quantity left.
         */
        $flags = Products::productFlags((int) $p['pid']);
        $out = $flags['stock_control'] && $flags['quantity'] !== null && $flags['quantity'] <= 0 ? 'out' : null;

        return array(
            'id' => (int) $p['pid'],
            'name' => (string) $p['name'],
            'group' => $group ? (string) $group->name : '',
            'tagline' => $tagline,
            'features' => $features,
            'cycles' => $cycles,
            'currency' => array('code' => $currency['code'], 'prefix' => $currency['prefix'], 'suffix' => $currency['suffix']),
            'order_url' => $base,
            'stock' => $out,
            // So the assistant knows BEFORE it starts an order whether to ask for a domain. Without
            // it the only way to find out was to attempt the order and be refused.
            'requires_domain' => $flags['requires_domain'],
        );
    }

    /**
     * Split a product description into a one-line tagline and its feature list.
     *
     * Descriptions are free HTML. Lists (<li>) are the usual feature format; line breaks and plain
     * new lines are the other. A short line ending in a colon ("Essential features:") is a heading,
     * not a feature.
     *
     * @return array{0: string, 1: array<int, string>}
     */
    public static function describe($html)
    {
        $html = (string) $html;
        $tagline = '';
        $items = array();

        $plain = function ($fragment) {
            return Format::plain($fragment);
        };

        if (preg_match_all('/<li[^>]*>(.*?)<\/li>/is', $html, $m)) {
            $items = $m[1];
            $before = preg_split('/<(ul|ol)[^>]*>/i', $html, 2);
            // The text above the list, minus any heading line ("Essential features") that
            // introduces it.
            $intro = array();
            foreach (preg_split('/\n+/', Format::plain($before[0])) as $line) {
                $line = trim($line);
                if ($line !== '' && !self::isHeading($line)) {
                    $intro[] = $line;
                }
            }
            $tagline = implode(' ', $intro);
        } else {
            $parts = preg_split('/<br\s*\/?>|<\/p>|<\/div>|\r?\n/i', $html);
            $parts = array_values(array_filter(array_map($plain, $parts), 'strlen'));
            if ($parts && mb_strlen($parts[0]) > 60) {
                $tagline = array_shift($parts);
            }
            $items = $parts;
        }

        $features = array();
        foreach ($items as $item) {
            $text = trim(preg_replace('/\s+/', ' ', Format::plain($item)));
            // Dropped: headings, run-on paragraphs, and fragments left behind by a stripped logo
            // image ("Powered by", "Built with").
            if ($text === '' || mb_strlen($text) > 90 || self::isHeading($text)
                || preg_match('/\b(by|with|from|and|or)$/i', $text)) {
                continue;
            }
            $features[] = $text;
        }

        return array(Format::cut($tagline, 160), array_slice(array_values(array_unique($features)), 0, 12));
    }

    /** A short line that introduces a list rather than being part of it. */
    public static function isHeading($line)
    {
        $line = trim((string) $line);

        return $line !== '' && (preg_match('/:$/', $line)
            || (mb_strlen($line) <= 40 && preg_match('/^(key |essential |main |plan |all |what\'s |whats )?(features|includes|included|specs|specifications|highlights)$/i', $line)));
    }
}
