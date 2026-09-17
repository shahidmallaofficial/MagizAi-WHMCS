<?php

namespace MagizAI\Whmcs;

/**
 * A refusal whose message is written for the client and is safe to repeat in chat.
 *
 * The code must be one MagizAI recognises as customer-safe (WhmcsConnector::CUSTOMER_SAFE_ERRORS);
 * anything else is replaced with a generic apology on the MagizAI side.
 */
class ActionError extends \Exception
{
    private $errorCode;

    public function __construct($code, $message)
    {
        parent::__construct($message);
        $this->errorCode = $code;
    }

    public function code()
    {
        return $this->errorCode;
    }
}
