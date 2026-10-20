<?php
// Раздел «Заявки» в кабинете agregatory.pro/dokumenty (CRM).
// Вход по тем же пин-кодам, что и договоры (api/docs-auth.php), пин в заголовке X-Docs-Key.
//   GET  ?a=list                 все заявки, статусы, сотрудники, подключён ли Telegram
//   POST ?a=update               id + status / owner / note: меняет заявку, пишет историю
//   POST ?a=add                  заявка вручную (звонок, сообщение)
//   POST ?a=tglink               ссылка на бота, чтобы получать уведомления лично
//   POST ?a=tgcheck              проверить, нажал ли человек «Старт» у бота
//   POST ?a=tgoff                отключить личные уведомления
// Видят все заявки все сотрудники кабинета (Лиля, Алиса, Юрий).

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');

require __DIR__ . '/docs-auth.php';
require __DIR__ . '/crm-lib.php';

function out($code, $data) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
function cfg() {
    $c = is_file(__DIR__ . '/config.php') ? (include __DIR__ . '/config.php') : [];
    return is_array($c) ? $c : [];
}

$dataDir = __DIR__ . '/data';
if (!is_dir($dataDir . '/docs')) @mkdir($dataDir . '/docs', 0750, true);
crm_dir();

auth();
$a = (string)($_GET['a'] ?? '');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$body = json_decode((string)file_get_contents('php://input') ?: '{}', true);
if (!is_array($body)) $body = [];
$in = fn($k, $max = 1000) => mb_substr(trim((string)($body[$k] ?? '')), 0, $max);

$NAMES = [];
foreach (DOCS_USERS as $u) $NAMES[$u['id']] = $u['name'];
// Порядок в кабинете: Лиля, Алиса, Юрий
$TEAM = DOCS_USERS;
$ord = ['lilia' => 0, 'alisa' => 1, 'yuri' => 2];
usort($TEAM, fn($x, $y) => ($ord[$x['id']] ?? 9) <=> ($ord[$y['id']] ?? 9));

// --- Telegram -----------------------------------------------------------------
function tg_file() { return crm_dir() . '/tg.json'; }
function tg_read() { return @json_decode((string)@file_get_contents(tg_file()), true) ?: []; }
function tg_write($d) { @file_put_contents(tg_file(), json_encode($d, JSON_UNESCAPED_UNICODE), LOCK_EX); }
function tg_api($method, $params = []) {
    $token = trim((string)(cfg()['tg_token'] ?? ''));
    if ($token === '' || !function_exists('curl_init')) return null;
    foreach (['', '149.154.167.220', '149.154.167.99', '149.154.175.50'] as $ip) {
        $ch = curl_init("https://api.telegram.org/bot{$token}/{$method}");
        $opts = [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($params), CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 8];
        if ($ip !== '') $opts[CURLOPT_RESOLVE] = ["api.telegram.org:443:{$ip}"];
        curl_setopt_array($ch, $opts);
        $r = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code > 0) return ['code' => $code, 'body' => json_decode((string)$r, true)];
    }
    return null;
}
function tg_dm($userId, $html) {
    $chat = tg_read()['users'][$userId]['chat'] ?? '';
    if ($chat === '') return false;
    $r = tg_api('sendMessage', ['chat_id' => $chat, 'text' => $html, 'parse_mode' => 'HTML', 'disable_web_page_preview' => 'true']);
    return ($r['code'] ?? 0) === 200;
}
function lead_line($l) {
    $e = fn($s) => htmlspecialchars((string)$s, ENT_NOQUOTES, 'UTF-8');
    $t = "<b>Заявка № {$l['id']}</b>: {$e($l['name'])}, {$e($l['phone'])}";
    if ($l['place'] !== '') $t .= "\nЗаведение: {$e($l['place'])}";
    if ($l['service'] !== '') $t .= "\nУслуга: {$e($l['service'])}";
    if ($l['comment'] !== '') $t .= "\nКомментарий: {$e(mb_substr($l['comment'], 0, 400))}";
    return $t . "\n\nОткрыть: https://agregatory.pro/dokumenty?tab=zayavki&id={$l['id']}";
}

