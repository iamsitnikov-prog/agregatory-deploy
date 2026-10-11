<?php
// Отметки «Полезно» под статьями блога agregatory.pro (раньше жили на poehali.dev).
//   GET  ?slugs=a,b,c   -> {"likes": {"a": 3, ...}}
//   POST {"slug": "a"}  -> {"slug": "a", "likes": 4}
// Хранение: api/data/reactions.json (папка data снаружи закрыта в .htaccess).
// При первом запуске счётчики берутся из api/reactions-seed.json (перенос с poehali 11.10.2026).
// Один голос с одного IP за статью (хранится только хэш IP).

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function reply($code, $data) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'OPTIONS') reply(204, []);

$dir = __DIR__ . '/data';
if (!is_dir($dir)) @mkdir($dir, 0750, true);
$file = $dir . '/reactions.json';
$SLUG_RE = '/^[a-z0-9-]{1,160}$/';

$fh = @fopen($file, 'c+');
if (!$fh) reply(500, ['error' => 'storage']);
flock($fh, $method === 'POST' ? LOCK_EX : LOCK_SH);
$raw = stream_get_contents($fh);
$db = json_decode($raw ?: '', true);
if (!is_array($db) || !isset($db['likes'])) {
    $seed = @json_decode(@file_get_contents(__DIR__ . '/reactions-seed.json') ?: '{}', true);
    $db = ['likes' => is_array($seed) ? $seed : [], 'voters' => []];
    if ($method !== 'POST') {
        // записываем начальные данные под эксклюзивной блокировкой
        flock($fh, LOCK_UN); flock($fh, LOCK_EX);
        ftruncate($fh, 0); rewind($fh); fwrite($fh, json_encode($db)); fflush($fh);
    }
}

if ($method === 'GET') {
    flock($fh, LOCK_UN); fclose($fh);
    $slugs = array_slice(array_filter(explode(',', (string)($_GET['slugs'] ?? '')), function ($s) use ($SLUG_RE) {
        return preg_match($SLUG_RE, $s);
    }), 0, 300);
    $out = new stdClass();
    foreach ($slugs as $s) if (isset($db['likes'][$s])) $out->$s = (int)$db['likes'][$s];
    reply(200, ['likes' => $out]);
}

if ($method !== 'POST') { flock($fh, LOCK_UN); fclose($fh); reply(405, ['error' => 'Method not allowed', 'service' => 'reactions']); }

$body = json_decode(file_get_contents('php://input') ?: '{}', true);
$slug = is_array($body) ? (string)($body['slug'] ?? '') : '';
if (!preg_match($SLUG_RE, $slug)) { flock($fh, LOCK_UN); fclose($fh); reply(400, ['error' => 'bad slug']); }

$voter = substr(sha1(($_SERVER['REMOTE_ADDR'] ?? '') . '|' . $slug . '|agregatory'), 0, 16);
if (!isset($db['voters']) || !is_array($db['voters'])) $db['voters'] = [];
if (!isset($db['voters'][$voter])) {
    $db['likes'][$slug] = (int)($db['likes'][$slug] ?? 0) + 1;
    $db['voters'][$voter] = time();
    // чистим голоса старше 365 дней
    $cut = time() - 365 * 86400;
    foreach ($db['voters'] as $k => $t) if ($t < $cut) unset($db['voters'][$k]);
    ftruncate($fh, 0); rewind($fh); fwrite($fh, json_encode($db)); fflush($fh);
}
$likes = (int)$db['likes'][$slug];
flock($fh, LOCK_UN); fclose($fh);
reply(200, ['slug' => $slug, 'likes' => $likes]);
