<?php

namespace MagizAI\Whmcs\Action\Services;

use MagizAI\Whmcs\ActionError;
use MagizAI\Whmcs\Action\ActionContext;
use MagizAI\Whmcs\Action\ActionInterface;
use MagizAI\Whmcs\Domain\ControlPanel;
use MagizAI\Whmcs\Domain\ModuleCommands;
use MagizAI\Whmcs\Domain\Ownership;
use MagizAI\Whmcs\Support\WhmcsApi;
use MagizAI\Whmcs\Text;

/**
 * Runs one of the server commands the admin allows (1.10.0) on a service the signed-in client owns:
 * a server module's own custom function, such as reboot, boot or shutdown on a VPS, through WHMCS's
 * ModuleCustom.
 *
 * Sensitive, so off until an admin ticks it, and even then nothing is offered until the admin lists
 * the commands on the Actions tab. Never a lifecycle command (ModuleCommands). Only an active service.
 * At most a few an hour for one client (3 unless the admin changes it), counting the ones the server
 * refused. WHMCS's own answer is what the client is told: success only when the server module said
 * success, and WHMCS reports that the command ran, not how the server is afterwards. MagizAI first
 * asks with `check_only`, which runs every check and nothing else, so Confirm names the service and
 * the command that will run.
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class ServiceModuleCommand implements ActionInterface
{
    public function spec()
    {
        return array('identity' => true, 'write' => true, 'group' => 'sensitive', 'label' => 'Run a server command you list (such as reboot) on a service');
    }

    public function handle(ActionContext $context)
    {
        $args = $context->args();
        $clientId = $context->clientId();

        if (!ModuleCommands::configured()) {
            throw new ActionError('not_allowed', 'No server commands are offered through the chat here. The client can use the buttons on the service page in the client area, or a staff member can help.');
        }

        $p = Ownership::ownedService($clientId, $args);
        $name = '#' . (int) $p['id'] . ' ' . $p['name'] . (!empty($p['domain']) ? ' (' . $p['domain'] . ')' : '');
        if ($p['status'] !== 'Active') {
            throw new ActionError('not_allowed', 'Server commands only run on an active service. ' . $name . ' is ' . strtolower((string) $p['status']) . '.');
        }

        $module = ControlPanel::serverModule(isset($p['pid']) ? $p['pid'] : 0, isset($p['serverid']) ? $p['serverid'] : 0);
        $offered = ModuleCommands::offeredFor($module);
        if (!$offered) {
            throw new ActionError('not_allowed', 'No server commands are offered through the chat for ' . $name . '. The client can use the buttons on the service page in the client area, or a staff member can help.');
        }

        $command = ModuleCommands::pick(isset($args['command']) && is_scalar($args['command']) ? $args['command'] : '', $offered);
        if ($command === null || ModuleCommands::refusal($command) !== null) {
            throw new ActionError('invalid_args', 'Ask which command to run on ' . $name . '. Offer them as choices: ' . implode(', ', $offered) . '.');
        }

        try {
            $ran = ModuleCommands::recentRuns('service_module_command', $clientId, $context->claimedUserId());
        } catch (\Throwable $e) {
            throw new ActionError('not_allowed', 'The server command could not be checked against its hourly limit just now, so it was not run. Offer to pass this to the team.', 'audit log unreadable: ' . $e->getMessage());
        }
        $limit = ModuleCommands::perHour();
        if ($ran['client'] >= $limit || $ran['user'] >= $limit) {
            throw new ActionError('not_allowed', 'The limit of ' . $limit . ' server command' . ($limit === 1 ? '' : 's') . ' an hour through the chat is reached for this account. '
                . 'The client can use the buttons on the service page in the client area, or a staff member can help.');
        }

        // The check before the client confirms: everything above, and nothing run.
        if (!empty($args['check_only'])) {
            return array(
                'summary' => 'The ' . $command . ' command can be run on ' . $name . ' once the client confirms.',
                'data' => array('service_id' => (int) $p['id'], 'command' => $command, 'service' => $name, 'commands' => $offered),
                'changed' => false,
            );
        }

        $r = WhmcsApi::api('ModuleCustom', array('serviceid' => (int) $p['id'], 'func_name' => $command), false);
        $said = Text::cut(strip_tags((string) ($r['message'] ?? '')), 300);
        if (($r['result'] ?? '') !== 'success') {
            $safe = Text::customerSafe($said, '');
            throw new ActionError('server_error', 'The ' . $command . ' command did not run on ' . $name
                . ($safe !== '' ? '. The server said: ' . rtrim($safe, '. ') . '.' : '. The server did not complete it.') . ' Offer to pass this to the team.',
                'ModuleCustom ' . $command . ' on service ' . (int) $p['id'] . ': ' . ($said !== '' ? $said : 'no answer'));
        }

        $summary = 'WHMCS ran the ' . $command . ' command on ' . $name . ', and the server module reported success. '
            . 'WHMCS reports only that the command ran, not how the server is now. If something still looks wrong in a few minutes, offer to pass it to the team.';

        return array(
            'summary' => $summary,
            'audit' => 'service ' . (int) $p['id'] . ' (' . $module . '): ' . $command . ' ran, server module reported success',
            'notify' => 'The ' . $command . ' command was run on ' . $name . ' through live chat.',
            'data' => array('service_id' => (int) $p['id'], 'command' => $command, 'ran' => true),
        );
    }
}
