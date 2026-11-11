<?php
// Общий вход по пин-кодам для служебных страниц agregatory.pro:
// /dokumenty (api/docs.php) и раздел «Заявки» (api/crm.php).
// Пин-коды одни и те же, сменённые хранятся в api/data/docs/users.json.
// Файл, который подключает этот, сам определяет функцию out($code, $data).

// Кабинеты. Пин-кодов и их отпечатков в коде нет (с 12.11.2026): свой пин-код каждого хранится только
// на хостинге (api/data/docs/users.json), временный выдаёт бот заявок командой /pin в личном чате.
// all = видит весь журнал; tpl = прежняя папка шаблонов (до переезда в api/data/docs/tpl/).
const DOCS_USERS = [
    ['id' => 'alisa', 'name' => 'Алиса', 'all' => true, 'tpl' => ''],
    ['id' => 'yuri', 'name' => 'Юрий', 'all' => true, 'tpl' => 'yuri/', 'admin' => true],
    ['id' => 'lilia', 'name' => 'Лиля', 'all' => false, 'tpl' => 'lilia/'],
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
    // Адрес берём только из того, что выставляет сам хостинг: настоящий адрес соединения,
    // а если запрос пришёл через внутренний прокси хостинга — X-Real-IP или последний адрес
    // в X-Forwarded-For (его дописывает прокси). Первый адрес из X-Forwarded-For присылает
    // посетитель, по нему считать ошибки входа нельзя.
    $pub = fn($ip) => (bool)filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    $ra = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    if ($pub($ra)) return substr($ra, 0, 64);
    $real = trim((string)($_SERVER['HTTP_X_REAL_IP'] ?? ''));
    if ($pub($real)) return substr($real, 0, 64);
    $xff = array_map('trim', explode(',', (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '')));
    $last = (string)end($xff);
    if ($pub($last)) return substr($last, 0, 64);
    return substr($ra !== '' ? $ra : 'unknown', 0, 64);
}
function fails_file() { return __DIR__ . '/data/docs/fails.json'; }
function too_many_fails() {
    $f = @json_decode((string)@file_get_contents(fails_file()), true) ?: [];
    $ip = client_ip();
    $list = array_filter($f[$ip] ?? [], fn($t) => $t > time() - 3600);
    if (count($list) >= 5) return true;
    // Общий предел на кабинет: больше 60 ошибок за час со всех адресов — вход закрыт для всех до конца часа.
    $all = 0;
    foreach ($f as $ts) foreach ((array)$ts as $t) if ($t > time() - 3600) $all++;
    return $all >= 60;
}
function note_fail() {
    $f = @json_decode((string)@file_get_contents(fails_file()), true) ?: [];
    $ip = client_ip();
    $f[$ip] = array_values(array_filter($f[$ip] ?? [], fn($t) => $t > time() - 3600));
    $f[$ip][] = time();
    @file_put_contents(fails_file(), json_encode($f), LOCK_EX);
}
// --- Шаблоны договоров: с 12.11.2026 переезжают в закрытую папку хостинга ---------
// Раньше шаблон лежал в открытом коде, зашифрованный выданным пин-кодом (короткий ключ).
// Теперь сайт сам, один раз для каждого кабинета, перекладывает шаблоны в api/data/docs/tpl/<кабинет>/
// под длинным случайным ключом, который хранится только на хостинге (api/data/docs/tplkeys.json).
function tpl_keys_file() { return __DIR__ . '/data/docs/tplkeys.json'; }
function tpl_keys() { return @json_decode((string)@file_get_contents(tpl_keys_file()), true) ?: []; }
function tpl_dir($id) { return __DIR__ . '/data/docs/tpl/' . $id; }
function tpl_key($id) { return (string)(tpl_keys()[$id]['k'] ?? ''); }
function tpl_dec($raw, $password) {
    if (strlen($raw) < 48 || substr($raw, 0, 4) !== 'AGD1') return false;
    $key = hash_pbkdf2('sha256', $password, substr($raw, 4, 16), 200000, 32, true);
    return openssl_decrypt(substr($raw, 32, -16), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($raw, 20, 12), substr($raw, -16), 'agregatory-docs');
}
function tpl_enc($plain, $password) {
    $salt = random_bytes(16); $nonce = random_bytes(12); $tag = '';
    $key = hash_pbkdf2('sha256', $password, $salt, 200000, 32, true);
    $ct = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, 'agregatory-docs', 16);
    return $ct === false ? false : 'AGD1' . $salt . $nonce . $ct . $tag;
}
// Перекладывает шаблоны одного кабинета. $oldKey = прежний ключ шаблонов (выданный пин-код).
function tpl_migrate($u, $oldKey) {
    if ($oldKey === '' || tpl_key($u['id']) !== '') return tpl_key($u['id']) !== '';
    $files = glob(__DIR__ . '/docs/' . $u['tpl'] . '*.docx.enc') ?: [];
    if (!$files) return false;
    $k = bin2hex(random_bytes(32));
    $out = [];
    foreach ($files as $f) {
        $plain = tpl_dec((string)file_get_contents($f), $oldKey);
        if ($plain === false || substr($plain, 0, 2) !== 'PK') return false; // ключ не подошёл: ничего не трогаем
        $enc = tpl_enc($plain, $k);
        if ($enc === false) return false;
        $out[basename($f)] = $enc;
    }
    $dir = tpl_dir($u['id']);
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    foreach ($out as $name => $enc) if (@file_put_contents($dir . '/' . $name, $enc, LOCK_EX) === false) return false;
    $keys = tpl_keys();
    $keys[$u['id']] = ['k' => $k, 'at' => date('c'), 'files' => array_keys($out)];
    return @file_put_contents(tpl_keys_file(), json_encode($keys, JSON_UNESCAPED_UNICODE), LOCK_EX) !== false;
}
// Для тех, у кого свой пин-код уже есть, прежний ключ шаблонов лежит на хостинге: перекладываем без входа.
function tpl_migrate_known() {
    $pins = pins_read(); $done = [];
    foreach (DOCS_USERS as $u) {
        $old = (string)($pins[$u['id']]['tk'] ?? '');
        if ($old !== '' && tpl_key($u['id']) === '' && tpl_migrate($u, $old)) $done[] = $u['id'];
        // шаблоны переехали: прежний короткий ключ больше не нужен, стираем
        if ($old !== '' && tpl_key($u['id']) !== '') { $pins[$u['id']]['tk'] = ''; $dirty = true; }
    }
    if (!empty($dirty)) pins_write($pins);
    return $done;
}
// Что уже сделано (без секретов): у кого свой пин-код и у кого шаблоны переехали
function sec_state() {
    $pins = pins_read(); $r = [];
    foreach (DOCS_USERS as $u) $r[$u['id']] = ['own' => !empty($pins[$u['id']]['hash']), 'tpl' => tpl_key($u['id']) !== ''];
    return $r;
}

