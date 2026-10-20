<?php

namespace MagizAI\Whmcs\Domain;

use MagizAI\Whmcs\ActionError;
use MagizAI\Whmcs\Install\Tables;
use MagizAI\Whmcs\Schema;
use MagizAI\Whmcs\Settings;
use WHMCS\Database\Capsule;

/**
 * Accounts the chat opened for new clients (1.8.0), and the limits on opening them.
 *
 * WHY LIMITS. Opening an account is the one change the chat makes for somebody nobody has signed in:
 * every request comes from MagizAI's servers, so the bridge's per-client limit cannot apply. Without a
 * limit of its own, a script talking to the chat could fill WHMCS with accounts. So: one account per
 * conversation, a few a day per visitor address when MagizAI sends one (`ip`), and an hourly ceiling for
 * the whole install that the admin chooses (Setup tab, default 5). A limit that cannot be counted refuses.
 *
 * The record is also what the sign-in link checks: a link is only ever for an account its OWN
 * conversation opened, in the last SIGN_IN_HOURS, at most SIGN_INS_EACH times.
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class Signups
{
    /** Accounts one visitor address may open from the chat in a day. */
    const PER_ADDRESS_PER_DAY = 3;

    /** How long after opening an account its conversation may still get a sign-in link. */
    const SIGN_IN_HOURS = 2;

    /** Sign-in links one opened account may get: enough to pay after a closed tab, not a standing key. */
    const SIGN_INS_EACH = 5;

    /** The table, created on the spot when new files were uploaded and nothing has run the upgrade step yet. */
    public static function ensureTable()
    {
        if (!Schema::hasTable(Tables::SIGNUPS)) {
            Tables::install();
        }
    }

    /**
     * Refuse before anything is created when this conversation, this address or this install has opened
     * as many accounts as it may. Throws when the counts cannot be read.
     */
    public static function mustBeUnderLimits($conversation, $ip)
    {
        self::ensureTable();
        $conversation = substr((string) $conversation, 0, 64);
        if ($conversation === '') {
            throw new ActionError('not_allowed', 'An account can only be opened from a live chat conversation.');
        }

        if (Capsule::table(Tables::SIGNUPS)->where('conversation', $conversation)->exists()) {
            throw new ActionError('not_allowed', 'An account was already opened in this chat. They can sign in to it, '
                . 'or use the link they were given to finish paying. Do not open another one.');
        }

        $day = date('Y-m-d H:i:s', time() - 86400);
        if ($ip !== '' && Capsule::table(Tables::SIGNUPS)->where('ip', $ip)->where('created_at', '>=', $day)->count() >= self::PER_ADDRESS_PER_DAY) {
            throw new ActionError('not_allowed', 'No more accounts can be opened from the chat today. They can sign up in the store: '
                . Settings::systemUrl() . 'cart.php');
        }

        $hour = date('Y-m-d H:i:s', time() - 3600);
        $ceiling = max(1, (int) Settings::get('signups_per_hour', '5'));
        if (Capsule::table(Tables::SIGNUPS)->where('created_at', '>=', $hour)->count() >= $ceiling) {
            throw new ActionError('not_allowed', 'New accounts cannot be opened from the chat just now. They can sign up in the store: '
                . Settings::systemUrl() . 'cart.php');
        }
    }

    /** "Already has an account" answers one conversation may get in an hour before it gets none. */
    const EXISTS_ANSWERS_PER_HOUR = 3;

    /**
     * Refuse a conversation that keeps trying addresses WHMCS already has. "There is already an account
     * with that address" is what WHMCS's own sign-up page says too, but the chat can be scripted, so a
     * conversation gets that answer a few times an hour and then a plain refusal that says nothing about
     * the address. Counted from the bridge's own activity log; a log that cannot be read refuses.
     */
    public static function mustNotBeProbing($conversation)
    {
        $tries = Capsule::table(Tables::AUDIT)->where('action', 'client_signup')->where('outcome', 'refused')
            ->where('error', 'account_exists')->where('conversation', substr((string) $conversation, 0, 64))
            ->where('created_at', '>=', date('Y-m-d H:i:s', time() - 3600))->count();
        if ($tries >= self::EXISTS_ANSWERS_PER_HOUR) {
            throw new ActionError('not_allowed', 'No account can be opened from this chat just now. They can sign up in the store: '
                . Settings::systemUrl() . 'cart.php, or sign in at ' . Settings::systemUrl() . 'clientarea.php if they already have an account.');
        }
    }

    /** @return int the record's id */
    public static function record($conversation, $clientId, $ip)
    {
        self::ensureTable();

        return (int) Capsule::table(Tables::SIGNUPS)->insertGetId(array(
            'conversation' => substr((string) $conversation, 0, 64),
            'client_id' => (int) $clientId,
            'invoice_id' => null,
            'ip' => substr((string) $ip, 0, 45),
            'sign_ins' => 0,
            'created_at' => date('Y-m-d H:i:s'),
        ));
    }

    public static function attachInvoice($id, $invoiceId)
    {
        if ((int) $invoiceId > 0) {
            Capsule::table(Tables::SIGNUPS)->where('id', (int) $id)->update(array('invoice_id' => (int) $invoiceId));
        }
    }

    /**
     * The account this conversation opened, while its sign-in link may still be used: null when it opened
     * none, too long ago, or has used its links up.
     *
     * @return object|null the row: id, client_id, invoice_id, sign_ins
     */
    public static function openedBy($conversation)
    {
        self::ensureTable();
        $conversation = substr((string) $conversation, 0, 64);
        if ($conversation === '') {
            return null;
        }

        $row = Capsule::table(Tables::SIGNUPS)->where('conversation', $conversation)
            ->where('created_at', '>=', date('Y-m-d H:i:s', time() - self::SIGN_IN_HOURS * 3600))
            ->orderBy('id', 'desc')->first();

        return $row && (int) $row->sign_ins < self::SIGN_INS_EACH ? $row : null;
    }

    /** Count one sign-in link. Only a row still under the limit moves, so two at once cannot both pass it. */
    public static function useSignIn($id)
    {
        return Capsule::table(Tables::SIGNUPS)->where('id', (int) $id)
            ->where('sign_ins', '<', self::SIGN_INS_EACH)
            ->increment('sign_ins') === 1;
    }
}
