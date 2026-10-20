<?php

namespace MagizAI\Whmcs\Install;

use MagizAI\Whmcs\Rollout;
use MagizAI\Whmcs\Settings;
use WHMCS\Database\Capsule;

/**
 * The upgrade step (magizai_upgrade), which WHMCS runs the first time the module is opened after new
 * files were uploaded over older ones.
 *
 * An upgrade keeps everything an install runs with. It creates what is missing, switches on the
 * reads each release added, and writes down the old value of any default a release shortened, so a
 * new default only ever applies to a new install. It never switches on anything that changes a
 * client's account, raises an invoice or reveals a password.
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class Upgrade
{
    /** @param array $vars what WHMCS passes: 'version' is the version installed before these files */
    public static function run($vars)
    {
        Tables::install();

        /*
         * 1.7.0: the "MagizAI verification code" email template, created on every upgrade when it is
         * missing and never overwritten once the merchant edited it. "Send chat verification codes by
         * email" needs no stored value: it is on unless an admin unticks it. A template that cannot be
         * created now is created by the next send or the daily cron. @see EmailTemplate
         */
        try {
            EmailTemplate::install();
        } catch (\Throwable $e) {
            if (function_exists('logActivity')) {
                logActivity('MagizAI live chat: the verification code email template could not be created during the upgrade. It is tried again on the next send.');
            }
        }

        // A copy of every current setting, so the NEXT deactivation cannot lose them. @see Tables::KEPT
        Settings::snapshot();

        $from = isset($vars['version']) ? (string) $vars['version'] : '0';

        /*
         * The per-version lists below switch on the reads each release added, through Rollout::switchOn,
         * which never switches on an action this install has already been offered: if MagizAI's first
         * request after the upload already ran the rollout (Rollout::whenNeeded), an action the admin
         * switched off since then stays off when this page is finally opened.
         */

        // 1.1.0 added the password reset action. Installs from before it keep their saved action list,
        // so the new action is switched on for them explicitly; admins can still turn it off.
        if (version_compare($from, '1.1.0', '<')) {
            Rollout::switchOn(array('password_reset_email'));
        }

        // 1.3.0 added the Site Doctor ownership check, which MagizAI calls before it inspects a server
        // for a client. Without it, site checks from chat are refused.
        if (version_compare($from, '1.3.0', '<')) {
            Rollout::switchOn(array('hosting_accounts'));
        }

        /*
         * 1.4.0 added ordering, upgrades, renewals, extras and a password reset.
         *
         * The ones that only LOOK are switched on, exactly as 1.1.0 and 1.3.0 did for theirs: an
         * existing install keeps the action list it was activated with, so a new read action stays
         * dead forever unless it is added here, and a host who upgraded would simply never get it.
         *
         * Everything that creates an invoice or changes an account stays OFF. An upgrade must never
         * start charging a host's clients because somebody uploaded new files.
         */
        /*
         * 1.5.0 lets a signed-in client get their own service login details, and asks how to start using
         * a service. Both are reads of the client's own account, so both are switched on — the password
         * is EMAILED unless the admin separately ticks "show in chat", which upgrading never does.
         */
        if (version_compare($from, '1.5.0', '<')) {
            Rollout::switchOn(array('service_credentials', 'service_getting_started'));
        }

        if (version_compare($from, '1.4.0', '<')) {
            Rollout::switchOn(array('service_upgrade_options', 'addon_catalog', 'client_area_link'));
        }

        /*
         * 1.6.0 shortened two defaults for NEW installs: "account changes need a sign-in within" (15
         * minutes; it was 60, the same as the token lifetime, so it added nothing) and the identity token
         * lifetime (15 minutes; the chat now fetches a fresh token before one runs out). An existing
         * install keeps what it runs today: one that never stored a value was running on the old default,
         * so that is written now, explicitly. A value the admin saved is left exactly as it is.
         *
         * Nothing else changes for an existing install. The new tables come from Tables::install() above;
         * staff view stays off and placement stays as it was, because neither has a stored value to
         * change and their defaults are the old behaviour.
         */
        if (version_compare($from, '1.6.0', '<')) {
            $stored = Capsule::table('tbladdonmodules')->where('module', Settings::MODULE)->pluck('setting');
            self::keepEarlierDefaults(is_array($stored) ? $stored : $stored->all());

            /*
             * 1.6.0 also adds four look-ups: payment status, order status, renewal status and the email
             * history. They only read the client's own account, so they are switched on, exactly as
             * every earlier release switched on its reads. Sending an email again is a change made in
             * the client's name, so it stays off until an admin ticks it, here and on a new install.
             */
            Rollout::switchOn(array('payment_status', 'order_status', 'renewal_status', 'email_history'));
        }

        /*
         * 1.9.0: tickets in the MagizAI inbox are on by default. An install upgraded from a version
         * that had no such setting (before 1.7.0) gets it switched on, as a new install does. One from
         * 1.7.0 or later keeps what it stored, which may be an admin's own "off". Tickets are shared only
         * with the MagizAI workspace this module is connected to. @see Defaults, Staff\TicketInbox
         */
        if (version_compare($from, '1.7.0', '<')) {
            $stored = Capsule::table('tbladdonmodules')->where('module', Settings::MODULE)->where('setting', 'tickets_inbox')->exists();
            if (!$stored) {
                Settings::set('tickets_inbox', 'on');
            }
        }

        /*
         * Every read released after $from, including any a future release adds without a block of its
         * own above, and the ledger that stops all of them being switched on twice. A failure here does
         * not stop the upgrade: MagizAI's next request runs the same rollout. @see Rollout
         */
        try {
            Rollout::afterUpgrade($from);
        } catch (\Throwable $e) {
            if (function_exists('logActivity')) {
                logActivity('MagizAI live chat: new read actions could not be switched on during the upgrade. They are tried again on the next chat request.');
            }
        }
    }

    /**
     * The values an install ran with before 1.6.0 shortened them for new installs, written down where
     * nothing is stored, so updating changes nothing for it. A value already stored is never touched.
     *
     * @param string[] $stored the settings this install already has
     * @return string[] the settings written
     */
    public static function keepEarlierDefaults(array $stored)
    {
        $written = array();

        foreach (array('write_freshness_minutes' => '60', 'identity_ttl_minutes' => '60') as $key => $value) {
            if (!in_array($key, $stored, true)) {
                Settings::set($key, $value);
                $written[] = $key;
            }
        }

        return $written;
    }
}
