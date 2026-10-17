<?php
// Закрытая страница agregatory.pro/dokumenty: бухгалтер готовит договор с рестораном.
//
// Договор собирает браузер из зашифрованного шаблона (api/docs/*.docx.enc,
// ключ = пароль страницы). Здесь только то, что нужно делать на сервере:
//   POST ?a=login              проверка пароля
//   GET  ?a=lookup&inn=…       реквизиты по ИНН: DaData (с адресом), если задан ключ, иначе ЕГРЮЛ/ЕГРИП
//   GET  ?a=addr&q=…           подсказки адреса (DaData)
//   GET  ?a=bank&bik=…         банк и корр. счёт по БИК (справочник ЦБ из выкладки, запасной путь DaData)
//   GET  ?a=nextnum&prefix=…   следующий свободный номер договора: МОЛ01, МОЛ02…
//   GET  ?a=journal            последние договоры из журнала
//   GET  ?a=check&num=…        есть ли уже договор с таким номером
//   POST ?a=save               сохранить договор, записать в журнал, отправить Юрию в Telegram
// Пароль приходит в заголовке X-Docs-Key. В репозитории лежит только его хеш.

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');

// Кабинеты: у каждого свой пин-код (в репозитории только хеши).
// all = видит весь журнал; tpl = папка шаблонов, зашифрованных этим пин-кодом.
const DOCS_USERS = [
    ['id' => 'alisa', 'name' => 'Алиса', 'all' => true, 'tpl' => '',
     'hash' => '$2y$11$rQ8HzcREARElUzMfyCkzu.bwIRFuHjR9Nq1.Bm3c.kVXN1m4bVWG6'],
    ['id' => 'lilia', 'name' => 'Лиля', 'all' => false, 'tpl' => 'lilia/',
     'hash' => '$2y$11$DPM0rs5z2LhztLOtdcEI/.aI87ysjNcKp9jafo/xCruOCOMzzYq7y'],
];
$USER = null;

function out($code, $data) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

$dataDir = __DIR__ . '/data';
$docsDir = $dataDir . '/docs';
if (!is_dir($docsDir)) @mkdir($docsDir, 0750, true);
if (!is_file($dataDir . '/.htaccess')) @file_put_contents($dataDir . '/.htaccess', "Require all denied\nDeny from all\n");

// --- Защита от подбора пин-кода: 5 ошибок за час с одного адреса ----------
function client_ip() {
    $ip = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['HTTP_X_REAL_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '');
    return substr(trim(explode(',', $ip)[0]), 0, 64);
}
function fails_file() { return __DIR__ . '/data/docs/fails.json'; }
function too_many_fails() {
    $f = @json_decode((string)@file_get_contents(fails_file()), true) ?: [];
    $ip = client_ip();
    $list = array_filter($f[$ip] ?? [], fn($t) => $t > time() - 3600);
    return count($list) >= 5;
}
function note_fail() {
    $f = @json_decode((string)@file_get_contents(fails_file()), true) ?: [];
    $ip = client_ip();
    $f[$ip] = array_values(array_filter($f[$ip] ?? [], fn($t) => $t > time() - 3600));
    $f[$ip][] = time();
    @file_put_contents(fails_file(), json_encode($f), LOCK_EX);
}
function auth() {
    if (too_many_fails()) out(429, ['error' => 'Слишком много попыток. Попробуйте через час.']);
    $key = rawurldecode((string)($_SERVER['HTTP_X_DOCS_KEY'] ?? ''));
    global $USER;
    foreach (DOCS_USERS as $u) {
        if ($key !== '' && password_verify($key, $u['hash'])) { $USER = $u; return; }
    }
    note_fail();
    out(401, ['error' => 'Неверный пин-код']);
}

function cfg() {
    $c = [];
    if (is_file(__DIR__ . '/config.php')) {
        $l = include __DIR__ . '/config.php';
        if (is_array($l)) $c = $l;
    }
    return $c;
}

$a = (string)($_GET['a'] ?? '');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

function dadata_key() { return trim((string)(cfg()['dadata_key'] ?? '')); }
function dadata($path, $body) {
    $key = dadata_key();
    if ($key === '' || !function_exists('curl_init')) return null;
    $ch = curl_init('https://suggestions.dadata.ru/suggestions/api/4_1/rs/' . $path);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', 'Authorization: Token ' . $key],
        CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE)]);
    $r = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code === 200 ? json_decode((string)$r, true) : null;
}

