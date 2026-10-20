<?php

namespace MagizAI\Whmcs\Domain;

/**
 * What WHMCS's statuses mean for an answer: which invoices are still open, which services and
 * domains are live, which invoice lines bill what, and when something counts as due soon.
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class Statuses
{
    /** Invoices that are still open, and what each status means for paying it. @see Invoices::invoiceState */
    const OPEN_INVOICE_STATUSES = array('Unpaid', 'Payment Pending', 'Collections');

    /** Domains that are live, in the order WHMCS moves them through. Expired ones are listed apart. */
    const LIVE_DOMAIN_STATUSES = array('Active', 'Pending', 'Pending Registration', 'Pending Transfer', 'Grace', 'Redemption');

    /** Domains that can be renewed from the chat: live, or expired and still in WHMCS's hands. @see DomainRenewals::renewability */
    const RENEWABLE_DOMAIN_STATUSES = array('Active', 'Expired', 'Grace');

    /** Services that are live: set up and billed, or waiting to be set up. Anything else has ended. */
    const LIVE_SERVICE_STATUSES = array('Active', 'Suspended', 'Pending');

    /** A service is "due soon" when its next due date is this many days away or fewer. */
    const SERVICE_DUE_SOON_DAYS = 14;

    /** A domain "expires soon" when it expires this many days from now or fewer. */
    const DOMAIN_EXPIRES_SOON_DAYS = 30;

    /** Invoice lines that bill a service, and the ones that bill a domain (tblinvoiceitems.type). */
    const SERVICE_ITEM_TYPES = array('Hosting');

    const DOMAIN_ITEM_TYPES = array('Domain', 'DomainRegister', 'DomainTransfer');

    /**
     * The days a domain "expires soon" in, as WHMCS date strings: today to DOMAIN_EXPIRES_SOON_DAYS
     * days from now, both included.
     *
     * ONE WINDOW FOR EVERY COUNT AND EVERY FLAG. The domain list counted "expiring" with one rule,
     * flagged its lines with another and the overview counted with a third, so a domain expiring in
     * exactly 30 days was flagged and not counted, and an Active domain whose date had already passed
     * was "expiring" in one answer and not in the other. A date already passed is not "expiring".
     * A date string compares the same way in MySQL and SQLite. @see domainExpiresSoon
     *
     * @return array{0: string, 1: string}
     */
    public static function domainSoonWindow()
    {
        return array(date('Y-m-d'), date('Y-m-d', strtotime('+' . self::DOMAIN_EXPIRES_SOON_DAYS . ' days')));
    }

    /** Is this domain row one of the "expiring within 30 days"? The same rule the counts query. */
    public static function domainExpiresSoon($status, $expiryDate)
    {
        $date = substr(trim((string) $expiryDate), 0, 10);
        if ((string) $status !== 'Active' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date < '1000-01-01') {
            return false;
        }

        list($from, $to) = self::domainSoonWindow();

        return $date >= $from && $date <= $to;
    }
}
