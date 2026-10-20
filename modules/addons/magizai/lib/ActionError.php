<?php

namespace MagizAI\Whmcs;

/**
 * A refusal whose message is written for the client and is safe to repeat in chat.
 *
 * The code must be one MagizAI recognises as customer-safe (WhmcsConnector::CUSTOMER_SAFE_ERRORS);
 * anything else is replaced with a generic apology on the MagizAI side.
 *
 * The optional detail is for staff only: it goes to the module's activity log instead of the
 * message, so a registrar's or server's own words are kept where staff can read them without ever
 * being repeated to the client. It must never hold a secret.
 */
class ActionError extends \Exception
{
    private $errorCode;

    private $detail;

    public function __construct($code, $message, $detail = null)
    {
        parent::__construct($message);
        $this->errorCode = $code;
        $this->detail = $detail === null ? null : (string) $detail;
    }

    public function code()
    {
        return $this->errorCode;
    }

    /** @return string|null what staff should see in the activity log, when it differs from the message */
    public function detail()
    {
        return $this->detail;
    }
}