function public_item($l) {
    unset($l['page_raw']);
    return $l;
}

if ($a === 'list') {
    $db = crm_read();
    if ($db === null) out(500, ['error' => 'База заявок недоступна']);
    $tg = tg_read();
    out(200, [
        'items' => array_map('public_item', array_reverse($db['items'])),
        'statuses' => crm_statuses(),
        'system' => CRM_SYSTEM,
        'users' => array_map(fn($u) => ['id' => $u['id'], 'name' => $u['name'],
            'tg' => !empty($tg['users'][$u['id']]['chat'])], $TEAM),
        'me' => ['id' => $USER['id'], 'name' => $USER['name'], 'admin' => !empty($USER['admin'])],
        'tg_ready' => trim((string)(cfg()['tg_token'] ?? '')) !== '',
    ]);
}

if ($method !== 'POST') out(405, ['error' => 'Нужен POST']);

if ($a === 'update') {
    $id = (int)($body['id'] ?? 0);
    $status = $in('status', 20);
    $owner = array_key_exists('owner', $body) ? $in('owner', 20) : null;
    $note = $in('note', 3000);
    $channel = array_key_exists('channel', $body) ? $in('channel', 50) : null;
    $reason = array_key_exists('reason', $body) ? preg_replace('/\s+/u', ' ', $in('reason', 200)) : null;
    $valid = array_column(crm_statuses(), 'id');
    if ($status !== '' && !in_array($status, $valid, true)) out(400, ['error' => 'Нет такого статуса']);
    if ($owner !== null && $owner !== '' && !isset($NAMES[$owner])) out(400, ['error' => 'Нет такого сотрудника']);
    $me = $USER;
    $res = crm_update(function (&$db) use ($id, $status, $owner, $note, $reason, $channel, $me, $NAMES) {
        foreach ($db['items'] as &$l) {
            if ((int)$l['id'] !== $id) continue;
            $now = crm_now();
            $was = $l['owner'];
            $l['reason'] = (string)($l['reason'] ?? '');
            $toRefused = $status === 'refused' || ($status === '' && $l['status'] === 'refused');
            if ($status === 'refused' && $l['status'] !== 'refused' && ($reason ?? '') === '') return ['error' => 'Укажи причину отказа'];
            if ($reason !== null && $toRefused && $reason !== $l['reason']) {
                if ($reason === '') return ['error' => 'Укажи причину отказа'];
                $l['history'][] = ['at' => crm_now(), 'who' => $me['name'], 'text' => 'причина отказа: ' . $reason];
                $l['reason'] = $reason;
            }
            if ($status !== '' && $status !== 'refused') $l['reason'] = ''; // вернули в работу: причина больше не актуальна
            if ($status !== '' && $status !== $l['status']) {
                $l['history'][] = ['at' => $now, 'who' => $me['name'],
                    'text' => 'статус: ' . crm_status_title($l['status']) . ' → ' . crm_status_title($status)];
                $l['status'] = $status;
            }
            if ($owner !== null && $owner !== $l['owner']) {
                $l['history'][] = ['at' => $now, 'who' => $me['name'],
                    'text' => $owner === '' ? 'больше никто не ведёт' : 'ведёт: ' . $NAMES[$owner]];
                $l['owner'] = $owner;
            }
            if ($channel !== null && $channel !== '' && $channel !== $l['channel']) {
                $l['history'][] = ['at' => $now, 'who' => $me['name'], 'text' => 'связь: ' . ($l['channel'] ?: 'не указана') . ' → ' . $channel];
                $l['channel'] = $channel;
            }
            if ($note !== '') {
                $l['notes'][] = ['at' => $now, 'who' => $me['name'], 'text' => $note];
            }
            $l['updated'] = $now;
            return ['item' => $l, 'assigned' => $owner !== null && $owner !== '' && $owner !== $was ? $owner : ''];
        }
        unset($l);
        return null;
    });
    if (!$res) out(404, ['error' => 'Заявка не найдена']);
    if (isset($res['error'])) out(400, ['error' => $res['error']]);
    $notified = false;
    if ($res['assigned'] !== '' && $res['assigned'] !== $USER['id']) {
        $notified = tg_dm($res['assigned'], "{$USER['name']} передаёт тебе заявку\n\n" . lead_line($res['item']));
    }
    out(200, ['ok' => true, 'item' => public_item($res['item']), 'notified' => $notified]);
}

