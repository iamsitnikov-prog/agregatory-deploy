<?php
// Еженедельная выгрузка заявок в Telegram (вызывает GitHub Actions, workflow report.yml).
// Ключ доступа не хранится в репозитории: это sha256 от токена бота + 'leads-report',
// его знают только хостинг (config.php) и секреты GitHub.
// Файл уходит только в первый чат из TG_CHAT_ID (личный чат Юрия), не в группу.

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function out($code, $data) { http_response_code($code); echo json_encode($data, JSON_UNESCAPED_UNICODE); exit; }

$cfg = is_file(__DIR__ . '/config.php') ? (include __DIR__ . '/config.php') : [];
$token = trim((string)($cfg['tg_token'] ?? ''));
if ($token === '') out(404, ['error' => 'not found']);
$key = hash('sha256', $token . 'leads-report');
if (!hash_equals($key, (string)($_POST['k'] ?? $_GET['k'] ?? ''))) out(404, ['error' => 'not found']);

$chat = trim(explode(',', (string)($cfg['tg_chat'] ?? ''))[0] ?? '');
if ($chat === '') out(500, ['error' => 'no chat']);

$csv = __DIR__ . '/data/leads.csv';
$rows = [];
if (is_readable($csv) && ($fh = fopen($csv, 'r'))) {
    $first = true;
    while (($r = fgetcsv($fh)) !== false) {
        if ($first) { $first = false; continue; } // заголовок
        if (count($r) < 3) continue;
        $name = (string)($r[1] ?? '');
        $comment = (string)($r[6] ?? '');
        // тестовые заявки Claude при настройке 11.10.2026 не считаем
        if (stripos($name, 'ТЕСТ Claude') === 0 || mb_stripos($comment, 'от Claude') !== false) continue;
        $rows[] = $r;
    }
    fclose($fh);
}

// Если работает раздел «Заявки» (CRM), берём заявки оттуда: со статусом и ответственной
$crmRows = null;
if (is_file(__DIR__ . '/crm-lib.php')) {
    require_once __DIR__ . '/crm-lib.php';
    $db = crm_read();
    if (is_array($db)) {
        $names = ['alisa' => 'Алиса', 'lilia' => 'Лиля', 'yuri' => 'Юрий'];
        $crmRows = [];
        foreach ($db['items'] as $l) {
            $crmRows[] = [$l['created'], $l['name'], $l['phone'], $l['place'], $l['service'], $l['channel'], $l['comment'], $l['page'],
                crm_status_title($l['status']), $names[$l['owner']] ?? '', $l['source']];
        }
        $rows = $crmRows;
    }
}

$tz = new DateTimeZone('Europe/Moscow');
$now = new DateTime('now', $tz);
$weekAgo = (clone $now)->modify('-7 days')->format('Y-m-d H:i:s');
$week = array_values(array_filter($rows, fn($r) => ($r[0] ?? '') >= $weekAgo));

// Файл для Excel: разделитель «;» и BOM, чтобы русский Excel открыл без мастера импорта.
$tmp = tempnam(sys_get_temp_dir(), 'leads');
$o = fopen($tmp, 'w');
fwrite($o, "\xEF\xBB\xBF");
$cols = ['дата МСК', 'имя', 'телефон', 'заведение', 'услуга', 'связь', 'комментарий', 'страница'];
if ($crmRows !== null) $cols = array_merge($cols, ['статус', 'ответственная', 'откуда']);
fputcsv($o, $cols, ';');
foreach (array_reverse($rows) as $r) fputcsv($o, array_slice(array_pad($r, count($cols), ''), 0, count($cols)), ';');
fclose($o);

$caption = "Заявки с сайта agregatory.pro\n"
    . "За последние 7 дней: " . count($week) . "\n"
    . "Всего в базе: " . count($rows) . "\n"
    . ($crmRows !== null ? "По статусам: " . implode(', ', array_filter(array_map(function ($st) use ($rows) {
        $n = count(array_filter($rows, fn($r) => $r[8] === $st['title']));
        return $n ? "{$st['title']} {$n}" : '';
    }, CRM_STATUSES))) . "\n" : '')
    . "Выгрузка на " . $now->format('d.m.Y H:i') . " МСК";

$fileName = 'zayavki_agregatory_' . $now->format('Y-m-d') . '.csv';
$sent = false; $last = '';
foreach (['', '149.154.167.220', '149.154.167.99', '149.154.175.50'] as $ip) {
    $ch = curl_init("https://api.telegram.org/bot{$token}/sendDocument");
    $opts = [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => [
            'chat_id' => $chat,
            'caption' => $caption,
            'document' => new CURLFile($tmp, 'text/csv', $fileName),
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 20,
    ];
    if ($ip !== '') $opts[CURLOPT_RESOLVE] = ["api.telegram.org:443:{$ip}"];
    curl_setopt_array($ch, $opts);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code === 200) { $sent = true; break; }
    $last = "http {$code}";
    if ($code >= 400 && $code < 500) break;
}
@unlink($tmp);

if (!$sent) out(502, ['ok' => false, 'error' => $last]);
out(200, ['ok' => true, 'week' => count($week), 'total' => count($rows)]);
