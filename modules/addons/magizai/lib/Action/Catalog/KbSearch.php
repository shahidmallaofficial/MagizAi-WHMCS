<?php

namespace MagizAI\Whmcs\Action\Catalog;

use MagizAI\Whmcs\ActionError;
use MagizAI\Whmcs\Action\ActionContext;
use MagizAI\Whmcs\Action\ActionInterface;
use MagizAI\Whmcs\Domain\Knowledgebase;
use MagizAI\Whmcs\Support\Args;
use MagizAI\Whmcs\Support\Format;

/**
 * Searches the host's public knowledgebase for the visitor's question: the three best articles,
 * each with its link and an excerpt.
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class KbSearch implements ActionInterface
{
    public function spec()
    {
        return array('identity' => false, 'write' => false, 'group' => 'public', 'label' => 'Search the knowledgebase');
    }

    public function handle(ActionContext $context)
    {
        $args = $context->args();

        $query = Args::text($args, 'query', 120);
        $words = Knowledgebase::kbWords($query);

        if (!$words) {
            throw new ActionError('invalid_args', 'Ask the visitor what they need help with, in a few words.');
        }

        $q = Knowledgebase::publicArticles();

        $likes = array();
        foreach ($words as $word) {
            $likes[] = '%' . str_replace(array('%', '_'), array('\\%', '\\_'), $word) . '%';
        }

        $q->where(function ($w) use ($likes) {
            foreach ($likes as $like) {
                $w->orWhere('kb.title', 'like', $like)->orWhere('kb.article', 'like', $like);
            }
        });

        /*
         * THE BEST CANDIDATES FIRST, so the window holds them. This used to take the first 40 matches
         * in whatever order the table returned them (in practice the oldest) and score only those:
         * in a knowledgebase with more than 40 articles mentioning a word, the newest guides were
         * never even looked at. A title hit counts five times a body hit; among equals, newest first.
         */
        $score = array();
        $bindings = array();
        foreach ($likes as $like) {
            $score[] = '(CASE WHEN kb.title LIKE ? THEN 5 ELSE 0 END)';
            $score[] = '(CASE WHEN kb.article LIKE ? THEN 1 ELSE 0 END)';
            $bindings[] = $like;
            $bindings[] = $like;
        }

        $rows = $q->orderByRaw(implode(' + ', $score) . ' DESC', $bindings)->orderBy('kb.id', 'desc')
            ->limit(Knowledgebase::KB_CANDIDATES)->get(array('kb.id', 'kb.title', 'kb.article'));

        $scored = array();
        foreach ($rows as $row) {
            $title = mb_strtolower((string) $row->title);
            $body = mb_strtolower(Format::plain($row->article));
            $score = 0;
            foreach ($words as $word) {
                // A word that STARTS a word counts fully ("install" in "installing"); one found only
                // inside another word ("how" in "show") counts for little.
                $start = '/(?<![\p{L}\p{N}])' . preg_quote($word, '/') . '/u';
                $score += preg_match($start, $title) ? 5 : (mb_strpos($title, $word) !== false ? 1 : 0);
                $hits = (int) preg_match_all($start, $body);
                $score += $hits > 0 ? min(3, $hits) : (mb_strpos($body, $word) !== false ? 1 : 0);
            }
            $scored[] = array($score, $row);
        }
        usort($scored, function ($a, $b) {
            return $b[0] !== $a[0] ? $b[0] - $a[0] : (int) $b[1]->id - (int) $a[1]->id;
        });

        $lines = array();
        $data = array();
        foreach (array_slice($scored, 0, 3) as $pair) {
            $row = $pair[1];
            $url = Format::kbUrl($row->id, $row->title);
            $excerpt = Format::cut(Format::plain($row->article), 450);
            $lines[] = '"' . $row->title . '" (' . $url . "): " . $excerpt;
            $data[] = array('title' => $row->title, 'url' => $url);
        }

        if (!$lines) {
            return array('summary' => 'No help article matches "' . $query . '".', 'data' => array('articles' => array()));
        }

        return array('summary' => implode("\n", $lines), 'data' => array('articles' => $data));
    }
}
