<?php
/**
 * MagizAI hooks: the chat widget and the sign-in on client-area pages, sign-outs that end the chat's
 * sign-in, the nightly knowledge sync and clean-up, (1.9.0) ticket changes, which MagizAI's inbox is
 * told about at once, and (1.10.0) the MagizAI panel on the admin ticket page. What each one does is
 * lib/Hooks.
 *
 * Every hook is wrapped: nothing here may ever break a client-area page, a sign-out, the cron or a
 * ticket reply.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/lib/autoload.php';

add_hook('ClientAreaFooterOutput', 1, function ($vars) {
    try {
        return \MagizAI\Whmcs\Hooks\FooterHook::run(is_array($vars) ? $vars : array());
    } catch (\Throwable $e) {
        // The chat widget must never break a client-area page.
        return '';
    }
});

add_hook('UserLogout', 1, function ($vars) {
    try {
        \MagizAI\Whmcs\Hooks\SessionHooks::userLogout(is_array($vars) ? $vars : array());
    } catch (\Throwable $e) {
        // Signing out must always work.
    }
});

add_hook('ClientLogout', 1, function ($vars) {
    try {
        \MagizAI\Whmcs\Hooks\SessionHooks::clientLogout(is_array($vars) ? $vars : array());
    } catch (\Throwable $e) {
        // Signing out must always work.
    }
});

add_hook('DailyCronJob', 1, function ($vars) {
    try {
        \MagizAI\Whmcs\Hooks\CronHook::run(is_array($vars) ? $vars : array());
    } catch (\Throwable $e) {
        // The cron must never stop because of this module. A failed sync is logged by the hook itself.
    }
});

add_hook('TicketOpen', 1, function ($vars) {
    try {
        \MagizAI\Whmcs\Hooks\TicketHooks::run('TicketOpen', is_array($vars) ? $vars : array());
    } catch (\Throwable $e) {
        // A ticket must always save. MagizAI's five-minute check picks the change up instead.
    }
});

add_hook('TicketOpenAdmin', 1, function ($vars) {
    try {
        \MagizAI\Whmcs\Hooks\TicketHooks::run('TicketOpenAdmin', is_array($vars) ? $vars : array());
    } catch (\Throwable $e) {
        // A ticket must always save. MagizAI's five-minute check picks the change up instead.
    }
});

add_hook('TicketUserReply', 1, function ($vars) {
    try {
        \MagizAI\Whmcs\Hooks\TicketHooks::run('TicketUserReply', is_array($vars) ? $vars : array());
    } catch (\Throwable $e) {
        // A ticket must always save. MagizAI's five-minute check picks the change up instead.
    }
});

add_hook('TicketAdminReply', 1, function ($vars) {
    try {
        \MagizAI\Whmcs\Hooks\TicketHooks::run('TicketAdminReply', is_array($vars) ? $vars : array());
    } catch (\Throwable $e) {
        // A ticket must always save. MagizAI's five-minute check picks the change up instead.
    }
});

add_hook('TicketStatusChange', 1, function ($vars) {
    try {
        \MagizAI\Whmcs\Hooks\TicketHooks::run('TicketStatusChange', is_array($vars) ? $vars : array());
    } catch (\Throwable $e) {
        // A ticket must always save. MagizAI's five-minute check picks the change up instead.
    }
});

add_hook('TicketClose', 1, function ($vars) {
    try {
        \MagizAI\Whmcs\Hooks\TicketHooks::run('TicketClose', is_array($vars) ? $vars : array());
    } catch (\Throwable $e) {
        // A ticket must always save. MagizAI's five-minute check picks the change up instead.
    }
});

add_hook('AdminAreaViewTicketPage', 1, function ($vars) {
    try {
        return \MagizAI\Whmcs\Hooks\TicketAssistHook::render(is_array($vars) ? $vars : array());
    } catch (\Throwable $e) {
        // The ticket page must always open.
        return '';
    }
});
