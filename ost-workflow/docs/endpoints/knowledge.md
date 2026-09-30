# Knowledge Base (read-only)

OW-REQ-64. osTicket's own FAQ/Category tables are the only store; the plugin adds no table, index or cache. Handler: `lib/OstWorkflow/Handlers/Knowledge.php`, reading the core's `FAQ` and `Category` models the way the SCP does (`scp/kb.php`, `include/staff/faq-categories.inc.php`). **GET only**: no create, update, delete, attachments, topics or `notes`.

| Route | Policy | Notes |
|---|---|---|
| `GET /knowledge/categories?limit=&cursor=` | `auth` | Every category, ordered by id (a tree via `parent_id`). |
| `GET /knowledge/articles?q=&category=&limit=&cursor=` | `auth` | Article summaries (no `answer`), ordered by id. |
| `GET /knowledge/articles/{id}` | `auth` | Summary + `answer` + `created`. Unknown id → 404. |

Parameters. `limit` 1–100 (default 25; out of range is clamped, non-integer → 422). `cursor` is the opaque id cursor of the other lists (`meta.next_cursor`, `meta.has_more`); malformed → 422. `q` = 2–100 characters, substring of question, answer, keywords or the category's name/description (the SCP's own search; no ranking, no full-text). `category` = exact category id (descendants are **not** included); non-integer or unknown → 422. Unknown query parameters are ignored, as everywhere else in the plugin.

## Response fields (exact allowlists)

| Resource | Fields |
|---|---|
| category | `id`, `parent_id` (null for a root), `name`, `visibility` (`internal`/`public`/`featured`), `article_count` (the category's own articles) |
| article (list) | `id`, `question`, `category_id`, `keywords` (null when blank), `visibility`, `updated` |
| article (detail) | list fields + `answer`, `created` |

Dates are ISO-8601 UTC. `question` and `name` are the stored values (no translation lookup).

## Visibility (what the core does)

The SCP lists every category and every article — internal, public and featured — to any logged-in agent; only writing needs `faq.manage`. There is no per-article ACL, so none is simulated: a Limited Access agent reads the same set (tested). `visibility` is returned so the client can tell internal from public. **Do not store secrets in the KB**: every agent can read it.

## `answer` HTML

`answer` is HTML run through the core's `Format::safe_html` (HTMLawed with the same rules the SCP applies when saving), so it is safe even when the row was written outside the SCP: `<script>`/`<style>`, `on*` attributes, `iframe`, `form`/`input` and unsafe URL schemes are removed (`javascript:` links become `denied:javascript:…`). Verified with a hostile fixture. Inline images that point at core attachments (`cid:`) are not resolved: attachments are out of scope (OW-REQ-64b, LATER). The client should still render it in a restricted HTML view, not a full web view.

## Findings worth knowing

* `FAQ.question` is UNIQUE across the whole KB: titles must carry the manufacturer/model.
* The SCP editor always saves `keywords` as a single space, so `keywords` is normally `null`; search still covers them if a row has any.
* No write, no attachment download and no help-topic link are exposed.

Tested in `prod-sandbox/e2e.py` (section «knowledge base»): 40 checks with self-created, self-cleaned fixtures — field allowlists, tree, visibility, search, cursors, validation, hostile HTML, 401/405, and identical `faq`/`faq_category`/`faq_topic`/attachment state before and after.
