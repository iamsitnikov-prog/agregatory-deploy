#!/usr/bin/env python3
"""Поисковые запросы и визиты по страницам входа для отчёта «Откуда заявки» в CRM (с 23.11.2026).

Яндекс и Google не передают сайту сам поисковый запрос, поэтому заявка знает только
страницу входа и поисковик. Этот скрипт раз в день берёт:
  Вебмастер  какие запросы ведут на каждую страницу (клики, показы, средняя позиция)
  Метрика    сколько визитов было на каждую страницу входа за 90 дней (всего, из поиска, по поисковикам)
и пишет один файл seo.json. Задача lead-queries.yml кладёт его на хостинг в api/data/crm/,
кабинет /dokumenty показывает рядом с заявками. Только чтение, в Вебмастере и Метрике ничего не меняет.
"""
import datetime as dt, json, os, sys, urllib.parse, urllib.request
from collections import defaultdict

TOKEN = os.environ['YANDEX_TOKEN']
COUNTER = '112965538'
OUT = sys.argv[1]
log = []


def call(url, params=None, body=None):
    if params:
        url += '?' + urllib.parse.urlencode(params, doseq=True)
    data = json.dumps(body).encode() if body is not None else None
    h = {'Authorization': f'OAuth {TOKEN}'}
    if data:
        h['Content-Type'] = 'application/json'
    with urllib.request.urlopen(urllib.request.Request(url, data=data, headers=h), timeout=60) as r:
        return json.loads(r.read().decode())


def err(ex):
    body = ''
    if hasattr(ex, 'read'):
        try:
            body = ex.read().decode()[:300]
        except Exception:
            pass
    return f'{ex} {body}'.strip()


def norm(path):
    p = urllib.parse.urlparse(path).path if path.startswith('http') else path.split('?')[0].split('#')[0]
    p = p.rstrip('/') or '/'
    return p


pages = defaultdict(lambda: {'q': {}, 'v': {}})
wm_period = {}


def add_stats(page, query, stats):
    clicks = shows = pos_w = 0.0
    dates = set()
    for s in stats or []:
        dates.add(s.get('date', '')[:10])
        f, v = s.get('field'), float(s.get('value') or 0)
        if f == 'CLICKS':
            clicks += v
        elif f == 'IMPRESSIONS':
            shows += v
    for s in stats or []:
        if s.get('field') == 'POSITION':
            # средняя позиция по дням, взвешенная показами этого дня
            d = s.get('date', '')[:10]
            day_shows = sum(float(x.get('value') or 0) for x in stats if x.get('field') == 'IMPRESSIONS' and x.get('date', '')[:10] == d)
            pos_w += float(s.get('value') or 0) * day_shows
    if dates - {''}:
        ds = sorted(dates - {''})
        wm_period['from'] = min(wm_period.get('from', ds[0]), ds[0])
        wm_period['to'] = max(wm_period.get('to', ds[-1]), ds[-1])
    if not shows and not clicks:
        return
    cur = pages[norm(page)]['q'].get(query)
    row = [query, int(clicks), int(shows), round(pos_w / shows, 1) if shows else None]
    if not cur or row[2] > cur[2]:
        pages[norm(page)]['q'][query] = row


def wm_list(text_indicator, filters=None, cap=5000):
    rows, off = [], 0
    while off < cap:
        body = {'offset': off, 'limit': 500, 'device_type_indicator': 'ALL', 'text_indicator': text_indicator}
        if filters:
            body['filters'] = filters
        r = call(base + '/query-analytics/list', body=body)
        t = r.get('text_indicator_to_statistics') or r.get('statistics') or []
        rows += t
        if len(t) < 500:
            break
        off += 500
    return rows


