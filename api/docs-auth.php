<?php
// Общий вход по пин-кодам для служебных страниц agregatory.pro:
// /dokumenty (api/docs.php) и раздел «Заявки» (api/crm.php).
// Пин-коды одни и те же, сменённые хранятся в api/data/docs/users.json.
// Файл, который подключает этот, сам определяет функцию out($code, $data).

// Кабинеты: у каждого свой пин-код (в репозитории только хеши).
// all = видит весь журнал; tpl = папка шаблонов, зашифрованных этим пин-кодом.
const DOCS_USERS = [
    ['id' => 'alisa', 'name' => 'Алиса', 'all' => true, 'tpl' => '',
     'hash' => '$2y$11$rQ8HzcREARElUzMfyCkzu.bwIRFuHjR9Nq1.Bm3c.kVXN1m4bVWG6'],
    ['id' => 'yuri', 'name' => 'Юрий', 'all' => true, 'tpl' => 'yuri/', 'admin' => true,
     'hash' => '$2y$11$KJcooU2DCiWyxaG8FmOJJuMr778dHWQdzSkVYjeAqEGHKEvJ7Y1D.'],
    ['id' => 'lilia', 'name' => 'Лиля', 'all' => false, 'tpl' => 'lilia/',
     'hash' => '$2y$11$DPM0rs5z2LhztLOtdcEI/.aI87ysjNcKp9jafo/xCruOCOMzzYq7y'],
];
$USER = null;
$TK = '';          // ключ шаблонов: первый пин-код кабинета (шаблоны зашифрованы им)
$MUST_CHANGE = false;

// Свои пин-коды: после первого входа человек меняет выданный пин на свой.
// Хранятся на хостинге (api/data/docs/users.json, закрыто от чтения): хеш нового пина и ключ шаблонов.
function pins_file() { return __DIR__ . '/data/docs/users.json'; }
function pins_read() { return @json_decode((string)@file_get_contents(pins_file()), true) ?: []; }
function pins_write($p) { @file_put_contents(pins_file(), json_encode($p, JSON_UNESCAPED_UNICODE), LOCK_EX); }

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
    global $USER, $TK, $MUST_CHANGE;
    $pins = pins_read();
    foreach (DOCS_USERS as $u) {
        if ($key === '') break;
        $own = $pins[$u['id']] ?? null;
        if ($own) {
            // пин уже сменён: старый выданный пин больше не подходит
            if (password_verify($key, $own['hash'])) { $USER = $u; $TK = $own['tk']; return; }
        } elseif (password_verify($key, $u['hash'])) {
            $USER = $u; $TK = $key; $MUST_CHANGE = true; return;
        }
    }
    note_fail();
    out(401, ['error' => 'Неверный пин-код']);
}
