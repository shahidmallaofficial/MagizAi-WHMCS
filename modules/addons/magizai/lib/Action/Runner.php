<?php

namespace MagizAI\Whmcs\Action;

use MagizAI\Whmcs\ActionError;
use MagizAI\Whmcs\Domain\Clients;
use MagizAI\Whmcs\Domain\ControlPanel;
use MagizAI\Whmcs\Domain\Pricing;
use MagizAI\Whmcs\Domain\Products;
use MagizAI\Whmcs\Identity\Verifier;
use MagizAI\Whmcs\Install\EmailTemplate;

/**
 * Runs one action for one request: everything that decides whether it may run at all, then the
 * action. Enforced HERE, once, so no action added later can forget any of it.
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class Runner
{
    /**
     * @param array|null $claims  the verified identity claims. The bridge always passes an array
     *                            (empty for a public action). null is an in-process caller that
     *                            established the client itself and has no token to judge.
     * @param array      $request what else the bridge knows: 'conversation', MagizAI's id for it
     * @return array what the action returned
     * @throws ActionError
     */
    public static function run($action, array $args, $clientId, ?array $claims = null, array $request = array())
    {
        $instance = Registry::make($action);
        if ($instance === null) {
            throw new ActionError('invalid_args', 'This is not supported.');
        }

        self::forgetMemory();
        $context = new ActionContext($args, null, is_array($claims) ? $claims : array(), is_array($claims),
            isset($request['conversation']) && is_scalar($request['conversation']) ? substr((string) $request['conversation'], 0, 64) : '');
        ActionContext::enter($context);

        $spec = $instance->spec();

        /*
         * A CLIENT ID IS A POSITIVE NUMBER, OR THERE IS NO CLIENT.
         *
         * Client 0 used to get through: it skipped the client-exists check and the permission gate
         * (both read "if ($clientId && ...)"), then reached list lookups with clientid 0, and a
         * guest ticket's userid IS 0. An action about an account refuses to run without one.
         */
        $clientId = (int) $clientId > 0 ? (int) $clientId : null;
        if ($spec['identity'] === true && $clientId === null) {
            throw new ActionError('identity_missing', Verifier::message('identity_missing'));
        }

        /*
         * THE SIGN-IN MUST NAME A USER OF THIS ACCOUNT (WHMCS 8 and later, where users exist).
         *
         * A token with no user id used to be treated as the account OWNER. Every token this module
         * mints on WHMCS 8 names its user, so one that does not was minted by something else, or
         * by a version before 1.5.6 (and has long expired). It is refused, never promoted to owner,
         * as a sign-in that does not match this account. A price list stays public: a sign-in that
         * cannot be used there simply drops to the public prices, the same as a lapsed one.
         */
        if ($clientId !== null && $context->viaToken() && !Permissions::tokenUserBelongs($clientId)) {
            if ($spec['identity'] === 'optional' && $spec['group'] === 'public') {
                $clientId = null;
            } else {
                throw new ActionError('identity_mismatch', Verifier::message('identity_mismatch'));
            }
        }

        // Enforced HERE, once, so no action added later can forget it.
        if ($clientId !== null && isset(Permissions::NEEDED[$action])) {
            Permissions::mustMay($clientId, Permissions::NEEDED[$action]);
        }

        // Also here, for the same reason: a read with a cost outside WHMCS. @see ReadLimits::READ_LIMITS
        if (isset(ReadLimits::READ_LIMITS[$action])) {
            ReadLimits::mustBeUnderReadLimit($action);
        }

        $context = $context->withClientId($clientId);
        ActionContext::enter($context);

        return $instance->handle($context);
    }

    /**
     * Forget everything remembered for the current request.
     *
     * One request is one PHP run in WHMCS, so in production this only matters to code that runs
     * several requests in one process: the test harness, which calls it before every test.
     */
    public static function forget()
    {
        ActionContext::forget();
        self::forgetMemory();
    }

    /** What the lookups of the last request remembered: none of it is the next request's answer. */
    private static function forgetMemory()
    {
        Permissions::forget();
        ReadLimits::forget();
        ControlPanel::forget();
        Products::forget();
        Pricing::forget();
        Clients::forget();
        EmailTemplate::forget();
    }
}
