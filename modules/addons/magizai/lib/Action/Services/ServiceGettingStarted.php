<?php

namespace MagizAI\Whmcs\Action\Services;

use MagizAI\Whmcs\Action\ActionContext;
use MagizAI\Whmcs\Action\ActionInterface;
use MagizAI\Whmcs\Action\Catalog\KbSearch;
use MagizAI\Whmcs\Domain\ControlPanel;
use MagizAI\Whmcs\Domain\Knowledgebase;
use MagizAI\Whmcs\Domain\Ownership;

/**
 * How to actually start using a service that was just bought.
 *
 * The gap this fills: an order completes, WHMCS emails a welcome message nobody reads twice,
 * and the next day the client asks in chat how to upload their site. The answer is specific to
 * what they bought and which server it is on, so it is assembled here rather than left to the
 * model, and it is pointed at the host's OWN knowledgebase so the steps match their panel
 * rather than a generic cPanel article.
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class ServiceGettingStarted implements ActionInterface
{
    public function spec()
    {
        return array('identity' => true, 'write' => false, 'group' => 'read', 'label' => 'How to start using a service: control panel, email, uploading a site');
    }

    public function handle(ActionContext $context)
    {
        $args = $context->args();
        $clientId = $context->clientId();

        $p = Ownership::ownedService($clientId, $args);

        $label = (string) $p['name'] . ($p['domain'] ? ' (' . $p['domain'] . ')' : '');
        $panel = ControlPanel::panelUrl($p);
        $username = isset($p['username']) ? trim((string) $p['username']) : '';

        /*
         * WEB-HOSTING STEPS ONLY FOR WEB HOSTING.
         *
         * This used to hand every service the same four steps: sign in to the control panel, point
         * the nameservers, upload to public_html, create mailboxes. To a streaming customer asking
         * how to start watching, that is four instructions for a product they did not buy. A service
         * counts as web hosting here only when it actually has what those steps need — a control
         * panel, and a domain for the DNS step. Everything else is told to install and sign in, and
         * pointed at the host's own setup guides, which are the real instructions for that product.
         */
        $isWebHosting = $panel !== '' && (string) $p['domain'] !== '';

        // One click into the control panel, through WHMCS's own single sign-on, before any login
        // details. @see \MagizAI\Whmcs\Action\Account\ClientAreaLink::controlPanelLink
        $sso = ControlPanel::ssoLink($clientId, $p);

        $steps = array();

        if ($sso !== '') {
            $steps[] = 'Open the control panel in one click: ' . $sso . ' (it uses their client area sign-in, so no password is needed).'
                . ($panel !== '' ? ' They can also sign in to the control panel at ' . $panel . ($username !== '' ? ' with the username ' . $username : '') . '.' : '');
        }

        if ($isWebHosting) {
            if ($sso === '') {
                $steps[] = 'Sign in to the control panel at ' . $panel
                    . ($username !== '' ? ' with the username ' . $username : '')
                    . '. Ask for the login details if they do not have them.';
            }
            $steps[] = 'Point ' . $p['domain'] . ' at the nameservers on the welcome email, then allow a few hours for DNS.';
            $steps[] = 'Upload the website into the public_html folder, using the panel file manager or FTP.';
            $steps[] = 'Create the mailboxes in the panel, then set them up on a phone or mail client.';
        } elseif ($sso === '') {
            // Not a control panel at all (a streaming line, a licence): the app and its guides.
            $steps[] = 'Install the app or player on the device they want to use it on — the setup guides below cover each one.';
            $steps[] = 'Sign in with the login details for this service'
                . ($username !== '' ? ' (username ' . $username . ')' : '')
                . '. Offer to give them their login details if they do not have them.';
        }

        /*
         * The host's OWN articles, through the same search the assistant uses, so the wording
         * matches their panel and their policies. Reusing KbSearch rather than calling the
         * knowledgebase API directly is deliberate: that method already excludes hidden categories,
         * and a direct API call would happily return staff-only articles to a client.
         */
        $articles = Knowledgebase::setupGuides($clientId);
        if (!$articles) {
            try {
                $kb = (new KbSearch())->handle($context->withArgs(array('query' => 'getting started')));
                $found = isset($kb['data']['articles']) && is_array($kb['data']['articles']) ? $kb['data']['articles'] : array();
                $articles = array_slice($found, 0, 5);
            } catch (\Exception $e) {
                // A knowledgebase that cannot be read must not cost them the steps above.
                $articles = array();
            }
        }

        return array(
            'summary' => 'Getting started with ' . $label . ': ' . implode(' ', $steps)
                . ($articles
                    ? ' Setup guides: ' . implode('; ', array_map(function ($a) {
                        return (string) $a['title'] . ' (' . (string) $a['url'] . ')';
                    }, $articles)) . '. Offer these as a tappable choices block so they pick their own device or app.'
                    : ''),
            'data' => array(
                'service' => $label,
                'panel_url' => $panel,
                'sso_url' => $sso,
                'username' => $username,
                'steps' => $steps,
                'articles' => $articles,
            ),
        );
    }
}
