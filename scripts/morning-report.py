#!/usr/bin/env python3
"""Утренний отчёт agregatory.pro для рабочей группы, без Claude.

Берёт цифры за вчера из Яндекс Метрики и Яндекс Вебмастера по API (токен
YANDEX_TOKEN), проверяет сайт, оплату и SSL и собирает красивое сообщение
для Telegram (HTML, разделы в цитатах). Печатает текст в файл out.

Запуск:  python3 morning-report.py out.html
"""
import datetime as dt, html, json, os, re, socket, ssl, sys, urllib.parse, urllib.request

TOKEN = os.environ['YANDEX_TOKEN']
COUNTER = '112965538'
HOST_URL = 'https://agregatory.pro'
MSK = dt.timezone(dt.timedelta(hours=3))
today = dt.datetime.now(MSK).date()
yday = today - dt.timedelta(days=1)
wago = yday - dt.timedelta(days=7)
DAYS = ['понедельник', 'вторник', 'среда', 'четверг', 'пятница', 'суббота', 'воскресенье']
DAYS_ACC = ['понедельник', 'вторник', 'среду', 'четверг', 'пятницу', 'субботу', 'воскресенье']
MONTHS = ['января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа',
          'сентября', 'октября', 'ноября', 'декабря']
e = html.escape
problems = []


def get(url, params=None, auth=True):
    if params:
        url += '?' + urllib.parse.urlencode(params, doseq=True)
    req = urllib.request.Request(url, headers={'Authorization': f'OAuth {TOKEN}'} if auth else {})
    with urllib.request.urlopen(req, timeout=40) as r:
        return json.loads(r.read().decode())


def metrika(metrics, dims=None, date=yday, limit=20, sort=None, filters=None):
    p = {'ids': COUNTER, 'metrics': metrics, 'date1': date.isoformat(), 'date2': date.isoformat(),
         'limit': limit, 'accuracy': 'full', 'lang': 'ru'}
    if dims:
        p['dimensions'] = dims
    if sort:
        p['sort'] = sort
    if filters:
        p['filters'] = filters
    return get('https://api-metrika.yandex.net/stat/v1/data', p)


def total(metrics, date=yday, filters=None):
    d = metrika(metrics, date=date, filters=filters)
    return [round(x) for x in d['totals']]


def pct(now, before):
    if not before:
        return ''
    ch = round((now - before) / before * 100)
    return '＝' if ch == 0 else (f'▲ {ch}%' if ch > 0 else f'▼ {-ch}%')


def plural(n, one, few, many):
    n = abs(n) % 100
    if 11 <= n <= 14:
        return many
    n %= 10
    return one if n == 1 else few if 2 <= n <= 4 else many


# ---------- Метрика ----------
m = {}
try:
    STAFF = "ym:s:paramsLevel1!='staff'"
    v, u, pv = total('ym:s:visits,ym:s:users,ym:s:pageviews')
    v0, u0, _ = total('ym:s:visits,ym:s:users,ym:s:pageviews', date=wago)
    try:
        cv, cu = total('ym:s:visits,ym:s:users', filters=STAFF)
    except Exception:
        cv, cu = v, u
    m.update(visits=v, users=u, views=pv, visits0=v0, users0=u0, staff=max(v - cv, 0))

    src = metrika('ym:s:visits', 'ym:s:lastTrafficSource', limit=20)
    m['sources'] = [(r['dimensions'][0]['name'], round(r['metrics'][0])) for r in src['data']]
    se = metrika('ym:s:visits', 'ym:s:lastSearchEngineRoot', limit=10)
    m['engines'] = [(r['dimensions'][0]['name'], round(r['metrics'][0])) for r in se['data'] if r['dimensions'][0]['name']]
    s0 = metrika('ym:s:visits', 'ym:s:lastTrafficSource', date=wago, limit=20)
    m['search0'] = sum(round(r['metrics'][0]) for r in s0['data'] if 'поиск' in (r['dimensions'][0]['name'] or '').lower())
    ref = metrika('ym:s:visits', 'ym:s:lastReferalDomain', limit=10)
    m['sites'] = [(r['dimensions'][0]['name'], round(r['metrics'][0])) for r in ref['data'] if r['dimensions'][0]['name']]
    soc = metrika('ym:s:visits', 'ym:s:lastSocialNetwork', limit=10)
    m['social'] = [(r['dimensions'][0]['name'], round(r['metrics'][0])) for r in soc['data'] if r['dimensions'][0]['name']]
    ent = metrika('ym:s:visits', 'ym:s:startURLPath', limit=5, sort='-ym:s:visits')
    m['entry'] = [(r['dimensions'][0]['name'], round(r['metrics'][0])) for r in ent['data']]
    ph = metrika('ym:s:visits', 'ym:s:lastSearchPhrase', limit=100)
    m['phrases'] = [(r['dimensions'][0]['name'] or '', round(r['metrics'][0])) for r in ph['data']]

    goals = get(f'https://api-metrika.yandex.net/management/v1/counter/{COUNTER}/goals')['goals']
    gl = [g for g in goals if g.get('type') == 'action']  # только свои JS цели, без автоцелей
    m['goals'] = {}
    for i in range(0, len(gl), 20):
        part = gl[i:i + 20]
        t = total(','.join(f'ym:s:goal{g["id"]}reaches' for g in part))
        for g, n in zip(part, t):
            m['goals'][g['name']] = n