// --- Этапы: добавить в любое место, переименовать, переставить, удалить -----
if ($a === 'stages') {
    $op = $in('op', 20);
    $list = crm_statuses();
    $ids = array_column($list, 'id');
    $id = $in('id', 20);
    $pos = array_search($id, $ids, true);
    $title = preg_replace('/\s+/u', ' ', $in('title', 40));
    $clash = fn($t, $skip = '') => (bool)array_filter($list, fn($x) => $x['id'] !== $skip && mb_strtolower($x['title']) === mb_strtolower($t));
    $who = $USER['name'];
    $log = '';
    if ($op === 'add') {
        if ($title === '') out(400, ['error' => 'Напиши название этапа']);
        if ($clash($title)) out(400, ['error' => 'Такой этап уже есть']);
        if (count($list) >= 20) out(400, ['error' => 'Этапов уже 20, больше не нужно']);
        $after = $in('after', 20);
        $at = $after === '' ? 0 : array_search($after, $ids, true);
        if ($at === false) out(400, ['error' => 'Нет этапа, после которого вставить']);
        $at = $after === '' ? 0 : $at + 1;
        array_splice($list, $at, 0, [['id' => 's' . bin2hex(random_bytes(3)), 'title' => $title]]);
        $log = "добавлен этап «{$title}»";
    } elseif ($pos === false) {
        out(404, ['error' => 'Нет такого этапа']);
    } elseif ($op === 'rename') {
        if ($title === '') out(400, ['error' => 'Напиши название этапа']);
        if ($clash($title, $id)) out(400, ['error' => 'Такой этап уже есть']);
        $log = "этап «{$list[$pos]['title']}» переименован в «{$title}»";
        $list[$pos]['title'] = $title;
    } elseif ($op === 'move') {
        $to = $pos + ((int)($body['dir'] ?? 0) < 0 ? -1 : 1);
        if ($to < 0 || $to >= count($list)) out(400, ['error' => 'Дальше двигать некуда']);
        [$list[$pos], $list[$to]] = [$list[$to], $list[$pos]];
    } elseif ($op === 'delete') {
        if (in_array($id, CRM_SYSTEM, true)) out(400, ['error' => 'Этот этап нужен сайту: в него заявки попадают сами. Его можно переименовать, но не удалить']);
        $move = $in('to', 20);
        $gone = $list[$pos]['title'];
        if ($move === $id || ($move !== '' && !in_array($move, $ids, true))) out(400, ['error' => 'Выбери, куда перенести заявки']);
        $n = crm_update(function (&$db) use ($id, $move, $gone, $who) {
            $c = 0;
            foreach ($db['items'] as &$l) if ($l['status'] === $id) $c++;
            unset($l);
            if ($c && $move === '') return -$c;
            foreach ($db['items'] as &$l) {
                if ($l['status'] !== $id) continue;
                $l['history'][] = ['at' => crm_now(), 'who' => $who, 'text' => "этап «{$gone}» удалён, заявка перенесена в «" . crm_status_title($move) . '»'];
                $l['status'] = $move;
                $l['updated'] = crm_now();
            }
            unset($l);
            return $c;
        });
        if ($n < 0) out(409, ['error' => 'В этапе есть заявки (' . -$n . '). Выбери, куда их перенести', 'count' => -$n]);
        array_splice($list, $pos, 1);
        $log = "удалён этап «{$gone}»" . ($n ? ", заявок перенесено: {$n}" : '');
    } else {
        out(400, ['error' => 'Неизвестное действие с этапом']);
    }
    if (!crm_statuses_save($list)) out(500, ['error' => 'Не удалось сохранить этапы']);
    if ($log !== '') {
        $f = crm_dir() . '/stages-log.json';
        $j = @json_decode((string)@file_get_contents($f), true) ?: [];
        $j[] = ['at' => crm_now(), 'who' => $who, 'text' => $log];
        @file_put_contents($f, json_encode(array_slice($j, -200), JSON_UNESCAPED_UNICODE), LOCK_EX);
    }
    out(200, ['ok' => true, 'statuses' => $list]);
}

