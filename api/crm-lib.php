<?php
// База заявок для раздела «Заявки» в кабинете agregatory.pro/dokumenty.
// Хранится на хостинге в api/data/crm/leads.json (папка data снаружи закрыта).
// Сюда пишут: api/lead.php (форма и чек-листы), api/pay-lib.php (оплаты на сайте),
// api/crm.php (статусы, ответственные, комментарии, ручные заявки).

const CRM_STATUSES = [
    ['id' => 'new', 'title' => 'Новая'],
    ['id' => 'work', 'title' => 'Взята в работу'],
    ['id' => 'call', 'title' => 'Созвон назначен'],
    ['id' => 'kp', 'title' => 'КП отправлено'],
    ['id' => 'contract', 'title' => 'Договор'],
    ['id' => 'paid', 'title' => 'Оплачено'],
    ['id' => 'noanswer', 'title' => 'Нет ответа'], // на карточке: «не дозвонились» или «не дописались» по типу связи
    ['id' => 'refused', 'title' => 'Отказ'],
];

function crm_dir() {
    $base = __DIR__ . '/data';
    $dir = $base . '/crm';
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    if (!is_file($base . '/.htaccess')) @file_put_contents($base . '/.htaccess', "Require all denied\nDeny from all\n");
    return $dir;
}

function crm_now() {
    return (new DateTime('now', new DateTimeZone('Europe/Moscow')))->format('Y-m-d H:i:s');
}

// Этапы можно менять в кабинете (добавить в любое место, переименовать, переставить, удалить).
// Хранятся в api/data/crm/statuses.json; пока файла нет, действуют этапы по умолчанию.
// new, contract, paid системные: в них сайт сам кладёт заявки, поэтому их нельзя удалить.
// refused тоже: к нему привязаны причины отказа
const CRM_SYSTEM = ['new', 'contract', 'paid', 'refused'];
function crm_statuses() {
    $f = crm_dir() . '/statuses.json';
    $list = is_file($f) ? json_decode((string)@file_get_contents($f), true) : null;
    return is_array($list) && $list ? $list : CRM_STATUSES;
}
function crm_statuses_save($list) {
    return @file_put_contents(crm_dir() . '/statuses.json', json_encode(array_values($list), JSON_UNESCAPED_UNICODE), LOCK_EX) !== false;
}
function crm_status_title($id) {
    static $cache = null;
    if ($cache === null) $cache = crm_statuses();
    foreach ($cache as $s) if ($s['id'] === $id) return $s['title'];
    return $id;
}

// Тестовые заявки Claude не переносим и не показываем
function crm_is_test($name, $comment) {
    return stripos((string)$name, 'ТЕСТ Claude') === 0 || mb_stripos((string)$comment, 'от Claude') !== false;
}

// --- Мусор от роботов (с 25.11.2026) ------------------------------------------
// 25.11 сканер уязвимостей прислал через форму заявки 38–43: имя TestName, телефон
// 79991234567 и строки вида «' AND SLEEP(8)-- -» (попытка SQL-инъекции). Базы SQL
// у сайта нет (заявки лежат в файлах), поэтому вреда не было, но такие заявки не должны
// попадать в кабинет и в группу. Возвращает причину или '' для нормальной заявки.
function crm_junk_reason($l) {
    $fields = [];
    foreach (['name', 'phone', 'place', 'city', 'service', 'channel', 'comment', 'page'] as $k) $fields[] = (string)($l[$k] ?? '');
    $all = implode("\n", $fields);
    $attack = [
        '/\b(sleep|benchmark|pg_sleep|dbms_pipe\.receive_message|waitfor\s+delay)\s*\(/i',
        '/\bunion\b[\s\S]{0,40}\bselect\b/i',
        '/\bselect\b[\s\S]{0,60}\bfrom\b[\s\S]{0,40}\b(information_schema|dual|users?)\b/i',
        "/['\"`]\\s*\\)?\\s*(and|or|xor)\\s+(\\d+\\s*[=<>]|['\"]\\w*['\"]?\\s*=|true\\b|false\\b|not\\b|\\(\\s*select\\b)/i",
        "/['\"`)]\\s*(--\\s*-?|#|\\/\\*)\\s*$/m",
        '/@@version|xp_cmdshell|load_file\s*\(|into\s+outfile/i',
        '/<\s*\/?\s*(script|iframe|svg|img|body)\b|javascript:|on(error|load)\s*=/i',
        '/\$\{[^}]*\}|\{\{[^}]*\}\}|<%=?/',
        '/\.\.\/|\/etc\/passwd|win\.ini|\bcmd\.exe\b|\|\s*(id|whoami|cat)\b|;\s*(id|whoami|ls)\b/i',
    ];
    foreach ($attack as $re) if (preg_match($re, $all)) return 'похоже на атаку';
    if (preg_match('/^test\s*name$|^тест\s*имя$/iu', trim((string)($l['name'] ?? '')))) return 'имя-заглушка сканера';
    $digits = preg_replace('/\D/', '', (string)($l['phone'] ?? ''));
    if ($digits !== '' && in_array(substr($digits, -10), ['9991234567', '1234567890', '9999999999', '0000000000', '9876543210'], true)) return 'телефон-заглушка';
    return '';
}

