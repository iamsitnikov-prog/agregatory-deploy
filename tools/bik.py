"""Справочник БИК Банка России для страницы /dokumenty.

Качает свежий справочник (https://www.cbr.ru/s/newbik, архив с XML ED807)
и пишет компактный JSON: {"044030653": ["СЕВЕРО-ЗАПАДНЫЙ БАНК ПАО СБЕРБАНК", "30101810500000000653"], ...}

  python3 tools/bik.py <выход.json>
Если скачать не вышло, выходит с кодом 1 и ничего не пишет (выкладка берёт прошлую копию из кэша).
"""
import io
import json
import sys
import urllib.request
import zipfile
import xml.etree.ElementTree as ET

URL = 'https://www.cbr.ru/s/newbik'


def local(tag):
    return tag.rsplit('}', 1)[-1]


def parse(xml_bytes):
    root = ET.fromstring(xml_bytes)
    out = {}
    for e in root.iter():
        if local(e.tag) != 'BICDirectoryEntry':
            continue
        bik = e.get('BIC', '')
        name, ks = '', ''
        for c in e:
            t = local(c.tag)
            if t == 'ParticipantInfo':
                name = c.get('NameP', '')
            elif t == 'Accounts' and c.get('RegulationAccountType') == 'CRSA' and not ks:
                ks = c.get('Account', '')
        if len(bik) == 9 and name:
            out[bik] = [name.strip(), ks]
    return out


def main():
    dst = sys.argv[1]
    req = urllib.request.Request(URL, headers={'User-Agent': 'Mozilla/5.0 (agregatory.pro)'})
    data = urllib.request.urlopen(req, timeout=60).read()
    z = zipfile.ZipFile(io.BytesIO(data))
    xml_name = next(n for n in z.namelist() if n.lower().endswith('.xml'))
    book = parse(z.read(xml_name))
    check = book.get('044030653', ['', ''])
    if len(book) < 300 or check[1] != '30101810500000000653':
        raise SystemExit(f'справочник странный: {len(book)} записей, проверка {check}')
    json.dump(book, open(dst, 'w', encoding='utf-8'), ensure_ascii=False, separators=(',', ':'))
    print(f'БИК: {len(book)} записей, 044030653 = {check[0]}')


if __name__ == '__main__':
    main()
