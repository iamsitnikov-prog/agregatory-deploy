<?php
// Общие функции оплаты для api/pay.php и api/yookassa.php.
// Снаружи этот файл ничего не выводит.

const SITE = 'https://agregatory.pro';
const YK_API = 'https://api.yookassa.ru/v3/';

// Каталог услуг с онлайн оплатой. Цена в рублях. Менять цены только здесь
// и в src/data/payments.ts сайта (там цены для показа на странице).
const PRODUCTS = [
    'konsultaciya' => [
        'title' => 'Консультация по доставке на агрегаторах, 60 минут',
        'price' => 20000,
    ],
    'audit' => [
        'title' => 'Аудит ресторана на агрегаторе',
        'price' => 30000,
    ],
    'kurs-komandy' => [
        'title' => 'Курс «Работа с агрегатором» для команды до 6 человек',
        'price' => 80000,
    ],
    'po-schetu' => [
        'title' => 'Оплата по счёту или договору',
        'price' => 0, // сумму вводит клиент
    ],
];

function pay_reply($code, $data) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function pay_config() {
    static $cfg = null;
    if ($cfg !== null) return $cfg;
    $cfg = [];
    if (is_file(__DIR__ . '/config.php')) {
        $loaded = include __DIR__ . '/config.php';
        if (is_array($loaded)) $cfg = $loaded;
    }
    return $cfg;
}

function pay_enabled($cfg) {
    return trim((string)($cfg['yk_shop_id'] ?? '')) !== ''
        && trim((string)($cfg['yk_secret'] ?? '')) !== ''
        && function_exists('curl_init');
}

function pay_dir() {
    $dir = __DIR__ . '/data/payments';
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    $guard = __DIR__ . '/data/.htaccess';
    if (!is_file($guard)) @file_put_contents($guard, "Require all denied\nDeny from all\n");
    return $dir;
}

function order_path($id) {
    return pay_dir() . '/' . preg_replace('/[^a-f0-9]/', '', $id) . '.json';
}

function order_load($id) {
    $p = order_path($id);
    if (!is_file($p)) return null;
    $o = json_decode((string)file_get_contents($p), true);
    return is_array($o) ? $o : null;
}

