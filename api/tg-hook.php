<?php
// Сюда Telegram присылает нажатия кнопок под заявками и сообщения боту (см. api/tg-bot.php).
// Чужой запрос отсекается по секретному заголовку, который знает только Telegram.

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');

require __DIR__ . '/tg-bot.php';

$secret = (string)($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '');
if (bot_token() === '' || !hash_equals(bot_secret(), $secret)) { http_response_code(404); echo '{"error":"not found"}'; exit; }

$u = json_decode((string)file_get_contents('php://input'), true);
echo '{"ok":true}';
if (!is_array($u)) exit;
// Отвечаем Telegram сразу, чтобы он не присылал нажатие повторно, и работаем дальше
if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
ignore_user_abort(true);

try {
    if (isset($u['callback_query'])) hook_callback($u['callback_query']);
    elseif (isset($u['message'])) hook_message($u['message']);
} catch (Throwable $e) {
    error_log('TG HOOK: ' . $e->getMessage());
}

function hook_answer($cq, $text = '', $alert = false) {
    bot_api('answerCallbackQuery', ['callback_query_id' => $cq['id'], 'text' => $text, 'show_alert' => $alert ? 'true' : 'false']);
}
function hook_tg_name($from) {
    $n = trim((string)($from['first_name'] ?? '') . ' ' . (string)($from['last_name'] ?? ''));
    return $n !== '' ? $n : ('@' . (string)($from['username'] ?? 'без имени'));
}
function hook_tg_mention($from) {
    return '<a href="tg://user?id=' . (int)$from['id'] . '">' . bot_e(hook_tg_name($from)) . '</a>';
}

