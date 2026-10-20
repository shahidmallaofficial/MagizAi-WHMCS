<?php

namespace MagizAI\Whmcs\Support;

use MagizAI\Whmcs\ActionError;
use MagizAI\Whmcs\Schema;
use MagizAI\Whmcs\Settings;
use WHMCS\Database\Capsule;

/**
 * Support departments, and which one a ticket from the chat goes to. A department WHMCS hides from
 * clients is never theirs to choose.
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class Departments
{
    /**
     * The department a chat ticket goes to: the one the client named, else the admin's default,
     * else the first one a client could choose.
     *
     * HIDDEN DEPARTMENTS ARE NOT THE CLIENT'S TO CHOOSE. The client area never lists them (Abuse,
     * Management, Billing escalations), but a name in a chat message used to match one anyway,
     * because GetSupportDepartments cannot say which are hidden. tblticketdepartments can.
     *
     * The admin's own default on the Setup tab is honoured whatever it is. A client cannot steer a
     * ticket there, the admin picked it on purpose (a hidden "Live chat" department is a common way
     * to keep chat tickets apart), and an update must not silently reroute an existing install's
     * tickets. The Setup tab marks a hidden department as such, so the choice is an informed one.
     */
    public static function department($name)
    {
        $depts = self::supportDepartments(true);
        $open = array_values(array_filter($depts, function ($d) {
            return !$d['hidden'];
        }));

        if ($name !== '') {
            foreach ($open as $d) {
                if (stripos((string) $d['name'], $name) !== false || stripos($name, (string) $d['name']) !== false) {
                    return (int) $d['id'];
                }
            }
        }

        $configured = (int) Settings::get('ticket_department_id');
        foreach ($depts as $d) {
            if ((int) $d['id'] === $configured) {
                return $configured;
            }
        }

        if (!$depts) {
            throw new \RuntimeException('No support departments exist.');
        }

        if (!$open) {
            throw new ActionError('not_allowed', 'Tickets cannot be opened from the chat at the moment. Offer to pass this to the team another way.');
        }

        return (int) $open[0]['id'];
    }

    /**
     * Every support department, each marked when WHMCS hides it from clients.
     *
     * GetSupportDepartments cannot say which departments the client area hides (Abuse, Management,
     * billing escalations); tblticketdepartments can. A hidden department is never matched from a
     * name a client typed, and its name is not published by the knowledge sync. Where the flag
     * cannot be read, no department is treated as hidden, as before.
     *
     * @param bool $strict throw when WHMCS cannot list them, rather than answer with none
     * @return array<int, array{id: int, name: string, hidden: bool}>
     */
    public static function supportDepartments($strict = false)
    {
        $r = WhmcsApi::api('GetSupportDepartments', array('ignore_dept_assignments' => true), (bool) $strict);

        $hidden = array();
        if (Schema::hasTable('tblticketdepartments') && Schema::hasColumn('tblticketdepartments', 'hidden')) {
            $ids = Capsule::table('tblticketdepartments')->where('hidden', 'on')->pluck('id');
            $hidden = array_map('intval', is_array($ids) ? $ids : $ids->all());
        }

        $out = array();
        foreach (WhmcsApi::rows($r, 'departments', 'department') as $d) {
            $out[] = array(
                'id' => (int) $d['id'],
                'name' => (string) $d['name'],
                'hidden' => in_array((int) $d['id'], $hidden, true),
            );
        }

        return $out;
    }
}
