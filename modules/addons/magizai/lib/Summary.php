<?php

namespace MagizAI\Whmcs;

/**
 * How long one answer may be, and how a list answer is cut to fit.
 *
 * MagizAI hands a summary to the model whole up to 1,500 characters and cuts anything longer at that
 * length, wherever it falls. A long account (eighteen services, thirty invoices) lost its totals and
 * its last sections that way, mid-line and with no mark, and the model then answered as if they did
 * not exist. So a list answer leads with its counts, keeps whole lines only, stays below 1,400
 * characters, and when something had to be left out it ends with "... and N more, ask about a
 * specific one".
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class Summary
{
    /** A list answer stays below this many characters, so MagizAI never has to cut it. */
    const BUDGET = 1400;

    /**
     * The last line when lines that are always shown had to go too, and what was left out can no
     * longer be counted as one kind of thing. @see fit
     */
    const MORE_UNCOUNTED = '... and more, ask about a specific one.';

    /**
     * The lines that fit: $before, then each section's head and as many of its items as fit, then
     * $after. A section that could not show all of its items ends with one line saying how many were
     * left out, counting `more` too (items there are, but that were never read).
     *
     * Sections are filled in the order given, so the first one is the most important. Room for the
     * "left out" line of every later section is kept back while an earlier one fills, so a long first
     * section can never leave a later one with no line at all.
     *
     * WHEN THE ALWAYS-SHOWN LINES TAKE MOST OF THE ROOM there is no space for a "left out" line under
     * every section. Each such line used to be added anyway: five orders, each a long head over one
     * line item, came out as five "... and 1 more" lines, and the answer then broke the budget and was
     * cut in the middle of a line. Now the sections share ONE "left out" line at the end, counting
     * everything they left out. And should the always-shown lines alone break the budget, the answer
     * is cut after a whole line, never inside one, and says that more was left out.
     *
     * @param string[] $before   always shown, first: the counts
     * @param array<int, array{head?: string, items?: string[], more?: int}> $sections
     * @param string[] $after    always shown, last
     * @param int      $budget   the whole answer stays below this many characters
     * @return string
     */
    public static function fit(array $before, array $sections, array $after = array(), $budget = self::BUDGET)
    {
        $budget = (int) $budget;
        $reserve = self::length(self::more(99999)) + 1;

        $before = self::lines($before);
        $after = self::lines($after);

        $list = array();
        foreach (array_values($sections) as $section) {
            $head = isset($section['head']) && is_scalar($section['head']) ? self::lines(array($section['head'])) : array();
            $list[] = array(
                'head' => $head ? $head[0] : '',
                'items' => isset($section['items']) && is_array($section['items']) ? self::lines($section['items']) : array(),
                'more' => isset($section['more']) ? max(0, (int) $section['more']) : 0,
            );
        }

        // Every line that is always shown, each with its newline.
        $used = 0;
        foreach (array_merge($before, $after) as $line) {
            $used += self::length($line) + 1;
        }

        // Sections that may need a "left out" line: only those with something to list or count.
        $mayCut = 0;
        foreach ($list as $section) {
            if ($section['head'] !== '') {
                $used += self::length($section['head']) + 1;
            }
            if ($section['items'] || $section['more'] > 0) {
                $mayCut++;
            }
        }

        // The always-shown lines alone leave no room for even one "left out" line: no item is shown,
        // so the answer must say that something was left out, whatever else still fits.
        if ($mayCut > 0 && $used + $reserve > $budget) {
            return self::wholeLines(self::alwaysShown($before, $list, $after), $budget, true);
        }

        $text = $used + $mayCut * $reserve > $budget
            ? self::sharedMarker($before, $list, $after, $used, $reserve, $budget)
            : self::markerPerSection($before, $list, $after, $used, $reserve, $budget, $mayCut);

        // Reached only when the always-shown lines alone break the budget and there is nothing to
        // list: the budget still holds, cut after a whole line.
        return self::length($text) < $budget ? $text : self::wholeLines(explode("\n", $text), $budget, true);
    }

    /** The usual case: each section that left something out says how much, under its own items. */
    private static function markerPerSection(array $out, array $list, array $after, $used, $reserve, $budget, $mayCut)
    {
        $remaining = $mayCut;

        foreach ($list as $section) {
            if ($section['head'] !== '') {
                $out[] = $section['head'];
            }
            if (!$section['items'] && $section['more'] === 0) {
                continue;
            }

            // Room kept for the "left out" line of each section still to come that may need one.
            $remaining--;
            $later = $remaining * $reserve;
            $shown = 0;

            foreach ($section['items'] as $j => $item) {
                $leftAfter = count($section['items']) - $j - 1 + $section['more'];
                $need = self::length($item) + 1 + ($leftAfter > 0 ? $reserve : 0);
                if ($used + $need + $later > $budget) {
                    break;
                }
                $out[] = $item;
                $used += self::length($item) + 1;
                $shown++;
            }

            $left = count($section['items']) - $shown + $section['more'];
            if ($left > 0) {
                $line = self::more($left);
                $out[] = $line;
                $used += self::length($line) + 1;
            }
        }

        return implode("\n", array_merge($out, $after));
    }

    /**
     * Too little room for a "left out" line under every section: items are shown in order while they
     * fit, keeping room for one line, and everything left out is counted on that one line after the
     * last section. Once one item does not fit, none after it is shown, so what is listed is always
     * the first part of each list and never a scattering.
     */
    private static function sharedMarker(array $out, array $list, array $after, $used, $reserve, $budget)
    {
        // How much each section, and everything after it, holds.
        $tail = array();
        $sum = 0;
        for ($i = count($list) - 1; $i >= 0; $i--) {
            $tail[$i] = $sum;
            $sum += count($list[$i]['items']) + $list[$i]['more'];
        }

        $left = 0;
        $full = false;

        foreach ($list as $i => $section) {
            if ($section['head'] !== '') {
                $out[] = $section['head'];
            }

            foreach ($section['items'] as $j => $item) {
                $behind = count($section['items']) - $j - 1 + $section['more'] + $tail[$i] + $left;
                $need = self::length($item) + 1 + ($behind > 0 ? $reserve : 0);
                if ($full || $used + $need > $budget) {
                    $full = true;
                    $left++;
                    continue;
                }
                $out[] = $item;
                $used += self::length($item) + 1;
            }

            $left += $section['more'];
        }

        if ($left > 0) {
            $out[] = self::more($left);
        }

        return implode("\n", array_merge($out, $after));
    }

    /** Every line that is always shown, in order: $before, each section's head, $after. */
    private static function alwaysShown(array $before, array $list, array $after)
    {
        $out = $before;
        foreach ($list as $section) {
            if ($section['head'] !== '') {
                $out[] = $section['head'];
            }
        }

        return array_merge($out, $after);
    }

    /**
     * As many whole lines from the top as fit with MORE_UNCOUNTED after them. Only a first line too
     * long to fit on its own is cut inside, as nothing else could be said at all.
     *
     * @param string[] $lines
     * @param bool     $leftOut something was left out already, so the marker is said even when
     *                          every one of these lines fits
     */
    private static function wholeLines(array $lines, $budget, $leftOut)
    {
        $marker = self::MORE_UNCOUNTED;
        $room = $budget - self::length($marker) - 1;

        $kept = array();
        $used = 0;
        foreach ($lines as $line) {
            $length = self::length($line) + 1;
            if ($used + $length > $room) {
                break;
            }
            $kept[] = $line;
            $used += $length;
        }

        if (!$leftOut && count($kept) === count($lines)) {
            return implode("\n", $kept);
        }

        if (!$kept && $lines) {
            $first = (string) reset($lines);

            return function_exists('mb_substr')
                ? rtrim(mb_substr($first, 0, $room - 2, 'UTF-8')) . "…\n" . $marker
                : rtrim(substr($first, 0, $room - 4)) . "...\n" . $marker;
        }

        $kept[] = $marker;

        return implode("\n", $kept);
    }

    /** The last line of a list that could not show everything. */
    public static function more($count)
    {
        return '... and ' . (int) $count . ' more, ask about a specific one.';
    }

    /** "1 service", "3 services", "0 services". */
    public static function count($n, $one, $many = null)
    {
        $n = (int) $n;

        return $n . ' ' . ($n === 1 ? $one : ($many !== null ? $many : $one . 's'));
    }

    /** Characters, not bytes: a summary is cut by MagizAI at a number of characters. */
    public static function length($text)
    {
        return function_exists('mb_strlen') ? mb_strlen((string) $text, 'UTF-8') : strlen((string) $text);
    }

    /**
     * Strings only, each on one line: a newline inside an item would make one "line" two, and the
     * count of what was left out would be wrong.
     *
     * @param array $lines
     * @return string[]
     */
    private static function lines(array $lines)
    {
        $out = array();
        foreach ($lines as $line) {
            if (is_scalar($line) && (string) $line !== '') {
                $out[] = (string) preg_replace('/[\r\n]+/', ' ', (string) $line);
            }
        }

        return $out;
    }
}