except Exception as ex:
    problems.append(f'Метрика не ответила: {e(str(ex)[:120])}')

# ---------- Вебмастер ----------
w = {}
try:
    uid = get('https://api.webmaster.yandex.net/v4/user')['user_id']
    hosts = get(f'https://api.webmaster.yandex.net/v4/user/{uid}/hosts')['hosts']
    host = next(h for h in hosts if 'agregatory.pro' in h['ascii_host_url'] and h['ascii_host_url'].startswith('https'))
    hid = host['host_id']
    base = f'https://api.webmaster.yandex.net/v4/user/{uid}/hosts/{hid}'
    s = get(base + '/summary')
    w['sqi'] = s.get('sqi')
    w['in_search'] = s.get('searchable_pages_count')
    w['excluded'] = s.get('excluded_pages_count')
    w['problems'] = {k: v for k, v in (s.get('site_problems') or {}).items() if v}
    q = get(base + '/search-queries/popular', {
        'order_by': 'TOTAL_SHOWS', 'query_indicator': ['TOTAL_SHOWS', 'TOTAL_CLICKS', 'AVG_SHOW_POSITION'],
        'date_from': (yday - dt.timedelta(days=6)).isoformat(), 'date_to': yday.isoformat(), 'limit': 6})
    q0 = get(base + '/search-queries/popular', {
        'order_by': 'TOTAL_SHOWS', 'query_indicator': ['AVG_SHOW_POSITION'],
        'date_from': (yday - dt.timedelta(days=13)).isoformat(), 'date_to': (yday - dt.timedelta(days=7)).isoformat(),
        'limit': 500})
    prev = {x['query_text']: x['indicators'].get('AVG_SHOW_POSITION') for x in q0.get('queries', [])}
    w['queries'] = []
    for x in q.get('queries', []):
        pos = x['indicators'].get('AVG_SHOW_POSITION')
        w['queries'].append((x['query_text'], pos, prev.get(x['query_text']), x['indicators'].get('TOTAL_CLICKS', 0)))
except Exception as ex:
    problems.append(f'Вебмастер не ответил: {e(str(ex)[:120])}')

# ---------- Здоровье ----------
def site_ok():
    try:
        req = urllib.request.Request(HOST_URL + '/', headers={'User-Agent': 'agregatory-report'})
        with urllib.request.urlopen(req, timeout=30) as r:
            return r.status == 200
    except Exception:
        return False


def pay_ok():
    try:
        with urllib.request.urlopen(HOST_URL + '/api/pay.php?check=1', timeout=30) as r:
            return '"enabled":true' in r.read().decode()
    except Exception:
        return False


def ssl_days():
    try:
        ctx = ssl.create_default_context()
        with socket.create_connection(('agregatory.pro', 443), timeout=20) as s:
            with ctx.wrap_socket(s, server_hostname='agregatory.pro') as t:
                end = dt.datetime.strptime(t.getpeercert()['notAfter'], '%b %d %H:%M:%S %Y %Z')
        return (end - dt.datetime.utcnow()).days
    except Exception:
        return None


ok_site, ok_pay, days = site_ok(), pay_ok(), ssl_days()

# ---------- Сборка ----------
def line(icon, name, n):
    return f'{icon} {e(name)} · <b>{n}</b>'


def goal_sum(*keys):
    g = m.get('goals', {})
    return sum(n for name, n in g.items() if any(k in name.lower() for k in keys))


out = [f'📊 <b>agregatory.pro · утренний отчёт</b>',
       f'<i>{DAYS[today.weekday()]}, {today.day} {MONTHS[today.month - 1]} · цифры за {DAYS_ACC[yday.weekday()]}</i>', '']

