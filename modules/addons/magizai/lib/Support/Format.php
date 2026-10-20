<?php

namespace MagizAI\Whmcs\Support;

use MagizAI\Whmcs\Settings;

/**
 * Text from WHMCS made fit for an answer: HTML as plain text, cut to a length, a knowledgebase
 * link, and WHMCS's tick boxes read as yes or no.
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class Format
{
    /** A WHMCS tick box as stored: 1, "1" and "on" are ticked; 0, "", "0" and anything else are not. */
    public static function flagOn($value)
    {
        if (is_bool($value)) {
            return $value;
        }

        $value = strtolower(trim((string) $value));

        return $value === '1' || $value === 'on' || $value === 'yes' || $value === 'true';
    }

    public static function kbUrl($id, $title)
    {
        $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower((string) $title)), '-');

        return Settings::systemUrl() . 'index.php?rp=/knowledgebase/' . (int) $id . '/' . ($slug !== '' ? $slug : 'article') . '.html';
    }

    public static function plain($html)
    {
        $text = html_entity_decode(strip_tags(preg_replace('#<(br|/p|/li|/h[1-6])[^>]*>#i', "\n", (string) $html)), ENT_QUOTES, 'UTF-8');

        return trim(preg_replace("/[ \t]+/", ' ', preg_replace("/\n{3,}/", "\n\n", $text)));
    }

    public static function cut($text, $max)
    {
        $text = trim(preg_replace('/\s+/', ' ', (string) $text));

        return mb_strlen($text) > $max ? rtrim(mb_substr($text, 0, $max - 1)) . '…' : $text;
    }
}
