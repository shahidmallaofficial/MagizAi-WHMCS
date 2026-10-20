<?php

namespace MagizAI\Whmcs\Action\Account;

use MagizAI\Whmcs\ActionError;
use MagizAI\Whmcs\Action\ActionContext;
use MagizAI\Whmcs\Action\ActionInterface;
use MagizAI\Whmcs\Action\Permissions;
use MagizAI\Whmcs\Domain\ControlPanel;
use MagizAI\Whmcs\Domain\Ownership;
use MagizAI\Whmcs\Settings;
use MagizAI\Whmcs\Support\Args;
use WHMCS\Database\Capsule;

/**
 * A direct link to the right page of the client area.
 *
 * NEVER AN ACCOUNT LOGIN TOKEN. WHMCS can mint an auto-login token, and it is the wrong tool
 * here: it grants full access to the account, skips two-factor authentication, and anything put
 * in a chat is visible to whoever is watching that chat. This action is only reached when the
 * client is already signed in (that is what the identity token means), so a plain link does the
 * same job with none of that.
 *
 * The one exception is not one: `control_panel` links to WHMCS's own one-click sign-in to the
 * service's control panel, which runs inside the client's existing WHMCS session and mints
 * nothing. @see controlPanelLink
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class ClientAreaLink implements ActionInterface
{
    public function spec()
    {
        return array('identity' => true, 'write' => false, 'group' => 'read', 'label' => 'Link the client straight to the right client-area page');
    }

    public function handle(ActionContext $context)
    {
        $args = $context->args();
        $clientId = $context->clientId();

        $base = Settings::systemUrl();
        $where = mb_strtolower(Args::text($args, 'destination', 40));

        if (in_array(str_replace(array(' ', '-'), '_', $where), array('control_panel', 'controlpanel', 'cpanel', 'panel'), true)) {
            return self::controlPanelLink($clientId, $args);
        }

        $destinations = array(
            'invoices' => array('clientarea.php?action=invoices', 'your invoices'),
            'services' => array('clientarea.php?action=services', 'your services'),
            'domains' => array('clientarea.php?action=domains', 'your domains'),
            'tickets' => array('supporttickets.php', 'your support tickets'),
            'details' => array('clientarea.php?action=details', 'your account details'),
            'security' => array('clientarea.php?action=security', 'your security settings, including two-factor'),
            'emails' => array('clientarea.php?action=emails', 'the emails we have sent you'),
            'store' => array('cart.php', 'the store'),
            'affiliate' => array('affiliates.php', 'your affiliate account'),
            'home' => array('clientarea.php', 'your client area'),
        );

        if (!isset($destinations[$where])) {
            $where = 'home';
        }

        // A service or invoice the client named goes straight to that page, which is the whole
        // point — but only after the usual ownership check, and only for a user who may see it:
        // naming the service or domain would otherwise tell them what the owner keeps from them.
        if ($where === 'services' && (!empty($args['service_id']) || !empty($args['domain']))) {
            Permissions::mustMay($clientId, 'products');
            $p = Ownership::ownedService($clientId, $args);

            return array('summary' => 'Open ' . $p['name'] . ' here: ' . $base . 'clientarea.php?action=productdetails&id=' . (int) $p['id']);
        }

        if ($where === 'domains' && !empty($args['domain'])) {
            Permissions::mustMay($clientId, 'domains');
            $d = Ownership::ownedDomain($clientId, $args);

            // `id=`, as WHMCS's own templates link it. `domainid=` is for the contacts and DNS pages.
            return array('summary' => 'Manage ' . $d['domainname'] . ' here: ' . $base . 'clientarea.php?action=domaindetails&id=' . (int) $d['id']);
        }

        return array('summary' => 'Open ' . $destinations[$where][1] . ' here: ' . $base . $destinations[$where][0]);
    }

    /**
     * One click into a service's own control panel, through WHMCS's single sign-on.
     *
     * clientarea.php?action=productdetails&id=N&dosinglesignon=1 is WHMCS's documented trigger for
     * the provisioning module's ServiceSingleSignOn. It runs in the client's own WHMCS session in
     * their browser: nothing is minted, nothing secret is in the link, and it does nothing for anyone
     * who is not signed in to this client area as someone allowed to use it. "Log me into cPanel"
     * gets one link instead of a password.
     *
     * When the service has no such sign-on (its module has none, it is not active, or the account
     * switched single sign-on off), the client gets the service's page in the client area, where its
     * details are, and is told why. A team member without WHMCS's "Perform Single Sign-On" is refused,
     * as the client area would refuse them.
     */
    private static function controlPanelLink($clientId, array $args)
    {
        Permissions::mustMay($clientId, 'products');
        $p = self::panelService($clientId, $args);

        $label = $p['name'] . ($p['domain'] ? ' (' . $p['domain'] . ')' : '');
        $page = Settings::systemUrl() . 'clientarea.php?action=productdetails&id=' . (int) $p['id'];
        $why = ControlPanel::ssoBlock($clientId, $p);

        if ($why === '') {
            return array(
                'summary' => 'Open the control panel of ' . $label . ' in one click: ' . ControlPanel::ssoUrl($p)
                    . ' It signs them in through their client area session in this browser, so no password is needed.',
                'data' => array('service_id' => (int) $p['id'], 'url' => ControlPanel::ssoUrl($p), 'single_sign_on' => true),
            );
        }

        if ($why === 'permission') {
            throw new ActionError('not_allowed', 'The account owner has not given this user permission to open control panels in one click '
                . '("Perform Single Sign-On"). The owner can change it under Account → User Management. The service page is here: ' . $page);
        }

        $reasons = array(
            'module' => $label . ' has no one-click control panel sign-in. Its page in the client area has its details and options: ' . $page,
            'status' => $label . ' is ' . strtolower((string) $p['status']) . ', and a control panel opens in one click only for an active service. '
                . 'Its page in the client area: ' . $page,
            'switched_off' => 'One-click sign-in is switched off for this account, in its security settings: ' . Settings::systemUrl() . 'clientarea.php?action=security'
                . ' The client can switch it on there, or open the service page and sign in with their login details: ' . $page,
        );

        return array(
            'summary' => $reasons[$why],
            'data' => array('service_id' => (int) $p['id'], 'url' => $page, 'single_sign_on' => false, 'reason' => $why),
        );
    }

    /**
     * The service whose control panel is meant: the one named, else the only live service with a
     * control panel WHMCS signs in to. With several of those the client is asked which; with none,
     * the usual choice of service applies.
     */
    private static function panelService($clientId, array $args)
    {
        if (!empty($args['service_id']) || !empty($args['domain'])) {
            return Ownership::ownedService($clientId, $args);
        }

        $candidates = array();
        foreach (Capsule::table('tblhosting')->where('userid', (int) $clientId)->whereIn('domainstatus', array('Active', 'Suspended'))
            ->orderBy('id', 'desc')->limit(200)->get(array('id', 'packageid', 'server')) as $row) {
            if (in_array(ControlPanel::serverModule((int) $row->packageid, (int) $row->server), ControlPanel::SSO_MODULES, true)) {
                $candidates[] = (int) $row->id;
            }
        }

        if (count($candidates) === 1) {
            return Ownership::serviceById($clientId, $candidates[0]);
        }

        if (count($candidates) > 1) {
            $names = array();
            foreach (array_slice($candidates, 0, 8) as $id) {
                try {
                    $one = Ownership::serviceById($clientId, $id);
                    $names[] = '#' . $one['id'] . ' ' . $one['name'] . ($one['domain'] ? ' (' . $one['domain'] . ')' : '');
                } catch (ActionError $e) {
                    continue;
                }
            }

            throw new ActionError('invalid_args', 'The client has ' . count($candidates) . ' services with a control panel. '
                . 'Offer them as choices and ask which one: ' . implode('; ', $names) . '.');
        }

        return Ownership::ownedService($clientId, $args);
    }
}
