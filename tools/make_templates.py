"""Готовит шаблоны договоров для docxtemplater из исходных Word-файлов Юрия.

Меняет только поля в квадратных скобках на метки {…} и вносит правки,
согласованные 16.10.2026: цена и число точек полями, без КПП у исполнителя,
опечатка со скобкой, «Яндекс Еда» без точки, таблица точек в Приложении 1,
выбор подписанта ООО, контакты заказчика. Остальной текст не трогает.
"""
import copy
import re
import sys
from docx import Document
from docx.oxml.ns import qn
from docx.oxml import OxmlElement

W = '{http://schemas.openxmlformats.org/wordprocessingml/2006/main}'


def p_text(p):
    return ''.join(t.text or '' for t in p.iter(W + 't'))


def replace_in_p(p, old, new, count=0):
    """Заменяет текст внутри абзаца, даже если он разбит на несколько кусков (runs).
    Новый текст встаёт в первый кусок совпадения и берёт его оформление."""
    ts = list(p.iter(W + 't'))
    full = ''.join(t.text or '' for t in ts)
    if old not in full:
        return 0
    done = 0
    while old in full and (count == 0 or done < count):
        start = full.index(old)
        end = start + len(old)
        pos = 0
        first = True
        for t in ts:
            txt = t.text or ''
            a, b = pos, pos + len(txt)
            pos = b
            if b <= start or a >= end:
                continue
            s, e = max(start, a) - a, min(end, b) - a
            if first:
                t.text = txt[:s] + new + txt[e:]
                t.set('{http://www.w3.org/XML/1998/namespace}space', 'preserve')
                first = False
            else:
                t.text = txt[:s] + txt[e:]
                t.set('{http://www.w3.org/XML/1998/namespace}space', 'preserve')
        full = ''.join(t.text or '' for t in ts)
        done += 1
    return done


def paragraphs(doc):
    return list(doc.element.body.iter(W + 'p'))


def sub_all(doc, old, new, expect=None, where=None):
    n = 0
    for p in paragraphs(doc):
        if where and not where(p_text(p)):
            continue
        n += replace_in_p(p, old, new)
    if expect is not None and n != expect:
        raise SystemExit(f'ожидал {expect} замен «{old}», сделал {n}')
    return n


def remove_highlight(doc):
    for h in list(doc.element.body.iter(W + 'highlight')):
        h.getparent().remove(h)
    for sh in list(doc.element.body.iter(W + 'shd')):
        fill = (sh.get(W + 'fill') or '').upper()
        if sh.getparent().tag == W + 'rPr' and fill in ('FFFF00', 'FFF200', 'FFFF66'):
            sh.getparent().remove(sh)


def drop_paragraph(doc, startswith):
    for p in paragraphs(doc):
        if p_text(p).startswith(startswith):
            p.getparent().remove(p)
            return 1
    return 0


def set_cell(cell, text):
    ps = cell.paragraphs
    for p in ps[1:]:
        p._p.getparent().remove(p._p)
    p = ps[0]
    runs = p.runs
    if runs:
        runs[0].text = text
        for r in runs[1:]:
            r._r.getparent().remove(r._r)
    else:
        p.add_run(text)


def contacts(doc):
    tags = ['{phone}', '{email}', '{edo_operator}', '{edo_id}', '{contact}']
    i = 0
    for p in paragraphs(doc):
        if p_text(p).strip() == '____________________' and i < len(tags):
            replace_in_p(p, '____________________', tags[i])
            i += 1
    if i != 5:
        raise SystemExit(f'контактов нашёл {i}, ждал 5')


