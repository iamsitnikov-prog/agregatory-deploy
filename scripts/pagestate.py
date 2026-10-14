#!/usr/bin/env python3
"""Честные даты изменений в sitemap.xml и список изменённых страниц для IndexNow.

Запуск в выкладке после сборки:  python3 pagestate.py <dist> <state_dir>

Для каждой страницы из sitemap.xml считаем отпечаток её содержимого (текст body
без скриптов, поэтому новые имена файлов сборки страницу «изменённой» не делают).
Если отпечаток не поменялся с прошлой выкладки, в <lastmod> остаётся дата
прошлого изменения, а не сегодняшняя. Иначе поисковики видят, что все страницы
«меняются» каждый день, и перестают доверять датам.

Пишет:
  <state_dir>/state.json   — {адрес: {"h": отпечаток, "d": "ГГГГ-ММ-ДД"}}
  <state_dir>/changed.txt  — полные адреса изменённых и новых страниц
"""
import datetime, hashlib, json, os, re, sys

dist, state_dir = sys.argv[1], sys.argv[2]
os.makedirs(state_dir, exist_ok=True)
state_path = os.path.join(state_dir, 'state.json')
old = json.load(open(state_path, encoding='utf-8')) if os.path.exists(state_path) else {}
first_run = not old
today = datetime.datetime.now(datetime.timezone.utc).strftime('%Y-%m-%d')

sm_path = os.path.join(dist, 'sitemap.xml')
sm = open(sm_path, encoding='utf-8').read()
SITE = 'https://agregatory.pro'


def fingerprint(route):
    rel = route[len(SITE):].strip('/')
    f = os.path.join(dist, rel, 'index.html') if rel else os.path.join(dist, 'index.html')
    if not os.path.exists(f):
        return None
    h = open(f, encoding='utf-8').read()
    m = re.search(r'<body[^>]*>(.*)</body>', h, re.S)
    body = m.group(1) if m else h
    body = re.sub(r'<script\b.*?</script>', '', body, flags=re.S)
    body = re.sub(r'<link\b[^>]*>', '', body)
    body = re.sub(r'/assets/[\w.-]+', '/assets/x', body)
    body = re.sub(r'\s+', ' ', body)
    title = re.search(r'<title>(.*?)</title>', h, re.S)
    desc = re.search(r'<meta name="description" content="([^"]*)"', h)
    key = (title.group(1) if title else '') + '|' + (desc.group(1) if desc else '') + '|' + body
    return hashlib.sha1(key.encode('utf-8')).hexdigest()


new, changed = {}, []


def fix(m):
    block = m.group(0)
    loc = re.search(r'<loc>([^<]+)</loc>', block).group(1)
    lastmod = re.search(r'<lastmod>([^<]+)</lastmod>', block)
    built = lastmod.group(1) if lastmod else today
    fp = fingerprint(loc)
    prev = old.get(loc)
    if prev and fp and prev['h'] == fp:
        d = prev['d']
    else:
        # Статьям блога сборка ставит дату публикации; остальным при первом
        # запуске — сегодняшнюю (раньше даты не хранились).
        d = built if (first_run or not prev) and built < today else today
        changed.append(loc)
    new[loc] = {'h': fp or '', 'd': d}
    return re.sub(r'<lastmod>[^<]+</lastmod>', f'<lastmod>{d}</lastmod>', block) if lastmod else block


sm = re.sub(r'<url>.*?</url>', fix, sm, flags=re.S)
open(sm_path, 'w', encoding='utf-8').write(sm)
json.dump(new, open(state_path, 'w', encoding='utf-8'), ensure_ascii=False, indent=0)
open(os.path.join(state_dir, 'changed.txt'), 'w').write('\n'.join(changed) + ('\n' if changed else ''))
kept = len(new) - len(changed)
print(f'Страниц в карте: {len(new)}, изменились или новые: {len(changed)}, без изменений: {kept}'
      + (' (первый запуск)' if first_run else ''))