const PIN_MIN = 6;          // с 12.11.2026 свой пин-код не короче 6 цифр
const PIN_TEMP_TTL = 86400; // временный пин-код от бота живёт сутки

function auth() {
    if (too_many_fails()) out(429, ['error' => 'Слишком много попыток. Попробуйте через час.']);
    $key = rawurldecode((string)($_SERVER['HTTP_X_DOCS_KEY'] ?? ''));
    // запрос без пин-кода (страница ещё не вошла) попыткой подбора не считаем
    if ($key === '') out(401, ['error' => 'Введите пин-код']);
    global $USER, $TK, $MUST_CHANGE;
    $pins = pins_read();
    $found = null; $legacy = '';
    foreach (DOCS_USERS as $u) {
        $own = $pins[$u['id']] ?? null;
        if ($own) {
            // свой пин-код
            if (!empty($own['hash']) && password_verify($key, $own['hash'])) {
                $found = $u; $legacy = (string)($own['tk'] ?? ''); $MUST_CHANGE = strlen($key) < PIN_MIN; break;
            }
            // временный пин-код, который бот прислал в личный чат (/pin)
            $t = $own['temp'] ?? null;
            if ($t && ($t['at'] ?? 0) > time() - PIN_TEMP_TTL && password_verify($key, (string)$t['hash'])) {
                $found = $u; $legacy = (string)($own['tk'] ?? ''); $MUST_CHANGE = true; break;
            }
        } elseif (!empty($u['hash']) && password_verify($key, $u['hash'])) {
            // выданный пин-код (уходит: после переезда шаблонов отпечатки из кода убираем)
            $found = $u; $legacy = $key; $MUST_CHANGE = true; break;
        }
    }
    if (!$found) { note_fail(); out(401, ['error' => 'Неверный пин-код']); }
    $USER = $found;
    if (tpl_key($USER['id']) === '' && $legacy !== '') { try { tpl_migrate($USER, $legacy); } catch (Throwable $e) { error_log('TPL MIGRATE: ' . $e->getMessage()); } }
    $new = tpl_key($USER['id']);
    $TK = $new !== '' ? $new : $legacy;
}
