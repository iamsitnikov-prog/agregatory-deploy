<?php
// Анкета партнёра с agregatory.pro/partneram.
//   1. Сохраняет заявку в api/data/partner-requests.jsonl, логотип в api/data/partner-logos/
//      (папка data снаружи закрыта в .htaccess).
//   2. Шлёт заявку в Telegram (те же чаты, что и заявки клиентов: tg_chat из config.php),
//      логотип отдельным файлом.
//   3. Дублирует на почту (как заявки клиентов).
// Защита: скрытое поле «website» и не больше 3 заявок в час с одного IP.

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function reply($code, $data) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') reply(405, ['error' => 'Method not allowed', 'service' => 'partner']);

$cfg = [];
if (is_file(__DIR__ . '/config.php')) {
    $loaded = include __DIR__ . '/config.php';
    if (is_array($loaded)) $cfg = $loaded;
}

$f = function ($k, $max = 1000) {
    $v = isset($_POST[$k]) ? trim((string)$_POST[$k]) : '';
    $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $v);
    return mb_substr($v, 0, $max);
};

// Ловушка для ботов: делаем вид, что всё хорошо.
if ($f('website', 200) !== '') reply(200, ['ok' => true]);

$d = [
    'name' => $f('name', 120),
    'site' => $f('site', 300),
    'section' => $f('section', 80),
    'section_other' => $f('section_other', 120),
    'geo' => $f('geo', 600),
    'tagline' => $f('tagline', 120),
    'description' => $f('description', 3000),
    'points' => $f('points', 2000),
    'for_whom' => $f('for_whom', 800),
    'promo' => $f('promo', 600),
    'utm_url' => $f('utm_url', 400),
    'contact_name' => $f('contact_name', 120),
    'contact_role' => $f('contact_role', 120),
    'contact_phone' => $f('contact_phone', 60),
    'contact_tg' => $f('contact_tg', 80),
    'contact_email' => $f('contact_email', 160),
    'referral' => $f('referral', 40),
    'consent' => $f('consent', 10),
];
foreach (['name', 'site', 'section', 'geo', 'tagline', 'contact_name'] as $k) {
    if ($d[$k] === '') reply(400, ['error' => 'required', 'field' => $k]);
}
if ($d['contact_tg'] === '') reply(400, ['error' => 'required', 'field' => 'contact_tg']);
if ($d['consent'] !== '1') reply(400, ['error' => 'consent']);

$dir = __DIR__ . '/data';
if (!is_dir($dir)) @mkdir($dir, 0750, true);

// Не больше 3 заявок в час с одного IP.
$ipKey = substr(sha1(($_SERVER['REMOTE_ADDR'] ?? '') . '|partner'), 0, 16);
$rlFile = $dir . '/partner-rate.json';
$rl = @json_decode(@file_get_contents($rlFile) ?: '{}', true);
if (!is_array($rl)) $rl = [];
$now = time();
foreach ($rl as $k => $ts) { $rl[$k] = array_values(array_filter((array)$ts, function ($t) use ($now) { return $t > $now - 3600; })); if (!$rl[$k]) unset($rl[$k]); }
if (count($rl[$ipKey] ?? []) >= 3) reply(429, ['error' => 'too many']);
$rl[$ipKey][] = $now;
@file_put_contents($rlFile, json_encode($rl), LOCK_EX);

// Логотип или фото (необязательно).
$logoPath = '';
$logoName = '';
if (!empty($_FILES['logo']) && ($_FILES['logo']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
    $tmp = $_FILES['logo']['tmp_name'];
    if (filesize($tmp) > 5 * 1024 * 1024) reply(413, ['error' => 'logo too large']);
    $head = file_get_contents($tmp, false, null, 0, 512);
    $ext = '';
    if (strncmp($head, "\x89PNG", 4) === 0) $ext = 'png';
    elseif (strncmp($head, "\xFF\xD8\xFF", 3) === 0) $ext = 'jpg';
    elseif (substr($head, 0, 4) === 'RIFF' && substr($head, 8, 4) === 'WEBP') $ext = 'webp';
    elseif (stripos($head, '<svg') !== false || stripos($head, '<?xml') !== false) $ext = 'svg';
    if ($ext === '') reply(415, ['error' => 'logo type']);
    $ldir = $dir . '/partner-logos';
    if (!is_dir($ldir)) @mkdir($ldir, 0750, true);
    $lat = function_exists('transliterator_transliterate') ? (string)transliterator_transliterate('Any-Latin; Latin-ASCII', $d['name']) : '';
    $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($lat !== '' ? $lat : 'partner'));
    if (trim($slug, '-') === '') $slug = 'partner';
    $logoName = trim($slug, '-') . '-' . date('Ymd-His') . '.' . $ext;
    $logoPath = $ldir . '/' . $logoName;
    if (!move_uploaded_file($tmp, $logoPath)) { $logoPath = ''; $logoName = ''; }
}

