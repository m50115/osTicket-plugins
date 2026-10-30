#!/usr/bin/env python3
"""Drive the REAL osTicket admin forms (Admin > Plugins) over HTTP, for sandbox rehearsals of plugin
install state, so a plugin author can reproduce what the operator will do in production BEFORE touching it.
Standard library only. SANDBOX USE: it logs in as an agent with plugin-admin rights.

  SB_URL=http://127.0.0.1:8090 SB_USER=... SB_PASS=... ui-plugin-flow.py list
  ... ui-plugin-flow.py plugin-status <plugin_id> <0|1>
  ... ui-plugin-flow.py add-instance <plugin_id> <name> <0|1> [label=value ...]
        label=value fills a config field by its VISIBLE LABEL (field names in the HTML are hashes), e.g.
        "Token signing secret=<32+ chars>" "Trusted proxy IPs=127.0.0.1"
  ... ui-plugin-flow.py set-instance-status <plugin_id> <instance_id> <0|1>

What it taught us (2026-10-30): the "Add New Instance" form defaults Status to DISABLED, and an instance
only registers a bootstrap()-time route when the plugin row is Active AND the instance is Enabled.
Credentials come only from the environment; nothing is printed.
"""
import os, re, sys, html, urllib.request, urllib.parse, urllib.error, http.cookiejar

BASE = os.environ.get("SB_URL", "http://127.0.0.1:8090").rstrip("/")
cj = http.cookiejar.CookieJar()
op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(cj))


def _r(f):
    try:
        return f().read().decode("utf-8", "replace")
    except urllib.error.HTTPError as e:  # osTicket answers some normal pages with 422
        return e.read().decode("utf-8", "replace")


def get(p):
    return _r(lambda: op.open(BASE + p, timeout=30))


def post(p, d):
    return _r(lambda: op.open(BASE + p, urllib.parse.urlencode(d).encode(), timeout=30))


def csrf(h):
    m = re.search(r'name="__CSRFToken__"\s+value="([^"]+)"', h) or re.search(r'value="([^"]+)"\s+name="__CSRFToken__"', h)
    return m.group(1)


def msgs(r):
    return [x.strip() for x in re.findall(r'id="msg_(?:notice|warning|error)"[^>]*>([^<]+)<', r)][:3]


def errors(r):
    return [html.unescape(x).strip() for x in re.findall(r'class="error"[^>]*>([^<]+)<', r) if x.strip() not in ("", "*")][:5]


def login():
    t = csrf(get("/scp/login.php"))
    r = post("/scp/login.php", {"__CSRFToken__": t, "do": "scplogin", "userid": os.environ["SB_USER"], "passwd": os.environ["SB_PASS"]})
    if "ogout" not in r:
        sys.exit("login failed")


def inputs(h):
    return {n: html.unescape(v) for n, v in re.findall(r'<input[^>]*name="([^"]+)"[^>]*value="([^"]*)"', h) if not n.startswith("__")}


def main():
    cmd, args = sys.argv[1], sys.argv[2:]
    login()
    if cmd == "list":
        h = get("/scp/plugins.php")
        for row in re.findall(r"<tr[^>]*>(.*?)</tr>", h, re.S):
            cells = [re.sub(r"<[^>]+>", " ", c) for c in re.findall(r"<td[^>]*>(.*?)</td>", row, re.S)]
            cells = [re.sub(r"\s+", " ", html.unescape(c)).strip() for c in cells if c.strip()]
            if len(cells) >= 3:
                print(" | ".join(cells[:4]))
    elif cmd == "plugin-status":
        pid, val = args
        h = get("/scp/plugins.php?id=%s" % pid)
        r = post("/scp/plugins.php?id=%s" % pid, {"__CSRFToken__": csrf(h), "do": "update", "a": "", "id": pid, "isactive": val, "notes": inputs(h).get("notes", "")})
        print(msgs(r), errors(r))
    elif cmd == "add-instance":
        pid, name, val, *kv = args
        h = get("/scp/plugins.php?id=%s&a=add-instance" % pid)
        data = {"__CSRFToken__": csrf(h), "do": "add-instance", "a": "add-instance", "id": pid, "xid": "0", "name": name, "isactive": val, "notes": ""}
        data.update({k: v for k, v in inputs(h).items() if k not in data})
        for item in kv:
            label, _, value = item.partition("=")
            m = re.search(re.escape(label) + r".*?name=\"([^\"]+)\"", h, re.S)
            if not m:
                sys.exit("config field not found by label: %s" % label)
            data[m.group(1)] = value
        r = post("/scp/plugins.php?id=%s&a=add-instance" % pid, data)
        print(msgs(r), errors(r))
    elif cmd == "set-instance-status":
        pid, xid, val = args
        h = get("/scp/plugins.php?id=%s&xid=%s" % (pid, xid))
        data = {"__CSRFToken__": csrf(h), "do": "update-instance", "a": "", "id": pid, "xid": xid, "isactive": val, "notes": ""}
        data.update({k: v for k, v in inputs(h).items() if k not in data})
        r = post("/scp/plugins.php?id=%s&xid=%s" % (pid, xid), data)
        print(msgs(r), errors(r))
    else:
        sys.exit(__doc__)


if __name__ == "__main__":
    main()
