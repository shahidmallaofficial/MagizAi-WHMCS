<?php

namespace MagizAI\Whmcs;

/**
 * One-code setup: the code an owner creates in MagizAI (Connectors, WHMCS) is traded here for every
 * setting the module needs, so nobody copies keys between the two systems by hand.
 *
 * What goes out: the code, this install's System URL and its bridge secret (made first if there is
 * none), and the module version. What comes back: the MagizAI address, the widget key, the identity
 * secret and an API token for the knowledge sync, and whether MagizAI could reach the bridge. MagizAI
 * answers only once the code checks out; a used or expired code changes nothing here.
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class Pairing
{
    const CODE = '/^mzp_[A-Za-z0-9_-]{43}$/';

    /**
     * @return array{workspace: string, chatbot: string, bridge_ok: bool, bridge_message: string}
     * @throws \RuntimeException with the message the admin page shows
     */
    public static function connect($code, $magizaiUrl, $version)
    {
        $code = trim((string) $code);
        if (!preg_match(self::CODE, $code)) {
            throw new \RuntimeException('That code does not look right. Copy the whole code from MagizAI, Connectors, WHMCS (it starts with mzp_).');
        }
        $base = rtrim(trim((string) $magizaiUrl), '/');
        if ($base === '') {
            $base = Settings::magizaiBase();
        }
        if (!preg_match('#^https://[a-z0-9.-]+(:\d+)?$#i', $base)) {
            throw new \RuntimeException('The MagizAI address must be an https address with no path, e.g. https://magiz.ai');
        }
        $system = rtrim(Settings::systemUrl(), '/');
        if (stripos($system, 'https://') !== 0) {
            throw new \RuntimeException('Your WHMCS System URL must start with https:// (WHMCS, General Settings, System URL). MagizAI only talks to WHMCS over https.');
        }

        // The bridge secret goes with the code, so make one now if this install has none yet.
        $secret = (string) Settings::get('bridge_secret');
        if ($secret === '') {
            $secret = Crypto::newBridgeSecret();
            Settings::set('bridge_secret', $secret);
        }

        $reply = self::post($base . '/api/integrations/whmcs/pair', array(
            'code' => $code,
            'whmcs_url' => $system,
            'bridge_secret' => $secret,
            'module_version' => (string) $version,
        ));

        foreach (array('widget_key', 'identity_secret', 'api_token') as $field) {
            if (!isset($reply[$field]) || !is_string($reply[$field]) || $reply[$field] === '') {
                throw new \RuntimeException('MagizAI answered without the ' . str_replace('_', ' ', $field) . '. Try again with a new code.');
            }
        }
        if (!preg_match('/^[A-Za-z0-9_-]{4,80}$/', $reply['widget_key']) || strlen($reply['identity_secret']) < 32) {
            throw new \RuntimeException('MagizAI answered with settings this module cannot use. Update the module and try again.');
        }

        Settings::set('magizai_url', $base);
        Settings::set('widget_key', $reply['widget_key']);
        Settings::set('identity_secret', $reply['identity_secret']);
        Settings::set('api_token', $reply['api_token']);

        return array(
            'workspace' => isset($reply['workspace']) ? (string) $reply['workspace'] : '',
            'chatbot' => isset($reply['chatbot']) ? (string) $reply['chatbot'] : '',
            'bridge_ok' => !empty($reply['bridge_ok']),
            'bridge_message' => isset($reply['bridge_message']) ? (string) $reply['bridge_message'] : '',
        );
    }

    /**
     * @return array the decoded answer of a successful exchange
     * @throws \RuntimeException
     */
    private static function post($url, array $payload)
    {
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('This server has no cURL extension for PHP, which the module needs to reach MagizAI.');
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTPHEADER => array('Content-Type: application/json', 'Accept: application/json', 'User-Agent: MagizAI-WHMCS/1.0'),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => false,
        ));
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        if (PHP_VERSION_ID < 80000) {
            curl_close($ch);
        }
        if ($body === false) {
            throw new \RuntimeException('This server could not reach MagizAI (' . $error . '). Check that it can make outgoing https requests.');
        }
        $data = json_decode((string) $body, true);
        if ($status === 200 && is_array($data) && !empty($data['ok'])) {
            return $data;
        }
        // MagizAI's own sentences (wrong or expired code, http address, too many tries) are shown as they are.
        if (is_array($data) && isset($data['message']) && is_string($data['message'])) {
            throw new \RuntimeException(Text::cut(strip_tags($data["message"]), 300));
        }
        if ($status === 429) {
            throw new \RuntimeException('Too many tries. Wait a minute, then try again.');
        }
        throw new \RuntimeException('MagizAI did not accept the code (HTTP ' . $status . '). Create a new code and try again.');
    }
}
