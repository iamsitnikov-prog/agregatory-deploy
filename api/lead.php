<?php
// Приём заявок с формы agregatory.pro на хостинге reg.ru.
//
// Что делает с каждой заявкой:
//   1. Записывает в журнал на хостинге (api/data/leads.csv, снаружи закрыт).
//   2. Шлёт в Telegram, если в секретах выкладки заданы TG_BOT_TOKEN и TG_CHAT_ID
//      (несколько чатов через запятую).
//   3. Шлёт на почту (Яндекс и Gmail, адреса ниже; секрет LEAD_EMAIL_TO важнее).
//   4. Если ни Telegram, ни почта не сработали — пересылает
//      заявку в старую функцию poehali.dev, чтобы заявка дошла привычным путём.
//
// Настройки приходят из api/config.php, который выкладка собирает из секретов
// GitHub. В репозитории токенов нет.

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function reply($code, $data) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    reply(405, ['error' => 'Method not allowed', 'service' => 'lead']);
}

$cfg = [];
if (is_file(__DIR__ . '/config.php')) {
    $loaded = include __DIR__ . '/config.php';
    if (is_array($loaded)) $cfg = $loaded;
}
$POEHALI_URL = $cfg['backup_url'] ?? 'https://functions.poehali.dev/3df83e5c-d49d-4a84-8862-107cedfd17f0';
// Почта для заявок по умолчанию (закодирована, чтобы адрес не собирали роботы
// из открытого репозитория). Секрет LEAD_EMAIL_TO, если задан, важнее.
if (trim((string)($cfg['email_to'] ?? '')) === '') {
    // Яндекс и Gmail Юрия + копия Алисе: с 8.10 у Яндекс Почты сбой, Gmail получает письма с хостинга (проверено 11.10).
    $cfg['email_to'] = base64_decode('aS5hbS5zaXRuaWtvdkB5YW5kZXgucnU=') . ', ' . base64_decode('aS5hbS5zaXRuaWtvdkBnbWFpbC5jb20=')
        . ', ' . base64_decode('YWxpc2EubWFsdXl0YUB5YW5kZXgucnU='); // копия Алисе
}

$raw = file_get_contents('php://input');
if (strlen($raw) > 20000) reply(413, ['error' => 'too large']);
$body = json_decode($raw ?: '{}', true);
if (!is_array($body)) $body = [];

$f = function ($k, $max = 1000) use ($body) {
    $v = isset($body[$k]) ? trim((string)$body[$k]) : '';
    return mb_substr($v, 0, $max);
};
$name = $f('name', 200);
$phone = $f('phone', 50);
$place = $f('place', 300);
$status = $f('status', 100);
$channel = $f('channel', 50);
$comment = $f('comment', 3000);
$page = $f('page', 300);
$trap = $f('website', 200);

// Скрытое поле-ловушка: человек его не заполняет, робот заполняет.
if ($trap !== '') reply(200, ['ok' => true]);

if ($name === '' || $phone === '') reply(400, ['error' => 'name and phone are required']);

