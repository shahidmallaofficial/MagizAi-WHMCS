<?php

namespace MagizAI\Whmcs\Domain;

use MagizAI\Whmcs\Mailer;
use MagizAI\Whmcs\Schema;
use MagizAI\Whmcs\Support\WhmcsApi;
use MagizAI\Whmcs\Text;
use WHMCS\Database\Capsule;

/**
 * A service's stored login: the password read correctly for both ways WHMCS installs keep it
 * (WHMCS ciphertext or plain text), a credential kept in custom fields, the email that carries it,
 * and a strong new password for a reset.
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class Credentials
{
    /**
     * Email the login details to the address on the account.
     *
     * @return string|null the masked address, for the assistant to repeat — or null when WHMCS did
     *                     not send the email
     */
    public static function emailCredentials($clientId, $label, $username, $password, $panel)
    {
        $sent = Mailer::send('general', (int) $clientId, 'Your login details for ' . $label,
            '<p>You asked for these in live chat.</p>'
            . '<p><strong>' . Text::mail($label) . '</strong><br>'
            . ($username !== '' ? 'Username: <strong>' . Text::mail($username) . '</strong><br>' : '')
            . 'Password: <strong>' . Text::mail($password) . '</strong></p>'
            . ($panel !== '' ? '<p>Control panel: <a href="' . Text::mail($panel) . '">' . Text::mail($panel) . '</a></p>' : '')
            . '<p>If you did not ask for this, contact us straight away and change the password.</p>',
            array($password));

        if (!$sent) {
            return null;
        }

        return Clients::accountEmailMasked($clientId);
    }

    /**
     * The stored control-panel password for a service, in plain text.
     *
     * WHMCS keeps it reversibly encrypted and will only decrypt it through its own API, which is
     * the point: the key never leaves WHMCS and this module never touches the cipher itself. An
     * empty string means there is nothing stored — common for services provisioned by hand, and for
     * anything without a panel.
     */
    /**
     * The password, plus WHERE it came from — the only way a missing password can be diagnosed.
     *
     * `source` is one of service_record, custom_field, none, and on `none` also says which stages
     * were reached: an empty column and a decrypt that failed look identical from the chat, and
     * they are fixed in completely different places.
     *
     * @return array{password: string, source: string}
     */
    public static function servicePasswordWithSource(array $p)
    {
        $id = !empty($p['id']) ? (int) $p['id'] : 0;

        $fromApi = isset($p['password']) ? (string) $p['password'] : '';
        $raw = '';
        if ($id > 0) {
            try {
                $raw = (string) Capsule::table('tblhosting')->where('id', $id)->value('password');
            } catch (\Exception $e) {
                $raw = '';
            }
        }

        /*
         * READ THE PASSWORD — correctly for both ways WHMCS installs store it.
         *
         * Service passwords are either WHMCS ciphertext or PLAIN TEXT (how many provisioning modules,
         * IPTV and streaming panels above all, write them). Every earlier version ran the value
         * through WHMCS's decrypt, and that decrypt does not refuse plain text — it mangles it:
         *   - plain text that base64-decodes to 20 bytes or fewer comes back EMPTY;
         *   - anything longer comes back as BINARY GARBAGE, still marked "success".
         * (WHMCS's legacy cipher: base64 of a 20-byte IV followed by the XOR'd data, no validation.)
         * GetClientsProducts runs the same decrypt on the column, so on a plain-text install its
         * `password` is empty or garbage too.
         *
         * So nothing that comes out of a decrypt is trusted unless it is readable text, and a decrypt
         * is only attempted on a value that could actually be WHMCS ciphertext. Otherwise the stored
         * value IS the password.
         */

        // 1. GetClientsProducts decrypted real ciphertext for us — any WHMCS cipher, old or new.
        if ($fromApi !== '' && $raw !== '' && $fromApi !== $raw && self::isReadableSecret($fromApi)) {
            return array('password' => $fromApi, 'source' => 'service_record');
        }

        $stored = $raw !== '' ? $raw : $fromApi;

        if ($stored !== '') {
            $read = self::readStoredSecret($stored);

            if ($read['kind'] === 'decrypted') {
                // 2. WHMCS ciphertext, decrypted to readable text.
                return array('password' => $read['value'], 'source' => 'service_record_decrypted');
            }

            if ($read['kind'] === 'plain') {
                // 3. Not ciphertext, or ciphertext WHMCS's legacy decrypt turned to garbage (which is
                //    what it does to plain text): it was stored as the password itself.
                return array('password' => $read['value'], 'source' => 'service_record_plaintext');
            }

            // 'empty': WHMCS's encryption of an EMPTY password. Its ciphertext used to be handed out
            // as "the password", because a value no decrypt could read was taken to be plain text.
        }

        // 4. Nothing usable on the service: products provisioned outside WHMCS keep it in a field.
        if ($id > 0) {
            $fromField = self::customFieldValue($id, isset($p['pid']) ? (int) $p['pid'] : 0, '/\b(pass|password|passwd|pwd)\b/');

            if ($fromField !== '') {
                return array('password' => $fromField, 'source' => 'custom_field');
            }
        }

        return array('password' => '', 'source' => 'none (no readable password on the service and no password custom field)');
    }

    /**
     * What a stored secret really holds, by the shape WHMCS's ciphers leave.
     *
     * WHMCS's legacy cipher is base64 of a 20-byte IV followed by the encrypted bytes, so:
     *  - exactly 20 bytes is the ciphertext of an EMPTY value ('empty');
     *  - more than 20 is decrypted, and kept only when the result is readable ('decrypted');
     *    a decrypt that answers with NOTHING means an empty value in a newer cipher ('empty');
     *    a decrypt WHMCS REFUSES (result other than success) gives nothing ('none'): whatever the
     *    value is, it is not something to hand out;
     *    a decrypt that answers "success" with garbage is the legacy cipher mangling plain text,
     *    and the stored value is the password ('plain');
     *  - anything that is not base64 at all is plain text ('plain').
     * A value that is none of these readable things is 'none'.
     *
     * KNOWN LIMIT. The legacy cipher validates nothing, so real ciphertext decrypted with a key
     * WHMCS no longer has (cc_encryption_hash changed, say after a server move) also comes back as
     * "success" with garbage, exactly like plain text does. Those two cannot be told apart from
     * here, and the value is then returned as typed: ciphertext CAN be handed out as the password
     * in that one case. Refusing it would break every install that stores passwords as plain text
     * (the SolarXMedia case), so it stays a documented limit.
     *
     * @return array{kind: string, value: string} kind is decrypted | plain | empty | none
     */
    public static function readStoredSecret($stored)
    {
        $stored = (string) $stored;
        $shape = self::cipherShape($stored);

        if ($shape === 'empty') {
            return array('kind' => 'empty', 'value' => '');
        }

        if ($shape === 'cipher') {
            $r = WhmcsApi::api('DecryptPassword', array('password2' => $stored), false);

            if (($r['result'] ?? '') !== 'success') {
                return array('kind' => 'none', 'value' => '');
            }

            $plain = isset($r['password']) ? (string) $r['password'] : '';

            if ($plain !== '' && self::isReadableSecret($plain)) {
                return array('kind' => 'decrypted', 'value' => $plain);
            }

            if ($plain === '') {
                return array('kind' => 'empty', 'value' => '');
            }
        }

        return self::isReadableSecret($stored)
            ? array('kind' => 'plain', 'value' => $stored)
            : array('kind' => 'none', 'value' => '');
    }

    /**
     * 'empty' when the value is canonical base64 of exactly 20 bytes (the legacy ciphertext of an
     * empty value), 'cipher' when it is canonical base64 of more (it could be ciphertext), 'plain'
     * otherwise. A short plain-text password is recognised here without a decrypt to mangle it.
     */
    public static function cipherShape($value)
    {
        $value = (string) $value;
        $decoded = base64_decode($value, true);

        if ($decoded === false || $decoded === '' || base64_encode($decoded) !== $value) {
            return 'plain';
        }

        $bytes = strlen($decoded);

        return $bytes === 20 ? 'empty' : ($bytes > 20 ? 'cipher' : 'plain');
    }

    /** A password a person could type: valid UTF-8, no control bytes. Garbage from a bad decrypt is not. */
    public static function isReadableSecret($value)
    {
        $value = (string) $value;

        if ($value === '' || preg_match('/[\x00-\x1F\x7F]/', $value)) {
            return false;
        }

        return function_exists('mb_check_encoding') ? mb_check_encoding($value, 'UTF-8') : true;
    }

    /**
     * A credential kept in the product's CUSTOM FIELDS rather than on the service.
     *
     * WHMCS has one username column and one password column, and a great many products use neither.
     * Anything provisioned by a panel WHMCS does not speak — IPTV and streaming lines above all, and
     * VPNs, game servers, licence keys — keeps its login in custom fields on the product, because
     * that is the only place the merchant has to put it.
     *
     * Matched on the FIELD NAME only, against a pattern the caller supplies, so a password lookup
     * never returns a licence key, a portal URL or a MAC address from the neighbouring fields. A
     * field WHMCS stored as a "password" type is read by the same rules as the service record.
     *
     * ONLY FIELDS THE CLIENT COULD SEE, ON THIS SERVICE'S OWN PRODUCT. An admin-only field is the
     * merchant's own note (an upstream panel login, a reseller key) that WHMCS never shows the
     * client; named "Panel Password" and created first, it used to be handed out as the client's
     * password. A field belonging to some other product is never read for this one.
     */
    public static function customFieldValue($serviceId, $productId, $namePattern)
    {
        if ((int) $productId <= 0) {
            return '';
        }

        try {
            $query = Capsule::table('tblcustomfieldsvalues as v')
                ->join('tblcustomfields as f', 'f.id', '=', 'v.fieldid')
                ->where('v.relid', (int) $serviceId)
                ->where('f.type', 'product')
                ->where('f.relid', (int) $productId);

            if (Schema::hasColumn('tblcustomfields', 'adminonly')) {
                $query->where(function ($w) {
                    $w->whereNull('f.adminonly')->orWhere('f.adminonly', '!=', 'on');
                });
            }

            $rows = $query->orderBy('f.id')->get(array('f.fieldname', 'f.fieldtype', 'v.value'));
        } catch (\Exception $e) {
            return '';
        }

        foreach ($rows as $row) {
            // WHMCS stores display options after a pipe: "Password|Your line password".
            $name = mb_strtolower(trim((string) preg_replace('/\|.*$/', '', (string) $row->fieldname)));
            $value = trim((string) $row->value);

            if ($value === '' || !preg_match($namePattern, $name)) {
                continue;
            }

            if ((string) $row->fieldtype === 'password') {
                $read = self::readStoredSecret($value);
                if ($read['kind'] === 'empty' || $read['kind'] === 'none') {
                    continue; // encrypted but empty, or nothing readable: try the next field
                }

                return $read['value'];
            }

            return self::isReadableSecret($value) ? $value : '';
        }

        return '';
    }

    /** A password strong enough for a hosting account, from a source that is actually random. */
    public static function strongPassword()
    {
        $alphabet = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789!@#%^*-_=+';
        $max = strlen($alphabet) - 1;
        $out = '';

        for ($i = 0; $i < 20; $i++) {
            $out .= $alphabet[random_int(0, $max)];
        }

        return $out;
    }
}
