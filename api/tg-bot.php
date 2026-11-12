<?php
// Кнопки под заявками в рабочей группе Telegram (с 11.11.2026, решение Юрия).
//   «Взять заявку»      кто нажал, тот записан в кабинете как ведущий
//   «Отказ»             причина кнопкой или своим текстом (ответом на сообщение бота)
//   /otkazy             список отказов за 30 дней
//   вечером в 22:00     сводка дня и невзятые заявки
//   в рабочее время     напоминание, если заявка висит невзятой дольше часа
// Нажатия приходят в api/tg-hook.php. Время отсчитывает api/tick.php (cron хостинга, раз в 30 минут).
// Подключают: lead.php, crm.php, tick.php, tg-hook.php. Все имена с приставкой bot_, чтобы не пересекаться.

require_once __DIR__ . '/crm-lib.php';

const BOT_TEAM = ['lilia' => 'Лиля', 'alisa' => 'Алиса', 'yuri' => 'Юрий'];
// Ники в Telegram (назвал Юрий 11.11.2026): по ним бот тегает, пока человек сам не нажал кнопку и бот не узнал его аккаунт
const BOT_NICKS = ['alisa' => 'agregatoryandex', 'lilia' => 'LK728'];
const BOT_REASONS = ['Не дозвонились', 'Не наш профиль', 'Дорого', 'Передумал', 'Дубль', 'Спам'];
const BOT_HOOK_VERSION = 1;
const BOT_REPORT_AT = 2200;      // вечерняя сводка, МСК
const BOT_WORK_FROM = 1000;      // напоминания о невзятых только в это время, МСК
const BOT_WORK_TO = 2100;
const BOT_REMIND_FIRST = 3600;   // первое напоминание через час
const BOT_REMIND_NEXT = 7200;    // дальше раз в два часа

function bot_cfg() {
    static $c = null;
    if ($c === null) {
        $c = is_file(__DIR__ . '/config.php') ? (include __DIR__ . '/config.php') : [];
        if (!is_array($c)) $c = [];
    }
    return $c;
}
function bot_token() { return trim((string)(bot_cfg()['tg_token'] ?? '')); }
function bot_group() { return trim(explode(',', (string)(bot_cfg()['tg_chat'] ?? ''))[0] ?? ''); }
function bot_secret() { return substr(hash('sha256', bot_token() . 'tg-hook'), 0, 48); }
function bot_e($s) { return htmlspecialchars((string)$s, ENT_NOQUOTES, 'UTF-8'); }
function bot_msk() { return new DateTime('now', new DateTimeZone('Europe/Moscow')); }

function bot_api($method, $params = []) {
    $token = bot_token();
    if ($token === '' || !function_exists('curl_init')) return null;
    // 12.11.2026: связь хостинга с Telegram иногда рвётся (заявка № 24 ушла без кнопок).
    // Ждём дольше, пробуем другие адреса Telegram, но не дольше 25 секунд, чтобы форма не висела.
    $start = microtime(true);
    $errs = [];
    foreach (['', '149.154.167.220', '149.154.167.99', '149.154.175.50'] as $ip) {
        if (microtime(true) - $start > 25) break;
        $ch = curl_init("https://api.telegram.org/bot{$token}/{$method}");
        $opts = [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($params), CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_TIMEOUT => 10];
        if ($ip !== '') $opts[CURLOPT_RESOLVE] = ["api.telegram.org:443:{$ip}"];
        curl_setopt_array($ch, $opts);
        $r = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($code > 0) {
            $body = json_decode((string)$r, true);
            $GLOBALS['bot_last_err'] = $code === 200 ? '' : "ответ {$code}: " . mb_substr((string)($body['description'] ?? $r), 0, 150);
            return ['code' => $code, 'body' => $body];
        }
        $errs[] = ($ip ?: 'основной адрес') . ': ' . ($err ?: 'нет ответа');
    }
    $GLOBALS['bot_last_err'] = 'нет связи с Telegram (' . mb_substr(implode('; ', $errs), 0, 200) . ')';
    return null;
}
function bot_last_err() { return (string)($GLOBALS['bot_last_err'] ?? ''); }
function bot_send($chat, $html, $extra = []) {
    $r = bot_api('sendMessage', ['chat_id' => $chat, 'text' => $html, 'parse_mode' => 'HTML', 'disable_web_page_preview' => 'true'] + $extra);
    return ($r['code'] ?? 0) === 200 ? (int)($r['body']['result']['message_id'] ?? 0) : 0;
}

