"""Оформление договора: меняет только вид (шрифт, цвета, линии), текст не трогает.
python3 style.py in.docx out.docx <classic|brand|strict|modern>"""
import re, sys, zipfile

FONTS = {'classic': None, 'brand': 'Arial', 'strict': 'Times New Roman', 'modern': 'Arial'}
YEL, BRN = 'FFD900', '23100B'


def set_font(x, font):
    if not font:
        return x
    x = re.sub(r'\s?w:(ascii|hAnsi|eastAsia|cs)Theme="[^"]*"', '', x)
    for a in ('ascii', 'hAnsi', 'eastAsia', 'cs'):
        x = re.sub(rf'w:{a}="[^"]*"', f'w:{a}="{font}"', x)
    return x


def text(p):
    return ''.join(re.findall(r'<w:t[^>]*>([^<]*)', p)).strip()


def is_heading(p):
    t = text(p)
    letters = re.sub(r'[^А-Яа-яЁёA-Za-z]', '', t)
    return 'w:jc w:val="center"' in p and len(letters) > 3 and letters == letters.upper()


def recolor_runs(p, color, size=None):
    def fix(m):
        rpr = m.group(0)
        rpr = re.sub(r'<w:color [^>]*/>', '', rpr)
        add = f'<w:color w:val="{color}"/>'
        if size:
            rpr = re.sub(r'<w:sz w:val="\d+"/>', f'<w:sz w:val="{size}"/>', rpr)
        return rpr.replace('</w:rPr>', add + '</w:rPr>')
    p = re.sub(r'<w:rPr>.*?</w:rPr>', fix, p)
    p = re.sub(r'<w:r>(?!<w:rPr>)', f'<w:r><w:rPr><w:color w:val="{color}"/></w:rPr>', p)
    return p


def add_ppr(p, frag, drop_jc=False):
    m = re.search(r'<w:pPr>(.*?)</w:pPr>', p)
    inner = m.group(1)
    inner = re.sub(r'<w:pBdr>.*?</w:pBdr>', '', inner)
    if drop_jc:
        inner = re.sub(r'<w:jc w:val="center"/>', '<w:jc w:val="left"/>', inner)
    # pBdr идёт после keepNext/keepLines/pageBreakBefore и до spacing
    k = re.search(r'<w:(spacing|ind|jc)\b', inner)
    inner = inner[:k.start()] + frag + inner[k.start():] if k else inner + frag
    return p[:m.start()] + '<w:pPr>' + inner + '</w:pPr>' + p[m.end():]


def body_paragraphs(d, fn):
    """Применяет fn к абзацам вне таблиц."""
    parts = re.split(r'(<w:tbl>.*?</w:tbl>)', d, flags=re.S)
    for i, s in enumerate(parts):
        if s.startswith('<w:tbl>'):
            continue
        parts[i] = re.sub(r'<w:p[ >].*?</w:p>', lambda m: fn(m.group(0)), s, flags=re.S)
    return ''.join(parts)


def head_cells(d, fill, color):
    """Ячейки-заголовки таблиц (кремовая заливка в исходнике) → новая заливка и цвет текста."""
    def fix(m):
        tc = m.group(0)
        if not re.search(r'w:fill="(FCEFC7|FAE2D5)"', tc):
            return tc
        tc = re.sub(r'w:fill="(FCEFC7|FAE2D5)"', f'w:fill="{fill}"', tc)
        return recolor_runs(tc, color) if color else tc
    return re.sub(r'<w:tc>.*?</w:tc>', fix, d, flags=re.S)


def keep_tables(d):
    """Таблицы реквизитов и контактов не разрываем между страницами."""
    def fix(m):
        t = m.group(0)
        if not any(k in t for k in ('Правовая форма', 'Электронная почта', 'Подпись:', 'Услуги оказаны:')):
            return t
        rows = re.findall(r'<w:tr[ >].*?</w:tr>', t, flags=re.S)
        for r in rows[:-1]:
            r2 = re.sub(r'<w:pPr>(?!<w:keepNext/>)', '<w:pPr><w:keepNext/>', r)
            r2 = re.sub(r'<w:p>', '<w:p><w:pPr><w:keepNext/></w:pPr>', r2)
            t = t.replace(r, r2, 1)
        return t
    return re.sub(r'<w:tbl>.*?</w:tbl>', fix, d, flags=re.S)


