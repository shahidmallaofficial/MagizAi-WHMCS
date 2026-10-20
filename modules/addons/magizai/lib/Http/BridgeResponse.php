<?php

namespace MagizAI\Whmcs\Http;

use MagizAI\Whmcs\Crypto;

/**
 * One answer of the bridge, built without sending anything, and sent exactly as built.
 *
 * Every reply to an authenticated request is signed and bound to the request nonce, so MagizAI can
 * tell a real answer from a cached page, a proxy error or a replayed response. Only a request that
 * never proved it holds the secret gets an unsigned one.
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class BridgeResponse
{
    /** @var int */
    private $status;

    /** @var string[] header lines */
    private $headers;

    /** @var string */
    private $body;

    public function __construct($status, array $headers, $body)
    {
        $this->status = (int) $status;
        $this->headers = $headers;
        $this->body = (string) $body;
    }

    /** Unsigned reply — only for requests that never proved they hold the secret. */
    public static function unsigned($status, $error)
    {
        return new self($status, array('Content-Type: application/json'), json_encode(array('ok' => false, 'error' => $error)));
    }

    /** Signed reply, bound to the request nonce. */
    public static function signed($secret, $nonce, array $payload, $status = 200)
    {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
        $ts = (string) time();

        return new self($status, array(
            'Content-Type: application/json',
            'X-MagizAI-Timestamp: ' . $ts,
            'X-MagizAI-Signature: ' . Crypto::sign($secret, $ts, $nonce, $body),
        ), $body);
    }

    /**
     * EXACTLY what crosses the wire back to MagizAI after a successful action.
     *
     * It is decided here, apart from the bridge's own last line, because that is where the one bug
     * that mattered most hid: the reply was built from ok/summary/data only, so an action's `secret`
     * (a service password, for the client's eyes alone) was dropped on its last line inside WHMCS,
     * and the chat's "Show my login details" button could never appear. Every test passed, because
     * every test stopped short of that line. @see \MagizAI\Whmcs\Actions::replyFor
     *
     * `secret` travels signed with the rest of the reply; MagizAI swaps it for a single-use token
     * before anything is stored or shown to the model. It is never written to the audit log.
     *
     * @param array $result what an action returned
     * @return array
     */
    public static function replyFor(array $result)
    {
        $reply = array(
            'ok' => true,
            'summary' => isset($result['summary']) ? (string) $result['summary'] : '',
            'data' => isset($result['data']) && is_array($result['data']) ? $result['data'] : array(),
        );

        if (isset($result['secret']) && is_array($result['secret']) && $result['secret'] !== array()) {
            $reply['secret'] = $result['secret'];
        }

        return $reply;
    }

    /**
     * Send exactly the body, after throwing away anything printed before it, and make sure nothing
     * printed after it (shutdown functions) is sent too. Ending the request is bridge.php's job.
     */
    public function send(OutputGuard $guard)
    {
        $guard->drain();
        http_response_code($this->status);
        foreach ($this->headers as $header) {
            header($header);
        }
        $guard->drain();
        echo $this->body;
        ob_start(function () {
            return '';
        });
    }

    public function status()
    {
        return $this->status;
    }

    /** @return string[] */
    public function headers()
    {
        return $this->headers;
    }

    public function body()
    {
        return $this->body;
    }
}