$ip = substr((string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['HTTP_X_REAL_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? ''), 0, 64);
$ip = trim(explode(',', $ip)[0]);

// --- Журнал и защита от потока заявок с одного адреса -----------------------
$dataDir = __DIR__ . '/data';
if (!is_dir($dataDir)) @mkdir($dataDir, 0750, true);
if (!is_file($dataDir . '/.htaccess')) {
    @file_put_contents($dataDir . '/.htaccess', "Require all denied\nDeny from all\n");
}

$rateFile = $dataDir . '/rate.json';
$now = time();
$rate = [];
$fh = @fopen($rateFile, 'c+');
if ($fh) {
    flock($fh, LOCK_EX);
    $rate = json_decode(stream_get_contents($fh) ?: '{}', true) ?: [];
    foreach ($rate as $k => $list) {
        $rate[$k] = array_values(array_filter($list, fn($t) => $t > $now - 600));
        if (!$rate[$k]) unset($rate[$k]);
    }
    $tooMany = $ip !== '' && count($rate[$ip] ?? []) >= 5;
    if (!$tooMany && $ip !== '') $rate[$ip][] = $now;
    ftruncate($fh, 0); rewind($fh);
    fwrite($fh, json_encode($rate));
    flock($fh, LOCK_UN); fclose($fh);
    if ($tooMany) reply(429, ['error' => 'too many requests']);
}

$saved = false;
$csv = $dataDir . '/leads.csv';
$isNew = !is_file($csv);
$out = @fopen($csv, 'a');
if ($out) {
    flock($out, LOCK_EX);
    if ($isNew) {
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['дата МСК', 'имя', 'телефон', 'заведение', 'услуга', 'связь', 'комментарий', 'страница', 'ip']);
    }
    $dt = new DateTime('now', new DateTimeZone('Europe/Moscow'));
    $saved = fputcsv($out, [$dt->format('Y-m-d H:i:s'), $name, $phone, $place, $status, $channel, $comment, $page, $ip]) !== false;
    flock($out, LOCK_UN); fclose($out);
}

// --- Текст сообщения --------------------------------------------------------
$e = fn($s) => htmlspecialchars($s, ENT_NOQUOTES, 'UTF-8');
$lines = ['<b>Новая заявка с сайта</b>', '', "<b>Имя:</b> {$e($name)}", "<b>Телефон:</b> {$e($phone)}"];
if ($place !== '') $lines[] = "<b>Заведение:</b> {$e($place)}";
if ($status !== '') $lines[] = "<b>Услуга:</b> {$e($status)}";
if ($channel !== '') $lines[] = "<b>Связь:</b> {$e($channel)}";
if ($comment !== '') $lines[] = "<b>Комментарий:</b> {$e($comment)}";
if (strpos($page, 'http') === 0) $lines[] = "<b>Страница:</b> {$e($page)}";
$html = implode("\n", $lines);
$plain = html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8');

// --- Telegram ---------------------------------------------------------------
function tg_send($token, $chat, $text) {
    $ips = ['', '149.154.167.220', '149.154.167.99', '149.154.175.50'];
    foreach ($ips as $ip) {
        $ch = curl_init("https://api.telegram.org/bot{$token}/sendMessage");
        $opts = [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query(['chat_id' => $chat, 'text' => $text, 'parse_mode' => 'HTML']),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 6,
        ];
        if ($ip !== '') $opts[CURLOPT_RESOLVE] = ["api.telegram.org:443:{$ip}"];
        curl_setopt_array($ch, $opts);
        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code === 200) return true;
        if ($code >= 400 && $code < 500) return false; // чат недоступен боту
    }
    return false;
}

$tg = false;
$token = trim((string)($cfg['tg_token'] ?? ''));
$chats = array_filter(array_map('trim', explode(',', (string)($cfg['tg_chat'] ?? ''))));
if ($token !== '' && $chats && function_exists('curl_init')) {
    foreach ($chats as $chat) {
        if (tg_send($token, $chat, $html)) $tg = true;
    }
}

// --- Почта ------------------------------------------------------------------
$mailed = false;
$to = trim((string)($cfg['email_to'] ?? ''));
if ($to !== '') {
    $from = "info@agregatory.pro"; // так задано в sendmail_path хостинга reg.ru
    $fromName = '=?UTF-8?B?' . base64_encode('Сайт agregatory.pro') . '?=';
    $headers = "From: {$fromName} <{$from}>\r\n"
        . "Reply-To: {$from}\r\n"
        . "MIME-Version: 1.0\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: base64\r\n";
    $subject = '=?UTF-8?B?' . base64_encode("Заявка с сайта: {$name}") . '?=';
    $mailed = @mail($to, $subject, chunk_split(base64_encode($plain)), $headers, "-f{$from}");
}

// --- Запасной путь: старая функция poehali.dev ------------------------------
// Только если ни Telegram, ни почта не сработали — чтобы заявки не дублировались.
// Письма с хостинга (отправитель info@agregatory.pro) доходят: проверено 11.10 на Gmail.
$poehali = false;
if (!$tg && !$mailed && function_exists('curl_init')) {
    $ch = curl_init($POEHALI_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            'name' => $name, 'phone' => $phone, 'place' => $place, 'status' => $status,
            'channel' => $channel, 'comment' => $comment, 'page' => $page,
        ], JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Origin: https://agregatory.pro'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT => 10,
    ]);
    curl_exec($ch);
    $poehali = curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200;
    curl_close($ch);
}

if (!$saved && !$tg && !$mailed && !$poehali) {
    error_log('LEAD LOST: ' . $plain);
    reply(502, ['ok' => false, 'error' => 'delivery failed']);
}

reply(200, ['ok' => true, 'telegram' => $tg, 'email' => $mailed, 'backup' => $poehali, 'saved' => $saved]);