// --- Состояние бота: api/data/crm/tg.json (тот же файл, что у личных уведомлений кабинета) ---
function bot_state_update(callable $fn) {
    $fh = @fopen(crm_dir() . '/tg.json', 'c+');
    if (!$fh) return null;
    flock($fh, LOCK_EX);
    $d = json_decode(stream_get_contents($fh) ?: '', true);
    if (!is_array($d)) $d = [];
    $res = $fn($d);
    ftruncate($fh, 0); rewind($fh);
    fwrite($fh, json_encode($d, JSON_UNESCAPED_UNICODE));
    fflush($fh);
    flock($fh, LOCK_UN); fclose($fh);
    return $res;
}
function bot_state() { return @json_decode((string)@file_get_contents(crm_dir() . '/tg.json'), true) ?: []; }

// Кто из кабинета стоит за аккаунтом Telegram
function bot_user_by_tg($tgId) {
    $tgId = (string)$tgId;
    $st = bot_state();
    $id = (string)($st['ids'][$tgId] ?? '');
    if ($id !== '' && isset(BOT_TEAM[$id])) return $id;
    foreach (($st['users'] ?? []) as $uid => $u) {
        if ((string)($u['chat'] ?? '') === $tgId && isset(BOT_TEAM[$uid])) return (string)$uid;
    }
    return '';
}
function bot_tg_of($userId) {
    $st = bot_state();
    foreach (($st['ids'] ?? []) as $tg => $uid) if ($uid === $userId) return (string)$tg;
    $chat = (string)($st['users'][$userId]['chat'] ?? '');
    return $chat !== '' && $chat[0] !== '-' ? $chat : '';
}
// Упоминание, от которого у человека звенит телефон (если бот знает его аккаунт)
function bot_mention($userId) {
    $name = BOT_TEAM[$userId] ?? $userId;
    $tg = bot_tg_of($userId);
    if ($tg !== '') return "<a href=\"tg://user?id={$tg}\">" . bot_e($name) . '</a>';
    return isset(BOT_NICKS[$userId]) ? bot_e($name) . ' @' . BOT_NICKS[$userId] : bot_e($name);
}
function bot_mention_all() {
    return implode(', ', array_map('bot_mention', array_keys(BOT_TEAM)));
}

// --- Приём нажатий: Telegram сам присылает их на сайт ---
function bot_ensure_hook() {
    if (bot_token() === '') return false;
    if ((int)(bot_state()['hook'] ?? 0) === BOT_HOOK_VERSION) return true;
    $r = bot_api('setWebhook', [
        'url' => 'https://agregatory.pro/api/tg-hook.php',
        'secret_token' => bot_secret(),
        'allowed_updates' => json_encode(['message', 'callback_query']),
        'max_connections' => 5,
    ]);
    $ok = ($r['code'] ?? 0) === 200 && !empty($r['body']['ok']);
    if ($ok) {
        bot_api('setMyCommands', ['commands' => json_encode([
            ['command' => 'otkazy', 'description' => 'Отказы за 30 дней'],
            ['command' => 'zayavki', 'description' => 'Невзятые заявки'],
        ], JSON_UNESCAPED_UNICODE), 'scope' => json_encode(['type' => 'all_group_chats'])]);
        bot_state_update(function (&$d) { $d['hook'] = BOT_HOOK_VERSION; });
    }
    return $ok;
}

// --- Сообщение о заявке ---
function bot_is_open($l) { return ($l['status'] ?? '') === 'new' && ($l['owner'] ?? '') === ''; }
function bot_hm($at) { return substr((string)$at, 11, 5); }

