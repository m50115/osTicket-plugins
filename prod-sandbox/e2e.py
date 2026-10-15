#!/usr/bin/env python3
"""
End-to-end regression suite for ost-workflow (HTTP, against a running sandbox).

  python3 e2e.py [BASE]           default BASE = http://127.0.0.1:8090/api/workflow/v1
  env: SB_ADMIN_USER / SB_ADMIN_PASS (default: read from ~/development/ost-sandbox/credentials.env)
       AGENT2_USER / AGENT2_PASS  (a Limited-Access agent; if missing, the permission checks are skipped)
       AGENT3_USER / AGENT3_PASS  (a non-admin, non-manager agent with ticket.edit, e.g. Expanded Access in dept 1;
                                   if missing, the department-manager SLA checks are skipped)
       SCR   directory with agent2.env / agent3.env (and optionally sql.sh); default ~/development/ost-sandbox/e2e-fixtures.
             Build them with create-agent.php: see E2E-Fixtures.md (nothing secret is versioned).
       E2E_STRICT=1   any skip except the known one ("agent2 actions") counts as a FAILURE (use it to verify a rebuilt fixture)

Each run creates its own data (unique emails/subjects); nothing is deleted afterwards.
Covers the regression chains RC-* of the Technical Notes: routing, auth/tokens, idempotency and crash recovery,
base values, candidates, threads/files, sync, tasks, sandbox-only checks skipped when the DB is not reachable.
Exit code 0 = all checks passed.
"""
import base64, json, os, sys, time, uuid, urllib.request, urllib.error, urllib.parse, re, subprocess

BASE = (sys.argv[1] if len(sys.argv) > 1 else "http://127.0.0.1:8090/api/workflow/v1").rstrip("/")
ROOT = BASE.split("/api/")[0]


def load_env(path):
    out = {}
    try:
        for ln in open(os.path.expanduser(path)):
            if "=" in ln and not ln.startswith("#"):
                k, v = ln.strip().split("=", 1)
                out[k] = v.strip().strip('"').strip("'")
    except OSError:
        pass
    return out


ENV = load_env("~/development/ost-sandbox/credentials.env")
ADMIN = (os.environ.get("SB_ADMIN_USER") or ENV.get("SB_ADMIN_USER", "ostadmin"), os.environ.get("SB_ADMIN_PASS") or ENV.get("SB_ADMIN_PASS", ""))
# Local, uncommitted fixture credentials (agent2.env, agent3.env; see docs/security/E2E-Fixtures.md): SCR, else the sandbox's own directory.
SCR_DIR = os.environ.get("SCR") or os.path.expanduser("~/development/ost-sandbox/e2e-fixtures")
A2 = (os.environ.get("AGENT2_USER"), os.environ.get("AGENT2_PASS"))
if not A2[0]:
    e2 = load_env(os.path.join(SCR_DIR, "agent2.env"))
    A2 = (e2.get("AGENT2_USER"), e2.get("AGENT2_PASS"))

passed = failed = skipped = 0


def check(name, cond, detail=""):
    global passed, failed
    if cond:
        passed += 1
        print("  ok    " + name)
    else:
        failed += 1
        print("  FAIL  %s %s" % (name, detail))


KNOWN_SKIPS = ("agent2 actions",)   # the ticket of that check is not visible to the limited agent (known, not a fixture problem)


def skip(name, why):
    """E2E_STRICT=1 (fixture verification): any skip other than the known one is a FAILURE, so a half-built fixture cannot pass silently."""
    global skipped, failed
    if os.environ.get("E2E_STRICT") and name not in KNOWN_SKIPS:
        failed += 1
        print("  FAIL  %s (unexpected skip under E2E_STRICT: %s)" % (name, why))
        return
    skipped += 1
    print("  skip  %s (%s)" % (name, why))


class R:
    def __init__(self, status, body, headers):
        self.status, self.headers = status, headers
        self.raw = body
        try:
            self.json = json.loads(body) if body else None
        except ValueError:
            self.json = None

    @property
    def data(self):
        return (self.json or {}).get("data")

    @property
    def meta(self):
        return (self.json or {}).get("meta") or {}

    @property
    def err(self):
        return ((self.json or {}).get("error") or {}).get("code")

    @property
    def details(self):
        return ((self.json or {}).get("error") or {}).get("details") or {}


def call(method, path, body=None, token=None, key="auto", headers=None, raw=None, ctype="application/json"):
    url = BASE + path
    h = dict(headers or {})
    if token:
        h["Authorization"] = "Bearer " + token
    data = None
    if raw is not None:
        data = raw
        h["Content-Type"] = ctype
    elif body is not None:
        data = json.dumps(body).encode()
        h["Content-Type"] = "application/json"
    if method not in ("GET", "HEAD") and key:
        h["Idempotency-Key"] = str(uuid.uuid4()) if key == "auto" else key
    req = urllib.request.Request(url, data=data, method=method, headers=h)
    try:
        with urllib.request.urlopen(req, timeout=60) as r:
            return R(r.status, r.read().decode("utf-8", "replace"), dict(r.headers))
    except urllib.error.HTTPError as e:
        return R(e.code, e.read().decode("utf-8", "replace"), dict(e.headers))


def multipart(field, filename, content, ctype="text/plain"):
    b = uuid.uuid4().hex
    body = ("--%s\r\nContent-Disposition: form-data; name=\"%s\"; filename=\"%s\"\r\nContent-Type: %s\r\n\r\n" % (b, field, filename, ctype)).encode() + content + ("\r\n--%s--\r\n" % b).encode()
    return body, "multipart/form-data; boundary=" + b


def login(user, pw):
    r = call("POST", "/auth/login", {"username": user, "password": pw}, key=None)
    return r.data["token"] if r.data else None


def sql(query):
    """Optional direct DB access (sandbox only): SCR/sql.sh if present, else the versioned prod-sandbox/sql.sh (credentials.env)."""
    script = os.path.join(SCR_DIR, "sql.sh")
    if not os.path.exists(script):
        script = os.path.join(os.path.dirname(os.path.abspath(__file__)), "sql.sh")
    if not os.path.exists(script) or not os.path.exists(os.path.expanduser("~/development/ost-sandbox/credentials.env")):
        return None
    out = subprocess.run([script, query], capture_output=True, text=True).stdout.strip().split("\n")
    return out[1:] if len(out) > 1 else []


U = uuid.uuid4().hex[:8]
print("e2e: %s (run %s)" % (BASE, U))

# ------------------------------------------------------------------ routing / envelope
print("\n[routing and envelope]")
r = call("GET", "/ping", key=None)
check("ping is public JSON 200", r.status == 200 and r.data == {"status": "ok"})
check("security headers", r.headers.get("X-Content-Type-Options") == "nosniff" and "no-store" in r.headers.get("Cache-Control", ""))
r = call("GET", "/definitely/not/a/route", key=None)
check("unknown route is JSON 404", r.status == 404 and r.err == "not_found")
r = call("POST", "/ping", {}, key=None)
check("wrong method is 405 with Allow", r.status == 405 and "GET" in r.headers.get("Allow", ""))
check("no token is 401 JSON", call("GET", "/config", key=None).err == "unauthorized")
check("garbage token is 401", call("GET", "/config", token="a.b", key=None).status == 401)
check("SCP and portal still answer", urllib.request.urlopen(ROOT + "/").status == 200)

# ------------------------------------------------------------------ auth
print("\n[auth and tokens]")
TOK = login(*ADMIN)
check("login returns a token", bool(TOK))
r = call("POST", "/auth/login", {"username": ADMIN[0], "password": "wrong-" + U}, key=None)
check("bad password is 401", r.status == 401 and r.err == "unauthorized")
check("login needs username and password", call("POST", "/auth/login", {"username": "x"}, key=None).status == 422)
v = call("GET", "/auth/verify", token=TOK)
check("verify", v.status == 200 and v.data["valid"] and v.data["staff"]["username"] == ADMIN[0])
cfg = call("GET", "/config", token=TOK)
check("/config: version, api, attachment limits, text policy", cfg.status == 200 and "plugin_version" in cfg.data and cfg.data["api_versions"] == ["v1"]
      and cfg.data["attachments"]["max_file_bytes"] > 0 and "supplementary_characters_supported" in cfg.data["text"])
check("/config never leaks the secret", "secret" not in cfg.raw.lower())
me = call("GET", "/me/permissions", token=TOK)
check("/me/permissions per department", me.status == 200 and len(me.data["departments"]) > 0 and "ticket.reply" in me.data["departments"][0]["permissions"])
t2 = login(*ADMIN)
call("POST", "/auth/logout", {}, token=t2, key=None)
check("logged-out token is revoked", call("GET", "/config", token=t2).status == 401)
check("other tokens keep working", call("GET", "/config", token=TOK).status == 200)
if A2[0]:
    ta, tb = login(*A2), login(*A2)
    call("POST", "/auth/logout", {"all": True}, token=ta, key=None)
    check("logout all revokes every token of that agent", call("GET", "/config", token=ta).status == 401 and call("GET", "/config", token=tb).status == 401)
    check("...and does not touch other agents", call("GET", "/config", token=TOK).status == 200)
else:
    skip("logout all", "needs AGENT2_USER/AGENT2_PASS (it would revoke the admin's tokens)")
TOK2 = login(*A2) if A2[0] else None
A3 = (os.environ.get("AGENT3_USER"), os.environ.get("AGENT3_PASS"))
if not A3[0]:
    e3 = load_env(os.path.join(SCR_DIR, "agent3.env"))
    A3 = (e3.get("AGENT3_USER"), e3.get("AGENT3_PASS"))
TOK3 = login(*A3) if A3[0] else None

# ------------------------------------------------------------------ catalogs
print("\n[catalogs, forms, canned]")
for path in ["/departments", "/topics", "/statuses", "/priorities", "/slas", "/teams", "/staff", "/forms/ticket", "/forms/user", "/forms/organization", "/catalog/sources", "/canned"]:
    r = call("GET", path, token=TOK)
    check("GET " + path, r.status == 200 and r.data is not None, "-> %s" % r.status)
r = call("GET", "/statuses", token=TOK)
et = r.headers.get("ETag")
check("catalog carries an ETag", bool(et))
r304 = call("GET", "/statuses", token=TOK, headers={"If-None-Match": et or ""})
check("If-None-Match gives 304 with no body", r304.status == 304 and r304.raw == "")

# ------------------------------------------------------------------ contacts / orgs / matching
print("\n[contacts, organizations, matching]")
r = call("POST", "/users", {"name": "E2E " + U, "email": "e2e-%s@example.com" % U}, token=TOK)
check("POST /users requires org_id", r.status == 422 and r.json["error"]["field"] == "org_id")
body = {"name": "E2E " + U, "email": "e2e-%s@example.com" % U, "org_id": None, "phone": "555 %s" % ("1%06d" % (int(U, 16) % 1000000))}
r = call("POST", "/users", body, token=TOK)
check("create contact", r.status == 201 and r.data["email"] == body["email"], r.raw[:120])
UID = r.data["id"]
r = call("POST", "/users", {"name": "Dup", "email": body["email"], "org_id": None}, token=TOK)
check("same email -> 409 candidates (safe)", r.status == 409 and r.err == "candidates" and r.details["classification"] == "safe")
r = call("GET", "/match/contact?email=" + body["email"], token=TOK)
check("match/contact by email is safe", r.data["classification"] == "safe")
r = call("PATCH", "/users/%d" % UID, {"name": "E2E renamed"}, token=TOK)
check("PATCH /users needs base", r.status == 422)
r = call("PATCH", "/users/%d" % UID, {"name": "E2E renamed", "base": {"name": "E2E " + U}}, token=TOK)
check("PATCH /users applies with base", r.status == 200 and r.data["applied"] and r.data["user"]["name"] == "E2E renamed")
r = call("PATCH", "/users/%d" % UID, {"name": "Other", "base": {"name": "E2E " + U}}, token=TOK)
check("PATCH /users stale base -> 409", r.status == 409 and r.err == "conflict")
r = call("POST", "/organizations", {"name": "E2E Org %s S.A. de C.V." % U, "domain": "e2e-%s.example.com" % U}, token=TOK)
check("create organization", r.status == 201, r.raw[:100])
OID = r.data["id"]
r = call("POST", "/organizations", {"name": "e2e org %s" % U}, token=TOK)
check("same normalized name -> 409 candidates", r.status == 409 and r.err == "candidates")
r = call("GET", "/match/organization?name=E2E%%20ORG%%20%s" % U, token=TOK)
check("match/organization by name is a candidate, never safe", r.data["classification"] == "candidate")
r = call("PUT", "/users/%d/organization" % UID, {"org_id": OID, "base": None}, token=TOK)
check("set contact organization", r.status == 200 and r.data["user"]["org"]["id"] == OID)
r = call("PATCH", "/organizations/%d/extra" % OID, {"base_hash": "0" * 64, "set": {"RFC": "AAA010101AAA"}}, token=TOK)
check("extra stale hash -> 409", r.status == 409)
h0 = call("GET", "/organizations/%d" % OID, token=TOK).data["extra_hash"]
r = call("PATCH", "/organizations/%d/extra" % OID, {"base_hash": h0, "set": {"RFC": "AAA010101AAA"}}, token=TOK)
check("extra set with CAS", r.status == 200 and r.data["extra"].startswith("BCW|1|RFC=AAA010101AAA"))

# ------------------------------------------------------------------ tickets
print("\n[tickets]")
r = call("GET", "/tickets", token=TOK)
check("list requires state", r.status == 422 and r.json["error"]["field"] == "state")
key = str(uuid.uuid4())
tb = {"subject": "E2E %s" % U, "message": "hello", "user_id": UID, "topic_id": 1}
r = call("POST", "/tickets", tb, token=TOK, key=key)
check("create ticket", r.status == 201 and r.data["subject"] == tb["subject"], r.raw[:120])
TID = r.data["id"]
check("ticket carries the idempotency marker", r.data["source_extra"] == "wf:" + key[:37])
r2 = call("POST", "/tickets", tb, token=TOK, key=key)
check("same key replays the same ticket", r2.status == 201 and r2.data["id"] == TID and r2.headers.get("Idempotent-Replayed") == "true")
check("same key, other body -> 422", call("POST", "/tickets", dict(tb, subject="other"), token=TOK, key=key).err == "idempotency_key_reused")
check("write without key -> 400", call("POST", "/tickets", tb, token=TOK, key=None).err == "idempotency_key_required")
r = call("GET", "/tickets?state=all&limit=1", token=TOK)
check("list: cursor page + real activity block", r.status == 200 and "activity" in r.data[0] and "excerpt" in json.dumps(r.data[0]["activity"]))
ids = []
cur = None
for _ in range(50):
    r = call("GET", "/tickets?state=all&limit=3" + ("&cursor=" + cur if cur else ""), token=TOK)
    ids += [t["id"] for t in r.data]
    cur = r.meta.get("next_cursor")
    if not cur:
        break
check("cursor walk returns each ticket once", len(ids) == len(set(ids)) and TID in ids)
r = call("GET", "/tickets/%d" % TID, token=TOK)
check("detail: allowed_actions, visible_to_caller, lock", r.status == 200 and "reply" in r.data["allowed_actions"] and r.data["visible_to_caller"] and r.data["lock"]["locked"] is False)
check("search finds it", TID in [t["id"] for t in call("GET", "/search?q=E2E%%20%s" % U, token=TOK).data])
check("actions endpoint", call("GET", "/tickets/%d/actions" % TID, token=TOK).data["actions"]["reply"]["allowed"])
tg = call("GET", "/tickets/%d/targets" % TID, token=TOK)
check("targets: assign/refer with availability", tg.status == 200 and "agents" in tg.data["assign"] and "depts" in tg.data["refer"])
check("participants include department", "department" in [p["role"] for p in call("GET", "/tickets/%d/participants" % TID, token=TOK).data])

# updates with base values
r = call("POST", "/tickets/%d/claim" % TID, {}, token=TOK)
check("claim", r.status == 200 and r.data["applied"] and r.data["ticket"]["assignee"]["token"].startswith("s"))
check("claim again is a no-op", call("POST", "/tickets/%d/claim" % TID, {}, token=TOK).data["applied"] is False)
r = call("POST", "/tickets/%d/assignment" % TID, {"assignee": {"type": "staff", "id": 1}}, token=TOK)
check("assignment needs base", r.status == 422)
r = call("POST", "/tickets/%d/assignment" % TID, {"assignee": {"type": "team", "id": 1}, "base": None}, token=TOK)
check("assignment with stale base -> 409 + last_change", r.status == 409 and "last_change" in r.details)
r = call("PATCH", "/tickets/%d/fields/priority" % TID, {"value": 3, "base": 2}, token=TOK)
check("priority change", r.status == 200 and r.data["applied"] and r.data["ticket"]["priority"]["id"] == 3)
r = call("PATCH", "/tickets/%d/fields/priority" % TID, {"value": 4, "base": 2}, token=TOK)
check("priority stale base -> 409", r.status == 409)
# Same RC-14 drift as task due_at (D-22 requalification): the core stored PATCH .../duedate +1 h in DST months when MySQL
# runs on a fixed 'CST'. The manual due date must come back identical in UTC in winter, DST and shoulder months.
prev = call("GET", "/tickets/%d" % TID, token=TOK).data["due"]["manual"]
for iso in ["%d-%02d-%02dT18:00:00Z" % (time.gmtime().tm_year + 1, m, d) for m, d in ((1, 15), (3, 20), (7, 15), (10, 15), (12, 15))]:
    r = call("PATCH", "/tickets/%d/fields/duedate" % TID, {"value": iso, "base": prev}, token=TOK)
    got = call("GET", "/tickets/%d" % TID, token=TOK).data["due"]["manual"] if r.status == 200 else None
    check("ticket duedate UTC round trip (%s)" % iso[:7], r.status == 200 and got == iso, "%s -> %s" % (iso, got))
    prev = got
r = call("PATCH", "/tickets/%d/fields/duedate" % TID, {"value": None, "base": prev}, token=TOK)
check("ticket duedate cleared with null", r.status == 200 and call("GET", "/tickets/%d" % TID, token=TOK).data["due"]["manual"] is None)
r = call("POST", "/tickets/%d/status" % TID, {"status_id": 3, "base": 2}, token=TOK)
check("status stale base -> 409", r.status == 409)
r = call("POST", "/tickets/%d/status" % TID, {"status_id": 3, "base": 1, "comment": "e2e"}, token=TOK)
check("close ticket", r.status == 200 and r.data["ticket"]["status"]["state"] == "closed")
r = call("POST", "/tickets/%d/assignment" % TID, {"assignee": {"type": "staff", "id": 1}, "base": None}, token=TOK)
check("assigning a closed ticket needs reopen:true", r.status == 409 and r.json["error"]["field"] == "reopen")
r = call("POST", "/tickets/%d/assignment" % TID, {"assignee": {"type": "staff", "id": 1}, "base": None, "reopen": True}, token=TOK)
check("reopen + assign", r.status == 200 and r.data["ticket"]["status"]["state"] == "open")
r = call("POST", "/tickets/%d/transfer" % TID, {"dept_id": 2, "base": 1, "comment": "e2e"}, token=TOK)
check("transfer with base", r.status == 200 and r.data["ticket"]["dept"]["id"] == 2 and r.data["visible_to_caller"] is True)
r = call("DELETE", "/tickets/%d/assignment" % TID, {"base": "s1", "comment": "e2e"}, token=TOK)
check("release", r.status == 200 and r.data["applied"] and r.data["ticket"]["assignee"] is None)
check("PC-S1: PUT /tickets/{id}/owner is retired", call("PUT", "/tickets/%d/owner" % TID, {"user_id": UID, "base": UID}, token=TOK).status in (404, 405))
check("PC-S1: /actions no longer advertises change_owner", "change_owner" not in call("GET", "/tickets/%d/actions" % TID, token=TOK).data["actions"])

# collaborators
r = call("POST", "/tickets/%d/collaborators" % TID, {"user_id": 1}, token=TOK)
check("add collaborator", r.status in (200, 201))
r = call("PATCH", "/tickets/%d/collaborators/1" % TID, {"active": False, "base": True}, token=TOK)
check("deactivate collaborator", r.status == 200 and r.data["applied"])
r = call("PATCH", "/tickets/%d/collaborators/1" % TID, {"active": True, "base": True}, token=TOK)
check("collaborator stale base -> 409", r.status == 409)

# ------------------------------------------------------------------ threads / files
print("\n[threads and files]")
r = call("POST", "/tickets/%d/replies" % TID, {"body": "no notify field"}, token=TOK)
check("reply needs an explicit notify", r.status == 422 and r.json["error"]["field"] == "notify")
r = call("POST", "/tickets/%d/replies" % TID, {"body": "public reply", "notify": "none", "cc": [999999]}, token=TOK)
check("reply cc with unknown contact -> 422", r.status == 422)
r = call("POST", "/tickets/%d/replies" % TID, {"body": "public reply", "notify": "none"}, token=TOK)
check("reply", r.status == 201 and r.data["entry"]["audience"] == "customer" and "sanitized" in r.data["effects"])
RID = r.data["entry"]["id"]
r = call("POST", "/tickets/%d/notes" % TID, {"body": "internal note with a typo"}, token=TOK)
check("note (alert off by default)", r.status == 201 and r.data["entry"]["audience"] == "internal" and r.data["effects"]["alert"] is False)
NID = r.data["entry"]["id"]
r = call("PATCH", "/tickets/%d/notes/%d" % (TID, NID), {"body": "internal note fixed"}, token=TOK)
check("edit internal note -> new entry supersedes", r.status == 200 and r.data["applied"] and r.data["entry"]["supersedes"] == NID and r.data["entry"]["edited"])
NID2 = r.data["entry"]["id"]
r = call("PATCH", "/tickets/%d/notes/%d" % (TID, NID), {"body": "again"}, token=TOK)
check("editing an old version -> 409 with current id", r.status == 409 and r.details.get("current_entry_id") == NID2)
r = call("PATCH", "/tickets/%d/notes/%d" % (TID, RID), {"body": "edit a public reply"}, token=TOK)
check("a public reply is not editable", r.status == 422 and r.details.get("reason") == "not_editable_type")
r = call("GET", "/tickets/%d/activity?include_hidden=1" % TID, token=TOK)
kinds = [(x["kind"], x["id"], x.get("hidden")) for x in r.data]
check("activity keeps every version (old one hidden)", ("entry", NID, True) in kinds and ("entry", NID2, False) in kinds)
r = call("GET", "/tickets/%d/activity?direction=backward&limit=2" % TID, token=TOK)
check("activity backward page", r.status == 200 and r.meta["direction"] == "backward" and "before_entry" in r.meta["next_cursor"])
# emoji policy
r = call("POST", "/tickets/%d/notes" % TID, {"body": "emoji \U0001F600 here", "unsupported_chars": "reject"}, token=TOK)
if cfg.data["text"]["supplementary_characters_supported"]:
    check("emoji accepted when the database stores them", r.status == 201)
else:
    check("emoji rejected on request (utf8mb3)", r.status == 422 and r.details.get("reason") == "unsupported_characters")
    r = call("POST", "/tickets/%d/notes" % TID, {"body": "emoji \U0001F600 here"}, token=TOK)
    check("emoji reported as removed, not silently lost", r.status == 201 and r.data["effects"]["sanitized"]["removed_chars"] == 1)
# files
body_, ct = multipart("file", "e2e.txt", ("hello e2e %s" % U).encode())
r = call("POST", "/files", token=TOK, raw=body_, ctype=ct)
check("upload a file", r.status == 201 and r.data["hash"], r.raw[:120])
FID, FH = r.data["file_id"], r.data["hash"]
r = call("POST", "/tickets/%d/notes" % TID, {"body": "with file", "file_ids": [FID]}, token=TOK)
check("note with the uploaded file", r.status == 201 and [a["name"] for a in r.data["entry"]["attachments"]] == ["e2e.txt"])
d = urllib.request.Request(BASE + "/files/" + FH, headers={"Authorization": "Bearer " + TOK})
with urllib.request.urlopen(d) as resp:
    check("download returns identical bytes + ETag", resp.read() == ("hello e2e %s" % U).encode() and bool(resp.headers.get("ETag")))
check("unknown file id -> 422", call("POST", "/tickets/%d/notes" % TID, {"body": "x", "file_ids": [99999999]}, token=TOK).status == 422)
bad, ct = multipart("file", "evil.jpg", b"MZ\x90\x00 not an image", "image/jpeg")
check("executable renamed .jpg is refused", call("POST", "/files", token=TOK, raw=bad, ctype=ct).status in (415, 422))
big, ct = multipart("file", "big.txt", b"x" * (cfg.data["attachments"]["max_file_bytes"] + 10))
rb = call("POST", "/files", token=TOK, raw=big, ctype=ct)
check("oversize file -> typed too_large (or nginx 413)", rb.status == 413)

# ------------------------------------------------------------------ PDF rule, recipients, SLA
print("\n[PDF needs text, recipients, SLA]")
check("/config publishes the PDF text rule and search limits", cfg.data["limits"]["min_text_with_pdf"] >= 0 and cfg.data["search"]["min_length"] == 2)
pdf = b"%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj 2 0 obj<</Type/Pages/Count 0/Kids[]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n"
def upload_pdf():
    b_, c_ = multipart("file", "report-%s.pdf" % uuid.uuid4().hex[:6], pdf, "application/pdf")
    return call("POST", "/files", token=TOK, raw=b_, ctype=c_)
up = upload_pdf()
check("upload a PDF", up.status == 201 and up.data["type"] == "application/pdf", up.raw[:100])
PF = up.data["file_id"]
minc = cfg.data["limits"]["min_text_with_pdf"]
if minc:
    r = call("POST", "/tickets/%d/notes" % TID, {"body": "ver", "file_ids": [PF]}, token=TOK)
    check("note: a PDF with too little text is refused", r.status == 422 and r.details.get("reason") == "attachment_needs_text")
    r = call("POST", "/tickets/%d/replies" % TID, {"body": "adjunto", "notify": "none", "file_ids": [PF]}, token=TOK)
    check("public reply: a PDF with too little text is refused", r.status == 422 and r.details.get("reason") == "attachment_needs_text")
    short_note = call("POST", "/tickets/%d/notes" % TID, {"body": "corto"}, token=TOK).data["entry"]["id"]
    r = call("POST", "/tickets/%d/notes/%d/files" % (TID, short_note), {"file_ids": [upload_pdf().data["file_id"]]}, token=TOK)
    check("late attachment of a PDF onto a short note is refused", r.status == 422 and r.details.get("reason") == "attachment_needs_text")
r = call("POST", "/tickets/%d/notes" % TID, {"body": "Attached: signed service report. The customer should review it and confirm.", "file_ids": [PF]}, token=TOK)
check("note: a PDF with explanatory text is accepted", r.status == 201 and r.data["entry"]["attachments"][0]["type"] == "application/pdf")
PN = r.data["entry"]["id"]
if minc:
    r = call("PATCH", "/tickets/%d/notes/%d" % (TID, PN), {"body": "ok"}, token=TOK)
    check("editing the note down to almost no text is refused while the PDF is attached", r.status == 422)
rc = call("GET", "/tickets/%d/recipients?reply_to=all" % TID, token=TOK)
check("recipients: who gets mail (data) vs who sees it in the portal (meta.portal)", rc.status == 200 and isinstance(rc.meta.get("portal"), list) and any(x["reason"] == "owner" for x in rc.meta["portal"]))

# SLA
sl = call("GET", "/tickets/%d/sla" % TID, token=TOK)
check("GET /sla: plan, due dates, overdue flag, available actions", sl.status == 200 and "plan" in sl.data and "effective" in sl.data["due"] and "restart" in sl.meta["actions"])
def sla_base():
    x = call("GET", "/tickets/%d/sla" % TID, token=TOK).data
    return {"sla_id": (x["plan"] or {}).get("id"), "due": x["due"]["effective"]}
r = call("POST", "/tickets/%d/sla" % TID, {"action": "extend", "hours": 0, "base": sla_base()}, token=TOK)
check("SLA extend validates hours", r.status == 422)
r = call("POST", "/tickets/%d/sla" % TID, {"action": "restart", "base": {"sla_id": 999, "due": None}}, token=TOK)
check("SLA op with a stale base -> 409", r.status == 409)
r = call("POST", "/tickets/%d/sla" % TID, {"action": "disable", "base": sla_base()}, token=TOK)
check("SLA disable", r.status == 200 and r.data["sla"]["plan"] is None and not r.data["sla"]["is_overdue"])
r = call("POST", "/tickets/%d/sla" % TID, {"action": "restart", "base": sla_base()}, token=TOK)
check("SLA restart without a plan -> 409 no_sla", r.status == 409 and r.details.get("reason") == "no_sla")
r = call("POST", "/tickets/%d/sla" % TID, {"action": "enable", "sla_id": 1, "base": sla_base()}, token=TOK)
check("SLA enable a plan (counts from now)", r.status == 200 and r.data["sla"]["plan"]["id"] == 1 and r.data["sla"]["due"]["sla"])
r = call("POST", "/tickets/%d/sla" % TID, {"action": "extend", "hours": 24, "base": sla_base()}, token=TOK)
check("SLA extend +24h", r.status == 200 and r.data["applied"])
if sql("select 1") is not None:
    sql("update ost_ticket set est_duedate=DATE_SUB(NOW(), INTERVAL 3 HOUR), duedate=NULL, isoverdue=1 where ticket_id=%d" % TID)
    check("forced overdue is reported", call("GET", "/tickets/%d" % TID, token=TOK).data["is_overdue"] is True)
    r = call("POST", "/tickets/%d/sla" % TID, {"action": "restart", "base": sla_base(), "comment": "e2e"}, token=TOK)
    check("SLA restart clears overdue and sets a new deadline", r.status == 200 and r.data["sla"]["is_overdue"] is False and r.data["sla"]["due"]["sla"])
    sql("update ost_ticket set est_duedate=DATE_SUB(NOW(), INTERVAL 3 HOUR), duedate=NULL, isoverdue=1 where ticket_id=%d" % TID)
    r = call("POST", "/tickets/%d/sla" % TID, {"action": "clear_overdue", "base": sla_base()}, token=TOK)
    check("clear_overdue drops the flag (and the past deadline)", r.status == 200 and r.data["sla"]["is_overdue"] is False)
