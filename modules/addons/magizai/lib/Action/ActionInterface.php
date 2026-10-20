<?php

namespace MagizAI\Whmcs\Action;

/**
 * One thing the assistant can ask WHMCS for: lib/Action/<Area>/<Name>.php, listed in Registry.
 *
 * Rules every action follows:
 *  - A client action takes the client id the BRIDGE verified (ActionContext::clientId()), never an
 *    id from the arguments.
 *  - Anything named in the arguments (a domain, a service, an invoice, a ticket) is looked up UNDER
 *    that client. Not found and not yours give the same answer, so the chat cannot be used to probe
 *    which domains exist on other accounts. @see \MagizAI\Whmcs\Domain\Ownership
 *  - No EPP code and no payment details are ever returned.
 *  - A service USERNAME and PASSWORD are returned by one action only, service_credentials, and only
 *    for the client the bridge verified.
 *  - `summary` is what the assistant reads: short, factual, no HTML.
 *
 * Who may run an action at all (identity, permissions, read limits) is decided before handle() is
 * called, once, in Runner::run, so no action added later can forget it.
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
interface ActionInterface
{
    /**
     * What the action is, exactly as Actions::catalog() lists it:
     * array('identity' => true|false|'optional', 'write' => bool, 'group' => public|read|write|sensitive,
     * 'label' => what the admin page says).
     *
     * identity 'optional': the action answers anonymously, and differently for a signed-in client.
     *
     * @return array{identity: bool|string, write: bool, group: string, label: string}
     */
    public function spec();

    /**
     * Run the action for the request in $context.
     *
     * @return array{summary: string, data?: array, audit?: string, notify?: string, secret?: array, changed?: bool}
     * @throws \MagizAI\Whmcs\ActionError a refusal written for the client
     */
    public function handle(ActionContext $context);
}
