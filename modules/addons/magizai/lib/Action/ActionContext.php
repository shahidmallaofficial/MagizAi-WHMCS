<?php

namespace MagizAI\Whmcs\Action;

use MagizAI\Whmcs\Identity\Verifier;

/**
 * The request an action runs for: its arguments, the client the bridge verified, and the verified
 * identity claims, which the few actions that need more than the client id read.
 *
 * One request is one PHP run in WHMCS. The context of the action running now is also kept as the
 * CURRENT one, for the shared rules that need the sign-in without being handed it (whether the user
 * may see invoices, Permissions::userMay). It stays current after the action returns, as the claims
 * always did, until the next action runs or Runner::forget(). Nobody signed in, no token, no
 * conversation when no action has run.
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class ActionContext
{
    /** @var ActionContext|null the request the running action, or the last one, is for */
    private static $current = null;

    /** @var array */
    private $args;

    /** @var int|null */
    private $clientId;

    /** @var array verified identity claims of the request */
    private $claims;

    /**
     * Did this run arrive with an identity token? The bridge ALWAYS passes the verified claims, so
     * this is true for every request MagizAI makes. False only for an in-process caller that
     * established the client itself and so has no token to judge.
     *
     * @var bool
     */
    private $viaToken;

    /**
     * MagizAI's conversation id, which is the only per-visitor key a public read has.
     *
     * @var string
     */
    private $conversation;

    /**
     * @param int|null $clientId the client the bridge verified, or none
     * @param array    $claims   the verified identity claims (empty for a public action)
     * @param bool     $viaToken whether the claims came from a token the bridge verified
     */
    public function __construct(array $args, $clientId, array $claims, $viaToken, $conversation)
    {
        $this->args = $args;
        $this->clientId = $clientId === null ? null : (int) $clientId;
        $this->claims = $claims;
        $this->viaToken = (bool) $viaToken;
        $this->conversation = (string) $conversation;
    }

    /** The context of the action running now, or of the last one; an empty one before any. */
    public static function current()
    {
        if (self::$current === null) {
            self::$current = new self(array(), null, array(), false, '');
        }

        return self::$current;
    }

    /** From now on, $context is the current one. */
    public static function enter(ActionContext $context)
    {
        self::$current = $context;
    }

    /** Back to nobody: no claims, no token, no conversation. */
    public static function forget()
    {
        self::$current = null;
    }

    /** @return array */
    public function args()
    {
        return $this->args;
    }

    /** @return int|null */
    public function clientId()
    {
        return $this->clientId;
    }

    /** @return array */
    public function claims()
    {
        return $this->claims;
    }

    /** @return bool */
    public function viaToken()
    {
        return $this->viaToken;
    }

    /** @return string */
    public function conversation()
    {
        return $this->conversation;
    }

    /** The same request with other arguments: one action asking another (a setup guide search). */
    public function withArgs(array $args)
    {
        $copy = clone $this;
        $copy->args = $args;

        return $copy;
    }

    /** The same request for this client: the one the checks before the action settled on. */
    public function withClientId($clientId)
    {
        $copy = clone $this;
        $copy->clientId = $clientId === null ? null : (int) $clientId;

        return $copy;
    }

    /** The WHMCS user id the sign-in names, or 0. */
    public function claimedUserId()
    {
        $uid = isset($this->claims['uid']) ? (string) $this->claims['uid'] : '';

        return ctype_digit($uid) ? (int) $uid : 0;
    }

    /**
     * Was the sign-in made within the last $minutes? An in-process caller has no token to judge
     * and is taken at its word.
     */
    public function signedInWithin($minutes)
    {
        if (!$this->viaToken) {
            return true;
        }

        $iat = isset($this->claims['iat']) ? (int) $this->claims['iat'] : 0;

        return $iat > 0 && time() - $iat <= max(1, (int) $minutes) * 60;
    }

    /** Is this request a staff member viewing the client's account (staff view)? */
    public function staffView()
    {
        return $this->viaToken && Verifier::staffActor($this->claims) !== null;
    }
}
