<?php

namespace MagizAI\Whmcs\Action\Catalog;

use MagizAI\Whmcs\ActionError;
use MagizAI\Whmcs\Action\ActionContext;
use MagizAI\Whmcs\Action\ActionInterface;
use MagizAI\Whmcs\Domain\Pricing;
use MagizAI\Whmcs\Support\Args;

/**
 * What one domain extension costs to register, renew and transfer for a year.
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class TldPricing implements ActionInterface
{
    public function spec()
    {
        return array('identity' => 'optional', 'write' => false, 'group' => 'public', 'label' => 'Domain prices');
    }

    public function handle(ActionContext $context)
    {
        $args = $context->args();
        $clientId = $context->clientId();

        $tld = '.' . ltrim(strtolower(Args::text($args, 'tld', 30)), '.');
        if (!preg_match('/^\.[a-z0-9-]{2,24}(\.[a-z0-9-]{2,24})?$/', $tld)) {
            throw new ActionError('invalid_args', 'Ask which domain extension they mean, for example .com.');
        }

        // In the signed-in client's own currency: the one their invoice will be raised in.
        $parts = array();
        foreach (array('register', 'renew', 'transfer') as $kind) {
            $price = Pricing::tldPrice($tld, $kind, $clientId);
            if ($price) {
                $parts[] = $kind . ' ' . $price;
            }
        }

        if (!$parts) {
            throw new ActionError('no_results', $tld . ' domains are not offered.');
        }

        $table = Pricing::tldPricing($clientId);

        return array(
            'summary' => $tld . ' (1 year): ' . implode(', ', $parts) . '.',
            'data' => array('currency' => isset($table['currency']['code']) ? (string) $table['currency']['code'] : ''),
        );
    }
}
