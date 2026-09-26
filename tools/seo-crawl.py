"""Crawl every URL in a Chess Codex sitemap and print a short SEO summary.

    python tools/seo-crawl.py                                  # live site, 2 workers
    python tools/seo-crawl.py http://127.0.0.1:8099 -w 1       # local copy (tools/dev/serve.sh)
    python tools/seo-crawl.py --limit 200 --show 5 --out crawl.json

Checks status codes, <title> and meta description (empty, duplicate, length),
canonical, robots, H1 count, JSON-LD validity and missing image alt text.
The User-Agent contains "bot", so Views::track() doesn't count these visits.
Standard library only.
"""
import argparse, collections, html, json, re, statistics, sys, time
import urllib.error, urllib.request
from concurrent.futures import ThreadPoolExecutor
from html.parser import HTMLParser

UA = "ChessCodexSEO-bot/1.0"


class Page(HTMLParser):
    def __init__(self):
        super().__init__()
        self.title, self.meta, self.canon, self.h1, self.no_alt = "", {}, None, 0, 0
        self.ld, self._in_title, self._in_ld, self._buf = [], False, False, ""

    def handle_starttag(self, tag, a):
        a = dict(a)
        if tag == "title": self._in_title = True
        elif tag == "meta" and (a.get("name") or a.get("property")):
            self.meta.setdefault(a.get("name") or a.get("property"), a.get("content") or "")
        elif tag == "link" and a.get("rel") == "canonical": self.canon = a.get("href")
        elif tag == "h1": self.h1 += 1
        elif tag == "img" and a.get("alt") is None: self.no_alt += 1
        elif tag == "script" and a.get("type") == "application/ld+json": self._in_ld, self._buf = True, ""

    def handle_endtag(self, tag):
        if tag == "title": self._in_title = False
        if tag == "script" and self._in_ld: self._in_ld = False; self.ld.append(self._buf)

    def handle_data(self, d):
        if self._in_title: self.title += d
        if self._in_ld: self._buf += d


def fetch(url):
    req = urllib.request.Request(url, headers={"User-Agent": UA})
    t = time.time()
    try:
        with urllib.request.urlopen(req, timeout=30) as r:
            return r.status, r.read(), time.time() - t
    except urllib.error.HTTPError as e:
        return e.code, e.read(), time.time() - t


def audit(url):
    status, body, dt = fetch(url)
    p = Page(); p.feed(body.decode("utf-8", "replace"))
    ld_ok = True
    for block in p.ld:
        try: json.loads(block)
        except ValueError: ld_ok = False
    return {"url": url, "status": status, "time": round(dt, 3), "title": p.title.strip(),
            "desc": p.meta.get("description", ""), "robots": p.meta.get("robots", ""),
            "canon": p.canon, "h1": p.h1, "no_alt": p.no_alt, "ld_ok": ld_ok}


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("base", nargs="?", default="https://chesscodex.org")
    ap.add_argument("-w", "--workers", type=int, default=2)
    ap.add_argument("--limit", type=int, default=0, help="crawl only the first N sitemap URLs")
    ap.add_argument("--show", type=int, default=3, help="print N examples per problem")
    ap.add_argument("--out", help="save every page's record as JSON")
    args = ap.parse_args()
    base = args.base.rstrip("/")

    status, body, _ = fetch(base + "/sitemap.xml")
    urls = [html.unescape(u) for u in re.findall(r"<loc>(.*?)</loc>", body.decode())]
    if args.limit: urls = urls[: args.limit]
    with ThreadPoolExecutor(args.workers) as ex:
        pages = list(ex.map(audit, urls))
    if args.out: json.dump(pages, open(args.out, "w", encoding="utf-8"), ensure_ascii=False)

    def report(label, bad):
        print(f"{label}: {len(bad)}")
        for x in bad[: args.show]: print("   ", x)

    times = [p["time"] for p in pages]
    print(f"pages: {len(pages)}  status: {dict(collections.Counter(p['status'] for p in pages))}"
          f"  time median {statistics.median(times):.3f}s max {max(times):.3f}s")
    report("non-200", [p["url"] for p in pages if p["status"] != 200])
    report("empty description", [p["url"] for p in pages if not p["desc"]])
    report("description > 160", [p["url"] for p in pages if len(p["desc"]) > 160])
    for key in ("title", "desc"):
        groups = collections.defaultdict(list)
        for p in pages:
            if p[key]: groups[p[key]].append(p["url"])
        dups = {k: v for k, v in groups.items() if len(v) > 1}
        report(f"duplicate {key} groups", [f"{len(v)}× {k[:90]}" for k, v in dups.items()])
    report("canonical != URL", [p["url"] for p in pages if p["canon"] and p["canon"] != p["url"]])
    report("noindex in sitemap", [p["url"] for p in pages if "noindex" in p["robots"]])
    report("H1 count != 1", [f"{p['h1']} {p['url']}" for p in pages if p["h1"] != 1])
    report("broken JSON-LD", [p["url"] for p in pages if not p["ld_ok"]])
    report("images without alt", [p["url"] for p in pages if p["no_alt"]])
    print(f"title > 70 chars: {sum(len(p['title']) > 70 for p in pages)}")


if __name__ == "__main__":
    sys.exit(main())
