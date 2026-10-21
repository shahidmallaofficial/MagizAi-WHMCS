<?php

namespace MagizAI\Whmcs\Admin;

use MagizAI\Whmcs\Action\Registry;
use MagizAI\Whmcs\Settings;
use MagizAI\Whmcs\Text;
use WHMCS\Database\Capsule;

/**
 * Saving the "What the assistant may do" tab (1.10.1), and saying exactly what WHMCS kept.
 *
 * ONE FIELD PER ACTION. Up to 1.10.0 the tab posted the ticked boxes as one repeated `actions[]`
 * list. On two real installs (WHMCS 8.13.1 and 9.0.9) boxes ticked there never reached PHP: the page
 * said the save worked and the boxes came back unticked. What dropped them is not known. Each action
 * now posts a field of its own, `act_<name>`: a hidden 0, then the checkbox's 1. Of two fields with
 * one name PHP keeps the last, so a ticked box reads 1 and an unticked one 0, and a field that never
 * arrived is told apart from a box left unticked: that action stays as it was, and the page says so.
 *
 * READ BACK. After the write, the list is read again straight from tbladdonmodules, not from this
 * request's cached settings, and compared with what was asked. Anything WHMCS did not keep is named
 * in an error, never in a success message.
 *
 * REMEMBERED. Every save is noted in one setting (actions_last_save: action names and counts, never a
 * secret), which the ping hands to MagizAI, so a save that went wrong can be seen from there. @see report
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class ActionsSave
{
    /** Each action's own field: act_<name>. */
    const FIELD = 'act_';

    /** A hidden field that says the page posts one field per action. Its value is how many it has. */
    const FORM = 'actions_form';

    /** The setting the last save is noted in, as JSON. Not kept through a deactivation. */
    const LAST = 'actions_last_save';

    /** The most of the raw request body read to compare it with what PHP made of it. */
    const BODY_MAX = 262144;

    /** The most actions a message names one by one. */
    const LABELS_MAX = 10;

    /**
     * The actions the tab has a box for, in the catalogue's order: not one a Setup tab setting
     * switches, nor one that follows another. Loads no action class.
     * @see Registry::SETTING_SWITCHES, Registry::FOLLOWS
     *
     * @return string[]
     */
    public static function choosable()
    {
        return array_values(array_diff(Registry::names(), array_keys(Registry::SETTING_SWITCHES), array_keys(Registry::FOLLOWS)));
    }

    /**
     * Save what the form posted, read the list back and say what changed.
     *
     * @return string the notice the page shows
     * @throws \RuntimeException naming what WHMCS did not keep, or the boxes that never arrived
     */
    public static function save()
    {
        $choosable = self::choosable();
        $before = array_values(array_intersect($choosable, Settings::enabledActions()));
        $posted = self::fields($_POST, $choosable);

        if ($posted || isset($_POST[self::FORM])) {
            $asked = array();
            foreach ($choosable as $name) {
                // A box whose field never arrived stays as it was.
                if (isset($posted[$name]) ? $posted[$name] : in_array($name, $before, true)) {
                    $asked[] = $name;
                }
            }
            $form = 'fields';
            $missing = array_values(array_diff($choosable, array_keys($posted)));
        } else {
            // The 1.10.0 page, still open in a browser: the ticked boxes as one actions[] list.
            $chosen = isset($_POST['actions']) && is_array($_POST['actions']) ? array_filter($_POST['actions'], 'is_string') : array();
            $asked = array_values(array_intersect($choosable, $chosen));
            $form = 'legacy';
            $missing = array();
        }

        $why = '';
        try {
            Settings::set('enabled_actions', json_encode($asked));
        } catch (\Throwable $e) {
            $why = Text::cut($e->getMessage(), 200);
        }

        $stored = self::storedList();
        $stillOff = $stored === null ? $asked : array_values(array_diff($asked, $stored));
        $stillOn = $stored === null ? array() : array_values(array_diff(array_intersect($choosable, $stored), $asked));
        $kept = $stored !== null && !$stillOff && !$stillOn;
        $on = array_values(array_diff($asked, $before));
        $off = array_values(array_diff($before, $asked));
        $body = self::bodyFields($choosable);

        self::note(array(
            'at' => time(),
            'form' => $form,
            'posted_fields' => count($posted),
            'not_posted' => $missing,
            'asked_on' => $on,
            'asked_off' => $off,
            'kept' => $kept,
            'still_off' => $stillOff,
            'still_on' => $stillOn,
            'body_fields' => $body === null ? null : count($body),
            'body_agrees' => $body === null ? null : $body === $posted,
        ));

        $total = self::onNow();
        if ($stored === null) {
            throw new \RuntimeException('WHMCS could not read the action list back after saving' . ($why !== '' ? ' (' . $why . ')' : '')
                . ', so this save is not confirmed. Open this tab again to see what is stored.');
        }
        if (!$kept) {
            throw new \RuntimeException('WHMCS did not keep this save.'
                . ($stillOff ? ' Ticked but still off: ' . self::labels($stillOff) . '.' : '')
                . ($stillOn ? ' Unticked but still on: ' . self::labels($stillOn) . '.' : '')
                . ($why !== '' ? ' WHMCS said: ' . $why . '.' : '')
                . ' ' . $total . ' Press Save again. If the same actions come back, tell MagizAI support: MagizAI sees this save at its next connection check.');
        }

        $changes = 'Switched on: ' . self::labels($on) . '. Switched off: ' . self::labels($off) . '. ' . $total;
        if ($missing) {
            throw new \RuntimeException(count($missing) . ' of the ' . count($choosable) . ' action boxes did not reach WHMCS, so '
                . (count($missing) === 1 ? 'it stayed as it was' : 'they stayed as they were') . ': ' . self::labels($missing) . '. ' . $changes);
        }

        return $changes;
    }

    /**
     * The last save as the ping reports it to MagizAI: an ISO time, action names and counts, never a
     * secret. Null when nothing has been saved since 1.10.1.
     *
     * @return array{at: string, form: string, posted_fields: int, not_posted: string[], asked_on: string[], asked_off: string[], kept: bool, still_off: string[], still_on: string[], body_fields: int|null, body_agrees: bool|null}|null
     */
    public static function report()
    {
        $raw = json_decode((string) Settings::get(self::LAST, ''), true);
        if (!is_array($raw) || !isset($raw['at']) || !is_numeric($raw['at'])) {
            return null;
        }
        $names = function ($key) use ($raw) {
            return isset($raw[$key]) && is_array($raw[$key]) ? array_values(array_filter($raw[$key], 'is_string')) : array();
        };

        return array(
            'at' => gmdate('Y-m-d\TH:i:s\Z', max(0, (int) $raw['at'])),
            'form' => isset($raw['form']) && $raw['form'] === 'legacy' ? 'legacy' : 'fields',
            'posted_fields' => isset($raw['posted_fields']) ? max(0, (int) $raw['posted_fields']) : 0,
            'not_posted' => $names('not_posted'),
            'asked_on' => $names('asked_on'),
            'asked_off' => $names('asked_off'),
            'kept' => isset($raw['kept']) && $raw['kept'] === true,
            'still_off' => $names('still_off'),
            'still_on' => $names('still_on'),
            'body_fields' => isset($raw['body_fields']) && is_int($raw['body_fields']) ? max(0, $raw['body_fields']) : null,
            'body_agrees' => isset($raw['body_agrees']) && is_bool($raw['body_agrees']) ? $raw['body_agrees'] : null,
        );
    }

    /**
     * Each action's own field in $fields, as on (true) or off (false), in the catalogue's order. A
     * field that is not there, or is not a plain value, is left out.
     *
     * @return array<string, bool>
     */
    private static function fields(array $fields, array $choosable)
    {
        $out = array();
        foreach ($choosable as $name) {
            if (isset($fields[self::FIELD . $name]) && is_string($fields[self::FIELD . $name])) {
                $out[$name] = $fields[self::FIELD . $name] === '1';
            }
        }

        return $out;
    }

    /**
     * The same fields read from the raw request body, as PHP itself parses it, so the note can say
     * whether what PHP handed this page is what the browser sent. Null when the body cannot be read
     * (empty, as in a multipart post or from the command line, or too long).
     *
     * @return array<string, bool>|null
     */
    private static function bodyFields(array $choosable)
    {
        try {
            $raw = file_get_contents('php://input', false, null, 0, self::BODY_MAX);
        } catch (\Throwable $e) {
            return null;
        }
        if (!is_string($raw) || $raw === '' || strlen($raw) >= self::BODY_MAX) {
            return null;
        }
        $fields = array();
        parse_str($raw, $fields);

        return self::fields($fields, $choosable);
    }

    /**
     * The stored action list as tbladdonmodules holds it now. Null when it cannot be read.
     *
     * @return string[]|null
     */
    private static function storedList()
    {
        try {
            $raw = Capsule::table('tbladdonmodules')->where('module', Settings::MODULE)->where('setting', 'enabled_actions')->value('value');
        } catch (\Throwable $e) {
            return null;
        }
        $list = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($list) ? array_values(array_filter($list, 'is_string')) : null;
    }

    /** One settings write for the save. A note that cannot be written never hides the answer. */
    private static function note(array $save)
    {
        try {
            Settings::setTransient(self::LAST, (string) json_encode($save));
        } catch (\Throwable $e) {
            // The page still says what happened.
        }
    }

    /** "41 actions are on.": everything switched on now, the Setup tab's switches included. */
    private static function onNow()
    {
        $n = count(Settings::enabledActions());

        return $n === 1 ? '1 action is on.' : $n . ' actions are on.';
    }

    /**
     * The actions as the tab labels them, each in quotes (a label can hold commas of its own), or
     * "none". After LABELS_MAX, how many more: the note keeps every name.
     */
    private static function labels(array $names)
    {
        if (!$names) {
            return 'none';
        }
        $catalog = Registry::catalog();
        $labels = array();
        foreach (array_slice($names, 0, self::LABELS_MAX) as $name) {
            $labels[] = '"' . (isset($catalog[$name]) ? $catalog[$name]['label'] : $name) . '"';
        }
        $more = count($names) - count($labels);

        return implode(', ', $labels) . ($more > 0 ? ' and ' . $more . ' more' : '');
    }
}