// Заявки-мусор, которые уже попали в базу и с ними никто не работал, убираем из списка
// в отдельный раздел той же базы ($db['junk']). Ничего не удаляется: при нужде можно вернуть.
function crm_sweep_junk(&$db) {
    $keep = []; $moved = 0;
    foreach ($db['items'] as $l) {
        $untouched = ($l['status'] ?? '') === 'new' && ($l['owner'] ?? '') === '' && empty($l['notes']);
        $why = $untouched ? crm_junk_reason($l) : '';
        if ($why !== '') { $l['junk'] = $why; $l['junk_at'] = crm_now(); $db['junk'][] = $l; $moved++; }
        else $keep[] = $l;
    }
    if ($moved) $db['items'] = $keep;
    return $moved;
}

// Адрес посетителя: только то, что выставляет сам хостинг (как в docs-auth.php).
// Первый адрес в X-Forwarded-For присылает посетитель, по нему ограничивать нельзя.
function site_client_ip() {
    $pub = fn($ip) => (bool)filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    $ra = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    if ($pub($ra)) return substr($ra, 0, 64);
    $real = trim((string)($_SERVER['HTTP_X_REAL_IP'] ?? ''));
    if ($pub($real)) return substr($real, 0, 64);
    $xff = array_map('trim', explode(',', (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '')));
    $last = (string)end($xff);
    if ($pub($last)) return substr($last, 0, 64);
    return substr($ra, 0, 64);
}

// Читает и меняет базу под замком: $fn получает массив заявок по ссылке.
function crm_update(callable $fn) {
    $file = crm_dir() . '/leads.json';
    $fh = @fopen($file, 'c+');
    if (!$fh) return null;
    flock($fh, LOCK_EX);
    $db = json_decode(stream_get_contents($fh) ?: '', true);
    if (!is_array($db) || !isset($db['items'])) $db = ['next' => 1, 'items' => [], 'imported' => false];
    if (empty($db['imported'])) crm_import_csv($db);
    if (!empty($db['items'])) crm_sweep_junk($db);
    $res = $fn($db);
    ftruncate($fh, 0); rewind($fh);
    fwrite($fh, json_encode($db, JSON_UNESCAPED_UNICODE));
    fflush($fh);
    flock($fh, LOCK_UN); fclose($fh);
    return $res;
}

function crm_read() {
    return crm_update(fn(&$db) => $db);
}

function crm_new_item(&$db, $l, $status = 'new', $historyText = 'заявка с сайта') {
    $id = (int)($db['next'] ?? 1);
    $db['next'] = $id + 1;
    $created = $l['created'] ?? crm_now();
    $item = [
        'id' => $id,
        'created' => $created,
        'name' => mb_substr(trim((string)($l['name'] ?? '')), 0, 200),
        'phone' => mb_substr(trim((string)($l['phone'] ?? '')), 0, 50),
        'email' => mb_substr(trim((string)($l['email'] ?? '')), 0, 120),
        'place' => mb_substr(trim((string)($l['place'] ?? '')), 0, 300),
        'city' => mb_substr(trim((string)($l['city'] ?? '')), 0, 80),
        'service' => mb_substr(trim((string)($l['service'] ?? '')), 0, 200),
        'channel' => mb_substr(trim((string)($l['channel'] ?? '')), 0, 50),
        'comment' => mb_substr(trim((string)($l['comment'] ?? '')), 0, 3000),
        'page' => mb_substr(trim((string)($l['page'] ?? '')), 0, 300),
        'source' => mb_substr((string)($l['source'] ?? 'форма'), 0, 40),
        'src' => is_array($l['src'] ?? null) ? $l['src'] : null, // откуда пришла (crm_src_clean), с 23.11.2026
        'amount' => (int)($l['amount'] ?? 0),
        'status' => $status,
        'owner' => '',
        'reason' => '',
        'updated' => $created,
        'notes' => [],
        'history' => [['at' => $created, 'who' => (string)($l['who'] ?? 'сайт'), 'text' => $historyText]],
    ];
    $db['items'][] = $item;
    return $item;
}

