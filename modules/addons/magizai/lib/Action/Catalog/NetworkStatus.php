<?php

namespace MagizAI\Whmcs\Action\Catalog;

use MagizAI\Whmcs\Action\ActionContext;
use MagizAI\Whmcs\Action\ActionInterface;
use MagizAI\Whmcs\Support\Format;
use WHMCS\Database\Capsule;

/**
 * Network issues that are still open, as WHMCS's network status page lists them.
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class NetworkStatus implements ActionInterface
{
    public function spec()
    {
        return array('identity' => false, 'write' => false, 'group' => 'public', 'label' => 'Network status');
    }

    public function handle(ActionContext $context)
    {
        $rows = Capsule::table('tblnetworkissues')
            ->where('status', '!=', 'Resolved')
            ->orderBy('startdate', 'desc')
            ->limit(10)
            ->get();

        if (!count($rows)) {
            return array('summary' => 'No open network issues are reported at the moment.', 'data' => array('open_issues' => 0));
        }

        $lines = array();
        foreach ($rows as $i) {
            $lines[] = '[' . $i->status . ', ' . $i->priority . '] ' . $i->title
                . ' — affecting ' . $i->type . ($i->affecting ? ' ' . $i->affecting : '')
                . '. Started ' . $i->startdate . ', last update ' . $i->lastupdate . '. '
                . Format::cut(Format::plain($i->description), 250);
        }

        return array('summary' => implode("\n", $lines), 'data' => array('open_issues' => count($lines)));
    }
}