def points_table(doc):
    """Таблица точек в Приложении 1 сразу после «Стороны согласовали…»."""
    anchor = None
    for p in doc.paragraphs:
        if p.text.startswith('Стороны согласовали, что в рамках Договора'):
            anchor = p
            break
    if anchor is None:
        raise SystemExit('не нашёл абзац для таблицы точек')
    # Берём оформление у таблицы услуг (Приложение 1), чтобы точки выглядели так же.
    src = None
    for t in doc.tables:
        if t.rows[0].cells[0].text.strip() == '№' and 'Услуга' in t.rows[0].cells[1].text:
            src = t
            break
    head = copy.deepcopy(anchor._p)
    for t in head.iter(W + 't'):
        t.text = ''
    ts = list(head.iter(W + 't'))
    ts[0].text = 'Услуги оказываются в отношении следующих точек Заказчика на Сервисе:'
    anchor._p.addprevious(head)
    tbl = copy.deepcopy(src._tbl)
    rows = tbl.findall(W + 'tr')
    for r in rows[2:]:
        tbl.remove(r)
    # 4 колонки исходной таблицы: № | Услуга | Содержание | трудоёмкость → № | Название | Адрес | Ссылка
    def fill(row, vals):
        cells = row.findall(W + 'tc')
        for c, v in zip(cells, vals):
            psx = c.findall(W + 'p')
            for extra in psx[1:]:
                c.remove(extra)
            p = psx[0]
            tlist = list(p.iter(W + 't'))
            if not tlist:
                r = OxmlElement('w:r'); t = OxmlElement('w:t'); r.append(t); p.append(r); tlist = [t]
            tlist[0].text = v
            tlist[0].set('{http://www.w3.org/XML/1998/namespace}space', 'preserve')
            for t in tlist[1:]:
                t.text = ''
    fill(rows[0], ['№', 'Название точки на Сервисе', 'Адрес точки', 'Город'])
    fill(rows[1], ['{#points}{n}', '{title}', '{address}', '{city}{/points}'])
    # Ширина колонок: город шире, чем трудоёмкость в исходной таблице
    grid = tbl.find(W + 'tblGrid')
    cols = grid.findall(W + 'gridCol')
    total = sum(int(c.get(qn('w:w'))) for c in cols)
    widths = [int(total * f) for f in (0.07, 0.30, 0.41)]
    widths.append(total - sum(widths))
    for c, wv in zip(cols, widths):
        c.set(qn('w:w'), str(wv))
    for row in rows[:2]:
        for c, wv in zip(row.findall(W + 'tc'), widths):
            tcPr = c.find(W + 'tcPr')
            tw = tcPr.find(W + 'tcW') if tcPr is not None else None
            if tw is not None:
                tw.set(qn('w:w'), str(wv)); tw.set(qn('w:type'), 'dxa')
    # В строке данных обычный, не жирный шрифт
    for b in list(rows[1].iter(W + 'b')) + list(rows[1].iter(W + 'bCs')):
        b.getparent().remove(b)
    anchor._p.addprevious(tbl)
    gap = copy.deepcopy(head)
    for t in gap.iter(W + 't'):
        t.text = ''
    anchor._p.addprevious(gap)


def executor(doc):
    """Исполнитель выбирается на странице (Ситников или Малюта): его данные тоже метки."""
    gender_p = lambda t: 'Ситников Юрий Сергеевич (ОГРНИП' in t
    sub_all(doc, 'действующий', '{e_acting}', where=lambda t: gender_p(t) and 'действующий на основании записи' in t)
    for p in paragraphs(doc):
        if gender_p(p_text(p)):
            replace_in_p(p, 'именуемый', '{e_named}', count=1)
    for a, b in [
        ('автоматизированной упрощённой системы налогообложения (АУСН) в соответствии с Федеральным законом от 25.02.2022 № 17-ФЗ', '{e_tax_law}'),
        ('автоматизированной упрощённой системы налогообложения (АУСН)', '{e_tax_gen}'),
        ('автоматизированную упрощённую систему налогообложения (АУСН)', '{e_tax_acc}'),
        ('Ситников Юрий Сергеевич', '{e_fio}'), ('Ситников Ю.С.', '{e_short}'),
        ('325632700100793', '{e_ogrnip}'), ('632509481120', '{e_inn}'),
        ('192007 г.Санкт-Петербург, Лиговский 242 к.1 кв.30', '{e_address}'),
        ('Северо-Западный банк ПАО Сбербанк', '{e_bank}'), ('40802810955710016483', '{e_rs}'),
        ('044030653', '{e_bik}'), ('30101810500000000653', '{e_ks}'),
        ('+7 931 002-82-22', '{e_phone}'), ('i.am.sitnikov@gmail.com', '{e_email}'),
        ('2BEe17ee89c5e5341f08fd2890b3c14ad2a', '{e_edo_id}'),
    ]:
        sub_all(doc, a, b)
    n = 0
    for p in paragraphs(doc):
        if p_text(p).strip() == 'СБИС':
            n += replace_in_p(p, 'СБИС', '{e_edo}')
    if n != 1:
        raise SystemExit(f'оператор ЭДО исполнителя: {n}')
    left = [p_text(p) for p in paragraphs(doc) if re.search(r'Ситников|632509481120|АУСН|Лиговск', p_text(p))]
    if left:
        raise SystemExit(f'остались данные исполнителя: {left[:3]}')


