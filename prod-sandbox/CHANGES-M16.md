# M16 campaign: ost-workflow keeps no SQL table (branch claude/m16-no-idempotency-table)

Retired: table `workflow_idempotency` (kinds idem, revoked, tokgen, ratelimit, throttle, doc, file/resource ownership).
Reconciliation is practical (M16 compares against the official state), NOT an absolute exactly-once guarantee.

## e2e.py assertions removed or rewritten (reason: they tested the removed contract)
| Was | Now |
|---|---|
| logged-out token is revoked (401) | logout is stateless: 200 `{logged_out:true, revoked:false}` (token lives to TTL; documented) |
| logout all revokes every token of that agent (+ "other agents untouched") | removed (no revocation rows; `all` ignored) |
| ticket carries the idempotency marker `wf:<key>` | ticket carries no plugin marker |
| same key replays the same ticket (`Idempotent-Replayed`) | key optional/ignored: never replayed |
| same key, other body -> 422 idempotency_key_reused | same: accepted (201), not 422 |
| write without key -> 400 idempotency_key_required | write without key accepted (201) |
| (new) | reconciliation: `GET /tickets?q=&state=all&limit=100` finds the created ticket, DTO has number/subject/owner/created |
| retry with another key adopts the existing note | retry of same (uuid, version) on the same ticket adopts it, read from the thread; (new) body carries `BCW_DOC:<uuid>@<v>` |
| a document version cannot be planted on a second ticket (409) | removed: the cross-ticket check needed a global index (dropped) |
| hourly budgets block (lookup/upload/pdf/mail 429, "429 not replayed") + its ledger SQL seeding | removed (no budgets) |
| `[idempotency crash recovery]` section (adopt via marker, needs_review, in_progress, SQL ledger fixtures) | removed |
| unattached upload readable by its uploader only / D3 "another agent's unattached upload is 403" | unattached upload readable with its hash; the D3 pre-check removed (no ownership store); attached files still follow ticket access (unchanged) |
| batch: per-request replay identical + `Idempotent-Replayed` | no per-request ledger: re-evaluated, nothing new created |
| batch: key required (400) | key optional (200) |
| durability: expire ledger row, cron purge removed it (`purge-check.php`) | "no TTL, no purge": records unchanged; replay of the same key is a new request, per-item ids keep it idempotent; `purge-check.php` deleted |
| login throttling 5 failures -> 6th 429 (+ other user not locked) | removed (native auth backends own lockout) |

## Other tooling
- smoke.sh: 'write without key 400', 'create + replay = same ticket', 'same key other body 422', 'revoked token 401' replaced by: write without key accepted (201), created id returned, GET /tickets/{id} reconciles (200), logout 200, garbage token 401. 'stale base 409' kept.
- ci-check.sh: new guards (no CREATE/DROP/ALTER TABLE, no Idempotency::/Throttle::/ledger name; no raw INSERT/UPDATE on ticket/thread tables). All others kept.
- gen-openapi.php: Idempotency-Key header is optional+deprecated; `OW_SRC=<ost-workflow dir>` mode generates without an osTicket install.
- maintenance-unit.php: Documents stub accepts the new third argument.
