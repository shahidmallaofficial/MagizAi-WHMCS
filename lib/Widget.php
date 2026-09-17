<?php

namespace MagizAI\Whmcs;

use WHMCS\Database\Capsule;

/**
 * The chat widget on client-area pages, with a signed identity for whoever is signed in.
 *
 * The identity token is what lets the assistant answer "when does my domain expire?" for THIS
 * client and nobody else. It is minted server-side on every page load (short-lived, HS256 with the
 * identity secret) and only the finished token reaches the browser — never the secret.
 */
class Widget
{
    public static function footer(array $vars)
    {
        $placement = Settings::get('widget_placement', 'all');
        $key = trim(Settings::get('widget_key'));

        if ($placement === 'off' || !preg_match('/^[A-Za-z0-9_-]{4,80}$/', $key)) {
            return '';
        }

        $client = self::currentClient();

        if ($placement === 'loggedin' && !$client) {
            return '';
        }

        $html = '';
        $secret = Settings::get('identity_secret');

        if ($client && $secret !== '') {
            $token = Crypto::mintIdentity($secret, $client, 60 * max(5, (int) Settings::get('identity_ttl_minutes', '60')));
            // JSON_HEX_* so nothing in a client's name can close the script tag.
            $html .= '<script>window.MagizAIIdentity=' . json_encode($token, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';</script>';
        }

        $html .= '<script src="' . htmlspecialchars(Settings::magizaiBase() . '/widget.js', ENT_QUOTES) . '" data-key="' . htmlspecialchars($key, ENT_QUOTES) . '" defer></script>';

        return $html;
    }

    /**
     * The client account currently selected in this session, as identity claims — or null.
     *
     * WHMCS 8 separates the login (User) from the account (Client); a user can manage several
     * accounts. The claims name the ACCOUNT, because that is what every lookup is scoped to. An admin
     * "logged in as client" gets no identity: changes made that way should be made from the admin
     * area, where they are attributed to the admin.
     */
    private static function currentClient()
    {
        $clientId = 0;
        $email = '';

        if (class_exists('\WHMCS\Authentication\CurrentUser')) {
            $current = new \WHMCS\Authentication\CurrentUser();
            if ($current->isMasqueradingAdmin()) {
                return null;
            }
            $client = $current->client();
            $user = $current->user();
            if (!$client || !$user) {
                return null;
            }
            $clientId = (int) $client->id;
            $email = (string) $user->email;
        } elseif (!empty($_SESSION['uid']) && empty($_SESSION['adminid'])) {
            $clientId = (int) $_SESSION['uid'];
        }

        if ($clientId <= 0) {
            return null;
        }

        $row = Capsule::table('tblclients')->where('id', $clientId)->first(array('firstname', 'lastname', 'companyname', 'email', 'status'));
        if (!$row || $row->status === 'Closed') {
            return null;
        }

        return array_filter(array(
            'sub' => (string) $clientId,
            'external_id' => (string) $clientId,
            'email' => $email !== '' ? $email : (string) $row->email,
            'name' => trim($row->firstname . ' ' . $row->lastname),
            'company' => (string) $row->companyname,
        ), function ($v) {
            return $v !== '';
        });
    }
}
