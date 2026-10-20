<?php

namespace MagizAI\Whmcs\Domain;

use MagizAI\Whmcs\Action\Permissions;

/**
 * Renewing a domain from the chat: whether it can be, and what the client hears when an invoice
 * already renews it. The answer has the same shape as a service's. @see Renewals
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class DomainRenewals
{
    /**
     * Can this domain be renewed from the chat, and if not, what the client should do instead.
     *
     * Shared by domain_renew (which enforces it) and domain_details (which reports it), as
     * Renewals::renewability() is for services, so MagizAI can check BEFORE it offers a
     * confirmation code. domain_details used to carry no such signal: a code was offered for a
     * domain whose renewal invoice was already raised, clearing or with collections, and refused
     * once typed.
     *
     * The same order as for a service: the domain's state, then whether this user may renew it at
     * all, then an open invoice that already renews it (its number and pay link only to a user who
     * may see invoices).
     *
     * @return array{renewable: bool, reason: string, unpaid_invoice: int, invoice_status: string, pay_url: string, covered_by: array|null}
     */
    public static function renewability($clientId, array $d)
    {
        $name = (string) $d['domainname'];
        $status = (string) $d['status'];

        if (!in_array($status, Statuses::RENEWABLE_DOMAIN_STATUSES, true)) {
            return Renewals::notRenewable($name . ' is ' . strtolower($status) . ', so it cannot be renewed from here. Offer to pass this to the team.');
        }

        // domain_renew needs "Manage Domain Settings" and "Place New Orders" as well as "View Domains",
        // which is all domain_details needs. Said here, or a code is offered that is refused when typed.
        if (!Permissions::userMay($clientId, Permissions::NEEDED['domain_renew'])) {
            return Renewals::notRenewable('The account owner has not given this user permission to manage domains and place orders, which renewing '
                . 'a domain needs. The owner can change it under Account → User Management, or can renew it themselves.');
        }

        $open = Invoices::openInvoiceFor($clientId, $name, 'renew', (int) (isset($d['id']) ? $d['id'] : 0), 'Domain');
        if ($open) {
            return Permissions::userMay($clientId, 'invoices')
                ? Renewals::notRenewable(self::domainInvoiceReason($open), $open)
                : Renewals::notRenewable(Invoices::invoiceNotShown('An invoice for this domain'), $open, false);
        }

        return array('renewable' => true, 'reason' => '', 'unpaid_invoice' => 0, 'invoice_status' => '', 'pay_url' => '', 'covered_by' => null);
    }

    /** What to tell the client about the invoice that already covers this domain, by its status. */
    public static function domainInvoiceReason(array $invoice)
    {
        $id = (int) $invoice['id'];

        if ($invoice['status'] === 'Payment Pending') {
            return 'A payment for invoice #' . $id . ', which covers this domain, was received and is still clearing. '
                . 'It must not be paid again, and no second renewal is raised.';
        }

        if ($invoice['status'] === 'Collections') {
            return 'Invoice #' . $id . ' for this domain is with collections. Offer to pass this to the billing team.';
        }

        return 'There is already an unpaid invoice (#' . $id . ') for this domain: '
            . Invoices::invoiceUrl($id) . ' Point them at it rather than raising another.';
    }
}
