<?php
// Переход на сайт партнёра: agregatory.pro/go/<партнёр> -> сайт партнёра.
// Адреса берутся из partners-links.json (его пишет сборка сайта из src/data/partners.ts).
// Считаем клики по дням: api/data/partner-clicks.json (папка data снаружи закрыта).
// Боты и повторные клики с одного IP за сутки не считаются.

header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');

$slug = (string)($_GET['p'] ?? '');
$links = @json_decode(@file_get_contents(dirname(__DIR__) . '/partners-links.json') ?: '{}', true);
if (!preg_match('/^[a-z0-9-]{1,60}$/', $slug) || !is_array($links) || empty($links[$slug])) {
    header('Location: /partnery', true, 302);
    exit;
}
$url = (string)$links[$slug];
if (!preg_match('#^https?://#', $url)) { header('Location: /partnery', true, 302); exit; }

$ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
$isBot = $ua === '' || preg_match('/bot|crawl|spider|slurp|preview|headless|lighthouse/i', $ua);

if (!$isBot) {
    $dir = __DIR__ . '/data';
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    $fh = @fopen($dir . '/partner-clicks.json', 'c+');
    if ($fh) {
        flock($fh, LOCK_EX);
        $db = json_decode(stream_get_contents($fh) ?: '', true);
        if (!is_array($db)) $db = ['days' => [], 'seen' => []];
        $day = gmdate('Y-m-d', time() + 3 * 3600);
        $key = substr(sha1(($_SERVER['REMOTE_ADDR'] ?? '') . '|' . $slug . '|' . $day), 0, 16);
        if (!isset($db['seen'][$key])) {
            $db['seen'][$key] = $day;
            $db['days'][$day][$slug] = (int)($db['days'][$day][$slug] ?? 0) + 1;
            foreach ($db['seen'] as $k => $d) if ($d !== $day) unset($db['seen'][$k]);
            ftruncate($fh, 0); rewind($fh); fwrite($fh, json_encode($db)); fflush($fh);
        }
        flock($fh, LOCK_UN); fclose($fh);
    }
}

header('Location: ' . $url, true, 302);