if ($a === 'login' && $method === 'POST') {
    auth();
    out(200, ['ok' => true, 'dadata' => dadata_key() !== '',
        'user' => ['id' => $USER['id'], 'name' => $USER['name'], 'all' => $USER['all'], 'tpl' => $USER['tpl']]]);
}

// --- Банк по БИК (открытые данные ЦБ, без пароля) ---------------------------
// Справочник БИК хостинг сам качает с cbr.ru раз в сутки и хранит в api/data/docs/bik.json.
function bik_book() {
    $cache = __DIR__ . '/data/docs/bik.json';
    $fresh = is_file($cache) && filemtime($cache) > time() - 86400;
    if (!$fresh && function_exists('curl_init') && class_exists('ZipArchive')) {
        $ch = curl_init('https://www.cbr.ru/s/newbik');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 25,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; agregatory.pro)']);
        $zipData = curl_exec($ch);
        $ok = curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200 && $zipData;
        curl_close($ch);
        if ($ok) {
            $tmp = tempnam(sys_get_temp_dir(), 'bik');
            file_put_contents($tmp, $zipData);
            $z = new ZipArchive();
            if ($z->open($tmp) === true) {
                for ($i = 0; $i < $z->numFiles; $i++) {
                    if (preg_match('/\.xml$/i', $z->getNameIndex($i))) { $xml = $z->getFromIndex($i); break; }
                }
                $z->close();
            }
            @unlink($tmp);
            $book = isset($xml) ? bik_parse($xml) : [];
            if (count($book) > 300) @file_put_contents($cache, json_encode($book, JSON_UNESCAPED_UNICODE), LOCK_EX);
            elseif (is_file($cache)) @touch($cache); // ЦБ отдал странное: живём на старой копии ещё сутки
        }
    }
    return @json_decode((string)@file_get_contents($cache), true) ?: [];
}
function bik_parse($xml) {
    $out = [];
    $r = new XMLReader();
    if (!$r->XML($xml)) return $out;
    $bik = null;
    while (@$r->read()) {
        if ($r->nodeType !== XMLReader::ELEMENT) continue;
        if ($r->localName === 'BICDirectoryEntry') $bik = $r->getAttribute('BIC');
        elseif ($bik && $r->localName === 'ParticipantInfo') $out[$bik] = [(string)$r->getAttribute('NameP'), $out[$bik][1] ?? ''];
        elseif ($bik && $r->localName === 'Accounts' && $r->getAttribute('RegulationAccountType') === 'CRSA' && empty($out[$bik][1]))
            $out[$bik] = [$out[$bik][0] ?? '', (string)$r->getAttribute('Account')];
    }
    return array_filter($out, fn($v) => $v[0] !== '');
}
if ($a === 'bank') {
    $bik = preg_replace('/\D/', '', (string)($_GET['bik'] ?? ''));
    if (strlen($bik) !== 9) out(400, ['error' => 'В БИК 9 цифр']);
    $book = bik_book();
    if (isset($book[$bik])) out(200, ['ok' => true, 'name' => $book[$bik][0], 'ks' => $book[$bik][1], 'source' => 'cbr']);
    $dd = dadata('findById/bank', ['query' => $bik, 'count' => 1]);
    $x = $dd['suggestions'][0]['data'] ?? null;
    if ($x) out(200, ['ok' => true, 'name' => (string)($x['name']['payment'] ?? $dd['suggestions'][0]['value'] ?? ''),
        'ks' => (string)($x['correspondent_account'] ?? ''), 'source' => 'dadata']);
    out(404, ['error' => $book ? 'Такого БИК нет в справочнике Банка России. Проверьте цифры.' : 'Справочник БИК сейчас недоступен: впишите банк и корр. счёт вручную.']);
}

