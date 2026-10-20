<?php

namespace MagizAI\Whmcs\Action\Catalog;

use MagizAI\Whmcs\Action\ActionContext;
use MagizAI\Whmcs\Action\ActionInterface;
use MagizAI\Whmcs\Support\Format;
use MagizAI\Whmcs\Support\WhmcsApi;

/**
 * The host's latest published announcements, newest first.
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class Announcements implements ActionInterface
{
    public function spec()
    {
        return array('identity' => false, 'write' => false, 'group' => 'public', 'label' => 'Latest announcements');
    }

    public function handle(ActionContext $context)
    {
        $r = WhmcsApi::api('GetAnnouncements', array('limitnum' => 10));
        $lines = array();
        foreach (WhmcsApi::rows($r, 'announcements', 'announcement') as $a) {
            if (empty($a['published']) || (!empty($a['parentid']) && (int) $a['parentid'] !== 0)) {
                continue;
            }
            $lines[] = substr((string) $a['date'], 0, 10) . ' — ' . $a['title'] . ': ' . Format::cut(Format::plain($a['announcement']), 220);
            if (count($lines) >= 5) {
                break;
            }
        }

        return array('summary' => $lines ? implode("\n", $lines) : 'There are no announcements.');
    }
}