function bot_lead_text($l) {
    $id = (int)$l['id'];
    if (($l['status'] ?? '') === 'refused') {
        $head = "⚪ <b>Заявка № {$id}</b> · отказ: " . bot_e($l['reason'] ?: 'причина не указана');
        if (!empty($l['tg']['by'])) $head .= ' (' . bot_e($l['tg']['by']) . ')';
    } elseif (($l['owner'] ?? '') !== '') {
        $head = "🟢 <b>Заявка № {$id}</b> · ведёт " . bot_e(BOT_TEAM[$l['owner']] ?? $l['owner']);
        if (!empty($l['tg']['took'])) $head .= ', с ' . bot_hm($l['tg']['took']);
        if (!in_array($l['status'], ['new', 'work'], true)) $head .= ' · ' . bot_e(crm_status_title($l['status']));
    } elseif (($l['status'] ?? '') !== 'new') {
        $head = "🟡 <b>Заявка № {$id}</b> · " . bot_e(crm_status_title($l['status'])) . ', никто не ведёт';
    } else {
        $head = "🔴🔴🔴 <b>НОВАЯ ЗАЯВКА № {$id}</b> 🔴🔴🔴";
    }
    $lines = [$head, '', '👤 <b>Имя:</b> ' . bot_e($l['name']), '📞 <b>Телефон:</b> ' . bot_e($l['phone'])];
    // Город: всегда строкой у заявок с формы и ручных; у чек-листов и оплат его не спрашиваем
    if (($l['city'] ?? '') !== '') $lines[] = '🏙 <b>Город:</b> ' . bot_e($l['city']);
    elseif (in_array($l['source'] ?? '', ['форма', 'вручную'], true)) $lines[] = '🏙 <b>Город:</b> не указан';
    if (($l['place'] ?? '') !== '') $lines[] = '<b>Заведение:</b> ' . bot_e($l['place']);
    if (($l['service'] ?? '') !== '') $lines[] = '<b>Услуга:</b> ' . bot_e($l['service']);
    if (($l['channel'] ?? '') !== '') $lines[] = '<b>Связь:</b> ' . bot_e($l['channel']);
    if (($l['comment'] ?? '') !== '') $lines[] = '<b>Комментарий:</b> ' . bot_e(mb_substr($l['comment'], 0, 1500));
    if (strpos((string)($l['page'] ?? ''), 'http') === 0) $lines[] = '<b>Страница:</b> ' . bot_e($l['page']);
    return implode("\n", $lines);
}
function bot_lead_kb($l, $mode = '') {
    $id = (int)$l['id'];
    $open = ['text' => '📂 В кабинете', 'url' => "https://agregatory.pro/dokumenty?tab=zayavki&id={$id}"];
    if ($mode === 'reasons') {
        $rows = [];
        foreach (array_chunk(BOT_REASONS, 2, true) as $pair) {
            $row = [];
            foreach ($pair as $k => $title) $row[] = ['text' => $title, 'callback_data' => "x:{$id}:{$k}"];
            $rows[] = $row;
        }
        $rows[] = [['text' => '✍️ Своя причина', 'callback_data' => "c:{$id}"], ['text' => '← Назад', 'callback_data' => "b:{$id}"]];
        return ['inline_keyboard' => $rows];
    }
    if (($l['status'] ?? '') === 'refused') {
        return ['inline_keyboard' => [[['text' => '↩️ Вернуть в работу', 'callback_data' => "u:{$id}"], $open]]];
    }
    $row = [];
    if (($l['owner'] ?? '') === '') $row[] = ['text' => '✋ Взять заявку', 'callback_data' => "t:{$id}"];
    $row[] = ['text' => '✖️ Отказ', 'callback_data' => "r:{$id}"];
    return ['inline_keyboard' => [$row, [$open]]];
}

