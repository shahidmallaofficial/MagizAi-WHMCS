<?php

namespace MagizAI\Whmcs\Http;

/**
 * One request to the bridge, as PHP received it: the method, the signing headers and the body.
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class BridgeRequest
{
    /** @var array the server variables the request came with */
    private $server;

    public function __construct(array $server)
    {
        $this->server = $server;
    }

    public static function fromGlobals()
    {
        return new self(isset($_SERVER) && is_array($_SERVER) ? $_SERVER : array());
    }

    public function method()
    {
        return isset($this->server['REQUEST_METHOD']) ? $this->server['REQUEST_METHOD'] : '';
    }

    /**
     * The body, read only when it is needed, never more than one byte past $max: a body longer
     * than that is refused whatever the rest of it says.
     *
     * @return string|false
     */
    public function body($max)
    {
        return file_get_contents('php://input', false, null, 0, (int) $max + 1);
    }

    public function timestamp()
    {
        return $this->header('HTTP_X_MAGIZAI_TIMESTAMP');
    }

    public function nonce()
    {
        return $this->header('HTTP_X_MAGIZAI_NONCE');
    }

    public function signature()
    {
        return $this->header('HTTP_X_MAGIZAI_SIGNATURE');
    }

    private function header($name)
    {
        return isset($this->server[$name]) ? (string) $this->server[$name] : '';
    }
}