else:
    skip("SLA overdue restart / clear_overdue", "no DB access")
if TOK2:
    check("agent2: SLA operations need ticket.edit", call("POST", "/tickets/%d/sla" % TID, {"action": "restart", "base": sla_base()}, token=TOK2).status in (403, 404))

# ------------------------------------------------------------------ range, /me, documents, queues, PDF export
print("\n[downloads with Range, profile, documents, queues, ticket PDF]")
data = ("".join(chr(65 + i % 26) for i in range(5000))).encode()
b_, c_ = multipart("file", "range-%s.txt" % U, data)
RH = call("POST", "/files", token=TOK, raw=b_, ctype=c_).data["hash"]
def get_range(rng):
    rq = urllib.request.Request(BASE + "/files/" + RH, headers={"Authorization": "Bearer " + TOK, "Range": rng})
    try:
        with urllib.request.urlopen(rq) as r:
            return r.status, r.read(), dict(r.headers)
    except urllib.error.HTTPError as e:
        return e.code, e.read(), dict(e.headers)
st_, body_, h_ = get_range("bytes=0-9")
check("Range: first 10 bytes -> 206 + Content-Range", st_ == 206 and body_ == data[:10] and h_["Content-Range"] == "bytes 0-9/5000")
st_, body_, h_ = get_range("bytes=-5")
check("Range: suffix", st_ == 206 and body_ == data[-5:])
check("Range: unsatisfiable -> 416", get_range("bytes=9000-9999")[0] == 416)
a_ = get_range("bytes=0-2499")[1] + get_range("bytes=2500-")[1]
check("Range: reassembled bytes equal the original", a_ == data)

DU = str(uuid.uuid4())
dn = {"body": "Service document %s, first version" % U, "document": {"uuid": DU, "version": 1}}
r = call("POST", "/tickets/%d/notes" % TID, dn, token=TOK)
check("document note v1", r.status == 201 and r.data["document"]["uuid"] == DU)
DE = r.data["entry"]["id"]
r = call("POST", "/tickets/%d/notes" % TID, dn, token=TOK)   # new idempotency key: only the document index can answer
check("retry with another key adopts the existing note", r.status == 200 and r.data["adopted"] and r.data["entry"]["id"] == DE)
check("supersedes a version that does not exist -> 409", call("POST", "/tickets/%d/notes" % TID, {"body": "Service document second version", "document": {"uuid": DU, "version": 3, "supersedes": 2}}, token=TOK).status == 409)
r = call("POST", "/tickets/%d/notes" % TID, {"body": "Service document %s, corrected" % U, "document": {"uuid": DU, "version": 2, "supersedes": 1}}, token=TOK)
check("document v2 supersedes v1", r.status == 201)
r = call("GET", "/documents/" + DU, token=TOK)
check("GET /documents/{uuid} lists the chain", r.status == 200 and r.data["latest_version"] == 2 and [v["supersedes"] for v in r.data["versions"]] == [None, 1])
check("GET /tickets/{id}/documents", DU in [d["uuid"] for d in call("GET", "/tickets/%d/documents" % TID, token=TOK).data])

qs = call("GET", "/queues?counts=1", token=TOK)
check("queues: the agent's saved queues with counts", qs.status == 200 and len(qs.data) > 0 and all("count" in q for q in qs.data))
q0 = qs.data[0]
qt = call("GET", "/queues/%d/tickets?limit=100" % q0["id"], token=TOK)
check("queue tickets = its count (one page)", qt.status == 200 and len(qt.data) == min(q0["count"], 100) and qt.meta["queue"]["id"] == q0["id"], "%s vs %s" % (len(qt.data or []), q0["count"]))   # min(): this suite never deletes its data, so the queue outgrows one page
check("unknown queue -> 404", call("GET", "/queues/999999/tickets", token=TOK).status == 404)

rq = urllib.request.Request(BASE + "/tickets/%d/pdf?notes=1" % TID, headers={"Authorization": "Bearer " + TOK})
with urllib.request.urlopen(rq) as resp:
    pdfb = resp.read()
    check("ticket PDF export", resp.headers.get("Content-Type") == "application/pdf" and pdfb.startswith(b"%PDF") and pdfb.rstrip().endswith(b"%%EOF"))
check("PDF: paper is validated", call("GET", "/tickets/%d/pdf?paper=A9" % TID, token=TOK).status == 422)

# ------------------------------------------------------------------ tasks
print("\n[tasks]")
r = call("POST", "/tickets/%d/tasks" % TID, {"title": "E2E task " + U, "description": "do it"}, token=TOK)
check("create task", r.status == 201 and r.data["title"] == "E2E task " + U, r.raw[:120])
KID = r.data["id"]
check("tasks list needs state", call("GET", "/tasks", token=TOK).status == 422)
check("task listed for its ticket", KID in [t["id"] for t in call("GET", "/tickets/%d/tasks" % TID, token=TOK).data])
r = call("PUT", "/tasks/%d" % KID, {"title": "E2E task renamed", "base": {"title": "E2E task " + U}}, token=TOK)
check("PUT /tasks with base", r.status == 200 and r.data["applied"] and r.data["task"]["title"] == "E2E task renamed")
check("PUT /tasks stale base -> 409", call("PUT", "/tasks/%d" % KID, {"title": "x", "base": {"title": "nope"}}, token=TOK).status == 409)
r = call("POST", "/tasks/%d/notes" % KID, {"body": "task note"}, token=TOK)
check("task note", r.status == 201)
r = call("PATCH", "/tasks/%d/notes/%d" % (KID, r.data["entry"]["id"]), {"body": "task note fixed"}, token=TOK)
check("edit task note", r.status == 200 and r.data["applied"])
r = call("POST", "/tasks/%d/status" % KID, {"status": "closed", "base": "open"}, token=TOK)
check("close task", r.status == 200 and r.data["task"]["state"] == "closed")
check("task thread", len(call("GET", "/tasks/%d/thread" % KID, token=TOK).data) >= 2)

# D-22 regressions VR-18 (invalid TaskForm was a 500: formErrors was typed Form, TaskForm is a DynamicFormEntry) and
# VR-17 (due_at drifted +1 h in DST months when MySQL runs on a fixed 'CST' that the core guesses as America/Chicago, RC-14).
for label, extra in (("without description", {}), ("with empty description", {"description": ""})):
    r = call("POST", "/tickets/%d/tasks" % TID, dict({"title": "E2E nodesc " + U}, **extra), token=TOK)
    check("create task %s -> 422 on 'description', never 500" % label, r.status == 422 and r.err == "validation_failed" and r.json["error"].get("field") == "description", r.raw[:140])
yr = time.gmtime().tm_year + 1      # always in the future (the form rejects past dates); winter, DST and shoulder months
DUES = ["%d-%02d-%02dT18:00:00Z" % (yr, m, d) for m, d in ((1, 15), (3, 20), (7, 15), (10, 15), (12, 15))]
for iso in DUES:
    r = call("POST", "/tickets/%d/tasks" % TID, {"title": "E2E due " + U, "description": "x", "due_at": iso}, token=TOK)
    check("task due_at UTC round trip on create (%s)" % iso[:7], r.status == 201 and r.data["due"] == iso, r.raw[:140])
r = call("POST", "/tickets/%d/tasks" % TID, {"title": "E2E dueput " + U, "description": "x"}, token=TOK)
KD, prev = r.data["id"], None
for iso in DUES:
    r = call("PUT", "/tasks/%d" % KD, {"due_at": iso, "base": {"due_at": prev}}, token=TOK)
    check("task due_at UTC round trip on PUT (%s)" % iso[:7], r.status == 200 and r.data["task"]["due"] == iso, r.raw[:140])
    prev = iso
r = call("PUT", "/tasks/%d" % KD, {"due_at": None, "base": {"due_at": prev}}, token=TOK)
check("task due_at cleared with null", r.status == 200 and r.data["task"]["due"] is None, r.raw[:140])

# ------------------------------------------------------------------ sync / reports
print("\n[sync and reports]")
st, cur, seen = None, None, []
for _ in range(100):
    r = call("GET", "/sync/tickets?limit=50" + ("&cursor=" + cur if cur else "") + ("&state=" + st if st and not cur else ""), token=TOK)
    seen += [x["id"] for x in r.data]
    cur = r.meta.get("cursor")
    if not cur:
        st = r.meta["sync_state"]
        break
check("sync: full pass finishes with a sync_state", bool(st) and TID in seen)
time.sleep(6)
n = call("POST", "/tickets/%d/notes" % TID, {"body": "sync probe"}, token=TOK)
time.sleep(6)
delta, cur = [], None
for _ in range(20):
    r = call("GET", "/sync/tickets?limit=50&state=" + st + ("&cursor=" + cur if cur else ""), token=TOK)
    delta += [x["id"] for x in r.data]
    cur = r.meta.get("cursor")
    if not cur:
        break
check("sync: a new note is detected (ticket.updated does not move)", TID in delta)
# /sync/visible-ticket-ids is cursor-paged (1000 per page): follow next_cursor, or the new ticket falls on page 2 once the sandbox holds > 1000 tickets (FC-1 F-06, tooling fix)
vis, vcur = [], None
for _ in range(50):
    rv = call("GET", "/sync/visible-ticket-ids" + ("?cursor=" + urllib.parse.quote(vcur) if vcur else ""), token=TOK)
    vis += rv.data or []
    vcur = rv.meta.get("next_cursor")
    if not rv.meta.get("has_more") or not vcur:
        break
check("sync: visible ids (all pages)", TID in vis and len(vis) == len(set(vis)))
check("sync: organizations window", call("GET", "/sync/organizations?since=2020-01-01T00:00:00Z", token=TOK).status == 200)
check("sync: tasks", call("GET", "/sync/tasks", token=TOK).status == 200)
r = call("GET", "/reports/support?group_by=status", token=TOK)
check("report totals add up", r.status == 200 and r.meta["totals"]["created"] == sum(x["created"] for x in r.data))
check("report group_by is validated", call("GET", "/reports/support?group_by=x", token=TOK).status == 422)

# ------------------------------------------------------------------ permissions
print("\n[permissions with a Limited-Access agent]")
if TOK2:
    check("agent2: cannot close", call("POST", "/tickets/%d/status" % TID, {"status_id": 3, "base": 1}, token=TOK2).status in (403, 409, 404))
    r = call("GET", "/tickets/%d/actions" % TID, token=TOK2)
    if r.status == 200:
        check("agent2: actions explain the missing permission", r.data["actions"]["close"]["allowed"] is False and r.data["actions"]["close"].get("permission") == "ticket.close")
    else:
        skip("agent2 actions", "ticket not visible to agent2 (%s)" % r.status)
    check("agent2: cannot create contacts", call("POST", "/users", {"name": "x", "email": "a2-%s@example.com" % U, "org_id": None}, token=TOK2).status == 403)
    check("agent2: cannot rename an organization", call("PUT", "/organizations/%d" % OID, {"name": "x", "base": {"name": "y"}}, token=TOK2).status == 403)
else:
    skip("permission checks", "no AGENT2_USER/AGENT2_PASS")

# ------------------------------------------------------------------ hardening (2026-09-28)
print("\n[hardening: frozen surface, removed routes, mass assignment, directory, cross-department, budgets]")
FROZEN_ROUTES = 134   # 133 existing routes + the approved read-only semantic list-role resolver for M7
oa = json.load(open(os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "ost-workflow", "docs", "openapi.json")))
ops = [(m.upper(), p) for p, v in oa["paths"].items() for m in v if m in ("get", "post", "put", "patch", "delete")]
check("frozen surface: %d routes in the OpenAPI generated from the route table" % FROZEN_ROUTES, len(ops) == FROZEN_ROUTES, "found %d" % len(ops))
public = {("GET", "/ping"), ("POST", "/auth/login")}
noauth = []
for m, p in ops:
    if (m, p) in public:
        continue
    path = re.sub(r"\{[^}]+\}", lambda x: "0123456789abcdef0123456789abcdef0123" if "uuid" in x.group(0) else ("f" * 32 if "hash" in x.group(0) else "1"), p)
    st = call(m, path, {} if m in ("POST", "PUT", "PATCH") else None, key="auto").status
    if st != 401:
        noauth.append((m, p, st))
check("every route except ping/login answers 401 without a token", not noauth, str(noauth[:5]))

# removed from the public surface: they must stay gone (405 = the path exists with other verbs, 404 = it does not)
check("DELETE collaborator is not routable (PATCH active:false is the reversible way)", call("DELETE", "/tickets/%d/collaborators/1" % TID, token=TOK).status in (404, 405))
check("POST organization member is not routable (PUT /users/{id}/organization covers it)", call("POST", "/organizations/%d/members" % OID, {"user_id": UID}, token=TOK).status in (404, 405))
check("DELETE organization member is not routable", call("DELETE", "/organizations/%d/members/%d" % (OID, UID), token=TOK).status in (404, 405))
check("PATCH /me is not routable (the signature reaches customers by e-mail)", call("PATCH", "/me", {"signature": "x", "base": {"signature": ""}}, token=TOK).status in (404, 405))

# mass assignment: Ticket::create reads dept, assignee, status, autoresponse... from the same array as the form fields
for who, tk in (("admin", TOK), ("agent2", TOK2)):
    if not tk:
        continue
    for k, v in (("deptId", 2), ("staffId", 1), ("autorespond", 1), ("statusId", 3), ("slaId", 1), ("duedate", "2030-01-01 00:00:00")):
        r = call("POST", "/tickets", {"subject": "mass %s" % U, "message": "x", "topic_id": 1, "user_id": UID, "dept_id": 1, "fields": {k: v}}, token=tk)
        check("%s: core key '%s' inside `fields` -> 422" % (who, k), r.status == 422 and r.err == "validation_failed", "%s %s" % (r.status, r.raw[:100]))
check("`fields` must be an object", call("POST", "/tickets", {"subject": "m", "message": "x", "topic_id": 1, "user_id": UID, "fields": [1, 2]}, token=TOK).status == 422)
check("nothing was created by those attempts", not call("GET", "/search?q=mass%%20%s" % U, token=TOK).data)

st = call("GET", "/staff", token=TOK)
check("staff catalog does not publish login names (usernames)", st.status == 200 and st.data and all("username" not in a for a in st.data))
# the PDF-needs-text rule is the same on every path that can attach a file (tickets are covered above)
if minc:
    tk = call("POST", "/tickets/%d/tasks" % TID, {"title": "PDF rule " + U, "description": "x"}, token=TOK).data["id"]
    r = call("POST", "/tasks/%d/notes" % tk, {"body": "ver", "file_ids": [upload_pdf().data["file_id"]]}, token=TOK)
    check("task note: a PDF with too little text is refused", r.status == 422 and r.details.get("reason") == "attachment_needs_text", r.raw[:100])
    r = call("POST", "/tasks/%d/replies" % tk, {"body": "ver", "file_ids": [upload_pdf().data["file_id"]]}, token=TOK)
    check("task reply: a PDF with too little text is refused", r.status in (422, 403) and (r.status == 403 or r.details.get("reason") == "attachment_needs_text"), r.raw[:100])
    r = call("POST", "/tasks/%d/notes" % tk, {"body": "Signed report attached; please review it and reply.", "file_ids": [upload_pdf().data["file_id"]]}, token=TOK)
    check("task note: a PDF with explanatory text is accepted", r.status == 201, r.raw[:100])
    dv = str(uuid.uuid4())
    r = call("POST", "/tickets/%d/notes" % TID, {"body": "ok", "file_ids": [upload_pdf().data["file_id"]], "document": {"uuid": dv, "version": 1}}, token=TOK)
    check("versioned document note: the PDF rule applies too", r.status == 422 and r.details.get("reason") == "attachment_needs_text", r.raw[:100])

# e-mail defaults: nothing leaves the building unless the request says so (Mailpit is the sandbox SMTP sink)
def mail_count():
    try:
        with urllib.request.urlopen("http://127.0.0.1:8025/api/v1/messages", timeout=5) as x:
            return json.load(x)["total"]
    except Exception:
        return None
m0 = mail_count()
if m0 is None:
    skip("e-mail defaults", "Mailpit is not reachable on 127.0.0.1:8025")
else:
    et = call("POST", "/tickets", {"subject": "mail defaults %s" % U, "message": "m", "topic_id": 1, "user_id": UID, "dept_id": 1}, token=TOK).data["id"]
    call("POST", "/tickets/%d/notes" % et, {"body": "internal, no alert"}, token=TOK)
    call("POST", "/tickets/%d/assignment" % et, {"assignee": {"type": "staff", "id": 2}, "base": None}, token=TOK)
    call("POST", "/tickets/%d/transfer" % et, {"dept_id": 2, "base": 1}, token=TOK)
    call("POST", "/tickets/%d/referrals" % et, {"target": "agent", "id": 2}, token=TOK)
    call("POST", "/tickets/%d/replies" % et, {"body": "public but notify none", "notify": "none"}, token=TOK)
    tk = call("POST", "/tickets/%d/tasks" % et, {"title": "mail defaults task", "description": "x"}, token=TOK).data["id"]
    call("POST", "/tasks/%d/notes" % tk, {"body": "task note"}, token=TOK)
    call("POST", "/tasks/%d/assignment" % tk, {"assignee": {"type": "staff", "id": 2}, "base": None}, token=TOK)
    time.sleep(2)
    check("no e-mail from create, note, assignment, transfer, referral, notify-none reply, task, task note, task assignment", mail_count() == m0, "%s -> %s" % (m0, mail_count()))
    et2 = call("POST", "/tickets", {"subject": "mail control %s" % U, "message": "m", "topic_id": 1, "user_id": UID, "dept_id": 1}, token=TOK).data["id"]
    call("POST", "/tickets/%d/assignment" % et2, {"assignee": {"type": "staff", "id": 2}, "base": None, "alert": True}, token=TOK)
    time.sleep(2)
    check("positive control: alert:true does send", (mail_count() or 0) > m0)

if TOK2:
    # directory: a normal agent finds contacts while opening tickets, it cannot dump them
    NU = call("POST", "/users", {"name": "Hardening " + U, "email": "hard-%s@example.com" % U, "org_id": None}, token=TOK).data["id"]
    OID2 = call("POST", "/organizations", {"name": "Hardening Org " + U}, token=TOK).data["id"]
    check("agent2: a contact on no visible ticket -> 403", call("GET", "/users/%d" % NU, token=TOK2).status == 403)
    check("agent2: ... nor its tickets, fields, notes", all(call("GET", "/users/%d/%s" % (NU, p), token=TOK2).status == 403 for p in ("tickets", "fields", "notes")))
    check("agent2: ... nor a note on it", call("POST", "/users/%d/notes" % NU, {"body": "x"}, token=TOK2).status == 403)
    check("agent2: an organization on no visible ticket -> 403", call("GET", "/organizations/%d" % OID2, token=TOK2).status == 403)
    check("agent2: browsing users or organizations without a query -> 403", call("GET", "/users", token=TOK2).status == 403 and call("GET", "/organizations", token=TOK2).status == 403)
    check("agent2: a 2-letter search -> 422", call("GET", "/users?q=ab", token=TOK2).status == 422)
    r = call("GET", "/users?q=e2e&limit=100", token=TOK2)
    check("agent2: search answers are autocomplete-sized and unpaged", r.status == 200 and len(r.data) <= 10 and not r.meta.get("next_cursor"), r.raw[:100])
    check("agent2: paging the directory -> 422", call("GET", "/users?q=e2e&cursor=eyJpIjoxfQ", token=TOK2).status == 422)
    check("agent2: /sync/users and /sync/organizations -> 403", call("GET", "/sync/users", token=TOK2).status == 403 and call("GET", "/sync/organizations", token=TOK2).status == 403)
    check("admin (user.dir): both feeds still work", call("GET", "/sync/users?limit=1", token=TOK).status == 200 and call("GET", "/sync/organizations?limit=1", token=TOK).status == 200)
    check("agent2: match by organization id answers 'none' for a foreign one", call("GET", "/match/organization?id=%d" % OID2, token=TOK2).data["classification"] == "none")
    check("admin: the same id is 'safe'", call("GET", "/match/organization?id=%d" % OID2, token=TOK).data["classification"] == "safe")
    r = call("POST", "/tickets", {"subject": "visible %s" % U, "message": "m", "topic_id": 1, "user_id": UID, "dept_id": 1}, token=TOK2)
    check("agent2 opens a ticket for a known contact", r.status == 201, r.raw[:120])
    check("agent2: the contact and organization of a ticket it can see -> 200", call("GET", "/users/%d" % UID, token=TOK2).status == 200 and call("GET", "/organizations/%d" % OID, token=TOK2).status == 200)

    # cross-department: a ticket of another department, its file and its document
    r = call("POST", "/tickets", {"subject": "sales only %s" % U, "message": "m", "topic_id": 1, "user_id": UID, "dept_id": 2}, token=TOK)
    ST = r.data["id"]
    b_, c_ = multipart("file", "sales-%s.txt" % U, b"sales secret " * 100)
    SH = call("POST", "/files", token=TOK, raw=b_, ctype=c_).data
    SDU = str(uuid.uuid4())
    r = call("POST", "/tickets/%d/notes" % ST, {"body": "Sales document, first version", "file_ids": [SH["file_id"]], "document": {"uuid": SDU, "version": 1}}, token=TOK)
    check("admin: note with file and document identity on the other department's ticket", r.status == 201, r.raw[:120])
    check("agent2: that ticket, its PDF and its documents -> 403", all(call("GET", "/tickets/%d%s" % (ST, p), token=TOK2).status == 403 for p in ("", "/pdf", "/documents", "/activity")))
    check("agent2: document uuid of another department -> 404 (no existence leak)", call("GET", "/documents/" + SDU, token=TOK2).status == 404)

    def dl(hash_, tk, extra=None):
        h = {"Range": "bytes=0-9"} if extra == "range" else {}
        if tk:
            h["Authorization"] = "Bearer " + tk
        q = "?inline=1" if extra == "inline" else ""
        try:
            with urllib.request.urlopen(urllib.request.Request(BASE + "/files/" + hash_ + q, headers=h)) as x:
                return x.status
        except urllib.error.HTTPError as e:
            return e.code
    check("download: the owner-department agent gets the file", dl(SH["hash"], TOK) in (200, 206))
    check("download: agent2 -> 403 whole, with Range, and inline", [dl(SH["hash"], TOK2), dl(SH["hash"], TOK2, "range"), dl(SH["hash"], TOK2, "inline")] == [403, 403, 403])
    check("download: no token -> 401 whole and with Range", [dl(SH["hash"], None), dl(SH["hash"], None, "range")] == [401, 401])
    check("an unknown hash and a foreign one look the same to Range (404 vs 403 never reveals content)", dl("f" * 32, TOK2, "range") == 404)
    b_, c_ = multipart("file", "mine-%s.txt" % U, b"agent2 private upload " + U.encode())
    MH = call("POST", "/files", token=TOK2, raw=b_, ctype=c_).data["hash"]
    check("an unattached upload is readable by its uploader only", dl(MH, TOK2) == 200 and dl(MH, TOK) == 403 and dl(MH, TOK2, "range") == 206)
    check("a document version cannot be planted on a second ticket", call("POST", "/tickets/%d/notes" % TID, {"body": "Sales document, first version", "document": {"uuid": SDU, "version": 1}}, token=TOK).status == 409)

    # D-22 requalification: the core enforces the department of a task only while it is OPEN (Task::checkStaffPerm), so a
    # CLOSED task of another department was readable, downloadable and writable by a Limited-Access agent.
    CT = call("POST", "/tickets/%d/tasks" % ST, {"title": "sales task %s" % U, "description": "sales only"}, token=TOK).data["id"]
    b_, c_ = multipart("file", "sales-task-%s.txt" % U, b"sales task secret " + uuid.uuid4().hex.encode())   # unique: the core de-duplicates files by content
    CH = call("POST", "/files", token=TOK, raw=b_, ctype=c_).data
    check("admin: a note with a file on a task of the other department", call("POST", "/tasks/%d/notes" % CT, {"body": "internal", "file_ids": [CH["file_id"]]}, token=TOK).status == 201)
    check("agent2: an OPEN task of another department -> 403 (detail, thread, file)", [call("GET", "/tasks/%d" % CT, token=TOK2).status, call("GET", "/tasks/%d/thread" % CT, token=TOK2).status, dl(CH["hash"], TOK2)] == [403, 403, 403])
    check("admin closes that task", call("POST", "/tasks/%d/status" % CT, {"status": "closed", "base": "open"}, token=TOK).status == 200)
    got = [call("GET", "/tasks/%d" % CT, token=TOK2).status, call("GET", "/tasks/%d/thread" % CT, token=TOK2).status, dl(CH["hash"], TOK2), dl(CH["hash"], TOK2, "range"),
           call("POST", "/tasks/%d/notes" % CT, {"body": "x"}, token=TOK2).status, call("POST", "/tasks/%d/status" % CT, {"status": "open", "base": "closed"}, token=TOK2).status]
    check("agent2: a CLOSED task of another department -> 403 (detail, thread, file, Range, note, status)", got == [403] * 6, str(got))
    check("agent2: ... and it is not listed", CT not in [t["id"] for t in call("GET", "/tasks?state=all&limit=200", token=TOK2).data])
    check("admin still reads the closed task and its file", call("GET", "/tasks/%d" % CT, token=TOK).status == 200 and dl(CH["hash"], TOK) in (200, 206))
    OT = call("POST", "/tickets", {"subject": "own dept %s" % U, "message": "m", "topic_id": 1, "user_id": UID, "dept_id": 1}, token=TOK2).data["id"]
    OK_ = call("POST", "/tickets/%d/tasks" % OT, {"title": "own task %s" % U, "description": "own dept"}, token=TOK).data["id"]
    call("POST", "/tasks/%d/status" % OK_, {"status": "closed", "base": "open"}, token=TOK)
    check("agent2: a closed task of its OWN department is still readable (control)", call("GET", "/tasks/%d" % OK_, token=TOK2).status == 200 and call("GET", "/tasks/%d/thread" % OK_, token=TOK2).status == 200)

    # Permanent positive controls of the same fix (D-22): the SAME operations that are denied above must keep working for the
    # authorized department, on an OPEN task and on a CLOSED one. Without them a policy that denied everything would pass.
    OP = call("POST", "/tickets/%d/tasks" % OT, {"title": "own open task %s" % U, "description": "own dept"}, token=TOK).data["id"]
    b_, c_ = multipart("file", "own-task-%s.txt" % U, b"own task file " + uuid.uuid4().hex.encode())
    OH = call("POST", "/files", token=TOK, raw=b_, ctype=c_).data
    check("admin: a note with a file on an own-department task", call("POST", "/tasks/%d/notes" % OP, {"body": "internal", "file_ids": [OH["file_id"]]}, token=TOK).status == 201)
    got = [call("GET", "/tasks/%d" % OP, token=TOK2).status, call("GET", "/tasks/%d/thread" % OP, token=TOK2).status, dl(OH["hash"], TOK2), dl(OH["hash"], TOK2, "range")]
    check("agent2: an OPEN task of its own department -> detail, thread, file, Range all readable", got[:3] == [200, 200, 200] and got[3] == 206, str(got))
    check("agent2: ... and it can write a note on it", call("POST", "/tasks/%d/notes" % OP, {"body": "agent2 note %s" % U}, token=TOK2).status == 201)
    check("agent2: ... and it is listed", OP in [t["id"] for t in call("GET", "/tasks?state=all&limit=200", token=TOK2).data])
    call("POST", "/tasks/%d/status" % OP, {"status": "closed", "base": "open"}, token=TOK)
    got = [call("GET", "/tasks/%d" % OP, token=TOK2).status, call("GET", "/tasks/%d/thread" % OP, token=TOK2).status, dl(OH["hash"], TOK2), dl(OH["hash"], TOK2, "range")]
    check("agent2: the same task once CLOSED (own department) -> detail, thread, file, Range still readable", got[:3] == [200, 200, 200] and got[3] == 206, str(got))
    check("agent2: ... and a note can still be written on it", call("POST", "/tasks/%d/notes" % OP, {"body": "agent2 closed-task note %s" % U}, token=TOK2).status == 201)

    # hourly budgets: seeded so the test is deterministic, cleaned afterwards
    hour = time.strftime("%Y%m%d%H", time.gmtime())
    def seed(staff, bucket, n):
        sql("insert into ost_workflow_idempotency set kind='throttle', staff_id=%d, idem_key='th:%s:%s', status='done', counter=%d, created=NOW(), expires=DATE_ADD(NOW(), INTERVAL 2 HOUR) on duplicate key update counter=%d" % (staff, bucket, hour, n, n))
    def unseed(staff, bucket):
        sql("delete from ost_workflow_idempotency where kind='throttle' and staff_id=%d and idem_key='th:%s:%s'" % (staff, bucket, hour))
    if sql("select 1") is not None:
        seed(2, "lookup", 100000)
        r = call("GET", "/users?q=e2e", token=TOK2)
        check("budget: an agent without directory access is limited on lookups (429 + Retry-After)", r.status == 429 and "Retry-After" in r.headers and r.err == "rate_limited")
        check("budget: directory agents are not charged", call("GET", "/users?q=e2e", token=TOK).status == 200)
        unseed(2, "lookup")
        check("budget: the window frees the agent again", call("GET", "/users?q=e2e", token=TOK2).status == 200)
        seed(2, "upload", 100000)
        b_, c_ = multipart("file", "x-%s.txt" % U, b"x")
        check("budget: uploads", call("POST", "/files", token=TOK2, raw=b_, ctype=c_).status == 429)
        unseed(2, "upload")
        seed(1, "pdf", 100000)
        check("budget: ticket PDFs", call("GET", "/tickets/%d/pdf" % TID, token=TOK).status == 429)
        unseed(1, "pdf")
        seed(1, "mail", 100000)
        K = str(uuid.uuid4())
        rb = {"body": "budget probe", "notify": "user"}
        r = call("POST", "/tickets/%d/replies" % TID, rb, token=TOK, key=K)
        check("budget: customer e-mail (reply with notify) -> 429", r.status == 429 and r.err == "rate_limited")
        check("budget: notify none is not e-mail and is not charged", call("POST", "/tickets/%d/replies" % TID, {"body": "no mail here", "notify": "none"}, token=TOK).status == 201)
        unseed(1, "mail")
        r = call("POST", "/tickets/%d/replies" % TID, rb, token=TOK, key=K)
        check("budget: a 429 is not replayed for the same Idempotency-Key", r.status == 201 and "Idempotent-Replayed" not in r.headers, "%s %s" % (r.status, r.raw[:100]))
    else:
        skip("hourly budgets", "no DB access")