auth();

// --- Реквизиты по ИНН -------------------------------------------------------
if ($a === 'lookup') {
    $inn = preg_replace('/\D/', '', (string)($_GET['inn'] ?? ''));
    if (!in_array(strlen($inn), [10, 12], true)) out(400, ['error' => 'ИНН: 10 цифр у организации, 12 у ИП']);
    // DaData: отдаёт и юридический адрес; если ключа нет или сервис не ответил, идём в налоговую
    $dd = dadata('findById/party', ['query' => $inn, 'count' => 1]);
    $x = $dd['suggestions'][0] ?? null;
    if ($x) {
        $d = $x['data'] ?? [];
        $isIp = ($d['type'] ?? '') === 'INDIVIDUAL';
        $m = $d['management'] ?? [];
        out(200, ['ok' => true, 'source' => 'dadata', 'row' => [
            'kind' => $isIp ? 'ip' : 'ooo',
            'full' => (string)($isIp ? ($d['name']['full'] ?? '') : ($d['name']['full_with_opf'] ?? $x['value'] ?? '')),
            'short' => (string)($d['name']['short_with_opf'] ?? $x['value'] ?? ''),
            'ogrn' => (string)($d['ogrn'] ?? ''),
            'inn' => (string)($d['inn'] ?? $inn),
            'kpp' => (string)($d['kpp'] ?? ''),
            'address' => (string)($d['address']['unrestricted_value'] ?? $d['address']['value'] ?? ''),
            'head' => !empty($m['name']) ? mb_strtoupper((string)($m['post'] ?? 'ГЕНЕРАЛЬНЫЙ ДИРЕКТОР')) . ': ' . $m['name'] : '',
            'closed' => !in_array($d['state']['status'] ?? 'ACTIVE', ['ACTIVE', 'REORGANIZING'], true),
        ]]);
    }
    $ua = 'Mozilla/5.0 (compatible; agregatory.pro)';
    $ch = curl_init('https://egrul.nalog.ru/');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query(['query' => $inn]),
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_USERAGENT => $ua]);
    $r = json_decode((string)curl_exec($ch), true);
    curl_close($ch);
    $t = $r['t'] ?? '';
    if ($t === '') out(502, ['error' => 'Налоговая не ответила, заполните вручную']);
    $rows = [];
    for ($i = 0; $i < 6 && !$rows; $i++) {
        usleep($i ? 700000 : 300000);
        $ch = curl_init('https://egrul.nalog.ru/search-result/' . rawurlencode($t) . '?r=' . (int)(microtime(true) * 1000));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_USERAGENT => $ua]);
        $res = json_decode((string)curl_exec($ch), true);
        curl_close($ch);
        $rows = $res['rows'] ?? [];
    }
    if (!$rows) out(404, ['error' => 'По этому ИНН ничего не найдено']);
    $x = $rows[0];
    out(200, ['ok' => true, 'row' => [
        'kind' => ($x['k'] ?? '') === 'fl' ? 'ip' : 'ooo',
        'full' => (string)($x['n'] ?? ''),
        'short' => (string)($x['c'] ?? ''),
        'ogrn' => (string)($x['o'] ?? ''),
        'inn' => (string)($x['i'] ?? $inn),
        'kpp' => (string)($x['p'] ?? ''),
        'address' => (string)($x['a'] ?? ''),
        'head' => (string)($x['g'] ?? ''),
        'closed' => !empty($x['e']),
    ]]);
}

