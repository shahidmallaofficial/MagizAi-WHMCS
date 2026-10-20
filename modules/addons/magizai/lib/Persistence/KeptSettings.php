<?php

namespace MagizAI\Whmcs\Persistence;

use MagizAI\Whmcs\Install\Tables;
use MagizAI\Whmcs\Schema;
use MagizAI\Whmcs\Settings;
use WHMCS\Database\Capsule;

/**
 * A copy of the settings that survives deactivation. @see Tables::KEPT for why it exists.
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class KeptSettings
{
    /** WHMCS writes these rows for every addon itself; they are never ours to copy back. */
    const WHMCS_OWNED = array('version', 'access');

    /**
     * Is a copy of the settings being kept at all? Off only after "Forget the kept copy".
     *
     * Read straight from the row, not through Settings::all(): this runs on every Settings::set(),
     * and all() would decrypt every secret each time just to read one plain flag.
     */
    public static function keeping()
    {
        try {
            $flag = Capsule::table('tbladdonmodules')
                ->where('module', Settings::MODULE)->where('setting', 'keep_on_deactivate')->value('value');

            return $flag !== 'off';
        } catch (\Exception $e) {
            return true;
        }
    }

    /**
     * Copy one setting, exactly as stored (secrets stay encrypted with WHMCS's own key).
     *
     * Never throws: this is a backup of a write that has already succeeded, and a failure to back it
     * up must not turn a saved setting into an error on the admin page.
     */
    public static function keep($setting, $storedValue)
    {
        if (in_array($setting, self::WHMCS_OWNED, true) || !self::keeping()) {
            return;
        }

        try {
            if (!Schema::hasTable(Tables::KEPT)) {
                return;
            }

            $row = array('value' => (string) $storedValue, 'updated_at' => date('Y-m-d H:i:s'));
            if (Capsule::table(Tables::KEPT)->where('setting', $setting)->exists()) {
                Capsule::table(Tables::KEPT)->where('setting', $setting)->update($row);
            } else {
                Capsule::table(Tables::KEPT)->insert(array('setting' => $setting) + $row);
            }
        } catch (\Exception $e) {
            // See above.
        }
    }

    /** @return array<string, string> every kept setting, exactly as stored */
    public static function kept()
    {
        try {
            if (!Schema::hasTable(Tables::KEPT)) {
                return array();
            }

            $out = array();
            foreach (Capsule::table(Tables::KEPT)->get(array('setting', 'value')) as $row) {
                $out[(string) $row->setting] = (string) $row->value;
            }

            return $out;
        } catch (\Exception $e) {
            return array();
        }
    }

    /**
     * Delete the kept copy of the settings, secrets included.
     *
     * @return int how many kept settings were removed
     */
    public static function forget()
    {
        if (!Schema::hasTable(Tables::KEPT)) {
            return 0;
        }

        return (int) Capsule::table(Tables::KEPT)->delete();
    }
}
