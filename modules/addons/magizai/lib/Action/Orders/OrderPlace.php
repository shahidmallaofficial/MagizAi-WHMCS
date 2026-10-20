<?php

namespace MagizAI\Whmcs\Action\Orders;

use MagizAI\Whmcs\ActionError;
use MagizAI\Whmcs\Action\ActionContext;
use MagizAI\Whmcs\Action\ActionInterface;
use MagizAI\Whmcs\Domain\Invoices;
use MagizAI\Whmcs\Domain\OrderRules;
use MagizAI\Whmcs\Domain\Whois;
use MagizAI\Whmcs\Settings;
use MagizAI\Whmcs\Support\Args;
use MagizAI\Whmcs\Support\Cycles;
use MagizAI\Whmcs\Support\Money;
use MagizAI\Whmcs\Support\WhmcsApi;
use MagizAI\Whmcs\Text;

/**
 * Place an order for the signed-in client: a product, a billing term, and a domain.
 *
 * THE BOT NEVER TAKES MONEY. This creates the order and its invoice in WHMCS and hands back the
 * payment link; the client pays on the host's own checkout with the host's own gateway. A chat
 * assistant charging a card is a different risk class entirely, and nothing about ordering
 * hosting needs it.
 *
 * The product, term and price are re-read from WHMCS here rather than trusted from the
 * conversation. The model may have quoted the catalogue five minutes or five days ago, and an
 * order placed against a remembered price is how a customer ends up on a plan that no longer
 * exists at a price we no longer charge.
 *
 * Sensitive by classification, so it is off until an admin ticks it, and MagizAI holds it until the
 * client confirms what it will order in the chat (a Confirm button) before anything is created.
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class OrderPlace implements ActionInterface
{
    public function spec()
    {
        return array('identity' => true, 'write' => true, 'group' => 'sensitive', 'label' => 'Place an order for a plan and domain');
    }

    public function handle(ActionContext $context)
    {
        $order = self::place($context->clientId(), $context->args());

        return array(
            'summary' => $order['summary'],
            'audit' => $order['audit'],
            'notify' => $order['notify'],
            'data' => $order['data'],
        );
    }

    /**
     * Check one order and, unless $checkOnly, place it for $clientId.
     *
     * 1.8.0: shared with ClientSignup, which runs every check here BEFORE it creates an account
     * ($clientId null, $checkOnly true), so no account is left behind for an order that could never
     * be placed (a taken domain, a retired plan, a term the plan does not sell), and then places it
     * for the account it has just made.
     *
     * @param int|null $clientId the client the order is for; null only with $checkOnly
     * @return array{summary: string, audit: string, notify: string, data: array, product: string, price: string}
     * @throws ActionError
     */
    public static function place($clientId, array $args, $checkOnly = false)
    {
        $domainAction = mb_strtolower(Args::text($args, 'domain_action', 20));
        if (!in_array($domainAction, array('register', 'transfer', 'owned'), true)) {
            $domainAction = 'register';
        }

        $domain = mb_strtolower(trim(Args::text($args, 'domain', 253)));
        $domain = preg_replace('/^https?:\/\//', '', (string) $domain);
        $domain = rtrim((string) preg_replace('/\/.*$/', '', $domain), '.');

        /*
         * THE PRODUCT IS RESOLVED FIRST, because it decides whether a domain is wanted at all.
         *
         * This used to demand a valid domain before it had any idea what was being ordered, so an
         * IPTV subscription, a licence, a VPN plan or an SSL certificate could never be ordered
         * through the chat: the assistant was forced to ask for a domain the product does not take
         * and the customer does not have. WHMCS's own "Require Domain" tick decides now.
         */
        // A new account (ClientSignup) is opened in the host's default currency, so that is what it is quoted in.
        $product = OrderRules::orderableProduct($args, $clientId !== null ? Money::clientCurrency($clientId) : Money::defaultCurrency());
        $needsDomain = !isset($product['requires_domain']) || $product['requires_domain'];

        $domainValid = $domain !== ''
            && preg_match('/^(?=.{4,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i', $domain);

        if ($needsDomain && !$domainValid) {
            throw new ActionError('invalid_args', 'Ask the client for the domain they want, including the ending — for example example.com.');
        }

        // Not wanted by this product: drop whatever was passed rather than sending WHMCS a domain it
        // will attach to an order that has no business carrying one.
        if (!$needsDomain) {
            $domain = '';
        }

        /*
         * Registering: only place the order if the domain is KNOWN to be free. WHMCS would accept
         * the order either way and leave the client with an invoice for a name somebody else owns.
         *
         * A lookup that fails (a registry timing out, an extension WHMCS cannot look up) used to let
         * the order through, because only an explicit "unavailable" stopped it. Not knowing is now a
         * reason to stop: the client can try again, or order the name as a transfer or as one they
         * already own, where no lookup is needed.
         */
        if ($needsDomain && $domainAction === 'register') {
            $lookup = Whois::domainLookup($domain);
            if ($lookup['status'] === 'unavailable') {
                throw new ActionError('not_allowed', $domain . ' is already registered. Ask the client for a different name, or order with the domain they already own.');
            }
            if ($lookup['status'] !== 'available') {
                throw new ActionError('not_allowed', $lookup['reason'] === 'unsupported_tld'
                    ? 'Whether ' . $domain . ' is free cannot be checked from the chat for this extension, so no order was placed. '
                        . 'The client can search for it and order it in the store: ' . Whois::registerUrl($domain)
                    : 'It could not be confirmed that ' . $domain . ' is free just now, so no order was placed. '
                        . 'Try again in a minute, or order it as a transfer or with a domain the client already owns.');
            }
        }

        // The term last: a taken domain is worth hearing about before being asked how long to pay.
        $cycle = OrderRules::orderableCycle($product, $args);

        // Also before an account is made for it: an order nobody can pay is not worth an account.
        $paymentMethod = OrderRules::orderPaymentMethod((int) $clientId);

        $price = Money::money($cycle['price'], $product['currency']);
        $setup = (float) $cycle['setup'] > 0 ? ' plus ' . Money::money($cycle['setup'], $product['currency']) . ' setup' : '';
        // "for example.com" only when there IS one. A subscription with no domain read as
        // "Order #12 is placed: IPTV 1 Month for  at $30" — a gap the client can see.
        $forDomain = $domain !== '' ? ' for ' . $domain : '';
        $what = $product['name'] . $forDomain . ' at ' . $price . ' ' . Cycles::cycleLabel($cycle['cycle']) . $setup;

        if ($checkOnly) {
            return array(
                'summary' => 'It can be ordered: ' . $what . '.',
                'audit' => '',
                'notify' => '',
                'data' => array('domain' => $domain, 'product' => $product['name'], 'cycle' => $cycle['cycle']),
                'product' => $product['name'],
                'price' => $what,
            );
        }

        $params = array(
            'clientid' => (int) $clientId,
            'paymentmethod' => $paymentMethod,
            'pid' => array((int) $product['pid']),
            'billingcycle' => array($cycle['cycle']),
        );

        if ($needsDomain) {
            $params['domain'] = array($domain);

            // A client who already owns the domain gets the hosting alone: no registration, no
            // registration fee, no transfer they did not ask for.
            if ($domainAction !== 'owned') {
                $params['domaintype'] = array($domainAction);
                $params['regperiod'] = array(1);
            }
        }

        $r = WhmcsApi::api('AddOrder', $params, false);

        if (($r['result'] ?? '') !== 'success' || empty($r['orderid'])) {
            $message = trim((string) ($r['message'] ?? ''));
            $said = Text::customerSafe($message, '');
            throw new ActionError('not_allowed', $said !== ''
                ? 'The order could not be placed: ' . $said
                : 'The order could not be placed. Offer to pass this to the team.',
                $message !== '' ? 'AddOrder: ' . $message : null);
        }

        $orderId = (int) $r['orderid'];
        $invoiceId = isset($r['invoiceid']) ? (int) $r['invoiceid'] : 0;
        $ordersUrl = Settings::systemUrl() . 'clientarea.php?action=orders';
        // Its number and pay link only to a user who may see invoices. @see Invoices::raisedInvoice
        $invoice = Invoices::raisedInvoice($clientId, $invoiceId, 'The service starts once that invoice is paid.', 'is ready to pay here');
        $payUrl = $invoiceId > 0 ? $invoice['pay_url'] : $ordersUrl;

        $summary = 'Order #' . $orderId . ' is placed: ' . $what . '. '
            . ($invoiceId > 0 ? $invoice['text'] : 'It is waiting in the client area: ' . $ordersUrl);

        return array(
            'summary' => $summary,
            'audit' => 'order #' . $orderId . ' pid ' . $product['pid'] . ($domain !== '' ? ' ' . $domain : '') . ' ' . $cycle['cycle'],
            'notify' => 'A new order (#' . $orderId . ') for ' . $product['name']
                . ($domain !== '' ? ' with the domain ' . $domain : '') . ' was placed through live chat.',
            'data' => array(
                'order_id' => $orderId,
                'invoice_id' => $invoice['invoice_id'],
                'pay_url' => $payUrl,
                'domain' => $domain,
                'product' => $product['name'],
                'cycle' => $cycle['cycle'],
            ),
            'product' => $product['name'],
            'price' => $what,
        );
    }
}
