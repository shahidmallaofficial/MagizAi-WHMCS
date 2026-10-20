<?php

namespace MagizAI\Whmcs\Admin\Tabs;

use MagizAI\Whmcs\Admin\View;
use MagizAI\Whmcs\Persistence\AuditLog;

/**
 * The Activity log tab: the last 100 requests from MagizAI.
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class ActivityTab
{
    /** Echo the tab, exactly as the page shows it. */
    public static function render($link, $tab, $token)
    {
        $e = View::escaper();

        echo '<div class="mz-card"><h3>Last 100 requests from MagizAI</h3><div class="table-responsive"><table class="table table-striped mz-log"><tr><th>Time</th><th>Action</th><th>Client</th><th>Result</th><th>Detail</th></tr>';
        foreach (AuditLog::page(100) as $row) {
            $staff = isset($row->actor) && is_string($row->actor) && strpos($row->actor, 'admin:') === 0
                ? ' <span class="label label-info">staff view, admin #' . (int) substr($row->actor, 6) . '</span>' : '';
            echo '<tr><td>' . $e($row->created_at) . '</td><td>' . $e($row->action) . ($row->is_write ? ' <span class="label label-warning">change</span>' : '') . $staff . '</td><td>'
                . ($row->client_id ? '<a href="clientssummary.php?userid=' . (int) $row->client_id . '">#' . (int) $row->client_id . '</a>' : '—')
                . '</td><td>' . $e($row->outcome) . ($row->error !== '' ? ' (' . $e($row->error) . ')' : '') . '</td><td>' . $e($row->detail) . '</td></tr>';
        }
        echo '</table></div></div>';
    }
}