else:
    skip("directory, cross-department and budget checks", "no AGENT2_USER/AGENT2_PASS")

# ------------------------------------------------------------------ MSOLIS decisions PC-S1..PC-S5 (2026-09-28)
print("\n[PC-S2..PC-S5: SLA curbs, contact e-mail, organization sharing, token lifetime]")
# PC-S2: disable / clear_overdue need the department manager (agent3 = Expanded Access: has ticket.edit, is not a manager)
if TOK3 and sql("select 1") is not None:
    ET = call("POST", "/tickets", {"subject": "sla curbs %s" % U, "message": "m", "topic_id": 1, "user_id": UID, "dept_id": 1}, token=TOK).data["id"]
    def eb():
        x = call("GET", "/tickets/%d/sla" % ET, token=TOK).data
        return {"sla_id": (x["plan"] or {}).get("id"), "due": x["due"]["effective"]}
    call("POST", "/tickets/%d/sla" % ET, {"action": "enable", "sla_id": 1, "base": eb()}, token=TOK)
    sql("update ost_ticket set est_duedate=DATE_SUB(NOW(), INTERVAL 3 HOUR), duedate=NULL, isoverdue=1 where ticket_id=%d" % ET)
    try:
        r = call("POST", "/tickets/%d/sla" % ET, {"action": "disable", "base": eb()}, token=TOK3)
        check("PC-S2: an agent with ticket.edit who is not the manager cannot disable the SLA", r.status == 403 and r.details.get("reason") == "department_manager_required", r.raw[:120])
        r = call("POST", "/tickets/%d/sla" % ET, {"action": "clear_overdue", "base": eb()}, token=TOK3)
        check("PC-S2: ... nor clear the overdue flag", r.status == 403 and r.details.get("reason") == "department_manager_required", r.raw[:120])
        x = call("GET", "/tickets/%d/sla" % ET, token=TOK3)
        check("PC-S2: nothing changed, and /sla does not offer those actions to that agent", x.data["plan"] is not None and x.data["is_overdue"] is True and x.meta["actions"]["disable"] is False and x.meta["actions"]["clear_overdue"] is False)
        r = call("POST", "/tickets/%d/sla" % ET, {"action": "extend", "hours": 2, "base": eb()}, token=TOK3)
        check("PC-S2: restart/extend/enable stay with ticket.edit (extend works for that agent)", r.status == 200 and r.data["applied"], r.raw[:120])
        sql("update ost_department set manager_id=3 where id=1")
        sql("update ost_ticket set est_duedate=DATE_SUB(NOW(), INTERVAL 3 HOUR), duedate=NULL, isoverdue=1 where ticket_id=%d" % ET)
        x = call("GET", "/tickets/%d/sla" % ET, token=TOK3)
        check("PC-S2: the department manager is offered both", x.meta["actions"]["disable"] is True and x.meta["actions"]["clear_overdue"] is True)
        r = call("POST", "/tickets/%d/sla" % ET, {"action": "clear_overdue", "base": eb()}, token=TOK3)
        check("PC-S2: the department manager can clear the overdue flag", r.status == 200 and r.data["sla"]["is_overdue"] is False, r.raw[:120])
        r = call("POST", "/tickets/%d/sla" % ET, {"action": "disable", "base": eb(), "comment": "manager decision"}, token=TOK3)
        check("PC-S2: the department manager can disable the SLA", r.status == 200 and r.data["sla"]["plan"] is None, r.raw[:120])
        notes = [e["body_text"] for e in call("GET", "/tickets/%d/activity?limit=200" % ET, token=TOK).data if e.get("kind") == "entry" and e.get("audience") == "internal"]
        check("PC-S2: the audit note is kept (internal SLA note with the actor's reason)", any("SLA disabled" in n and "manager decision" in n for n in notes) and any("Overdue flag cleared" in n for n in notes), str(notes)[:200])
    finally:
        sql("update ost_department set manager_id=0 where id=1")
else:
    skip("PC-S2 manager checks", "needs agent3 (AGENT3_USER/AGENT3_PASS or <fixtures>/agent3.env) and DB access")

# PC-S3: the e-mail address is not editable through the API
before = call("GET", "/users/%d" % UID, token=TOK).data
for body in ({"email": "changed-%s@example.com" % U, "base": {"email": before["email"]}},
             {"fields": {"email": "changed-%s@example.com" % U}, "base": {"email": before["email"]}},
             {"EMAIL": "changed-%s@example.com" % U, "base": {"email": before["email"]}}):
    r = call("PATCH", "/users/%d" % UID, body, token=TOK)
    check("PC-S3: PATCH /users/{id} refuses the e-mail (%s)" % ("fields" if "fields" in body else "top level"), r.status == 422 and r.err == "validation_failed" and r.details.get("reason") == "not_editable", r.raw[:120])
after = call("GET", "/users/%d" % UID, token=TOK).data
check("PC-S3: the contact is unchanged", after["email"] == before["email"] and after["emails"] == before["emails"])
r = call("PATCH", "/users/%d" % UID, {"name": before["name"] + " b", "base": {"name": before["name"]}}, token=TOK)
check("PC-S3: name and phone still editable", r.status == 200 and r.data["applied"])
call("PATCH", "/users/%d" % UID, {"name": before["name"], "base": {"name": before["name"] + " b"}}, token=TOK)

# PC-S4: sharing and the collaborator/assignment flags are not editable; manager/domain/primary contacts are
o0 = call("GET", "/organizations/%d" % OID, token=TOK).data
for body in ({"sharing": "everybody", "base": {"sharing": "primary"}}, {"flags": {"collab_all_members": True}, "base": {"flags": {"collab_all_members": False}}}):
    r = call("PATCH", "/organizations/%d/profile" % OID, body, token=TOK)
    check("PC-S4: profile refuses '%s'" % list(body)[0], r.status == 422 and r.details.get("reason") == "not_editable", r.raw[:120])
newdom = "e2e2-%s.example.com" % U
r = call("PATCH", "/organizations/%d/profile" % OID, {"domain": newdom, "base": {"domain": o0["domain"]}}, token=TOK)
o1 = call("GET", "/organizations/%d" % OID, token=TOK).data
check("PC-S4: domain still editable and every flag is preserved by the save", r.status == 200 and r.data["applied"] and o1["domain"] == newdom and o1["flags"] == o0["flags"], r.raw[:160])

# PC-S5: device tokens live 14 days
import base64
def payload(tok):
    part = tok.split(".")[0]
    return json.loads(base64.urlsafe_b64decode(part + "=" * (-len(part) % 4)))
pl = payload(TOK)
check("PC-S5: token lifetime is 14 days", pl["exp"] - pl["iat"] == 14 * 86400, str(pl["exp"] - pl["iat"]))

# ------------------------------------------------------------------ crash recovery (needs DB access)
print("\n[idempotency crash recovery]")
if sql("select 1") is not None:
    K = str(uuid.uuid4())
    B = {"subject": "E2E adopt %s" % U, "message": "crash", "user_id": UID, "topic_id": 1}
    r = call("POST", "/tickets", B, token=TOK, key=K)
    AT = r.data["id"]
    sql("update ost_workflow_idempotency set status='in_progress', lease_until=DATE_SUB(NOW(), INTERVAL 5 MINUTE), resource_type=NULL, resource_id=NULL, response=NULL, http_code=0 where idem_key='%s'" % K)
    r = call("POST", "/tickets", B, token=TOK, key=K)
    check("expired lease + marker -> adopts the created ticket", r.status == 200 and r.data.get("adopted") and str(r.data["resource_id"]) == str(AT))
    K2 = str(uuid.uuid4())
    B2 = {"subject": "never created %s" % U, "message": "x", "user_id": UID, "topic_id": 1}
    import hashlib
    h = hashlib.sha256(json.dumps(B2).encode()).hexdigest()
    # the body hash is over the raw JSON bytes the client sent: send exactly those bytes
    raw2 = json.dumps(B2).encode()
    h = hashlib.sha256(raw2).hexdigest()
    sql("insert into ost_workflow_idempotency set kind='idem', staff_id=1, idem_key='%s', method='POST', route='POST /tickets', body_hash='%s', status='in_progress', lease_until=DATE_SUB(NOW(), INTERVAL 5 MINUTE), created=NOW(), expires=DATE_ADD(NOW(), INTERVAL 30 DAY)" % (K2, h))
    r = call("POST", "/tickets", token=TOK, key=K2, raw=raw2)
    check("expired lease, nothing provable -> needs_review", r.status == 409 and r.err == "needs_review")
    K3 = str(uuid.uuid4())
    raw3 = json.dumps({"subject": "in flight %s" % U, "message": "x", "user_id": UID, "topic_id": 1}).encode()
    sql("insert into ost_workflow_idempotency set kind='idem', staff_id=1, idem_key='%s', method='POST', route='POST /tickets', body_hash='%s', status='in_progress', lease_until=DATE_ADD(NOW(), INTERVAL 60 SECOND), created=NOW(), expires=DATE_ADD(NOW(), INTERVAL 30 DAY)" % (K3, hashlib.sha256(raw3).hexdigest()))
    r = call("POST", "/tickets", token=TOK, key=K3, raw=raw3)
    check("live lease -> 409 in_progress + Retry-After", r.status == 409 and r.err == "in_progress" and "Retry-After" in r.headers)
else:
    skip("crash recovery", "no DB access (prod-sandbox/sql.sh needs ~/development/ost-sandbox/credentials.env)")

# ------------------------------------------------------------------ MSOLIS final security decisions (2026-09-28)
print("\n[final security decisions: 2FA A1, dept_id, actor, cid:/data:]")


def mp_total(query=None):
    """Messages in the Mailpit sandbox sink (all, or those matching a search); None when Mailpit is not reachable."""
    try:
        url = "http://127.0.0.1:8025/api/v1/" + ("search?query=" + urllib.parse.quote(query) if query else "messages?limit=1")
        with urllib.request.urlopen(url, timeout=5) as x:
            d = json.loads(x.read())
        return d.get("messages_count", d.get("total"))
    except Exception:
        return None


def n_rows(table):
    r = sql("select count(*) from " + table)
    return int(r[0]) if r else None


def file_status(hash_, tk):
    try:
        with urllib.request.urlopen(urllib.request.Request(BASE + "/files/" + hash_, headers={"Authorization": "Bearer " + tk})) as x:
            return x.status
    except urllib.error.HTTPError as e:
        return e.code


# --- 2FA A1: an agent with a second factor cannot sign in through the API (fail closed, before process()) ---
A2F = (os.environ.get("AGENT2FA_USER"), os.environ.get("AGENT2FA_PASS"))
if not A2F[0]:
    e2f = load_env(os.path.join(SCR_DIR, "agent2fa.env"))
    A2F = (e2f.get("AGENT2FA_USER"), e2f.get("AGENT2FA_PASS"))
if A2F[0]:
    otp_before = mp_total("to:%s@example.com" % A2F[0])
    r = call("POST", "/auth/login", {"username": A2F[0], "password": A2F[1]}, key=None)
    check("2FA A1: correct credentials of an agent with a second factor -> 403 two_factor_required", r.status == 403 and r.err == "two_factor_required", r.raw[:120])
    check("2FA A1: ... no token and no session data in the answer", '"token"' not in r.raw and r.data is None, r.raw[:120])
    r = call("POST", "/auth/login", {"username": A2F[0] + "@example.com", "password": A2F[1]}, key=None)
    check("2FA A1: ... signing in by e-mail address is refused the same way", r.status == 403 and r.err == "two_factor_required", r.raw[:120])
    check("2FA A1: ... an unauthenticated call is still 401 (nothing was granted)", call("GET", "/me", key=None).status == 401)
    if otp_before is not None:
        check("2FA A1: ... refused BEFORE process(): no OTP e-mail was sent", mp_total("to:%s@example.com" % A2F[0]) == otp_before)
    else:
        skip("2FA A1 no-OTP check", "Mailpit is not reachable on 127.0.0.1:8025")
    if TOK2:
        check("2FA A1 control: an agent WITHOUT a second factor still gets a token and reads /me", call("GET", "/me", token=TOK2).status == 200)
        r = call("POST", "/auth/login", {"username": A2[0], "password": A2[1] + "x"}, key=None)
        check("2FA A1 control: a wrong password of a normal agent is still 401", r.status == 401)
else:
    skip("2FA A1", "needs the 2FA fixture agent (agent2fa.env; see E2E-Fixtures.md)")

# --- D1: POST /tickets only in a department the agent really has access to (the set GET /departments lists) ---
if TOK2:
    subj = "dept-restrict %s" % U
    body = {"message": "m", "topic_id": 1, "user_id": UID}
    r = call("POST", "/tickets", dict(body, subject=subj + " own", dept_id=1), token=TOK2)
    check("D1: agent2 creates a ticket in its own department (Support) -> 201", r.status == 201, r.raw[:120])
    r = call("POST", "/tickets", dict(body, subject=subj + " sales", dept_id=2), token=TOK2)
    check("D1: ... in a department it cannot access (Sales) -> 403 forbidden, reason department_not_accessible",
          r.status == 403 and r.err == "forbidden" and r.details.get("reason") == "department_not_accessible" and r.details.get("dept_id") == 2, r.raw[:160])
    check("D1: ... and the rejected ticket was NOT created", (sql("select count(*) from ost_ticket__cdata where subject = '%s sales'" % subj) or ["?"])[0] == "0")
    mine = {d["id"] for d in call("GET", "/departments", token=TOK2).data}
    check("D1: the accessible set is what GET /departments lists (Sales is not in it)", 1 in mine and 2 not in mine, str(mine))
    ft = next((t["id"] for t in call("GET", "/topics", token=TOK).data if t.get("dept_id") and t["dept_id"] not in mine), None)
    if ft:
        r = call("POST", "/tickets", dict(body, subject=subj + " topic", topic_id=ft), token=TOK2)
        check("D1: a help topic that routes to a department it cannot access -> 403 as well (same rule, no bypass by topic)",
              r.status == 403 and r.details.get("reason") == "department_not_accessible", r.raw[:160])
    else:
        skip("D1 topic routing", "no help topic routes to a department outside agent2's set")
    check("D1: an unknown department is still 422", call("POST", "/tickets", dict(body, subject=subj + " x", dept_id=99999), token=TOK2).status == 422)
    r = call("POST", "/tickets", dict(body, subject=subj + " admin-sales", dept_id=2), token=TOK)
    check("D1 control: an administrator (access to every department) creates in Sales -> 201", r.status == 201, r.raw[:120])
else:
    skip("D1 department restriction", "needs AGENT2_USER/AGENT2_PASS")

# --- D2: last_change.actor is the normalized actor {type,id,name}; the login never appears ---
def stale_conflict(actor_token):
    tk = call("POST", "/tickets", {"subject": "actor privacy %s" % uuid.uuid4().hex[:6], "message": "m", "topic_id": 1, "user_id": UID, "dept_id": 1}, token=TOK).data["id"]
    call("PATCH", "/tickets/%d/fields/priority" % tk, {"value": 3, "base": 2}, token=actor_token)      # the change that becomes last_change
    return call("PATCH", "/tickets/%d/fields/priority" % tk, {"value": 4, "base": 2}, token=TOK)        # stale base -> 409


r = stale_conflict(TOK)
lc = r.details.get("last_change") or {}
act = lc.get("actor")
check("D2: a 409 carries last_change.actor as {type:'staff', id, name}", r.status == 409 and isinstance(act, dict) and act.get("type") == "staff" and isinstance(act.get("id"), int) and act.get("name"), r.raw[:200])
check("D2: ... with no login/username field and staff_id kept for the person cache", set(act or {}) == {"type", "id", "name"} and "staff_id" in lc, r.raw[:200])
check("D2: ... the admin login never appears in the response (unless it is also the display name)", ADMIN[0] not in r.raw or (act or {}).get("name") == ADMIN[0])
if TOK3:
    r = stale_conflict(TOK3)
    act = (r.details.get("last_change") or {}).get("actor") or {}
    check("D2: another agent's change -> actor names that agent, not its login ('%s')" % A3[0], r.status == 409 and act.get("type") == "staff" and act.get("name") and A3[0] not in r.raw, r.raw[:200])
else:
    skip("D2 second actor", "needs agent3")

# --- D3/D4: cid: and data: (and file.php?key= URLs, which the core turns into cid:) are rejected in every free-text field ---
def data_uri():
    return '<p>x</p><img src="data:image/png;base64,%s">' % base64.b64encode(("PNG-e2e-%s-%s" % (U, uuid.uuid4().hex[:8])).encode()).decode()


def fresh_ticket(tk=TOK):
    return call("POST", "/tickets", {"subject": "inline %s" % uuid.uuid4().hex[:6], "message": "m", "topic_id": 1, "user_id": UID, "dept_id": 1}, token=tk).data["id"]


if sql("select 1") is None:
    skip("D3/D4 inline content", "no DB access (attachment counts need prod-sandbox/sql.sh)")
else:
    b_, c_ = multipart("file", "cid-private-%s.txt" % U, b"admin private upload for the cid probe " + uuid.uuid4().hex.encode())
    PF = call("POST", "/files", raw=b_, ctype=c_, token=TOK).data
    cid_html = '<p>x</p><img src="cid:%s">' % PF["hash"]
    url_html = '<p>x</p><img src="https://host.example/scp/file.php?key=%s&expires=1&signature=a">' % PF["hash"]
    T_OWN = fresh_ticket(TOK2) if TOK2 else fresh_ticket()
    tk2 = TOK2 or TOK
    att0, file0, mail0 = n_rows("ost_attachment"), n_rows("ost_file"), mp_total()
    if TOK2:
        check("D3: before the attempt, another agent's unattached upload is 403 for agent2", file_status(PF["hash"], TOK2) == 403)
    r = call("POST", "/tickets/%d/notes" % T_OWN, {"body": cid_html, "body_format": "html"}, token=tk2)
    check("D3: a foreign cid:<key> in an HTML note -> 422 validation_failed / inline_cid_not_supported", r.status == 422 and r.err == "validation_failed" and r.details.get("reason") == "inline_cid_not_supported" and (r.json["error"].get("field") == "body"), r.raw[:200])
    if TOK2:
        check("D3: ... and the foreign file is still 403 for agent2 (accessibility unchanged)", file_status(PF["hash"], TOK2) == 403)
    r = call("POST", "/tickets/%d/notes" % T_OWN, {"body": url_html, "body_format": "html"}, token=tk2)
    check("D3: a .../file.php?key=<key> URL (the core turns it into cid:) -> 422 inline_cid_not_supported", r.status == 422 and r.details.get("reason") == "inline_cid_not_supported", r.raw[:200])
    for label, h in (("single-quoted", '<img src=\'cid:%s\'>' % PF["hash"]), ("upper-case", '<IMG SRC="CID:%s">' % PF["hash"]),
                     ("entity-encoded", '<img src="c&#105;d:%s">' % PF["hash"]), ("unquoted", '<img src=cid:%s>' % PF["hash"])):
        r = call("POST", "/tickets/%d/notes" % T_OWN, {"body": h, "body_format": "html"}, token=tk2)
        check("D3: obfuscated cid: (%s) -> 422" % label, r.status == 422 and r.details.get("reason") == "inline_cid_not_supported", r.raw[:160])
    r = call("POST", "/tickets/%d/notes" % T_OWN, {"body": data_uri(), "body_format": "html"}, token=tk2)
    check("D4: a data: image in an HTML note -> 422 validation_failed / inline_data_not_supported", r.status == 422 and r.err == "validation_failed" and r.details.get("reason") == "inline_data_not_supported", r.raw[:200])
    for label, h in (("declared MIME", '<img src="data:application/x-msdownload;base64,QUJDRA==">'), ("single-quoted", "<img src='data:text/plain,hi'>"),
                     ("entity-encoded", '<img src="da&#116;a:image/png;base64,QUJDRA==">')):
        r = call("POST", "/tickets/%d/notes" % T_OWN, {"body": h, "body_format": "html"}, token=tk2)
        check("D4: data: variant (%s) -> 422" % label, r.status == 422 and r.details.get("reason") == "inline_data_not_supported", r.raw[:160])
    check("D3/D4: no attachment, no stored file and no e-mail were produced by any of those attempts",
          (n_rows("ost_attachment"), n_rows("ost_file")) == (att0, file0) and (mail0 is None or mp_total() == mail0), "%s/%s -> %s/%s" % (att0, file0, n_rows("ost_attachment"), n_rows("ost_file")))

    # text mode: the core matches these patterns on the SANITIZED body, where the escaping of plain text is already undone
    # (found while writing this test: a literal "cid:<key>" in a TEXT note still attached the file), so text is guarded too
    for label, lit, reason in (("cid:", cid_html, "inline_cid_not_supported"), ("data:", data_uri(), "inline_data_not_supported"), ("file.php?key=", url_html, "inline_cid_not_supported")):
        r = call("POST", "/tickets/%d/notes" % T_OWN, {"body": lit + "<b>literal</b>", "body_format": "text"}, token=tk2)
        check("D3/D4: body_format=text (the default) carrying a literal %s reference -> 422 %s, not an attachment" % (label, reason), r.status == 422 and r.details.get("reason") == reason, r.raw[:180])
    check("D3/D4: ... and none of them created an attachment or file", (n_rows("ost_attachment"), n_rows("ost_file")) == (att0, file0))
    plain = 'say "hi" <p>x</p><img src="x.png"><b>literal</b> it\'s a & b; note: data: x, y and cid: 5'
    r = call("POST", "/tickets/%d/notes" % T_OWN, {"body": plain, "body_format": "text"}, token=tk2)
    eb = (r.data or {}).get("entry", {}).get("body", "")
    check("D3/D4: any other text (quotes, markup, prose that mentions data:/cid:) is accepted (201) and stays escaped, never interpreted",
          r.status == 201 and "<img" not in eb and "<b>" not in eb and "&lt;img" in eb and (r.data["entry"]["body_text"] == plain), r.raw[:220])
    check("D3/D4: ... and it created no attachment or file", (n_rows("ost_attachment"), n_rows("ost_file")) == (att0, file0))
    r = call("POST", "/tickets/%d/notes" % T_OWN, {"body": "<p>fine <b>markup</b> and a sentence: data: x, y, cid: 5</p>", "body_format": "html"}, token=tk2)
    check("body_format=html itself is NOT removed: supported markup (and prose that merely mentions data:/cid:) -> 201", r.status == 201 and "<b>markup</b>" in r.data["entry"]["body"], r.raw[:200])

    # every route that hands free text to the core parser (one common guard, not one per endpoint)
    sweep_att, sweep_tk = n_rows("ost_attachment"), n_rows("ost_ticket")
    subj_sweep = "inline sweep %s" % U
    KID = call("POST", "/tickets/%d/tasks" % fresh_ticket(), {"title": "sweep task", "description": "d"}, token=TOK).data["id"]
    KID2 = call("POST", "/tickets/%d/tasks" % fresh_ticket(), {"title": "sweep task 2", "description": "d"}, token=TOK).data["id"]
    NOTE = call("POST", "/tickets/%d/notes" % T_OWN, {"body": "to be edited"}, token=tk2).data["entry"]["id"]
    routes_ = [
        ("POST /tickets (message)", lambda h: call("POST", "/tickets", {"subject": subj_sweep, "message": h, "topic_id": 1, "user_id": UID, "dept_id": 1}, token=TOK)),
        ("POST /tickets/{id}/notes", lambda h: call("POST", "/tickets/%d/notes" % T_OWN, {"body": h, "body_format": "html"}, token=tk2)),
        ("PATCH /tickets/{id}/notes/{entry}", lambda h: call("PATCH", "/tickets/%d/notes/%d" % (T_OWN, NOTE), {"body": h, "body_format": "html"}, token=tk2)),
        ("POST /tickets/{id}/replies", lambda h: call("POST", "/tickets/%d/replies" % T_OWN, {"body": h, "body_format": "html", "notify": "none"}, token=TOK)),
        ("POST /tickets/{id}/claim (comment)", lambda h: call("POST", "/tickets/%d/claim" % fresh_ticket(), {"comment": h}, token=TOK)),
        ("POST /tickets/{id}/status (comment)", lambda h: call("POST", "/tickets/%d/status" % fresh_ticket(), {"status_id": 3, "base": 1, "comment": h}, token=TOK)),
        ("POST /tickets/{id}/transfer (comment)", lambda h: call("POST", "/tickets/%d/transfer" % fresh_ticket(), {"dept_id": 2, "base": 1, "comment": h}, token=TOK)),
        ("PATCH /tickets/{id}/fields/priority (comment)", lambda h: call("PATCH", "/tickets/%d/fields/priority" % fresh_ticket(), {"value": 3, "base": 2, "comment": h}, token=TOK)),
        ("POST /tickets/{id}/tasks (description)", lambda h: call("POST", "/tickets/%d/tasks" % fresh_ticket(), {"title": "sweep", "description": h}, token=TOK)),
        ("POST /tasks/{id}/notes", lambda h: call("POST", "/tasks/%d/notes" % KID, {"body": h, "body_format": "html"}, token=TOK)),
        ("POST /tasks/{id}/status (comment)", lambda h: call("POST", "/tasks/%d/status" % KID2, {"status": "closed", "base": "open", "comment": h}, token=TOK)),
    ]
    for label, fn in routes_:
        rs = [fn(data_uri()), fn(cid_html), fn(url_html)]
        check("inline guard: %s rejects data:, cid: and file.php?key= (422 each)" % label,
              [x.status for x in rs] == [422] * 3 and [x.details.get("reason") for x in rs] == ["inline_data_not_supported", "inline_cid_not_supported", "inline_cid_not_supported"],
              str([(x.status, x.details.get("reason")) for x in rs]))
    check("inline guard: none of those creates an attachment, and the rejected POST /tickets created no ticket",
          n_rows("ost_attachment") == sweep_att and (sql("select count(*) from ost_ticket__cdata where subject = '%s'" % subj_sweep) or ["?"])[0] == "0")

# ------------------------------------------------------------------ Knowledge Base, read-only (OW-REQ-64, 2026-09-29)
print("\n[knowledge base: read-only gateway over osTicket's FAQ / Category]")
KB = "e2e-kb-" + U
if sql("select 1") is None:
    skip("knowledge base", "no DB access to build the fixtures")