// Новая заявка: отправляем в группу с кнопками и запоминаем номер сообщения
function bot_post_lead($l, $note = '', $tries = 2) {
    $chat = bot_group();
    if ($chat === '' || !$l) return false;
    bot_ensure_hook();
    $id = (int)$l['id'];
    $kb = ['reply_markup' => json_encode(bot_lead_kb($l), JSON_UNESCAPED_UNICODE)];
    $mid = bot_send($chat, bot_lead_text($l) . $note, $kb);
    if (!$mid && $tries > 1) { usleep(1500000); $mid = bot_send($chat, bot_lead_text($l) . $note, $kb); }
    $err = bot_last_err();
    crm_update(function (&$db) use ($id, $chat, $mid, $err) {
        foreach ($db['items'] as &$x) {
            if ((int)$x['id'] !== $id) continue;
            if ($mid) {
                $x['tg'] = ['chat' => $chat, 'mid' => $mid];
            } else {
                $x['tg']['fails'] = (int)($x['tg']['fails'] ?? 0) + 1;
                $x['history'][] = ['at' => crm_now(), 'who' => 'бот', 'text' => 'кнопки в Telegram не ушли: ' . ($err ?: 'причина неизвестна')];
            }
            break;
        }
    });
    return (bool)$mid;
}

// Досылка кнопок: невзятые заявки за сутки, которые ушли в группу без кнопок (сбой связи).
// Вызывается из bot_tick раз в 30 минут; на одну заявку не больше 6 попыток.
function bot_resend_missing() {
    $log = [];
    $from = bot_msk()->modify('-1 day')->format('Y-m-d H:i:s');
    foreach ((crm_read()['items'] ?? []) as $l) {
        if (!bot_is_open($l) || !empty($l['tg']['mid'])) continue;
        if (!in_array($l['source'] ?? '', ['форма', 'чек-лист'], true)) continue; // ручные заявки в группу не публикуются
        if ((string)$l['created'] < $from || (int)($l['tg']['fails'] ?? 0) >= 6) continue;
        $note = "\n\n<i>Пришла " . substr($l['created'], 8, 2) . '.' . substr($l['created'], 5, 2) . ' в ' . bot_hm($l['created'])
            . ', кнопки досланы после сбоя связи с Telegram</i>';
        $ok = bot_post_lead($l, $note, 1);
        $log[] = "бот: кнопки к заявке № {$l['id']} " . ($ok ? 'досланы' : 'снова не ушли: ' . bot_last_err());
        usleep(400000);
    }
    return $log;
}
// Заявку поменяли (кнопкой или в кабинете): обновляем её сообщение в группе
function bot_refresh($l, $mode = '') {
    if (empty($l['tg']['mid'])) return false;
    $r = bot_api('editMessageText', [
        'chat_id' => $l['tg']['chat'], 'message_id' => $l['tg']['mid'], 'text' => bot_lead_text($l), 'parse_mode' => 'HTML',
        'disable_web_page_preview' => 'true', 'reply_markup' => json_encode(bot_lead_kb($l, $mode), JSON_UNESCAPED_UNICODE),
    ]);
    return ($r['code'] ?? 0) === 200;
}

