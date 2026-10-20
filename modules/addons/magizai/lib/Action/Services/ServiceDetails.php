<?php

namespace MagizAI\Whmcs\Action\Services;

use MagizAI\Whmcs\Action\ActionContext;
use MagizAI\Whmcs\Action\ActionInterface;
use MagizAI\Whmcs\Action\Permissions;
use MagizAI\Whmcs\Domain\Nameservers;
use MagizAI\Whmcs\Domain\Ownership;
use MagizAI\Whmcs\Domain\Pricing;
use MagizAI\Whmcs\Domain\Renewals;
use MagizAI\Whmcs\Settings;
use MagizAI\Whmcs\Support\Args;
use MagizAI\Whmcs\Support\Dates;
use MagizAI\Whmcs\Support\Money;
use WHMCS\Database\Capsule;

/**
 * One service in full: status, billing, server, usage and nameservers, and whether it can be
 * renewed early from the chat.
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class ServiceDetails implements ActionInterface
{
    public function spec()
    {
        return array('identity' => true, 'write' => false, 'group' => 'read', 'label' => 'Service details');
    }

    public function handle(ActionContext $context)
    {
        $args = $context->args();
        $clientId = $context->clientId();

        /*
         * `purpose` says what the service is wanted FOR, when MagizAI checks before a change (its
         * renewal check sends purpose=renew). With a paid line and a free trial on the account, "renew
         * it" is about the paid line, and asking "which service?" was a question with one real answer.
         * Older MagizAI sends no purpose and gets exactly the old resolution. @see Ownership::ownedService
         */
        $p = Ownership::ownedService($clientId, $args, Args::purposeArg($args));
        $currency = Money::clientCurrency($clientId);

        $lines = array(
            $p['groupname'] . ' — ' . $p['name'] . ' (#' . $p['id'] . ')' . ($p['domain'] ? ' for ' . $p['domain'] : ''),
            'Status: ' . $p['status'] . ($p['status'] === 'Suspended' && $p['suspensionreason'] ? ' (reason: ' . $p['suspensionreason'] . ')' : ''),
            'Billing: ' . Money::money($p['recurringamount'], $currency) . ' ' . strtolower($p['billingcycle']) . ', next due ' . Dates::dueLabel($p['nextduedate']) . ', started ' . Dates::date($p['regdate']),
        );

        if ($p['serverhostname'] || $p['dedicatedip'] || $p['serverip']) {
            $lines[] = 'Server: ' . ($p['serverhostname'] ?: $p['servername']) . ', IP ' . ($p['dedicatedip'] ?: $p['serverip']);
        }
        if ((int) $p['disklimit'] > 0) {
            $lines[] = 'Disk: ' . $p['diskusage'] . ' MB of ' . $p['disklimit'] . ' MB; bandwidth: ' . $p['bwusage'] . ' MB of ' . ((int) $p['bwlimit'] > 0 ? $p['bwlimit'] . ' MB' : 'unlimited') . ' (as of ' . $p['lastupdate'] . ')';
        }

        $ns = Nameservers::serverNameservers((int) $p['serverid']);
        if ($ns) {
            $lines[] = 'To use this hosting, point the domain to these nameservers: ' . implode(', ', $ns);
        }

        if ($p['status'] === 'Suspended') {
            $overdue = Capsule::table('tblinvoices')->where('userid', $clientId)->where('status', 'Unpaid')->where('duedate', '<', date('Y-m-d'))->count();
            $collections = Capsule::table('tblinvoices')->where('userid', $clientId)->where('status', 'Collections')->count();

            /*
             * How many invoices, and the link to them, only for a user who may see invoices, as
             * renewability() says in the same answer. Anyone else hears that paying usually lifts
             * the suspension and who can pay. @see \MagizAI\Whmcs\Domain\Invoices::invoiceNotShown
             */
            if (!Permissions::userMay($clientId, 'invoices')) {
                if ($overdue) {
                    $lines[] = 'An invoice on the account is overdue, and paying it usually lifts an overdue suspension. This user is not allowed '
                        . 'to see invoices, so the account owner, or a team member who may see invoices, can pay it in the client area.';
                }
                if ($collections) {
                    $lines[] = 'An invoice on the account is with collections, which the billing team handles. Offer to pass this to them.';
                }
            } else {
                if ($overdue) {
                    $lines[] = 'The client has ' . $overdue . ' overdue invoice(s); paying them usually lifts an overdue suspension: ' . Settings::systemUrl() . 'clientarea.php?action=invoices';
                }

                // Not paid through the client area: an invoice with collections is for the billing team.
                if ($collections) {
                    $lines[] = 'The client also has ' . $collections . ' invoice(s) with collections, which the billing team handles. Offer to pass this to them.';
                }
            }
        }

        $lines[] = 'Manage: ' . Settings::systemUrl() . 'clientarea.php?action=productdetails&id=' . (int) $p['id'];

        $renew = Renewals::renewability($clientId, $p);

        return array(
            'summary' => implode("\n", $lines),
            // Read by MagizAI before it offers a renewal code. @see Renewals::renewability
            'data' => array(
                'service_id' => (int) $p['id'],
                'billingcycle' => (string) $p['billingcycle'],
                'renewable' => $renew['renewable'],
                'renew_block_reason' => $renew['reason'],
                'unpaid_invoice' => $renew['unpaid_invoice'],
                // The open invoice's status, and its pay link only when it is to be paid.
                'unpaid_invoice_status' => $renew['invoice_status'],
                'pay_url' => $renew['pay_url'],
                // What a renewal costs, in the client's currency, so the confirmation can say so.
                // Null for a service that does not renew.
                'renewal' => Pricing::serviceRenewalPrice($p, $currency),
            ),
        );
    }
}
