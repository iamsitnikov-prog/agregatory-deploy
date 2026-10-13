"""Снимок справки Яндекс Еды для ресторанов (eda-vendor-ru).
Обходит все страницы раздела, сохраняет текст статей в pages/*.txt.
Запуск: python snapshot.py <outdir>"""
import re, sys, time, os, collections
import requests
from bs4 import BeautifulSoup
BASE = "https://yandex.com/support/eda-vendor-ru/ru/"
START = [BASE, BASE + "index.html"] + [BASE + "main/" + s for s in ["index","orders","menu","finance","rating","tools","services","help"]]
EXTRA = [l.strip() for l in open(os.path.join(os.path.dirname(__file__), "seeds.txt"), encoding="utf-8") if l.strip() and not l.startswith("#")]
START += [BASE + p for p in EXTRA]
out = sys.argv[1]; os.makedirs(out, exist_ok=True)
S = requests.Session(); S.headers["User-Agent"] = "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124 Safari/537.36"
S.headers["Accept-Language"] = "ru-RU,ru;q=0.9"
def norm(u):
    u = u.split("#")[0].split("?")[0]
    u = u.replace("/ru/ru/", "/ru/")
    if u.endswith(".html"): u = u[:-5]
    return u.rstrip("/")
def sitemap():
    for sm in ["https://yandex.com/support/eda-vendor-ru/sitemap.xml", "https://yandex.com/support/sitemap.xml"]:
        try:
            r = requests.get(sm, timeout=30, headers=S.headers)
            if r.ok: return [x for x in re.findall(r"<loc>([^<]+)</loc>", r.text) if "/eda-vendor-ru/ru/" in x]
        except Exception: pass
    return []
SM = sitemap(); print("sitemap urls:", len(SM)); START += SM
seen, q, saved, bad = set(), collections.deque(norm(u) for u in START), 0, 0
BLOCK = ["p","li","h1","h2","h3","h4","td","th","dt","dd","pre","blockquote"]
while q and len(seen) < 400:
    u = q.popleft()
    if u in seen: continue
    seen.add(u)
    try:
        r = S.get(u, timeout=30)
    except Exception as e:
        print("ERR", u, e); continue
    if r.status_code != 200: print(r.status_code, u); continue
    html = r.text
    if "captcha" in r.url.lower() or "SmartCaptcha" in html:
        print("CAPTCHA", u); bad += 1
        if bad > 3: sys.exit(2)
        continue
    soup = BeautifulSoup(html, "html.parser")
    for a in soup.find_all("a", href=True):
        h = requests.compat.urljoin(u + "/", a["href"]) if not a["href"].startswith("http") else a["href"]
        h = norm(h)
        if h.startswith(norm(BASE)) and h not in seen: q.append(h)
    art = soup.find("article") or soup.find("main") or soup.body
    if not art: continue
    for t in art(["script","style","nav","svg"]): t.decompose()
    lines = []
    for el in art.find_all(BLOCK):
        if el.find(BLOCK): continue
        t = re.sub(r"\s+", " ", el.get_text(" ", strip=True))
        if t: lines.append(("# " if el.name in ("h1","h2","h3","h4") else "") + t)
    if len(lines) < 3: continue
    slug = u[len(norm(BASE)):].strip("/") or "index"
    p = os.path.join(out, slug.replace("/", "__") + ".txt")
    with open(p, "w", encoding="utf-8") as f: f.write(u + "\n\n" + "\n".join(lines) + "\n")
    saved += 1
    time.sleep(0.7)
print("pages saved:", saved, "visited:", len(seen))
if saved < 10: sys.exit(3)
