"""Crawl every URL in a Caissa Codex sitemap and print a short SEO summary.

    python tools/seo-crawl.py                                  # live site, 2 workers
    python tools/seo-crawl.py http://127.0.0.1:8099 -w 1       # local copy (tools/dev/serve.sh)
    python tools/seo-crawl.py --limit 200 --show 5 --out crawl.json

Checks status codes, <title> and meta description (empty, duplicate, length),
canonical, robots, H1 count, JSON-LD validity and missing image alt text.
Internal links between the sitemap pages (rel=nofollow left out) give each
page its click depth from the home page and its number of linking pages.
Standard library only.
"""
import argparse, collections, html, json, re, statistics, sys, time
import urllib.error, urllib.parse, urllib.request
from concurrent.futures import ThreadPoolExecutor
from html.parser import HTMLParser

UA = "CaissaCodexSEO-bot/1.0"


class Page(HTMLParser):
    def __init__(self):
        super().__init__()
        self.title, self.meta, self.canon, self.h1, self.no_alt = "", {}, None, 0, 0
        self.ld, self._in_title, self._in_ld, self._buf = [], False, False, ""
        self.links = []

    def handle_starttag(self, tag, a):
        a = dict(a)
        if tag == "title": self._in_title = True
        elif tag == "meta" and (a.get("name") or a.get("property")):
            self.meta.setdefault(a.get("name") or a.get("property"), a.get("content") or "")
        elif tag == "link" and a.get("rel") == "canonical": self.canon = a.get("href")
        elif tag == "h1": self.h1 += 1
        elif tag == "img" and a.get("alt") is None: self.no_alt += 1
        elif tag == "a" and a.get("href") and "nofollow" not in (a.get("rel") or ""): self.links.append(a["href"])
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
    host = urllib.parse.urlsplit(url).netloc
    links = {urllib.parse.urldefrag(urllib.parse.urljoin(url, html.unescape(h)))[0] for h in p.links}
    return {"url": url, "status": status, "time": round(dt, 3), "title": p.title.strip(),
            "desc": p.meta.get("description", ""), "robots": p.meta.get("robots", ""),
            "canon": p.canon, "h1": p.h1, "no_alt": p.no_alt, "ld_ok": ld_ok,
            "links": sorted(u for u in links if urllib.parse.urlsplit(u).netloc == host and u != url)}


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
    text = body.decode()
    if "<sitemapindex" in text:   # an index of sitemap parts: read each part
        parts = [html.unescape(u) for u in re.findall(r"<loc>(.*?)</loc>", text)]
        text = "".join(fetch(p)[1].decode() for p in parts)
    urls = [html.unescape(u) for u in re.findall(r"<url><loc>(.*?)</loc>", text)]
    if args.limit: urls = urls[: args.limit]
    with ThreadPoolExecutor(args.workers) as ex:
        pages = list(ex.map(audit, urls))

    # Link graph between the sitemap pages: linking pages and clicks from "/".
    known = {p["url"] for p in pages}
    inlinks = collections.Counter(u for p in pages for u in p["links"] if u in known)
    graph = {p["url"]: [u for u in p["links"] if u in known] for p in pages}
    depth, queue = {base + "/": 0}, collections.deque([base + "/"])
    while queue:
        u = queue.popleft()
        for v in graph.get(u, []):
            if v not in depth: depth[v] = depth[u] + 1; queue.append(v)
    for p in pages:
        p["inlinks"], p["depth"] = inlinks[p["url"]], depth.get(p["url"])
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
    levels = collections.Counter(p["depth"] for p in pages)
    print("clicks from the home page: " + ", ".join(f"{d}: {levels[d]}" for d in sorted(k for k in levels if k is not None))
          + f", unreachable: {levels[None]}")
    report("no sitemap page links here", [p["url"] for p in pages if not p["inlinks"] and p["url"] != base + "/"])
    report("only one sitemap page links here", [p["url"] for p in pages if p["inlinks"] == 1])


if __name__ == "__main__":
    sys.exit(main())
