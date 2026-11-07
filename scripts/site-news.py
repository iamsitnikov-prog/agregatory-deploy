#!/usr/bin/env python3
"""Сообщение в рабочую группу о том, что поменялось на сайте после выкладки.

Запуск в выкладке:  python3 site-news.py <site_repo> <dist> <state_dir> <out_file>

Берёт коммиты кода сайта с прошлой выкладки (state_dir/site_sha) и новые
страницы (state_dir/added.txt). Коммиты, которые меняют только журналы seo/,
не считаются. Если менять нечего, файл не создаётся и сообщение не уходит.
"""
import datetime, html, os, re, subprocess, sys

repo, dist, state, out = sys.argv[1:5]
SITE = 'https://agregatory.pro'
MONTHS = ['января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля',
          'августа', 'сентября', 'октября', 'ноября', 'декабря']


def git(*a):
    return subprocess.run(['git', '-C', repo, *a], capture_output=True, text=True).stdout.strip()


head = git('rev-parse', 'HEAD')
prev_path = os.path.join(state, 'site_sha')
prev = open(prev_path).read().strip() if os.path.exists(prev_path) else ''
open(prev_path, 'w').write(head)


def clean(t):
    t = re.sub(r'\s+[—–-]\s+', ', ', t)        # без тире в тексте для группы
    t = t.replace('—', ',').replace('–', ',')
    return html.escape(t.strip().rstrip('.'))


changes = []
if prev and prev != head and git('cat-file', '-t', prev) == 'commit':
    for sha in git('rev-list', '--reverse', '--no-merges', f'{prev}..{head}').split():
        files = git('show', '--name-only', '--format=', sha).split('\n')
        files = [f for f in files if f]
        if files and all(f.startswith('seo/') for f in files):
            continue  # только журналы индексации и реакций
        subj = git('log', '-1', '--format=%s', sha)
        if subj:
            changes.append(clean(subj))

added = []
ap = os.path.join(state, 'added.txt')
if os.path.exists(ap):
    for url in [u.strip() for u in open(ap) if u.strip()]:
        rel = url[len(SITE):].strip('/')
        f = os.path.join(dist, rel, 'index.html') if rel else os.path.join(dist, 'index.html')
        title = rel or 'главная'
        if os.path.exists(f):
            m = re.search(r'<h1[^>]*>(.*?)</h1>', open(f, encoding='utf-8').read(), re.S)
            if m:
                title = re.sub(r'<[^>]+>', ' ', m.group(1))
                title = re.sub(r'\s+', ' ', html.unescape(title)).strip()
        added.append((url, clean(title)))

if not changes and not added:
    print('Для группы новостей нет'); sys.exit(0)

now = datetime.datetime.now(datetime.timezone(datetime.timedelta(hours=3)))
when = f'{now.day} {MONTHS[now.month - 1]}, {now:%H:%M}'
lines = [f'🛠 <b>Обновление сайта</b>  ·  <i>{when}</i>', '']
if changes:
    lines.append('✏️ <b>Что поменяли</b>')
    shown = changes[:12]
    lines.append('<blockquote>' + '\n'.join(f'▫️ {c}' for c in shown)
                 + (f'\n<i>и ещё {len(changes) - 12}</i>' if len(changes) > 12 else '') + '</blockquote>')
    lines.append('')
if added:
    lines.append('🆕 <b>Новые страницы</b>')
    for url, t in added[:10]:
        lines.append(f'▫️ <a href="{url}">{t}</a>')
    if len(added) > 10:
        lines.append(f'<i>и ещё {len(added) - 10}</i>')
    lines.append('')
lines.append('✅ Выложено и проверено, сайт открывается')
lines.append(f'🔗 <a href="{SITE}/">agregatory.pro</a>')
text = '\n'.join(lines)
if len(text) > 3900:
    text = text[:3800].rsplit('\n', 1)[0] + '\n<i>список сокращён</i>'
    # закрываем цитату, если обрезали внутри
    if text.count('<blockquote>') > text.count('</blockquote>'):
        text += '</blockquote>'
open(out, 'w', encoding='utf-8').write(text)
print(text)
