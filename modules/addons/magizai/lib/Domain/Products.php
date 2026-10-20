<?php

namespace MagizAI\Whmcs\Domain;

use MagizAI\Whmcs\Schema;
use MagizAI\Whmcs\Support\Format;
use WHMCS\Database\Capsule;

/**
 * What WHMCS's own product rows say that GetProducts does not: the "Require Domain" tick, stock
 * control, hidden, retired and the product group. Read once per request.
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class Products
{
    /** tblproducts flags the API does not return, read once per request. @see productFlags */
    private static $productFlags = null;

    /** Which product groups are hidden, read once per request. @see groupHidden */
    private static $hiddenGroups = null;

    /** Is this product gone, hidden, retired, or in a hidden group? */
    public static function isOffSale($pid)
    {
        $flags = self::productFlags($pid);

        return !$flags['exists'] || $flags['hidden'] || $flags['retired'] || self::groupHidden($flags['gid']);
    }

    /**
     * What WHMCS's own product row says about one product, for what GetProducts does not return:
     * the "Require Domain" tick, "Stock Control" and the quantity left, plus hidden, retired and the
     * group for the off-sale check.
     *
     * ONE query for every product, once per request. This used to be a schema question plus a select
     * for every product in a catalogue (up to 300 when MagizAI syncs the plan cards) and for every
     * upgrade target.
     *
     * When the row or a column cannot be read, the safe answers apply: a domain IS required (asking
     * for one that is not needed is an annoying question; skipping one that is needed breaks the
     * order in the merchant's own system), the stock is unknown (WHMCS still refuses what it cannot
     * fill), and a product that is not there is off sale.
     *
     * @return array{exists: bool, requires_domain: bool, stock_control: bool, quantity: int|null, hidden: bool, retired: bool, gid: int}
     */
    public static function productFlags($productId)
    {
        if (self::$productFlags === null) {
            self::$productFlags = array();

            try {
                $columns = array('id', 'gid', 'hidden');
                foreach (array('retired', 'showdomainoptions', 'stockcontrol', 'qty') as $column) {
                    if (Schema::hasColumn('tblproducts', $column)) {
                        $columns[] = $column;
                    }
                }

                foreach (Capsule::table('tblproducts')->get($columns) as $row) {
                    self::$productFlags[(int) $row->id] = array(
                        'exists' => true,
                        'requires_domain' => isset($row->showdomainoptions) ? Format::flagOn($row->showdomainoptions) : true,
                        'stock_control' => isset($row->stockcontrol) && Format::flagOn($row->stockcontrol),
                        'quantity' => isset($row->qty) && is_numeric($row->qty) ? (int) $row->qty : null,
                        'hidden' => Format::flagOn($row->hidden),
                        'retired' => isset($row->retired) && Format::flagOn($row->retired),
                        'gid' => (int) $row->gid,
                    );
                }
            } catch (\Exception $e) {
                self::$productFlags = array();
            }
        }

        $pid = (int) $productId;

        return isset(self::$productFlags[$pid]) ? self::$productFlags[$pid] : array(
            'exists' => false, 'requires_domain' => true, 'stock_control' => false, 'quantity' => null,
            'hidden' => false, 'retired' => false, 'gid' => 0,
        );
    }

    /** Does WHMCS say this product needs a domain? @see productFlags for when it cannot be read */
    public static function productRequiresDomain($productId)
    {
        $flags = self::productFlags($productId);

        return $flags['requires_domain'];
    }

    /** Is this product group hidden from the store? Every group is read once per request. */
    public static function groupHidden($groupId)
    {
        if (self::$hiddenGroups === null) {
            self::$hiddenGroups = array();
            foreach (Capsule::table('tblproductgroups')->get(array('id', 'hidden')) as $group) {
                if (Format::flagOn($group->hidden)) {
                    self::$hiddenGroups[] = (int) $group->id;
                }
            }
        }

        return in_array((int) $groupId, self::$hiddenGroups, true);
    }

    /** Forget what was remembered for the request that ran last. @see \MagizAI\Whmcs\Action\Runner::forget */
    public static function forget()
    {
        self::$productFlags = null;
        self::$hiddenGroups = null;
    }
}
