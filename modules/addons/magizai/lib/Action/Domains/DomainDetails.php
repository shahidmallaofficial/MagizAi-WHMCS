<?php

namespace MagizAI\Whmcs\Action\Domains;

use MagizAI\Whmcs\Action\ActionContext;
use MagizAI\Whmcs\Action\ActionInterface;
use MagizAI\Whmcs\Domain\Nameservers;
use MagizAI\Whmcs\Domain\Ownership;
use MagizAI\Whmcs\Domain\Pricing;
use MagizAI\Whmcs\Domain\DomainRenewals;
use MagizAI\Whmcs\Settings;
use MagizAI\Whmcs\Support\Dates;
use MagizAI\Whmcs\Support\WhmcsApi;

/**
 * One domain: status, dates, auto-renew, nameservers, the registrar lock and what renewing it costs.
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class DomainDetails implements ActionInterface
{
    public function spec()
    {
        return array('identity' => true, 'write' => false, 'group' => 'read', 'label' => 'Domain details (status, expiry, nameservers, lock)');
    }

    public function handle(ActionContext $context)
    {
        $args = $context->args();
        $clientId = $context->clientId();

        $d = Ownership::ownedDomain($clientId, $args);

        $lines = array(
            $d['domainname'] . ' — ' . $d['status'] . ', registered ' . Dates::date($d['regdate']) . ', expires ' . Dates::date($d['expirydate']) . ', next due ' . Dates::date($d['nextduedate']),
            'Auto-renew: ' . (!empty($d['donotrenew']) ? 'OFF' : 'on'),
        );

        if ($d['status'] === 'Active' && $d['registrar']) {
            $ns = WhmcsApi::api('DomainGetNameservers', array('domainid' => $d['id']), false);
            if (($ns['result'] ?? '') === 'success') {
                $lines[] = 'Nameservers: ' . implode(', ', Nameservers::nsList($ns));
            } else {
                $lines[] = 'Nameservers: could not be read from the registrar just now.';
            }

            $lock = WhmcsApi::api('DomainGetLockingStatus', array('domainid' => $d['id']), false);
            if (($lock['result'] ?? '') === 'success' && isset($lock['lockstatus'])) {
                $lines[] = 'Registrar lock: ' . $lock['lockstatus'];
            }
        }

        $hostingNs = Nameservers::defaultNameservers();
        if ($hostingNs) {
            $lines[] = 'Our hosting nameservers are: ' . implode(', ', $hostingNs);
        }

        // What renewing costs, in the client's own currency: the figure their renewal invoice
        // carries, so a renewal confirmation can say the amount instead of only "an invoice".
        $renewal = Pricing::domainRenewalPrices((string) $d['domainname'], $clientId);
        if ($renewal['prices']) {
            $terms = array();
            foreach (array_slice($renewal['prices'], 0, 3, true) as $years => $one) {
                $terms[] = $one['price'] . ' for ' . $years . ' year' . ((int) $years === 1 ? '' : 's');
            }
            $lines[] = 'Renewal price: ' . implode(', ', $terms) . '.';
        }

        $lines[] = 'Manage: ' . Settings::systemUrl() . 'clientarea.php?action=domaindetails&id=' . (int) $d['id'];

        $renew = DomainRenewals::renewability($clientId, $d);

        return array(
            'summary' => implode("\n", $lines),
            // Read by MagizAI before it offers a domain renewal code, as service_details is for a
            // service: the same rules domain_renew enforces. @see DomainRenewals::renewability
            'data' => array(
                'domain_id' => (int) $d['id'],
                'status' => (string) $d['status'],
                'renewal' => $renewal,
                'renewable' => $renew['renewable'],
                'renew_block_reason' => $renew['reason'],
                'unpaid_invoice' => $renew['unpaid_invoice'],
                // The open invoice's status, and its pay link only when it is to be paid.
                'unpaid_invoice_status' => $renew['invoice_status'],
                'pay_url' => $renew['pay_url'],
            ),
        );
    }
}