else:
    def kb_snapshot():
        a = sql("select count(*), coalesce(sum(crc32(concat(faq_id,'|',category_id,'|',ispublished,'|',question,'|',answer,'|',ifnull(keywords,''),'|',ifnull(notes,''),'|',updated))),0) from ost_faq")
        b = sql("select count(*), coalesce(sum(crc32(concat(category_id,'|',ifnull(category_pid,0),'|',ispublic,'|',name,'|',description,'|',updated))),0) from ost_faq_category")
        c = sql("select (select count(*) from ost_faq_topic), (select count(*) from ost_attachment where type='F')")
        return (a, b, c)

    hostile = ('<p>ok</p><script>alert(1)</script><img src="x" onerror="alert(2)"><a href="javascript:alert(3)" onclick="x()">l</a>'
               '<iframe src="http://evil.example"></iframe><form action="x"><input name="p"></form><p onmouseover="z()">t</p>')
    sql("insert into ost_faq_category (category_pid,ispublic,name,description,notes,created,updated) values "
        "(NULL,1,'%s-AGFA','d','n',NOW(),NOW()),(NULL,0,'%s-Priv','d','n',NOW(),NOW())" % (KB, KB))
    cA = int(sql("select category_id from ost_faq_category where name='%s-AGFA'" % KB)[0])
    cP = int(sql("select category_id from ost_faq_category where name='%s-Priv'" % KB)[0])
    sql("insert into ost_faq_category (category_pid,ispublic,name,description,notes,created,updated) values (%d,1,'%s-CR30X','d','n',NOW(),NOW())" % (cA, KB))
    cB = int(sql("select category_id from ost_faq_category where name='%s-CR30X'" % KB)[0])
    NOTE = "SECRET-NOTE-" + U
    sql("insert into ost_faq (category_id,ispublished,question,answer,keywords,notes,created,updated) values "
        "(%d,1,'%s Configurar destino PACS','<p>Procedimiento <b>DICOM</b> AE title</p>','pacs dicom','%s',NOW(),NOW()),"
        "(%d,0,'%s Worklist interna','<p>solo interno</p>',' ','%s',NOW(),NOW()),"
        "(%d,2,'%s Problemas frecuentes','<p>destacado</p>',NULL,'%s',NOW(),NOW()),"
        "(%d,1,'%s Hostil','%s','x','%s',NOW(),NOW())" % (cB, KB, NOTE, cB, KB, NOTE, cA, KB, NOTE, cP, KB, hostile.replace("'", "''"), NOTE))
    ids = {k: int(sql("select faq_id from ost_faq where question='%s %s'" % (KB, k))[0])
           for k in ("Configurar destino PACS", "Worklist interna", "Problemas frecuentes", "Hostil")}
    fPacs, fWl, fProb, fHost = (ids[k] for k in ("Configurar destino PACS", "Worklist interna", "Problemas frecuentes", "Hostil"))
    before = kb_snapshot()

    ART_LIST_KEYS = {"id", "question", "category_id", "keywords", "visibility", "updated"}
    ART_KEYS = ART_LIST_KEYS | {"answer", "created"}
    CAT_KEYS = {"id", "parent_id", "name", "visibility", "article_count"}

    # --- categories
    r = call("GET", "/knowledge/categories?limit=200", token=TOK)
    mine = {c["id"]: c for c in (r.data or []) if c["name"].startswith(KB)}
    check("categories: 200 with data/meta envelope", r.status == 200 and isinstance(r.data, list) and "has_more" in r.meta and "next_cursor" in r.meta)
    check("categories: exact field allowlist", bool(mine) and all(set(c) == CAT_KEYS for c in r.data), str(r.data[:1]))
    check("categories: parent/child (CR30X under AGFA, AGFA is a root)", mine.get(cB, {}).get("parent_id") == cA and mine.get(cA, {}).get("parent_id") is None)
    check("categories: visibility labels (public / internal) and own article counts",
          mine[cA]["visibility"] == "public" and mine[cP]["visibility"] == "internal" and mine[cB]["article_count"] == 2 and mine[cA]["article_count"] == 1 and mine[cP]["article_count"] == 1,
          str(mine))
    p1 = call("GET", "/knowledge/categories?limit=1", token=TOK)
    check("categories: cursor pagination (limit=1 -> has_more + next_cursor; the next page moves forward)",
          p1.status == 200 and p1.meta.get("count") == 1 and p1.meta.get("has_more") is True and p1.meta.get("next_cursor")
          and call("GET", "/knowledge/categories?limit=1&cursor=" + urllib.parse.quote(p1.meta["next_cursor"]), token=TOK).data[0]["id"] > p1.data[0]["id"])

    # --- article list
    r = call("GET", "/knowledge/articles?q=" + KB, token=TOK)
    check("articles: q finds the 4 fixtures (question substring), by id", r.status == 200 and [a["id"] for a in r.data] == sorted(ids.values()), str(r.data))
    check("articles: list allowlist has no answer, no notes", all(set(a) == ART_LIST_KEYS for a in r.data) and NOTE not in r.raw and "answer" not in r.data[0])
    by = {a["id"]: a for a in r.data}
    check("articles: visibility labels internal/public/featured, keywords null when blank",
          (by[fWl]["visibility"], by[fPacs]["visibility"], by[fProb]["visibility"]) == ("internal", "public", "featured") and by[fWl]["keywords"] is None and by[fProb]["keywords"] is None and by[fPacs]["keywords"] == "pacs dicom")
    r = call("GET", "/knowledge/articles?category=%d&q=%s" % (cB, KB), token=TOK)
    check("articles: q + category (exact category, no descendants)", r.status == 200 and sorted(a["id"] for a in r.data) == sorted([fPacs, fWl]), str(r.data))
    r = call("GET", "/knowledge/articles?category=%d" % cA, token=TOK)
    check("articles: category alone", r.status == 200 and [a["id"] for a in r.data] == [fProb])
    check("articles: q matches inside the answer", [a["id"] for a in call("GET", "/knowledge/articles?q=AE%20title&category=" + str(cB), token=TOK).data] == [fPacs])
    check("articles: q matches keywords", fPacs in [a["id"] for a in call("GET", "/knowledge/articles?q=pacs%20dicom", token=TOK).data])
    check("articles: q matches the category name", {fPacs, fWl} <= {a["id"] for a in call("GET", "/knowledge/articles?q=" + KB + "-CR30X", token=TOK).data})
    r = call("GET", "/knowledge/articles?q=" + KB + "&limit=2", token=TOK)
    n1 = r.meta.get("next_cursor")
    r2 = call("GET", "/knowledge/articles?q=%s&limit=2&cursor=%s" % (KB, urllib.parse.quote(n1 or "")), token=TOK)
    check("articles: cursor pages cover the 4 fixtures once each",
          r.meta.get("count") == 2 and r.meta.get("has_more") is True and r2.status == 200 and r2.meta.get("count") == 2 and r2.meta.get("has_more") is False and r2.meta.get("next_cursor") is None
          and [a["id"] for a in r.data + r2.data] == sorted(ids.values()))
    r = call("GET", "/knowledge/articles?q=" + KB + "-nothing-here", token=TOK)
    check("articles: no match is a valid empty page", r.status == 200 and r.data == [] and r.meta.get("count") == 0 and r.meta.get("has_more") is False)
    check("articles: limit above the maximum is clamped (200, at most 100)", (lambda x: x.status == 200 and x.meta.get("count") <= 100)(call("GET", "/knowledge/articles?limit=100000", token=TOK)))
    check("articles: limit=0 is clamped to 1", call("GET", "/knowledge/articles?limit=0&q=" + KB, token=TOK).meta.get("count") == 1)
    check("articles: unknown parameters are ignored (existing convention)", call("GET", "/knowledge/articles?q=%s&foo=bar" % KB, token=TOK).status == 200)

    # --- detail
    r = call("GET", "/knowledge/articles/%d" % fPacs, token=TOK)
    check("detail: 200 with the exact allowlist (no notes, no admin fields)", r.status == 200 and set(r.data) == ART_KEYS and NOTE not in r.raw, str(sorted(r.data or {})))
    check("detail: answer HTML kept, category, keywords, question",
          r.data["answer"] == "<p>Procedimiento <b>DICOM</b> AE title</p>" and r.data["category_id"] == cB and r.data["keywords"] == "pacs dicom" and r.data["question"] == KB + " Configurar destino PACS")
    check("detail: dates are ISO UTC", bool(re.match(r"^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$", r.data["created"] or "")) and bool(re.match(r"^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$", r.data["updated"] or "")), str((r.data["created"], r.data["updated"])))
    r = call("GET", "/knowledge/articles/%d" % fHost, token=TOK)
    ans = (r.data or {}).get("answer", "")
    low = ans.lower()
    check("html: script, on* handlers, iframe and form are gone; the javascript: link is neutralized by the core's `denied:` scheme",
          r.status == 200 and not any(x in low for x in ("<script", "alert(1)", "onerror", "onclick", "onmouseover", 'href="javascript', "<iframe", "<form", "<input")) and 'href="denied:' in low, ans)
    check("html: harmless content survives (<p>ok</p>, the image, the link text)", "<p>ok</p>" in ans and "<img" in low and ">l</a>" in ans, ans)
    check("html: the stored row was NOT rewritten by reading it", sql("select answer from ost_faq where faq_id=%d" % fHost)[0].count("<script>") == 1)
    check("detail: unknown id is 404 JSON", call("GET", "/knowledge/articles/999999999", token=TOK).err == "not_found")
    check("detail: id 0 is 404", call("GET", "/knowledge/articles/0", token=TOK).status == 404)
    check("detail: a non-numeric or huge id is 404, never 500", [call("GET", "/knowledge/articles/" + i, token=TOK).status for i in ("abc", "1x", "99999999999999999999999")] == [404, 404, 404])

    # --- visibility: the core shows every article/category to any logged-in agent; a Limited Access agent included
    if TOK2:
        r = call("GET", "/knowledge/articles?q=" + KB, token=TOK2)
        check("visibility: a Limited Access agent sees the same list, internal and private-category articles included (as the SCP does)",
              r.status == 200 and sorted(a["id"] for a in r.data) == sorted(ids.values()), str(r.status))
        check("visibility: ... and reads an internal article and one of a private category", call("GET", "/knowledge/articles/%d" % fWl, token=TOK2).status == 200 and call("GET", "/knowledge/articles/%d" % fHost, token=TOK2).status == 200)
        check("visibility: categories list for that agent contains the private category (labelled internal)",
              any(c["id"] == cP and c["visibility"] == "internal" for c in call("GET", "/knowledge/categories?limit=200", token=TOK2).data))
    else:
        skip("knowledge visibility (agent2)", "no agent2 credentials")

    # --- validation
    def st(path): return call("GET", path, token=TOK)
    check("validation: category not an id / zero / unknown / array / injection -> 422",
          [st(p).status for p in ("/knowledge/articles?category=abc", "/knowledge/articles?category=0", "/knowledge/articles?category=99999999", "/knowledge/articles?category[]=1", "/knowledge/articles?category=1%20OR%201=1")] == [422] * 5)
    check("validation: q too short / too long / array -> 422 with field q",
          [st(p).status for p in ("/knowledge/articles?q=a", "/knowledge/articles?q=" + "x" * 101, "/knowledge/articles?q[]=ab")] == [422] * 3 and st("/knowledge/articles?q=a").json["error"].get("field") == "q")
    check("validation: limit not an integer -> 422; cursor garbage -> 422 (both lists)",
          [st(p).status for p in ("/knowledge/articles?limit=abc", "/knowledge/articles?cursor=zzzz", "/knowledge/categories?limit=abc", "/knowledge/categories?cursor=zzzz")] == [422] * 4)
    r = st("/knowledge/articles?q=" + urllib.parse.quote("' OR '1'='1' -- ") + "&category=" + str(cA))
    check("security: SQL metacharacters in q are data (200, nothing matched)", r.status == 200 and r.data == [])
    check("security: no attachment/notes/topics sub-resources exist", [st("/knowledge/articles/%d/%s" % (fPacs, x)).status for x in ("attachments", "notes", "topics", "files")] == [404] * 4)

    # --- auth + methods
    ks = ["/knowledge/categories", "/knowledge/articles", "/knowledge/articles/%d" % fPacs]
    check("auth: no token / garbage token -> 401 on all three", [call("GET", p, key=None).status for p in ks] == [401] * 3 and [call("GET", p, token="a.b", key=None).status for p in ks] == [401] * 3)
    ms = [(m, p, call(m, p, {} if m != "DELETE" else None, token=TOK)) for p in ks for m in ("POST", "PUT", "PATCH", "DELETE")]
    check("methods: POST/PUT/PATCH/DELETE on every knowledge path -> 405 with Allow: GET", all(x.status == 405 and "GET" in x.headers.get("Allow", "") for _, _, x in ms), str([(m, p, x.status) for m, p, x in ms if x.status != 405]))
    check("methods: nothing named /knowledge/... other than these three exists (write verbs on a sibling path -> 404)",
          [call("POST", p, {}, token=TOK).status for p in ("/knowledge", "/knowledge/articles/%d/publish" % fPacs, "/knowledge/categories/%d" % cA)] == [404] * 3)

    # --- read-only: every KB byte is unchanged after all of the above
    after = kb_snapshot()
    check("read-only: faq, faq_category, faq_topic and FAQ attachments are identical before/after all requests", before == after, "%s -> %s" % (before, after))

    # --- clean only our own fixtures
    sql("delete from ost_faq where question like '%s %%'" % KB)
    sql("delete from ost_faq_category where name like '%s-%%'" % KB)
    check("fixtures cleaned (only this run's rows)", sql("select count(*) from ost_faq where question like '%s %%'" % KB)[0] == "0" and sql("select count(*) from ost_faq_category where name like '%s-%%'" % KB)[0] == "0")

# ------------------------------------------------------------------ Custom Lists, read-only (OW-REQ-63, 2026-10-04)
print("\n[custom lists: read-only gateway over osTicket's DynamicList / DynamicListItem]")
LS = "e2e-ow63-" + U
if sql("select 1") is None:
    skip("custom lists", "no DB access to build the fixtures")
else:
    def ls_snapshot():
        return (sql("select count(*), coalesce(sum(crc32(concat(id,'|',name,'|',ifnull(type,''),'|',sort_mode,'|',updated))),0) from ost_list"),
                sql("select count(*), coalesce(sum(crc32(concat(id,'|',list_id,'|',status,'|',value,'|',ifnull(extra,''),'|',sort,'|',ifnull(properties,'')))),0) from ost_list_items"),
                sql("select count(*) from ost_form where type like 'L%'"), sql("select count(*) from ost_form_field"))

    sql("insert into ost_list (name,name_plural,sort_mode,masks,type,notes,created,updated) values ('%s','%s','Alpha',0,NULL,'SECRET-NOTE-%s',NOW(),NOW())" % (LS, LS, U))
    L1 = int(sql("select id from ost_list where name='%s'" % LS)[0])
    sql("insert into ost_list (name,name_plural,sort_mode,masks,type,notes,created,updated) values ('%s-bare','%s-bare','Alpha',0,NULL,NULL,NOW(),NOW())" % (LS, LS))
    L2 = int(sql("select id from ost_list where name='%s-bare'" % LS)[0])
    sql("insert into ost_form (pid,type,flags,title,instructions,name,notes,created,updated) values (NULL,'L%d',1,'%s Properties',NULL,'',NULL,NOW(),NOW())" % (L1, LS))
    F1 = int(sql("select id from ost_form where type='L%d'" % L1)[0])
    sql("insert into ost_form_field (form_id,flags,type,label,name,configuration,sort,hint,created,updated) values "
        "(%d,12289,'text','Serial number','serial','{\"size\":\"20\",\"length\":\"40\"}',1,NULL,NOW(),NOW()),"
        "(%d,12289,'memo','Free notes','notes',NULL,2,NULL,NOW(),NOW())" % (F1, F1))
    fs, fn = (int(sql("select id from ost_form_field where form_id=%d and name='%s'" % (F1, n))[0]) for n in ("serial", "notes"))
    sql("insert into ost_list_items (list_id,status,value,extra,sort,properties) values "
        "(%d,1,'%s Alpha','ALPHA',1,'{\"%d\":\"SN-001\",\"%d\":\"first note\"}'),"
        "(%d,1,'%s Beta','BETA-X',2,'{\"%d\":\"SN-002\"}'),"
        "(%d,0,'%s Retired','OLD',3,'{\"%d\":\"SN-003\"}'),"
        "(%d,1,'%s Plain',NULL,4,NULL)" % (L1, LS, fs, fn, L1, LS, fs, L1, LS, fs, L1, LS))
    its = {k: int(sql("select id from ost_list_items where list_id=%d and value='%s %s'" % (L1, LS, k))[0]) for k in ("Alpha", "Beta", "Retired", "Plain")}
    sql("insert into ost_list_items (list_id,status,value,extra,sort,properties) values (%d,1,'%s Solo',NULL,1,NULL)" % (L2, LS))
    before = ls_snapshot()
    LIST_KEYS = {"id", "name", "name_plural", "sort_mode", "item_count", "properties"}
    ITEM_KEYS = {"id", "value", "extra", "enabled", "internal", "sort", "properties"}

    r = call("GET", "/lists?limit=500", token=TOK)
    mine = {x["id"]: x for x in (r.data or [])}
    check("lists: 200 with data/meta envelope", r.status == 200 and isinstance(r.data, list) and "has_more" in r.meta and "next_cursor" in r.meta)
    check("lists: exact field allowlist; the fixture list is there with its item count (incl. retired) and no notes", L1 in mine and all(set(x) == LIST_KEYS for x in r.data) and mine[L1]["item_count"] == 4 and "SECRET-NOTE" not in r.raw, str(mine.get(L1)))
    cfgrow = sql("select concat(namespace, '\\t', value) from ost_config where namespace like 'plugin.%.instance.%' and `key`='installed_equipment_list_id' limit 1")
    if cfgrow and '\t' in cfgrow[0]:
        cfgns, cfgvalue = cfgrow[0].split('\t', 1)
        expected_role = int(cfgvalue) if str(cfgvalue).isdigit() else 0
        role = call("GET", "/lists/roles", token=TOK)
        check("list roles: configured Installed Equipment role is exact, installation-scoped and non-enumerating",
              role.status == 200 and role.data == {"installed_equipment": {"list_id": expected_role}} and set(role.data) == {"installed_equipment"}, str(role.data))
        sql("delete from ost_config where namespace='%s' and `key`='installed_equipment_list_id'" % cfgns)
        missing = call("GET", "/lists/roles", token=TOK)
        check("list roles: missing configuration -> 503 not_configured", missing.status == 503 and missing.err == "not_configured", missing.raw[:160])
        sql("insert into ost_config (namespace,`key`,value,updated) values ('%s','installed_equipment_list_id','99999999',NOW())" % cfgns)
        invalid = call("GET", "/lists/roles", token=TOK)
        check("list roles: invalid configured list -> 503 not_configured", invalid.status == 503 and invalid.err == "not_configured", invalid.raw[:160])
        for malformed_value in ("24abc", "24.0"):
            sql("delete from ost_config where namespace='%s' and `key`='installed_equipment_list_id'" % cfgns)
            sql("insert into ost_config (namespace,`key`,value,updated) values ('%s','installed_equipment_list_id','%s',NOW())" % (cfgns, malformed_value))
            malformed = call("GET", "/lists/roles", token=TOK)
            check("list roles: malformed configured value %s -> 503 not_configured" % malformed_value,
                  malformed.status == 503 and malformed.err == "not_configured", malformed.raw[:160])
        sql("delete from ost_config where namespace='%s' and `key`='installed_equipment_list_id'" % cfgns)
        sql("insert into ost_config (namespace,`key`,value,updated) values ('%s','installed_equipment_list_id','%s',NOW())" % (cfgns, cfgvalue))
    else:
        skip("list roles", "no Installed Equipment configuration fixture")
    check("lists: property schema by logical name / label / type, in form order", mine[L1]["properties"] == [{"name": "serial", "label": "Serial number", "type": "text"}, {"name": "notes", "label": "Free notes", "type": "memo"}], str(mine[L1]["properties"]))
    check("lists: a list without a property form has an empty schema and none is created by reading", mine[L2]["properties"] == [] and sql("select count(*) from ost_form where type='L%d'" % L2)[0] == "0")
    check("lists: typed core lists (ticket-status) are not custom catalogs", 1 not in mine and all(x["id"] != 1 for x in r.data))
    p1 = call("GET", "/lists?limit=1", token=TOK)
    check("lists: cursor pagination moves forward", p1.status == 200 and p1.meta.get("has_more") is True and call("GET", "/lists?limit=1&cursor=" + urllib.parse.quote(p1.meta["next_cursor"]), token=TOK).data[0]["id"] > p1.data[0]["id"])

    r = call("GET", "/lists/%d/items" % L1, token=TOK)
    check("items: enabled only by default, by id, exact allowlist, no list notes", r.status == 200 and [i["id"] for i in r.data] == [its["Alpha"], its["Beta"], its["Plain"]] and all(set(i) == ITEM_KEYS for i in r.data) and "SECRET-NOTE" not in r.raw, str(r.data))
    r = call("GET", "/lists/%d/items?include_disabled=1" % L1, token=TOK)
    by = {i["id"]: i for i in (r.data or [])}
    check("items: include_disabled=1 also returns the retired item, flagged enabled=false (historical references)", r.status == 200 and sorted(by) == sorted(its.values()) and by[its["Retired"]]["enabled"] is False and by[its["Alpha"]]["enabled"] is True, str(r.data))
    check("items: value, extra (null when blank), sort, internal", by[its["Alpha"]]["value"] == LS + " Alpha" and by[its["Alpha"]]["extra"] == "ALPHA" and by[its["Plain"]]["extra"] is None and by[its["Beta"]]["sort"] == 2 and by[its["Alpha"]]["internal"] is False)
    check("items: properties keyed by the field's logical name, with label and text value",
          by[its["Alpha"]]["properties"] == {"serial": {"label": "Serial number", "value": "SN-001"}, "notes": {"label": "Free notes", "value": "first note"}}, str(by[its["Alpha"]]["properties"]))
    check("items: a property that was never set is absent; an item without properties has an empty object", by[its["Beta"]]["properties"] == {"serial": {"label": "Serial number", "value": "SN-002"}} and by[its["Plain"]]["properties"] == {})
    check("items: q = the core's search (value starts with)", [i["id"] for i in call("GET", "/lists/%d/items?q=%s%%20Al" % (L1, LS), token=TOK).data] == [its["Alpha"]])
    check("items: q matches the abbreviation (extra contains)", [i["id"] for i in call("GET", "/lists/%d/items?q=ETA-" % L1, token=TOK).data] == [its["Beta"]])
    check("items: q matches inside a property value", [i["id"] for i in call("GET", "/lists/%d/items?q=SN-002" % L1, token=TOK).data] == [its["Beta"]])
    check("items: q never returns a retired item unless include_disabled=1", call("GET", "/lists/%d/items?q=SN-003" % L1, token=TOK).data == [] and [i["id"] for i in call("GET", "/lists/%d/items?q=SN-003&include_disabled=1" % L1, token=TOK).data] == [its["Retired"]])
    check("items: q shorter than 2 -> 422 on q", call("GET", "/lists/%d/items?q=a" % L1, token=TOK).status == 422)
    check("items: include_disabled must be 0 or 1", call("GET", "/lists/%d/items?include_disabled=2" % L1, token=TOK).status == 422)
    pg = call("GET", "/lists/%d/items?include_disabled=1&limit=2" % L1, token=TOK)
    pg2 = call("GET", "/lists/%d/items?include_disabled=1&limit=2&cursor=%s" % (L1, urllib.parse.quote(pg.meta["next_cursor"])), token=TOK)
    check("items: cursor pagination walks every item once, in id order", pg.meta["count"] == 2 and pg.meta["has_more"] and [i["id"] for i in pg.data] + [i["id"] for i in pg2.data] == sorted(its.values()) and pg2.meta["has_more"] is False)
    check("items: another custom list is isolated (L2 has one item and not these)", [i["value"] for i in call("GET", "/lists/%d/items" % L2, token=TOK).data] == [LS + " Solo"])
    check("items: unknown list and typed core list -> 404", call("GET", "/lists/99999999/items", token=TOK).status == 404 and call("GET", "/lists/1/items", token=TOK).status == 404)
    v1 = call("GET", "/lists/%d/items" % L1, token=TOK).meta.get("version")
    v1b = call("GET", "/lists/%d/items?q=Alpha&limit=1" % L1, token=TOK).meta.get("version")
    check("items: meta.version is the same for every page/filter of an unchanged list (a 40-hex hash of the whole list)", bool(v1) and len(v1) == 40 and v1 == v1b)
    rr = urllib.request.Request(BASE + "/lists/%d/items" % L1, headers={"Authorization": "Bearer " + TOK})
    with urllib.request.urlopen(rr) as x:
        et = x.headers.get("ETag")
    check("items: ETag + If-None-Match -> 304 (offline cache revalidation)", bool(et) and call("GET", "/lists/%d/items" % L1, token=TOK, headers={"If-None-Match": et}).status == 304)
    after = ls_snapshot()
    check("lists: reading never writes (lists, items, property forms and fields unchanged)", before == after, "%s -> %s" % (before, after))
    sql("update ost_list_items set value='%s Alpha2' where id=%d" % (LS, its["Alpha"]))
    check("items: meta.version changes when the list content changes (also for a retired item)", call("GET", "/lists/%d/items" % L1, token=TOK).meta.get("version") != v1)
    v2 = call("GET", "/lists/%d/items" % L1, token=TOK).meta.get("version")
    sql("update ost_list_items set extra='OLD2' where id=%d" % its["Retired"])
    check("items: ... including a retired item", call("GET", "/lists/%d/items" % L1, token=TOK).meta.get("version") != v2)
    check("lists: no token -> 401", call("GET", "/lists", key=None).status == 401 and call("GET", "/lists/%d/items" % L1, key=None).status == 401)
    check("lists: any agent reads them (policy auth) - the limited agent too", TOK2 is None or (call("GET", "/lists", token=TOK2).status == 200 and call("GET", "/lists/%d/items" % L1, token=TOK2).status == 200))
    check("lists: no write verbs exist (405, never routed)", all(call(m, p, {}, token=TOK).status == 405 for m, p in (("POST", "/lists"), ("PUT", "/lists"), ("DELETE", "/lists"), ("POST", "/lists/%d/items" % L1), ("PATCH", "/lists/%d/items" % L1), ("DELETE", "/lists/%d/items" % L1))))
    sql("delete from ost_list_items where list_id in (%d,%d)" % (L1, L2))
    sql("delete from ost_form_field where form_id=%d" % F1)
    sql("delete from ost_form where id=%d" % F1)
    sql("delete from ost_list where id in (%d,%d)" % (L1, L2))
    check("lists: fixtures cleaned (only this run's rows)", sql("select count(*) from ost_list where name like '%s%%'" % LS)[0] == "0" and sql("select count(*) from ost_form where title like '%s%%'" % LS)[0] == "0")

# ------------------------------------------------------------------ list item ids in /fields (OW-REQ-66, 2026-10-04)
print("\n[ticket fields: Custom List selection as machine-readable ids]")
LF_ = "[BCWF TEST] e2e-ow66"          # persistent, idempotent fixture: tickets cannot be deleted, so the config rows they reference stay
if sql("select 1") is None:
    skip("list item ids", "no DB access to build the fixtures")
else:
    def one(q):
        r = sql(q)
        return int(r[0]) if r and r[0] not in ("", "NULL") else None
    LI = one("select id from ost_list where name='%s'" % LF_)
    if not LI:
        sql("insert into ost_list (name,name_plural,sort_mode,masks,type,notes,created,updated) values ('%s','%s','Alpha',0,NULL,NULL,NOW(),NOW())" % (LF_, LF_))
        LI = one("select id from ost_list where name='%s'" % LF_)
        sql("insert into ost_list_items (list_id,status,value,extra,sort,properties) values (%d,1,'%s Unit A','A',1,NULL),(%d,1,'%s Unit B','B',2,NULL)" % (LI, LF_, LI, LF_))
    iA = one("select id from ost_list_items where list_id=%d and extra='A'" % LI)
    sql("update ost_list_items set value='%s Unit A' where id=%d" % (LF_, iA))          # a previous aborted run may have left the rename
    FI = one("select id from ost_form where title='%s Form'" % LF_)
    if not FI:
        sql("insert into ost_form (pid,type,flags,title,instructions,name,notes,created,updated) values (NULL,'G',1,'%s Form',NULL,'',NULL,NOW(),NOW())" % LF_)
        FI = one("select id from ost_form where title='%s Form'" % LF_)
        sql("insert into ost_form_field (form_id,flags,type,label,name,configuration,sort,hint,created,updated) values "
            "(%d,12289,'list-%d','Unit','e2eunit','{\"multiselect\":false,\"widget\":\"dropdown\",\"prompt\":\"\"}',1,NULL,NOW(),NOW()),"
            "(%d,12289,'text','Plain text','e2eplain','{\"size\":\"20\",\"length\":\"40\"}',2,NULL,NOW(),NOW())" % (FI, LI, FI))
    TP = one("select topic_id from ost_help_topic where topic='%s Topic'" % LF_)
    if not TP:
        sql("insert into ost_help_topic (topic_pid,ispublic,noautoresp,flags,status_id,priority_id,dept_id,staff_id,team_id,sla_id,page_id,sequence_id,sort,topic,number_format,notes,created,updated) values "
            "(0,0,1,3,0,0,1,0,0,0,0,1,0,'%s Topic','','e2e fixture (OW-REQ-66)',NOW(),NOW())" % LF_)
        TP = one("select topic_id from ost_help_topic where topic='%s Topic'" % LF_)
        sql("insert into ost_help_topic_form (topic_id,form_id,sort,extra) values (%d,%d,1,'{\"disable\":[]}')" % (TP, FI))

    def lf_fields(tid):
        out = {}
        for e in call("GET", "/tickets/%d/fields" % tid, token=TOK).data or []:
            for f in e["fields"]:
                out[f["name"]] = f
        return out

    r = call("POST", "/tickets", {"subject": "e2e ow66 %s" % U, "message": "m", "user_id": UID, "topic_id": TP, "fields": {"e2eunit": iA, "e2eplain": "x"}}, token=TOK)
    check("list field: ticket created with an item selected by id", r.status == 201, r.raw[:200])
    T66 = r.data["id"]
    fl = lf_fields(T66)
    check("list field: `value` stays the label; `list_id` and `value_ids` name the selected item", fl["e2eunit"]["value"] == LF_ + " Unit A" and fl["e2eunit"]["list_id"] == LI and fl["e2eunit"]["value_ids"] == [iA], str(fl.get("e2eunit")))
    check("list field: `raw` keeps its old meaning (null for a list) and no field loses a key", fl["e2eunit"]["raw"] is None and all(k in fl["e2eunit"] for k in ("id", "name", "label", "type", "editable", "client_visible", "client_editable", "value", "raw")))
    check("non-list fields are unchanged (no list_id / value_ids keys)", "list_id" not in fl["e2eplain"] and "value_ids" not in fl["e2eplain"] and "list_id" not in fl["subject"] and fl["e2eplain"]["raw"] == "x")
    sql("update ost_list_items set value='%s Unit A RENAMED' where id=%d" % (LF_, iA))
    fl2 = lf_fields(T66)
    sql("update ost_list_items set value='%s Unit A' where id=%d" % (LF_, iA))
    check("rename of the item: the id still identifies the selection (the label is mutable)", fl2["e2eunit"]["value_ids"] == [iA] and fl2["e2eunit"]["list_id"] == LI)
    r = call("POST", "/tickets", {"subject": "e2e ow66b %s" % U, "message": "m", "user_id": UID, "topic_id": TP, "fields": {"e2eplain": "y"}}, token=TOK)
    flb = lf_fields(r.data["id"])
    check("list field with nothing selected: value_ids is an empty list (not null, not an error)", r.status == 201 and flb["e2eunit"]["value_ids"] == [] and flb["e2eunit"]["list_id"] == LI and flb["e2eunit"]["value"] == "", str(flb.get("e2eunit")))
    check("same visibility as before: an agent without access to the ticket still gets 403", TOK2 is None or call("GET", "/tickets/%d/fields" % T66, token=TOK2).status in (200, 403, 404))
    n0 = sql("select count(*), coalesce(sum(crc32(concat(entry_id,'|',field_id,'|',ifnull(value,''),'|',ifnull(value_id,'')))),0) from ost_form_entry_values")
    lf_fields(T66)
    check("reading ids writes nothing (answers unchanged)", sql("select count(*), coalesce(sum(crc32(concat(entry_id,'|',field_id,'|',ifnull(value,''),'|',ifnull(value_id,'')))),0) from ost_form_entry_values") == n0)
    # fixture state: RETAINED INTENTIONALLY (list, form, topic; tickets cannot be deleted through the API)

# ------------------------------------------------------------------ ScheduledMaintenance store (OW-REQ-70, 2026-10-04)
print("\n[maintenances: durable ScheduledMaintenance store (OW-REQ-70)]")
LM_ = "[BCWF TEST] e2e-ow70 Installed"      # persistent get-or-create list; its items are per-run (equipment ids are the isolation)
if sql("select 1") is None or not TOK3 or not TOK2:
    skip("maintenances", "needs DB access and agent2/agent3 (E2E-Fixtures.md)")
