<?php

namespace MagizAI\Whmcs\Admin;

use MagizAI\Whmcs\Identity\Signing;
use MagizAI\Whmcs\Support\Departments;
use MagizAI\Whmcs\Text;

/**
 * What every part of the admin page builds its HTML with: one escaper, the form every tab posts,
 * a select's options, and the sentences that explain a setting.
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class View
{
    /** The page's escaper, as a closure: Text::html. */
    public static function escaper()
    {
        return function ($v) {
            return Text::html($v);
        };
    }

    /**
     * A form that posts `do` back to the tab it is on, as a closure: ($do, $body, $button = 'Save').
     * The CSRF token goes in when WHMCS gave one.
     */
    public static function form($link, $tab, $token)
    {
        $e = self::escaper();

        return function ($do, $body, $button = 'Save') use ($link, $tab, $token, $e) {
            return '<form method="post" action="' . $e($link . '&tab=' . $tab) . '">'
                . ($token !== '' ? '<input type="hidden" name="token" value="' . $e($token) . '">' : '')
                . '<input type="hidden" name="do" value="' . $e($do) . '">' . $body
                . '<button type="submit" class="btn btn-primary">' . $e($button) . '</button></form>';
        };
    }

    /** One sentence on how sign-ins are signed right now, and why, for the Setup tab. */
    public static function signingStatus()
    {
        try {
            $why = Signing::whyNotEddsa();
            $pair = Signing::keyPair();
        } catch (\Throwable $e) {
            return '';
        }

        if ($why === '' && $pair !== null) {
            return 'Now: signed with this install\'s own key (id ' . Text::html($pair['kid']) . ').';
        }

        $reasons = array(
            'override' => 'you chose the shared secret.',
            'no_sodium' => 'this server\'s PHP has no sodium extension, which this install\'s own key needs. Your host can enable it.',
            'no_capability' => 'MagizAI has not said in the last 30 days that it can check this install\'s own key. A MagizAI that can says so with every request.',
            'not_published' => 'this install\'s own key is used once its public half has gone to MagizAI in two connection checks at least an hour apart (the nightly one, or Test connection on MagizAI\'s Connectors page), so one lost answer never leaves MagizAI without it. After 29 days with no check, that starts again.',
        );

        return 'Now: signed with the shared secret, because ' . (isset($reasons[$why]) ? $reasons[$why] : 'the shared secret is in use.');
    }

    public static function options(array $options, $current)
    {
        $out = '';
        foreach ($options as $value => $label) {
            $out .= '<option value="' . Text::html($value) . '"' . ((string) $current === (string) $value ? ' selected' : '') . '>' . Text::html($label) . '</option>';
        }

        return $out;
    }

    /**
     * Every department, hidden ones included: the admin may route chat tickets to one on purpose. A
     * hidden one is marked, because a client can never choose it by name in the chat and its name is
     * kept out of the knowledge sync. @see Departments::department
     */
    public static function departmentOptions($current)
    {
        $options = array('0' => 'First department clients can choose');

        try {
            $departments = Departments::supportDepartments(false);
        } catch (\Throwable $e) {
            $departments = array();
        }

        foreach ($departments as $d) {
            $options[(string) (int) $d['id']] = $d['name'] . ($d['hidden'] ? ' (hidden from clients)' : '');
        }

        return View::options($options, $current);
    }
}