// --- Действия с заявкой ---
function bot_find($id) {
    $db = crm_read();
    foreach (($db['items'] ?? []) as $l) if ((int)$l['id'] === (int)$id) return $l;
    return null;
}
function bot_take($id, $userId) {
    $name = BOT_TEAM[$userId];
    return crm_update(function (&$db) use ($id, $userId, $name) {
        foreach ($db['items'] as &$l) {
            if ((int)$l['id'] !== (int)$id) continue;
            if (($l['owner'] ?? '') !== '') return ['error' => 'Заявку уже ведёт ' . (BOT_TEAM[$l['owner']] ?? $l['owner']), 'item' => $l];
            if (($l['status'] ?? '') === 'refused') return ['error' => 'По заявке уже отказ', 'item' => $l];
            $now = crm_now();
            $l['owner'] = $userId;
            $l['history'][] = ['at' => $now, 'who' => $name, 'text' => 'ведёт: ' . $name . ' (кнопка в Telegram)'];
            if ($l['status'] === 'new' && in_array('work', array_column(crm_statuses(), 'id'), true)) {
                $l['history'][] = ['at' => $now, 'who' => $name, 'text' => 'статус: ' . crm_status_title('new') . ' → ' . crm_status_title('work')];
                $l['status'] = 'work';
            }
            $l['updated'] = $now;
            $l['tg']['took'] = $now;
            return ['item' => $l];
        }
        return null;
    });
}
function bot_refuse($id, $userId, $reason) {
    $name = BOT_TEAM[$userId];
    $reason = mb_substr(trim(preg_replace('/\s+/u', ' ', (string)$reason)), 0, 200);
    if ($reason === '') return ['error' => 'Пустая причина'];
    return crm_update(function (&$db) use ($id, $name, $reason) {
        foreach ($db['items'] as &$l) {
            if ((int)$l['id'] !== (int)$id) continue;
            $now = crm_now();
            if ($l['status'] !== 'refused') {
                $l['history'][] = ['at' => $now, 'who' => $name, 'text' => 'статус: ' . crm_status_title($l['status']) . ' → ' . crm_status_title('refused')];
                $l['tg']['before'] = $l['status'];
                $l['status'] = 'refused';
            }
            $l['history'][] = ['at' => $now, 'who' => $name, 'text' => 'причина отказа: ' . $reason . ' (кнопка в Telegram)'];
            $l['reason'] = $reason;
            $l['updated'] = $now;
            $l['tg']['by'] = $name;
            $l['tg']['refused'] = $now;
            return ['item' => $l];
        }
        return null;
    });
}
function bot_unrefuse($id, $userId) {
    $name = BOT_TEAM[$userId];
    return crm_update(function (&$db) use ($id, $name) {
        foreach ($db['items'] as &$l) {
            if ((int)$l['id'] !== (int)$id) continue;
            if ($l['status'] !== 'refused') return ['item' => $l];
            $now = crm_now();
            $back = (string)($l['tg']['before'] ?? '');
            if ($back === '' || $back === 'refused' || !in_array($back, array_column(crm_statuses(), 'id'), true)) $back = ($l['owner'] ?? '') !== '' ? 'work' : 'new';
            if (!in_array($back, array_column(crm_statuses(), 'id'), true)) $back = 'new';
            $l['history'][] = ['at' => $now, 'who' => $name, 'text' => 'статус: ' . crm_status_title('refused') . ' → ' . crm_status_title($back) . ' (кнопка в Telegram)'];
            $l['status'] = $back;
            $l['reason'] = '';
            $l['updated'] = $now;
            unset($l['tg']['by'], $l['tg']['refused']);
            return ['item' => $l];
        }
        return null;
    });
}

// --- Списки ---
function bot_age($created) {
    $t = strtotime((string)$created . ' Europe/Moscow');
    $m = max(0, (int)((time() - $t) / 60));
    if ($m < 60) return "{$m} мин";
    if ($m < 1440) return intdiv($m, 60) . ' ч ' . ($m % 60 ? ($m % 60) . ' мин' : '');
    return intdiv($m, 1440) . ' дн';
}
function bot_short($l) {
    $t = "№ {$l['id']} · " . bot_e($l['name'] !== '' ? $l['name'] : $l['phone']);
    if (($l['place'] ?? '') !== '') $t .= ', ' . bot_e(mb_substr($l['place'], 0, 60));
    if (($l['city'] ?? '') !== '') $t .= ', ' . bot_e($l['city']);
    return $t;
}
// Невзятые: только те, что пришли уже с кнопками (старые из журнала считаем отдельно)
function bot_open_leads($db) {
    $fresh = []; $old = 0;
    foreach (($db['items'] ?? []) as $l) {
        if (!bot_is_open($l)) continue;
        if (!empty($l['tg']['mid'])) $fresh[] = $l; else $old++;
    }
    return [$fresh, $old];
}
function bot_refusals_text($days = 30) {
    $db = crm_read();
    $from = bot_msk()->modify("-{$days} days")->format('Y-m-d H:i:s');
    $rows = []; $by = [];
    foreach (array_reverse($db['items'] ?? []) as $l) {
        if (($l['status'] ?? '') !== 'refused') continue;
        $when = '';
        foreach (array_reverse($l['history'] ?? []) as $h) if (strpos($h['text'], 'причина отказа') === 0 || strpos($h['text'], '→ ' . crm_status_title('refused')) !== false) { $when = $h['at']; $who = $h['who']; break; }
        if ($when === '') $when = (string)($l['updated'] ?? $l['created']);
        if ($when < $from) continue;
        $reason = $l['reason'] !== '' ? $l['reason'] : 'причина не указана';
        $by[$reason] = ($by[$reason] ?? 0) + 1;
        $rows[] = substr($when, 8, 2) . '.' . substr($when, 5, 2) . ' · ' . bot_short($l) . "\n      <i>" . bot_e($reason) . '</i>' . (!empty($who) ? ' (' . bot_e($who) . ')' : '');
        unset($who);
    }
    if (!$rows) return "⚪ <b>Отказы за {$days} дней</b>\n\nОтказов нет.";
    arsort($by);
    $sum = [];
    foreach ($by as $r => $n) $sum[] = bot_e($r) . ": {$n}";
    $more = count($rows) > 40 ? "\n\nПоказаны последние 40 из " . count($rows) . ', остальные в кабинете.' : '';
    return "⚪ <b>Отказы за {$days} дней: " . count($rows) . "</b>\n" . implode(' · ', $sum) . "\n\n" . implode("\n", array_slice($rows, 0, 40)) . $more
        . "\n\nВсе отказы: https://agregatory.pro/dokumenty?tab=zayavki";
}
function bot_open_text() {
    [$fresh, $old] = bot_open_leads(crm_read());
    if (!$fresh && !$old) return '✅ Невзятых заявок нет.';
    $t = '🔴 <b>Невзятые заявки: ' . count($fresh) . '</b>';
    foreach ($fresh as $l) $t .= "\n" . bot_short($l) . ' · висит ' . bot_age($l['created']);
    if ($old) $t .= ($fresh ? "\n\n" : "\n") . "Старых заявок в статусе «Новая» без ведущего в кабинете: {$old}.";
    return $t;
}