else:
    def m_one(q):
        r = sql(q)
        return int(r[0]) if r and r[0] not in ("", "NULL") else None

    MTAB = "ost_workflow_maintenance"
    LMID = m_one("select id from ost_list where name='%s'" % LM_)
    if not LMID:
        sql("insert into ost_list (name,name_plural,sort_mode,masks,type,notes,created,updated) values ('%s','%s','Alpha',0,NULL,NULL,NOW(),NOW())" % (LM_, LM_))
        LMID = m_one("select id from ost_list where name='%s'" % LM_)
    sql("insert into ost_list_items (list_id,status,value,extra,sort,properties) values (%d,1,'%s EqA %s',NULL,1,NULL),(%d,1,'%s EqB %s',NULL,2,NULL),(%d,0,'%s EqR %s',NULL,3,NULL)" % (LMID, LM_, U, LMID, LM_, U, LMID, LM_, U))
    EA, EB, ER = (m_one("select id from ost_list_items where list_id=%d and value='%s %s %s'" % (LMID, LM_, n, U)) for n in ("EqA", "EqB", "EqR"))
    OTHER = m_one("select id from ost_list_items where list_id is not null and list_id<>%d limit 1" % LMID)     # an item of another custom list
    CFGNS = (sql("select distinct namespace from ost_config where namespace like 'plugin.%.instance.%' and `key`='signing_secret' limit 1") or [None])[0]
    check("fixtures: three equipment items (2 enabled, 1 retired) and an item of another list", all([EA, EB, ER, OTHER]) and CFGNS, str((EA, EB, ER, OTHER, CFGNS)))

    def m_setting(val):
        sql("delete from ost_config where namespace='%s' and `key`='installed_equipment_list_id'" % CFGNS)
        if val is not None:
            sql("insert into ost_config (namespace,`key`,value,updated) values ('%s','installed_equipment_list_id','%s',NOW())" % (CFGNS, val))

    m_setting(None)
    r = call("POST", "/maintenances/batch", {"items": [{"id": str(uuid.uuid4()), "equipment_id": EA, "scheduled_date": "2025-01-10"}]}, token=TOK)
    check("setting 'installed_equipment_list_id' empty -> batch is refused 503 not_configured (nothing guessed)", r.status == 503 and r.err == "not_configured", r.raw[:160])
    m_setting(LMID)

    def mid():
        return str(uuid.uuid4())

    def mrow(i):
        x = sql("select stored_status,scheduled_date,ifnull(ticket_id,''),ifnull(completed_on,''),ifnull(evidence_ref,''),rev from %s where id='%s'" % (MTAB, i))
        return x[0].split("\t") if x else None

    def mcount():
        return int(sql("select count(*) from %s" % MTAB)[0])

    # ---- create: batch, per-item result, validation ------------------------------------------------------------
    a1, a2, b1, dup, rt, oth, nf, bd, bi = (mid() for _ in range(9))
    items = [
        {"id": a1, "equipment_id": EA, "scheduled_date": "2025-03-15"},
        {"id": a2, "equipment_id": EA, "scheduled_date": "2025-06-15"},
        {"id": b1, "equipment_id": EB, "scheduled_date": "2025-03-15"},
        {"id": dup, "equipment_id": EA, "scheduled_date": "2025-03-15"},                  # same (equipment, date) as a1
        {"id": rt, "equipment_id": ER, "scheduled_date": "2025-03-15"},                   # retired equipment
        {"id": oth, "equipment_id": OTHER, "scheduled_date": "2025-03-15"},               # item of another list
        {"id": nf, "equipment_id": 99999999, "scheduled_date": "2025-03-15"},             # no such item
        {"id": bd, "equipment_id": EA, "scheduled_date": "2025-02-30"},                   # not a DATE
        {"id": "not-a-uuid", "equipment_id": EA, "scheduled_date": "2025-09-15"},
    ]
    n0 = mcount()
    KEY = "e2e-ow70-" + U
    r = call("POST", "/maintenances/batch", {"items": items}, token=TOK, key=KEY)
    res = {x["id"]: x for x in (r.data or [])}
    check("batch: 200, one result per item in order, counts in meta", r.status == 200 and [x["id"] for x in r.data] == [a1, a2, b1, dup, rt, oth, nf, bd, "not-a-uuid"] and r.meta == {"created": 3, "exists": 0, "rejected": 6}, "%s %s" % (r.meta, [x["id"] for x in r.data]))
    check("batch: created items are PLANNED with server fields and an audit entry `created`", all(res[i]["result"] == "created" and res[i]["maintenance"]["stored_status"] == "PLANNED" and res[i]["maintenance"]["changes"][0]["kind"] == "created" and res[i]["maintenance"]["created_by"] == 1 for i in (a1, a2, b1)), str(res[a1]))
    ch = res[a1]["maintenance"]["changes"][0]
    check("changes[] entry shape: kind, at, by, reason, from, to", set(ch) == {"kind", "at", "by", "reason", "from", "to"} and ch["to"] == {"equipment_id": EA, "scheduled_date": "2025-03-15"} and ch["from"] is None)
    check("batch: uniqueness (equipment, date) among non-cancelled -> rejected `duplicate` naming the holder", res[dup]["code"] == "duplicate" and res[dup]["details"]["existing_id"] == a1)
    check("batch: retired equipment -> rejected equipment_retired", res[rt]["code"] == "equipment_retired")
    check("batch: an item of ANOTHER list -> rejected equipment_not_found", res[oth]["code"] == "equipment_not_found")
    check("batch: unknown equipment id -> rejected equipment_not_found", res[nf]["code"] == "equipment_not_found")
    check("batch: invalid DATE (31 Feb) -> rejected invalid_date; non-UUID id -> invalid_id", res[bd]["code"] == "invalid_date" and r.data[8]["code"] == "invalid_id")
    check("batch: only the three valid items were stored", mcount() - n0 == 3)
    r2 = call("POST", "/maintenances/batch", {"items": items}, token=TOK, key=KEY)
    check("idempotency per request: same Idempotency-Key + body -> identical answer, replayed, nothing new", r2.status == 200 and r2.json == r.json and r2.headers.get("Idempotent-Replayed") == "true" and mcount() - n0 == 3)
    r3 = call("POST", "/maintenances/batch", {"items": items[:3]}, token=TOK)
    check("idempotency per item: a new request with the same client ids -> `exists` with the stored record, no duplicates", r3.status == 200 and [x["result"] for x in r3.data] == ["exists"] * 3 and r3.data[0]["maintenance"]["id"] == a1 and mcount() - n0 == 3)
    mixed = call("POST", "/maintenances/batch", {"items": [items[0], {"id": mid(), "equipment_id": EB, "scheduled_date": "2025-09-15"}]}, token=TOK)
    check("per-item results mix: an existing id and a new one in the same request", [x["result"] for x in mixed.data] == ["exists", "created"])
    b2 = mixed.data[1]["id"]
    check("batch: bad shapes -> 422 (no items, empty, over 200)", call("POST", "/maintenances/batch", {}, token=TOK).status == 422 and call("POST", "/maintenances/batch", {"items": []}, token=TOK).status == 422
          and call("POST", "/maintenances/batch", {"items": [{}] * 201}, token=TOK).status == 422)
    check("batch: an Idempotency-Key is required (400)", call("POST", "/maintenances/batch", {"items": items[:1]}, token=TOK, key=None).err == "idempotency_key_required")

    # ---- reads ----------------------------------------------------------------------------------------------------
    r = call("GET", "/maintenances/%s" % a1, token=TOK2)
    check("read by id: any agent (policy auth), exact DTO keys", r.status == 200 and set(r.data) == {"id", "equipment_id", "scheduled_date", "stored_status", "ticket_id", "completed_on", "completed_by", "evidence_ref", "completion_note", "cancel_reason", "changes", "created_at", "created_by", "updated_at", "updated_by"}, r.raw[:200])
    check("read by id: unknown -> 404; no token -> 401", call("GET", "/maintenances/%s" % mid(), token=TOK).status == 404 and call("GET", "/maintenances/%s" % a1, key=None).status == 401)
    r = call("GET", "/maintenances?equipment_id=%d" % EA, token=TOK2)
    check("read by equipment_id (all states)", r.status == 200 and sorted(x["id"] for x in r.data) == sorted([a1, a2]), r.raw[:200])
    check("read: filter validation (equipment_id=0 -> 422)", call("GET", "/maintenances?equipment_id=0", token=TOK).status == 422 and call("GET", "/maintenances?cursor=zzzz", token=TOK).status == 422)
    allm = call("GET", "/maintenances?limit=1", token=TOK)
    walked = []; cur = None
    for _ in range(4000):
        pg = call("GET", "/maintenances?limit=200" + ("&cursor=" + urllib.parse.quote(cur) if cur else ""), token=TOK)
        walked += pg.data
        if not pg.meta["has_more"]:
            break
        cur = pg.meta["next_cursor"]
    ids = [x["id"] for x in walked]
    check("incremental feed: the cursor walks every record once, ordered by (updated_at, id) server-side", len(ids) == len(set(ids)) == mcount() and all(i in ids for i in (a1, a2, b1, b2)), "%d walked, %d ids, %d rows" % (len(walked), len(set(ids)), mcount()))
    wm = pg.meta["watermark"]
    check("incremental feed: the last page carries a watermark and `limit` pages with has_more", bool(wm) and allm.meta["has_more"] is True and allm.meta["count"] == 1)
    check("incremental feed: from the watermark nothing is returned (and the watermark is kept)", call("GET", "/maintenances?cursor=" + urllib.parse.quote(wm), token=TOK).data == [] and call("GET", "/maintenances?cursor=" + urllib.parse.quote(wm), token=TOK).meta["watermark"] == wm)

    # ---- reschedule (agent: anydept.ticket.create), base + conflict ---------------------------------------------
    tm = call("GET", "/me/permissions", token=TOK2)
    a2_can_create = any("ticket.create" in d["permissions"] for d in tm.data["departments"])
    time.sleep(1.1)
    r = call("POST", "/maintenances/%s/reschedule" % a1, {"base": "2025-03-15", "scheduled_date": "2025-03-28", "reason": "client asked"}, token=TOK3)
    check("reschedule: PLANNED, base = the date seen -> 200 applied, scheduled_date moves, audit entry kind=reschedule from/to/reason/by", r.status == 200 and r.meta["applied"] is True and r.data["scheduled_date"] == "2025-03-28"
          and r.data["changes"][-1]["kind"] == "reschedule" and r.data["changes"][-1]["from"] == "2025-03-15" and r.data["changes"][-1]["to"] == "2025-03-28" and r.data["changes"][-1]["reason"] == "client asked" and r.data["changes"][-1]["by"] == 3 and r.data["updated_by"] == 3, r.raw[:300])
    f = call("GET", "/maintenances?cursor=" + urllib.parse.quote(wm), token=TOK)
    check("incremental feed: after a change, the watermark returns exactly that record", [x["id"] for x in f.data] == [a1] and f.meta["watermark"] != wm)
    r = call("POST", "/maintenances/%s/reschedule" % a1, {"base": "2025-03-28", "scheduled_date": "2025-03-28", "reason": "retry"}, token=TOK3)
    check("reschedule to the date it already has -> 200 applied=false (no new audit entry)", r.status == 200 and r.meta["applied"] is False and len(r.data["changes"]) == 2)
    r = call("POST", "/maintenances/%s/reschedule" % a1, {"base": "2025-03-15", "scheduled_date": "2025-04-02", "reason": "stale device"}, token=TOK)
    check("reschedule with a stale base -> 409 conflict with current, base, last_change and the server record", r.status == 409 and r.err == "conflict" and r.details["reason"] == "base_mismatch" and r.details["current"] == "2025-03-28" and r.details["base"] == "2025-03-15" and r.details["last_change"]["kind"] == "reschedule" and r.details["record"]["id"] == a1, r.raw[:300])
    r = call("POST", "/maintenances/%s/reschedule" % a1, {"scheduled_date": "2025-04-02", "reason": "x"}, token=TOK)
    check("reschedule without base -> 422; without reason -> 422; bad date -> 422", r.status == 422 and r.json["error"]["field"] == "base"
          and call("POST", "/maintenances/%s/reschedule" % a1, {"base": "2025-03-28", "scheduled_date": "2025-04-02"}, token=TOK).status == 422
          and call("POST", "/maintenances/%s/reschedule" % a1, {"base": "2025-03-28", "scheduled_date": "2025-13-02", "reason": "x"}, token=TOK).status == 422)
    r = call("POST", "/maintenances/%s/reschedule" % a1, {"base": "2025-03-28", "scheduled_date": "2025-06-15", "reason": "clash"}, token=TOK)
    check("reschedule onto a date held by another non-cancelled maintenance of the same equipment -> 409 duplicate", r.status == 409 and r.details["reason"] == "duplicate" and r.details["existing_id"] == a2, r.raw[:200])
    r = call("POST", "/maintenances/%s/reschedule" % a1, {"base": "2025-03-28", "scheduled_date": "2020-01-05", "reason": "past is allowed"}, token=TOK)
    check("reschedule into the past is allowed (it becomes overdue on the client)", r.status == 200 and r.data["scheduled_date"] == "2020-01-05")
    call("POST", "/maintenances/%s/reschedule" % a1, {"base": "2020-01-05", "scheduled_date": "2025-03-28", "reason": "back"}, token=TOK)
    check("reschedule: concurrent compare-and-swap - rev bumps on each change (no lost update)", int(mrow(a1)[5]) == 4)
    r = call("POST", "/maintenances/%s/reschedule" % mid(), {"base": "2025-03-28", "scheduled_date": "2025-04-02", "reason": "x"}, token=TOK)
    check("reschedule an unknown id -> 404", r.status == 404)

    # ---- tickets: link (ticket.view on the target), unlink (no access needed), change = unlink + link ------------------------
    T1 = call("POST", "/tickets", {"subject": "e2e ow70 own %s" % U, "message": "m", "topic_id": 1, "user_id": UID, "dept_id": 1}, token=TOK).data["id"]
    T2 = call("POST", "/tickets", {"subject": "e2e ow70 second %s" % U, "message": "m", "topic_id": 1, "user_id": UID, "dept_id": 1}, token=TOK).data["id"]
    TS = call("POST", "/tickets", {"subject": "e2e ow70 sales only %s" % U, "message": "m", "topic_id": 1, "user_id": UID, "dept_id": 2}, token=TOK).data["id"]
    r = call("POST", "/maintenances/%s/link" % b1, {"base": None, "ticket_id": TS}, token=TOK3)
    check("link a Ticket the agent cannot see -> 403 (and the maintenance is untouched)", r.status == 403 and mrow(b1)[2] == "", r.raw[:160])
    r = call("POST", "/maintenances/%s/link" % b1, {"base": None, "ticket_id": 99999999}, token=TOK3)
    check("link a Ticket that does not exist -> the same 403 (no probing)", r.status == 403)
    r = call("POST", "/maintenances/%s/link" % b1, {"base": None, "ticket_id": TS}, token=TOK)
    check("link: the administrator sees every Ticket", r.status == 200 and r.data["ticket_id"] == TS and r.data["changes"][-1]["kind"] == "link" and r.data["changes"][-1]["to"] == TS and r.data["changes"][-1]["from"] is None, r.raw[:200])
    r = call("POST", "/maintenances/%s/link" % b1, {"base": None, "ticket_id": TS}, token=TOK)
    check("link again to the same Ticket -> 200 applied=false", r.status == 200 and r.meta["applied"] is False)
    r = call("POST", "/maintenances/%s/link" % b1, {"base": TS, "ticket_id": T1}, token=TOK)
    check("link another Ticket while one is linked -> 409 (change = unlink first, with a reason)", r.status == 409 and r.details["reason"] == "ticket_already_linked")
    r = call("POST", "/maintenances/%s/unlink" % b1, {"base": TS}, token=TOK3)
    check("unlink without a reason -> 422", r.status == 422)
    r = call("POST", "/maintenances/%s/unlink" % b1, {"base": T1, "reason": "wrong"}, token=TOK3)
    check("unlink with a stale base -> 409", r.status == 409 and r.details["current"] == TS)
    r = call("POST", "/maintenances/%s/unlink" % b1, {"base": TS, "reason": "wrong ticket"}, token=TOK3)
    check("unlink needs NO access to the Ticket: an agent that cannot see it unlinks it (audit kind=unlink from/to/reason)", r.status == 200 and r.data["ticket_id"] is None and r.data["changes"][-1]["kind"] == "unlink" and r.data["changes"][-1]["from"] == TS and r.data["changes"][-1]["reason"] == "wrong ticket", r.raw[:200])
    r = call("POST", "/maintenances/%s/unlink" % b1, {"base": TS, "reason": "again"}, token=TOK3)
    check("unlink with nothing linked -> 200 applied=false", r.status == 200 and r.meta["applied"] is False)
    r = call("POST", "/maintenances/%s/link" % b1, {"base": None, "ticket_id": T1, "reason": "right one"}, token=TOK3)
    check("link the right Ticket (visible to the agent) -> 200", r.status == 200 and r.data["ticket_id"] == T1 and r.data["changes"][-1]["reason"] == "right one")
    check("read by ticket_id", [x["id"] for x in call("GET", "/maintenances?ticket_id=%d" % T1, token=TOK2).data] == [b1])
    check("link: a stale base (null while a Ticket is linked) -> 409", call("POST", "/maintenances/%s/link" % b1, {"base": None, "ticket_id": T2}, token=TOK).status == 409)
    check("link: ticket_id must be a positive integer -> 422; base is required", call("POST", "/maintenances/%s/link" % b1, {"base": T1, "ticket_id": "x"}, token=TOK).status == 422 and call("POST", "/maintenances/%s/link" % b1, {"ticket_id": T2}, token=TOK).status == 422)

    # ---- complete: evidence_ref against the linked Ticket's document index; completion_note otherwise -------------------
    DU70 = str(uuid.uuid4()); DUX = str(uuid.uuid4())
    check("evidence fixture: published document on the linked Ticket, another on a different Ticket",
          call("POST", "/tickets/%d/notes" % T1, {"body": "Service document %s" % U, "document": {"uuid": DU70, "version": 1}}, token=TOK).status == 201
          and call("POST", "/tickets/%d/notes" % T2, {"body": "Foreign document %s" % U, "document": {"uuid": DUX, "version": 1}}, token=TOK).status == 201)
    r = call("POST", "/maintenances/%s/complete" % b1, {"base": "PLANNED", "completed_on": "2025-03-16"}, token=TOK3)
    check("complete: no evidence_ref and no completion_note -> 422 (exception needs a note)", r.status == 422 and r.json["error"]["field"] == "completion_note")
    r = call("POST", "/maintenances/%s/complete" % b1, {"base": "PLANNED", "completed_on": "2999-01-01", "completion_note": "x"}, token=TOK3)
    check("complete: completed_on in the future (staff time zone) -> 422", r.status == 422 and r.json["error"]["field"] == "completed_on")
    r = call("POST", "/maintenances/%s/complete" % b1, {"base": "PLANNED", "completed_on": "2025-03-16", "evidence_ref": "doc:%s@1" % DUX}, token=TOK3)
    check("complete: evidence_ref of a document that belongs to ANOTHER Ticket -> 422 foreign_evidence", r.status == 422 and r.details.get("reason") == "foreign_evidence", r.raw[:200])
    r = call("POST", "/maintenances/%s/complete" % b1, {"base": "PLANNED", "completed_on": "2025-03-16", "evidence_ref": "doc:%s@7" % DU70}, token=TOK3)
    check("complete: evidence_ref of a version that was never published -> 422 foreign_evidence", r.status == 422 and r.details.get("reason") == "foreign_evidence")
    r = call("POST", "/maintenances/%s/complete" % b1, {"base": "PLANNED", "completed_on": "2025-03-16", "evidence_ref": "not-a-ref"}, token=TOK3)
    check("complete: malformed evidence_ref -> 422", r.status == 422 and r.json["error"]["field"] == "evidence_ref")
    r = call("POST", "/maintenances/%s/complete" % a2, {"base": "PLANNED", "completed_on": "2025-03-16", "evidence_ref": "doc:%s@1" % DU70}, token=TOK3)
    check("complete: evidence_ref on a maintenance without a Ticket -> 422 no_ticket", r.status == 422 and r.details.get("reason") == "no_ticket")
    r = call("POST", "/maintenances/%s/complete" % b1, {"base": "CANCELLED", "completed_on": "2025-03-16", "evidence_ref": "doc:%s@1" % DU70}, token=TOK3)
    check("complete with a stale base -> 409", r.status == 409 and r.details["reason"] == "base_mismatch" and r.details["current"] == "PLANNED")
    r = call("POST", "/maintenances/%s/complete" % b1, {"base": "PLANNED", "completed_on": "2025-03-16", "evidence_ref": "doc:%s@1" % DU70.upper()}, token=TOK3)
    check("complete with a published document of the linked Ticket -> 200 COMPLETED, completed_on/by, evidence_ref (normalized), audit", r.status == 200 and r.data["stored_status"] == "COMPLETED" and r.data["completed_on"] == "2025-03-16" and r.data["completed_by"] == 3
          and r.data["evidence_ref"] == "doc:%s@1" % DU70 and r.data["changes"][-1]["kind"] == "complete", r.raw[:300])
    r = call("POST", "/maintenances/%s/complete" % b1, {"base": "PLANNED", "completed_on": "2025-03-16", "evidence_ref": "doc:%s@1" % DU70}, token=TOK3)
    check("complete again with the same data -> 200 applied=false", r.status == 200 and r.meta["applied"] is False)
    r = call("POST", "/maintenances/%s/complete" % b1, {"base": "PLANNED", "completed_on": "2025-03-20", "evidence_ref": "doc:%s@1" % DU70}, token=TOK3)
    check("complete again with a different day (someone else completed it) -> 409", r.status == 409)
    check("a COMPLETED maintenance cannot be rescheduled, linked or unlinked (409 invalid_state); the Ticket stays",
          all(call("POST", "/maintenances/%s/%s" % (b1, p), body, token=TOK).details.get("reason") == "invalid_state" for p, body in (
              ("reschedule", {"base": "2025-03-15", "scheduled_date": "2025-05-01", "reason": "x"}), ("link", {"base": T1, "ticket_id": T2}), ("unlink", {"base": T1, "reason": "x"}))) and mrow(b1)[2] == str(T1))
    r = call("POST", "/maintenances/%s/complete" % a2, {"base": "PLANNED", "completed_on": "2025-06-16", "completion_note": "done, documented elsewhere"}, token=TOK3)
    check("complete without Ticket and without evidence, with a completion_note -> 200 (the only way with no Ticket)", r.status == 200 and r.data["stored_status"] == "COMPLETED" and r.data["completion_note"] == "done, documented elsewhere" and r.data["evidence_ref"] is None)

    # ---- manager-only actions: cancel, revert (administrator, or manager of ANY department) --------------------------------
    r = call("POST", "/maintenances/%s/cancel" % b2, {"base": "PLANNED", "reason": "no longer needed"}, token=TOK3)
    check("cancel by an agent who is not a manager -> 403 manager_required", r.status == 403 and r.details.get("reason") == "manager_required" and mrow(b2)[0] == "PLANNED", r.raw[:160])
    r = call("POST", "/maintenances/%s/revert" % a2, {"base": "COMPLETED", "reason": "mistake"}, token=TOK3)
    check("revert by an agent who is not a manager -> 403", r.status == 403 and mrow(a2)[0] == "COMPLETED")
    check("limited agent (agent2): create/complete follow `anydept.ticket.create`; manager actions are 403",
          call("POST", "/maintenances/%s/cancel" % b2, {"base": "PLANNED", "reason": "x"}, token=TOK2).status == 403
          and call("POST", "/maintenances/batch", {"items": [{"id": mid(), "equipment_id": EB, "scheduled_date": "2025-10-15"}]}, token=TOK2).status == (200 if a2_can_create else 403))
    r = call("POST", "/maintenances/%s/cancel" % b2, {"base": "PLANNED"}, token=TOK)
    check("cancel without a reason -> 422", r.status == 422)
    r = call("POST", "/maintenances/%s/cancel" % b2, {"base": "COMPLETED", "reason": "x"}, token=TOK)
    check("cancel with a stale base -> 409", r.status == 409 and r.details["current"] == "PLANNED")
    r = call("POST", "/maintenances/%s/cancel" % a2, {"base": "COMPLETED", "reason": "x"}, token=TOK)
    check("cancel a COMPLETED one -> 409 invalid_state (revert it first; no COMPLETED->CANCELLED)", r.status == 409 and r.details["reason"] == "invalid_state")
    r = call("POST", "/maintenances/%s/cancel" % b2, {"base": "PLANNED", "reason": "no longer needed"}, token=TOK)
    check("cancel by the administrator -> 200 CANCELLED, cancel_reason, audit; the row is kept", r.status == 200 and r.data["stored_status"] == "CANCELLED" and r.data["cancel_reason"] == "no longer needed" and r.data["changes"][-1]["kind"] == "cancel" and mrow(b2) is not None, r.raw[:200])
    check("cancel again -> 200 applied=false", call("POST", "/maintenances/%s/cancel" % b2, {"base": "PLANNED", "reason": "again"}, token=TOK).meta.get("applied") is False)
    check("a CANCELLED maintenance is read-only and never goes back to PLANNED (reschedule/complete/revert/link -> 409 or no-op)",
          call("POST", "/maintenances/%s/reschedule" % b2, {"base": "2025-09-15", "scheduled_date": "2025-09-16", "reason": "x"}, token=TOK).status == 409
          and call("POST", "/maintenances/%s/complete" % b2, {"base": "CANCELLED", "completed_on": "2025-09-16", "completion_note": "x"}, token=TOK).status == 409
          and call("POST", "/maintenances/%s/revert" % b2, {"base": "CANCELLED", "reason": "x"}, token=TOK).status == 409
          and call("POST", "/maintenances/%s/link" % b2, {"base": None, "ticket_id": T1}, token=TOK).status == 409)
    r = call("POST", "/maintenances/batch", {"items": [{"id": mid(), "equipment_id": EB, "scheduled_date": "2025-09-15"}]}, token=TOK)
    check("uniqueness ignores CANCELLED: the same (equipment, date) can be created again after the cancel", r.status == 200 and r.data[0]["result"] == "created", r.raw[:200])
    sql("update ost_department set manager_id=3 where id=3")          # agent3 manages ANOTHER department (its own is dept 1)
    try:
        r = call("POST", "/maintenances/%s/revert" % a2, {"base": "COMPLETED"}, token=TOK3)
        check("revert without a reason -> 422", r.status == 422)
        r = call("POST", "/maintenances/%s/revert" % a2, {"base": "PLANNED", "reason": "mistake"}, token=TOK3)
        check("revert with a stale base -> 409", r.status == 409 and r.details["current"] == "COMPLETED")
        r = call("POST", "/maintenances/%s/revert" % a2, {"base": "COMPLETED", "reason": "completed by mistake"}, token=TOK3)
        c = r.data["changes"][-1] if r.data else {}
        check("revert by a manager of ANY department -> 200 PLANNED; scheduled_date and ticket_id unchanged; completion fields cleared", r.status == 200 and r.data["stored_status"] == "PLANNED" and r.data["scheduled_date"] == "2025-06-15"
              and r.data["completed_on"] is None and r.data["completed_by"] is None and r.data["completion_note"] is None and r.data["evidence_ref"] is None, r.raw[:300])
        check("revert: the previous completion values, reason, by and at are kept in changes[] (kind=revert)", c.get("kind") == "revert" and c["reason"] == "completed by mistake" and c["by"] == 3 and c["from"]["completed_on"] == "2025-06-16"
              and c["from"]["completion_note"] == "done, documented elsewhere" and c["from"]["completed_by"] == 3 and c["to"] == {"stored_status": "PLANNED"} and c["at"].endswith("Z"), str(c))
        check("revert again -> 200 applied=false (already PLANNED)", call("POST", "/maintenances/%s/revert" % a2, {"base": "COMPLETED", "reason": "again"}, token=TOK3).meta.get("applied") is False)
        r = call("POST", "/maintenances/%s/cancel" % a2, {"base": "PLANNED", "reason": "manager cancels"}, token=TOK3)
        check("cancel by a manager of any department -> 200", r.status == 200 and r.data["stored_status"] == "CANCELLED")
        r = call("POST", "/maintenances/%s/revert" % b1, {"base": "COMPLETED", "reason": "evidence replaced"}, token=TOK)
        check("revert by the administrator (completed with evidence) -> 200; evidence_ref preserved in the audit entry only", r.status == 200 and r.data["evidence_ref"] is None and r.data["changes"][-1]["from"]["evidence_ref"] == "doc:%s@1" % DU70 and r.data["ticket_id"] == T1)
        check("after a revert the unique slot still holds (uniqueness counts it)", call("POST", "/maintenances/batch", {"items": [{"id": mid(), "equipment_id": EB, "scheduled_date": "2025-03-15"}]}, token=TOK).data[0]["code"] == "duplicate")
    finally:
        sql("update ost_department set manager_id=0 where id=3")

    # ---- the retired-equipment rules apply to NEW records only (design §11/§19.2) --------------------------------------
    sql("update ost_list_items set status=0 where id=%d" % EB)
    r = call("POST", "/maintenances/batch", {"items": [{"id": mid(), "equipment_id": EB, "scheduled_date": "2025-12-20"}]}, token=TOK)
    check("equipment retired AFTER the schedule: new records are rejected equipment_retired", r.data[0]["code"] == "equipment_retired")
    r = call("POST", "/maintenances/%s/complete" % b1, {"base": "PLANNED", "completed_on": "2025-03-17", "evidence_ref": "doc:%s@1" % DU70}, token=TOK)
    check("...but existing ones can still be completed (the work happened)", r.status == 200 and r.data["stored_status"] == "COMPLETED")
    sql("update ost_list_items set status=1 where id=%d" % EB)

    # ---- durability: table, no purge, no delete ----------------------------------------------------------------------
    meta = sql("select table_name from information_schema.tables where table_schema=database() and table_name='%s'" % MTAB)
    check("table exists, separate from the idempotency table, with the schema_version column", meta == [MTAB] and sql("select count(*) from information_schema.columns where table_schema=database() and table_name='%s' and column_name='schema_version'" % MTAB) == ["1"])
    check("every row carries schema_version=1", sql("select count(*) from %s where schema_version<>1" % MTAB) == ["0"])
    n_before = mcount()
    sql("update ost_workflow_idempotency set expires=DATE_SUB(NOW(), INTERVAL 1 DAY) where idem_key='%s'" % KEY)
    check("durability setup: the batch's idempotency record is now expired", sql("select count(*) from ost_workflow_idempotency where idem_key='%s' and expires<NOW()" % KEY) == ["1"])
    pr = subprocess.run([os.path.expanduser("~/development/ost-prod/php80/bin/php"), os.path.join(os.path.dirname(os.path.abspath(__file__)), "purge-check.php"), os.path.expanduser("~/development/ost-sandbox/app")], capture_output=True, text=True)
    check("the plugin's cron purge ran and removed the expired idempotency record ...", pr.stdout.strip() == "purged" and sql("select count(*) from ost_workflow_idempotency where idem_key='%s'" % KEY) == ["0"], pr.stdout + pr.stderr)
    check("... and did not touch a single maintenance (no TTL, no purge)", mcount() == n_before and mrow(a1) is not None and mrow(b1) is not None)
    check("replaying the purged key is a fresh request, and the per-item ids still make it idempotent (no duplicates)", [x["result"] for x in call("POST", "/maintenances/batch", {"items": items[:3]}, token=TOK, key=KEY).data] == ["exists"] * 3 and mcount() == n_before)
    check("no hard delete: no maintenance route uses DELETE (405) and every id created in this run is still stored",
          call("DELETE", "/maintenances/%s" % a1, token=TOK).status == 405 and all(mrow(i) is not None for i in (a1, a2, b1, b2)))
    check("enable() is idempotent: re-running it keeps the table and its rows", subprocess.run([os.path.expanduser("~/development/ost-prod/php80/bin/php"), os.path.join(os.path.dirname(os.path.abspath(__file__)), "plugin-admin.php"), os.path.expanduser("~/development/ost-sandbox/app"), "enable"], capture_output=True, text=True).stdout.startswith("enabled")
          and mcount() == n_before)
    # fixture state: list, equipment items, tickets and maintenance rows are RETAINED INTENTIONALLY (rows are never deleted by design)

