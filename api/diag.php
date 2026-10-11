<?php
// Временная диагностика почты. Доступ только по ключу (в репозитории лишь его хэш). Удалить после проверки.
header('Content-Type: text/plain; charset=utf-8'); header('Cache-Control: no-store');
if (!hash_equals('8d28a622392fd9bc23c5e3c5a8eff61b6b1cf85e44b77dbefcaa3143c7349f65', hash('sha256', (string)($_GET['k'] ?? '')))) { http_response_code(404); exit; }
echo "disable_functions: " . ini_get('disable_functions') . "\n";
echo "sendmail_path: " . ini_get('sendmail_path') . "\n";
echo "user: " . get_current_user() . "  home: " . (getenv('HOME') ?: '?') . "\n";
$cands = [dirname($_SERVER['DOCUMENT_ROOT']) . '/mail.log', dirname($_SERVER['DOCUMENT_ROOT'], 2) . '/mail.log', (getenv('HOME') ?: '') . '/mail.log'];
foreach (array_unique($cands) as $f) {
  echo "\n== $f: " . (is_readable($f) ? filesize($f) . " байт" : "нет") . "\n";
  if (is_readable($f)) { $l = file($f); foreach (array_slice($l, -25) as $s) echo preg_replace('/[\w.+-]+@(yandex|gmail|mail)\.\w+/i', '***@$1', $s); }
}
echo "\ndocroot: " . $_SERVER['DOCUMENT_ROOT'] . "\n";
foreach ([dirname($_SERVER['DOCUMENT_ROOT']), dirname($_SERVER['DOCUMENT_ROOT'], 2)] as $d) { echo "ls $d: " . implode(' ', array_slice(@scandir($d) ?: [], 0, 40)) . "\n"; }
if (($_GET['send'] ?? '') === '1') {
  $to = base64_decode('aS5hbS5zaXRuaWtvdkBnbWFpbC5jb20=');
  $ok = mail($to, 'Diag agregatory.pro ' . date('H:i:s'), "Служебное письмо для проверки почты хостинга.", "From: info@agregatory.pro\r\nContent-Type: text/plain; charset=UTF-8\r\n", '-finfo@agregatory.pro');
  echo "\nmail() to gmail: " . var_export($ok, true) . "\n";
}