def set_ppr_flag(p, flag):
    """Добавляет keepNext / pageBreakBefore в начало pPr (порядок схемы: pStyle, keepNext, keepLines, pageBreakBefore)."""
    if f'<w:{flag}/>' in p:
        return p
    if '<w:pPr>' not in p:
        return re.sub(r'(<w:p(?: [^>]*)?>)', rf'\1<w:pPr><w:{flag}/></w:pPr>', p, count=1)
    m = re.search(r'<w:pPr>(<w:pStyle [^>]*/>)?(<w:keepNext/>)?(<w:keepLines/>)?', p)
    return p[:m.end()] + f'<w:{flag}/>' + p[m.end():] if flag == 'pageBreakBefore' else \
        p[:m.start()] + '<w:pPr>' + (m.group(1) or '') + '<w:keepNext/>' + p[m.end(1) if m.group(1) else m.start() + 7:]


TEXT_W = 11906 - 1701 - 850  # ширина текста A4 при полях шаблона, twips
TABLE_W = TEXT_W - 90  # таблицы чуть уже текста: правая рамка не срезается краем поля в просмотрщиках
UPD_GRID = [450, 3255, 550, 500, 1050, 1050, 700, 700, 1100]


def fit_tables(d):
    """Таблицы шире полей (9600 при 9355) ужимаем пропорционально, чтобы не вылезали за край."""
    def fix(m):
        t = m.group(0)
        W = int(re.search(r'<w:tblW w:w="(\d+)"', t).group(1))
        grid = [int(x) for x in re.findall(r'<w:gridCol w:w="(\d+)"', t)]
        if 'Ед. изм.' in t and len(grid) == len(UPD_GRID):
            new = UPD_GRID
        elif grid == [2600, 3500, 3500]:  # реквизиты, контакты, подписи: шире колонка подписей строк
            new = [2850, 3252, 3253]
        elif grid == [500, 2600, 5400, 1100]:  # услуги Приложения 1: «трудоёмкость» без переноса по буквам
            new = [480, 2250, 4675, 1950]
        elif grid == [500, 8000, 1100]:
            new = [480, 6925, 1950]
        elif W > TEXT_W:
            new = [round(g * TEXT_W / W) for g in grid]
            new[-1] += TEXT_W - sum(new)
        else:
            return t
        new = [round(g * TABLE_W / sum(new)) for g in new]
        new[new.index(max(new))] += TABLE_W - sum(new)
        t = re.sub(r'<w:tblW w:w="\d+"', f'<w:tblW w:w="{TABLE_W}"', t, count=1)
        it = iter(new)
        t = re.sub(r'<w:gridCol w:w="\d+"/>', lambda _: f'<w:gridCol w:w="{next(it)}"/>', t)
        # ширины ячеек: по сетке строки с учётом gridSpan
        def row(mr):
            r = mr.group(0)
            col = [0]
            def cell(mc):
                c = mc.group(0)
                span = re.search(r'<w:gridSpan w:val="(\d+)"', c)
                span = int(span.group(1)) if span else 1
                w = sum(new[col[0]:col[0] + span])
                col[0] += span
                return re.sub(r'<w:tcW w:w="\d+"', f'<w:tcW w:w="{w}"', c, count=1)
            return re.sub(r'<w:tc>.*?</w:tc>', cell, r, flags=re.S)
        t = re.sub(r'<w:tr[ >].*?</w:tr>', row, t, flags=re.S)
        if 'Ед. изм.' in t and len(new) == len(UPD_GRID):
            t = upd_total_row(t, new)
        return t
    return re.sub(r'<w:tbl>.*?</w:tbl>', fix, d, flags=re.S)


def upd_total_row(t, g):
    """Строка «Всего к оплате» в образце УПД: суммы под своими колонками, без пустого хвоста."""
    rows = re.findall(r'<w:tr[ >].*?</w:tr>', t, flags=re.S)
    r = rows[-1]
    if 'Всего к оплате' not in r or 'gridAfter' not in r:
        return t
    cells = re.findall(r'<w:tc>.*?</w:tc>', r, flags=re.S)
    total, no_vat, dash, summ = cells
    w = lambda c, x: re.sub(r'<w:tcW w:w="\d+"', f'<w:tcW w:w="{x}"', c, count=1)
    total = w(total, sum(g[:5])).replace('</w:tcW>', '').replace('<w:tcBorders>', '<w:gridSpan w:val="5"/><w:tcBorders>', 1)
    new_cells = total + w(summ, g[5]) + w(no_vat, g[6]) + w(dash, g[7]) + w(summ, g[8])
    head = r[:r.index('<w:tc>')]
    head = re.sub(r'<w:gridAfter w:val="\d+"/>|<w:wAfter [^>]*/>', '', head)
    return t.replace(r, head + new_cells + '</w:tr>')


