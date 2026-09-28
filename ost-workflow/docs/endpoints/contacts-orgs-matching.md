# Contacts, organizations and reconciliation — `/workflow/v1`

Handlers `Users.php`, `Orgs.php`, `Matching.php`; helper `Contacts.php`. Base-value rule as in [tickets](tickets.md). Core: `class.user.php`, `class.organization.php`, `class.note.php`.

## Contacts (osTicket "users")
| Route (policy) | Notes |
|---|---|
| `GET /users?email=` / `?q=` (`auth`) | exact email / name-or-email search (2–100 chars) — allowed to any agent (ticket-creation lookup). Browsing with neither needs `user.dir`. Optional `org_id`, `limit` (≤100), `cursor` (by id). |
| `GET /users/{id}`, `/users/{id}/fields`, `/users/{id}/tickets` | DTO `{id,name,email,emails[],phone,org{id,name},is_primary_contact,has_account,created,updated}`; tickets are visibility-filtered, newest first, cursor by ticket id. |
| `POST /users` (`global.user.create`) | `name`, `email`, **`org_id` required** (`null` = no organization; domain auto-mapping is disabled by passing `0`), `phone?`, `force_new?`. Same email → `409 candidates` (`classification: safe`). Same last-10 phone digits, or same normalized name in the same organization → `409 candidates` (`candidate`/`ambiguous`) unless `force_new:true`. Never a blind find-or-create. |
| `PATCH /users/{id}` (`user.edit`) | `name?`, `email?`, `phone?`, `fields?{name:value}` + **`base{…}` for every changed field**. Phone compared by last-10 digits (the core reformats numbers as US style). Email owned by another contact → `409 candidates`. |
| `PUT /users/{id}/organization` (`user.edit`) | `org_id\|null`, `base` (current org id). |
| `GET/POST /users/{id}/notes` | quick notes (`QuickNote`); POST `{body}`. |

## Organizations
| Route (policy) | Notes |
|---|---|
| `GET /organizations?q=&domain=` (`auth`) | cursor by id. DTO adds `manager{token,name}`, `flags{collab_all_members, collab_primary_contact, assign_account_manager, share_primary_contact, share_everybody}`, `members_count`, `extra`, `extra_hash`. |
| `GET /organizations/{id}` | + `meta.warnings` (`auto_collaborators`, `account_manager_auto_assign`) the app should surface before creating tickets for its members. |
| `GET /organizations/{id}/members`, `/tickets`, `/fields` | members with `is_primary_contact`; visibility-filtered tickets. |
| `POST /organizations` (`global.org.create`) | `name`, `domain?`, `force_new?`. **409 candidates before `Organization::fromVars`** (which would silently return an existing organization): same normalized name (accents/case/corporate suffixes like S.A. de C.V.) → always 409; only a mapped domain → 409 unless `force_new`. |
| `POST /organizations/{id}/members` (`org.edit`) | `{user_id}`; a contact of another organization → 409 (use `PUT /users/{id}/organization` with its `base`). `DELETE …/members/{uid}` removes. |
| `PUT /organizations/{id}` (`org.edit`) | `{name, base:{name}}`; rename collision → 409 candidates. |
| `PATCH /organizations/{id}/profile` (`org.edit`) | `manager` (`s<id>`/`t<id>`/null), `domain`, `flags{…}`, `sharing: primary\|everybody`, `primary_contacts[]` + `base{same keys}`. Rebuilds the full POST the core expects (`Organization::updateProfile` reads `$_POST`; scoped by `Contacts::asPost`). |
| `PATCH /organizations/{id}/extra` (`org.edit`) | **True compare-and-swap** on the raw `extra` text: `{base_hash, set:{KEY:val}, unset:[KEY]}` edits the `BCW\|<ver>\|K=V\|K=V` line (other lines untouched; values cannot contain `\|` or line breaks) or `{base_hash, text}` replaces everything. `base_hash` = sha256 of the current text (`extra_hash` in the DTO). Stale hash → `409 conflict` with the current text; the UPDATE itself is conditional on the stored text, so a concurrent writer cannot be overwritten (`reason: cas_lost`). |
| `GET/POST /organizations/{id}/notes` | quick notes. |

## Reconciliation (`GET`, policy `auth`) — Architecture §L
Classification is `safe` (the server vouches: same email, server id, marker, server number), `candidate` (one plausible match) or `ambiguous` (several → a person decides), else `none`. No fuzzy matching.
* `/match/contact?email=&phone=&name=&org_id=` — email exact → safe; same last-10 phone digits → candidate; same normalized name inside `org_id` → candidate.
* `/match/organization?id=|idem_key=|name=&domain=` — by name/domain the answer is never `safe`; `id` or an own `idem_key` recorded as an organization is.
* `/match/ticket?marker=wf:<uuid>|number=|user_id=&subject=&since=` — marker/number → safe; otherwise same contact + open + same normalized subject + created within ±1 h of `since` **by the caller** → candidate.

## Errors
`candidates` (409, `details:{classification, matches[]}`), `conflict`, `validation_failed`, `forbidden`, `not_found`.

## Verified
Create/candidates/forced create, patch conflict/noop, org set/clear, notes, org create with normalized-name and domain candidates, members, rename, profile (manager/flags/sharing/primary contacts), `extra` set/unset/stale hash/bad value, match by phone (ambiguous), by org id/name, by marker/number/candidate window. Found: the core caches loaded form values on the objects and then ignores the request source, so current values are read with SQL (`Contacts::rawAnswers`) and updates run on untouched instances.
