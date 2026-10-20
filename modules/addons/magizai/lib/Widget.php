<?php

namespace MagizAI\Whmcs;

use MagizAI\Whmcs\Hooks\FooterHook;
use MagizAI\Whmcs\Identity\IdentityResolver;
use MagizAI\Whmcs\Identity\PageSettings;

/**
 * The chat widget on client-area pages, with a signed identity for whoever is signed in, and the
 * reason when there is none.
 *
 * The footer itself is Hooks\FooterHook, the decision who is signed in Identity\IdentityResolver,
 * and what the admin chose for the pages Identity\PageSettings. This facade keeps every name the
 * module published here.
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class Widget
{
    /** What a widget key looks like. Anything else is never written into a page. */
    const KEY_PATTERN = Identity\PageSettings::KEY_PATTERN;

    /** The reasons a page can give for having no client token. */
    const REASONS = Identity\IdentityResolver::REASONS;

    public static function footer(array $vars)
    {
        return FooterHook::run($vars);
    }

    /** @see PageSettings::onPages */
    public static function onPages()
    {
        return PageSettings::onPages();
    }

    /** @see PageSettings::placement */
    public static function placement()
    {
        return PageSettings::placement();
    }

    /** @see PageSettings::staffMode */
    public static function staffMode()
    {
        return PageSettings::staffMode();
    }

    /** @see PageSettings::endpointPath */
    public static function endpointPath()
    {
        return PageSettings::endpointPath();
    }

    /**
     * @see IdentityResolver::resolve
     * @return array{claims: array|null, status: string, reason: string|null, signed_in: bool}
     */
    public static function resolve(?array $session = null)
    {
        return IdentityResolver::resolve($session);
    }

    /**
     * @see IdentityResolver::issue
     * @return array{claims: array|null, status: string, reason: string|null, signed_in: bool, token: string|null, exp: int|null}
     */
    public static function issue(?array $session = null)
    {
        return IdentityResolver::issue($session);
    }

    /** @see PageSettings::ttlMinutes */
    public static function ttlMinutes()
    {
        return PageSettings::ttlMinutes();
    }

    /** @see IdentityResolver::state */
    public static function state(array $outcome)
    {
        return IdentityResolver::state($outcome);
    }
}