def compact_samples(d):
    """Образцы счёта, акта и УПД (Приложения 2–4) плотнее, чтобы каждый помещался на свою страницу."""
    i = d.find('>Приложение № 2<')
    i = d.rfind('<w:p ', 0, i)
    head, tail = d[:i], d[i:]
    def sp(m):
        a = m.group(0)
        a = re.sub(r'w:(before|after)="(\d+)"', lambda x: f'w:{x.group(1)}="{int(x.group(2)) * 45 // 100}"', a)
        return a.replace('w:line="250"', 'w:line="240"')
    tail = re.sub(r'<w:spacing [^>]*/>', sp, tail)
    # основной текст образцов 10 pt вместо 12 pt; куски без своего размера берут размер соседей по абзацу
    after_sz = r'<w:(szCs|highlight|u|effect|bdr|shd|fitText|vertAlign|rtl|cs|em|lang|eastAsianLayout|specVanish|oMath)\b'

    def para(mp):
        p = mp.group(0)
        sizes = re.findall(r'<w:r(?: [^>]*)?><w:rPr>(?:(?!</w:rPr>).)*<w:sz w:val="(\d+)"', p)
        size = max(set(sizes), key=sizes.count) if sizes else '20'

        def rpr(m):
            r = m.group(0)
            if '<w:sz ' in r:
                return r
            k = re.search(after_sz, r)
            add = f'<w:sz w:val="{size}"/>' + ('' if k and k.group(1) == 'szCs' else f'<w:szCs w:val="{size}"/>')
            return r[:k.start()] + add + r[k.start():] if k else r.replace('</w:rPr>', add + '</w:rPr>')
        p = re.sub(r'(?<=<w:r>)<w:rPr>.*?</w:rPr>|(?<=<w:r )[^>]*><w:rPr>.*?</w:rPr>', rpr, p, flags=re.S)
        return re.sub(r'(<w:r(?: [^>]*)?>)(?!<w:rPr>)', rf'\1<w:rPr><w:sz w:val="{size}"/><w:szCs w:val="{size}"/></w:rPr>', p)
    tail = re.sub(r'<w:p[ >](?:(?!</w:p>).)*</w:p>', para, tail, flags=re.S)
    tail = re.sub(r'<w:trHeight w:val="(\d+)"/>', lambda x: f'<w:trHeight w:val="{min(int(x.group(1)), 400)}"/>', tail)
    return head + tail