// Один раз переносим заявки, которые уже лежат в журнале api/data/leads.csv
function crm_import_csv(&$db) {
    $db['imported'] = true;
    $csv = __DIR__ . '/data/leads.csv';
    if (!is_readable($csv) || !($fh = fopen($csv, 'r'))) return;
    $first = true;
    while (($r = fgetcsv($fh)) !== false) {
        if ($first) { $first = false; continue; }
        if (count($r) < 3) continue;
        if (isset($r[0])) $r[0] = preg_replace('/^\xEF\xBB\xBF/', '', $r[0]);
        if (crm_is_test($r[1] ?? '', $r[6] ?? '')) continue;
        $svc = (string)($r[4] ?? '');
        crm_new_item($db, [
            'created' => (string)$r[0], 'name' => $r[1] ?? '', 'phone' => $r[2] ?? '', 'place' => $r[3] ?? '',
            'service' => $svc, 'channel' => $r[5] ?? '', 'comment' => $r[6] ?? '', 'page' => $r[7] ?? '', 'city' => $r[9] ?? '',
            'source' => $svc === 'скачал чек-лист' ? 'чек-лист' : 'форма',
        ], 'new', 'перенесена из журнала заявок сайта');
    }
    fclose($fh);
}

// Новая заявка с сайта. Никогда не роняет обработчик, который её вызвал.
function crm_add($l, $status = 'new', $historyText = 'заявка с сайта') {
    try {
        if (crm_is_test($l['name'] ?? '', $l['comment'] ?? '')) return null;
        if (crm_junk_reason($l) !== '') return null;
        return crm_update(function (&$db) use ($l, $status, $historyText) {
            return crm_new_item($db, $l, $status, $historyText);
        });
    } catch (Throwable $e) {
        error_log('CRM ADD FAILED: ' . $e->getMessage());
        return null;
    }
}

// --- Откуда пришла заявка (с 23.11.2026) ---------------------------------------
// Сайт (src/lib/attribution.ts) присылает вместе с заявкой: first и last (страница входа,
// referrer, utm-метки), pages (что человек открыл до заявки), form (где отправил), cid (ClientID Метрики).
// Здесь всё чистим, обрезаем и определяем канал и поисковик. Сам поисковый запрос
// Яндекс и Google на сайт не передают, его подставляет кабинет по странице входа
// из данных Вебмастера (api/data/crm/seo.json, обновляет lead-queries.yml).

function crm_ends($s, $end) { return $end === '' || substr($s, -strlen($end)) === $end; }

function crm_src_path($u) {
    $u = trim((string)$u);
    if ($u === '') return '';
    $p = parse_url($u);
    if ($p === false) return '';
    $path = (string)($p['path'] ?? '/');
    if ($path === '' ) $path = '/';
    $path = preg_replace('~/+$~', '', $path) ?: '/';
    return mb_substr($path, 0, 200);
}