def build(kind):
    doc = Document(f'src/{kind}.docx')
    remove_highlight(doc)
    drop_paragraph(doc, 'Поля, выделенные жёлтым цветом')

    # Номер, город, дата договора, месяц
    sub_all(doc, '[номер]', '{num}')
    sub_all(doc, '[дата]', '{date}')
    # Услуги оказываются онлайн: в шапке вместо города «Дистанционно (онлайн)»,
    # город каждой точки бухгалтер пишет в таблице точек (Приложение 1)
    sub_all(doc, 'г. [город]', 'Дистанционно (онлайн)', expect=2)
    sub_all(doc, '[месяц, год]', '{month_year}')
    # «[», «номер», «]» разбиты на куски в одном месте: replace_in_p справляется сам.

    # Стоимость: п. 6.1 и образцы счёта, акта, УПД
    sub_all(doc, 'составляет 25 000 (двадцать пять тысяч) рублей 00 копеек за 1 (один) календарный месяц оказания Услуг.',
            'составляет {total} ({total_words}) рублей 00 копеек за 1 (один) календарный месяц оказания Услуг ({points_phrase}, по {price} рублей за одну точку).',
            expect=1)
    sub_all(doc, '25 000 (двадцать пять тысяч) руб. 00 коп.', '{total} ({total_words}) руб. 00 коп.', expect=4)
    sub_all(doc, '25 000-00', '{total}-00', expect=8)

    # «Яндекс Еда» без точки, кроме названия компании ООО «Яндекс.Еда»
    sub_all(doc, 'Яндекс.Еда)»', 'Яндекс Еда)»', where=lambda t: '«Сервис (Яндекс.Еда)»' in t)

    # Банк заказчика
    for a, b in [('[банк получателя]', '{bank}'), ('[расчётный счёт]', '{rs}'),
                 ('[БИК]', '{bik}'), ('[корреспондентский счёт]', '{ks}'), ('[ИНН]', '{inn}'),
                 ('[Фамилия И.О.]', '{short}')]:
        sub_all(doc, a, b)

    if kind == 'ooo':
        # Подписант: по частям, чтобы ФИО осталось жирным, как в шаблоне
        w = lambda t: 'в лице генерального директора [Фамилия Имя Отчество], действующего на основании Устава' in t
        sub_all(doc, 'в лице генерального директора ', 'в лице {sign_pos_gen} ', expect=2, where=w)
        w2 = lambda t: 'в лице {sign_pos_gen} [Фамилия Имя Отчество], действующего на основании Устава' in t
        sub_all(doc, ', действующего на основании Устава', ', {signer_acting} на основании {basis_text}', expect=2, where=w2)
        w3 = lambda t: 'в лице {sign_pos_gen} [Фамилия Имя Отчество], {signer_acting}' in t
        sub_all(doc, '[Фамилия Имя Отчество]', '{dir_gen}', expect=2, where=w3)
        sub_all(doc, '[Фамилия Имя Отчество], действует на основании Устава', '{dir_full}, действует на основании {basis_text}', expect=1)
        sub_all(doc, 'Генеральный директор ООО «[название ООО]»', '{sign_position} ООО «{name}»', expect=2)
        sub_all(doc, '[название ООО]', '{name}')
        sub_all(doc, '[ОГРН]', '{ogrn}')
        sub_all(doc, '[КПП]', '{kpp}')
        sub_all(doc, '[юридический адрес]', '{address}')
        # Строка «Генеральный директор» в реквизитах: должность из формы
        for t in doc.tables:
            for r in t.rows:
                if r.cells[0].text.strip() == 'Генеральный директор':
                    set_cell(r.cells[0], '{sign_position}')
                if r.cells[0].text.strip() == 'КПП' and r.cells[2].text.strip() == '784243001':
                    set_cell(r.cells[2], '—')
    else:
        cust = lambda t: 'в дальнейшем «Заказчик»' in t and '[Фамилия Имя Отчество]' in t
        sub_all(doc, 'действующий на основании записи', '{acting} на основании записи', expect=1, where=cust)
        cust2 = lambda t: 'именуемый в дальнейшем «Заказчик»' in t and '[Фамилия Имя Отчество]' in t
        sub_all(doc, 'именуемый', '{named}', expect=2, where=cust2)
        sub_all(doc, '[Фамилия Имя Отчество]', '{fio}')
        sub_all(doc, '[ОГРНИП]', '{ogrnip}')
        sub_all(doc, '[адрес регистрации ИП]', '{address}')
        sub_all(doc, '[192007 г.Санкт-Петербург', '192007 г.Санкт-Петербург', expect=1)
        for t in doc.tables:
            for r in list(t.rows):
                if r.cells[0].text.strip() == 'КПП' and r.cells[2].text.strip() == '784243001':
                    r._tr.getparent().remove(r._tr)

    contacts(doc)
    points_table(doc)
    executor(doc)

    left = [p_text(p) for p in paragraphs(doc) if re.search(r'\[[^\]]*\]', p_text(p))]
    if left:
        raise SystemExit(f'{kind}: остались поля в скобках: {left[:5]}')
    if any('784243001' in p_text(p) for p in paragraphs(doc)):
        raise SystemExit(f'{kind}: КПП исполнителя остался')
    doc.save(f'out/{kind}.docx')
    print(kind, 'ok')


if __name__ == '__main__':
    import os
    os.makedirs('out', exist_ok=True)
    for k in sys.argv[1:] or ['ooo', 'ip']:
        build(k)
