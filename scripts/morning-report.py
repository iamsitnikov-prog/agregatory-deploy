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
STAFF = "ym:s:paramsLevel1=='staff'"  # наши заходы (Юрий, Алиса, Лиля): вычитаем их


def safe(name, fn):
    try:
        return fn()
    except Exception as ex:
        msg = str(ex)
        if hasattr(ex, 'read'):
            try:
                msg += ' ' + ex.read().decode()[:200]
            except Exception:
                pass
        print(f'::warning::{name}: {msg}', file=sys.stderr)
        problems.append(f'{name}: не получилось')
        return None


def rows_of(d):
    return [(r['dimensions'][0]['name'] or '', round(r['metrics'][0])) for r in d['data']]


def clients(dim, date=yday, limit=20):
    """Разбивка визитов без наших заходов: всего минус staff по каждой строке."""
    allr = rows_of(metrika('ym:s:visits', dim, date=date, limit=limit, sort='-ym:s:visits'))
    try:
        st = dict(rows_of(metrika('ym:s:visits', dim, date=date, limit=100, filters=STAFF)))
    except Exception:
        st = {}
    res = [(k, n - st.get(k, 0)) for k, n in allr]
    return [x for x in sorted(res, key=lambda x: -x[1]) if x[1] > 0]


def base():
    v, u = total('ym:s:visits,ym:s:users')
    sv, su = total('ym:s:visits,ym:s:users', filters=STAFF)
    v0, u0 = total('ym:s:visits,ym:s:users', date=wago)
    sv0, su0 = total('ym:s:visits,ym:s:users', date=wago, filters=STAFF)
    m.update(visits=v - sv, users=u - su, visits0=v0 - sv0, users0=u0 - su0, staff=sv)


safe('Метрика, посещаемость', base)
m['sources'] = safe('Метрика, источники', lambda: clients('ym:s:lastTrafficSource')) or []
m['search0'] = sum(n for s_, n in (safe('Метрика, источники неделю назад', lambda: clients('ym:s:lastTrafficSource', date=wago)) or []) if 'поиск' in s_.lower())
m['engines'] = [x for x in (safe('Метрика, поисковики', lambda: clients('ym:s:lastSearchEngineRoot')) or []) if x[0]]
m['sites'] = [x for x in (safe('Метрика, сайты', lambda: clients('ym:s:lastReferalSource')) or []) if x[0]]
m['social'] = [x for x in (safe('Метрика, соцсети', lambda: clients('ym:s:lastSocialNetwork')) or []) if x[0]]
m['entry'] = (safe('Метрика, страницы входа', lambda: clients('ym:s:startURLPathFull', limit=30)) or [])[:5]
m['phrases'] = safe('Метрика, поисковые фразы', lambda: rows_of(metrika('ym:s:visits', 'ym:s:lastSearchPhrase', limit=100))) or []


def goals_fn():
    goals = get(f'https://api-metrika.yandex.net/management/v1/counter/{COUNTER}/goals')['goals']
    gl = [g for g in goals if g.get('type') in ('action', 'js')]
    res = {}
    for i in range(0, len(gl), 20):
        part = gl[i:i + 20]
        t = total(','.join(f'ym:s:goal{g["id"]}reaches' for g in part))
        for g, n in zip(part, t):
            res[g['name']] = n
    return res


m['goals'] = safe('Метрика, цели', goals_fn)

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
        'order_by': 'TOTAL_SHOWS', 'query_indicator': ['TOTAL_SHOWS', 'TOTAL_CLICKS', 'AVG_SHOW_POSITION'], 'limit': 6})
    d_to = dt.date.fromisoformat(q['date_from'][:10]) - dt.timedelta(days=1)
    q0 = get(base + '/search-queries/popular', {
        'order_by': 'TOTAL_SHOWS', 'query_indicator': ['AVG_SHOW_POSITION'],
        'date_from': (d_to - dt.timedelta(days=6)).isoformat(), 'date_to': d_to.isoformat(), 'limit': 500})
    prev = {x['query_text']: x['indicators'].get('AVG_SHOW_POSITION') for x in q0.get('queries', [])}
    w['qto'] = '.'.join(reversed(q['date_to'][:10].split('-')[1:]))
    if (yday - dt.date.fromisoformat(q['date_to'][:10])).days > 7:
        problems.append(f'Вебмастер не обновлял статистику запросов с {w["qto"]}, позиции старые')
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
    g = m.get('goals') or {}
    return sum(n for name, n in g.items() if any(k in name.lower() for k in keys))


out = [f'📊 <b>agregatory.pro · утренний отчёт</b>',
       f'<i>{DAYS[today.weekday()]}, {today.day} {MONTHS[today.month - 1]} · цифры за {DAYS_ACC[yday.weekday()]}</i>', '']

leads = sum(n for name, n in (m.get('goals') or {}).items() if 'заявк' in name.lower() and 'ошибк' not in name.lower())
pays = goal_sum('оплата прошла', 'оплатил', 'pay_success')
search = sum(n for s, n in m.get('sources', []) if 'поиск' in (s or '').lower())
main = ['⚡️ <b>ГЛАВНОЕ ЗА ДЕНЬ</b>', '']
if m.get('users') is not None:
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

g = m.get('goals')
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
    rows.append(f'<i>средняя позиция за неделю по {w.get("qto", "")}, сдвиг к неделе раньше</i>')
    out += ['', '🔎 <b>ПОЗИЦИИ В ПОИСКЕ</b>', '<blockquote expandable>' + '\n'.join(rows) + '</blockquote>']

rows = [f'{"✅" if ok_site else "❌"} Сайт {"открывается" if ok_site else "не открывается"}   '
        f'{"✅" if ok_pay else "❌"} Оплата {"включена" if ok_pay else "выключена"}']
if days is not None:
    rows.append(f'🔒 SSL ещё {days} {plural(days, "день", "дня", "дней")}')
if w.get('in_search') is not None:
    rows.append(f'📚 В поиске Яндекса · <b>{w["in_search"]}</b> страниц, исключено {w.get("excluded", 0)}')
if w.get('sqi'):
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
