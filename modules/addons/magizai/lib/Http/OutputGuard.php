<?php

namespace MagizAI\Whmcs\Http;

/**
 * NOTHING BUT THE SIGNED REPLY MAY LEAVE THE BRIDGE.
 *
 * The signature covers the reply body and nothing else, and MagizAI checks it against every byte it
 * receives. A PHP notice printed by any code on the way (with "Display Errors" switched on while
 * debugging), or whitespace after a closing tag in another module's hook file, used to land in front
 * of the JSON: every reply then failed verification and MagizAI marked the connection broken. So
 * bridge.php buffers all output from its first line, and this throws away everything buffered above
 * that level before a reply is sent. What was thrown away is counted in the activity log, so the
 * cause can still be found.
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class OutputGuard
{
    /** @var int the output buffer level bridge.php started at, before its own buffer */
    private $base;

    /** @var int bytes of stray output thrown away so far */
    private $stray = 0;

    /** @var int bytes still held in buffers other code opened, which could not be thrown away */
    private $held = 0;

    public function __construct($base)
    {
        $this->base = (int) $base;
    }

    /**
     * Throw away everything buffered above the level the bridge started at; return how many bytes.
     *
     * A BUFFER THAT CANNOT BE REMOVED STOPS THE DRAIN, IT DOES NOT HANG IT. WHMCS or another module may
     * open an output buffer without the "removable" flag. ob_get_clean() cannot pop one: it fails, the
     * level stays where it was, and a loop waiting for the level to drop spun until PHP's time limit
     * killed the request. Such a buffer is emptied when it allows that, and the drain stops there,
     * because nothing below it can be reached. Whatever is still held above the bridge's level goes
     * out ahead of the reply, so it is counted for the activity log.
     */
    public function drain()
    {
        $bytes = 0;
        while (($level = ob_get_level()) > $this->base) {
            $status = ob_get_status();
            $flags = is_array($status) && isset($status['flags']) ? (int) $status['flags'] : 0;
            $cleanable = ($flags & PHP_OUTPUT_HANDLER_CLEANABLE) !== 0;

            if ($cleanable && ($flags & PHP_OUTPUT_HANDLER_REMOVABLE) !== 0) {
                $bytes += strlen((string) ob_get_clean());
                if (ob_get_level() < $level) {
                    continue;
                }
                break; // it said it could be removed, and was not: never loop on it
            }

            if ($cleanable) {
                $length = (int) ob_get_length();
                if ($length > 0 && ob_clean()) {
                    $bytes += $length;
                }
            }

            break;
        }

        $this->stray += $bytes;

        // What is still held above the bridge's level, in buffers it could not empty.
        $this->held = 0;
        foreach (ob_get_status(true) as $index => $buffer) {
            if ($index >= $this->base && is_array($buffer) && isset($buffer['buffer_used'])) {
                $this->held += (int) $buffer['buffer_used'];
            }
        }

        return $bytes;
    }

    /**
     * The audit detail, with a note of any stray output thrown away so far, and of any that could not
     * be thrown away. Buffering carries on afterwards, so anything printed later is caught too.
     */
    public function detail($detail)
    {
        $this->drain();
        ob_start();

        $notes = array();
        if ($this->stray > 0) {
            $notes[] = '[discarded ' . $this->stray . ' bytes of stray output]';
        }
        if ($this->held > 0) {
            $notes[] = '[could not discard ' . $this->held . ' bytes held in an output buffer opened by other code; they are sent ahead of the reply and break its signature]';
        }

        if (!$notes) {
            return $detail;
        }

        $note = implode(' ', $notes);

        return $detail === null || $detail === '' ? $note : $detail . ' ' . $note;
    }

    /** Bytes held in buffers other code opened, as last measured: they go out ahead of the reply. */
    public function held()
    {
        return $this->held;
    }
}