// --- Вечерняя сводка ---
function bot_evening_text() {
    $db = crm_read();
    $today = bot_msk()->format('Y-m-d');
    $came = 0; $taken = []; $refused = [];
    // считаем по истории заявки, чтобы попадало и сделанное в кабинете, и сделанное кнопками
    foreach (($db['items'] ?? []) as $l) {
        if (strpos((string)$l['created'], $today) === 0) $came++;
        $tookBy = ''; $refAt = '';
        foreach (($l['history'] ?? []) as $h) {
            if (strpos((string)$h['at'], $today) !== 0) continue;
            if (strpos($h['text'], 'ведёт: ') === 0) $tookBy = trim(preg_replace('/\s*\(.*$/u', '', substr($h['text'], strlen('ведёт: '))));
            if (strpos($h['text'], 'причина отказа') === 0) $refAt = $h['at'];
        }
        if ($tookBy !== '' && ($l['owner'] ?? '') !== '') $taken[$tookBy] = ($taken[$tookBy] ?? 0) + 1;
        if ($refAt !== '' && ($l['status'] ?? '') === 'refused') $refused[] = "№ {$l['id']}: " . bot_e($l['reason']);
    }
    [$fresh, $old] = bot_open_leads($db);
    $t = '🌙 <b>Заявки за ' . bot_msk()->format('d.m') . "</b>\n\n📥 Пришло: {$came}";
    $tk = [];
    foreach ($taken as $n => $c) $tk[] = bot_e($n) . " {$c}";
    $t .= "\n✋ Взято: " . array_sum($taken) . ($tk ? ' (' . implode(', ', $tk) . ')' : '');
    $t .= "\n✖️ Отказов: " . count($refused) . ($refused ? "\n      " . implode("\n      ", $refused) : '');
    if ($fresh) {
        $t .= "\n\n🔴 <b>Висят невзятые: " . count($fresh) . '</b>';
        foreach ($fresh as $l) $t .= "\n" . bot_short($l) . ' · ' . bot_age($l['created']);
        $t .= "\n\n" . bot_mention_all() . ', кто берёт?';
    } else {
        $t .= "\n\n✅ Невзятых заявок нет.";
    }
    if ($old) $t .= "\n\nСтарых заявок в статусе «Новая» без ведущего в кабинете: {$old}.";
    return $t;
}