$d['logo'] = $logoName;
$d['created'] = date('c');
@file_put_contents($dir . '/partner-requests.jsonl', json_encode($d, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);

// Сообщение.
$e = function ($s) { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); };
$ref = ['yes' => 'да', 'discuss' => 'обсудить', 'no' => 'нет'][$d['referral']] ?? $d['referral'];
$L = [];
$L[] = '🤝 <b>Анкета партнёра с сайта</b>';
$L[] = '';
$L[] = "<b>Название:</b> {$e($d['name'])}";
$L[] = "<b>Сайт:</b> {$e($d['site'])}";
$L[] = "<b>Раздел:</b> {$e($d['section'])}" . ($d['section_other'] !== '' ? " ({$e($d['section_other'])})" : '');
$L[] = "<b>География:</b> {$e($d['geo'])}";
$L[] = "<b>Одной строкой:</b> {$e($d['tagline'])}";
if ($d['description'] !== '') $L[] = "<b>Описание:</b> {$e($d['description'])}";
if ($d['points'] !== '') $L[] = "<b>Что входит:</b>\n{$e($d['points'])}";
if ($d['for_whom'] !== '') $L[] = "<b>Кому подойдёт:</b> {$e($d['for_whom'])}";
if ($d['promo'] !== '') $L[] = "<b>Промокод или предложение:</b> {$e($d['promo'])}";
if ($d['utm_url'] !== '') $L[] = "<b>Ссылка с метками:</b> {$e($d['utm_url'])}";
$L[] = '';
$L[] = '<b>Контакт (не публикуем):</b> ' . $e(trim($d['contact_name'] . ($d['contact_role'] !== '' ? ', ' . $d['contact_role'] : '')));
if ($d['contact_phone'] !== '') $L[] = "Телефон: {$e($d['contact_phone'])}";
$L[] = "Telegram (сюда пишем, когда карточка появится): {$e($d['contact_tg'])}";
if ($d['contact_email'] !== '') $L[] = "Почта: {$e($d['contact_email'])}";
$L[] = "<b>Готовы приводить к нам клиентов:</b> {$e($ref)}";
$L[] = $logoName !== '' ? '<b>Логотип:</b> файл ниже' : '<b>Логотип:</b> нет, на сайте будет знак с первой буквой';
$html = implode("\n", $L);

function tg_call($token, $method, $fields) {
    foreach (['', '149.154.167.220', '149.154.167.99'] as $ip) {
        $ch = curl_init("https://api.telegram.org/bot{$token}/{$method}");
        $opts = [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $fields, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 15];
        if ($ip !== '') $opts[CURLOPT_RESOLVE] = ["api.telegram.org:443:{$ip}"];
        curl_setopt_array($ch, $opts);
        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code === 200) return true;
        if ($code >= 400 && $code < 500) return false;
    }
    return false;
}

// Длинный текст режем на части по 3800 знаков по строкам.
$parts = [];
$cur = '';
foreach (explode("\n", $html) as $line) {
    while (mb_strlen($line) > 3800) { $parts[] = $cur; $cur = ''; $parts[] = mb_substr($line, 0, 3800); $line = mb_substr($line, 3800); }
    if (mb_strlen($cur) + mb_strlen($line) + 1 > 3800) { $parts[] = $cur; $cur = ''; }
    $cur .= ($cur === '' ? '' : "\n") . $line;
}
if ($cur !== '') $parts[] = $cur;
$parts = array_values(array_filter($parts, function ($p) { return trim($p) !== ''; }));

$tg = false;
$token = trim((string)($cfg['tg_token'] ?? ''));
$chats = array_filter(array_map('trim', explode(',', (string)($cfg['tg_chat'] ?? ''))));
if ($token !== '' && $chats && function_exists('curl_init')) {
    foreach ($chats as $chat) {
        $okChat = true;
        foreach ($parts as $p) $okChat = tg_call($token, 'sendMessage', http_build_query(['chat_id' => $chat, 'text' => $p, 'parse_mode' => 'HTML', 'disable_web_page_preview' => 'true'])) && $okChat;
        if ($logoPath !== '') tg_call($token, 'sendDocument', ['chat_id' => $chat, 'caption' => 'Логотип: ' . $d['name'], 'document' => new CURLFile($logoPath, '', $logoName)]);
        if ($okChat) $tg = true;
    }
}

// Почта.
$mailed = false;
$to = trim((string)($cfg['email_to'] ?? ''));
if ($to === '') $to = base64_decode('aS5hbS5zaXRuaWtvdkBnbWFpbC5jb20=') . ', info@agregatory.pro';
$plain = html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8');
$from = 'info@agregatory.pro';
$headers = "From: =?UTF-8?B?" . base64_encode('Сайт agregatory.pro') . "?= <{$from}>\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n";
$mailed = @mail($to, '=?UTF-8?B?' . base64_encode('Анкета партнёра: ' . $d['name']) . '?=', chunk_split(base64_encode($plain)), $headers);

reply(200, ['ok' => true, 'tg' => $tg, 'mail' => (bool)$mailed]);
