<?php

namespace MagizAI\Whmcs\Action\Catalog;

use MagizAI\Whmcs\Action\ActionContext;
use MagizAI\Whmcs\Action\ActionInterface;
use MagizAI\Whmcs\Action\ReadLimits;
use MagizAI\Whmcs\Domain\Pricing;
use MagizAI\Whmcs\Domain\Whois;
use MagizAI\Whmcs\Persistence\AuditLog;
use MagizAI\Whmcs\Settings;
use MagizAI\Whmcs\Support\Args;
use MagizAI\Whmcs\Support\WhmcsApi;

/**
 * Whether a domain is free to register, its first-year price, and a few other extensions when it
 * is taken.
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class DomainCheck implements ActionInterface
{
    /** After this many domain checks in an hour across the install, alternatives are not looked up. */
    const DOMAIN_ALTERNATIVES_BUDGET = 40;

    /** How many other extensions one check may look up: fewer for a visitor who is not signed in. */
    const DOMAIN_ALTERNATIVES_SIGNED_IN = 5;

    const DOMAIN_ALTERNATIVES_ANONYMOUS = 2;

    public function spec()
    {
        // 'optional' for the same reason: a domain price is quoted in the currency the client's
        // renewal or registration invoice will be raised in. Anonymous, it is the default one.
        return array('identity' => 'optional', 'write' => false, 'group' => 'public', 'label' => 'Domain availability');
    }

    public function handle(ActionContext $context)
    {
        $args = $context->args();
        $clientId = $context->clientId();

        $domain = Args::domainArg($args);
        $lookup = Whois::domainLookup($domain);
        $lookups = 1;

        // In the signed-in client's own currency, the one their registration invoice is raised in.
        $tld = substr($domain, strpos($domain, '.'));
        $price = Pricing::tldPrice($tld, 'register', $clientId);

        /*
         * A LOOKUP THAT FAILED IS NOT AN ANSWER. It used to surface as an internal error, which
         * MagizAI's order check read as "nothing known, carry on" — and an order for a domain
         * somebody else owns could follow. Now it says plainly that it is unknown (available null),
         * and points at the store, where the host's own lookup applies.
         */
        if ($lookup['status'] === 'unknown') {
            return array(
                'summary' => ($lookup['reason'] === 'unsupported_tld'
                        ? 'Whether ' . $domain . ' is free cannot be checked from the chat for this extension.'
                        : 'Whether ' . $domain . ' is free could not be checked just now.')
                    . ' The client can search for it in the store: ' . Whois::registerUrl($domain),
                'data' => array('available' => null, 'lookup' => $lookup['reason']),
                'audit' => 'whois 1',
            );
        }

        if ($lookup['status'] === 'available') {
            return array(
                'summary' => $domain . ' is available' . ($price ? ' to register for ' . $price . ' for the first year' : '') . '. Register: ' . Whois::registerUrl($domain),
                'data' => array('available' => true),
                'audit' => 'whois 1',
            );
        }

        /*
         * Taken: suggest the same name on up to three other extensions that have pricing.
         *
         * EVERY ALTERNATIVE IS A REGISTRY LOOKUP made from the host's server, so they are bounded:
         * two for a visitor who is not signed in, five for a signed-in client, and none once the
         * install has made many checks this hour. A registry that throttles the host's address
         * fails the lookups real customers make. @see ReadLimits::READ_LIMITS
         */
        $budget = self::alternativesBudget($clientId);
        $label = substr($domain, 0, strpos($domain, '.'));
        $alternatives = array();
        foreach (array('.com', '.net', '.org', '.co', '.io') as $alt) {
            if ($budget <= 0 || count($alternatives) >= 3) {
                break;
            }
            $altPrice = $alt === $tld ? null : Pricing::tldPrice($alt, 'register', $clientId);
            if (!$altPrice) {
                continue;
            }

            $budget--;
            $lookups++;
            $check = WhmcsApi::api('DomainWhois', array('domain' => $label . $alt), false);
            if (isset($check['status']) && $check['status'] === 'available') {
                $alternatives[] = $label . $alt . ' (' . $altPrice . ')';
            }
        }

        return array(
            'summary' => $domain . ' is already registered.' . ($alternatives ? ' Available alternatives: ' . implode(', ', $alternatives) . '.' : '')
                . ' If they own it, they can transfer it in: ' . Settings::systemUrl() . 'cart.php?a=add&domain=transfer',
            'data' => array('available' => false),
            'audit' => 'whois ' . $lookups,
        );
    }

    /** How many other extensions this domain check may look up. @see DomainCheck */
    private static function alternativesBudget($clientId)
    {
        try {
            $counted = ReadLimits::counted('domain_check');
            $recent = $counted !== null ? $counted : AuditLog::recentRuns('domain_check', 3600);
        } catch (\Exception $e) {
            return 0; // Unable to count is no reason to spend more lookups.
        }

        if ($recent >= self::DOMAIN_ALTERNATIVES_BUDGET) {
            return 0;
        }

        return $clientId ? self::DOMAIN_ALTERNATIVES_SIGNED_IN : self::DOMAIN_ALTERNATIVES_ANONYMOUS;
    }
}