def layout(d):
    """Вёрстка, чтобы при любых данных ничего не съезжало."""
    d = fit_tables(d)
    d = compact_samples(d)
    # дата договора по правому краю текста, а не за полем
    d = d.replace('<w:tab w:val="right" w:pos="9638"/>', f'<w:tab w:val="right" w:pos="{TEXT_W}"/>')
    # строка «Дистанционно (онлайн) … «__» ___ 20__ г.» в образце акта: пробелы → табуляция вправо
    def act_line(m):
        p = m.group(0)
        if 'Дистанционно (онлайн)' not in p or '«____» ________________ 20__ г.' not in p:
            return p
        p = p.replace('<w:jc w:val="center"/>', '', 1)
        p = p.replace('<w:pPr>', f'<w:pPr><w:tabs><w:tab w:val="right" w:pos="{TEXT_W}"/></w:tabs>', 1)
        p = p.replace('<w:t xml:space="preserve">   </w:t>', '<w:t xml:space="preserve"></w:t>')
        return re.sub(r'<w:t xml:space="preserve"> +«____»', '<w:tab/><w:t xml:space="preserve">«____»', p)
    d = re.sub(r'<w:p [^>]*>(?:(?!</w:p>).)*</w:p>', act_line, d, flags=re.S)
    # строки таблиц не рвём между страницами, шапку таблицы повторяем
    def rows(m):
        t = m.group(0)
        first = [True]
        def tr(mm):
            r = mm.group(0)
            flags = '<w:cantSplit/>' + ('<w:tblHeader/>' if first[0] else '')
            first[0] = False
            if '<w:trPr>' in r[:400]:
                r = r.replace('<w:trPr>', '<w:trPr>' + flags, 1)
            else:
                r = re.sub(r'^(<w:tr(?: [^>]*)?>(?:<w:tblPrEx>.*?</w:tblPrEx>)?)', r'\1<w:trPr>' + flags + '</w:trPr>', r, count=1, flags=re.S)
            return r
        return re.sub(r'<w:tr[ >].*?</w:tr>', tr, t, flags=re.S)
    d = re.sub(r'<w:tbl>.*?</w:tbl>', rows, d, flags=re.S)

    # приложения с новой страницы; заголовки держим со следующим абзацем
    parts = re.split(r'(<w:tbl>.*?</w:tbl>)', d, flags=re.S)
    for i, s in enumerate(parts):
        if s.startswith('<w:tbl>'):
            continue
        ps = re.findall(r'<w:p[ >].*?</w:p>|<w:p/>', s, flags=re.S)
        out, keep = s, 0
        nxt = parts[i + 1] if i + 1 < len(parts) else ''
        sig_next = any(k in nxt for k in ('Подпись:', 'Услуги оказаны:'))
        # к таблице подписей привязываем последний абзац с текстом и пустые строки после него
        texts_idx = [k for k, pp in enumerate(ps) if text(pp)]
        last_text = texts_idx[-1] if texts_idx else 0
        for j, p in enumerate(ps):
            t = text(p)
            q = p
            after = text(ps[j + 1]) if j + 1 < len(ps) else ''
            if (sig_next and j >= last_text) or after.startswith('Исполнитель: ___'):
                q = set_ppr_flag(q, 'keepNext')
            if re.fullmatch(r'Приложение № \d+', t):
                q = set_ppr_flag(q, 'pageBreakBefore')
                # тот же разрыв через стиль: его понимает и просмотрщик для PDF (docx-preview)
                q = q.replace('<w:pPr>', '<w:pPr><w:pStyle w:val="AppendixStart"/>', 1)
                q = re.sub(r'<w:spacing w:before="\d+"', '<w:spacing w:before="0"', q)
                keep = 3
            if keep or is_heading(p) or t.endswith(':') or t in ('Контакты сторон',):
                q = set_ppr_flag(q, 'keepNext')
            keep = max(0, keep - 1)
            if q != p:
                out = out.replace(p, q, 1)
        parts[i] = out
    return ''.join(parts)


def remove_signatures(d):
    """Подписи ставятся в ЭДО: убираем строки и таблицы для ручных подписей и лишние пустые строки на их месте."""
    def tbl(m):
        t = m.group(0)
        txt = text(t)
        if 'Услуги оказаны:' in txt or ('Подпись:' in txt and 'Телефон' not in txt):
            return ''
        if 'Телефон' in txt and 'ЗАКАЗЧИК:' in txt:  # контакты: убираем хвост «ЗАКАЗЧИК: / Подпись / М.П.»
            rows = re.findall(r'<w:tr[ >].*?</w:tr>', t, flags=re.S)
            k = next(i for i, r in enumerate(rows) if 'ЗАКАЗЧИК:' in text(r))
            for r in rows[k:]:
                t = t.replace(r, '', 1)
        return t
    d = re.sub(r'<w:tbl>.*?</w:tbl>', tbl, d, flags=re.S)
    d = re.sub(r'<w:p [^>]*>(?:(?!</w:p>).)*</w:p>', lambda m: '' if text(m.group(0)).startswith('Исполнитель: ___') else m.group(0), d, flags=re.S)
    # несколько пустых строк подряд (были отступом перед подписями) → одна
    def is_empty(p):
        return not text(p) and not re.search(r'<w:drawing|<w:sectPr|<w:br w:type="page"|<w:fldChar', p)
    parts = re.split(r'(<w:p [^>]*>(?:(?!</w:p>).)*</w:p>)', d, flags=re.S)
    out, prev_empty = [], False
    for i, part in enumerate(parts):
        if i % 2 == 1:
            e = is_empty(part)
            if e and prev_empty and not out[-1].strip():
                continue
            prev_empty = e
        elif part.strip():
            prev_empty = False
        out.append(part)
    d = ''.join(out)
    # пустая строка перед концом документа или перед «Приложение №» не нужна
    return d