// Вызывается из tick.php раз в 30 минут
function bot_tick() {
    $log = [];
    if (bot_token() === '' || bot_group() === '') return $log;
    if (!bot_ensure_hook()) $log[] = 'бот: не удалось включить приём нажатий';
    try { foreach (bot_intro() as $x) $log[] = $x; } catch (Throwable $e) { $log[] = 'бот: памятка не ушла: ' . $e->getMessage(); }
    try { foreach (bot_resend_missing() as $x) $log[] = $x; } catch (Throwable $e) { $log[] = 'бот: досылка кнопок: ' . $e->getMessage(); }
    $now = bot_msk();
    $hm = (int)$now->format('Hi');
    $today = $now->format('Y-m-d');
    $st = bot_state();

    // напоминания о невзятых
    if ($hm >= BOT_WORK_FROM && $hm < BOT_WORK_TO) {
        [$fresh] = bot_open_leads(crm_read());
        foreach ($fresh as $l) {
            $age = time() - strtotime($l['created'] . ' Europe/Moscow');
            $last = (int)($l['tg']['reminded'] ?? 0);
            if ($age < BOT_REMIND_FIRST || ($last && time() - $last < BOT_REMIND_NEXT)) continue;
            $ok = bot_send($l['tg']['chat'], "⏰ Заявка № {$l['id']} висит невзятой " . trim(bot_age($l['created'])) . ".\n" . bot_mention_all() . ', кто берёт?',
                ['reply_to_message_id' => $l['tg']['mid'], 'allow_sending_without_reply' => 'true']);
            if ($ok) {
                $id = (int)$l['id'];
                crm_update(function (&$db) use ($id) {
                    foreach ($db['items'] as &$x) if ((int)$x['id'] === $id) { $x['tg']['reminded'] = time(); break; }
                });
                $log[] = "бот: напоминание по заявке № {$id}";
            }
        }
    }

    // вечерняя сводка
    if ($hm >= BOT_REPORT_AT && ($st['evening'] ?? '') !== $today) {
        $kb = ['inline_keyboard' => [[['text' => '⚪ Отказы за 30 дней', 'callback_data' => 'L']]]];
        if (bot_send(bot_group(), bot_evening_text(), ['reply_markup' => json_encode($kb, JSON_UNESCAPED_UNICODE)])) {
            bot_state_update(function (&$d) use ($today) { $d['evening'] = $today; });
            $log[] = 'бот: вечерняя сводка отправлена';
        } else {
            $log[] = 'бот: вечерняя сводка не ушла';
        }
    }
    return $log;
}

