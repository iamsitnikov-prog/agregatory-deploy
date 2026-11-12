#!/usr/bin/env python3
"""Выгрузка данных для работы над позициями (13.11.2026), только чтение.

Вебмастер: все запросы с показами, кликами и средней позицией (последние доступные 30 дней),
плюс аналитика запросов по страницам, если API её отдаст.
Метрика (свежие данные): по каким поисковым фразам и на какие страницы приходили люди за 60 дней.
Пишет JSON-файлы в папку из аргумента.
"""
import datetime as dt, json, os, sys, urllib.parse, urllib.request

TOKEN = os.environ['YANDEX_TOKEN']
COUNTER = '112965538'
OUT = sys.argv[1]
os.makedirs(OUT, exist_ok=True)
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


def save(name, obj):
    with open(os.path.join(OUT, name), 'w', encoding='utf-8') as f:
        json.dump(obj, f, ensure_ascii=False, indent=1)


def step(name, fn):
    try:
        n = fn()
        log.append(f'{name}: {n}')
    except Exception as ex:
        body = ''
        if hasattr(ex, 'read'):
            try: body = ex.read().decode()[:300]
            except Exception: pass
        log.append(f'{name}: ОШИБКА {ex} {body}')


uid = call('https://api.webmaster.yandex.net/v4/user')['user_id']
hosts = call(f'https://api.webmaster.yandex.net/v4/user/{uid}/hosts')['hosts']
hid = next(h for h in hosts if 'agregatory.pro' in h['ascii_host_url'] and h['ascii_host_url'].startswith('https'))['host_id']
base = f'https://api.webmaster.yandex.net/v4/user/{uid}/hosts/{hid}'


def wm_popular():
    probe = call(base + '/search-queries/popular', {'order_by': 'TOTAL_SHOWS', 'query_indicator': ['TOTAL_SHOWS'], 'limit': 1})
    d_to = dt.date.fromisoformat(probe['date_to'][:10])
    d_from = d_to - dt.timedelta(days=29)
    rows, off = [], 0
    while off < 3000:
        r = call(base + '/search-queries/popular', {
            'order_by': 'TOTAL_SHOWS', 'date_from': d_from.isoformat(), 'date_to': d_to.isoformat(),
            'query_indicator': ['TOTAL_SHOWS', 'TOTAL_CLICKS', 'AVG_SHOW_POSITION', 'AVG_CLICK_POSITION'],
            'limit': 500, 'offset': off})
        q = r.get('queries', [])
        rows += [{'q': x['query_text'], **x['indicators']} for x in q]
        if len(q) < 500:
            break
        off += 500
    save('wm_queries.json', {'from': d_from.isoformat(), 'to': d_to.isoformat(), 'rows': rows})
    return f'{len(rows)} запросов, {d_from}..{d_to}'


def wm_analytics(kind):
    def f():
        rows, off = [], 0
        while off < 3000:
            r = call(base + '/query-analytics/list', body={'offset': off, 'limit': 500, 'device_type_indicator': 'ALL', 'text_indicator': kind})
            t = r.get('text_indicator_to_statistics') or r.get('statistics') or []
            rows += t
            if len(t) < 500:
                break
            off += 500
        save(f'wm_analytics_{kind.lower()}.json', rows)
        return f'{len(rows)} строк'
    return f


def mk(name, dims, days=60, limit=2000, filters="ym:s:lastTrafficSource=='organic'"):
    def f():
        today = dt.date.today()
        p = {'ids': COUNTER, 'metrics': 'ym:s:visits,ym:s:bounceRate,ym:s:avgVisitDurationSeconds', 'dimensions': dims,
             'date1': (today - dt.timedelta(days=days)).isoformat(), 'date2': (today - dt.timedelta(days=1)).isoformat(),
             'limit': limit, 'accuracy': 'full', 'lang': 'ru', 'sort': '-ym:s:visits',
             'filters': f"({filters}) AND ym:s:isRobot=='No'"}
        r = call('https://api-metrika.yandex.net/stat/v1/data', p)
        rows = [{'d': [x.get('name') for x in it['dimensions']], 'm': it['metrics']} for it in r.get('data', [])]
        save(name, rows)
        return f'{len(rows)} строк'
    return f


def wm_samples(path, name):
    def f():
        rows, off = [], 0
        while off < 5000:
            r = call(base + path, {'offset': off, 'limit': 100})
            items = r.get('samples', [])
            rows += items
            if len(items) < 100:
                break
            off += 100
        save(name, rows)
        return f'{len(rows)} адресов'
    return f


step('Вебмастер: страницы в поиске', wm_samples('/search-urls/in-search/samples', 'wm_in_search.json'))
step('Вебмастер: исключённые страницы', wm_samples('/search-urls/events/samples', 'wm_events.json'))
step('Вебмастер: что обошёл робот', wm_samples('/indexing/samples', 'wm_indexing.json'))
step('Вебмастер: запросы', wm_popular)
step('Вебмастер: аналитика по страницам', wm_analytics('URL'))
step('Вебмастер: аналитика по запросам', wm_analytics('QUERY'))
step('Метрика: фразы и страницы входа', mk('mk_phrases.json', 'ym:s:lastSearchEngineRoot,ym:s:lastSearchPhrase,ym:s:startURLPath'))
step('Метрика: страницы входа из поиска', mk('mk_pages.json', 'ym:s:lastSearchEngineRoot,ym:s:startURLPath'))
step('Метрика: ИИ и все источники по страницам', mk('mk_sources.json', 'ym:s:lastTrafficSource,ym:s:lastSourceEngine,ym:s:startURLPath', filters="ym:s:visits>0"))
save('log.json', log)
print('\n'.join(log))