# --- Вебмастер ---------------------------------------------------------------
try:
    uid = call('https://api.webmaster.yandex.net/v4/user')['user_id']
    hosts = call(f'https://api.webmaster.yandex.net/v4/user/{uid}/hosts')['hosts']
    hid = next(h for h in hosts if 'agregatory.pro' in h['ascii_host_url'] and h['ascii_host_url'].startswith('https'))['host_id']
    base = f'https://api.webmaster.yandex.net/v4/user/{uid}/hosts/{hid}'

    # 1) Все запросы: у каждого Вебмастер называет главную страницу, на которую по нему приходят
    qrows = wm_list('QUERY')
    for x in qrows:
        q = (x.get('text_indicator') or {}).get('value', '')
        u = (x.get('popular_complementary_indicator') or {}).get('value', '')
        if q and u:
            add_stats(u, q, x.get('statistics'))
    log.append(f'Вебмастер: запросов {len(qrows)}')

    # 2) Страницы: по каждой странице её собственные запросы (точнее, чем «главная страница запроса»)
    urows = wm_list('URL')
    urls = []
    for x in urows:
        u = (x.get('text_indicator') or {}).get('value', '')
        if u:
            urls.append(u)
            q = (x.get('popular_complementary_indicator') or {}).get('value', '')
            if q:
                add_stats(u, q, x.get('statistics'))
    log.append(f'Вебмастер: страниц с показами {len(urls)}')
    ok = bad = 0
    first_err = ''
    for u in urls[:150]:
        try:
            rows = wm_list('QUERY', {'text_filters': [{'text_indicator': 'URL', 'operation': 'TEXT_MATCH', 'value': u}]}, cap=1000)
            for x in rows:
                q = (x.get('text_indicator') or {}).get('value', '')
                if q:
                    add_stats(u, q, x.get('statistics'))
            ok += 1
        except Exception as ex:
            bad += 1
            first_err = first_err or err(ex)
            if bad >= 3 and ok == 0:
                break
    log.append(f'Вебмастер: запросы по страницам: {ok} страниц' + (f', ошибок {bad}: {first_err}' if bad else ''))
except Exception as ex:
    log.append(f'Вебмастер: ОШИБКА {err(ex)}')

# --- Метрика -----------------------------------------------------------------
mk_period = {}
try:
    today = dt.date.today()
    d1, d2 = (today - dt.timedelta(days=90)).isoformat(), (today - dt.timedelta(days=1)).isoformat()
    mk_period = {'from': d1, 'to': d2}
    r = call('https://api-metrika.yandex.net/stat/v1/data', {
        'ids': COUNTER, 'metrics': 'ym:s:visits', 'date1': d1, 'date2': d2, 'limit': 10000, 'accuracy': 'full', 'lang': 'ru',
        'dimensions': 'ym:s:startURLPath,ym:s:lastTrafficSource,ym:s:lastSourceEngine',
        'filters': "ym:s:isRobot=='No' AND ym:s:startURLPath!~'^/dokumenty'"})
    n = 0
    for it in r.get('data', []):
        path, src, eng = [d.get('name') or '' for d in it['dimensions']]
        sid = (it['dimensions'][1].get('id') or '').lower()
        v = int(it['metrics'][0])
        p = pages[norm(path or '/')]['v']
        p['total'] = p.get('total', 0) + v
        if sid == 'organic':
            p['search'] = p.get('search', 0) + v
            e = 'yandex' if 'яндекс' in eng.lower() or 'yandex' in eng.lower() else ('google' if 'google' in eng.lower() else 'other')
            p[e] = p.get(e, 0) + v
        elif sid in ('recommend', 'messenger', 'social'):
            p['social'] = p.get('social', 0) + v
        elif 'ai' in sid or 'llm' in sid:
            p['ai'] = p.get('ai', 0) + v
        n += 1
    log.append(f'Метрика: строк {n}, визитов {sum(p["v"].get("total", 0) for p in pages.values())} за 90 дней')
except Exception as ex:
    log.append(f'Метрика: ОШИБКА {err(ex)}')

# --- Итог --------------------------------------------------------------------
out = {'updated': dt.datetime.now(dt.timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ'), 'wm': wm_period, 'mk': mk_period, 'pages': {}}
for path, d in pages.items():
    qs = sorted(d['q'].values(), key=lambda r: (-r[1], -r[2]))[:25]
    if qs or d['v']:
        out['pages'][path] = {'q': qs, 'v': d['v']}
nq = sum(len(p['q']) for p in out['pages'].values())
log.append(f'Итог: страниц {len(out["pages"])}, запросов {nq}, Вебмастер {wm_period.get("from")}..{wm_period.get("to")}')
with open(OUT, 'w', encoding='utf-8') as f:
    json.dump(out, f, ensure_ascii=False, separators=(',', ':'))
print('\n'.join(log))
if not out['pages']:
    sys.exit('нет данных ни из Вебмастера, ни из Метрики')
