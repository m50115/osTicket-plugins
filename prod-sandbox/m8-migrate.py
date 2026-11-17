#!/usr/bin/env python3
"""One-shot migration of M8 ScheduledMaintenance from the retired plugin table to the native store (no plugin table).

Reads the OLD store ONLY through `GET /maintenances` of the old PHAR (60ad13e / 67931c0, table-backed) and writes the
NEW store ONLY through `POST /maintenances/import` of the new PHAR. Never touches the database. Idempotent by record id:
re-running `import` answers `exists` for what is already there (and `rejected mismatch` if a record differs).

  1. old PHAR deployed:  m8-migrate.py export --base URL --out m8-export.json [--expect 377,229,63,85]
  2. new PHAR deployed + plugin settings maintenance_list_id and maintenance_import_enabled=1:
                         m8-migrate.py import --base URL --in m8-export.json
  3.                     m8-migrate.py verify --base URL --in m8-export.json [--expect 377,229,63,85]
  4. empty the setting maintenance_import_enabled again.
  m8-migrate.py self-test   (no network: exercises paging, chunking, idempotency and the parity check)

Auth: env OW_TOKEN (Bearer), or OW_USER + OW_PASS (POST /auth/login). An administrator is required for `import`.
--expect TOTAL,PLANNED,COMPLETED,CANCELLED makes export/verify fail unless the counts match exactly.
"""
import argparse, collections, hashlib, json, os, sys, urllib.error, urllib.parse, urllib.request

PAGE = 200
CHUNK = 200
KEYS = ["id", "equipment_id", "scheduled_date", "stored_status", "ticket_id", "completed_on", "completed_by", "evidence_ref",
        "completion_note", "cancel_reason", "changes", "created_at", "created_by", "updated_at", "updated_by"]


def http(base, method, path, token, body=None, key=None):
    h = {"Accept": "application/json"}
    if token:
        h["Authorization"] = "Bearer " + token
    data = None
    if body is not None:
        data = json.dumps(body).encode()
        h["Content-Type"] = "application/json"
    if key:
        h["Idempotency-Key"] = key
    req = urllib.request.Request(base.rstrip("/") + path, data=data, method=method, headers=h)
    try:
        with urllib.request.urlopen(req, timeout=120) as r:
            return r.status, json.loads(r.read().decode("utf-8") or "null")
    except urllib.error.HTTPError as e:
        raw = e.read().decode("utf-8", "replace")
        try:
            return e.code, json.loads(raw)
        except ValueError:
            return e.code, {"raw": raw[:500]}


def token(base):
    t = os.environ.get("OW_TOKEN")
    if t:
        return t
    u, p = os.environ.get("OW_USER"), os.environ.get("OW_PASS")
    if not (u and p):
        sys.exit("set OW_TOKEN, or OW_USER and OW_PASS")
    st, b = HTTP(base, "POST", "/auth/login", None, {"username": u, "password": p})
    if st != 200:
        sys.exit("login failed: %s %s" % (st, b))
    return b["data"]["token"]


HTTP = http      # replaced by the self-test


def walk(base, tok):
    """Every record of GET /maintenances, following next_cursor; later copies of an id replace earlier ones."""
    out = collections.OrderedDict(); cur = None; pages = 0
    while True:
        q = "?limit=%d" % PAGE + ("&cursor=" + urllib.parse.quote(cur) if cur else "")
        st, b = HTTP(base, "GET", "/maintenances" + q, tok)
        if st != 200:
            sys.exit("GET /maintenances%s -> %s %s" % (q, st, b))
        for r in b["data"]:
            out.pop(r["id"], None)
            out[r["id"]] = r
        pages += 1
        if not b["meta"]["has_more"]:
            return list(out.values()), pages
        cur = b["meta"]["next_cursor"]
        if pages > 100000:
            sys.exit("feed does not end")


def counts(recs):
    c = collections.Counter(r["stored_status"] for r in recs)
    return [len(recs), c.get("PLANNED", 0), c.get("COMPLETED", 0), c.get("CANCELLED", 0)]


def expect(label, got, want):
    if want is None:
        return True
    w = [int(x) for x in want.split(",")]
    ok = got == w
    print("%s: counts %s, expected %s -> %s" % (label, got, w, "OK" if ok else "MISMATCH"))
    return ok


def cmd_export(a):
    tok = token(a.base)
    recs, pages = walk(a.base, tok)
    bad = [r.get("id") for r in recs if sorted(r) != sorted(KEYS)]
    if bad:
        sys.exit("records with unexpected keys (not the retired DTO): %s" % bad[:5])
    c = counts(recs)
    doc = {"source": a.base, "pages": pages, "counts": dict(zip(["total", "PLANNED", "COMPLETED", "CANCELLED"], c)),
           "without_ticket": sum(1 for r in recs if r["ticket_id"] is None), "records": recs}
    with open(a.out, "w") as f:
        json.dump(doc, f, ensure_ascii=False, indent=1)
    print("exported %d records in %d pages to %s (%s; %d without Ticket)" % (len(recs), pages, a.out, doc["counts"], doc["without_ticket"]))
    return 0 if expect("export", c, a.expect) else 1


def load(path):
    with open(path) as f:
        return json.load(f)["records"]


