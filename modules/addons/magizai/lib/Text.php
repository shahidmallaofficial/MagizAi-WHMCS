<?php

namespace MagizAI\Whmcs;

/**
 * Escaping and wording rules shared by the admin page, the widget, the emails and the bridge.
 *
 * ONE ESCAPER, SO EVERY PHP VERSION ESCAPES THE SAME WAY. htmlspecialchars() changed its default
 * flags in PHP 8.1. Before that, a value holding one invalid UTF-8 byte came back as an EMPTY
 * string, and with ENT_QUOTES but no ENT_SUBSTITUTE it still does on every version: a client name
 * or a transfer code could arrive in an email, or on the admin page, as nothing at all.
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class Text
{
    /** Escape for HTML. Invalid UTF-8 is replaced, never dropped. */
    public static function html($value)
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Escape a value for the BODY of an email sent with SendEmail.
     *
     * WHMCS 9 runs custom messages through Smarty 4 (WHMCS-23628), and htmlspecialchars() leaves
     * { and } alone. A stored password such as "Pa{ss}99" was read as a template tag: the email
     * failed, or went out mangled, while the chat said it had been sent. Braces become entities,
     * which every mail client shows as braces and Smarty never sees. WHMCS 8 shows them the same.
     */
    public static function mail($value)
    {
        return str_replace(array('{', '}'), array('&#123;', '&#125;'), self::html($value));
    }

    /**
     * A plain-text email SUBJECT. Not HTML, so not HTML-escaped (an entity would be shown as
     * typed); one line; braces swapped for brackets so Smarty has nothing to parse.
     */
    public static function subject($value)
    {
        $value = preg_replace('/[\x00-\x1F\x7F]+/', ' ', (string) $value);
        $value = str_replace(array('{', '}'), array('(', ')'), (string) $value);

        return self::cut($value, 190);
    }

    /** Collapse whitespace and cut to $max characters, marking the cut. */
    public static function cut($text, $max)
    {
        $text = trim((string) preg_replace('/\s+/', ' ', (string) $text));

        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            return mb_strlen($text) > $max ? rtrim(mb_substr($text, 0, $max - 1)) . '…' : $text;
        }

        return strlen($text) > $max ? rtrim(substr($text, 0, $max - 3)) . '...' : $text;
    }

    /**
     * Words and shapes that mark an error text as being about the host's own systems rather than
     * about the client's request: addresses, paths, credentials, reseller accounts and their
     * balance, allow-lists, transport failures, code. A client is told what happened to THEM; these
     * are for staff.
     *
     * A registrar's "Insufficient funds in your account" is about the HOST's prepaid reseller
     * balance. Repeated to a client, it reads as being about their own account.
     */
    const INTERNAL_DETAIL = '~(\b\d{1,3}(?:\.\d{1,3}){3}\b|\b(?:[0-9a-f]{1,4}:){3,7}[0-9a-f]{1,4}\b|::|\b(?:https?|ftp)://|(?:^|[\s(\'"])/(?:home|var|usr|etc|opt|srv|tmp|root|www)/|\b[a-z]:\\\\|\b(?:api|apikey|api_key|token|secret|credentials?|passw(?:or)?d|auth\w*|unauthori[sz]ed|forbidden|access denied|permission denied|white-?list\w*|allow-?list\w*|reseller\w*|login failed|curl\w*|ssl|tls|certificate|timed out|timeout|connection|socket|sql\w*|database|query|exception|stack trace|fatal|php|syntax error|internal server error|gateway|insufficient\s+(?:funds|balance|credit)|funds|balance|credit\s+limit|pre-?paid|top.?up)\b)~i';

    /**
     * A registrar's or a server module's own error text, fit to repeat to a client — or replaced.
     *
     * Clear refusals ("Domain is locked", "Invalid nameserver") are the useful answer and are kept,
     * tags stripped and length capped, the same way WHMCS's own client area shows them. Anything
     * that names the host's own systems is swapped for $fallback. The raw text is never lost: the
     * caller records it in the module's activity log, which only staff can read.
     */
    public static function customerSafe($raw, $fallback)
    {
        $text = html_entity_decode(strip_tags((string) $raw), ENT_QUOTES, 'UTF-8');
        $text = trim((string) preg_replace('/\s+/', ' ', $text));

        if ($text === '' || preg_match(self::INTERNAL_DETAIL, $text)) {
            return (string) $fallback;
        }

        return self::cut($text, 200);
    }

    /**
     * Replace each secret VALUE in a text with asterisks, in every form it can take there (see
     * secretForms). For logs and audit details, which staff read: a password or a transfer code has
     * no business sitting in either.
     *
     * @param string[] $secrets
     */
    public static function mask($text, array $secrets)
    {
        return self::replaceForms((string) $text, self::secretForms($secrets));
    }

    /**
     * mask() through every string in a value, at any depth: a request or a response as it is about
     * to be handed to WHMCS's module log. Anything that is not a string is kept as it is.
     *
     * @param mixed    $value
     * @param string[] $secrets
     * @return mixed
     */
    public static function maskDeep($value, array $secrets)
    {
        return self::maskWith($value, self::secretForms($secrets));
    }

    /**
     * Every form a secret can take in what this module writes, ready to mask (longest first).
     *
     * WHMCS masks its module log by EXACT value, and an email body carries a password escaped
     * (Text::mail): "Pa&ss{1}" sits there as "Pa&amp;ss&#123;1&#125;". Listing only the raw value
     * left that copy readable. So each secret is listed as given, entity-decoded (a transfer code can
     * come back from WHMCS's API already HTML-encoded), HTML-escaped the way pages and emails escape
     * it, brace-escaped the way email bodies carry it, and escaped with PHP's HTML5 rules too.
     *
     * @param array $secrets
     * @return string[]
     */
    public static function secretForms(array $secrets)
    {
        $forms = array();

        foreach ($secrets as $secret) {
            if (!is_scalar($secret) || (string) $secret === '') {
                continue;
            }

            $raw = (string) $secret;
            foreach (array($raw, html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8')) as $value) {
                $forms[] = $value;
                $forms[] = self::html($value);
                $forms[] = self::mail($value);
                $forms[] = htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
            }
        }

        return self::maskable($forms);
    }

    /**
     * Is any of these secrets too short to be masked (see maskable)? A text that carries one
     * cannot be made safe by masking, so it is left out of a log altogether.
     */
    public static function hasUnmaskable(array $secrets)
    {
        foreach ($secrets as $secret) {
            if (is_scalar($secret) && (string) $secret !== '' && strlen((string) $secret) < 4) {
                return true;
            }
        }

        return false;
    }

    /**
     * The secrets worth masking: non-empty strings of four characters or more, longest first so a
     * secret that contains another is replaced whole.
     *
     * @param array $secrets
     * @return string[]
     */
    public static function maskable(array $secrets)
    {
        $out = array();
        foreach ($secrets as $secret) {
            if (is_scalar($secret) && strlen((string) $secret) >= 4) {
                $out[] = (string) $secret;
            }
        }

        $out = array_values(array_unique($out));
        usort($out, function ($a, $b) {
            return strlen($b) - strlen($a);
        });

        return $out;
    }

    /** @param string[] $forms already expanded and ordered by secretForms() */
    private static function replaceForms($text, array $forms)
    {
        foreach ($forms as $form) {
            $text = str_replace($form, '********', $text);
        }

        return $text;
    }

    /**
     * @param mixed    $value
     * @param string[] $forms already expanded and ordered by secretForms()
     * @return mixed
     */
    private static function maskWith($value, array $forms)
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = self::maskWith($item, $forms);
            }

            return $value;
        }

        return is_string($value) ? self::replaceForms($value, $forms) : $value;
    }
}