// --- Подсказки адреса --------------------------------------------------------
if ($a === 'addr') {
    $q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 200);
    if (mb_strlen($q) < 3) out(200, ['items' => []]);
    $dd = dadata('suggest/address', ['query' => $q, 'count' => 7]);
    if ($dd === null) out(200, ['items' => [], 'off' => dadata_key() === '']);
    $items = array_map(function ($x) {
        $d = $x['data'] ?? [];
        return [
            'value' => (string)($x['value'] ?? ''),
            'full' => (string)($x['unrestricted_value'] ?? $x['value'] ?? ''),
            'city' => (string)($d['city'] ?? $d['settlement'] ?? $d['region'] ?? ''),
            'city_part' => (string)($d['city_with_type'] ?? $d['settlement_with_type'] ?? ''),
        ];
    }, $dd['suggestions'] ?? []);
    out(200, ['items' => $items]);
}

// --- Журнал -----------------------------------------------------------------
$journal = $docsDir . '/journal.json';
function journal_read($f) { return @json_decode((string)@file_get_contents($f), true) ?: []; }
// Лиля видит только свои договоры, Алиса и Юрий весь журнал
function visible($r) { global $USER; return $USER['all'] || ($r['user'] ?? '') === $USER['id']; }

if ($a === 'journal') {
    $j = journal_read($journal);
    $items = [];
    foreach ($j as $i => $r) {
        if (!visible($r)) continue;
        $r['id'] = $i;
        unset($r['form']);
        $r['has_form'] = true;
        $items[] = $r;
    }
    out(200, ['items' => array_slice(array_reverse($items), 0, 50)]);
}

// Данные формы из истории: «заполнить из этого договора»
if ($a === 'form') {
    $j = journal_read($journal);
    $i = (int)($_GET['id'] ?? -1);
    if (!isset($j[$i]['form']) || !visible($j[$i])) out(404, ['error' => 'Данных формы нет']);
    out(200, ['form' => $j[$i]['form']]);
}

