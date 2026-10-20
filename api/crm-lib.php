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
    ['id' => 'noanswer', 'title' => 'Не дозвонились'],
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
const CRM_SYSTEM = ['new', 'contract', 'paid'];
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

// Читает и меняет базу под замком: $fn получает массив заявок по ссылке.
function crm_update(callable $fn) {
    $file = crm_dir() . '/leads.json';
    $fh = @fopen($file, 'c+');
    if (!$fh) return null;
    flock($fh, LOCK_EX);
    $db = json_decode(stream_get_contents($fh) ?: '', true);
    if (!is_array($db) || !isset($db['items'])) $db = ['next' => 1, 'items' => [], 'imported' => false];
    if (empty($db['imported'])) crm_import_csv($db);
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
        'service' => mb_substr(trim((string)($l['service'] ?? '')), 0, 200),
        'channel' => mb_substr(trim((string)($l['channel'] ?? '')), 0, 50),
        'comment' => mb_substr(trim((string)($l['comment'] ?? '')), 0, 3000),
        'page' => mb_substr(trim((string)($l['page'] ?? '')), 0, 300),
        'source' => mb_substr((string)($l['source'] ?? 'форма'), 0, 40),
        'amount' => (int)($l['amount'] ?? 0),
        'status' => $status,
        'owner' => '',
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
            'service' => $svc, 'channel' => $r[5] ?? '', 'comment' => $r[6] ?? '', 'page' => $r[7] ?? '',
            'source' => $svc === 'скачал чек-лист' ? 'чек-лист' : 'форма',
        ], 'new', 'перенесена из журнала заявок сайта');
    }
    fclose($fh);
}

// Новая заявка с сайта. Никогда не роняет обработчик, который её вызвал.
function crm_add($l, $status = 'new', $historyText = 'заявка с сайта') {
    try {
        if (crm_is_test($l['name'] ?? '', $l['comment'] ?? '')) return null;
        return crm_update(function (&$db) use ($l, $status, $historyText) {
            return crm_new_item($db, $l, $status, $historyText);
        });
    } catch (Throwable $e) {
        error_log('CRM ADD FAILED: ' . $e->getMessage());
        return null;
    }
}
