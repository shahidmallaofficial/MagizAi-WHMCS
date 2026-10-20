<?php

namespace MagizAI\Whmcs\Admin\Tabs;

use MagizAI\Whmcs\Action\Registry;
use MagizAI\Whmcs\Admin\View;
use MagizAI\Whmcs\Domain\ModuleCommands;
use MagizAI\Whmcs\Settings;

/**
 * The "What the assistant may do" tab: every action, by group, switched on or off.
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class ActionsTab
{
    /** Echo the tab, exactly as the page shows it. */
    public static function render($link, $tab, $token)
    {
        $e = View::escaper();
        $form = View::form($link, $tab, $token);

        $enabled = Settings::enabledActions();
        $groups = array(
            'public' => 'Public answers (anyone chatting)',
            'read' => 'Signed-in client: look up their own account',
            'write' => 'Signed-in client: change their own account (always confirmed by the client in the chat first)',
            'sensitive' => 'Sensitive: off by default',
        );
        $body = '';
        foreach ($groups as $group => $title) {
            $body .= '<div class="mz-grp">' . $e($title) . '</div>';
            if ($group === 'sensitive') {
                $body .= '<p class="mz-warn">Cancellation requests cannot be undone by the client. A transfer code is never shown in the chat: it is emailed to the address on the account, or the registrar sends it to the domain\'s registrant contact. A transfer code is the key to moving a domain away, so enable this only if your team is comfortable with it.</p>'
                    . '<p class="mz-warn">Changing a domain\'s contact details changes who the registrar records as the domain\'s owner and how they are reached. Only the details the client names are changed, into the fields your registrar returns, and the client sees the new values before they tap Confirm.</p>'
                    . '<p class="mz-warn">Paying an invoice from the account credit uses the client\'s own credit balance on one of their unpaid invoices, never more than the credit or the amount still owed. The client sees the amount before they tap Confirm.</p>'
                    . '<p class="mz-warn">Opening an account for a new client creates a WHMCS client from the chat, with the name, email, country and phone they give (the rest of the profile is theirs to complete), and places their order. It follows your own settings: your terms of service when you require them, the country when you charge tax, and never a second account for an email you already have. WHMCS sends its welcome email and a link to choose a password, and the client can be signed in once from the chat to pay. Limited per hour on the Setup tab.</p>';
            }
            foreach (Registry::catalog() as $key => $spec) {
                if ($spec['group'] !== $group) {
                    continue;
                }
                if (isset(Registry::SETTING_SWITCHES[$key])) {
                    // Switched on the Setup tab, so no checkbox here that could disagree with it.
                    $body .= '<div class="mz-help">' . $e($spec['label']) . ': <strong>' . (in_array($key, $enabled, true) ? 'on' : 'off') . '</strong>.</div>';
                    continue;
                }
                if (isset(Registry::FOLLOWS[$key])) {
                    // On exactly when the action it belongs to is. @see Registry::FOLLOWS
                    $leader = Registry::spec(Registry::FOLLOWS[$key]);
                    $body .= '<div class="mz-help">' . $e($spec['label']) . ': on with "' . $e($leader ? $leader['label'] : Registry::FOLLOWS[$key]) . '".</div>';
                    continue;
                }
                $body .= '<div><label><input type="checkbox" name="actions[]" value="' . $e($key) . '"' . (in_array($key, $enabled, true) ? ' checked' : '') . '> ' . $e($spec['label']) . '</label></div>';
            }
        }
        echo '<div class="mz-card"><h3>What the assistant may do</h3><p class="mz-help">This is the final say: an action switched off here is refused whatever is configured in MagizAI. Every request is recorded in the activity log, and changes also appear in the WHMCS activity log.</p>'
            . $form('save_actions', $body) . '</div>';

        /*
         * SERVER COMMANDS (1.10.0). "Run a server command" above is only the switch: nothing is offered
         * until a command is listed here. @see \MagizAI\Whmcs\Domain\ModuleCommands
         */
        echo '<div class="mz-card"><h3>Server commands from the chat</h3>'
            . '<p class="mz-help">Used only when "' . $e(Registry::spec('service_module_command')['label']) . '" is ticked above. A signed-in client can run these on their own active service, after they tap Confirm. '
            . 'Write each command as your server module names its function, one a line, such as <code>reboot</code>, or for one module only, <code>virtualizor:reboot</code>. '
            . 'The chat can run any command listed here that the service\'s module has, including one your module shows only to staff, so list only what you are happy for clients to run. '
            . 'Commands that create, suspend, terminate, delete, reinstall, rebuild, wipe, restore, renew or change a password, package or plan are refused even if you list them. Empty: none are offered.</p>'
            . $form('save_module_commands',
                '<div class="mz-row"><label>Commands</label><textarea name="module_commands" class="form-control" rows="4">' . $e(Settings::get(ModuleCommands::SETTING, '')) . '</textarea></div>'
                . '<div class="mz-row"><label>Commands one client may run per hour</label><input type="text" class="form-control" name="module_commands_per_hour" value="' . $e(ModuleCommands::perHour()) . '" style="max-width:120px">'
                . '<p class="mz-help">From 1 to ' . ModuleCommands::PER_HOUR_MAX . '. A command the server refused counts too. Every run, and every refusal, is written to the activity log here and to WHMCS\'s own.</p></div>',
                'Save server commands')
            . '</div>';
    }
}