// --- Один раз (11.11.2026, просьба Юрия): памятка для группы, образец заявки и вчерашние заявки с кнопками ---
function bot_intro() {
    $chat = bot_group();
    if ($chat === '' || (int)(bot_state()['hook'] ?? 0) !== BOT_HOOK_VERSION) return [];
    $first = bot_state_update(function (&$d) { if (!empty($d['intro'])) return false; $d['intro'] = crm_now(); return true; });
    if (!$first) return [];
    $quiet = []; // со звуком (Юрий, 11.11)
    $log = [];

    $ok = bot_send($chat, "🆕 <b>Заявки теперь разбираем прямо здесь</b>\n\n"
        . "Алиса @agregatoryandex, Лиля @LK728, привет! Под каждой новой заявкой появились кнопки. В кабинет заходить не обязательно, всё само туда запишется.\n\n"
        . "🔴 <b>Красная шапка</b>\nЗаявку ещё никто не взял.\n\n"
        . "✋ <b>Взять заявку</b>\nКто нажал, тот её и ведёт. Шапка станет зелёной, и всем видно, чья заявка.\n\n"
        . "✖️ <b>Отказ</b>\nВыбери причину кнопкой. Нет подходящей? Нажми «Своя причина» и напиши её ответом боту.\n\n"
        . "⏰ <b>Напоминание</b>\nЗаявка висит без хозяина дольше часа? Бот напомнит. Только с 10 до 21.\n\n"
        . "🌙 <b>Сводка в 22:00</b>\nСколько пришло, кто что взял, какие отказы и что осталось висеть.\n\n"
        . "⌨️ <b>Команды</b>\n/zayavki  невзятые заявки\n/otkazy  отказы за 30 дней\n\n"
        . "☝️ При первом нажатии бот один раз спросит, кто ты. Нажми своё имя, и дальше всё работает.", $quiet);
    if (!$ok) { // попробуем ещё раз при следующей проверке
        bot_state_update(function (&$d) { unset($d['intro']); });
        return ['бот: памятка не ушла, повторю'];
    }
    $log[] = 'бот: памятка отправлена';
    usleep(400000);

    $demo = ['inline_keyboard' => [
        [['text' => '✋ Взять заявку', 'callback_data' => 'd'], ['text' => '✖️ Отказ', 'callback_data' => 'd']],
        [['text' => '📂 В кабинете', 'url' => 'https://agregatory.pro/dokumenty?tab=zayavki']],
    ]];
    bot_send($chat, "👇 <b>Так выглядит новая заявка</b>\n<i>Это образец, не настоящая заявка</i>\n\n"
        . "🔴🔴🔴 <b>НОВАЯ ЗАЯВКА № 00</b> 🔴🔴🔴\n\n"
        . "👤 <b>Имя:</b> Мария\n📞 <b>Телефон:</b> +7 900 000-00-00\n<b>Заведение:</b> Кафе «Пример», Казань\n"
        . "<b>Услуга:</b> Аудит кабинета\n<b>Связь:</b> Telegram\n<b>Комментарий:</b> Хотим поднять рейтинг\n\n"
        . "После «Взять заявку» шапка станет такой:\n🟢 <b>Заявка № 00</b> · ведёт Алиса, с 10:15\n\n"
        . "После отказа такой:\n⚪ <b>Заявка № 00</b> · отказ: Не дозвонились (Лиля)",
        $quiet + ['reply_markup' => json_encode($demo, JSON_UNESCAPED_UNICODE)]);
    usleep(400000);

    // заявки за вчера и за сегодня: невзятые выкладываем заново, уже с кнопками
    $y = bot_msk()->modify('-1 day')->format('Y-m-d');
    $db = crm_read();
    $open = []; $done = 0;
    foreach (($db['items'] ?? []) as $l) {
        if (substr((string)$l['created'], 0, 10) < $y) continue;
        if (bot_is_open($l) && empty($l['tg']['mid'])) $open[] = $l; elseif (!bot_is_open($l)) $done++;
    }
    $dm = substr($y, 8, 2) . '.' . substr($y, 5, 2);
    if (!$open) {
        bot_send($chat, "📋 <b>Заявки со вчера, {$dm}</b>\n\n✅ Неразобранных нет" . ($done ? ", {$done} уже в работе или закрыты" : '') . '. Новые будут приходить сюда уже с кнопками.', $quiet);
        $log[] = 'бот: вчерашних невзятых нет';
        return $log;
    }
    $n = count($open);
    $open = array_slice($open, -15);
    bot_send($chat, "📋 <b>Разберите, пожалуйста, заявки со вчера, {$dm}</b>\n\n"
        . "🔴 Без хозяина: <b>{$n}</b>" . ($done ? "\n🟢 Уже в работе или закрыты: {$done}" : '') . "\n\n"
        . "Они ниже, под каждой кнопки. Нажмите «Взять заявку» или «Отказ» на каждой, чтобы красных не осталось. Спасибо! 🙌", $quiet);
    $sent = 0;
    foreach ($open as $l) {
        usleep(400000);
        $mid = bot_send($chat, bot_lead_text($l) . "\n\n<i>Пришла " . substr($l['created'], 8, 2) . '.' . substr($l['created'], 5, 2) . ' в ' . bot_hm($l['created']) . '</i>',
            $quiet + ['reply_markup' => json_encode(bot_lead_kb($l), JSON_UNESCAPED_UNICODE)]);
        if (!$mid) continue;
        $id = (int)$l['id']; $sent++;
        crm_update(function (&$db) use ($id, $chat, $mid) {
            foreach ($db['items'] as &$x) if ((int)$x['id'] === $id) { $x['tg'] = ['chat' => $chat, 'mid' => $mid]; break; }
        });
    }
    $log[] = "бот: вчерашних заявок выложено {$sent} из {$n}";
    return $log;
}
