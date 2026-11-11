<?php
// Служебный сброс входа в кабинет /dokumenty (вызывает GitHub Actions, workflow pin-reset.yml).
// Ключ: sha256 от токена бота + 'pin-reset', его знают только хостинг и секреты GitHub.
//   user=yuri|alisa|lilia  гасит пин-код; новый временный человек берёт у бота командой /pin в личном чате
//   всегда снимает блокировку «Слишком много попыток»
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function out($c, $d) { http_response_code($c); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }
$cfg = is_file(__DIR__ . '/config.php') ? (include __DIR__ . '/config.php') : [];
$token = trim((string)($cfg['tg_token'] ?? ''));
if ($token === '' || !hash_equals(hash('sha256', $token . 'pin-reset'), (string)($_POST['k'] ?? ''))) out(404, ['error' => 'not found']);
$dir = __DIR__ . '/data/docs';
$res = ['fails_cleared' => @unlink($dir . '/fails.json') || !is_file($dir . '/fails.json')];
$user = (string)($_POST['user'] ?? '');
if (in_array($user, ['yuri', 'alisa', 'lilia'], true)) {
    $p = @json_decode((string)@file_get_contents($dir . '/users.json'), true) ?: [];
    $res['had_own_pin'] = !empty($p[$user]['hash']);
    // гасим только пин-код, ключ шаблонов не трогаем; новый временный пин-код человек берёт у бота (/pin)
    if (isset($p[$user])) { $p[$user]['hash'] = ''; unset($p[$user]['temp']); }
    @file_put_contents($dir . '/users.json', json_encode($p, JSON_UNESCAPED_UNICODE), LOCK_EX);
    $res['pin_reset'] = $user;
}
out(200, ['ok' => true] + $res);
