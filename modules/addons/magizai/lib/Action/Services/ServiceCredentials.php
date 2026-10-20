<?php

namespace MagizAI\Whmcs\Action\Services;

use MagizAI\Whmcs\ActionError;
use MagizAI\Whmcs\Action\ActionContext;
use MagizAI\Whmcs\Action\ActionInterface;
use MagizAI\Whmcs\Action\Permissions;
use MagizAI\Whmcs\Domain\ControlPanel;
use MagizAI\Whmcs\Domain\Credentials;
use MagizAI\Whmcs\Domain\Knowledgebase;
use MagizAI\Whmcs\Domain\Ownership;
use MagizAI\Whmcs\Persistence\AuditLog;
use MagizAI\Whmcs\Settings;

/**
 * The client's own login details for one of their services.
 *
 * WHY THIS EXISTS. "What is my cPanel password?" is one of the most common tickets a host gets,
 * and until now the only answer the assistant could give was "reset it" — which changes a
 * working password and breaks whatever was using it. The details already exist in WHMCS; the
 * client is already signed in; there is no reason to make them open a ticket for their own
 * username.
 *
 * TWO DELIVERIES, AND THE DIFFERENCE MATTERS
 *
 *   email  (the default, and always available)
 *          WHMCS emails the username and password to the address on the account. Nothing
 *          sensitive crosses the bridge, so nothing can be logged, cached or read by an agent
 *          scrolling the transcript later.
 *
 *   chat   (only when the host switches `credentials_in_chat` on)
 *          The password is returned to MagizAI under `data.secret`. EVERYTHING under that key is
 *          stripped before the conversation is stored and before the model is called: it is put
 *          behind a single-use, short-lived token and revealed in the visitor's browser only.
 *          That handling is MagizAI's side of the contract, and the key name is the contract.
 *
 * WHY THE CHAT OPTION IS OFF UNTIL A HUMAN TICKS IT. Putting a live password on screen is a
 * decision about somebody else's security, and it is not ours to make by default. Email gives
 * the same answer with none of the exposure, so that is what an unconfigured install does.
 *
 * The password is never shown for a service the client does not own, never for a cancelled or
 * fraud service, and never without the verified client-area sign-in every identity action needs.
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class ServiceCredentials implements ActionInterface
{
    /** How many times an hour login details may be sent, per account and per user. */
    const CREDENTIALS_PER_HOUR = 3;

    /**
     * What a service_credentials audit row says when a password actually left WHMCS, by email or
     * onto the reveal button. The hourly limit counts these rows and nothing else: an answer with
     * the username only, or with no password to give, sent nothing. The same words since 1.5, so
     * rows written before an upgrade still count.
     */
    const CREDENTIALS_EMAILED = 'credentials emailed';

    const CREDENTIALS_SHOWN = 'credentials shown in chat';

    public function spec()
    {
        return array('identity' => true, 'write' => false, 'group' => 'read', 'label' => 'Give the client their own service login details (username always; password by email, or in chat only if you switch that on)');
    }

    public function handle(ActionContext $context)
    {
        $args = $context->args();
        $clientId = $context->clientId();

        $p = Ownership::ownedService($clientId, $args);

        if (!in_array($p['status'], array('Active', 'Suspended'), true)) {
            throw new ActionError('not_allowed', 'Login details are only available for an active service. This one is '
                . strtolower((string) $p['status']) . '.');
        }

        $username = isset($p['username']) ? trim((string) $p['username']) : '';
        $productId = isset($p['pid']) ? (int) $p['pid'] : 0;

        /*
         * A LINE PROVISIONED BY HAND HAS NO USERNAME ON THE SERVICE — its login lives in custom
         * fields, the same place its password does. This used to stop right here with "there is no
         * username", so the custom-field password below was never even looked for.
         */
        if ($username === '' && !empty($p['id'])) {
            $username = Credentials::customFieldValue((int) $p['id'], $productId, '/\b(user ?name|username|user|login|line)\b/');
        }

        $label = (string) $p['name'] . ($p['domain'] ? ' (' . $p['domain'] . ')' : '');
        $panel = ControlPanel::panelUrl($p);

        /*
         * THE ONE-CLICK LINK COMES FIRST, BEFORE ANY PASSWORD (1.6.0). For a cPanel, Plesk,
         * DirectAdmin, InterWorx or CyberPanel service, WHMCS's own single sign-on opens the control
         * panel from the client's existing session: "log me into cPanel" is answered without a
         * password changing hands at all. The login details follow for whoever still needs them
         * (an FTP client, a mail app). @see \MagizAI\Whmcs\Action\Account\ClientAreaLink::controlPanelLink
         */
        $sso = ControlPanel::ssoLink($clientId, $p);
        $lead = $sso !== '' ? 'One-click sign-in to the control panel, with no password needed: ' . $sso . ' ' : '';

        // The non-secret half. Always safe to say out loud, and on its own it answers
        // "what is my username" without any password changing hands at all.
        $open = array(
            // 1.8.1: which service, so MagizAI asks for the password of the same one afterwards.
            'service_id' => (int) $p['id'],
            'domain' => (string) $p['domain'],
            'service' => $label,
            'username' => $username,
            'panel_url' => $panel,
            'sso_url' => $sso,
            'server_hostname' => isset($p['serverhostname']) ? strtolower(trim((string) $p['serverhostname'])) : '',
            'status' => (string) $p['status'],
        );

        $chatAllowed = Settings::get('credentials_in_chat', 'off') === 'on';
        $asked = strtolower(trim(isset($args['deliver']) ? (string) $args['deliver'] : ''));

        /*
         * THE DETAILS WITHOUT THE PASSWORD (1.8.1). "What is my cPanel login?" is answered first with
         * what is safe to say (the username, the control panel address and its one-click sign-in),
         * and nothing is sent or shown: the client then chooses to see the password in the chat
         * (after a code MagizAI has emailed to the account) or to have it emailed. Never counted
         * against the hourly limit: no password leaves WHMCS. Announced in the ping's features as
         * `credentials_overview`, so MagizAI asks for it only from a module that knows it.
         */
        if ($asked === 'none') {
            $open['password_in_chat_enabled'] = $chatAllowed;

            return array(
                'summary' => $lead . ($username !== '' ? 'The username for ' . $label . ' is ' . $username . '.' : 'There is no separate username for ' . $label . '.')
                    . ($panel !== '' ? ' The control panel is at ' . $panel . '.' : '')
                    . ' The password was not sent or shown.',
                'audit' => 'service #' . $p['id'] . ' login details without the password',
                'data' => $open,
            );
        }

        /*
         * WHERE THE PASSWORD GOES.
         *
         * When the host has switched on "show in chat", a client asking IN THE CHAT gets it in the
         * chat. This used to default to email unless the assistant happened to pass deliver=chat —
         * so a host who had ticked the box still saw every client told "check your email", which is
         * precisely the behaviour they ticked the box to replace. Email is now only used when the
         * client asks for it, or when the host has not allowed the chat.
         */
        $deliver = ($asked === 'email' || !$chatAllowed) ? 'email' : 'chat';

        /*
         * A PASSWORD ON SCREEN NEEDS A RECENT SIGN-IN. The token's issue time says when WHMCS last
         * confirmed the client's sign-in: a client-area page, or the chat fetching a fresh token
         * while they were still signed in. Older than the host's limit (15 minutes unless changed)
         * and the password is emailed instead, which reaches the account's own address whoever is
         * sitting at this screen.
         */
        $staleForChat = false;
        if ($deliver === 'chat' && !$context->signedInWithin(Settings::minutes('credentials_freshness_minutes', 15, 5, 240))) {
            $deliver = 'email';
            $staleForChat = true;
        }

        /*
         * STAFF VIEW GETS THE USERNAME ONLY. Nothing is emailed to the client and nothing is shown
         * behind the reveal button: the staff member has the password in the admin area already,
         * and a test from Login as Owner must not send the client an unexpected email.
         */
        if ($context->staffView()) {
            $open['password_source'] = 'staff_view';
            $open['password_in_chat_enabled'] = $chatAllowed;

            return array(
                'summary' => $lead . ($username !== '' ? 'The username for ' . $label . ' is ' . $username . '. ' : '')
                    . 'This is staff view, so no password is sent or shown from the chat. '
                    . 'Staff can see it in the WHMCS admin area, on the service.',
                'audit' => 'service #' . $p['id'] . ' username only (staff view)',
                'data' => $open,
            );
        }

        /*
         * THE PASSWORD IS WHMCS's "View & Modify Product Passwords" PERMISSION, not "View Products".
         * A team member the owner kept away from passwords still gets the username, which their
         * client area shows them anyway, and is told plainly why the password is not included.
         */
        if (!Permissions::userMay($clientId, 'manageproducts')) {
            $open['password_source'] = 'not_permitted';
            $open['password_in_chat_enabled'] = $chatAllowed;

            return array(
                'summary' => $lead . ($username !== '' ? 'The username for ' . $label . ' is ' . $username . '. ' : '')
                    . 'The account owner has not given this user access to service passwords, so the password was not sent. '
                    . 'The owner can change that under Account → User Management, or can ask for the details themselves.',
                'audit' => 'service #' . $p['id'] . ' username only (no password permission)',
                'data' => $open,
            );
        }

        $found = Credentials::servicePasswordWithSource($p);
        $password = $found['password'];

        // Nothing at all stored: say so plainly rather than read out an empty username.
        if ($username === '' && $password === '') {
            return array(
                'summary' => $lead . 'WHMCS holds no login details for ' . $label . ' — no username or password on the service and '
                    . 'none in its custom fields. It may have been set up by hand. Offer to pass it to the team.',
                'data' => $open,
            );
        }

        // How the username is spoken, including the case where only a password exists.
        $userLine = $username !== ''
            ? 'The username for ' . $label . ' is ' . $username . '.'
            : 'There is no separate username for ' . $label . '.';

        // How to actually use it: the host's own setup guides, offered by device or app.
        $guides = Knowledgebase::setupGuides($clientId);
        $guideLine = $guides
            ? ' Setup guides are available for: ' . implode('; ', array_map(function ($g) {
                return $g['title'];
            }, $guides)) . '. After the login details, offer these as a tappable choices block so they pick their device or app.'
            : '';

        // Non-secret facts about where we looked. Harmless to the model and the only way to tell,
        // next time, whether a missing password is missing in WHMCS or missing in our reading of it.
        $open['password_source'] = $found['source'];
        $open['password_in_chat_enabled'] = $chatAllowed;
        $open['setup_guides'] = $guides;

        if ($password === '') {
            return array(
                'summary' => $lead . $userLine . ' WHMCS holds no password for this '
                    . 'service — not on the service record and not in its custom fields — so there is none to give. '
                    . 'Offer to reset it, which sets a new one and emails it.' . $guideLine,
                'data' => $open,
            );
        }

        /*
         * AT MOST THREE TIMES AN HOUR, for the account and for the user. Every call emailed a fresh
         * copy of the password (or showed it), and there was no limit: anyone holding a live chat
         * could fill the client's mailbox with password emails, each copy kept in WHMCS's email log.
         *
         * Checked HERE, once a password is about to leave, and counting only the times one did. It
         * used to run first and count every answer: a team member without password access who asked
         * three times (and got the username only) locked the account owner out for an hour, and the
         * owner was told the details "were already sent" when nothing had been.
         */
        $recent = AuditLog::recentSuccesses('service_credentials', $clientId, $context->claimedUserId(), 3600,
            array(self::CREDENTIALS_EMAILED, self::CREDENTIALS_SHOWN));
        if ($recent['client'] >= self::CREDENTIALS_PER_HOUR || $recent['user'] >= self::CREDENTIALS_PER_HOUR) {
            throw new ActionError('not_allowed', 'Login details can be sent at most ' . self::CREDENTIALS_PER_HOUR . ' times an hour, '
                . 'and that limit has been reached, so nothing was sent this time. The client can ask again later. '
                . 'If they asked for the details by email earlier, that email may be in their spam folder.'
                . ($sso !== '' ? ' The control panel still opens in one click, with no password: ' . $sso : ''));
        }

        if ($deliver === 'chat') {
            return array(
                'summary' => $lead . 'The login details for ' . $label . ' are ready. ' . $userLine
                    . ' Give that in your reply, and say the password is on the "Show my login details" button under your reply, '
                    . 'which only they can open. NEVER write a password into your reply yourself — you have not been given it.'
                    . $guideLine,
                'audit' => 'service #' . $p['id'] . ' ' . self::CREDENTIALS_SHOWN,
                'data' => $open,
                // STRIPPED BY MAGIZAI BEFORE ANYTHING IS STORED OR SENT TO THE MODEL.
                'secret' => array(
                    'kind' => 'service_credentials',
                    'title' => $label,
                    'username' => $username,
                    'password' => $password,
                    'panel_url' => $panel,
                ),
            );
        }

        $email = Credentials::emailCredentials($clientId, $label, $username, $password, $panel);

        if ($email === null) {
            // WHMCS did not send it. Saying "emailed" here sent clients looking for nothing. Staff are
            // told too, where they already look: the client may well open a ticket next.
            if (function_exists('logActivity')) {
                logActivity('MagizAI live chat: the client asked for the login details of service #' . (int) $p['id']
                    . ', but the email carrying them could not be sent. Check the mail settings, or send them from the service page.', (int) $clientId);
            }

            return array(
                'summary' => $lead . $userLine . ' The password could not be emailed just now: WHMCS did not send the email, so nothing went out. '
                    . ($chatAllowed
                        ? 'They can ask to see it here in the chat instead' . ($staleForChat ? ', after refreshing the client area page' : '') . '.'
                        : 'Offer to pass this to the team, who can send the login details.')
                    . $guideLine,
                'audit' => 'service #' . $p['id'] . ' credentials email failed',
                'data' => $open + array('delivery' => 'email_failed'),
            );
        }

        return array(
            'summary' => $lead . $userLine . ' The password has been emailed to '
                . $email . '. It can take a minute and it may land in spam.'
                . ($chatAllowed
                    ? ($staleForChat ? ' (It is shown in the chat only soon after the client signs in, so it was emailed instead.)' : '')
                    : ' (The host has not enabled showing passwords in the chat.)')
                . $guideLine,
            'audit' => 'service #' . $p['id'] . ' ' . self::CREDENTIALS_EMAILED,
            'data' => $open,
        );
    }
}