def doc_xml(d, v):
    d = remove_signatures(d)
    d = keep_tables(d)
    d = layout(d)
    if v == 'brand':
        d = head_cells(d, YEL, BRN)
        d = d.replace('w:fill="F5F5F5"', 'w:fill="FFF8D1"')
        d = re.sub(r'w:color="(D9D9D9|BFBFBF)"', 'w:color="E6D27A"', d)

        def h(p):
            if not is_heading(p):
                return p
            p = recolor_runs(p, BRN)
            return add_ppr(p, f'<w:pBdr><w:bottom w:val="single" w:sz="18" w:space="4" w:color="{YEL}"/></w:pBdr>')
        d = body_paragraphs(d, h)
    elif v == 'strict':
        d = re.sub(r'w:fill="(FCEFC7|FAE2D5)"', 'w:fill="EDEDED"', d)
        d = d.replace('w:fill="F5F5F5"', 'w:fill="FFFFFF"')
        d = re.sub(r'w:color="(D9D9D9|BFBFBF)"', 'w:color="000000"', d)
    elif v == 'modern':
        d = head_cells(d, BRN, 'FFFFFF')
        d = d.replace('w:fill="F5F5F5"', 'w:fill="F6F3EF"')
        d = re.sub(r'w:color="(D9D9D9|BFBFBF)"', 'w:color="E2DCD5"', d)

        def h(p):
            if not is_heading(p):
                return p
            t = text(p)
            if re.match(r'^\d+\.', t):  # разделы договора: слева, с жёлтой планкой
                p = recolor_runs(p, BRN, size='24')
                return add_ppr(p, f'<w:pBdr><w:left w:val="single" w:sz="36" w:space="8" w:color="{YEL}"/></w:pBdr>', drop_jc=True)
            return recolor_runs(p, BRN)
        d = body_paragraphs(d, h)
    return d


def header_xml(x, v):
    if v == 'brand':
        x = x.replace('w:sz="6" w:space="4" w:color="F2C94C"', f'w:sz="24" w:space="4" w:color="{YEL}"')
    elif v == 'strict':
        x = re.sub(r'<w:r>(?:(?!</w:r>).)*<w:drawing>.*?</w:r>', '', x, flags=re.S)
        x = x.replace('w:sz="6" w:space="4" w:color="F2C94C"', 'w:sz="4" w:space="4" w:color="000000"')
    elif v == 'modern':
        x = x.replace('w:sz="6" w:space="4" w:color="F2C94C"', f'w:sz="12" w:space="6" w:color="{BRN}"')
    return x


def footer_xml(x, v):
    if v == 'strict':
        x = x.replace('8C8C8C', '000000')
        x = re.sub(r'w:color="(F2C94C|FFD900)"', 'w:color="000000"', x)
    elif v == 'brand':
        x = x.replace('w:color="F2C94C"', f'w:color="{YEL}"')
    elif v == 'modern':
        x = x.replace('w:color="F2C94C"', f'w:color="{BRN}"')
    return x


def apply(src, dst, v):
    zin = zipfile.ZipFile(src)
    zout = zipfile.ZipFile(dst, 'w', zipfile.ZIP_DEFLATED)
    for it in zin.infolist():
        b = zin.read(it.filename)
        n = it.filename
        if n.startswith('word/') and n.endswith('.xml') and (v != 'classic' or n == 'word/document.xml'):
            x = set_font(b.decode('utf-8'), FONTS[v]) if re.match(r'word/(document|styles|header\d*|footer\d*|footnotes|endnotes|numbering)\.xml', n) else b.decode('utf-8')
            if n == 'word/styles.xml' and 'AppendixStart' not in x:
                base = re.search(r'<w:style w:type="paragraph" w:default="1" w:styleId="([^"]+)"', x).group(1)
                x = x.replace('</w:styles>', '<w:style w:type="paragraph" w:customStyle="1" w:styleId="AppendixStart">'
                              '<w:name w:val="Приложение с новой страницы"/><w:basedOn w:val="' + base + '"/>'
                              '<w:pPr><w:keepNext/><w:pageBreakBefore/></w:pPr></w:style></w:styles>')
            if n == 'word/document.xml':
                x = doc_xml(x, v)
            elif n.startswith('word/header'):
                x = header_xml(x, v)
            elif n.startswith('word/footer'):
                x = footer_xml(x, v)
            b = x.encode('utf-8')
        zout.writestr(it, b)
    zout.close()


if __name__ == '__main__':
    apply(*sys.argv[1:4])
