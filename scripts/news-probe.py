"""Проверка источников новостей: открываются ли с GitHub, сколько свежих записей и сколько по теме доставки.

Запуск: python3 scripts/news-probe.py scripts/news-sources.json
Ничего не меняет и никуда не пишет, только печатает таблицу.
"""
import email.utils
import html
import json
import re
import sys
import time
import urllib.request
import xml.etree.ElementTree as ET
from concurrent.futures import ThreadPoolExecutor

UA = "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130 Safari/537.36"
NOW = time.time()
WINDOW = 72 * 3600


def fetch(url):
    req = urllib.request.Request(url, headers={"User-Agent": UA, "Accept-Language": "ru"})
    with urllib.request.urlopen(req, timeout=25) as r:
        return r.status, r.read().decode("utf-8", "ignore")


def parse_date(s):
    s = (s or "").strip()
    if not s:
        return None
    try:
        return email.utils.parsedate_to_datetime(s).timestamp()
    except Exception:
        pass
    try:
        from datetime import datetime
        return datetime.fromisoformat(s.replace("Z", "+00:00")).timestamp()
    except Exception:
        return None


def rss_items(text):
    root = ET.fromstring(text.encode("utf-8"))
    out = []
    for it in root.iter():
        tag = it.tag.split("}")[-1]
        if tag not in ("item", "entry"):
            continue
        get = lambda n: next((c.text or "" for c in it if c.tag.split("}")[-1] == n), "")
        title = get("title")
        desc = re.sub("<[^>]+>", " ", html.unescape(get("description") or get("summary")))
        ts = parse_date(get("pubDate") or get("published") or get("updated") or get("date"))
        out.append((ts, title.strip(), desc[:300]))
    return out


def tg_items(text):
    out = []
    for block in re.findall(r'tgme_widget_message_wrap.*?(?=tgme_widget_message_wrap|$)', text, re.S):
        dt = re.search(r'<time[^>]*datetime="([^"]+)"', block)
        body = re.search(r'tgme_widget_message_text[^>]*>(.*?)</div>', block, re.S)
        t = html.unescape(re.sub("<[^>]+>", " ", body.group(1))) if body else ""
        out.append((parse_date(dt.group(1)) if dt else None, t.strip()[:120], t[:400]))
    return out


def html_items(text):
    return [(None, html.unescape(m).strip(), "") for m in re.findall(r">([^<>]{25,160})</a>", text)][:60]


def probe(src, keywords):
    last_err = ""
    for u in src["urls"]:
        url = "https://t.me/s/" + u if src["kind"] == "tg" else u
        try:
            status, text = fetch(url)
            items = {"rss": rss_items, "tg": tg_items, "html": html_items}[src["kind"]](text)
            if not items:
                last_err = f"{status}, записей не найдено"
                continue
            dated = [i for i in items if i[0]]
            fresh = [i for i in dated if NOW - i[0] <= WINDOW] if dated else items
            hits = [i for i in fresh if any(k in (i[1] + " " + i[2]).lower() for k in keywords)]
            newest = max((i[0] for i in dated), default=None)
            age = f"{(NOW - newest) / 3600:.0f} ч назад" if newest else "без дат"
            sample = (hits or fresh or items)[0][1][:90].replace("\n", " ")
            return dict(name=src["name"], ok=True, url=url, total=len(items), fresh=len(fresh), hits=len(hits), newest=age, sample=sample, focus=src["focus"])
        except Exception as e:
            last_err = type(e).__name__ + ": " + str(e)[:80]
    return dict(name=src["name"], ok=False, url=src["urls"][0], err=last_err, focus=src["focus"])


def main():
    cfg = json.load(open(sys.argv[1], encoding="utf-8"))
    kw = cfg["keywords"]
    with ThreadPoolExecutor(8) as ex:
        res = list(ex.map(lambda s: probe(s, kw), cfg["sources"]))
    ok = [r for r in res if r["ok"]]
    print(f"Открылись {len(ok)} из {len(res)}. Окно свежести 72 ч.\n")
    print("ИСТОЧНИК | записей | за 72 ч | по теме | последняя | пример")
    for r in sorted(ok, key=lambda r: -r["hits"]):
        print(f"OK {r['name']} | {r['total']} | {r['fresh']} | {r['hits']} | {r['newest']} | {r['sample']}")
    print()
    for r in res:
        if not r["ok"]:
            print(f"НЕТ {r['name']} | {r['url']} | {r['err']}")
    json.dump(res, open("news-probe.json", "w", encoding="utf-8"), ensure_ascii=False, indent=1)


if __name__ == "__main__":
    main()