function hook_callback($cq) {
    $from = $cq['from'] ?? [];
    $msg = $cq['message'] ?? [];
    $chat = (string)($msg['chat']['id'] ?? '');
    $data = (string)($cq['data'] ?? '');
    if ($chat === '' || $chat !== bot_group()) { hook_answer($cq, 'Кнопки работают только в рабочей группе', true); return; }
    $p = explode(':', $data);
    $act = $p[0];
    $tgId = (string)($from['id'] ?? '');

    if ($act === 'd') { hook_answer($cq, 'Это образец. Под настоящей заявкой кнопка сработает.', true); return; }

    // «Кто ты в кабинете»: один раз для каждого
    if ($act === 'i') {
        $uid = (string)($p[1] ?? '');
        if (($p[2] ?? '') !== $tgId) { hook_answer($cq, 'Этот вопрос не тебе', true); return; }
        if (!isset(BOT_TEAM[$uid])) { hook_answer($cq); return; }
        // имя с известным ником может занять только этот ник
        if (isset(BOT_NICKS[$uid]) && strcasecmp((string)($from['username'] ?? ''), BOT_NICKS[$uid]) !== 0) { hook_answer($cq, BOT_TEAM[$uid] . ' это @' . BOT_NICKS[$uid] . '. Выбери своё имя.', true); return; }
        $res = bot_state_update(function (&$d) use ($uid, $tgId) {
            foreach (($d['ids'] ?? []) as $tg => $x) if ($x === $uid && (string)$tg !== $tgId) return 'busy';
            foreach (($d['users'] ?? []) as $x => $v) if ($x === $uid && (string)($v['chat'] ?? '') !== '' && (string)$v['chat'] !== $tgId) return 'busy';
            $d['ids'][$tgId] = $uid;
            return 'ok';
        });
        if ($res === 'busy') { hook_answer($cq, BOT_TEAM[$uid] . ' уже привязан(а) к другому аккаунту. Если это ошибка, напиши Юрию.', true); return; }
        hook_answer($cq, 'Готово');
        bot_api('editMessageText', ['chat_id' => $chat, 'message_id' => $msg['message_id'], 'parse_mode' => 'HTML',
            'text' => '✅ ' . hook_tg_mention($from) . ' в кабинете: <b>' . bot_e(BOT_TEAM[$uid]) . '</b>. Теперь кнопки под заявками работают, нажми ещё раз.']);
        return;
    }

    $me = bot_user_by_tg($tgId);
    if ($me === '') {
        hook_answer($cq, 'Сначала один раз скажи боту, кто ты. Вопрос ниже в чате.', true);
        $row = [];
        foreach (BOT_TEAM as $uid => $name) $row[] = ['text' => $name, 'callback_data' => "i:{$uid}:{$tgId}"];
        bot_send($chat, hook_tg_mention($from) . ', кто ты в кабинете? Нажми своё имя, это один раз.',
            ['reply_markup' => json_encode(['inline_keyboard' => [$row]], JSON_UNESCAPED_UNICODE)]);
        return;
    }

    if ($act === 'L') { hook_answer($cq); bot_send($chat, bot_refusals_text()); return; }

    $id = (int)($p[1] ?? 0);
    if ($id <= 0) { hook_answer($cq); return; }

    if ($act === 't') {
        $r = bot_take($id, $me);
        if (!$r) { hook_answer($cq, 'Заявка не найдена', true); return; }
        if (isset($r['error'])) { hook_answer($cq, $r['error'], true); bot_refresh($r['item']); return; }
        hook_answer($cq, 'Заявка твоя');
        bot_refresh($r['item']);
        return;
    }
    if ($act === 'r' || $act === 'b') {
        $l = bot_find($id);
        if (!$l) { hook_answer($cq, 'Заявка не найдена', true); return; }
        hook_answer($cq, $act === 'r' ? 'Выбери причину' : '');
        bot_refresh($l, $act === 'r' && $l['status'] !== 'refused' ? 'reasons' : '');
        return;
    }
    if ($act === 'x') {
        $reason = BOT_REASONS[(int)($p[2] ?? -1)] ?? '';
        if ($reason === '') { hook_answer($cq); return; }
        $r = bot_refuse($id, $me, $reason);
        if (!$r || isset($r['error'])) { hook_answer($cq, $r['error'] ?? 'Заявка не найдена', true); return; }
        hook_answer($cq, 'Отказ записан');
        bot_refresh($r['item']);
        return;
    }
    if ($act === 'c') {
        $l = bot_find($id);
        if (!$l) { hook_answer($cq, 'Заявка не найдена', true); return; }
        hook_answer($cq, 'Напиши причину ответом на сообщение бота');
        $mid = bot_send($chat, hook_tg_mention($from) . ", напиши причину отказа по заявке № {$id} ответом на это сообщение.",
            ['reply_markup' => json_encode(['force_reply' => true, 'selective' => true, 'input_field_placeholder' => 'Причина отказа'], JSON_UNESCAPED_UNICODE)]);
        if ($mid) bot_state_update(function (&$d) use ($mid, $id, $tgId) {
            foreach (($d['ask'] ?? []) as $k => $a) if (($a['at'] ?? 0) < time() - 86400) unset($d['ask'][$k]);
            $d['ask'][(string)$mid] = ['lead' => $id, 'tg' => $tgId, 'at' => time()];
        });
        bot_refresh($l);
        return;
    }
    if ($act === 'u') {
        $r = bot_unrefuse($id, $me);
        if (!$r) { hook_answer($cq, 'Заявка не найдена', true); return; }
        hook_answer($cq, 'Вернули в работу');
        bot_refresh($r['item']);
        return;
    }
    hook_answer($cq);
}

