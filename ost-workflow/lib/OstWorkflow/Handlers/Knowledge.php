<?php
namespace OstWorkflow\Handlers;

use OstWorkflow\ApiError;
use OstWorkflow\Contacts;
use OstWorkflow\Request;
use OstWorkflow\Res;
use OstWorkflow\Time;

/**
 * Knowledge Base (FAQ) of osTicket, read-only (OW-REQ-64). osTicket stays the authority: the plugin adds no
 * table and no index; it reads the core's own `FAQ` / `Category` models the way the SCP does
 * (scp/kb.php, include/staff/faq-categories.inc.php).
 *
 * Visibility is the core's: the SCP shows EVERY category and article (internal, public, featured) to any logged-in
 * agent — `faq.manage` only governs writing. There is no per-article ACL to reproduce, so the policy is `auth`.
 * Never exposed: `notes`, translations, help-topic links, attachments, and any write.
 */
final class Knowledge {
    const LIMIT_DEFAULT = 25;
    const LIMIT_MAX = 100;
    const Q_MIN = 2;
    const Q_MAX = 100;

    static function routes() {
        return [
            ['GET', '/knowledge/categories',                    'categories', ['policy' => 'auth']],
            ['GET', '/knowledge/articles',                      'articles',   ['policy' => 'auth']],
            ['GET', '/knowledge/articles/(?P<id>\d+)',          'article',    ['policy' => 'auth']],
        ];
    }

    /** ispublished / ispublic → label (FAQ::VISIBILITY_*, Category::VISIBILITY_*: 0 internal, 1 public, 2 featured). */
    private static function visibility($v) {
        $v = (int) $v;
        return $v === 2 ? 'featured' : ($v === 1 ? 'public' : 'internal');
    }

    /** id-ordered page: [$after id, $limit]. */
    private static function paging(Request $req) {
        $limit = $req->intQuery('limit', self::LIMIT_DEFAULT, 1, self::LIMIT_MAX);
        return [Contacts::cursorId($req), $limit];
    }

    /**
     * GET /knowledge/categories?limit=&cursor=
     * Every category (a tree by `parent_id`), by id. `article_count` = the category's own articles.
     */
    static function categories(Request $req) {
        require_once(INCLUDE_DIR . 'class.faq.php');
        list($after, $limit) = self::paging($req);
        $qs = \Category::objects()->annotate(['faq_count' => \SqlAggregate::COUNT('faqs')]);
        if ($after) $qs = $qs->filter(['category_id__gt' => $after]);
        $items = []; $more = false; $last = 0;
        foreach ($qs->order_by('category_id')->limit($limit + 1) as $c) {
            if (count($items) >= $limit) { $more = true; break; }
            $items[] = [
                'id'            => (int) $c->getId(),
                'parent_id'     => ($p = (int) $c->category_pid) ? $p : null,
                'name'          => (string) $c->getName(),
                'visibility'    => self::visibility($c->ispublic),
                'article_count' => (int) $c->faq_count,
            ];
            $last = (int) $c->getId();
        }
        return Res::page($items, $more ? Contacts::nextCursor($last) : null);
    }

    /**
     * GET /knowledge/articles?q=&category=&limit=&cursor=
     * Same search as the SCP (include/staff/faq-categories.inc.php): a substring of question, answer, keywords or the
     * category's name/description; `category` = exact category id (no descendants). By id, no answer in the list.
     */
    static function articles(Request $req) {
        require_once(INCLUDE_DIR . 'class.faq.php');
        list($after, $limit) = self::paging($req);
        $qs = \FAQ::objects();
        if (($q = $req->q('q')) !== null) {
            if (!is_string($q)) throw ApiError::validation("'q' must be a string", 'q');
            $q = trim($q);
            if (strlen($q) < self::Q_MIN)
                throw ApiError::validation("'q' must have at least " . self::Q_MIN . ' characters', 'q');
            if (strlen($q) > self::Q_MAX)
                throw ApiError::validation("'q' is too long (max " . self::Q_MAX . ')', 'q');
            $qs = $qs->filter(\Q::any([
                'question__contains'             => $q,
                'answer__contains'               => $q,
                'keywords__contains'             => $q,
                'category__name__contains'       => $q,
                'category__description__contains'=> $q,
            ]));
        }
        if (($c = $req->q('category')) !== null) {
            if (!is_string($c) || !ctype_digit($c) || (int) $c < 1)
                throw ApiError::validation("'category' must be a category id", 'category');
            if (!\Category::lookup((int) $c))
                throw ApiError::validation('Unknown category', 'category');
            $qs = $qs->filter(['category_id' => (int) $c]);
        }
        if ($after) $qs = $qs->filter(['faq_id__gt' => $after]);
        $items = []; $more = false; $last = 0;
        foreach ($qs->order_by('faq_id')->limit($limit + 1) as $f) {
            if (count($items) >= $limit) { $more = true; break; }
            $items[] = self::summary($f);
            $last = (int) $f->getId();
        }
        return Res::page($items, $more ? Contacts::nextCursor($last) : null);
    }

    /**
     * GET /knowledge/articles/{id}
     * `answer` is HTML passed through osTicket's own sanitizer (`Format::safe_html`, the one used when the SCP saves it):
     * scripts, `on*` handlers, forms and unsafe schemes are removed even if the row was written outside the SCP.
     */
    static function article(Request $req) {
        require_once(INCLUDE_DIR . 'class.faq.php');
        $f = \FAQ::lookup($req->intParam('id'));
        if (!$f) throw ApiError::notFound('article');
        return Res::ok(self::summary($f) + [
            'answer'  => \Format::safe_html((string) $f->getAnswer()),
            'created' => Time::iso($f->created),
        ]);
    }

    private static function summary($f) {
        $kw = trim((string) $f->getKeywords());
        return [
            'id'          => (int) $f->getId(),
            'question'    => (string) $f->getQuestion(),
            'category_id' => (int) $f->getCategoryId(),
            'keywords'    => $kw !== '' ? $kw : null,
            'visibility'  => self::visibility($f->ispublished),
            'updated'     => Time::iso($f->updated),
        ];
    }
}