if ($a === 'add') {
    $name = $in('name', 200);
    $phone = $in('phone', 50);
    if ($name === '' && $phone === '') out(400, ['error' => 'Нужно имя или телефон']);
    $owner = $in('owner', 20);
    if ($owner !== '' && !isset($NAMES[$owner])) $owner = '';
    $me = $USER;
    $item = crm_update(function (&$db) use ($in, $name, $phone, $owner, $me, $NAMES) {
        $l = crm_new_item($db, [
            'name' => $name, 'phone' => $phone, 'email' => $in('email', 120), 'place' => $in('place', 300),
            'service' => $in('service', 200), 'channel' => $in('channel', 50), 'comment' => $in('comment', 3000),
            'source' => 'вручную', 'who' => $me['name'],
        ], 'new', 'добавлена вручную');
        if ($owner !== '') {
            $k = count($db['items']) - 1;
            $db['items'][$k]['owner'] = $owner;
            $db['items'][$k]['history'][] = ['at' => crm_now(), 'who' => $me['name'], 'text' => 'ведёт: ' . $NAMES[$owner]];
            $l = $db['items'][$k];
        }
        return $l;
    });
    if (!$item) out(500, ['error' => 'Не удалось сохранить']);
    if ($owner !== '' && $owner !== $USER['id']) tg_dm($owner, "{$USER['name']} передаёт тебе заявку\n\n" . lead_line($item));
    out(200, ['ok' => true, 'item' => public_item($item)]);
}

// --- Личные уведомления: человек открывает бота по ссылке и жмёт «Старт» ----
if ($a === 'tglink') {
    $me = tg_api('getMe');
    $bot = $me['body']['result']['username'] ?? '';
    if ($bot === '') out(502, ['error' => 'Бот заявок сейчас не отвечает, попробуй позже']);
    $code = bin2hex(random_bytes(6));
    $tg = tg_read();
    $tg['pending'][$USER['id']] = ['code' => $code, 'at' => time()];
    tg_write($tg);
    out(200, ['ok' => true, 'url' => "https://t.me/{$bot}?start=crm{$code}"]);
}

if ($a === 'tgcheck') {
    $tg = tg_read();
    $p = $tg['pending'][$USER['id']] ?? null;
    if (!$p || $p['at'] < time() - 86400) out(400, ['error' => 'Сначала нажми «подключить уведомления»']);
    $r = tg_api('getUpdates', ['limit' => 100, 'allowed_updates' => json_encode(['message'])]);
    if (($r['code'] ?? 0) === 409) out(502, ['error' => 'Бот занят другой программой, личные уведомления так не подключить. Напиши Юрию.']);
    if (($r['code'] ?? 0) !== 200) out(502, ['error' => 'Бот заявок сейчас не отвечает, попробуй через минуту']);
    $chat = '';
    foreach ($r['body']['result'] ?? [] as $u) {
        $m = $u['message'] ?? [];
        if (trim((string)($m['text'] ?? '')) === '/start crm' . $p['code'] && ($m['chat']['type'] ?? '') === 'private') {
            $chat = (string)$m['chat']['id'];
        }
    }
    if ($chat === '') out(200, ['ok' => false, 'error' => 'Бот пока не видит «Старт». Открой ссылку, нажми «Старт» и проверь ещё раз']);
    unset($tg['pending'][$USER['id']]);
    $tg['users'][$USER['id']] = ['chat' => $chat, 'at' => crm_now()];
    tg_write($tg);
    tg_dm($USER['id'], "Готово, {$USER['name']}! Сюда будут приходить заявки, которые передают тебе в кабинете agregatory.pro.");
    out(200, ['ok' => true]);
}

if ($a === 'tgoff') {
    $tg = tg_read();
    unset($tg['users'][$USER['id']]);
    tg_write($tg);
    out(200, ['ok' => true]);
}

out(400, ['error' => 'Неизвестное действие']);
