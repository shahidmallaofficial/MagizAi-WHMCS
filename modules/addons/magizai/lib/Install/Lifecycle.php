<?php

namespace MagizAI\Whmcs\Install;

use MagizAI\Whmcs\Action\Registry;
use MagizAI\Whmcs\Crypto;
use MagizAI\Whmcs\Persistence\KeptSettings;
use MagizAI\Whmcs\Rollout;
use MagizAI\Whmcs\Settings;
use WHMCS\Database\Capsule;

/**
 * Activating and deactivating the module (magizai_activate, magizai_deactivate).
 *
 * Deactivating keeps a copy of the settings, secrets included, so an update by deactivate, upload,
 * activate changes nothing; activating puts that copy back. @see Tables::KEPT
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class Lifecycle
{
    /** @return array{status: string, description: string} what WHMCS shows the admin */
    public static function activate()
    {
        try {
            Tables::install();

            // Deactivating deleted every setting. Put back what this install had, bridge secret included,
            // so MagizAI's copy of the secret still matches and nothing has to be pasted again.
            $restored = Settings::restoreKept();

            // Only settings never stored before get their default: an admin who switched something off
            // and re-activates the module keeps it off.
            $stored = Capsule::table('tbladdonmodules')->where('module', Settings::MODULE)->pluck('setting');
            $stored = is_array($stored) ? $stored : $stored->all();

            // No action list stored or restored: a new install, whose defaults decide what is on.
            $fresh = !in_array('enabled_actions', $stored, true);

            // Settings came back from an earlier version: what it ran with is kept, never a newer
            // default. @see Upgrade::keepEarlierDefaults
            if (!$fresh) {
                $stored = array_merge($stored, Upgrade::keepEarlierDefaults($stored));
            }

            foreach (Settings::defaults() as $key => $value) {
                if (!in_array($key, $stored, true)) {
                    Settings::set($key, $value);
                }
            }

            if (Settings::get('bridge_secret') === '') {
                Settings::set('bridge_secret', Crypto::newBridgeSecret());
            }

            // The email that carries the chat's verification code (1.7.0). An edited one is kept as it
            // is; one that cannot be made now is made by the next send or the daily cron.
            try {
                EmailTemplate::install();
            } catch (\Throwable $e) {
                // See above.
            }

            /*
             * WHAT THIS VERSION ADDED SINCE THE KEPT COPY WAS TAKEN. Activating writes the new version
             * itself, so WHMCS never runs the upgrade step after deactivate, upload, activate, and a new
             * read action used to stay off on that install for good. New reads are switched on here,
             * exactly as the upgrade step does; changes and anything sensitive never are. If this fails,
             * the next request from MagizAI does it instead. @see Rollout
             */
            $added = array();
            try {
                $added = Rollout::afterActivation($fresh);
            } catch (\Throwable $e) {
                $added = array();
            }

            $labels = array();
            $catalog = Registry::catalog();
            foreach ($added as $action) {
                $labels[] = isset($catalog[$action]['label']) ? $catalog[$action]['label'] : $action;
            }

            // Said out loud, because before 1.5.7 deactivating DID replace the secrets, and an admin
            // who suspects a leak may still expect it to.
            return array('status' => 'success', 'description' => $restored > 0
                ? 'MagizAI is active again, with the settings, bridge secret and action choices it had before. Nothing needs pasting again. '
                    . 'Deactivating does not replace the secrets. To replace them, use "Rotate all secrets" on the Setup tab.'
                    . ($labels ? ' New in this version and switched on, because they only look things up: ' . implode('; ', $labels) . '.' : '')
                : 'MagizAI is active. Open Addons → MagizAI Live Chat to finish setup.');
        } catch (\Throwable $e) {
            return array('status' => 'error', 'description' => 'MagizAI could not be activated: ' . $e->getMessage());
        }
    }

    /** @return array{status: string, description: string} what WHMCS shows the admin */
    public static function deactivate()
    {
        try {
            // Taken while WHMCS still holds the settings, in case this install never saved a copy —
            // unless the admin chose "Forget the kept copy", in which case nothing is kept at all.
            $keeping = KeptSettings::keeping();
            if ($keeping) {
                Settings::snapshot();
            } else {
                KeptSettings::forget();
            }
            Tables::uninstall(!$keeping);

            return array('status' => 'success', 'description' => $keeping
                ? 'MagizAI is deactivated. The activity log and your settings were kept, so activating it again restores them.'
                : 'MagizAI is deactivated. The activity log was kept. The settings and secrets were removed, as you chose.');
        } catch (\Throwable $e) {
            return array('status' => 'error', 'description' => $e->getMessage());
        }
    }
}
