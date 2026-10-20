<?php

namespace MagizAI\Whmcs\Action\Account;

use MagizAI\Whmcs\ActionError;
use MagizAI\Whmcs\Action\ActionContext;
use MagizAI\Whmcs\Action\ActionInterface;
use MagizAI\Whmcs\Action\Permissions;
use MagizAI\Whmcs\Domain\Clients;
use MagizAI\Whmcs\Domain\Ownership;
use MagizAI\Whmcs\Identity\Verifier;
use MagizAI\Whmcs\Persistence\AuditLog;
use MagizAI\Whmcs\Schema;
use MagizAI\Whmcs\Settings;
use MagizAI\Whmcs\Support\Args;
use MagizAI\Whmcs\Support\WhmcsApi;
use MagizAI\Whmcs\Text;
use WHMCS\Database\Capsule;

/**
 * "I never got my welcome email", "send me that invoice again".
 *
 * Sends one of WHMCS's own emails again, exactly as staff would from the admin area: a service's
 * welcome email (the template its product names) or an invoice's email. It goes where WHMCS sends
 * it, to the address on the account, never to an address given in the chat.
 *
 * A CHANGE, because it sends something in the client's name: it needs the client's confirmation
 * code and a recent sign-in like every other change, it is refused in staff view, and it is off
 * until an admin ticks it. @see Registry::OFF_UNTIL_TICKED
 *
 * WHMCS'S ANSWER IS BELIEVED. SendEmail refuses a template that is missing or switched off, and
 * says so when the mail server does not take the email. The chat then says nothing went out, and
 * WHMCS's own words are recorded for staff. At most three an hour per account, counting only the
 * emails that went.
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class EmailResend implements ActionInterface
{
    /** How many emails the chat may send again for one account in an hour. */
    const RESENDS_PER_HOUR = 3;

    /**
     * The invoice email sent again, by the invoice's status: the one that asks for payment, or the
     * receipt. An invoice in any other status gets nothing sent again.
     */
    const INVOICE_TEMPLATES = array('Unpaid' => 'Invoice Created', 'Paid' => 'Invoice Payment Confirmation');

    public function spec()
    {
        // Off on every install until an admin ticks it, although it sits with the changes that
        // start on. @see Registry::OFF_UNTIL_TICKED
        return array('identity' => true, 'write' => true, 'group' => 'write', 'label' => 'Send a service\'s welcome email or an invoice email again, to the address on the account (off until you tick it)');
    }

    public function handle(ActionContext $context)
    {
        $args = $context->args();
        $clientId = $context->clientId();

        $kind = strtolower(Args::text($args, 'kind', 20));
        if (!in_array($kind, array('welcome', 'invoice'), true)) {
            throw new ActionError('invalid_args', 'Ask whether they want a service\'s welcome email or an invoice email sent again.');
        }

        // The bridge refuses every change in staff view before it gets here. This is the second lock.
        if ($context->staffView()) {
            throw new ActionError('staff_write_refused', Verifier::message('staff_write_refused'));
        }

        if ($kind === 'welcome') {
            Permissions::mustMay($clientId, 'products');
            $target = self::welcomeEmailFor($clientId, $args);
        } else {
            Permissions::mustMay($clientId, 'invoices');
            $target = self::invoiceEmailFor($clientId, $args);
        }

        // Counted only once there is something to send. Asking about a service with no welcome
        // email, or an email WHMCS then refused, used up nothing.
        $recent = AuditLog::recentSuccesses('email_resend', $clientId, $context->claimedUserId(), 3600);
        if ($recent['client'] >= self::RESENDS_PER_HOUR) {
            throw new ActionError('not_allowed', 'Emails can be sent again from the chat at most ' . self::RESENDS_PER_HOUR . ' times an hour for an account, '
                . 'and that limit has been reached, so nothing was sent. The client can ask again later. The emails already sent are listed in the client area: '
                . Settings::systemUrl() . 'clientarea.php?action=emails');
        }

        $r = WhmcsApi::api('SendEmail', array('messagename' => $target['template'], 'id' => $target['id']), false);

        if (($r['result'] ?? '') !== 'success') {
            $message = trim((string) ($r['message'] ?? ''));
            if (stripos($message, 'disabled') !== false) {
                $why = ' is switched off in the billing system, so it was not sent.';
            } elseif (stripos($message, 'not found') !== false) {
                $why = ' is not set up in the billing system, so it was not sent.';
            } else {
                $why = ' could not be sent just now, so nothing went out.';
            }

            throw new ActionError('not_allowed', ucfirst($target['what']) . $why . ' Offer to pass this to the team.',
                $target['audit'] . ': sending "' . $target['template'] . '" again failed' . ($message !== '' ? ': SendEmail: ' . Text::cut(strip_tags($message), 300) : ''));
        }

        $masked = Clients::maskedAccountEmail($clientId);

        return array(
            'summary' => ucfirst($target['what']) . ' was sent again to ' . ($masked !== '' ? $masked . ', the address on the account' : 'the address on the account')
                . '. It can take a few minutes to arrive, and it may land in the spam folder.',
            'audit' => $target['audit'] . ': "' . $target['template'] . '" sent again',
            'data' => array('kind' => $kind, 'template' => $target['template'], 'sent_to' => $masked),
        );
    }

    /**
     * A service's welcome email: the template its product names, for one of this client's services
     * that has been set up. WHMCS sends it when a service is set up, so a service that has not been
     * has none to send again yet.
     *
     * @return array{template: string, id: int, what: string, audit: string}
     */
    private static function welcomeEmailFor($clientId, array $args)
    {
        $p = Ownership::ownedService($clientId, $args);
        $label = $p['name'] . ($p['domain'] ? ' (' . $p['domain'] . ')' : '');

        if ((string) $p['status'] === 'Pending') {
            throw new ActionError('not_allowed', $label . ' has not been set up yet, so it has no welcome email yet. WHMCS sends it when the service is set up.');
        }

        if (!in_array((string) $p['status'], array('Active', 'Suspended'), true)) {
            throw new ActionError('not_allowed', $label . ' is ' . strtolower((string) $p['status']) . ', so its welcome email is not sent again.');
        }

        $template = self::welcomeTemplate(isset($p['pid']) ? (int) $p['pid'] : 0);
        if ($template === '') {
            throw new ActionError('not_allowed', 'The plan of ' . $label . ' has no welcome email set up, so there is nothing to send again. Offer to pass this to the team.');
        }

        return array('template' => $template, 'id' => (int) $p['id'], 'what' => 'the welcome email for ' . $label, 'audit' => 'service #' . (int) $p['id']);
    }

    /** The name of the welcome email template a product sends (tblproducts.welcomeemail), or '' for none. */
    private static function welcomeTemplate($productId)
    {
        if ((int) $productId <= 0 || !Schema::hasColumn('tblproducts', 'welcomeemail') || !Schema::hasTable('tblemailtemplates')) {
            return '';
        }

        $templateId = (int) Capsule::table('tblproducts')->where('id', (int) $productId)->value('welcomeemail');
        if ($templateId <= 0) {
            return '';
        }

        // A product email only: the welcome email is sent for a service, and its id is the service's.
        $row = Capsule::table('tblemailtemplates')->where('id', $templateId)->first(array('name', 'type'));

        return $row && strtolower((string) $row->type) === 'product' ? trim((string) $row->name) : '';
    }

    /**
     * An invoice's email: for an unpaid invoice the one that asks for payment, for a paid one the
     * receipt. One of this client's invoices, by the number they see; with none named, their only
     * unpaid invoice, or a question when there are several.
     *
     * @return array{template: string, id: int, what: string, audit: string}
     */
    private static function invoiceEmailFor($clientId, array $args)
    {
        if (isset($args['invoice_id']) && is_scalar($args['invoice_id']) && trim((string) $args['invoice_id']) !== '') {
            $invoice = Ownership::ownedInvoice($clientId, Args::digits($args, 'invoice_id'));
        } else {
            $unpaid = array();
            foreach (Capsule::table('tblinvoices')->where('userid', (int) $clientId)->where('status', 'Unpaid')
                ->orderBy('id', 'desc')->limit(6)->get(array('id', 'status', 'invoicenum')) as $row) {
                $unpaid[] = array('id' => (int) $row->id, 'status' => (string) $row->status, 'number' => (string) $row->invoicenum);
            }

            if (count($unpaid) !== 1) {
                $names = array();
                foreach (array_slice($unpaid, 0, 5) as $one) {
                    $names[] = '#' . ($one['number'] !== '' ? $one['number'] : $one['id']);
                }
                throw new ActionError('invalid_args', $unpaid
                    ? 'The client has ' . count($unpaid) . ' unpaid invoices (' . implode(', ', $names) . '). Ask which one to send again.'
                    : 'Ask which invoice they want sent again, by its number.');
            }
            $invoice = $unpaid[0];
        }

        $number = $invoice['number'] !== '' ? $invoice['number'] : (string) $invoice['id'];
        $status = $invoice['status'];

        if ($status === 'Payment Pending') {
            throw new ActionError('not_allowed', 'A payment for invoice #' . $number . ' was received and is still clearing, so the invoice is not sent again: '
                . 'it must not be paid twice. The receipt comes once the payment clears.');
        }

        if ($status === 'Collections') {
            throw new ActionError('not_allowed', 'Invoice #' . $number . ' is with collections, so it is not sent again from the chat. Offer to pass this to the billing team.');
        }

        if (!isset(self::INVOICE_TEMPLATES[$status])) {
            throw new ActionError('not_allowed', 'Invoice #' . $number . ' is ' . strtolower($status) . ', so no email is sent again for it.');
        }

        return array(
            'template' => self::INVOICE_TEMPLATES[$status],
            'id' => (int) $invoice['id'],
            'what' => $status === 'Paid' ? 'the receipt for invoice #' . $number : 'the email for invoice #' . $number,
            'audit' => 'invoice #' . (int) $invoice['id'],
        );
    }
}