// Канал по referrer и меткам: kind (поиск, реклама, ИИ, соцсети, сайт, прямой заход, метка) и engine
function crm_src_classify($ref, $utm) {
    $utm = is_array($utm) ? $utm : [];
    $host = strtolower((string)(parse_url((string)$ref, PHP_URL_HOST) ?? ''));
    $host = preg_replace('/^(www|m)\./', '', $host);
    $us = mb_strtolower((string)($utm['utm_source'] ?? ''));
    $um = mb_strtolower((string)($utm['utm_medium'] ?? ''));
    $ai = ['chatgpt.com' => 'ChatGPT', 'chat.openai.com' => 'ChatGPT', 'perplexity.ai' => 'Perplexity', 'gemini.google.com' => 'Gemini',
        'copilot.microsoft.com' => 'Copilot', 'claude.ai' => 'Claude', 'chat.deepseek.com' => 'DeepSeek', 'alice.yandex.ru' => 'Алиса',
        'giga.chat' => 'GigaChat', 'grok.com' => 'Grok'];
    foreach ($ai as $h => $name) if ($host === $h || crm_ends($host, '.' . $h) || $us === strtolower($name) || $us === $h) return ['kind' => 'ИИ', 'engine' => $name];
    if (!empty($utm['yclid']) || (preg_match('/cpc|ppc|paid|direct|реклам/u', $um) && $um !== '')) {
        return ['kind' => 'реклама', 'engine' => !empty($utm['gclid']) ? 'Google Ads' : 'Яндекс Директ'];
    }
    if (!empty($utm['gclid'])) return ['kind' => 'реклама', 'engine' => 'Google Ads'];
    $search = [
        '/(^|\.)yandex\.(ru|com|by|kz|uz|com\.tr)$|(^|\.)ya\.ru$/' => 'Яндекс',
        '/(^|\.)google\.[a-z.]+$/' => 'Google',
        '/(^|\.)bing\.com$/' => 'Bing',
        '/(^|\.)(go\.)?mail\.ru$/' => 'Mail.ru',
        '/(^|\.)duckduckgo\.com$/' => 'DuckDuckGo',
        '/(^|\.)rambler\.ru$/' => 'Рамблер',
        '/(^|\.)yahoo\.com$/' => 'Yahoo',
    ];
    foreach ($search as $re => $name) {
        if ($host !== '' && preg_match($re, $host)) {
            // Яндекс Карты/Бизнес и Дзен — не поиск
            if ($name === 'Яндекс' && preg_match('~^https?://[^/]+/(maps|profile|business)~', (string)$ref)) return ['kind' => 'Яндекс Карты', 'engine' => 'Яндекс Карты'];
            return ['kind' => 'поиск', 'engine' => $name];
        }
    }
    if (preg_match('/(^|\.)(dzen\.ru|zen\.yandex\.ru)$/', $host)) return ['kind' => 'соцсети', 'engine' => 'Дзен'];
    $social = ['t.me' => 'Telegram', 'telegram.org' => 'Telegram', 'web.telegram.org' => 'Telegram', 'vk.com' => 'ВКонтакте', 'vk.ru' => 'ВКонтакте',
        'ok.ru' => 'Одноклассники', 'youtube.com' => 'YouTube', 'instagram.com' => 'Instagram', 'facebook.com' => 'Facebook', 'max.ru' => 'MAX',
        'web.max.ru' => 'MAX', 'vc.ru' => 'vc.ru', 'pikabu.ru' => 'Пикабу'];
    foreach ($social as $h => $name) if ($host === $h || crm_ends($host, '.' . $h)) return ['kind' => 'соцсети', 'engine' => $name];
    if ($us !== '') {
        $map = ['yandex' => 'Яндекс', 'google' => 'Google', 'telegram' => 'Telegram', 'tg' => 'Telegram', 'vk' => 'ВКонтакте'];
        return ['kind' => 'метка', 'engine' => $map[$us] ?? mb_substr((string)$utm['utm_source'], 0, 40)];
    }
    if (!empty($utm['ysclid'])) return ['kind' => 'поиск', 'engine' => 'Яндекс']; // ysclid Яндекс добавляет к переходам из своих сервисов
    if ($host !== '') return ['kind' => 'сайт', 'engine' => mb_substr($host, 0, 60)];
    return ['kind' => 'прямой заход', 'engine' => ''];
}

// Запрос, если поисковик сам положил его в referrer (бывает у Bing, Mail.ru, старых ссылок Яндекса)
function crm_src_query($ref) {
    $q = (string)(parse_url((string)$ref, PHP_URL_QUERY) ?? '');
    if ($q === '') return '';
    parse_str($q, $p);
    foreach (['text', 'q', 'query', 'p', 'words'] as $k) {
        if (!empty($p[$k]) && is_string($p[$k])) return mb_substr(trim($p[$k]), 0, 200);
    }
    return '';
}

