#!/usr/bin/env python3
"""
End-to-end regression suite for ost-workflow (HTTP, against a running sandbox).

  python3 e2e.py [BASE]           default BASE = http://127.0.0.1:8090/api/workflow/v1
  env: SB_ADMIN_USER / SB_ADMIN_PASS (default: read from ~/development/ost-sandbox/credentials.env)
       AGENT2_USER / AGENT2_PASS  (a Limited-Access agent; if missing, the permission checks are skipped)

Each run creates its own data (unique emails/subjects); nothing is deleted afterwards.
Covers the regression chains RC-* of the Technical Notes: routing, auth/tokens, idempotency and crash recovery,
base values, candidates, threads/files, sync, tasks, sandbox-only checks skipped when the DB is not reachable.
Exit code 0 = all checks passed.
"""
import json, os, sys, time, uuid, urllib.request, urllib.error, re, subprocess

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
A2 = (os.environ.get("AGENT2_USER"), os.environ.get("AGENT2_PASS"))
if not A2[0]:
    e2 = load_env(os.path.join(os.environ.get("SCR", ""), "agent2.env")) if os.environ.get("SCR") else {}
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


def skip(name, why):
    global skipped
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
    """Optional direct DB access (sandbox only)."""
    script = os.path.join(os.environ.get("SCR", ""), "sql.sh")
    if not os.environ.get("SCR") or not os.path.exists(script):
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
t3 = login(*ADMIN)
call("POST", "/auth/logout", {"all": True}, token=t3, key=None)
check("logout all revokes every token", call("GET", "/config", token=TOK).status == 401 and call("GET", "/config", token=t3).status == 401)
TOK = login(*ADMIN)
TOK2 = login(*A2) if A2[0] else None

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
r = call("PUT", "/tickets/%d/owner" % TID, {"user_id": UID, "base": UID}, token=TOK)
check("owner already the desired one is a no-op", r.status == 200 and r.data["applied"] is False)

# collaborators
r = call("POST", "/tickets/%d/collaborators" % TID, {"user_id": 1}, token=TOK)
check("add collaborator", r.status in (200, 201))
r = call("PATCH", "/tickets/%d/collaborators/1" % TID, {"active": False, "base": True}, token=TOK)
check("deactivate collaborator", r.status == 200 and r.data["applied"])
r = call("PATCH", "/tickets/%d/collaborators/1" % TID, {"active": True, "base": True}, token=TOK)
check("collaborator stale base -> 409", r.status == 409)
check("remove collaborator", call("DELETE", "/tickets/%d/collaborators/1" % TID, token=TOK).data["applied"])

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
    skip("crash recovery", "no DB access (set SCR to the scratchpad with sql.sh)")

# ------------------------------------------------------------------ login throttling
print("\n[login throttling per user + real IP]")
nm = "e2e-nobody-" + U
codes = [call("POST", "/auth/login", {"username": nm, "password": "x"}, key=None, headers={"X-Forwarded-For": "203.0.113.%d" % (int(U[:2], 16) % 200 + 1)}).status for _ in range(6)]
check("5 failures lock that user+IP (6th is 429)", codes[:5] == [401] * 5 and codes[5] == 429, str(codes))
other = call("POST", "/auth/login", {"username": nm + "-b", "password": "x"}, key=None, headers={"X-Forwarded-For": "203.0.113.%d" % (int(U[:2], 16) % 200 + 1)})
check("another user from the same IP is not locked", other.status == 401)

print("\nresult: %d passed, %d failed, %d skipped" % (passed, failed, skipped))
sys.exit(1 if failed else 0)
