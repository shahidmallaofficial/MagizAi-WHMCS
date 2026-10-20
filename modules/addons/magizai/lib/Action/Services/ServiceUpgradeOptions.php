<?php

namespace MagizAI\Whmcs\Action\Services;

use MagizAI\Whmcs\ActionError;
use MagizAI\Whmcs\Action\ActionContext;
use MagizAI\Whmcs\Action\ActionInterface;
use MagizAI\Whmcs\Domain\OrderRules;
use MagizAI\Whmcs\Domain\Ownership;
use MagizAI\Whmcs\Domain\Upgrades;
use MagizAI\Whmcs\Support\Money;
use MagizAI\Whmcs\Text;

/**
 * What this service could become, and what the change would cost today.
 *
 * The cost is WHMCS's own pro-rata calculation (`UpgradeProduct` with `calconly`), not a
 * subtraction the module invented: an upgrade part-way through a billing period costs the
 * difference for the days that remain, and only WHMCS knows how the host has configured that.
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class ServiceUpgradeOptions implements ActionInterface
{
    public function spec()
    {
        return array('identity' => true, 'write' => false, 'group' => 'read', 'label' => 'What a service can be upgraded to, and what it costs');
    }

    public function handle(ActionContext $context)
    {
        $args = $context->args();
        $clientId = $context->clientId();

        $p = Ownership::ownedService($clientId, $args, 'upgrade');

        if (!in_array($p['status'], array('Active', 'Suspended'), true)) {
            throw new ActionError('not_allowed', 'Only an active service can be changed. This one is ' . strtolower($p['status']) . '.');
        }

        Upgrades::mustBeUpgradeable($p);

        $currency = Money::clientCurrency($clientId);
        $targets = Upgrades::upgradeTargets($p, $currency);

        /*
         * Each candidate costs one UpgradeProduct call to price, so an unbounded loop turns one
         * read into as many outbound API calls as the host has plans in that group. Nobody reads
         * more than a handful of options in a chat anyway.
         */
        $targets = array_slice($targets, 0, Upgrades::MAX_UPGRADE_QUOTES);

        if (!$targets) {
            throw new ActionError('no_results', 'There is nothing to upgrade ' . $p['name'] . ' to. Offer to pass this to the team, who can quote a move by hand.');
        }

        $lines = array('Upgrades available for ' . $p['name'] . ($p['domain'] ? ' (' . $p['domain'] . ')' : '') . ', currently ' . Money::money($p['recurringamount'], $currency) . ' ' . strtolower($p['billingcycle']) . ':');
        $found = 0;

        // The gateway an upgrade would really be raised against, found once: the client's default,
        // else one the host has switched on. @see Upgrades::upgradeQuote
        $gateway = OrderRules::orderPaymentMethod($clientId);
        $refusal = '';

        foreach ($targets as $t) {
            $error = '';
            $quote = Upgrades::upgradeQuote((int) $p['id'], (int) $t['pid'], (string) $p['billingcycle'], $currency, $gateway, $error);
            if ($quote === null) {
                $refusal = $refusal !== '' ? $refusal : $error;
                continue;
            }

            $found++;
            $lines[] = '- ' . $t['name'] . ': ' . Upgrades::quoteText($quote, $currency) . ', then '
                . Money::money($t['recurring'], $currency) . ' ' . strtolower($p['billingcycle']);
        }

        if (!$found) {
            // WHMCS's own reason when it gave one the client can act on ("an upgrade is already in
            // progress"), instead of a refusal that explains nothing.
            $said = Text::customerSafe($refusal, '');
            throw new ActionError('no_results', 'No upgrade could be priced for this service right now'
                . ($said !== '' ? ': ' . $said : '') . '. Offer to pass it to the team.',
                $refusal !== '' ? 'UpgradeProduct: ' . Text::cut(strip_tags($refusal), 300) : null);
        }

        $lines[] = 'Ask which one they want, then place the upgrade. An invoice is raised and the change happens once it is paid.';

        return array('summary' => implode("\n", $lines));
    }
}