function order_save($order) {
    file_put_contents(order_path($order['id']), json_encode($order, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
}

// Журнал всех платежей: api/data/payments.csv (снаружи закрыт).
function pay_log($order, $event) {
    $csv = __DIR__ . '/data/payments.csv';
    pay_dir();
    $isNew = !is_file($csv);
    $out = @fopen($csv, 'a');
    if (!$out) return;
    flock($out, LOCK_EX);
    if ($isNew) {
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['дата МСК', 'событие', 'заказ', 'услуга', 'сумма', 'имя', 'телефон', 'почта', 'организация', 'счёт', 'платёж ЮKassa']);
    }
    $dt = new DateTime('now', new DateTimeZone('Europe/Moscow'));
    fputcsv($out, [$dt->format('Y-m-d H:i:s'), $event, $order['id'], $order['title'], $order['amount'],
        $order['name'], $order['phone'], $order['email'], $order['company'] ?? '', $order['invoice'] ?? '', $order['payment_id'] ?? '']);
    flock($out, LOCK_UN);
    fclose($out);
}

// Не больше 10 попыток оплаты за 10 минут с одного адреса.
function pay_rate_ok() {
    $ip = substr((string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['HTTP_X_REAL_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? ''), 0, 64);
    $ip = trim(explode(',', $ip)[0]);
    if ($ip === '') return true;
    $file = pay_dir() . '/rate.json';
    $fh = @fopen($file, 'c+');
    if (!$fh) return true;
    flock($fh, LOCK_EX);
    $now = time();
    $rate = json_decode(stream_get_contents($fh) ?: '{}', true) ?: [];
    foreach ($rate as $k => $list) {
        $rate[$k] = array_values(array_filter($list, fn($t) => $t > $now - 600));
        if (!$rate[$k]) unset($rate[$k]);
    }
    $ok = count($rate[$ip] ?? []) < 10;
    if ($ok) $rate[$ip][] = $now;
    ftruncate($fh, 0); rewind($fh);
    fwrite($fh, json_encode($rate));
    flock($fh, LOCK_UN); fclose($fh);
    return $ok;
}

function yk_request($cfg, $method, $path, $payload = null, $idemKey = null) {
    $ch = curl_init(((string)($cfg['yk_api'] ?? '') ?: YK_API) . $path); // yk_api — только для локальной проверки
    $headers = ['Content-Type: application/json'];
    if ($idemKey) $headers[] = 'Idempotence-Key: ' . $idemKey;
    $opts = [
        CURLOPT_USERPWD => trim($cfg['yk_shop_id']) . ':' . trim($cfg['yk_secret']),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 20,
    ];
    if ($method === 'POST') {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_UNICODE);
    }
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => json_decode((string)$raw, true)];
}

// Применяет статус платежа из ЮKassa к заказу и один раз сообщает об оплате.
function order_apply_payment($cfg, $order, $payment) {
    if (($payment['id'] ?? '') !== ($order['payment_id'] ?? '')) return $order;
    if (($payment['metadata']['order_id'] ?? '') !== $order['id']) return $order;
    $st = (string)($payment['status'] ?? '');
    $paidValue = (float)($payment['amount']['value'] ?? 0);

    if ($st === 'succeeded' && $paidValue >= $order['amount'] && $order['status'] !== 'paid') {
        $order['status'] = 'paid';
        $order['paid_at'] = time();
        order_save($order);
        pay_log($order, 'оплачен');
        if (empty($order['notified'])) {
            pay_notify($cfg, $order);
            $order['notified'] = true;
            order_save($order);
        }
    } elseif ($st === 'canceled' && $order['status'] === 'pending') {
        $order['status'] = 'canceled';
        $order['cancel_reason'] = (string)($payment['cancellation_details']['reason'] ?? '');
        order_save($order);
        pay_log($order, 'отменён: ' . $order['cancel_reason']);
    }
    return $order;
}

function pay_tg($token, $chat, $text) {
    foreach (['', '149.154.167.220', '149.154.167.99', '149.154.175.50'] as $ip) {
        $ch = curl_init("https://api.telegram.org/bot{$token}/sendMessage");
        $opts = [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query(['chat_id' => $chat, 'text' => $text, 'parse_mode' => 'HTML']),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 6,
        ];
        if ($ip !== '') $opts[CURLOPT_RESOLVE] = ["api.telegram.org:443:{$ip}"];
        curl_setopt_array($ch, $opts);
        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code === 200) return true;
        if ($code >= 400 && $code < 500) return false;
    }
    return false;
}

function pay_mail($to, $subject, $text) {
    $from = 'info@agregatory.pro';
    $fromName = '=?UTF-8?B?' . base64_encode('agregatory.pro') . '?=';
    $headers = "From: {$fromName} <{$from}>\r\nReply-To: {$from}\r\nMIME-Version: 1.0\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n";
    return @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', chunk_split(base64_encode($text)), $headers, "-f{$from}");
}

function pay_money($n) {
    return number_format($n, 0, ',', ' ') . ' ₽';
}

function pay_notify($cfg, $order) {
    $e = fn($s) => htmlspecialchars((string)$s, ENT_NOQUOTES, 'UTF-8');
    $lines = [
        '<b>Оплата на сайте</b>',
        '',
        "<b>Услуга:</b> {$e($order['title'])}",
        '<b>Сумма:</b> ' . pay_money($order['amount']),
        "<b>Имя:</b> {$e($order['name'])}",
        '<b>Телефон:</b> +' . $e($order['phone']),
        "<b>Почта:</b> {$e($order['email'])}",
    ];
    if (!empty($order['company'])) $lines[] = "<b>Организация:</b> {$e($order['company'])}";
    $lines[] = "<b>Заказ:</b> {$e($order['id'])}";
    $html = implode("\n", $lines);
    $plain = html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8');

    $token = trim((string)($cfg['tg_token'] ?? ''));
    $chats = array_filter(array_map('trim', explode(',', (string)($cfg['tg_chat'] ?? ''))));
    if ($token !== '') foreach ($chats as $chat) pay_tg($token, $chat, $html);

    $to = trim((string)($cfg['email_to'] ?? ''));
    if ($to === '') $to = base64_decode('aS5hbS5zaXRuaWtvdkB5YW5kZXgucnU=') . ', ' . base64_decode('aS5hbS5zaXRuaWtvdkBnbWFpbC5jb20=') . ', info@agregatory.pro';
    pay_mail($to, 'Оплата: ' . $order['title'] . ', ' . pay_money($order['amount']), $plain);

    // Письмо покупателю (кассовый чек ему отдельно присылает ЮKassa).
    $first = trim(explode(' ', $order['name'])[0]);
    pay_mail($order['email'], 'Оплата получена: ' . $order['title'],
        "Здравствуйте, {$first}!\n\n"
        . "Получили вашу оплату: {$order['title']}, " . pay_money($order['amount']) . ".\n"
        . "Номер заказа: {$order['id']}. Кассовый чек придёт отдельным письмом.\n\n"
        . "Свяжемся с вами в течение рабочего дня, чтобы договориться о времени.\n"
        . "Если удобнее написать самим: Telegram t.me/sitnikovy1, телефон +7 931 002-82-22.\n\n"
        . "Юрий Ситников, agregatory.pro");
}