function crm_src_touch($t) {
    if (!is_array($t)) return null;
    $utm = [];
    foreach ((array)($t['utm'] ?? []) as $k => $v) {
        if (is_string($k) && preg_match('/^(utm_[a-z]+|yclid|gclid|ysclid|fbclid|from|_openstat)$/', $k) && is_scalar($v)) $utm[$k] = mb_substr(trim((string)$v), 0, 120);
        if (count($utm) >= 10) break;
    }
    $ref = mb_substr(trim((string)($t['ref'] ?? '')), 0, 300);
    if ($ref !== '' && !preg_match('~^https?://~i', $ref)) $ref = '';
    $landing = mb_substr(trim((string)($t['landing'] ?? '')), 0, 300);
    if ($landing !== '' && $landing[0] !== '/') $landing = '';
    $c = crm_src_classify($ref, $utm);
    $out = ['at' => mb_substr((string)($t['at'] ?? ''), 0, 30), 'landing' => crm_src_path('https://x' . $landing), 'ref' => $ref, 'utm' => $utm] + $c;
    $q = crm_src_query($ref);
    if ($q !== '') $out['query'] = $q;
    if ($q === '' && !empty($utm['utm_term'])) $out['query'] = $utm['utm_term'];
    return $out;
}

// Чистит то, что прислал сайт. Возвращает null, если ничего не пришло (старые версии сайта).
function crm_src_clean($s) {
    if (!is_array($s)) return null;
    $last = crm_src_touch($s['last'] ?? null);
    $first = crm_src_touch($s['first'] ?? null);
    if (!$last && !$first) return null;
    if (!$last) $last = $first;
    $pages = [];
    foreach (array_slice((array)($s['pages'] ?? []), -12) as $p) if (is_string($p) && $p !== '' && $p[0] === '/') $pages[] = mb_substr($p, 0, 200);
    $out = $last + [
        'form' => crm_src_path('https://x' . (string)($s['form'] ?? '')),
        'pages' => $pages,
        'cid' => preg_replace('/\D/', '', mb_substr((string)($s['cid'] ?? ''), 0, 30)),
    ];
    // первый заход храним, только если он был другим (иначе это то же самое)
    if ($first && ($first['landing'] !== $last['landing'] || $first['ref'] !== $last['ref'] || $first['at'] !== $last['at'])) {
        unset($first['utm']['ysclid']);
        $out['first'] = $first;
    }
    return $out;
}

// Ручной источник для заявок, которые пришли не через форму (звонок, Telegram, сарафан)
const CRM_ORIGINS = ['сайт', 'Telegram-канал', 'рекомендация', 'Яндекс Бизнес', 'партнёр', 'соцсети', 'повторный клиент', 'другое'];

function crm_src_manual($origin) {
    return ['kind' => 'вручную', 'engine' => $origin, 'manual' => $origin, 'landing' => '', 'ref' => '', 'utm' => []];
}

// Одна строка для Telegram и писем: «Яндекс, поиск → /blog/x»
function crm_src_line($src) {
    if (!is_array($src)) return '';
    if (!empty($src['manual'])) return 'указали вручную: ' . $src['manual'];
    $t = crm_src_touch_line($src);
    if (($src['form'] ?? '') !== '' && $src['form'] !== ($src['landing'] ?? '')) $t .= ', форма на ' . $src['form'];
    if (!empty($src['first'])) $t .= '; впервые: ' . crm_src_touch_line($src['first']) . (preg_match('/^(\d{4})-(\d\d)-(\d\d)/', (string)$src['first']['at'], $m) ? " ({$m[3]}.{$m[2]})" : '');
    return $t;
}
function crm_src_touch_line($src) {
    $parts = [];
    if (($src['engine'] ?? '') !== '' && $src['kind'] !== 'сайт') $parts[] = $src['engine'] . ($src['kind'] === 'поиск' ? ', поиск' : ($src['kind'] === 'реклама' ? ', реклама' : ''));
    else $parts[] = $src['kind'] === 'сайт' ? 'ссылка с ' . $src['engine'] : $src['kind'];
    $t = implode(' ', $parts);
    if (($src['landing'] ?? '') !== '') $t .= ' → ' . $src['landing'];
    if (($src['query'] ?? '') !== '') $t .= ', запрос «' . $src['query'] . '»';
    return $t;
}