function hook_message($m) {
    $chat = (string)($m['chat']['id'] ?? '');
    $type = (string)($m['chat']['type'] ?? '');
    $text = trim((string)($m['text'] ?? ''));
    $from = $m['from'] ?? [];
    $tgId = (string)($from['id'] ?? '');

    // Личные уведомления кабинета: человек открыл бота по ссылке и нажал «Старт»
    if ($type === 'private') {
        if (preg_match('~^/pin(@\w+)?$~', $text)) { hook_pin($chat, $from); return; }
        if (preg_match('~^/start crm([0-9a-f]{12})$~', $text, $mm)) {
            $uid = bot_state_update(function (&$d) use ($mm, $chat) {
                foreach (($d['pending'] ?? []) as $id => $p) {
                    if (($p['code'] ?? '') === $mm[1] && ($p['at'] ?? 0) > time() - 86400) {
                        unset($d['pending'][$id]);
                        $d['users'][$id] = ['chat' => $chat, 'at' => crm_now()];
                        return $id;
                    }
                }
                return '';
            });
            if ($uid) bot_send($chat, 'Готово, ' . bot_e(BOT_TEAM[$uid] ?? '') . '! Сюда будут приходить заявки, которые передают тебе в кабинете agregatory.pro.');
            else bot_send($chat, 'Ссылка устарела. Открой кабинет и нажми «подключить уведомления» ещё раз.');
        }
        return;
    }
    if ($chat === '' || $chat !== bot_group()) return;

    // Своя причина отказа: ответ на вопрос бота
    $replyTo = (string)($m['reply_to_message']['message_id'] ?? '');
    if ($replyTo !== '' && $text !== '') {
        $ask = bot_state()['ask'][$replyTo] ?? null;
        if ($ask) {
            if ((string)$ask['tg'] !== $tgId) return;
            $me = bot_user_by_tg($tgId);
            if ($me === '') return;
            $r = bot_refuse((int)$ask['lead'], $me, $text);
            if ($r && !isset($r['error'])) {
                bot_state_update(function (&$d) use ($replyTo) { unset($d['ask'][$replyTo]); });
                bot_api('deleteMessage', ['chat_id' => $chat, 'message_id' => $replyTo]);
                bot_refresh($r['item']);
                bot_send($chat, "⚪ Отказ по заявке № {$ask['lead']} записан: " . bot_e($r['item']['reason']), ['reply_to_message_id' => $m['message_id'], 'allow_sending_without_reply' => 'true']);
            }
            return;
        }
    }

    if (preg_match('~^/(otkazy|zayavki)(@\w+)?(\s|$)~u', $text, $mm)) {
        bot_send($chat, $mm[1] === 'otkazy' ? bot_refusals_text() : bot_open_text());
    }
}

// /pin в личном чате: временный пин-код для кабинета. Бот шлёт его только самому человеку,
// в коде и в переписке с разработчиком пин-кодов нет.
function hook_pin($chat, $from) {
    require_once __DIR__ . '/docs-auth.php';
    $uid = bot_user_by_tg($chat);
    if ($uid === '' ) {
        foreach (BOT_NICKS as $id => $nick) if (strcasecmp((string)($from['username'] ?? ''), $nick) === 0) $uid = $id;
        if ($uid !== '') bot_state_update(function (&$d) use ($uid, $chat) { $d['ids'][(string)$chat] = $uid; });
    }
    if ($uid === '') { bot_send($chat, 'Не узнаю тебя. Нажми любую кнопку под заявкой в рабочей группе, бот спросит имя, и после этого напиши /pin ещё раз.'); return; }
    if (isset(BOT_NICKS[$uid]) && strcasecmp((string)($from['username'] ?? ''), BOT_NICKS[$uid]) !== 0) { bot_send($chat, 'Пин-код для этого кабинета выдаётся только аккаунту @' . BOT_NICKS[$uid] . '.'); return; }
    $pins = pins_read();
    $own = $pins[$uid] ?? null;
    if (!$own && tpl_key($uid) === '') {
        bot_send($chat, 'Сначала один раз войди в кабинет по своему нынешнему пин-коду: agregatory.pro/dokumenty. После этого /pin заработает. Не помнишь пин-код? Напиши Юрию.');
        return;
    }
    if (($own['temp']['at'] ?? 0) > time() - 600) { bot_send($chat, 'Временный пин-код я уже присылал меньше 10 минут назад, он выше в этом чате и ещё действует.'); return; }
    do { $pin = (string)random_int(100000, 999999); } while (preg_match('/^(\d)\1+$/', $pin) || strpos('0123456789', $pin) !== false || strpos('9876543210', $pin) !== false);
    $pins[$uid] = ($own ?: ['hash' => '', 'tk' => '', 'at' => date('c')]);
    $pins[$uid]['temp'] = ['hash' => password_hash($pin, PASSWORD_BCRYPT, ['cost' => 11]), 'at' => time()];
    pins_write($pins);
    bot_send($chat, "🔑 <b>Временный пин-код для кабинета</b>\n\n<code>{$pin}</code>\n\nДействует 24 часа. Войди на agregatory.pro/dokumenty, кабинет сразу попросит придумать свой пин-код: от 6 до 8 цифр.\n\nТвой прежний пин-код продолжает работать, пока ты не задашь новый.", ['protect_content' => 'true']);
}
