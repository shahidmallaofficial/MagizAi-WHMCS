<?php

namespace MagizAI\Whmcs\Action\Domains;

use MagizAI\Whmcs\ActionError;
use MagizAI\Whmcs\Action\ActionContext;
use MagizAI\Whmcs\Action\ActionInterface;
use MagizAI\Whmcs\Domain\DomainRenewals;
use MagizAI\Whmcs\Domain\OrderRules;
use MagizAI\Whmcs\Domain\Ownership;
use MagizAI\Whmcs\Domain\Pricing;
use MagizAI\Whmcs\Domain\Renewals;
use MagizAI\Whmcs\Support\Args;
use MagizAI\Whmcs\Support\Dates;
use MagizAI\Whmcs\Support\WhmcsApi;

/**
 * Renew a domain for a whole number of years.
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class DomainRenew implements ActionInterface
{
    public function spec()
    {
        return array('identity' => true, 'write' => true, 'group' => 'sensitive', 'label' => 'Renew a domain (raises an invoice)');
    }

    public function handle(ActionContext $context)
    {
        $args = $context->args();
        $clientId = $context->clientId();

        $d = Ownership::ownedDomain($clientId, $args);

        /*
         * The same check domain_details reports to MagizAI before it offers a code, so the two can
         * never disagree: the domain's state, this user's permissions, and an open invoice that
         * already renews it (named, with its pay link, only to a user who may see invoices).
         * @see DomainRenewals::renewability
         */
        $check = DomainRenewals::renewability($clientId, $d);
        if (!$check['renewable']) {
            throw new ActionError('not_allowed', $check['reason']);
        }

        $years = Args::text($args, 'years', 2);
        $years = ctype_digit($years) ? (int) $years : 1;
        if ($years < 1 || $years > 10) {
            $years = 1;
        }

        // The price for that many years, in the client's own currency: the one the invoice is in.
        $tld = substr((string) $d['domainname'], strpos((string) $d['domainname'], '.'));
        $amount = Pricing::tldAmount($tld, 'renew', $clientId, $years);
        $price = $amount !== null ? Pricing::tldPrice($tld, 'renew', $clientId, $years) : '';
        OrderRules::mustMatchQuote($args, $amount, (string) $price);

        $r = WhmcsApi::api('AddOrder', array(
            'clientid' => (int) $clientId,
            'paymentmethod' => OrderRules::orderPaymentMethod($clientId),
            'domainrenewals' => array($d['domainname'] => $years),
        ), false);

        return Renewals::renewalResult($clientId, $r, $d['domainname'] . ' for ' . $years . ' year' . ($years > 1 ? 's' : '')
            . ' (expires ' . Dates::date($d['expirydate']) . ')', 'domain ' . $d['domainname'] . ' x' . $years, (string) $price);
    }
}
