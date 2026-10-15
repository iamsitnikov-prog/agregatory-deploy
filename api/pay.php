<?php
// Онлайн оплата услуг agregatory.pro через ЮKassa (карта, СБП).
//
//   GET  /api/pay.php?check=1         → {"enabled": true|false}  (показывать ли кнопки оплаты)
//   POST /api/pay.php                 → создаёт платёж, отдаёт {"url": "<страница оплаты ЮKassa>"}
//   GET  /api/pay.php?order=<номер>   → статус заказа для страницы «Спасибо за оплату»
//
// Цены берутся только отсюда (PRODUCTS), из браузера приходит лишь код услуги:
// подменить сумму на странице нельзя. Подтверждение оплаты приходит в
// api/yookassa.php; этот файл тоже умеет сам спросить ЮKassa о статусе.
//
// Ключи магазина (yk_shop_id, yk_secret) приходят из api/config.php, который
// выкладка собирает из секретов GitHub YK_SHOP_ID и YK_SECRET_KEY.
// Пока ключей нет, оплата выключена: кнопки на сайте не показываются.

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require __DIR__ . '/pay-lib.php';

$cfg = pay_config();
$enabled = pay_enabled($cfg);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET' && isset($_GET['check'])) {
    pay_reply(200, ['enabled' => $enabled, 'products' => array_map(
        fn($p) => ['title' => $p['title'], 'price' => $p['price']],
        PRODUCTS
    )]);
}

if ($method === 'GET' && isset($_GET['order'])) {
    $id = preg_replace('/[^a-f0-9]/', '', (string)$_GET['order']);
    $order = $id !== '' ? order_load($id) : null;
    if (!$order) pay_reply(404, ['error' => 'not found']);
    if ($enabled && ($order['status'] ?? '') === 'pending' && !empty($order['payment_id'])) {
        $p = yk_request($cfg, 'GET', 'payments/' . rawurlencode($order['payment_id']));
        if ($p['code'] === 200 && is_array($p['body'])) $order = order_apply_payment($cfg, $order, $p['body']);
    }
    pay_reply(200, [
        'status' => $order['status'],
        'title' => $order['title'],
        'amount' => $order['amount'],
        'name' => $order['name'],
    ]);
}

if ($method !== 'POST') pay_reply(405, ['error' => 'Method not allowed', 'service' => 'pay']);
if (!$enabled) pay_reply(503, ['error' => 'not_configured']);

$raw = file_get_contents('php://input');
if (strlen($raw) > 10000) pay_reply(413, ['error' => 'too large']);
$body = json_decode($raw ?: '{}', true);
if (!is_array($body)) $body = [];
$f = function ($k, $max = 300) use ($body) {
    return mb_substr(trim((string)($body[$k] ?? '')), 0, $max);
};

if ($f('website') !== '') pay_reply(200, ['ok' => true]); // ловушка для роботов

$code = $f('product', 40);
$name = $f('name', 120);
$phone = preg_replace('/\D/', '', $f('phone', 40));
$email = $f('email', 120);
$company = ''; // онлайн оплата только для физических лиц
$invoice = $f('invoice', 100);
$agree = !empty($body['agree']);

if (!isset(PRODUCTS[$code])) pay_reply(400, ['error' => 'Выберите услугу']);
if (mb_strlen($name) < 2) pay_reply(400, ['error' => 'Укажите имя']);
if (strlen($phone) !== 11) pay_reply(400, ['error' => 'Укажите телефон полностью']);
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) pay_reply(400, ['error' => 'Укажите почту: на неё придёт чек']);
if (!$agree) pay_reply(400, ['error' => 'Нужно согласие с офертой']);

$product = PRODUCTS[$code];
$amount = $product['price'];
$title = $product['title'];

if (!pay_rate_ok()) pay_reply(429, ['error' => 'Слишком много попыток, попробуйте через 10 минут']);

$id = bin2hex(random_bytes(8));
$order = [
    'id' => $id,
    'created' => time(),
    'product' => $code,
    'title' => $title,
    'amount' => $amount,
    'name' => $name,
    'phone' => $phone,
    'email' => $email,
    'company' => $company,
    'invoice' => $invoice,
    'page' => $f('page', 300),
    'status' => 'pending',
    'payment_id' => '',
    'notified' => false,
];

$value = number_format($amount, 2, '.', '');
$desc = mb_substr($title, 0, 128);
$res = yk_request($cfg, 'POST', 'payments', [
    'amount' => ['value' => $value, 'currency' => 'RUB'],
    'capture' => true,
    'confirmation' => [
        'type' => 'redirect',
        'return_url' => SITE . '/oplata/spasibo?order=' . $id,
    ],
    'description' => mb_substr("Заказ {$id}: {$desc}", 0, 128),
    'metadata' => ['order_id' => $id],
    'receipt' => [
        'customer' => array_filter([
            'full_name' => $name,
            'email' => $email,
            'phone' => $phone,
        ]),
        'items' => [[
            'description' => $desc,
            'quantity' => '1.00',
            'amount' => ['value' => $value, 'currency' => 'RUB'],
            'vat_code' => (int)($cfg['yk_vat_code'] ?? 1), // 1 = без НДС
            'payment_mode' => 'full_prepayment',
            'payment_subject' => 'service',
        ]],
    ],
], $id);

$url = $res['body']['confirmation']['confirmation_url'] ?? '';
if ($res['code'] !== 200 || $url === '') {
    error_log('PAY CREATE FAILED ' . $res['code'] . ' ' . json_encode($res['body'], JSON_UNESCAPED_UNICODE));
    pay_reply(502, ['error' => 'Не удалось создать платёж. Попробуйте ещё раз или позвоните: +7 931 002-82-22']);
}

$order['payment_id'] = (string)$res['body']['id'];
order_save($order);
pay_log($order, 'создан');

pay_reply(200, ['ok' => true, 'url' => $url, 'order' => $id]);
