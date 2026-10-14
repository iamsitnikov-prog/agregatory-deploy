<?php
// Уведомления ЮKassa об оплате. Адрес для кабинета ЮKassa
// (Интеграция → HTTP-уведомления): https://agregatory.pro/api/yookassa.php
// события: payment.succeeded и payment.canceled.
//
// Уведомлению на слово не верим: берём из него только номер платежа и сами
// запрашиваем платёж у ЮKassa по секретному ключу. Подделать оплату так нельзя.

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require __DIR__ . '/pay-lib.php';

$cfg = pay_config();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') pay_reply(405, ['error' => 'Method not allowed', 'service' => 'yookassa']);
if (!pay_enabled($cfg)) pay_reply(503, ['error' => 'not_configured']);

$raw = file_get_contents('php://input');
if (strlen($raw) > 50000) pay_reply(413, ['error' => 'too large']);
$note = json_decode($raw ?: '{}', true);
$paymentId = (string)($note['object']['id'] ?? '');
$orderId = preg_replace('/[^a-f0-9]/', '', (string)($note['object']['metadata']['order_id'] ?? ''));

// Чужие и непонятные уведомления подтверждаем, чтобы ЮKassa их не повторяла.
if ($paymentId === '' || $orderId === '') pay_reply(200, ['ok' => true, 'skip' => 'no ids']);
$order = order_load($orderId);
if (!$order) pay_reply(200, ['ok' => true, 'skip' => 'unknown order']);

$p = yk_request($cfg, 'GET', 'payments/' . rawurlencode($paymentId));
if ($p['code'] !== 200 || !is_array($p['body'])) {
    // ЮKassa повторит уведомление позже.
    pay_reply(502, ['error' => 'cannot verify']);
}

$order = order_apply_payment($cfg, $order, $p['body']);
pay_reply(200, ['ok' => true, 'status' => $order['status']]);
