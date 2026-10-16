<?php
// Закрытая страница agregatory.pro/dokumenty: бухгалтер готовит договор с рестораном.
//
// Договор собирает браузер из зашифрованного шаблона (api/docs/*.docx.enc,
// ключ = пароль страницы). Здесь только то, что нужно делать на сервере:
//   POST ?a=login              проверка пароля
//   GET  ?a=lookup&inn=…       реквизиты по ИНН из ЕГРЮЛ/ЕГРИП (egrul.nalog.ru)
//   GET  ?a=journal            последние договоры из журнала
//   GET  ?a=check&num=…        есть ли уже договор с таким номером
//   POST ?a=save               сохранить договор, записать в журнал, отправить Юрию в Telegram
// Пароль приходит в заголовке X-Docs-Key. В репозитории лежит только его хеш.

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');

const DOCS_HASH = '$2y$11$rQ8HzcREARElUzMfyCkzu.bwIRFuHjR9Nq1.Bm3c.kVXN1m4bVWG6';

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
    if ($key === '' || !password_verify($key, DOCS_HASH)) {
        note_fail();
        out(401, ['error' => 'Неверный пин-код']);
    }
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

if ($a === 'login' && $method === 'POST') { auth(); out(200, ['ok' => true]); }

auth();

// --- Реквизиты по ИНН -------------------------------------------------------
if ($a === 'lookup') {
    $inn = preg_replace('/\D/', '', (string)($_GET['inn'] ?? ''));
    if (!in_array(strlen($inn), [10, 12], true)) out(400, ['error' => 'ИНН: 10 цифр у организации, 12 у ИП']);
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

// --- Журнал -----------------------------------------------------------------
$journal = $docsDir . '/journal.json';
function journal_read($f) { return @json_decode((string)@file_get_contents($f), true) ?: []; }

if ($a === 'journal') {
    $j = journal_read($journal);
    $items = array_map(function ($r, $i) {
        $r['id'] = $i;
        unset($r['form']);
        $r['has_form'] = true;
        return $r;
    }, $j, array_keys($j));
    out(200, ['items' => array_slice(array_reverse($items), 0, 50)]);
}

// Данные формы из истории: «заполнить из этого договора»
if ($a === 'form') {
    $j = journal_read($journal);
    $i = (int)($_GET['id'] ?? -1);
    if (!isset($j[$i]['form'])) out(404, ['error' => 'Данных формы нет']);
    out(200, ['form' => $j[$i]['form']]);
}

// Word-файл из истории
if ($a === 'file') {
    $j = journal_read($journal);
    $i = (int)($_GET['id'] ?? -1);
    $name = basename((string)($j[$i]['file'] ?? ''));
    $path = $docsDir . '/' . $name;
    if ($name === '' || !is_file($path)) out(404, ['error' => 'Файл не найден']);
    header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

if ($a === 'check') {
    $num = trim((string)($_GET['num'] ?? ''));
    $found = array_values(array_filter(journal_read($journal), fn($r) => mb_strtolower($r['num']) === mb_strtolower($num)));
    $last = $found ? end($found) : null;
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
        $cap = "<b>Договор № {$e($num)}</b>" . ($version > 1 ? " (версия {$version})" : '') . "\n{$e($client)}, ИНН {$e($meta['inn'] ?? '')}\nИсполнитель: {$e($meta['executor'] ?? '')}\n"
            . 'Точек: ' . (int)($meta['points'] ?? 0) . ', в месяц: ' . number_format((int)($meta['total'] ?? 0), 0, ',', ' ') . ' ₽';
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
