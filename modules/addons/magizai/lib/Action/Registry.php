<?php

namespace MagizAI\Whmcs\Action;

/**
 * Every action the assistant can ask WHMCS for, in the order the admin page lists them and the
 * stored action list keeps them, and the rules that read what each one is.
 *
 * The map holds class NAMES (::class in a constant loads nothing): a request loads only the one
 * action it runs. The catalogue itself is built from each action's own spec(), so what an action
 * is lives in one place, next to what it does.
 *
 * The catalogue must stay in step with WhmcsConnector::ACTIONS on the MagizAI side.
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class Registry
{
    /** Action name => the class that runs it, in the catalogue's order. */
    const ACTIONS = array(
        'kb_search' => Catalog\KbSearch::class,
        'announcements' => Catalog\Announcements::class,
        'network_status' => Catalog\NetworkStatus::class,
        'product_catalog' => Catalog\ProductCatalog::class,
        'domain_check' => Catalog\DomainCheck::class,
        'tld_pricing' => Catalog\TldPricing::class,
        'client_summary' => Account\ClientSummary::class,
        'services_list' => Services\ServicesList::class,
        'service_details' => Services\ServiceDetails::class,
        'domains_list' => Domains\DomainsList::class,
        'domain_details' => Domains\DomainDetails::class,
        'invoices_list' => Billing\InvoicesList::class,
        'invoice_details' => Billing\InvoiceDetails::class,
        'tickets_list' => Tickets\TicketsList::class,
        'ticket_details' => Tickets\TicketDetails::class,
        'service_upgrade_options' => Services\ServiceUpgradeOptions::class,
        'addon_catalog' => Services\AddonCatalog::class,
        'client_area_link' => Account\ClientAreaLink::class,
        'password_reset_email' => Account\PasswordResetEmail::class,
        'domain_update_nameservers' => Domains\DomainUpdateNameservers::class,
        'domain_set_autorenew' => Domains\DomainSetAutorenew::class,
        'ticket_open' => Tickets\TicketOpen::class,
        'ticket_reply' => Tickets\TicketReply::class,
        'email_resend' => Account\EmailResend::class,
        'service_cancel_request' => Services\ServiceCancelRequest::class,
        'order_place' => Orders\OrderPlace::class,
        'service_upgrade' => Services\ServiceUpgrade::class,
        'service_renew' => Services\ServiceRenew::class,
        'domain_renew' => Domains\DomainRenew::class,
        'addon_order' => Services\AddonOrder::class,
        'service_password_reset' => Services\ServicePasswordReset::class,
        'service_credentials' => Services\ServiceCredentials::class,
        'service_getting_started' => Services\ServiceGettingStarted::class,
        'domain_send_epp' => Domains\DomainSendEpp::class,
        'hosting_accounts' => Account\HostingAccounts::class,
        'payment_status' => Billing\PaymentStatus::class,
        'order_status' => Orders\OrderStatus::class,
        'renewal_status' => Services\RenewalStatus::class,
        'email_history' => Account\EmailHistory::class,
        'verification_send' => Account\VerificationSend::class,
        // 1.8.0
        'ticket_close' => Tickets\TicketClose::class,
        'client_signup' => Account\ClientSignup::class,
        'signup_sign_in' => Account\SignupSignIn::class,
        // 1.10.0
        'domain_set_lock' => Domains\DomainSetLock::class,
        'domain_update_contacts' => Domains\DomainUpdateContacts::class,
        'invoice_apply_credit' => Billing\InvoiceApplyCredit::class,
        'service_module_command' => Services\ServiceModuleCommand::class,
    );

    /**
     * Actions that are on exactly when another one is, with no switch of their own (1.8.0). The sign-in
     * link that lets a new client pay belongs to "Open an account": on with it, off with it. The Actions
     * tab shows it without a checkbox, and a stored action list never decides it.
     */
    const FOLLOWS = array('signup_sign_in' => 'client_signup');

    /**
     * Changes that start OFF on every install, new ones included, until an admin ticks them.
     *
     * Resending an email sends something in the client's name that they did not write. It is
     * harmless next to a cancellation, so it sits with the other changes, but a host should choose
     * it knowingly: it starts off like the sensitive ones, and no update ever switches it on.
     */
    const OFF_UNTIL_TICKED = array('email_resend');

    /**
     * Reads that SEND something on the client's behalf. Staff view refuses them like a change: a
     * staff member looking at an account must not have a password reset email land in the client's
     * mailbox because they tested the chat.
     */
    const STAFF_VIEW_REFUSED = array('password_reset_email', 'verification_send');

    /**
     * Actions switched on and off by a setting of their own on the Setup tab, not by the action list
     * (1.7.0). The Actions tab shows them without a checkbox, and an install's stored action list is
     * never what decides them. Refused as `not_enabled` while the setting is off.
     */
    const SETTING_SWITCHES = array('verification_send' => 'verification_email');

    /**
     * Arguments that carry a secret, by action: never written to any log, even when the action fails
     * in a way nobody planned for. @see \MagizAI\Whmcs\Http\BridgeKernel
     */
    const SECRET_ARGS = array('verification_send' => array('code'));

    /** @return string[] every action's name, in the catalogue's order, without loading any action */
    public static function names()
    {
        return array_keys(self::ACTIONS);
    }

    /** The class that runs $action, or null for one that does not exist. Names are exact. */
    public static function classFor($action)
    {
        return is_string($action) && isset(self::ACTIONS[$action]) ? self::ACTIONS[$action] : null;
    }

    /** @return ActionInterface|null */
    public static function make($action)
    {
        $class = self::classFor($action);

        return $class === null ? null : new $class();
    }

    /** @return array{identity: bool|string, write: bool, group: string, label: string}|null */
    public static function spec($action)
    {
        $instance = self::make($action);

        return $instance === null ? null : $instance->spec();
    }

    /** @return array<string, array{identity: bool|string, write: bool, group: string, label: string}> */
    public static function catalog()
    {
        $out = array();
        foreach (self::ACTIONS as $name => $class) {
            $instance = new $class();
            $out[$name] = $instance->spec();
        }

        return $out;
    }

    /**
     * Switched on at activation. Cancellations, transfer codes, anything that raises an invoice and
     * resending emails are left for the admin to decide. An action a setting switches is listed when
     * it starts on (verification_send does); its setting, not this list, decides it afterwards.
     */
    public static function defaultEnabled()
    {
        $out = array();
        foreach (self::catalog() as $key => $spec) {
            if ($spec['group'] !== 'sensitive' && !in_array($key, self::OFF_UNTIL_TICKED, true) && !isset(self::FOLLOWS[$key])) {
                $out[] = $key;
            }
        }

        return $out;
    }

    /**
     * Did this successful run CHANGE anything? What the bridge records it as.
     *
     * A write action can end having done nothing: a renewal that an open invoice already covers
     * orders nothing and says `changed: false`. Recorded as a change, it used up one of the client's
     * account changes for the hour, carried the "change" mark in the activity log, and was copied to
     * WHMCS's own activity log as a renewal. It is recorded as what it was, a look-up. A read is
     * never a change, whatever it says.
     *
     * @param string $action the action that ran
     * @param array  $result what it returned
     * @return bool
     */
    public static function changedAnything($action, array $result)
    {
        $spec = self::spec($action);
        if ($spec === null || !$spec['write']) {
            return false;
        }

        return !(array_key_exists('changed', $result) && $result['changed'] === false);
    }

    /**
     * May a staff member in staff view run this action? Look-ups only: every change, and every read
     * that sends something, is refused (the bridge answers `staff_write_refused`). A service login
     * is answered with the username only. @see Services\ServiceCredentials
     */
    public static function staffMayRun($action)
    {
        $spec = self::spec($action);

        return $spec !== null && !$spec['write'] && !in_array($action, self::STAFF_VIEW_REFUSED, true);
    }

    /**
     * What is switched on: the stored action list, except the actions a setting of their own decides,
     * which are on exactly when that setting is not "off". Loads no action class.
     *
     * @param array $stored   the stored action list, as decoded
     * @param array $settings every setting, as stored (the defaults filled in)
     * @return string[]
     */
    public static function switchedOn(array $stored, array $settings)
    {
        $out = array_values(array_diff(array_intersect($stored, self::names()), array_keys(self::SETTING_SWITCHES), array_keys(self::FOLLOWS)));
        foreach (self::SETTING_SWITCHES as $action => $setting) {
            if (isset(self::ACTIONS[$action]) && (!isset($settings[$setting]) || $settings[$setting] !== 'off')) {
                $out[] = $action;
            }
        }
        foreach (self::FOLLOWS as $action => $leader) {
            if (isset(self::ACTIONS[$action]) && in_array($leader, $out, true)) {
                $out[] = $action;
            }
        }

        return $out;
    }

    /** The secret values in $args (the verification code), for masking out of anything logged. */
    public static function secretValues($action, array $args)
    {
        $out = array();
        foreach (isset(self::SECRET_ARGS[$action]) ? self::SECRET_ARGS[$action] : array() as $key) {
            if (isset($args[$key]) && is_scalar($args[$key]) && (string) $args[$key] !== '') {
                $out[] = (string) $args[$key];
            }
        }

        return $out;
    }

    /** $args as a log may hold them: every secret argument replaced. */
    public static function argsForTheLog($action, array $args)
    {
        foreach (isset(self::SECRET_ARGS[$action]) ? self::SECRET_ARGS[$action] : array() as $key) {
            if (array_key_exists($key, $args)) {
                $args[$key] = '[not logged]';
            }
        }

        return $args;
    }
}
