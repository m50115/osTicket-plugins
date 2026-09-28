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
check("sync: visible ids", TID in call("GET", "/sync/visible-ticket-ids", token=TOK).data)
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
FROZEN_ROUTES = 102
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

# ------------------------------------------------------------------ login throttling
print("\n[login throttling per user + real IP]")
nm = "e2e-nobody-" + U
codes = [call("POST", "/auth/login", {"username": nm, "password": "x"}, key=None, headers={"X-Forwarded-For": "203.0.113.%d" % (int(U[:2], 16) % 200 + 1)}).status for _ in range(6)]
check("5 failures lock that user+IP (6th is 429)", codes[:5] == [401] * 5 and codes[5] == 429, str(codes))
other = call("POST", "/auth/login", {"username": nm + "-b", "password": "x"}, key=None, headers={"X-Forwarded-For": "203.0.113.%d" % (int(U[:2], 16) % 200 + 1)})
check("another user from the same IP is not locked", other.status == 401)

print("\nresult: %d passed, %d failed, %d skipped" % (passed, failed, skipped))
sys.exit(1 if failed else 0)