# ------------------------------------------------------------------ Contracts store (OW-REQ-71, 2026-10-04)
print("\n[contracts: durable Contracts / Service Agreements store (OW-REQ-71)]")
import hashlib, threading, datetime
PHP80_ = os.path.expanduser("~/development/ost-prod/php80/bin/php")
APP_ = os.path.expanduser("~/development/ost-sandbox/app")
TOOLS_ = os.path.dirname(os.path.abspath(__file__))
C71 = "[BCWF TEST] e2e-ow71"
LM71 = "[BCWF TEST] e2e-ow70 Installed"      # the Installed Equipment list the OW-REQ-70 section configures (persistent get-or-create)
if sql("select 1") is None or not TOK3 or not TOK2:
    skip("contracts", "needs DB access and agent2/agent3 (E2E-Fixtures.md)")
else:
    def c_one(q):
        r = sql(q)
        return int(r[0]) if r and r[0] not in ("", "NULL") else None

    CT = {k: "ost_workflow_contract" + k for k in ("", "_version", "_event", "_document", "_catalog", "_basis")}
    LM71ID = c_one("select id from ost_list where name='%s'" % LM71)
    if not LM71ID:
        sql("insert into ost_list (name,name_plural,sort_mode,masks,type,notes,created,updated) values ('%s','%s','Alpha',0,NULL,NULL,NOW(),NOW())" % (LM71, LM71))
        LM71ID = c_one("select id from ost_list where name='%s'" % LM71)
    CFGNS71 = (sql("select distinct namespace from ost_config where namespace like 'plugin.%.instance.%' and `key`='signing_secret' limit 1") or [None])[0]
    SET_BEFORE71 = (sql("select value from ost_config where namespace='%s' and `key`='installed_equipment_list_id'" % CFGNS71) or [None])[0]
    TZ_BEFORE71 = (sql("select value from ost_config where namespace='core' and `key`='default_timezone'") or [None])[0]

    def c_setting(val):
        sql("delete from ost_config where namespace='%s' and `key`='installed_equipment_list_id'" % CFGNS71)
        if val is not None:
            sql("insert into ost_config (namespace,`key`,value,updated) values ('%s','installed_equipment_list_id','%s',NOW())" % (CFGNS71, val))

    c_setting(LM71ID)
    check("fixtures: Installed Equipment list and plugin instance", bool(LM71ID and CFGNS71), str((LM71ID, CFGNS71)))
    T0_ = (sql("select DATE_FORMAT(UTC_TIMESTAMP(6),'%Y-%m-%d %H:%i:%s.%f')") or [""])[0]
    b64u = lambda b: base64.urlsafe_b64encode(b).decode().rstrip("=")
    CUR0 = b64u(json.dumps({"t": T0_, "i": "00000000-0000-0000-0000-000000000000"}, separators=(",", ":")).encode())   # "everything created in this run"
    TODAY = datetime.datetime.now(datetime.timezone.utc).date()
    D = lambda days: (TODAY + datetime.timedelta(days=days)).isoformat()

    def mk_org(tag):
        r = call("POST", "/organizations", {"name": "%s %s %s" % (C71, tag, U), "domain": "ow71-%s-%s.example.com" % (tag.lower(), U)}, token=TOK)
        assert r.status == 201, r.raw[:200]
        return r.data["id"]

    def mk_eq(org, tag, enabled=1, extra=True):
        val = "%s Eq%s %s" % (C71, tag, U)
        ex = "'BCW|1|O=%d|E=1'" % org if extra else "NULL"
        sql("insert into ost_list_items (list_id,status,value,extra,sort,properties) values (%d,%d,'%s',%s,1,NULL)" % (LM71ID, enabled, val, ex))
        return c_one("select id from ost_list_items where list_id=%d and value='%s'" % (LM71ID, val))

    def ent(**lines):
        """ent(RS='INCLUDED_UNLIMITED', PM=('INCLUDED_QUANTITY', 2, 'CALENDAR_YEAR'), ONSITE='BILLABLE', ...)"""
        names = {"RS": "REMOTE_SUPPORT", "PM": "PREVENTIVE_MAINTENANCE", "ONSITE": "ONSITE_LABOR", "PARTS": "GENERAL_PARTS", "SPEC": "SPECIALTY_PARTS"}
        out = []
        for k, v in lines.items():
            if isinstance(v, tuple):
                out.append({"service_line": names[k], "treatment": v[0], "quantity": v[1], "period_basis": v[2]})
            else:
                out.append({"service_line": names[k], "treatment": v})
        return out

    def listed(*ids): return {"mode": "LISTED", "equipment_ids": list(ids)}
    def org_all(*ex): return {"mode": "ORG_ALL", "excluded_ids": list(ex)}

    def ver(frm, to, cov, entl, **kw):
        v = {"effective_from": frm, "effective_to": to, "coverage": cov, "entitlements": entl}
        v.update(kw)
        return v

    GOLD = {"tier_code": "Gold", "schedule": {"type": "24X7"}, "response_objective_hours": 4}
    PMQ = ("INCLUDED_QUANTITY", 2, "CALENDAR_YEAR")

    def mkc(org, title, version, number=None, tok=TOK, key="auto"):
        b = {"organization_id": org, "title": title, "version": version}
        if number: b["external_number"] = number
        return call("POST", "/contracts", b, token=tok, key=key)

    def drv(c): return [v for v in c["versions"] if v["status"] == "DRAFT"][0]

    def putd(cid, base, version, tok=TOK):
        b = dict(version); b["base"] = base
        return call("PUT", "/contracts/%s/draft" % cid, b, token=tok)

    def act(cid, base, tok=TOK, reason="reviewed and approved"):
        return call("POST", "/contracts/%s/activate" % cid, {"base": base, "reason": reason}, token=tok)

    def active(org, title, version, number=None):
        """create + activate; returns the activated contract"""
        c = mkc(org, title, version, number).data
        r = act(c["contract_ref"], drv(c)["rev"])
        assert r.status == 200, r.raw[:400]
        return r.data

    def get_c(cid, tok=TOK): return call("GET", "/contracts/%s" % cid, token=tok)
    def evs(cid, tok=TOK): return call("GET", "/contracts/%s/events?limit=200" % cid, token=tok).data
    def vrow(vid):
        x = sql("select status,terms_hash,body,rev,effective_from from %s where id='%s'" % (CT["_version"], vid))
        return x[0].split("\t") if x else None
    def effv(c): return [v for v in c["versions"] if v["status"] == "EFFECTIVE"]

    def canon_hash(v):
        cov = v["coverage"]
        cov = {"mode": "LISTED", "equipment_ids": sorted(cov["equipment_ids"])} if cov["mode"] == "LISTED" else {"mode": "ORG_ALL", "excluded_ids": sorted(cov["excluded_ids"])}
        rp = v["response_policy"]
        if rp: rp = {"tier_code": rp["tier_code"], "schedule": rp["schedule"], "response_objective_hours": rp["response_objective_hours"]}
        doc = {"hash_schema": 1, "effective_from": v["effective_from"], "effective_to": v["effective_to"], "coverage": cov,
               "entitlements": [{k: e[k] for k in ("service_line", "treatment", "quantity", "period_basis", "exclusions_text")} for e in v["entitlements"]],
               "response_policy": rp, "catalog_version_ref": v["catalog_version_ref"]}
        return hashlib.sha256(json.dumps(doc, sort_keys=True, separators=(",", ":"), ensure_ascii=False).encode()).hexdigest()

    def pdf_bytes(tag): return ("%%PDF-1.4\n%% %s %s\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%%%EOF\n" % (tag, uuid.uuid4().hex)).encode()

    def upload(content, name, ctype="application/pdf", tok=TOK):
        b_, c_ = multipart("file", name, content, ctype)
        return call("POST", "/files", token=tok, raw=b_, ctype=c_)

    # ---- fixtures ---------------------------------------------------------------------------------------------------
    OA, OB, OZ, OT, OU, OF, OV, OW, OX, OBZ, ON, OM1, OM2, OD1, OD2, OE = (mk_org(t) for t in ("A", "B", "Z", "T", "U", "F", "V", "W", "X", "Bz", "N", "M1", "M2", "D1", "D2", "E"))
    E1, E2 = mk_eq(OA, "A1"), mk_eq(OA, "A2")
    E3R = mk_eq(OA, "A3-retired", enabled=0)
    EB1 = mk_eq(OB, "B1")
    EXO = mk_eq(OA, "NoOwner", extra=False)
    OTHERITEM = c_one("select id from ost_list_items where list_id is not null and list_id<>%d limit 1" % LM71ID)
    check("fixtures: organizations and Installed Equipment (owner in `extra`, one retired, one without owner)", all([OA, OB, OZ, E1, E2, E3R, EB1, EXO]), str((OA, OB, E1, E2, E3R)))
    slas = call("GET", "/slas", token=TOK).data
    SLA1 = slas[0]["id"] if slas else None

    # ---- catalog (ClassificationCatalogVersion): immutable, numbered, base = latest number -----------------------------
    cat0 = call("GET", "/contracts/catalogs", token=TOK)
    latest = cat0.meta["latest_number"]
    codes = [{"code": "RS-COV", "label": "Cubierto", "treatment": "COVERED"}, {"code": "RS-BILL", "label": "Cobrable", "treatment": "BILLABLE"}]
    check("catalogs: readable by any agent (labels only), meta.latest_number", cat0.status == 200 and isinstance(cat0.data, list) and call("GET", "/contracts/catalogs", token=TOK2).status == 200)
    check("catalog publish by an agent who is not a manager -> 403 manager_required", call("POST", "/contracts/catalogs", {"base": latest, "reason": "x", "codes": codes}, token=TOK3).details.get("reason") == "manager_required")
    check("catalog publish without reason -> 422; bad treatment -> 422; duplicate code -> 422; without base -> 422",
          call("POST", "/contracts/catalogs", {"base": latest, "codes": codes}, token=TOK).status == 422
          and call("POST", "/contracts/catalogs", {"base": latest, "reason": "x", "codes": [{"code": "A", "label": "a", "treatment": "NOPE"}]}, token=TOK).status == 422
          and call("POST", "/contracts/catalogs", {"base": latest, "reason": "x", "codes": codes + codes[:1]}, token=TOK).status == 422
          and call("POST", "/contracts/catalogs", {"reason": "x", "codes": codes}, token=TOK).status == 422)
    r = call("POST", "/contracts/catalogs", {"base": latest, "reason": "initial RS classification catalog (e2e %s)" % U, "codes": codes}, token=TOK)
    CAT1 = r.data["catalog_version_ref"] if r.status == 201 else None
    check("catalog publish -> 201, number = base + 1, codes kept, author recorded", r.status == 201 and r.data["number"] == (latest or 0) + 1 and [c["code"] for c in r.data["codes"]] == ["RS-COV", "RS-BILL"] and r.data["registered_by"] == 1, r.raw[:200])
    r = call("POST", "/contracts/catalogs", {"base": latest, "reason": "stale device", "codes": codes}, token=TOK)
    check("catalog publish with a stale base -> 409 base_mismatch (current = latest number)", r.status == 409 and r.details["reason"] == "base_mismatch" and r.details["current"] == (latest or 0) + 1 and r.details["base"] == latest, r.raw[:200])
    check("an immutable catalog version: no route edits or deletes it (PUT/PATCH/DELETE -> 404/405)", all(call(m, "/contracts/catalogs", {}, token=TOK).status in (404, 405) for m in ("PUT", "PATCH", "DELETE")))

    # ---- create ----------------------------------------------------------------------------------------------------------
    V1 = ver("2026-01-01", "2026-12-31", listed(E2, E1), ent(RS="INCLUDED_UNLIMITED", PM=PMQ), response_policy=dict(GOLD, native_sla_ref=SLA1), catalog_version_ref=CAT1)
    KEYC = "e2e-ow71-create-" + U
    r = mkc(OA, "Servicio %s" % U, V1, number="CT-%s-1" % U, key=KEYC)
    C1 = r.data["contract_ref"] if r.status == 201 else None
    c1 = r.data
    check("create contract -> 201: server-assigned contract_ref, status DRAFT, one INITIAL draft version, organization fixed", r.status == 201 and c1["status"] == "DRAFT" and c1["organization_id"] == OA and len(c1["versions"]) == 1
          and c1["versions"][0]["status"] == "DRAFT" and c1["versions"][0]["kind"] == "INITIAL" and c1["versions"][0]["terms_hash"] is None and c1["rev"] == 1 and c1["versions"][0]["rev"] == 1, r.raw[:300])
    check("create: coverage ids sorted, the five lines always present (missing = EXCLUDED)", c1["versions"][0]["coverage"]["equipment_ids"] == sorted([E1, E2]) and [e["service_line"] for e in c1["versions"][0]["entitlements"]] == ["REMOTE_SUPPORT", "PREVENTIVE_MAINTENANCE", "ONSITE_LABOR", "GENERAL_PARTS", "SPECIALTY_PARTS"]
          and c1["versions"][0]["entitlements"][2]["treatment"] == "EXCLUDED")
    r2 = mkc(OA, "Servicio %s" % U, V1, number="CT-%s-1" % U, key=KEYC)
    check("create: same Idempotency-Key replays the same contract (no second row)", r2.status == 201 and r2.data["contract_ref"] == C1 and r2.headers.get("Idempotent-Replayed") == "true"
          and c_one("select count(*) from %s where organization_id=%d" % (CT[""], OA)) == 1)
    check("create: same key with another body -> 422 idempotency_key_reused; no key -> 400", mkc(OA, "Other title", V1, key=KEYC).err == "idempotency_key_reused" and mkc(OA, "x", V1, key=None).err == "idempotency_key_required")
    r = mkc(OB, "dup number", V1, number="ct-%s-1" % U)       # another organization may reuse the number
    check("contract number is unique within an organization only: same number in another organization -> 201", r.status == 201)
    r = mkc(OA, "dup number", V1, number="CT-%s-1" % U.upper())
    check("contract number unique within the organization (case-insensitive) -> 409 number_taken", r.status == 409 and r.details["reason"] == "number_taken", r.raw[:200])
    check("create by an agent who is not a manager -> 403 manager_required (nothing created)", mkc(OA, "x", V1, tok=TOK3).details.get("reason") == "manager_required" and mkc(OA, "x", V1, tok=TOK2).status == 403)
    bad = [({"organization_id": "x"}, "organization_id"), ({"organization_id": 999999999}, "organization_id"), ({"title": ""}, "title"), ({"version": None}, "version"),
           ({"version": dict(V1, effective_from="2026-02-30")}, "effective_from"), ({"version": dict(V1, effective_to="2025-12-31")}, "effective_from"),
           ({"version": dict(V1, kind="RENEWAL")}, "version.kind"), ({"version": dict(V1, surprise=1)}, "version"), ({"version": dict(V1, coverage={"mode": "SOME"})}, "coverage.mode"),
           ({"version": dict(V1, entitlements=[{"service_line": "COFFEE", "treatment": "BILLABLE"}])}, "entitlements"),
           ({"version": dict(V1, entitlements=[{"service_line": "ONSITE_LABOR", "treatment": "INCLUDED_QUANTITY", "quantity": 1, "period_basis": "CALENDAR_YEAR"}])}, "entitlements"),
           ({"version": dict(V1, entitlements=[{"service_line": "ONSITE_LABOR", "treatment": "BILLABLE", "quantity": 3}])}, "entitlements"),
           ({"version": dict(V1, response_policy={"tier_code": "G"})}, "response_policy.schedule"), ({"version": dict(V1, catalog_version_ref=str(uuid.uuid4()))}, "catalog_version_ref"),
           ({"version": dict(V1, response_policy=dict(GOLD, native_sla_ref=99999))}, "response_policy.native_sla_ref")]
    ok_ = True
    for patch, fld in bad:
        b = {"organization_id": OA, "title": "t", "version": V1}; b.update(patch)
        r = call("POST", "/contracts", b, token=TOK)
        if not (r.status == 422 and r.json["error"].get("field") == fld):
            ok_ = False; print("        create validation:", fld, r.status, r.raw[:160])
    check("create validation: organization, title, dates (real dates, from <= to), kind, unknown keys, coverage, lines, quantity, policy, catalog and SLA refs -> 422 with the field", ok_)
    check("a rejected create leaves no row behind", c_one("select count(*) from %s where title in ('t','x')" % CT[""]) == 0)

    # ---- draft: one per contract (I5), base = rev, no-op, validations --------------------------------------------------------
    d1 = drv(c1)
    r = putd(C1, None, V1)
    check("a second draft while one is open -> 409 draft_exists (I5, held by the database)", r.status == 409 and r.details["reason"] == "draft_exists" and r.details["current"] == 1, r.raw[:200])
    V1b = ver("2026-01-01", "2026-12-31", listed(E1, E2), ent(RS="INCLUDED_UNLIMITED", PM=PMQ, ONSITE="BILLABLE"), response_policy=dict(GOLD, native_sla_ref=SLA1), catalog_version_ref=CAT1)
    r = putd(C1, 1, V1b)
    check("save draft with the rev seen -> 200, rev 2, terms replaced", r.status == 200 and r.meta["applied"] is True and drv(r.data)["rev"] == 2 and drv(r.data)["entitlements"][2]["treatment"] == "BILLABLE", r.raw[:200])
    r = putd(C1, 1, V1)
    check("save draft with a stale base -> 409 base_mismatch with current rev, last_change and the server record", r.status == 409 and r.details["reason"] == "base_mismatch" and r.details["current"] == 2 and r.details["base"] == 1
          and r.details["last_change"]["kind"] == "CREATED" and r.details["record"]["contract_ref"] == C1, r.raw[:300])
    r = putd(C1, 2, V1b)
    check("save an identical draft -> 200 applied=false (rev stays 2, no churn)", r.status == 200 and r.meta["applied"] is False and drv(r.data)["rev"] == 2)
    check("save draft without base -> 422; kind CORRECTION on a never-activated contract -> 422; INITIAL cannot be set once active is covered below",
          call("PUT", "/contracts/%s/draft" % C1, V1b, token=TOK).json["error"]["field"] == "base" and putd(C1, 2, dict(V1b, kind="CORRECTION", supersedes=str(uuid.uuid4()))).status == 422)
    check("save draft: non-manager -> 403; unknown contract -> 404", putd(C1, 2, V1b, tok=TOK3).status == 403 and putd(str(uuid.uuid4()), None, V1b).status == 404)

    # ---- activation: completeness, equipment (V8), stale reviewed base, terms_hash ------------------------------------------
    r = act(C1, 1)
    check("activate with the draft rev that was NOT reviewed (stale) -> 409 base_mismatch; nothing activated", r.status == 409 and r.details["reason"] == "base_mismatch" and r.details["current"] == 2 and get_c(C1).data["status"] == "DRAFT")
    check("activate without a reason -> 422", call("POST", "/contracts/%s/activate" % C1, {"base": 2}, token=TOK).status == 422)
    cx = mkc(OA, "empty %s" % U, ver("2026-01-01", "2026-12-31", None, None)).data
    r = act(cx["contract_ref"], 1)
    check("activate a draft with no coverage and no included line -> 422 incomplete (V7, V9)", r.status == 422 and r.details["reason"] == "incomplete" and len(r.details["problems"]) >= 2, r.raw[:300])
    cx2 = mkc(OA, "pm %s" % U, ver("2026-01-01", "2026-12-31", listed(E1), ent(PM=("INCLUDED_QUANTITY", None, None)))).data
    check("activate PM by quantity without quantity/period -> 422 (V10); LISTED without equipment -> 422 (V7)",
          act(cx2["contract_ref"], 1).details.get("reason") == "incomplete"
          and act(mkc(OA, "nolist %s" % U, ver("2026-01-01", "2026-12-31", listed(), ent(RS="BILLABLE"))).data["contract_ref"], 1).details.get("reason") == "incomplete")
    for nm, eqs, why in (("unknown equipment id", [987654321], "unknown_equipment"), ("item of another list", [OTHERITEM] if OTHERITEM else [], "unknown_equipment"),
                         ("retired equipment", [E3R], "equipment_retired"), ("equipment of another organization", [EB1], "equipment_other_organization"), ("equipment without a readable owner", [EXO], "equipment_other_organization")):
        if not eqs: continue
        cq = mkc(OA, "eq %s %s" % (nm, U), ver(D(10), D(100), listed(*eqs), ent(RS="INCLUDED_UNLIMITED"))).data
        r = act(cq["contract_ref"], 1)
        check("activate with %s (future version) -> 422 reason=%s (V8)" % (nm, why), r.status == 422 and r.details.get("reason") == why, r.raw[:200])
    c_setting(None)
    r = act(C1, 2)
    check("setting 'installed_equipment_list_id' empty -> activating LISTED coverage is refused 503 not_configured (nothing guessed)", r.status == 503 and r.err == "not_configured" and get_c(C1).data["status"] == "DRAFT")
    c_setting(LM71ID)

    r = act(C1, 2)
    c1 = r.data
    v1 = effv(c1)[0] if r.status == 200 else None
    check("activate -> 200: contract ACTIVE, version EFFECTIVE, registered_at/by, no draft left", r.status == 200 and c1["status"] == "ACTIVE" and v1 and v1["registered_by"] == 1 and v1["registered_at"] and not [v for v in c1["versions"] if v["status"] == "DRAFT"], r.raw[:300])
    check("activate: terms_hash = sha256 of the canonical terms (sorted keys, ids ascending, native_sla_ref outside the hash) - recomputed independently", v1["terms_hash"] == canon_hash(v1) and v1["hash_schema"] == 1 and v1["terms_hash"] == vrow(v1["contract_version_ref"])[1], str(v1["terms_hash"]))
    check("activate: applicable period derived (CT-D2) = effective period for the only version", v1["applicable_from"] == "2026-01-01" and v1["applicable_to"] == "2026-12-31")
    ev = evs(C1)
    check("events are appended in order with actor, server time and reason: CREATED, VERSION_ACTIVATED(kind, hash)", [e["kind"] for e in ev] == ["CREATED", "VERSION_ACTIVATED"] and ev[1]["reason"] == "reviewed and approved" and ev[1]["actor"] == 1
          and ev[1]["detail"]["terms_hash"] == v1["terms_hash"] and ev[1]["detail"]["kind"] == "INITIAL" and ev[1]["at"].endswith("Z") and ev[0]["reason"] is None, str(ev)[:300])
    check("activate again with nothing to activate -> 409 no_draft", act(C1, 2).details.get("reason") == "no_draft")

    # ---- immutability of an EFFECTIVE version ----------------------------------------------------------------------------------
    body0 = vrow(v1["contract_version_ref"])
    r = putd(C1, v1["rev"], dict(V1b, kind="AMENDMENT", entitlements=ent(RS="BILLABLE")))
    check("an EFFECTIVE version cannot be edited: a draft save against its rev -> 409 (there is no draft), body and hash unchanged in the database",
          r.status == 409 and r.details["reason"] == "base_mismatch" and vrow(v1["contract_version_ref"]) == body0)
    check("no route rewrites an EFFECTIVE version: PUT/PATCH/DELETE on /versions/{id} -> 404/405", all(call(m, "/contracts/%s/versions/%s" % (C1, v1["contract_version_ref"]), {}, token=TOK).status in (404, 405) for m in ("PUT", "PATCH", "DELETE")))

    # ---- overlap (CT-D8): claims, EXCLUDED line, scopes, inclusive dates -------------------------------------------------------------
    C2 = mkc(OA, "overlap BILLABLE %s" % U, ver("2026-03-01", "2026-09-30", listed(E1), ent(RS="BILLABLE"))).data
    r = act(C2["contract_ref"], 1)
    check("overlap: another contract claims REMOTE_SUPPORT (BILLABLE claims too) for the same equipment and days -> 409 overlap, lists the conflict",
          r.status == 409 and r.details["reason"] == "overlap" and r.details["conflicts"][0]["service_line"] == "REMOTE_SUPPORT" and r.details["conflicts"][0]["from"] == "2026-03-01" and r.details["conflicts"][0]["to"] == "2026-09-30"
          and {r.details["conflicts"][0]["a"]["contract_ref"], r.details["conflicts"][0]["b"]["contract_ref"]} == {C1, C2["contract_ref"]}, r.raw[:400])
    check("overlap: a rejected activation changes nothing (contract still DRAFT with its draft, no new event)", get_c(C2["contract_ref"]).data["status"] == "DRAFT" and len(evs(C2["contract_ref"])) == 1)
    r = putd(C2["contract_ref"], 1, ver("2026-03-01", "2026-09-30", listed(E1), ent(RS="EXCLUDED", PARTS="INCLUDED_UNLIMITED")))
    r = act(C2["contract_ref"], 2)
    check("overlap: the same equipment and days but REMOTE_SUPPORT is EXCLUDED (claims nothing) and another line is included -> activates", r.status == 200 and r.data["status"] == "ACTIVE", r.raw[:300])
    C4 = mkc(OA, "org-all with exclusions %s" % U, ver("2026-01-01", "2026-12-31", org_all(E1, E2), ent(RS="INCLUDED_UNLIMITED"))).data
    r = act(C4["contract_ref"], 1)
    check("overlap: ORG_ALL excluding the equipment of a LISTED contract has a disjoint scope -> activates", r.status == 200, r.raw[:300])
    C5 = mkc(OA, "org-all %s" % U, ver("2026-01-01", "2026-12-31", org_all(), ent(RS="INCLUDED_UNLIMITED"))).data
    r = act(C5["contract_ref"], 1)
    check("overlap: ORG_ALL without exclusions vs LISTED and vs ORG_ALL claiming the same line -> 409 with both conflicts", r.status == 409 and r.details["conflict_count"] >= 2 and {c["service_line"] for c in r.details["conflicts"]} == {"REMOTE_SUPPORT"}, r.raw[:300])
    r = putd(C5["contract_ref"], 1, ver("2027-01-01", "2027-12-31", org_all(), ent(RS="INCLUDED_UNLIMITED")))
    check("overlap: after moving the dates to the next year it activates", act(C5["contract_ref"], 2).status == 200)
    PF1 = active(OF, "adj-1 %s" % U, ver("2026-01-01", "2026-06-30", listed(mk_eq(OF, "F1")), ent(RS="INCLUDED_UNLIMITED")))
    FEQ = PF1["versions"][0]["coverage"]["equipment_ids"]
    PF2 = mkc(OF, "adj-2 %s" % U, ver("2026-06-30", "2026-12-31", listed(*FEQ), ent(RS="INCLUDED_UNLIMITED"))).data
    r = act(PF2["contract_ref"], 1)
    check("overlap: dates are inclusive - a contract starting the day the other one ends overlaps by one day (June 30) -> 409", r.status == 409 and r.details["conflicts"][0]["from"] == "2026-06-30" and r.details["conflicts"][0]["to"] == "2026-06-30", r.raw[:300])
    putd(PF2["contract_ref"], 1, ver("2026-07-01", "2026-12-31", listed(*FEQ), ent(RS="INCLUDED_UNLIMITED")))
    check("overlap: starting the next day activates", act(PF2["contract_ref"], 2).status == 200)

    # ---- concurrency: two activations in two contracts of the same organization --------------------------------------------------
    def race(org, tag):
        q = mk_eq(org, "race-" + tag)
        cs = [mkc(org, "race %s %s %d" % (tag, U, i), ver("2026-01-01", "2026-12-31", listed(q), ent(RS="INCLUDED_UNLIMITED"))).data for i in (1, 2)]
        out = [None, None]
        def go(i):
            out[i] = act(cs[i]["contract_ref"], 1)
        th = [threading.Thread(target=go, args=(i,)) for i in (0, 1)]
        for t in th: t.start()
        for t in th: t.join()
        return out, cs
    for rnd, org in enumerate((OD1, OD2), 1):
        out, cs = race(org, "r%d" % rnd)
        st = sorted(r.status for r in out)
        loser = [r for r in out if r.status != 200]
        check("concurrent activation round %d: two parallel requests in two contracts of one organization -> exactly one 200 and one 409 overlap (serialized per organization)" % rnd,
              st == [200, 409] and loser[0].details["reason"] == "overlap", str([(r.status, r.raw[:120]) for r in out]))
        n_eff = c_one("select count(*) from %s v join %s c on c.id=v.contract_id where c.organization_id=%d and v.status='EFFECTIVE'" % (CT["_version"], CT[""], org))
        check("concurrent activation round %d: the database holds exactly one EFFECTIVE version for the organization (the loser stayed DRAFT)" % rnd, n_eff == 1)
    cc = mkc(OD1, "two drafts %s" % U, ver("2026-01-01", "2026-12-31", listed(mk_eq(OD1, "dd")), ent(RS="BILLABLE"))).data
    cc = act(cc["contract_ref"], 1).data
    out = [None, None]
    def go_d(i): out[i] = putd(cc["contract_ref"], None, ver("2027-01-01", "2027-12-31", listed(mk_eq(OD1, "dd2")), ent(RS="BILLABLE"), kind="RENEWAL"))
    th = [threading.Thread(target=go_d, args=(i,)) for i in (0, 1)]
    for t in th: t.start()
    for t in th: t.join()
    check("concurrent drafts: two parallel first-draft saves -> one 201 and one 409 draft_exists (unique key, no database error)", sorted(r.status for r in out) == [201, 409] and [r for r in out if r.status == 409][0].details["reason"] == "draft_exists", str([r.raw[:100] for r in out]))

    # ---- amendment, derived period, duplicate start, correction --------------------------------------------------------------------
    h1, hash1 = v1["contract_version_ref"], v1["terms_hash"]
    V2 = ver("2026-07-01", "2026-12-31", listed(E1, E2), ent(RS="INCLUDED_UNLIMITED", PM=PMQ, SPEC="INCLUDED_UNLIMITED"), kind="AMENDMENT", supersedes=h1, response_policy=GOLD, catalog_version_ref=CAT1)
    r = putd(C1, None, dict(V2, kind="INITIAL"))
    check("a contract that already has a version cannot take another INITIAL, and needs an explicit kind -> 422", r.status == 422 and putd(C1, None, {k: v for k, v in V2.items() if k != "kind"}).status == 422)
    r = putd(C1, None, V2)
    check("amendment draft on an ACTIVE contract -> 201", r.status == 201 and drv(r.data)["kind"] == "AMENDMENT" and drv(r.data)["supersedes"] == h1)
    dv = drv(r.data)
    r = act(C1, dv["rev"])
    c1 = r.data
    v2 = [v for v in c1["versions"] if v["number"] == 2][0] if r.status == 200 else None
    check("activate the amendment: contract stays ACTIVE, two EFFECTIVE versions, event kind AMENDMENT", r.status == 200 and len(effv(c1)) == 2 and evs(C1)[-1]["detail"]["kind"] == "AMENDMENT", r.raw[:200])
    v1n = [v for v in c1["versions"] if v["number"] == 1][0]
    check("amendment: v1 is intact (same terms_hash, same body in the database) and its APPLICABLE period is derived: ends June 30 while its declared effective_to stays Dec 31",
          v1n["terms_hash"] == hash1 and vrow(h1)[:2] == body0[:2] and vrow(h1)[2] == body0[2] and v1n["applicable_to"] == "2026-06-30" and v1n["effective_to"] == "2026-12-31" and v2["applicable_from"] == "2026-07-01")
    putd(C1, None, ver("2026-07-01", "2026-12-31", listed(E1), ent(RS="BILLABLE"), kind="AMENDMENT"))
    dd = drv(get_c(C1).data)
    r = act(C1, dd["rev"])
    check("two EFFECTIVE versions cannot start the same day (I6) -> 409 duplicate_effective_from (UNIQUE contract + date); the draft stays", r.status == 409 and r.details["reason"] == "duplicate_effective_from" and drv(get_c(C1).data)["rev"] == dd["rev"], r.raw[:250])
    r = call("POST", "/contracts/%s/draft/discard" % C1, {"base": dd["rev"]}, token=TOK)
    check("discard without a reason -> 422", r.status == 422)
    r = call("POST", "/contracts/%s/draft/discard" % C1, {"base": dd["rev"] + 5, "reason": "x"}, token=TOK)
    check("discard with a stale base -> 409", r.status == 409 and r.details["current"] == dd["rev"])
    r = call("POST", "/contracts/%s/draft/discard" % C1, {"base": dd["rev"], "reason": "wrong amendment"}, token=TOK)
    check("discard an amendment draft -> 200: DISCARDED but kept (row stays), the contract stays ACTIVE, event DRAFT_DISCARDED", r.status == 200 and r.data["status"] == "ACTIVE" and c_one("select count(*) from %s where id='%s' and status='DISCARDED'" % (CT["_version"], dd["contract_version_ref"])) == 1
          and evs(C1)[-1]["kind"] == "DRAFT_DISCARDED")
    hash2 = v2["terms_hash"]
    # correction of v2: PM quantity 4, supersedes v2
    VC = ver("2026-07-01", "2026-12-31", listed(E1, E2), ent(RS="INCLUDED_UNLIMITED", PM=("INCLUDED_QUANTITY", 4, "CALENDAR_YEAR"), SPEC="INCLUDED_UNLIMITED"), kind="CORRECTION", supersedes=v2["contract_version_ref"], response_policy=GOLD, catalog_version_ref=CAT1)
    check("a CORRECTION must name the version it supersedes; a version of another contract is refused -> 422",
          putd(C1, None, {k: v for k, v in VC.items() if k != "supersedes"}).status == 422 and putd(C1, None, dict(VC, supersedes=effv(PF1)[0]["contract_version_ref"])).status == 422 and putd(C1, None, dict(VC, supersedes=str(uuid.uuid4()))).status == 422)
    r = putd(C1, None, VC)
    r = act(C1, drv(r.data)["rev"], reason="PM quantity was captured wrong")
    c1 = r.data
    vs = {v["number"]: v for v in c1["versions"]} if r.status == 200 else {}
    check("correction activated: the erroneous v2 is VOIDED (kept), the new version starts the same day and is EFFECTIVE, I1 holds", r.status == 200 and vs[2]["status"] == "VOIDED" and vs[4]["status"] == "EFFECTIVE" and vs[4]["kind"] == "CORRECTION" and vs[4]["effective_from"] == "2026-07-01"
          and r.meta["warnings"] and r.meta["warnings"][0]["code"] == "retroactive", r.raw[:300])
    check("correction: the voided version keeps its terms and hash untouched; both events are in the history (VERSION_VOIDED then VERSION_ACTIVATED, with the reason)",
          vs[2]["terms_hash"] == hash2 and vrow(vs[2]["contract_version_ref"])[1] == hash2 and [e["kind"] for e in evs(C1)][-2:] == ["VERSION_VOIDED", "VERSION_ACTIVATED"] and evs(C1)[-1]["reason"] == "PM quantity was captured wrong" and evs(C1)[-2]["detail"]["correction"] is True)
    check("correction: v1 still intact and now ends June 30 because v4 follows it", vs[1]["terms_hash"] == hash1 and vs[1]["applicable_to"] == "2026-06-30" and vs[4]["terms_hash"] == canon_hash(vs[4]))

    # ---- terminate / revoke / I8 / header ------------------------------------------------------------------------------------------------
    rev = get_c(C1).data["rev"]
    r = call("PUT", "/contracts/%s/termination" % C1, {"base": rev, "terminated_on": "2026-12-01"}, token=TOK)
    check("terminate without a reason -> 422; bad date -> 422; non-manager -> 403", r.status == 422 and call("PUT", "/contracts/%s/termination" % C1, {"base": rev, "terminated_on": "x", "reason": "r"}, token=TOK).status == 422
          and call("PUT", "/contracts/%s/termination" % C1, {"base": rev, "terminated_on": "2026-12-01", "reason": "r"}, token=TOK3).status == 403)
    check("terminate with a stale base -> 409", call("PUT", "/contracts/%s/termination" % C1, {"base": rev - 1, "terminated_on": "2026-12-01", "reason": "r"}, token=TOK).details.get("reason") == "base_mismatch")
    r = call("PUT", "/contracts/%s/termination" % C1, {"base": rev, "terminated_on": "2026-12-01", "reason": "customer left"}, token=TOK)
    check("terminate -> CANCELLED, terminated_on = first day without coverage (v4 now ends Nov 30), terms untouched, event TERMINATED", r.status == 200 and r.data["status"] == "CANCELLED" and r.data["terminated_on"] == "2026-12-01"
          and [v for v in r.data["versions"] if v["number"] == 4][0]["applicable_to"] == "2026-11-30" and evs(C1)[-1]["kind"] == "TERMINATED" and evs(C1)[-1]["detail"]["terminated_on"] == "2026-12-01"
          and vrow(vs[4]["contract_version_ref"])[1] == vs[4]["terms_hash"], r.raw[:300])
    check("terminate again with the same date -> 200 applied=false", call("PUT", "/contracts/%s/termination" % C1, {"base": rev, "terminated_on": "2026-12-01", "reason": "again"}, token=TOK).meta["applied"] is False)
    check("terminate a CANCELLED contract on another date -> 409 invalid_state (revoke first)", call("PUT", "/contracts/%s/termination" % C1, {"base": rev + 1, "terminated_on": "2026-11-01", "reason": "r"}, token=TOK).details.get("reason") == "invalid_state")
    cdr = mkc(OA, "never active %s" % U, ver("2026-01-01", "2026-12-31", listed(E1), ent(RS="BILLABLE"))).data
    check("a never-activated contract (DRAFT) cannot be terminated -> 409 invalid_state", call("PUT", "/contracts/%s/termination" % cdr["contract_ref"], {"base": 1, "terminated_on": "2026-11-01", "reason": "r"}, token=TOK).details.get("reason") == "invalid_state")
    r = putd(C1, None, ver("2026-12-15", "2027-06-30", listed(E1, E2), ent(RS="INCLUDED_UNLIMITED"), kind="RENEWAL"))
    r = act(C1, drv(r.data)["rev"])
    check("I8: a version starting on or after the termination date cannot be activated while the contract is CANCELLED -> 409 after_termination", r.status == 409 and r.details["reason"] == "after_termination" and r.details["current"] == "2026-12-01", r.raw[:250])
    rev = get_c(C1).data["rev"]
    r = call("PUT", "/contracts/%s/termination" % C1, {"base": rev, "terminated_on": None, "reason": "terminated by mistake"}, token=TOK)
    check("revoke the termination -> ACTIVE, terminated_on cleared, event TERMINATION_REVOKED keeps the previous date", r.status == 200 and r.data["status"] == "ACTIVE" and r.data["terminated_on"] is None and evs(C1)[-1]["kind"] == "TERMINATION_REVOKED" and evs(C1)[-1]["detail"]["previous_terminated_on"] == "2026-12-01")
    check("revoke on an ACTIVE contract -> 200 applied=false", call("PUT", "/contracts/%s/termination" % C1, {"base": rev + 1, "terminated_on": None, "reason": "again"}, token=TOK).meta["applied"] is False)
    check("the renewal draft is still there; discard it (kept)", call("POST", "/contracts/%s/draft/discard" % C1, {"base": drv(get_c(C1).data)["rev"], "reason": "not needed"}, token=TOK).status == 200)

    rev = get_c(C1).data["rev"]
    r = call("PATCH", "/contracts/%s" % C1, {"base": rev, "title": "Renamed %s" % U}, token=TOK)
    check("edit header without a reason -> 422 (Inbox: every write carries a reason)", r.status == 422 and r.json["error"]["field"] == "reason")
    r = call("PATCH", "/contracts/%s" % C1, {"base": rev - 1, "title": "x", "reason": "r"}, token=TOK)
    check("edit header with a stale base -> 409", r.status == 409 and r.details["current"] == rev)
    r = call("PATCH", "/contracts/%s" % C1, {"base": rev, "title": "Renamed %s" % U, "external_number": "CT-%s-1b" % U, "reason": "naming guide"}, token=TOK)
    e = evs(C1)[-1]
    check("edit header -> 200, rev+1; the event keeps BEFORE and AFTER (the record overwrites by CAS)", r.status == 200 and r.data["title"] == "Renamed %s" % U and r.data["rev"] == rev + 1 and e["kind"] == "HEADER_EDITED"
          and e["detail"]["before"]["title"] == "Servicio %s" % U and e["detail"]["after"]["external_number"] == "CT-%s-1b" % U and e["reason"] == "naming guide")
    check("edit header: same values -> applied=false; a number already used in the organization -> 409 number_taken; nothing to change -> 422",
          call("PATCH", "/contracts/%s" % C1, {"base": rev + 1, "title": "Renamed %s" % U, "reason": "r"}, token=TOK).meta["applied"] is False
          and call("PATCH", "/contracts/%s" % C4["contract_ref"], {"base": 2, "external_number": "CT-%s-1b" % U, "reason": "r"}, token=TOK).details.get("reason") == "number_taken"
          and call("PATCH", "/contracts/%s" % C1, {"base": rev + 1, "reason": "r"}, token=TOK).status == 422)

    # ---- void without replacement (I4) and revalidation when a period widens -------------------------------------------------------------
    PQ = mk_eq(OV, "PQ")
    P = active(OV, "void P %s" % U, ver("2026-01-01", "2026-12-31", listed(PQ), ent(RS="INCLUDED_UNLIMITED")))
    pv1 = effv(P)[0]
    r = putd(P["contract_ref"], None, ver("2026-07-01", "2026-12-31", listed(PQ), ent(ONSITE="INCLUDED_UNLIMITED"), kind="AMENDMENT"))
    P = act(P["contract_ref"], drv(r.data)["rev"]).data
    pv2 = [v for v in P["versions"] if v["number"] == 2][0]
    check("void scenario: v1 (RS) now ends June 30 and v2 (ONSITE only) follows - v1's second half no longer applies", [v for v in P["versions"] if v["number"] == 1][0]["applicable_to"] == "2026-06-30")
    Q = active(OV, "void Q %s" % U, ver("2026-07-01", "2026-12-31", listed(PQ), ent(RS="INCLUDED_UNLIMITED")))
    check("void scenario: another contract may claim REMOTE_SUPPORT for July-December because v1's applicable period ended June 30", Q["status"] == "ACTIVE")
    r = call("POST", "/contracts/%s/versions/%s/void" % (P["contract_ref"], pv2["contract_version_ref"]), {"base": P["rev"], "reason": "amendment registered by mistake"}, token=TOK)
    check("void v2: the previous version would recover July-December and collide with the other contract -> 409 overlap (revalidated on void), nothing changed",
          r.status == 409 and r.details["reason"] == "overlap" and vrow(pv2["contract_version_ref"])[0] == "EFFECTIVE", r.raw[:300])
    check("void without a reason -> 422; non-manager -> 403; a version of another contract -> 404",
          call("POST", "/contracts/%s/versions/%s/void" % (P["contract_ref"], pv2["contract_version_ref"]), {"base": P["rev"]}, token=TOK).status == 422
          and call("POST", "/contracts/%s/versions/%s/void" % (P["contract_ref"], pv2["contract_version_ref"]), {"base": P["rev"], "reason": "r"}, token=TOK3).status == 403
          and call("POST", "/contracts/%s/versions/%s/void" % (P["contract_ref"], effv(Q)[0]["contract_version_ref"]), {"base": P["rev"], "reason": "r"}, token=TOK).status == 404)
    W1 = active(OW, "void W %s" % U, ver("2026-01-01", "2026-12-31", listed(mk_eq(OW, "W1")), ent(RS="INCLUDED_UNLIMITED")))
    wv = effv(W1)[0]
    r = call("POST", "/contracts/%s/versions/%s/void" % (W1["contract_ref"], wv["contract_version_ref"]), {"base": W1["rev"] - 1, "reason": "wrong organization"}, token=TOK)
    check("void with a stale base -> 409", r.status == 409 and r.details["reason"] == "base_mismatch")
    r = call("POST", "/contracts/%s/versions/%s/void" % (W1["contract_ref"], wv["contract_version_ref"]), {"base": W1["rev"], "reason": "wrong organization"}, token=TOK)
    check("void the ONLY effective version -> the contract becomes VOIDED (I4, terminal); the version is kept VOIDED with its hash; event VERSION_VOIDED(contract_voided)",
          r.status == 200 and r.data["status"] == "VOIDED" and r.data["versions"][0]["status"] == "VOIDED" and r.data["versions"][0]["terms_hash"] == wv["terms_hash"] and evs(W1["contract_ref"])[-1]["detail"]["contract_voided"] is True)
    check("a VOIDED contract is terminal: draft save, terminate and activate -> 409 invalid_state; voiding again -> 200 applied=false",
          putd(W1["contract_ref"], None, ver("2027-01-01", "2027-12-31", listed(1), ent(RS="BILLABLE"), kind="RENEWAL")).details.get("reason") == "invalid_state"
          and call("PUT", "/contracts/%s/termination" % W1["contract_ref"], {"base": 3, "terminated_on": "2026-11-01", "reason": "r"}, token=TOK).details.get("reason") == "invalid_state"
          and call("POST", "/contracts/%s/versions/%s/void" % (W1["contract_ref"], wv["contract_version_ref"]), {"base": 3, "reason": "again"}, token=TOK).meta["applied"] is False)
    check("a VOIDED contract frees its contract number (the number can be reused in the organization)", mkc(OW, "reuse", ver("2026-01-01", "2026-12-31", None, None), number="CT-%s-V" % U).status == 201)
    cdv = mkc(OW, "discard me %s" % U, ver("2026-01-01", "2026-12-31", None, None), number="CT-%s-D" % U).data
    r = call("POST", "/contracts/%s/draft/discard" % cdv["contract_ref"], {"base": 1, "reason": "created by mistake"}, token=TOK)
    check("discard the only draft of a never-activated contract -> the contract is VOIDED (I2), the DISCARDED draft is kept", r.status == 200 and r.data["status"] == "VOIDED" and c_one("select count(*) from %s where contract_id='%s' and status='DISCARDED'" % (CT["_version"], cdv["contract_ref"])) == 1)

    XQ = mk_eq(OX, "XQ")
    R1 = active(OX, "revoke R %s" % U, ver("2026-01-01", "2026-12-31", listed(XQ), ent(RS="INCLUDED_UNLIMITED")))
    r = call("PUT", "/contracts/%s/termination" % R1["contract_ref"], {"base": R1["rev"], "terminated_on": "2026-07-01", "reason": "left in June"}, token=TOK)
    S1 = active(OX, "revoke S %s" % U, ver("2026-07-01", "2026-12-31", listed(XQ), ent(RS="INCLUDED_UNLIMITED")))
    check("revoke scenario: after the termination another contract takes over July-December", r.status == 200 and S1["status"] == "ACTIVE")
    r = call("PUT", "/contracts/%s/termination" % R1["contract_ref"], {"base": r.data["rev"], "terminated_on": None, "reason": "terminated by mistake"}, token=TOK)
    check("revoking the termination would give those days back -> 409 overlap (revalidated on revoke, F-E4-04), the contract stays CANCELLED", r.status == 409 and r.details["reason"] == "overlap" and get_c(R1["contract_ref"]).data["status"] == "CANCELLED", r.raw[:250])

    # ---- resolution as_of: the nine outcomes -------------------------------------------------------------------------------------------------
    Z1, Z2, Z3 = mk_eq(OZ, "Z1"), mk_eq(OZ, "Z2"), mk_eq(OZ, "Z3")
    ZC = active(OZ, "resolution Z %s" % U, ver("2026-01-01", "2026-12-31", listed(Z1, Z2), ent(RS="INCLUDED_UNLIMITED", ONSITE="BILLABLE", PM=PMQ), response_policy=dict(GOLD, native_sla_ref=SLA1), catalog_version_ref=CAT1))
    zv = effv(ZC)[0]

    def res(org, eq, line, asof, tok=TOK):
        q = "/contracts/entitlement?as_of=%s" % urllib.parse.quote(asof)
        if org: q += "&organization_id=%d" % org
        if eq: q += "&equipment_id=%d" % eq
        if line: q += "&service_line=%s" % line
        return call("GET", q, token=tok)
    r = res(OZ, Z1, "REMOTE_SUPPORT", "2026-03-01")
    x = r.data["results"][0] if r.status == 200 else {}
    check("resolve COVERED: version, hash, treatment, tier, objective, schedule, catalog and recommended SLA come from the server", x.get("result") == "COVERED" and x["contract_version_ref"] == zv["contract_version_ref"] and x["contract_ref"] == ZC["contract_ref"] and x["terms_hash"] == zv["terms_hash"]
          and x["treatment"] == "INCLUDED_UNLIMITED" and x["tier_code"] == "Gold" and x["response_objective_hours"] == 4 and x["schedule"]["type"] == "24X7" and x["catalog_version_ref"] == CAT1 and x["native_sla_ref"] == SLA1
          and x["organization_id_at_evaluation"] == OZ and r.data["tz"], r.raw[:400])
    check("resolve COVERED with BILLABLE: still COVERED (a contract governs the event) and the treatment says it is billable", res(OZ, Z1, "ONSITE_LABOR", "2026-03-01").data["results"][0]["treatment"] == "BILLABLE")
    check("resolve NOT_INCLUDED: equipment in scope but the contract does not claim the line", res(OZ, Z1, "GENERAL_PARTS", "2026-03-01").data["results"][0]["result"] == "NOT_INCLUDED")
    check("resolve NOT_IN_SCOPE: equipment of the organization that the LISTED coverage does not name (not NO_CONTRACT)", res(OZ, Z3, "REMOTE_SUPPORT", "2026-03-01").data["results"][0]["result"] == "NOT_IN_SCOPE")
    check("resolve NO_CONTRACT: before the contract exists, and an organization with no contracts at all", res(OZ, Z1, "REMOTE_SUPPORT", "2025-06-01").data["results"][0]["result"] == "NO_CONTRACT" and res(OB, None, "REMOTE_SUPPORT", "2026-03-01").data["results"][0]["result"] == "NO_CONTRACT")
    x = res(OZ, Z1, "REMOTE_SUPPORT", "2027-03-01").data["results"][0]
    check("resolve EXPIRED: after the last day, with the day it ended", x["result"] == "EXPIRED" and x["ended_on"] == "2026-12-31")
    check("resolve EQUIPMENT_REQUIRED: a LISTED contract and no equipment indicated (never COVERED or NOT_IN_SCOPE)", res(OZ, None, "REMOTE_SUPPORT", "2026-03-01").data["results"][0]["result"] == "EQUIPMENT_REQUIRED")
    r = res(OZ, Z1, None, "2026-03-01")
    check("resolve without service_line -> the five lines in order", [y["service_line"] for y in r.data["results"]] == ["REMOTE_SUPPORT", "PREVENTIVE_MAINTENANCE", "ONSITE_LABOR", "GENERAL_PARTS", "SPECIALTY_PARTS"] and [y["result"] for y in r.data["results"]] == ["COVERED", "COVERED", "COVERED", "NOT_INCLUDED", "NOT_INCLUDED"])
    T1_, T2_ = mk_eq(OT, "T1"), mk_eq(OT, "T2")
    TC = active(OT, "org-all T %s" % U, ver("2026-01-01", "2026-12-31", org_all(), ent(RS="INCLUDED_UNLIMITED")))
    check("resolution WITHOUT equipment: a single ORG_ALL contract without exclusions covers the organization -> COVERED (equipment_ref null)", res(OT, None, "REMOTE_SUPPORT", "2026-03-01").data["results"][0]["result"] == "COVERED" and res(OT, None, "REMOTE_SUPPORT", "2026-03-01").data["results"][0]["equipment_ref"] is None
          and res(None, T1_, "REMOTE_SUPPORT", "2026-03-01").data["results"][0]["organization_id_at_evaluation"] == OT)
    r = call("PUT", "/contracts/%s/termination" % TC["contract_ref"], {"base": TC["rev"], "terminated_on": "2026-09-01", "reason": "ended"}, token=TOK)
    x = res(OT, T1_, "REMOTE_SUPPORT", "2026-10-01").data["results"][0]
    check("resolve TERMINATED from the termination date on; the day before it is still COVERED (terminated_on is exclusive)", x["result"] == "TERMINATED" and x["terminated_on"] == "2026-09-01" and res(OT, T1_, "REMOTE_SUPPORT", "2026-08-31").data["results"][0]["result"] == "COVERED")
    UQ1, UQ2 = mk_eq(OU, "U1"), mk_eq(OU, "U2")
    active(OU, "org-all excl U %s" % U, ver("2026-01-01", "2026-12-31", org_all(UQ2), ent(RS="INCLUDED_UNLIMITED")))
    check("ORG_ALL with an excluded equipment: excluded -> NOT_IN_SCOPE; others COVERED; no equipment -> EQUIPMENT_REQUIRED (the scope depends on the equipment)",
          res(OU, UQ2, "REMOTE_SUPPORT", "2026-03-01").data["results"][0]["result"] == "NOT_IN_SCOPE" and res(OU, UQ1, "REMOTE_SUPPORT", "2026-03-01").data["results"][0]["result"] == "COVERED"
          and res(OU, None, "REMOTE_SUPPORT", "2026-03-01").data["results"][0]["result"] == "EQUIPMENT_REQUIRED")
    M1Q = mk_eq(OM1, "M1q")
    active(OM1, "listed M1 %s" % U, ver("2026-01-01", "2026-12-31", listed(M1Q), ent(RS="INCLUDED_UNLIMITED")))
    active(OM2, "org-all M2 %s" % U, ver("2026-01-01", "2026-12-31", org_all(M1Q), ent(RS="INCLUDED_UNLIMITED")))
    sql("update ost_list_items set extra='BCW|1|O=%d|E=1' where id=%d" % (OM2, M1Q))      # the equipment now belongs to M2 (M7 keeps no history)
    x = res(OM2, M1Q, "REMOTE_SUPPORT", "2026-03-01").data["results"][0]
    check("resolve ORG_MISMATCH: the equipment now belongs to another organization but a LISTED version of the previous one still names it (evaluation org = the equipment's CURRENT org)", x["result"] == "ORG_MISMATCH" and x["organization_id_at_evaluation"] == OM2 and x["contract_ref"] is None, str(x)[:300])
    NQ = mk_eq(ON, "NQ")
    active(ON, "ambiguous N1 %s" % U, ver("2026-01-01", "2026-12-31", listed(NQ), ent(RS="INCLUDED_UNLIMITED")))
    N2 = active(ON, "ambiguous N2 %s" % U, ver("2027-01-01", "2027-12-31", org_all(), ent(RS="INCLUDED_UNLIMITED")))
    n2v = effv(N2)[0]["contract_version_ref"]
    try:
        sql("update %s set effective_from='2026-01-01', effective_to='2026-12-31', eff_from_key='2026-01-01' where id='%s'" % (CT["_version"], n2v))     # tamper: the API cannot produce it (CT-D8)
        x = res(ON, NQ, "REMOTE_SUPPORT", "2026-03-01").data["results"][0]
        check("resolve AMBIGUOUS: two applicable versions claim the line (only possible if CT-D8 was violated) -> no version is chosen, both listed as candidates", x["result"] == "AMBIGUOUS" and x["contract_version_ref"] is None and len(x["candidates"]) == 2, str(x)[:300])
    finally:
        sql("update %s set status='VOIDED', ext_key=NULL where id='%s'" % (CT[""], N2["contract_ref"]))
    check("a VOIDED contract is ignored by the resolver (cleanup of the tampered fixture: NO_CONTRACT for it, N1 back to COVERED)", res(ON, NQ, "REMOTE_SUPPORT", "2026-03-01").data["results"][0]["result"] == "COVERED")
    check("resolve validation: unknown line/equipment, bad as_of, missing organization -> 422",
          res(OZ, Z1, "COFFEE", "2026-03-01").status == 422 and res(OZ, 987654321, "REMOTE_SUPPORT", "2026-03-01").status == 422 and res(OZ, Z1, "REMOTE_SUPPORT", "yesterday").status == 422
          and res(None, EXO, "REMOTE_SUPPORT", "2026-03-01").status == 422)
    # installation time zone (CT-D9): the local day of the instant, not UTC and not the staff's
    sql("update ost_config set value='America/Mexico_City' where namespace='core' and `key`='default_timezone'")
    try:
        r = res(OZ, Z1, "REMOTE_SUPPORT", "2026-12-31T23:30:00-06:00")
        x = r.data["results"][0] if r.status == 200 else {}
        check("as_of is evaluated on the INSTALLATION's local day: 2026-12-31 23:30 Mexico City (= 2027-01-01 05:30Z) is still 2026 -> COVERED, as_of_local_date 2026-12-31, tz in the answer",
              r.status == 200 and r.data["as_of_local_date"] == "2026-12-31" and r.data["tz"] == "America/Mexico_City" and x["result"] == "COVERED" and r.data["as_of"] == "2027-01-01T05:30:00Z", r.raw[:300])
        f = call("GET", "/contracts?cursor=" + CUR0, token=TOK)
        check("the feed carries the installation time zone (tz) and today's local date", f.meta["tz"] == "America/Mexico_City" and re.match(r"^\d{4}-\d{2}-\d{2}$", f.meta["today"]))
    finally:
        sql("update ost_config set value='%s' where namespace='core' and `key`='default_timezone'" % (TZ_BEFORE71 or "UTC"))
    x = res(OZ, Z1, "REMOTE_SUPPORT", "2026-12-31T23:30:00-06:00").data
    check("...and the same instant in UTC is 2027-01-01 -> EXPIRED (the zone is a setting of the installation)", x["as_of_local_date"] == "2027-01-01" and x["results"][0]["result"] == "EXPIRED")

    # ---- documents: PDF, durable link, controlled title, ACL ----------------------------------------------------------------------------------
    zrev = ZC["rev"]
    pdf1 = pdf_bytes("doc1")
    up = upload(pdf1, "firmado-%s.pdf" % U)
    FID = up.data["file_id"]
    zvid = zv["contract_version_ref"]
    path_doc = "/contracts/%s/versions/%s/documents" % (ZC["contract_ref"], zvid)
    check("document: non-manager -> 403; no reason -> 422; bad role -> 422; file_id not uploaded by this agent -> 422",
          call("POST", path_doc, {"base": zrev, "reason": "r", "role": "CONTRACT", "file_id": FID}, token=TOK3).status == 403
          and call("POST", path_doc, {"base": zrev, "role": "CONTRACT", "file_id": FID}, token=TOK).status == 422
          and call("POST", path_doc, {"base": zrev, "reason": "r", "role": "SECRET", "file_id": FID}, token=TOK).status == 422
          and call("POST", path_doc, {"base": zrev, "reason": "r", "role": "CONTRACT", "file_id": 987654321}, token=TOK).status == 422)
    txt = upload(b"just text %s" % U.encode(), "notes-%s.txt" % U, "text/plain")
    png = upload(base64.b64decode("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=="), "p-%s.png" % U, "image/png")
    r1 = call("POST", path_doc, {"base": zrev, "reason": "r", "role": "CONTRACT", "file_id": txt.data["file_id"]}, token=TOK)
    r2 = call("POST", path_doc, {"base": zrev, "reason": "r", "role": "CONTRACT", "file_id": png.data["file_id"]}, token=TOK)
    check("document PDF validation: a text file and an image are refused 415 unsupported_type (only %PDF- files link to a contract)", r1.status == 415 and r1.err == "unsupported_type" and r2.status == 415)
    for t_, why in (("firmado-%s.pdf" % U, "the file name"), ("Contrato $1,200 USD", "an amount")):
        r = call("POST", path_doc, {"base": zrev, "reason": "r", "role": "CONTRACT", "file_id": FID, "display_title": t_}, token=TOK)
        check("document display_title is controlled: %s -> 422" % why, r.status == 422 and r.json["error"]["field"] == "display_title", r.raw[:200])
    r = call("POST", path_doc, {"base": zrev - 1, "reason": "r", "role": "CONTRACT", "file_id": FID}, token=TOK)
    check("document with a stale base -> 409", r.status == 409 and r.details["current"] == zrev)
    r = call("POST", path_doc, {"base": zrev, "reason": "signed original", "role": "CONTRACT", "file_id": FID}, token=TOK)
    zc = r.data if r.status == 201 else {}
    zd = [d for v in zc.get("versions", []) for d in v["documents"]]
    check("add document -> 201: display_title defaults to the role label (never the file name), sha256 = the file's, ACTIVE; terms and terms_hash unchanged; event DOCUMENT_ADDED",
          r.status == 201 and len(zd) == 1 and zd[0]["display_title"] == "Contrato" and zd[0]["sha256"] == hashlib.sha256(pdf1).hexdigest() and zd[0]["status"] == "ACTIVE" and zd[0]["size"] == len(pdf1)
          and effv(zc)[0]["terms_hash"] == zv["terms_hash"] and zc["rev"] == zrev + 1 and evs(ZC["contract_ref"])[-1]["kind"] == "DOCUMENT_ADDED" and "sha256" not in json.dumps(evs(ZC["contract_ref"])[-1]), r.raw[:400])
    ZD1 = zd[0]["document_ref"]
    link = c_one("select count(*) from ost_attachment a join %s d on d.seq=a.object_id and a.type='W' and a.file_id=d.file_id where d.id='%s'" % (CT["_document"], ZD1))
    check("document durable link: a core `attachment` row (type W) ties the file to the document, so it is not an orphan", link == 1)
    # the core's daily cleanup of unattached uploads must not take it
    ctl = upload(pdf_bytes("orphan-control"), "orphan-%s.pdf" % U)
    CTL = ctl.data["file_id"]
    sql("update ost_file set created=DATE_SUB(NOW(), INTERVAL 3 DAY) where id in (%d,%d)" % (FID, CTL))
    pr = subprocess.run([PHP80_, os.path.join(TOOLS_, "purge-check.php"), APP_, "orphans"], capture_output=True, text=True)
    check("file durability vs the cron: after the core's orphan cleanup (files older than a day) the linked contract PDF is still stored; an unlinked control upload of the same age is gone",
          pr.stdout.strip() == "orphans" and c_one("select count(*) from ost_file where id=%d" % FID) == 1 and c_one("select count(*) from ost_file where id=%d" % CTL) == 0, pr.stdout + pr.stderr)
    dl = call("GET", "/contracts/%s/documents/%s/file" % (ZC["contract_ref"], ZD1), token=TOK)
    check("download by an administrator: the exact PDF bytes, application/pdf, attachment, nosniff + CSP", dl.status == 200 and dl.raw.startswith("%PDF-1.4") and dl.headers.get("Content-Type") == "application/pdf" and dl.headers.get("Content-Disposition", "").startswith("attachment;")
          and dl.headers.get("X-Content-Type-Options") == "nosniff" and len(dl.raw) == len(pdf1))
    check("download of a document through another contract's path -> 404; unknown document -> 404", call("GET", "/contracts/%s/documents/%s/file" % (C1, ZD1), token=TOK).status == 404 and call("GET", "/contracts/%s/documents/%s/file" % (ZC["contract_ref"], str(uuid.uuid4())), token=TOK).status == 404)
    # supersede
    up2 = upload(pdf_bytes("doc2"), "v2-%s.pdf" % U)
    r = call("POST", path_doc, {"base": zrev + 1, "reason": "signed original replaced", "role": "CONTRACT", "file_id": up2.data["file_id"], "display_title": "Contrato firmado", "supersedes": ZD1}, token=TOK)
    zd = [d for d in r.data["versions"][0]["documents"]] if r.status == 201 else []
    check("replace a document: the old one becomes SUPERSEDED and is kept (with its file), the new one is ACTIVE; events DOCUMENT_ADDED + DOCUMENT_SUPERSEDED",
          r.status == 201 and [d["status"] for d in zd] == ["SUPERSEDED", "ACTIVE"] and zd[0]["superseded_by"] == zd[1]["document_ref"] and zd[1]["display_title"] == "Contrato firmado"
          and "DOCUMENT_SUPERSEDED" in [e["kind"] for e in evs(ZC["contract_ref"])] and c_one("select count(*) from ost_file where id=%d" % FID) == 1, r.raw[:300])
    check("the same PDF cannot be linked twice as an active document of one version -> 409 duplicate_document; but it can be linked to ANOTHER version",
          call("POST", path_doc, {"base": r.data["rev"], "reason": "r", "role": "ANNEX", "file_id": up2.data["file_id"]}, token=TOK).details.get("reason") == "duplicate_document")
    zrev = r.data["rev"]
    # amendment draft on ZC (a DRAFT is hidden from readers); documents can be added to a DRAFT version
    r = putd(ZC["contract_ref"], None, ver("2026-07-01", "2026-12-31", listed(Z1, Z2), ent(RS="INCLUDED_UNLIMITED", ONSITE="INCLUDED_UNLIMITED"), kind="AMENDMENT"))
    zdraft = drv(r.data)
    up3 = upload(pdf_bytes("annex"), "annex-%s.pdf" % U)
    r = call("POST", "/contracts/%s/versions/%s/documents" % (ZC["contract_ref"], zdraft["contract_version_ref"]), {"base": zrev, "reason": "annex for the amendment", "role": "AMENDMENT", "file_id": up3.data["file_id"]}, token=TOK)
    check("documents can be added to a DRAFT version; the draft's rev and terms are not touched", r.status == 201 and drv(r.data)["rev"] == zdraft["rev"] and len(drv(r.data)["documents"]) == 1)
    zrev = r.data["rev"]

    # ---- read authorization over the Organization, reader view, ACL -----------------------------------------------------------------------------
    f3 = call("GET", "/contracts?cursor=" + CUR0, token=TOK3)
    check("reader without authorization over the organization: the contract is 404 (same as unknown), absent from the feed, entitlement 403, events 404",
          get_c(ZC["contract_ref"], TOK3).status == 404 and ZC["contract_ref"] not in [c["contract_ref"] for c in f3.data] and res(OZ, Z1, "REMOTE_SUPPORT", "2026-03-01", TOK3).status == 403
          and call("GET", "/contracts/%s/events" % ZC["contract_ref"], token=TOK3).status == 404)
    scope_before = f3.meta["scope"]
    uz = call("POST", "/users", {"name": "E2E ow71 %s" % U, "email": "e2e-ow71-%s@example.com" % U, "org_id": OZ}, token=TOK)
    UZ = uz.data["id"]
    TKZ = call("POST", "/tickets", {"subject": "e2e ow71 zone %s" % U, "message": "m", "topic_id": 1, "user_id": UZ, "dept_id": 1}, token=TOK).data["id"]
    f3 = call("GET", "/contracts?cursor=" + CUR0, token=TOK3)
    check("authorization over the Organization = the existing directory primitive (a Ticket of the organization the agent can see): the agent now reads the contract, feed and resolution; the feed scope changed",
          get_c(ZC["contract_ref"], TOK3).status == 200 and ZC["contract_ref"] in [c["contract_ref"] for c in f3.data] and res(OZ, Z1, "REMOTE_SUPPORT", "2026-03-01", TOK3).status == 200 and f3.meta["scope"] != scope_before)
    cr = get_c(ZC["contract_ref"], TOK3).data
    ra = [d for v in cr["versions"] for d in v["documents"]]
    check("reader view: existence, display_title, role, status and date of every document - no hash, size, author, URL or path; drafts are hidden from readers",
          ra and all(set(d) == {"document_ref", "contract_version_ref", "role", "display_title", "status", "registered_at"} for d in ra) and not [v for v in cr["versions"] if v["status"] == "DRAFT"]
          and "sha256" not in json.dumps(cr) and "download" not in json.dumps(cr) and [v for v in get_c(ZC["contract_ref"]).data["versions"] if v["status"] == "DRAFT"])
    check("reader sees the history (events) but not the PDF: download -> 403 manager_required; the reader's document list never exposes the file name",
          call("GET", "/contracts/%s/events" % ZC["contract_ref"], token=TOK3).status == 200 and call("GET", "/contracts/%s/documents/%s/file" % (ZC["contract_ref"], ZD1), token=TOK3).details.get("reason") == "manager_required"
          and ("firmado-%s" % U) not in json.dumps(cr) and ("firmado-%s" % U) not in json.dumps(get_c(ZC["contract_ref"]).data))
    check("the file hash of the PDF is not reachable by a reader through the generic file route either (GET /files/{hash} -> 403 for an agent that is not the uploader)",
          call("GET", "/files/%s" % up.data["hash"], token=TOK3).status == 403 and call("GET", "/files/%s" % up.data["hash"], token=TOK).status == 200)
    check("agent2 (limited, not a manager): writes are 403 and a contract of an organization she cannot read is 404", call("POST", "/contracts/%s/activate" % ZC["contract_ref"], {"base": 1, "reason": "r"}, token=TOK2).status == 403
          and get_c(ZC["contract_ref"], TOK2).status in (404, 200))
    sql("update ost_department set manager_id=3 where id=3")          # agent3 manages ANOTHER department (its own is dept 1)
    try:
        cm = get_c(ZC["contract_ref"], TOK3).data
        check("manager of ANY department (not the primary one) -> reads everything: drafts, hashes, authors and download paths appear; the PDF downloads", [v for v in cm["versions"] if v["status"] == "DRAFT"] and "sha256" in json.dumps(cm)
              and call("GET", "/contracts/%s/documents/%s/file" % (ZC["contract_ref"], ZD1), token=TOK3).status == 200)
        rm = mkc(OB, "by manager %s" % U, ver("2026-01-01", "2026-12-31", None, None), tok=TOK3)
        check("manager of ANY department may create contracts (the OW-REQ-70 predicate; isManager() would only cover the primary department)", rm.status == 201 and rm.data["created_by"] == 3, rm.raw[:200])
        fm = call("GET", "/contracts?cursor=" + CUR0, token=TOK3)
        check("the feed of a manager covers every organization (scope=all)", fm.meta["scope"] == "all" and ZC["contract_ref"] in [c["contract_ref"] for c in fm.data] and C1 in [c["contract_ref"] for c in fm.data])
    finally:
        sql("update ost_department set manager_id=0 where id=3")
    check("...and the permission is gone once the manager role is removed (403 again)", mkc(OB, "x", ver("2026-01-01", "2026-12-31", None, None), tok=TOK3).status == 403)

    # ---- feed: cursor, watermark, ETag, tz -------------------------------------------------------------------------------------------------------------------
    f = call("GET", "/contracts?cursor=" + CUR0, token=TOK)
    wm = f.meta["watermark"]; et = f.headers.get("ETag")
    check("feed: every contract of this run with versions, documents, rev; meta watermark, tz, scope, catalog_number; ETag header; no organization names (ids only)",
          f.status == 200 and f.meta["count"] == len(f.data) and C1 in [c["contract_ref"] for c in f.data] and wm and et and f.meta["tz"] and f.meta["catalog_number"] >= 1
          and all(isinstance(c["organization_id"], int) and "organization" not in c for c in f.data) and "name" not in f.data[0])
    f2 = call("GET", "/contracts?cursor=" + urllib.parse.quote(wm), token=TOK)
    check("feed: the watermark returns nothing until something changes", f2.status == 200 and f2.data == [] and f2.meta["watermark"] == wm)
    r304 = call("GET", "/contracts?cursor=" + CUR0, token=TOK, headers={"If-None-Match": et})
    check("feed: If-None-Match with the current ETag -> 304 without a body", r304.status == 304 and r304.raw == "")
    call("PATCH", "/contracts/%s" % C4["contract_ref"], {"base": 2, "title": "feed touch %s" % U, "reason": "touch"}, token=TOK)
    f2 = call("GET", "/contracts?cursor=" + urllib.parse.quote(wm), token=TOK)
    check("feed: after a change the watermark returns exactly that contract, and the ETag differs", [c["contract_ref"] for c in f2.data] == [C4["contract_ref"]] and f2.meta["watermark"] != wm and call("GET", "/contracts?cursor=" + CUR0, token=TOK, headers={"If-None-Match": et}).status == 200)
    check("feed: invalid cursor -> 422", call("GET", "/contracts?cursor=garbage", token=TOK).status == 422)
    check("history: events are paged by seq (cursor) in order and never change - GET /contracts/{id}/events", [e["seq"] for e in evs(C1)] == sorted(e["seq"] for e in evs(C1)) and len(evs(C1)) >= 10
          and call("GET", "/contracts/%s/events?limit=2" % C1, token=TOK).meta["has_more"] is True)

    # ---- entitlement bases (decision points), resolved in the server -----------------------------------------------------------------------------------
    BZ = mk_eq(OBZ, "Bz1")
    ucb = call("POST", "/users", {"name": "E2E ow71 bz %s" % U, "email": "e2e-ow71-bz-%s@example.com" % U, "org_id": OBZ}, token=TOK).data["id"]
    BC = active(OBZ, "basis %s" % U, ver(D(-60), D(300), listed(BZ), ent(RS="INCLUDED_UNLIMITED"), response_policy=GOLD, catalog_version_ref=CAT1))
    bv1 = effv(BC)[0]
    TK = call("POST", "/tickets", {"subject": "e2e ow71 basis %s" % U, "message": "m", "topic_id": 1, "user_id": ucb, "dept_id": 1}, token=TOK).data["id"]
    TKS = call("POST", "/tickets", {"subject": "e2e ow71 sales %s" % U, "message": "m", "topic_id": 1, "user_id": ucb, "dept_id": 2}, token=TOK).data["id"]
    bp = "/tickets/%d/entitlement-bases" % TK
    seen_ok = {"contract_version_ref": bv1["contract_version_ref"], "result": "COVERED", "cache_etag": "etag-1"}
    check("basis validation: purpose, service_line, base are required -> 422; unknown equipment -> 422",
          call("POST", bp, {"base": None, "service_line": "REMOTE_SUPPORT", "purpose": "TICKET_OPEN"}, token=TOK).status == 422 and call("POST", bp, {"base": None, "purpose": "RS_CLASSIFICATION"}, token=TOK).status == 422
          and call("POST", bp, {"purpose": "RS_CLASSIFICATION", "service_line": "REMOTE_SUPPORT"}, token=TOK).json["error"]["field"] == "base"
          and call("POST", bp, {"base": None, "purpose": "RS_CLASSIFICATION", "service_line": "REMOTE_SUPPORT", "equipment_ref": 987654321}, token=TOK).status == 422)
    r = call("POST", bp, {"base": None, "purpose": "RS_CLASSIFICATION", "service_line": "REMOTE_SUPPORT", "equipment_ref": BZ, "code": "RS-COV", "seen": seen_ok, "reason": "classified by the reviewer",
                          "tier_code": "FAKE", "result": "NOT_INCLUDED", "as_of": "2001-01-01T00:00:00Z", "contract_version_ref": str(uuid.uuid4())}, token=TOK)
    b1 = r.data if r.status == 201 else {}
    check("basis -> 201 resolved by the SERVER: result COVERED, version/hash/tier/objective copied from the resolved version (client values ignored), as_of = the Ticket's time (not the client's), organization and SLA of the Ticket recorded",
          r.status == 201 and b1["result"] == "COVERED" and b1["contract_version_ref"] == bv1["contract_version_ref"] and b1["terms_hash"] == bv1["terms_hash"] and b1["tier_code"] == "Gold" and b1["response_objective_hours"] == 4 and b1["treatment"] == "INCLUDED_UNLIMITED"
          and b1["catalog_version_ref"] == CAT1 and b1["code"] == "RS-COV" and b1["consumer"] == "ticket:%d" % TK and b1["purpose"] == "RS_CLASSIFICATION" and b1["as_of"].startswith(TODAY.isoformat()[:4]) and b1["as_of_local_date"] == TODAY.isoformat()
          and b1["detail"]["as_of_source"] == "ticket_created" and b1["organization_id_at_evaluation"] == OBZ and b1["equipment_ref"] == BZ and b1["supersedes"] is None and b1["recorded_by"] == 1 and "ticket_sla_id_at_evaluation" in b1
          and b1["seen_mismatch"] is False and b1["reason"] == "classified by the reviewer" and b1["tz"], r.raw[:600])
    r = call("POST", bp, {"base": None, "purpose": "RS_CLASSIFICATION", "service_line": "REMOTE_SUPPORT", "equipment_ref": BZ}, token=TOK)
    check("basis with a stale base (a basis already exists) -> 409 base_mismatch with the current id and record", r.status == 409 and r.details["reason"] == "base_mismatch" and r.details["current"] == b1["id"] and r.details["record"]["id"] == b1["id"])
    # stale cache: an amendment (retroactive, new tier) is registered AFTER the user's cache; the server resolves the authoritative version
    rr = putd(BC["contract_ref"], None, ver(D(-10), D(300), listed(BZ), ent(RS="INCLUDED_UNLIMITED"), kind="AMENDMENT", response_policy={"tier_code": "Silver", "schedule": {"type": "24X7"}, "response_objective_hours": 8}, catalog_version_ref=CAT1))
    BC = act(BC["contract_ref"], drv(rr.data)["rev"]).data
    bv2 = [v for v in BC["versions"] if v["number"] == 2][0]
    r = call("POST", bp, {"base": b1["id"], "purpose": "RS_CLASSIFICATION", "service_line": "REMOTE_SUPPORT", "equipment_ref": BZ, "code": "RS-BILL", "seen": seen_ok}, token=TOK)
    b2 = r.data if r.status == 201 else {}
    check("basis with a stale CACHE: the user saw v1, the server resolves v2 -> stored with v2 (tier Silver), seen_mismatch=true, seen_* kept as evidence, supersedes = the previous basis",
          r.status == 201 and b2["contract_version_ref"] == bv2["contract_version_ref"] and b2["tier_code"] == "Silver" and b2["response_objective_hours"] == 8 and b2["seen_mismatch"] is True and b2["seen_contract_version_ref"] == bv1["contract_version_ref"]
          and b2["seen_result"] == "COVERED" and b2["seen_cache_etag"] == "etag-1" and b2["supersedes"] == b1["id"], r.raw[:500])
    r = call("POST", bp, {"base": b2["id"], "purpose": "RS_CLASSIFICATION", "service_line": "REMOTE_SUPPORT", "seen": {"contract_version_ref": bv2["contract_version_ref"], "result": "EQUIPMENT_REQUIRED"}}, token=TOK)
    b3 = r.data if r.status == 201 else {}
    check("basis WITHOUT equipment on a LISTED contract -> EQUIPMENT_REQUIRED stored (a result that does not lie), no version reference; seen_mismatch compares the result too",
          r.status == 201 and b3["result"] == "EQUIPMENT_REQUIRED" and b3["contract_version_ref"] is None and b3["equipment_ref"] is None and b3["seen_mismatch"] is True)
    g = call("GET", bp, token=TOK)
    check("GET bases (ticket.view): the whole chain oldest first; meta.current = the latest id", g.status == 200 and [b["id"] for b in g.data] == [b1["id"], b2["id"], b3["id"]] and g.meta["current"]["RS_CLASSIFICATION"] == b3["id"])
    before = json.dumps(call("GET", bp, token=TOK).data, sort_keys=True)
    # a correction of the contract afterwards never rewrites a stored basis
    rr = putd(BC["contract_ref"], None, ver(D(-10), D(300), listed(BZ), ent(RS="BILLABLE"), kind="CORRECTION", supersedes=bv2["contract_version_ref"], response_policy={"tier_code": "Bronze", "schedule": {"type": "24X7"}, "response_objective_hours": 24}, catalog_version_ref=CAT1))
    BC = act(BC["contract_ref"], drv(rr.data)["rev"], reason="tier captured wrong").data
    check("a later CORRECTION voids v2 but the stored bases are byte-for-byte unchanged (immutable; the new reading is a new basis)", json.dumps(call("GET", bp, token=TOK).data, sort_keys=True) == before
          and [v["status"] for v in BC["versions"]] == ["EFFECTIVE", "VOIDED", "EFFECTIVE"])
    r = call("POST", bp, {"base": b3["id"], "purpose": "RS_CLASSIFICATION", "service_line": "REMOTE_SUPPORT", "equipment_ref": BZ}, token=TOK)
    check("a new basis after the correction resolves the CURRENT knowledge (v3, BILLABLE, Bronze) and supersedes the previous", r.status == 201 and r.data["tier_code"] == "Bronze" and r.data["treatment"] == "BILLABLE" and r.data["supersedes"] == b3["id"], r.raw[:300])
    check("no route rewrites or deletes a basis (PUT/PATCH/DELETE -> 404/405)", all(call(m, bp + "/" + b1["id"], {}, token=TOK).status in (404, 405) for m in ("PUT", "PATCH", "DELETE")) and all(call(m, bp, {}, token=TOK).status in (404, 405) for m in ("PUT", "PATCH", "DELETE")))
    check("basis permissions: write needs ticket.edit on the consumer Ticket (agent2 Limited -> 403; agent3 Expanded -> allowed); read needs ticket.view (a Ticket of another department -> 403)",
          call("POST", bp, {"base": None, "purpose": "RS_CLASSIFICATION", "service_line": "REMOTE_SUPPORT"}, token=TOK2).status == 403
          and call("GET", "/tickets/%d/entitlement-bases" % TKS, token=TOK3).status == 403 and call("POST", "/tickets/%d/entitlement-bases" % TKS, {"base": None, "purpose": "RS_CLASSIFICATION", "service_line": "REMOTE_SUPPORT"}, token=TOK3).status == 403
          and call("GET", bp, token=TOK3).status == 200)
    ubn = call("POST", "/users", {"name": "E2E ow71 bn %s" % U, "email": "e2e-ow71-bn-%s@example.com" % U, "org_id": OB}, token=TOK).data["id"]
    TKN = call("POST", "/tickets", {"subject": "e2e ow71 nobody %s" % U, "message": "m", "topic_id": 1, "user_id": ubn, "dept_id": 1}, token=TOK).data["id"]
    r = call("POST", "/tickets/%d/entitlement-bases" % TKN, {"base": None, "purpose": "RS_CLASSIFICATION", "service_line": "REMOTE_SUPPORT", "equipment_ref": BZ}, token=TOK3)
    check("basis by agent3 (ticket.edit) on a Ticket whose contact has no contract: the equipment's organization decides (BZ -> its contract), recorded_by = agent3", r.status == 201 and r.data["recorded_by"] == 3 and r.data["organization_id_at_evaluation"] == OBZ and r.data["result"] == "COVERED", r.raw[:300])
    TKC = call("POST", "/tickets", {"subject": "e2e ow71 none %s" % U, "message": "m", "topic_id": 1, "user_id": ubn, "dept_id": 1}, token=TOK).data["id"]
    r = call("POST", "/tickets/%d/entitlement-bases" % TKC, {"base": None, "purpose": "RS_CLASSIFICATION", "service_line": "REMOTE_SUPPORT"}, token=TOK)
    check("basis for a Ticket whose organization has no contracts and no equipment -> NO_CONTRACT with null references", r.status == 201 and r.data["result"] == "NO_CONTRACT" and r.data["contract_ref"] is None and r.data["contract_version_ref"] is None)
    parallel = [None, None]
    def go_b(i): parallel[i] = call("POST", "/tickets/%d/entitlement-bases" % TKC, {"base": r.data["id"], "purpose": "RS_CLASSIFICATION", "service_line": "REMOTE_SUPPORT"}, token=TOK)
    th = [threading.Thread(target=go_b, args=(i,)) for i in (0, 1)]
    for t in th: t.start()
    for t in th: t.join()
    check("two reclassifications racing on the same base -> one 201, one 409 (unique chain link, no database error)", sorted(p.status for p in parallel) == [201, 409], str([p.raw[:100] for p in parallel]))

    # ---- durability --------------------------------------------------------------------------------------------------------------------------------------------
    cnt_before = {k: c_one("select count(*) from %s" % v) for k, v in CT.items()}
    ev_before = [x for x in (sql("select id,kind,reason,detail from %s order by seq" % CT["_event"]) or [])]
    check("tables exist (six), separate from the idempotency table, each with the schema_version column", all(sql("select table_name from information_schema.tables where table_schema=database() and table_name='%s'" % t) == [t] for t in CT.values())
          and all(sql("select count(*) from information_schema.columns where table_schema=database() and table_name='%s' and column_name='schema_version'" % t) == ["1"] for t in CT.values()))
    check("every row of every table carries schema_version=1", all(sql("select count(*) from %s where schema_version<>1" % t) == ["0"] for t in CT.values()))
    keys = sql("select table_name,index_name from information_schema.statistics where table_schema=database() and table_name like 'ost_workflow_contract%' and non_unique=0")
    check("the database holds the invariants: unique keys for one open draft, one EFFECTIVE start date, contract number per organization, catalog number and basis chain link",
          all(any(k.split("\t")[1] == n and k.split("\t")[0] == t for k in keys) for t, n in ((CT["_version"], "uq_one_draft"), (CT["_version"], "uq_eff_from"), (CT[""], "uq_org_number"), (CT["_catalog"], "uq_number"), (CT["_basis"], "uq_chain"))))
    sql("update ost_workflow_idempotency set expires=DATE_SUB(NOW(), INTERVAL 1 DAY) where idem_key='%s'" % KEYC)
    pr = subprocess.run([PHP80_, os.path.join(TOOLS_, "purge-check.php"), APP_], capture_output=True, text=True)
    check("the plugin's cron purge ran (it removed the expired idempotency record) and did not touch a single contracts-store row (no TTL, no purge)",
          pr.stdout.strip() == "purged" and sql("select count(*) from ost_workflow_idempotency where idem_key='%s'" % KEYC) == ["0"] and {k: c_one("select count(*) from %s" % v) for k, v in CT.items()} == cnt_before, pr.stdout + pr.stderr)
    check("no hard delete: no contracts route uses DELETE (405); the discarded, voided and superseded records of this run are all still stored",
          call("DELETE", "/contracts/%s" % C1, token=TOK).status == 405 and call("DELETE", "/contracts/%s/draft" % C1, token=TOK).status == 405 and call("DELETE", "/contracts/%s/documents/%s/file" % (ZC["contract_ref"], ZD1), token=TOK).status == 405
          and c_one("select count(*) from %s where status='DISCARDED'" % CT["_version"]) >= 2 and c_one("select count(*) from %s where status='VOIDED'" % CT[""]) >= 2 and c_one("select count(*) from %s where status='SUPERSEDED'" % CT["_document"]) >= 1)
    check("append-only events: nothing in this run rewrote an event (ids, kinds, reasons and details are as first read) and every event row has an actor and a server time",
          sql("select count(*) from %s where actor=0 or at is null" % CT["_event"]) == ["0"] and [x for x in (sql("select id,kind,reason,detail from %s order by seq" % CT["_event"]) or [])] == ev_before)
    check("enable() is idempotent: re-running it keeps the tables and every row", subprocess.run([PHP80_, os.path.join(TOOLS_, "plugin-admin.php"), APP_, "enable"], capture_output=True, text=True).stdout.startswith("enabled")
          and {k: c_one("select count(*) from %s" % v) for k, v in CT.items()} == cnt_before)
    c_setting(SET_BEFORE71)
    # fixture state: organizations, equipment items, contacts, tickets, contracts, PDFs and catalog versions are RETAINED INTENTIONALLY (rows are never deleted by design)

# ------------------------------------------------------------------ login throttling
print("\n[login throttling per user + real IP]")
nm = "e2e-nobody-" + U
codes = [call("POST", "/auth/login", {"username": nm, "password": "x"}, key=None, headers={"X-Forwarded-For": "203.0.113.%d" % (int(U[:2], 16) % 200 + 1)}).status for _ in range(6)]
check("5 failures lock that user+IP (6th is 429)", codes[:5] == [401] * 5 and codes[5] == 429, str(codes))
other = call("POST", "/auth/login", {"username": nm + "-b", "password": "x"}, key=None, headers={"X-Forwarded-For": "203.0.113.%d" % (int(U[:2], 16) % 200 + 1)})
check("another user from the same IP is not locked", other.status == 401)

print("\nresult: %d passed, %d failed, %d skipped" % (passed, failed, skipped))
sys.exit(1 if failed else 0)
