<?php

namespace MagizAI\Whmcs\Action;

use MagizAI\Whmcs\ActionError;
use MagizAI\Whmcs\Persistence\AuditLog;
use MagizAI\Whmcs\Settings;

/**
 * Reads that cost something outside WHMCS, and how often each may run in an hour. Checked before
 * the action runs. @see Runner::run
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class ReadLimits
{
    /** How often each limited read ran in the last hour, as counted when this request began. */
    private static $readCounts = array();

    /**
     * READS THAT COST SOMETHING OUTSIDE WHMCS, and how often each may run in an hour.
     *
     * The bridge limits account CHANGES per client. A read with a side effect slipped past that:
     * domain_check is public, needs no sign-in, and runs up to six registry lookups per call, so a
     * scripted visitor could make the host's WHMCS fire hundreds of WHOIS queries an hour and get its
     * address throttled by the registries, which then fails the lookups real customers make.
     *
     * Counted per MagizAI conversation (the bridge never sees the visitor's own address: every call
     * comes from MagizAI's servers) and across the whole install. Over either limit, the client is
     * pointed at the store, where WHMCS's own lookup and its own protections apply.
     *
     * The service password email has its own, stricter limit. @see \MagizAI\Whmcs\Action\Services\ServiceCredentials::CREDENTIALS_PER_HOUR
     */
    const READ_LIMITS = array(
        'domain_check' => array('conversation' => 12, 'install' => 150),
    );

    /**
     * Refuse a limited read that ran too often in the last hour, in this conversation or across the
     * install. Only runs that went through count; a refused one cost nothing. The audit rows are the
     * count, so a log that cannot be read refuses rather than lets everything through.
     */
    public static function mustBeUnderReadLimit($action)
    {
        $limits = self::READ_LIMITS[$action];
        $conversation = ActionContext::current()->conversation();

        if ($conversation !== '' && AuditLog::recentRuns($action, 3600, $conversation) >= (int) $limits['conversation']) {
            throw new ActionError('not_allowed', self::readLimitMessage($action, true));
        }

        $install = AuditLog::recentRuns($action, 3600);
        self::$readCounts[$action] = $install;
        if ($install >= (int) $limits['install']) {
            throw new ActionError('not_allowed', self::readLimitMessage($action, false));
        }
    }

    /**
     * How often this read ran across the install in the last hour, as counted when this request began,
     * or null when it was not counted.
     */
    public static function counted($action)
    {
        return isset(self::$readCounts[$action]) ? (int) self::$readCounts[$action] : null;
    }

    /** What the client hears when a limited read has run too often. */
    public static function readLimitMessage($action, $inThisChat)
    {
        if ($action !== 'domain_check') {
            return 'This has been asked for several times in the last hour, so it is paused for a little while. '
                . 'The client can find it in the client area: ' . Settings::systemUrl() . 'clientarea.php';
        }

        return ($inThisChat
                ? 'Several domains have already been checked in this chat in the last hour, so no more are checked here for now.'
                : 'Domain checks from the chat are paused for a little while, because many have been made in the last hour.')
            . ' The client can search for any name in the store, where the result is instant: '
            . Settings::systemUrl() . 'cart.php?a=add&domain=register';
    }

    /** Forget what was remembered for the request that ran last. @see \MagizAI\Whmcs\Action\Runner::forget */
    public static function forget()
    {
        self::$readCounts = array();
    }
}
