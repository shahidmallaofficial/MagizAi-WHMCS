<?php
/**
 * MagizAI Live Chat for WHMCS.
 *
 * An AI live-chat assistant that knows your WHMCS: it answers from your knowledgebase, products and
 * prices, and — for clients signed in to the client area — checks their services, domains, invoices
 * and tickets, and can change nameservers, toggle auto-renew or open tickets after the client
 * confirms in chat.
 *
 * WHMCS's entry points only. Activation and upgrades are lib/Install, the admin page lib/Admin, the
 * token route lib/Http.
 */

use MagizAI\Whmcs\Admin\AdminPage;
use MagizAI\Whmcs\Admin\PostHandler;
use MagizAI\Whmcs\Admin\View;
use MagizAI\Whmcs\Http\TokenEndpoint;
use MagizAI\Whmcs\Install\Lifecycle;
use MagizAI\Whmcs\Install\Upgrade;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/lib/autoload.php';

function magizai_config()
{
    return array(
        'name' => 'MagizAI Live Chat',
        'description' => 'AI live chat that knows your WHMCS: answers from your knowledgebase and prices, and helps signed-in clients with their services, domains, invoices and tickets.',
        'version' => '1.10.0',
        'author' => 'MagizAI',
        'language' => 'english',
        'fields' => array(),
    );
}

/** @see Lifecycle::activate */
function magizai_activate()
{
    return Lifecycle::activate();
}

/** @see Lifecycle::deactivate */
function magizai_deactivate()
{
    return Lifecycle::deactivate();
}

/** @see Upgrade::run */
function magizai_upgrade($vars)
{
    Upgrade::run($vars);
}

/**
 * The client-area route: index.php?m=magizai&action=identity, where the chat fetches a fresh sign-in
 * for the page it is on. It answers with JSON alone, so the request ends here, before WHMCS can add a
 * page around it. @see TokenEndpoint
 */
function magizai_clientarea($vars)
{
    TokenEndpoint::serve(isset($_GET['action']) && is_string($_GET['action']) ? $_GET['action'] : '');
    exit;
}

/** Admin page. @see AdminPage */
function magizai_output($vars)
{
    AdminPage::render($vars);
}

/** @see PostHandler::handle */
function magizai_handle_post($do)
{
    return PostHandler::handle($do);
}

/** @see View::departmentOptions */
function magizai_department_options($current)
{
    return View::departmentOptions($current);
}
