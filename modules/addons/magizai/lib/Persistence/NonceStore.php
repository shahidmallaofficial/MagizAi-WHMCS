<?php

namespace MagizAI\Whmcs\Persistence;

use MagizAI\Whmcs\Install\Tables;
use WHMCS\Database\Capsule;

/**
 * Replay protection: every signed request's nonce is recorded once, and a nonce seen before is a
 * replay. Old nonces are purged, since a request that old is already refused by its timestamp.
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class NonceStore
{
    /** Nonces older than this are purged; requests older than this are already refused by timestamp. */
    const TTL = 900;

    /**
     * Record a nonce. False when it has been seen before — the request is a replay.
     *
     * ONLY a duplicate key is a replay. Any other database failure used to be reported as one too:
     * MagizAI was told "this request was already processed", which is not a broken connection, so
     * a database that had stopped answering raised no alert. It now throws, and the bridge answers
     * with a signed 503 'store_unavailable'. The purge of old nonces sits under the same rule: a
     * failure there is a fault too, never a replay.
     *
     * @throws \Exception when the nonce store cannot be used at all
     */
    public static function claim($nonce)
    {
        try {
            Capsule::table(Tables::NONCES)->where('created_at', '<', time() - self::TTL)->delete();
            Capsule::table(Tables::NONCES)->insert(array('nonce' => $nonce, 'created_at' => time()));

            return true;
        } catch (\Exception $e) {
            if (self::isDuplicateKey($e)) {
                return false;
            }

            throw $e;
        }
    }

    /** SQLSTATE 23000, integrity constraint violation: for this insert, the nonce already exists. */
    private static function isDuplicateKey(\Exception $e)
    {
        $state = '';
        if ($e instanceof \PDOException && is_array($e->errorInfo) && isset($e->errorInfo[0])) {
            $state = (string) $e->errorInfo[0];
        }
        if ($state === '') {
            $state = (string) $e->getCode();
        }

        return $state === '23000';
    }
}
