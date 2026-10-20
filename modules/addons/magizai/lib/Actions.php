<?php

namespace MagizAI\Whmcs;

use MagizAI\Whmcs\Action\Permissions;
use MagizAI\Whmcs\Action\Registry;
use MagizAI\Whmcs\Action\Runner;
use MagizAI\Whmcs\Domain\Clients;
use MagizAI\Whmcs\Http\BridgeResponse;
use MagizAI\Whmcs\Support\Departments;
use MagizAI\Whmcs\Support\Format;
use MagizAI\Whmcs\Support\WhmcsApi;

/**
 * Everything the assistant can ask WHMCS for, as the bridge, the knowledge sync and older callers
 * use it. Each action is its own class under lib/Action (listed in Action\Registry); the rules they
 * share live under lib/Domain and lib/Support. This facade keeps every name the module ever
 * published here, so nothing that calls it has to change.
 *
 * The catalogue must stay in step with WhmcsConnector::ACTIONS on the MagizAI side.
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class Actions
{
    const MAX_UPGRADE_QUOTES = Domain\Upgrades::MAX_UPGRADE_QUOTES;
    const OFF_UNTIL_TICKED = Action\Registry::OFF_UNTIL_TICKED;
    const STAFF_VIEW_REFUSED = Action\Registry::STAFF_VIEW_REFUSED;
    const PERMISSIONS = Action\Permissions::NEEDED;
    const READ_LIMITS = Action\ReadLimits::READ_LIMITS;
    const DOMAIN_ALTERNATIVES_BUDGET = Action\Catalog\DomainCheck::DOMAIN_ALTERNATIVES_BUDGET;
    const DOMAIN_ALTERNATIVES_SIGNED_IN = Action\Catalog\DomainCheck::DOMAIN_ALTERNATIVES_SIGNED_IN;
    const DOMAIN_ALTERNATIVES_ANONYMOUS = Action\Catalog\DomainCheck::DOMAIN_ALTERNATIVES_ANONYMOUS;
    const OPEN_INVOICE_STATUSES = Domain\Statuses::OPEN_INVOICE_STATUSES;
    const LIVE_DOMAIN_STATUSES = Domain\Statuses::LIVE_DOMAIN_STATUSES;
    const LIVE_SERVICE_STATUSES = Domain\Statuses::LIVE_SERVICE_STATUSES;
    const SERVICE_ITEM_TYPES = Domain\Statuses::SERVICE_ITEM_TYPES;
    const DOMAIN_ITEM_TYPES = Domain\Statuses::DOMAIN_ITEM_TYPES;
    const SERVICE_DUE_SOON_DAYS = Domain\Statuses::SERVICE_DUE_SOON_DAYS;
    const DOMAIN_EXPIRES_SOON_DAYS = Domain\Statuses::DOMAIN_EXPIRES_SOON_DAYS;
    const CREDENTIALS_PER_HOUR = Action\Services\ServiceCredentials::CREDENTIALS_PER_HOUR;
    const CREDENTIALS_EMAILED = Action\Services\ServiceCredentials::CREDENTIALS_EMAILED;
    const CREDENTIALS_SHOWN = Action\Services\ServiceCredentials::CREDENTIALS_SHOWN;
    const KB_STOPWORDS = Domain\Knowledgebase::KB_STOPWORDS;
    const KB_CANDIDATES = Domain\Knowledgebase::KB_CANDIDATES;
    const OVERVIEW_ITEMS = Action\Account\ClientSummary::OVERVIEW_ITEMS;
    const SERVICES_SHOWN = Action\Services\ServicesList::SERVICES_SHOWN;
    const DOMAINS_SHOWN = Action\Domains\DomainsList::DOMAINS_SHOWN;
    const DOMAIN_RECENTLY_EXPIRED_DAYS = Action\Domains\DomainsList::DOMAIN_RECENTLY_EXPIRED_DAYS;
    const PAYMENTS_LISTED = Action\Billing\PaymentStatus::PAYMENTS_LISTED;
    const COVERS_LISTED = Domain\Payments::COVERS_LISTED;
    const COVERS_IN_DATA = Domain\Payments::COVERS_IN_DATA;
    const RESENDS_PER_HOUR = Action\Account\EmailResend::RESENDS_PER_HOUR;
    const INVOICE_TEMPLATES = Action\Account\EmailResend::INVOICE_TEMPLATES;
    const SSO_MODULES = Domain\ControlPanel::SSO_MODULES;

    /** What the client hears when the sign-in itself cannot be relied on. The bridge says the same. */
    const SIGN_IN_AGAIN = 'The client\'s sign-in could not be confirmed or has expired. Ask them to refresh the client area page and try again.';

    /**
     * EXACTLY what crosses the wire back to MagizAI after a successful action: ok, summary, data and,
     * when there is one, the secret. Never the audit text, the client notice or "changed".
     * @see BridgeResponse::replyFor
     *
     * @param array $result what an action returned
     * @return array
     */
    public static function replyFor(array $result)
    {
        return BridgeResponse::replyFor($result);
    }

    /** @see Registry::changedAnything */
    public static function changedAnything($action, array $result)
    {
        return Registry::changedAnything($action, $result);
    }

    /** @return array<string, array{identity: bool|string, write: bool, group: string, label: string}> */
    public static function catalog()
    {
        return Registry::catalog();
    }

    /** @see Registry::defaultEnabled */
    public static function defaultEnabled()
    {
        return Registry::defaultEnabled();
    }

    /** @see Runner::forget */
    public static function forget()
    {
        Runner::forget();
    }

    /** @see Runner::run */
    public static function run($action, array $args, $clientId, ?array $claims = null, array $request = array())
    {
        return Runner::run($action, $args, $clientId, $claims, $request);
    }

    /** @see Permissions::userMay */
    public static function userMay($clientId, $permission)
    {
        return Permissions::userMay($clientId, $permission);
    }

    /** @see Registry::staffMayRun */
    public static function staffMayRun($action)
    {
        return Registry::staffMayRun($action);
    }

    /** @see Clients::notifyClient */
    public static function notifyClient($clientId, $text)
    {
        return Clients::notifyClient($clientId, $text);
    }

    /** @see WhmcsApi::api */
    public static function api($command, array $params, $throw = true, $errorCode = 'internal', array $secrets = array())
    {
        return WhmcsApi::api($command, $params, $throw, $errorCode, $secrets);
    }

    /** @see Departments::supportDepartments */
    public static function supportDepartments($strict = false)
    {
        return Departments::supportDepartments($strict);
    }

    public static function kbUrl($id, $title)
    {
        return Format::kbUrl($id, $title);
    }

    public static function plain($html)
    {
        return Format::plain($html);
    }
}
