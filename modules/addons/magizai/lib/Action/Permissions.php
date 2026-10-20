<?php

namespace MagizAI\Whmcs\Action;

use MagizAI\Whmcs\ActionError;
use WHMCS\Database\Capsule;

/**
 * What the signed-in USER may see and do on the account: WHMCS 8's per-user permissions, checked
 * before any account action runs. @see Runner::run
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class Permissions
{
    /** Does WHMCS 8's tblusers_clients exist? Asked once per run. */
    private static $usersTable = null;

    /**
     * The WHMCS client-area permissions each account action needs, for a USER who is not the
     * account's owner. Keys are WHMCS's own permission names (tblusers_clients.permissions), and a
     * list means ALL of them.
     *
     * WHY. WHMCS 8 lets an account owner add team members and choose what each may see. The chat
     * checked only WHICH ACCOUNT the visitor was signed in to, never which USER — so a team member
     * the owner had barred from invoices could read them here, get a service password, place an
     * order or request a cancellation. That broke the one promise this module makes: it can never do
     * more than the client could in their own client area.
     *
     * WHAT EACH ONE MEANS is WHMCS's own wording (lang/english.php, subaccountperms*), not a guess
     * from the name:
     *   products        View Products & Services
     *   manageproducts  View & Modify Product Passwords
     *   domains         View Domains
     *   managedomains   Manage Domain Settings
     *   invoices        View & Pay Invoices
     *   tickets         View & Open Support Tickets
     *   orders          Place New Orders/Upgrades/Cancellations
     *   emails          View Email History
     *   productsso      Perform Single Sign-On (the one-click control panel link)
     *
     * Earlier versions read "manageproducts" as "manage services". Upgrades, renewals, extras and
     * cancellations were gated on the PASSWORD permission, and a service password needed only "View
     * Products & Services": a user kept away from passwords could get one, and a user allowed only
     * to see passwords could cancel a service. Everything that orders, upgrades, renews or cancels
     * now needs "orders".
     *
     * service_credentials needs "products" to run, because the username is on the service page any
     * such user can open. The PASSWORD half checks "manageproducts" itself. @see ServiceCredentials
     */
    const NEEDED = array(
        'services_list' => 'products', 'service_details' => 'products', 'service_credentials' => 'products',
        'service_getting_started' => 'products', 'service_upgrade_options' => 'products', 'addon_catalog' => 'products',
        // Site Doctor's ownership check: it lists the client's hosting accounts, so it needs what
        // listing services needs.
        'hosting_accounts' => 'products',
        'service_password_reset' => array('products', 'manageproducts'),
        'service_upgrade' => array('products', 'orders'), 'service_renew' => array('products', 'orders'),
        'addon_order' => array('products', 'orders'), 'service_cancel_request' => array('products', 'orders'),
        'domains_list' => 'domains', 'domain_details' => 'domains',
        'domain_update_nameservers' => array('domains', 'managedomains'), 'domain_set_autorenew' => array('domains', 'managedomains'),
        'domain_send_epp' => array('domains', 'managedomains'),
        // 1.10.0: the registrar lock and the contact (WHOIS) details are domain settings in the client area too.
        'domain_set_lock' => array('domains', 'managedomains'), 'domain_update_contacts' => array('domains', 'managedomains'),
        // A renewal raises an order, so it needs "orders" as well as the domain permissions.
        'domain_renew' => array('domains', 'managedomains', 'orders'),
        'invoices_list' => 'invoices', 'invoice_details' => 'invoices',
        // 1.10.0: paying an invoice from the account credit is paying an invoice.
        'invoice_apply_credit' => 'invoices',
        // 1.10.0: a server command on a service. "View & Modify Product Passwords" is the client area's
        // permission to manage a service, not only to see it.
        'service_module_command' => array('products', 'manageproducts'),
        'tickets_list' => 'tickets', 'ticket_details' => 'tickets', 'ticket_open' => 'tickets', 'ticket_reply' => 'tickets',
        // 1.8.0: closing is what "View & Open Support Tickets" lets a user do in the client area too.
        'ticket_close' => 'tickets',
        'order_place' => 'orders',
        // 1.6.0. What an invoice was paid with is invoice information; the services it pays for are
        // shown only to a user who may also see those. @see PaymentStatus
        'payment_status' => 'invoices',
        'order_status' => 'orders',
        'renewal_status' => 'products',
        'email_history' => 'emails',
        // And "products" for a welcome email, "invoices" for an invoice email, checked by the action
        // once it knows which. @see EmailResend
        'email_resend' => 'emails',
    );

    /** Refuse, in the owner's terms, unless the signed-in user holds every listed permission. */
    public static function mustMay($clientId, $permission)
    {
        if (!self::userMay($clientId, $permission)) {
            throw new ActionError('not_allowed', 'The account owner has not given this user access to that. '
                . 'The owner can change it under Account → User Management, or can ask for it themselves.');
        }
    }

    /** WHMCS 8 and later keep users apart from accounts in tblusers_clients. */
    public static function usersTableExists()
    {
        if (self::$usersTable === null) {
            self::$usersTable = (bool) Capsule::schema()->hasTable('tblusers_clients');
        }

        return self::$usersTable;
    }

    /** Is the user the sign-in names a user of this account? Always so before WHMCS 8 (no users). */
    public static function tokenUserBelongs($clientId)
    {
        try {
            if (!self::usersTableExists()) {
                return true;
            }

            $uid = ActionContext::current()->claimedUserId();

            return $uid > 0 && Capsule::table('tblusers_clients')
                ->where('auth_user_id', $uid)->where('client_id', (int) $clientId)->exists();
        } catch (\Exception $e) {
            // Unable to tell is not permission to proceed.
            return false;
        }
    }

    /**
     * Does the signed-in USER hold this permission (or every one in a list) on this account?
     *
     * The account owner always does. On WHMCS before 8, which has no users table, there are no team
     * members to restrict. A sign-in with no user is refused before any action runs (see Runner::run()); the
     * only caller with no user here is in-process code with no token at all, which is the account.
     *
     * @param string|string[] $permission
     */
    public static function userMay($clientId, $permission)
    {
        $needed = array();
        foreach ((array) $permission as $one) {
            if (is_string($one) && $one !== '') {
                $needed[] = $one;
            }
        }

        $uid = ActionContext::current()->claimedUserId();

        try {
            if (!self::usersTableExists()) {
                return true;
            }

            if ($uid <= 0) {
                return !ActionContext::current()->viaToken();
            }

            $row = Capsule::table('tblusers_clients')
                ->where('auth_user_id', $uid)->where('client_id', (int) $clientId)
                ->first(array('owner', 'permissions'));
        } catch (\Exception $e) {
            // Unable to tell is not permission to proceed.
            return false;
        }

        if (!$row) {
            return false; // Signed in, but not a user on THIS account at all.
        }

        if ((int) $row->owner === 1) {
            return true;
        }

        $held = array_filter(array_map('trim', explode(',', (string) $row->permissions)));

        foreach ($needed as $one) {
            if (!in_array($one, $held, true)) {
                return false;
            }
        }

        return true;
    }

    /** Forget what was remembered for the request that ran last. @see \MagizAI\Whmcs\Action\Runner::forget */
    public static function forget()
    {
        self::$usersTable = null;
    }
}