def cmd_import(a):
    tok = token(a.base)
    recs = load(a.inp)
    tally = collections.Counter(); rejected = []
    for i in range(0, len(recs), CHUNK):
        chunk = recs[i:i + CHUNK]
        key = "m08-import-" + hashlib.sha1("|".join(r["id"] for r in chunk).encode()).hexdigest()
        st, b = HTTP(a.base, "POST", "/maintenances/import", tok, {"items": chunk}, key)
        if st != 200:
            sys.exit("POST /maintenances/import (chunk %d) -> %s %s" % (i // CHUNK, st, b))
        for r in b["data"]:
            tally[r["result"]] += 1
            if r["result"] == "rejected":
                rejected.append(r)
    print("import: %s" % dict(tally))
    for r in rejected[:20]:
        print("  rejected %s: %s %s" % (r.get("id"), r.get("code"), r.get("message")))
    return 0 if not rejected and tally["created"] + tally["exists"] == len(recs) else 1


def cmd_verify(a):
    tok = token(a.base)
    src = load(a.inp)
    now, _ = walk(a.base, tok)
    by = {r["id"]: r for r in now}
    same = [r["id"] for r in src if by.get(r["id"]) == r]
    missing = [r["id"] for r in src if r["id"] not in by]
    differ = [r["id"] for r in src if r["id"] in by and by[r["id"]] != r]
    print("parity: %d/%d identical DTOs, %d missing, %d different; the new store holds %d records in total" % (len(same), len(src), len(missing), len(differ), len(now)))
    for i in differ[:5]:
        a_, b_ = next(r for r in src if r["id"] == i), by[i]
        print("  differs %s: %s" % (i, [k for k in KEYS if a_.get(k) != b_.get(k)]))
    ok = len(same) == len(src)
    ok = expect("verify (imported set)", counts([by[r["id"]] for r in src if r["id"] in by]), a.expect) and ok
    return 0 if ok else 1


def cmd_self_test(_a):
    import random, uuid as U
    global HTTP
    random.seed(7)
    st = ["PLANNED"] * 229 + ["COMPLETED"] * 63 + ["CANCELLED"] * 85
    old = []
    for k, s in enumerate(st):
        rid = str(U.UUID(int=random.getrandbits(128), version=4))
        old.append({"id": rid, "equipment_id": 16000 + k % 55, "scheduled_date": "2026-%02d-%02d" % (1 + k % 12, 1 + k % 28), "stored_status": s,
                    "ticket_id": (k if k % 5 == 0 else None), "completed_on": ("2026-01-02" if s == "COMPLETED" else None), "completed_by": (3 if s == "COMPLETED" else None),
                    "evidence_ref": None, "completion_note": ("nota ñ %d" % k if s == "COMPLETED" else None), "cancel_reason": ("x" if s == "CANCELLED" else None),
                    "changes": [{"kind": "created", "at": "2026-01-01T00:00:00Z", "by": 1, "reason": None, "from": None, "to": {"equipment_id": 1, "scheduled_date": "2026-01-01"}}],
                    "created_at": "2026-01-01T00:00:00Z", "created_by": 1, "updated_at": "2026-01-%02dT00:00:00Z" % (1 + k % 28), "updated_by": 1})
    old.sort(key=lambda r: (r["updated_at"], r["id"]))
    new = {}
    seen_keys = {}

    def fake(base, method, path, tok, body=None, key=None):
        if path.startswith("/maintenances?"):
            q = urllib.parse.parse_qs(path.split("?", 1)[1])
            src = old if base == "old" else sorted(new.values(), key=lambda r: (r["updated_at"], r["id"]))
            start = int(q["cursor"][0]) if "cursor" in q else 0
            page = src[start:start + int(q["limit"][0])]
            more = start + len(page) < len(src)
            return 200, {"data": page, "meta": {"has_more": more, "next_cursor": str(start + len(page)) if more else None}}
        if path == "/maintenances/import":
            if key in seen_keys:
                return 200, seen_keys[key]
            out = []
            for r in body["items"]:
                if r["id"] in new:
                    out.append({"id": r["id"], "result": "exists" if new[r["id"]] == r else "rejected", "code": None if new[r["id"]] == r else "mismatch"})
                else:
                    new[r["id"]] = json.loads(json.dumps(r)); out.append({"id": r["id"], "result": "created"})
            seen_keys[key] = {"data": out}
            return 200, seen_keys[key]
        return 404, {}
    HTTP = fake
    os.environ["OW_TOKEN"] = "x"
    tmp = os.path.join(os.environ.get("TMPDIR", "/tmp"), "m8-selftest-%d.json" % os.getpid())
    ns = argparse.Namespace
    try:
        r1 = cmd_export(ns(base="old", out=tmp, expect="377,229,63,85"))
        r2 = cmd_import(ns(base="new", inp=tmp))
        r3 = cmd_import(ns(base="new", inp=tmp))
        r4 = cmd_verify(ns(base="new", inp=tmp, expect="377,229,63,85"))
        next(iter(new.values()))["completion_note"] = "tampered"
        r5 = cmd_verify(ns(base="new", inp=tmp, expect=None))
    finally:
        os.path.exists(tmp) and os.remove(tmp)
    ok = (r1, r2, r3, r4, r5) == (0, 0, 0, 0, 1)
    print("self-test: %s (export=%d import=%d reimport=%d verify=%d tampered-verify=%d)" % ("PASS" if ok else "FAIL", r1, r2, r3, r4, r5))
    return 0 if ok else 1


def main():
    p = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    sp = p.add_subparsers(dest="cmd", required=True)
    e = sp.add_parser("export"); e.add_argument("--base", required=True); e.add_argument("--out", required=True); e.add_argument("--expect")
    i = sp.add_parser("import"); i.add_argument("--base", required=True); i.add_argument("--in", dest="inp", required=True)
    v = sp.add_parser("verify"); v.add_argument("--base", required=True); v.add_argument("--in", dest="inp", required=True); v.add_argument("--expect")
    sp.add_parser("self-test")
    a = p.parse_args()
    sys.exit({"export": cmd_export, "import": cmd_import, "verify": cmd_verify, "self-test": cmd_self_test}[a.cmd](a))


if __name__ == "__main__":
    main()
