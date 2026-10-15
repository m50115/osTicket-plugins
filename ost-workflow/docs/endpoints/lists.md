# Custom Lists (read-only)

OW-REQ-63 (APPROVED V1 consumer requirement, msolis 2026-09-29; D-22 bounded exception authorized 2026-10-04). osTicket's own `DynamicList` / `DynamicListItem` tables are the only store; the plugin adds no table, index or cache. Handler: `lib/OstWorkflow/Handlers/Lists.php`, reading the core's models the way the SCP does (`scp/lists.php`, `include/class.list.php`). **GET only**: no create, edit, retire, delete or list administration (OW-REQ-65 stays NOT V1).

| Route | Policy | Notes |
|---|---|---|
| `GET /lists?limit=&cursor=` | `auth` | Custom lists by id, each with its property schema. |
| `GET /lists/roles` | `auth` | Installation-scoped semantic list roles; currently resolves `installed_equipment`. |
| `GET /lists/{id}/items?q=&include_disabled=&limit=&cursor=` | `auth` | Items of one custom list by id. Unknown list, or a typed core list (e.g. `ticket-status`) → 404. |

## Semantic roles

`GET /lists/roles` resolves installation configuration without deriving a role
from a mutable list name or enumerating lists. A configured valid response is:

```json
{"data":{"installed_equipment":{"list_id":24}}}
```

The number is installation-specific and is never a product default. If
`installed_equipment_list_id` is absent, non-positive, not a custom list, or no
longer exists, the route returns `503` with `error.code = "not_configured"`.
The route is read-only, requires the normal authenticated-agent policy, and
exposes no plugin configuration or secrets. Existing list and item routes are
unchanged.

Only **custom** lists (`type` IS NULL) are exposed. The core's typed lists have their own semantics and routes (`/statuses`).

Parameters. `limit` 1–500 (default 100; out of range is clamped, non-integer → 422). `cursor` is the opaque id cursor of the other lists (`meta.next_cursor`, `meta.has_more`); malformed → 422. `q` = 2–100 characters and is the core's own `DynamicList::search` predicate: the value **starts with** `q`, or the abbreviation (`extra`) **contains** it, or a stored property **contains** it. `include_disabled` = `0` (default: enabled items only) or `1` (also retired items, needed to resolve historical references); anything else → 422.

## Response fields (exact allowlists)

| Resource | Fields |
|---|---|
| list | `id`, `name`, `name_plural`, `sort_mode`, `item_count` (every item, retired included), `properties[]` = the schema `{name, label, type}` in form order (empty when the list has no property form) |
| item | `id`, `value`, `extra` (the abbreviation; null when blank), `enabled`, `internal`, `sort`, `properties` = `{<logical field name>: {label, value}}` |

`properties` are keyed by the property field's **logical name** (the form field `name`, stable across label edits), carry the label, and give the value as text. A property never set is absent. Never exposed: the list's `notes`, `masks`, configuration, translations, and any write.

## Cache and version

`meta.version` (on every `items` page) = SHA-1 of the **whole** list — every item, enabled or not, every property — so a client can validate a cached list with one request regardless of the page or filter. The route is also ETag-revalidated (`ETag` / `If-None-Match` → 304), like the catalogs. Order is by id (cursor order); the list's own sort (`sort_mode`, `sort`) is returned for the client to apply.

## Reads never write

`DynamicList::getForm()` auto-creates the property form; the handler uses `getConfigurationForm(false)` instead, so reading a list with no property form creates nothing (tested: lists, items, property forms and fields are identical before and after).

## Policy

`auth`: any logged-in agent reads every custom list (EQ-D3, accepted: the whole installed base is visible to every agent). **Do not store secrets in list items.**

Tested in `prod-sandbox/e2e.py` (section «custom lists»): 29 checks with self-created, self-cleaned fixtures — envelope and allowlists, typed-list exclusion, property schema, enabled/retired, search (value/abbreviation/property), validation, cursors, isolation between lists, version and ETag, 401/405, limited agent, and no write.
