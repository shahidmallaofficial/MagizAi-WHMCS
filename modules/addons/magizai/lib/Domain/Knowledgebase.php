<?php

namespace MagizAI\Whmcs\Domain;

use MagizAI\Whmcs\Action\ActionContext;
use MagizAI\Whmcs\Action\Catalog\KbSearch;
use MagizAI\Whmcs\Schema;
use MagizAI\Whmcs\Support\Format;
use WHMCS\Database\Capsule;

/**
 * The host's knowledgebase as the chat may use it: public articles only, the words of a question
 * worth searching for, and the setup guides offered after a login.
 *
 * PHP 7.4 compatible on purpose: this file ships inside merchants' WHMCS installs.
 */
class Knowledgebase
{
    /**
     * Words too common to say which article is meant, in the languages chats arrive in. Kept, they
     * matched nearly every article ("can you help with my invoice" searched for can, you, help and
     * with), so the handful of rows that were scored was effectively chosen at random.
     */
    const KB_STOPWORDS = array(
        'the', 'and', 'for', 'are', 'but', 'not', 'you', 'your', 'yours', 'with', 'from', 'this', 'that',
        'these', 'those', 'have', 'has', 'had', 'how', 'what', 'when', 'where', 'which', 'who', 'why',
        'can', 'could', 'would', 'should', 'will', 'does', 'did', 'was', 'were', 'been', 'into', 'about',
        'any', 'all', 'our', 'out', 'they', 'them', 'their', 'there', 'here', 'then', 'than', 'too',
        'very', 'just', 'also', 'get', 'got', 'need', 'want', 'please', 'help', 'thanks', 'thank',
        'hello', 'some', 'more', 'much', 'many', 'one', 'use', 'using', 'way', 'able',
        'les', 'des', 'une', 'pour', 'avec', 'dans', 'sur', 'par', 'pas', 'est', 'sont', 'que', 'qui',
        'quoi', 'comment', 'mon', 'mes', 'ton', 'tes', 'son', 'ses', 'votre', 'vos', 'nous', 'vous',
        'aux', 'cette', 'ces', 'mais', 'donc', 'car', 'aide', 'besoin', 'veux', 'voudrais', 'faire',
        'peux', 'bonjour', 'merci',
        'كيف', 'ماذا', 'هل', 'التي', 'الذي', 'على', 'إلى', 'الى', 'من', 'في', 'عن', 'مع', 'هذا', 'هذه',
        'أريد', 'اريد', 'ممكن', 'شكرا', 'مرحبا',
    );

    /** How many matching articles are ranked for one search. The best ones come first from SQL. */
    const KB_CANDIDATES = 100;

    /** The words of a search worth looking for: three letters or more, no stopwords, no repeats. */
    public static function kbWords($query)
    {
        $parts = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower((string) $query));
        $stop = array_flip(self::KB_STOPWORDS);
        $words = array();

        foreach (is_array($parts) ? $parts : array() as $word) {
            if (mb_strlen($word) >= 3 && !isset($stop[$word]) && !in_array($word, $words, true)) {
                $words[] = $word;
            }
        }

        return array_slice($words, 0, 6);
    }

    /**
     * Published articles a visitor may read: top level (not a translation), not private, and not in
     * a hidden category.
     */
    public static function publicArticles()
    {
        $q = Capsule::table('tblknowledgebase as kb')->where('kb.parentid', 0);

        if (Schema::hasColumn('tblknowledgebase', 'private')) {
            $q->where(function ($w) {
                $w->whereNull('kb.private')->orWhere('kb.private', '!=', 'on');
            });
        }

        // Articles only in hidden categories are not public.
        $hiddenCats = Capsule::table('tblknowledgebasecats')->where('hidden', 'on')->pluck('id');
        if (count($hiddenCats)) {
            $q->whereNotIn('kb.id', function ($sub) use ($hiddenCats) {
                $sub->select('articleid')->from('tblknowledgebaselinks')->whereIn('categoryid', $hiddenCats);
            });
        }

        return $q;
    }

    /**
     * The host's own setup guides, for "how do I use this" right after the login.
     *
     * Found through the same knowledgebase search the assistant uses, so hidden categories stay
     * hidden. Titles only: the article itself is fetched when the client picks one.
     *
     * The searches run as a request for $clientId: the running request with that client and the
     * search words, so they never borrow another caller's client. kb_search reads only its words
     * today; the client is passed so the context stays truthful if that ever changes.
     *
     * @param int $clientId the client the guides are offered to
     * @return array<int, array{title: string, url: string}>
     */
    public static function setupGuides($clientId)
    {
        $out = array();
        $seen = array();
        $request = ActionContext::current()->withClientId((int) $clientId);

        /*
         * 1. THE HOST'S OWN SETUP SECTION. Device and app guides usually sit in a category of their
         *    own ("Setup guides", "Devices", "Installation"), and that is the list the client should
         *    pick from. 2. Then articles about installing or setting up, found by search. The third
         *    search, "how to", is gone: it came down to the word "how", which nearly every article
         *    contains (and "show" too), so it offered whichever old articles the table returned first.
         */
        $sources = array(self::setupCategoryArticles());
        foreach (array('install', 'setup') as $query) {
            try {
                $kb = (new KbSearch())->handle($request->withArgs(array('query' => $query)));
                $sources[] = isset($kb['data']['articles']) && is_array($kb['data']['articles']) ? $kb['data']['articles'] : array();
            } catch (\Exception $e) {
                continue;
            }
        }

        foreach ($sources as $articles) {
            foreach ($articles as $a) {
                $title = trim((string) (isset($a['title']) ? $a['title'] : ''));
                if ($title === '' || isset($seen[$title])) {
                    continue;
                }
                $seen[$title] = true;
                $out[] = array('title' => $title, 'url' => (string) (isset($a['url']) ? $a['url'] : ''));
                if (count($out) >= 6) {
                    return $out;
                }
            }
        }

        return $out;
    }

    /**
     * Public articles in a visible category whose name says it holds setup guides, in the host's
     * own order, newest first among equals. A knowledgebase that cannot be read gives none.
     *
     * @return array<int, array{title: string, url: string}>
     */
    public static function setupCategoryArticles()
    {
        try {
            $categories = array();
            foreach (Capsule::table('tblknowledgebasecats')->get(array('id', 'name', 'hidden')) as $c) {
                if ((string) $c->hidden !== 'on'
                    && preg_match('/(set\s*-?\s*up|install|getting\s+started|guides?\b|tutorials?|how[\s-]+to|devices?\b|\bapps?\b|players?\b)/i', (string) $c->name)) {
                    $categories[] = (int) $c->id;
                }
            }
            if (!$categories) {
                return array();
            }

            $q = self::publicArticles()->whereIn('kb.id', function ($sub) use ($categories) {
                $sub->select('articleid')->from('tblknowledgebaselinks')->whereIn('categoryid', $categories);
            });
            if (Schema::hasColumn('tblknowledgebase', 'order')) {
                $q->orderBy('kb.order');
            }

            $out = array();
            foreach ($q->orderBy('kb.id', 'desc')->limit(6)->get(array('kb.id', 'kb.title')) as $row) {
                $out[] = array('title' => (string) $row->title, 'url' => Format::kbUrl($row->id, $row->title));
            }

            return $out;
        } catch (\Exception $e) {
            return array();
        }
    }
}
