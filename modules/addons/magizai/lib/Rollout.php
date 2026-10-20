<?php

namespace MagizAI\Whmcs;

use MagizAI\Whmcs\Action\Registry;
use WHMCS\Database\Capsule;

/**
 * New read actions reach every install, however the module was updated.
 *
 * WHY THIS EXISTS. An install keeps the action list it was activated with, so an action added in a
 * later release is off until something switches it on. The upgrade step did that, one version at a
 * time, but WHMCS runs the upgrade step only when it sees a stored version older than the files.
 * Updating by deactivating, uploading and activating (which 1.5.7 made safe, and many admins do)
 * restores the kept settings and writes the new version itself, so the upgrade step never runs and
 * a new read action stayed off on that install for good.
 *
 * THE LEDGER. `actions_seen` lists every action this install has already been offered. It is an
 * ordinary setting, so the kept copy carries it through a deactivation. An action that is not in it
 * is new to this install: a read is switched on, once, and recorded as seen. An action that is in it
 * is never switched on again, so an admin who switched one off keeps it off.
 *
 * WHAT IS SWITCHED ON. Reads only. Nothing that changes a client's account, raises an invoice or is
 * marked sensitive is ever switched on by an update: those wait for an admin to tick them.
 *
 * WHEN IT RUNS. After an upgrade, after an activation, and on a bridge request whenever the action
 * catalogue holds something the ledger has not seen, because a merchant who uploads new files over
 * the old ones may never open the admin page that runs the upgrade step.
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class Rollout
{
    /** The setting that lists every action this install has already been offered. */
    const LEDGER = 'actions_seen';

    /**
     * The first version whose settings survive a deactivation. A kept copy with no ledger was taken
     * by this version (the ledger came later), so it had every action released up to here.
     */
    const KEPT_SINCE = '1.5.7';

    /**
     * The release that first shipped each action, from the changelog. Every action in
     * Action\Registry needs one: an action missing here counts as already seen, so it is never
     * switched on by an update (WhmcsModuleContractTest keeps the two lists in step).
     */
    const SINCE = array(
        'kb_search' => '1.0.0',
        'announcements' => '1.0.0',
        'network_status' => '1.0.0',
        'product_catalog' => '1.0.0',
        'domain_check' => '1.0.0',
        'tld_pricing' => '1.0.0',
        'client_summary' => '1.0.0',
        'services_list' => '1.0.0',
        'service_details' => '1.0.0',
        'domains_list' => '1.0.0',
        'domain_details' => '1.0.0',
        'invoices_list' => '1.0.0',
        'invoice_details' => '1.0.0',
        'tickets_list' => '1.0.0',
        'ticket_details' => '1.0.0',
        'domain_update_nameservers' => '1.0.0',
        'domain_set_autorenew' => '1.0.0',
        'ticket_open' => '1.0.0',
        'ticket_reply' => '1.0.0',
        'service_cancel_request' => '1.0.0',
        'domain_send_epp' => '1.0.0',
        'password_reset_email' => '1.1.0',
        'hosting_accounts' => '1.3.0',
        'order_place' => '1.4.0',
        'service_upgrade_options' => '1.4.0',
        'addon_catalog' => '1.4.0',
        'client_area_link' => '1.4.0',
        'service_upgrade' => '1.4.0',
        'service_renew' => '1.4.0',
        'domain_renew' => '1.4.0',
        'addon_order' => '1.4.0',
        'service_password_reset' => '1.4.0',
        'service_credentials' => '1.5.0',
        'service_getting_started' => '1.5.0',
        // Four look-ups, switched on by an update like every earlier release's, and one change
        // (resending an email), which an update never switches on.
        'payment_status' => '1.6.0',
        'order_status' => '1.6.0',
        'renewal_status' => '1.6.0',
        'email_history' => '1.6.0',
        'email_resend' => '1.6.0',
        // Switched on and off by its own Setup tab setting, never by the action list. @see Action\Registry::SETTING_SWITCHES
        'verification_send' => '1.7.0',
        // Three changes, so an update switches none of them on: closing a ticket, opening an account for a
        // new client, and the sign-in link that follows it. @see Action\Registry::FOLLOWS
        'ticket_close' => '1.8.0',
        'client_signup' => '1.8.0',
        'signup_sign_in' => '1.8.0',
        // Changes, so an update switches none of them on: the registrar lock and a domain's contact details.
        'domain_set_lock' => '1.10.0',
        'domain_update_contacts' => '1.10.0',
        // A change that moves the client's money: paying an invoice from the account credit.
        'invoice_apply_credit' => '1.10.0',
        // A change on the client's server, offered only for the commands the admin lists.
        'service_module_command' => '1.10.0',
    );

    /**
     * After WHMCS ran the upgrade step from version $from: every action released after it is new.
     *
     * @return string[] the actions switched on
     */
    public static function afterUpgrade($from)
    {
        return self::run(self::baseline($from));
    }

    /**
     * After activation.
     *
     * @param bool $fresh no action list was stored or restored: a new install, whose defaults decide
     *                    what is on. Every action counts as seen from here on.
     * @return string[] the actions switched on
     */
    public static function afterActivation($fresh)
    {
        if ($fresh) {
            self::writeLedger(Registry::names());

            return array();
        }

        // Kept settings came back. With no ledger they were taken by a version from before it.
        return self::run(self::KEPT_SINCE);
    }

    /**
     * On a bridge request: only when the catalogue holds an action the ledger has not seen, which
     * after the first run means only after new files were uploaded. Cheap otherwise.
     *
     * @return string[] the actions switched on
     */
    public static function whenNeeded()
    {
        $ledger = self::ledger();
        if ($ledger !== null && !array_diff(Registry::names(), $ledger)) {
            return array();
        }

        // The upgrade step has not run, so the version WHMCS recorded is the one the files replaced.
        $recorded = (string) Capsule::table('tbladdonmodules')
            ->where('module', Settings::MODULE)->where('setting', 'version')->value('value');

        return self::run(self::baseline($recorded));
    }

    /**
     * Switch these actions on, unless this install has already been offered them. For the upgrade
     * step's own per-version lists, so they can never undo an admin's choice either.
     *
     * @param string[] $actions
     * @return string[] the actions switched on
     */
    public static function switchOn(array $actions)
    {
        $seen = self::ledger();
        $enabled = Settings::enabledActions();
        $added = array();

        foreach ($actions as $action) {
            if (in_array($action, $enabled, true) || ($seen !== null && in_array($action, $seen, true))) {
                continue;
            }
            $enabled[] = $action;
            $added[] = $action;
        }

        if ($added) {
            Settings::set('enabled_actions', json_encode(array_values($enabled)));
        }

        return $added;
    }

    /**
     * The decision itself, with no database, so it can be tested with actions this version does not
     * have yet.
     *
     * @param array<string, array> $catalog the action catalogue, as Registry::catalog() returns it
     * @param string[]             $enabled what is switched on now
     * @param string[]             $seen    what this install has already been offered
     * @return array{enabled: string[], added: string[], seen: string[]}
     */
    public static function plan(array $catalog, array $enabled, array $seen)
    {
        $added = array();

        foreach ($catalog as $action => $spec) {
            if (in_array($action, $seen, true) || in_array($action, $enabled, true)) {
                continue;
            }
            if (self::switchesOnByItself(is_array($spec) ? $spec : array())) {
                $enabled[] = $action;
                $added[] = $action;
            }
        }

        return array(
            'enabled' => array_values($enabled),
            'added' => $added,
            // Old entries are kept: after a downgrade and an upgrade again, an action the admin had
            // already been offered is still not switched back on.
            'seen' => array_values(array_unique(array_merge($seen, array_keys($catalog)))),
        );
    }

    /** Only a read switches itself on. Changes, invoices and anything marked sensitive never do. */
    public static function switchesOnByItself(array $spec)
    {
        return empty($spec['write']) && (isset($spec['group']) ? (string) $spec['group'] : '') !== 'sensitive';
    }

    /**
     * What a version up to $baseline already had, by release.
     *
     * @param string[] $actions
     * @return string[]
     */
    public static function seenBy($baseline, array $actions)
    {
        $seen = array();
        $since = self::SINCE;

        foreach ($actions as $action) {
            // No recorded release: counted as seen, so it is never switched on blindly.
            if (!isset($since[$action]) || version_compare($since[$action], (string) $baseline, '<=')) {
                $seen[] = $action;
            }
        }

        return $seen;
    }

    /** @return string[] the actions switched on */
    private static function run($baseline)
    {
        $catalog = Registry::catalog();
        $ledger = self::ledger();
        $seen = $ledger !== null ? $ledger : self::seenBy($baseline, array_keys($catalog));

        $plan = self::plan($catalog, Settings::enabledActions(), $seen);

        if ($plan['added']) {
            Settings::set('enabled_actions', json_encode($plan['enabled']));
        }
        if ($ledger === null || array_diff($plan['seen'], $ledger)) {
            self::writeLedger($plan['seen']);
        }

        return $plan['added'];
    }

    /**
     * A version as x.y.z. One that cannot be read is taken to be the last version without a ledger,
     * which is the only kind of install that reaches here without one.
     */
    private static function baseline($version)
    {
        $normal = Compat::normaliseVersion($version);

        return $normal !== '' ? $normal : self::KEPT_SINCE;
    }

    /**
     * @return string[]|null null when this install has no ledger yet. An empty or unreadable one
     *                       counts as none: read as "nothing seen", it would switch on every read.
     */
    private static function ledger()
    {
        $raw = trim((string) Settings::get(self::LEDGER, ''));
        if ($raw === '') {
            return null;
        }

        $list = json_decode($raw, true);
        if (!is_array($list)) {
            return null;
        }

        $out = array();
        foreach ($list as $action) {
            if (is_string($action) && $action !== '') {
                $out[] = $action;
            }
        }

        return $out ? $out : null;
    }

    /** @param string[] $actions */
    private static function writeLedger(array $actions)
    {
        Settings::set(self::LEDGER, json_encode(array_values(array_unique($actions))));
    }
}