leads = goal_sum('заявк') - goal_sum('ошибк')
pays = goal_sum('оплата прошла', 'оплатил', 'pay_success')
search = sum(n for s, n in m.get('sources', []) if 'поиск' in (s or '').lower())
main = ['⚡️ <b>ГЛАВНОЕ ЗА ДЕНЬ</b>', '']
if m.get('visits') is not None:
    main.append(f'👥 Посетители: <b>{m["users"]}</b>   {pct(m["users"], m["users0"])} к прошлой неделе')
    main.append(f'📩 Заявки: <b>{max(leads, 0)}</b>')
    main.append(f'💳 Оплаты: <b>{pays}</b>')
    main.append(f'🔎 Из поиска: <b>{search}</b>   {pct(search, m.get("search0"))}')
    note = []
    if m['users0'] and m['users'] >= m['users0'] * 1.3:
        note.append('посетителей заметно больше, чем неделю назад')
    elif m['users0'] and m['users'] <= m['users0'] * 0.7:
        note.append('посетителей заметно меньше, чем неделю назад')
    if leads > 0:
        note.append(f'{leads} {plural(leads, "заявка", "заявки", "заявок")}, проверьте CRM')
    if note:
        main += ['', f'💬 <i>{e("; ".join(note).capitalize())}.</i>']
else:
    main.append('Метрика не ответила, цифр за день нет')
out.append('<blockquote>' + '\n'.join(main) + '</blockquote>')

g = m.get('goals', {})
if g is not None:
    rows = [line('▫️', name, n) for name, n in sorted(g.items(), key=lambda x: -x[1]) if n]
    if not any('заявк' in k.lower() for k, n in g.items() if n):
        rows.insert(0, '📝 Заявок не было')
    if not pays:
        rows.append('💳 Оплат не было')
    out += ['', '📩 <b>ЗАЯВКИ И ДЕЙСТВИЯ</b>', '<blockquote>' + '\n'.join(rows) + '</blockquote>']

if m.get('sources'):
    rows = []
    for name, n in m.get('engines', []):
        rows.append(line('🟡' if 'Яндекс' in name else '🔵' if 'Google' in name else '🔍', name, n))
    for name, n in m.get('social', []):
        rows.append(line('✈️' if 'Telegram' in name else '💬', name, n))
    for name, n in m.get('sites', [])[:5]:
        rows.append(line('🌐', name, n))
    for name, n in m['sources']:
        if 'прям' in (name or '').lower():
            rows.append(line('↪️', 'Прямые заходы', n))
    if m.get('staff'):
        rows.append(f'👤 Свои заходы · {m["staff"]} (не считаем)')
    if m.get('entry'):
        rows += ['', '<b>Куда заходили первым делом</b>']
        rows += [f'{i}. {e(p)} · {n}' for i, (p, n) in enumerate(m['entry'], 1)]
    sup = sum(n for p, n in m.get('phrases', []) if re.search(r'поддерж|телефон|позвон|горяч|номер', p.lower()))
    if sup:
        rows += ['', f'☎️ Искали поддержку Яндекс Еды · {sup} (не клиенты)']
    out += ['', '🧭 <b>ОТКУДА ПРИШЛИ</b>', '<blockquote expandable>' + '\n'.join(rows) + '</blockquote>']

if w.get('queries'):
    rows = []
    for qt, pos, p0, cl in w['queries']:
        if pos is None:
            continue
        sh = '＝' if p0 is None or round(p0) == round(pos) else (f'▲{round(p0 - pos)}' if pos < p0 else f'▼{round(pos - p0)}')
        if p0 is None:
            sh = '🆕'
        rows.append(f'{e(qt)} · <b>{pos:.0f}</b>  {sh}')
    rows.append('<i>средняя позиция за 7 дней, сдвиг к прошлым 7</i>')
    out += ['', '🔎 <b>ПОЗИЦИИ В ПОИСКЕ</b>', '<blockquote expandable>' + '\n'.join(rows) + '</blockquote>']

rows = [f'{"✅" if ok_site else "❌"} Сайт {"открывается" if ok_site else "не открывается"}   '
        f'{"✅" if ok_pay else "❌"} Оплата {"включена" if ok_pay else "выключена"}']
if days is not None:
    rows.append(f'🔒 SSL ещё {days} {plural(days, "день", "дня", "дней")}')
if w.get('in_search') is not None:
    rows.append(f'📚 В поиске Яндекса · <b>{w["in_search"]}</b> страниц, исключено {w.get("excluded", 0)}')
if w.get('sqi') is not None:
    rows.append(f'⭐️ ИКС · {w["sqi"]}')
if w.get('problems'):
    rows.append('⚠️ Вебмастер видит проблемы: ' + e(', '.join(f'{k.lower()} {v}' for k, v in w['problems'].items())))
for p in problems:
    rows.append('⚠️ ' + p)
out += ['', '🩺 <b>ИНДЕКСАЦИЯ И ЗДОРОВЬЕ</b>', '<blockquote>' + '\n'.join(rows) + '</blockquote>']

text = '\n'.join(out)
text = re.sub(r'(?<=[^\s<>/=":])\s[-–—]\s', ', ', text)  # без тире в тексте
open(sys.argv[1], 'w', encoding='utf-8').write(text)
print(text)
