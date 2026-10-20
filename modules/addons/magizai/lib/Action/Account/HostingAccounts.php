<?php

namespace MagizAI\Whmcs\Action\Account;

use MagizAI\Whmcs\Action\ActionContext;
use MagizAI\Whmcs\Action\ActionInterface;
use WHMCS\Database\Capsule;

/**
 * The client's own hosting accounts: which domain, which system user, which server.
 *
 * Only what an ownership check needs. No passwords, no disk usage, no billing.
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class HostingAccounts implements ActionInterface
{
    public function spec()
    {
        // Used only by MagizAI's Site Doctor, to check that a website belongs to the signed-in
        // client before its server is inspected. MagizAI never shows this to the assistant or the
        // client: it is an ownership check, not an answer. Switch it off to stop site checks.
        return array('identity' => true, 'write' => false, 'group' => 'read', 'label' => 'Hosting accounts (Site Doctor ownership check)');
    }

    public function handle(ActionContext $context)
    {
        $clientId = $context->clientId();

        /*
         * FROM tblhosting, LIVE AND NEWEST FIRST — never paged out of the API.
         *
         * GetClientsProducts pages OLDEST first with no status filter, so for a client with more
         * than 100 service rows the newest hosting accounts fell off the page, and Site Doctor
         * refused to check a site the client really owns. This client's live rows only.
         */
        $rows = Capsule::table('tblhosting as h')
            ->leftJoin('tblservers as s', 's.id', '=', 'h.server')
            ->leftJoin('tblproducts as p', 'p.id', '=', 'h.packageid')
            ->where('h.userid', (int) $clientId)
            ->whereIn('h.domainstatus', array('Active', 'Suspended'))
            ->orderBy('h.id', 'desc')
            ->limit(200)
            ->get(array('h.domain', 'h.username', 'h.domainstatus', 's.hostname as server_hostname', 's.name as server_name', 'p.name as product'));
        $accounts = array();

        foreach ($rows as $row) {
            $domain = strtolower(trim((string) $row->domain));
            $username = trim((string) $row->username);
            if ($domain === '' && $username === '') {
                continue;
            }
            $accounts[] = array(
                'domain' => $domain,
                'username' => $username,
                'server_hostname' => strtolower(trim((string) $row->server_hostname)),
                'server_name' => (string) $row->server_name,
                'status' => (string) $row->domainstatus,
                'product' => (string) $row->product,
            );
            if (count($accounts) >= 50) {
                break;
            }
        }

        return array(
            'summary' => count($accounts) . ' hosting account(s) on this client.',
            'data' => array('accounts' => $accounts),
        );
    }
}