// Word-файл из истории
if ($a === 'file') {
    $j = journal_read($journal);
    $i = (int)($_GET['id'] ?? -1);
    if (!isset($j[$i]) || !visible($j[$i])) out(404, ['error' => 'Файл не найден']);
    $name = basename((string)($j[$i]['file'] ?? ''));
    $path = $docsDir . '/' . $name;
    if ($name === '' || !is_file($path)) out(404, ['error' => 'Файл не найден']);
    header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

// Следующий свободный номер: три буквы названия первой точки + порядковый номер (МОЛ01, МОЛ02…)
if ($a === 'nextnum') {
    $prefix = mb_strtoupper(preg_replace('/[^\p{L}]/u', '', (string)($_GET['prefix'] ?? '')));
    $prefix = mb_substr($prefix, 0, 3);
    if ($prefix === '') out(400, ['error' => 'Нет букв в названии']);
    $max = 0;
    foreach (journal_read($journal) as $r) {
        if (preg_match('/^' . preg_quote($prefix, '/') . '(\d+)$/iu', trim((string)$r['num']), $mm)) $max = max($max, (int)$mm[1]);
    }
    out(200, ['num' => $prefix . str_pad((string)($max + 1), 2, '0', STR_PAD_LEFT)]);
}

if ($a === 'check') {
    $num = trim((string)($_GET['num'] ?? ''));
    $found = array_values(array_filter(journal_read($journal), fn($r) => mb_strtolower($r['num']) === mb_strtolower($num)));
    $mine = array_values(array_filter($found, 'visible'));
    if ($found && !$mine) out(200, ['exists' => true, 'other' => true, 'versions' => count($found)]);
    $last = $mine ? end($mine) : null;
    if ($last) unset($last['form']);
    out(200, ['exists' => (bool)$found, 'versions' => count($found), 'item' => $last]);
}

// --- Сохранение договора ----------------------------------------------------
if ($a === 'save' && $method === 'POST') {
    $meta = json_decode((string)($_POST['meta'] ?? '{}'), true) ?: [];
    $file = $_FILES['docx'] ?? null;
    if (!$file || $file['error'] !== UPLOAD_ERR_OK || $file['size'] > 5_000_000) out(400, ['error' => 'Нет файла договора']);
    $num = mb_substr(trim((string)($meta['num'] ?? '')), 0, 60);
    $client = mb_substr(trim((string)($meta['client'] ?? '')), 0, 200);
    if ($num === '' || $client === '') out(400, ['error' => 'Нет номера или заказчика']);

    $safe = preg_replace('/[^\w\-]+/u', '_', $num);
    $name = date('Ymd-His') . '_' . $safe . '.docx';
    if (!move_uploaded_file($file['tmp_name'], $docsDir . '/' . $name)) out(500, ['error' => 'Не удалось сохранить']);

    $fh = fopen($journal, 'c+');
    flock($fh, LOCK_EX);
    $j = json_decode(stream_get_contents($fh) ?: '[]', true) ?: [];
    $version = 1 + count(array_filter($j, fn($r) => mb_strtolower($r['num']) === mb_strtolower($num)));
    $form = $meta['form'] ?? null;
    $j[] = [
        'num' => $num, 'version' => $version, 'client' => $client,
        'executor' => mb_substr((string)($meta['executor'] ?? ''), 0, 80),
        'author' => $USER['all'] ? mb_substr(trim((string)($meta['author'] ?? $USER['name'])), 0, 40) : $USER['name'],
        'user' => $USER['id'],
        'doc' => mb_substr((string)($meta['doc'] ?? ''), 0, 20),
        'form' => is_array($form) ? $form : null, 'inn' => mb_substr((string)($meta['inn'] ?? ''), 0, 12),
        'total' => (int)($meta['total'] ?? 0), 'points' => (int)($meta['points'] ?? 0),
        'date' => mb_substr((string)($meta['date'] ?? ''), 0, 40), 'file' => $name,
        'created' => (new DateTime('now', new DateTimeZone('Europe/Moscow')))->format('d.m.Y H:i'),
    ];
    ftruncate($fh, 0); rewind($fh);
    fwrite($fh, json_encode($j, JSON_UNESCAPED_UNICODE));
    flock($fh, LOCK_UN); fclose($fh);

    // Копия Юрию в личный Telegram (первый чат из настроек, без рабочей группы)
    $c = cfg();
    $token = trim((string)($c['tg_token'] ?? ''));
    $chat = trim(explode(',', (string)($c['tg_chat'] ?? ''))[0] ?? '');
    $sent = false;
    if ($token !== '' && $chat !== '' && function_exists('curl_init')) {
        $e = fn($s) => htmlspecialchars((string)$s, ENT_NOQUOTES, 'UTF-8');
        $cap = "<b>Договор № {$e($num)}</b>" . ($version > 1 ? " (версия {$version})" : '') . "\n{$e($client)}, ИНН {$e($meta['inn'] ?? '')}\nИсполнитель: {$e($meta['executor'] ?? '')}\nОформил(а): {$e($meta['author'] ?? '')}\n"
            . (($meta['doc'] ?? '') === 'проект'
            ? 'Консалтинговый проект: ' . number_format((int)($meta['total'] ?? 0), 0, ',', ' ') . ' ₽'
            : 'Точек: ' . (int)($meta['points'] ?? 0) . ', в месяц: ' . number_format((int)($meta['total'] ?? 0), 0, ',', ' ') . ' ₽');
        $ch = curl_init("https://api.telegram.org/bot{$token}/sendDocument");
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
            CURLOPT_POSTFIELDS => ['chat_id' => $chat, 'caption' => $cap, 'parse_mode' => 'HTML',
                'document' => new CURLFile($docsDir . '/' . $name,
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                    'Договор_' . $safe . '.docx')]]);
        curl_exec($ch);
        $sent = curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200;
        curl_close($ch);
    }
    out(200, ['ok' => true, 'telegram' => $sent, 'version' => $version]);
}

out(400, ['error' => 'Неизвестное действие']);
