<?php
// Будильник на хостинге reg.ru (с 8.11.2026). Бесплатный GitHub запускает задачи
// по расписанию как получится и иногда пропускает их, поэтому время отсчитывает
// хостинг: его планировщик (cron в панели) раз в 30 минут вызывает этот файл,
// а файл просит GitHub запустить нужные задачи.
//
// Что запускается:
//   каждый раз                  сторож сайта (monitor.yml)
//   раз в день после 6:00 МСК   выкладка (deploy.yml), чтобы вышли статьи дня
//   раз в день после 8:10 МСК   проверка резервной копии (backup-check.yml)
//   раз в день после 8:20 МСК   утренний отчёт в рабочую группу (morning-report.yml)
//   в субботу после 9:45 МСК    выгрузка заявок (report.yml)
//
// Ключ GitHub лежит только в config.php на хостинге (секрет GH_DISPATCH_TOKEN).
// Вызов: из cron командой php, либо по адресу с ключом k (sha256 токена бота + 'tick').

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');

$cfg = is_file(__DIR__ . '/config.php') ? (include __DIR__ . '/config.php') : [];
$cli = PHP_SAPI === 'cli';
if (!$cli) {
    $bot = trim((string)($cfg['tg_token'] ?? ''));
    $key = $bot === '' ? '' : hash('sha256', $bot . 'tick');
    if ($key === '' || !hash_equals($key, (string)($_GET['k'] ?? $_POST['k'] ?? ''))) {
        http_response_code(404); echo "not found\n"; exit;
    }
}
// Бот заявок: напоминания о невзятых и вечерняя сводка в 22:00 (api/tg-bot.php)
try {
    require_once __DIR__ . '/tg-bot.php';
    $botLog = bot_tick();
    if ($botLog) {
        if (!is_dir(__DIR__ . '/data')) @mkdir(__DIR__ . '/data', 0700, true);
        file_put_contents(__DIR__ . '/data/tick.log', implode("\n", array_map(fn($x) => date('c') . ' ' . $x, $botLog)) . "\n", FILE_APPEND);
        echo implode("\n", $botLog) . "\n";
    }
} catch (Throwable $e) {
    echo 'бот: ' . $e->getMessage() . "\n";
}

$gh = trim((string)($cfg['gh_token'] ?? ''));
if ($gh === '') { echo "нет ключа GitHub (GH_DISPATCH_TOKEN)\n"; exit; }

$dir = __DIR__ . '/data';
if (!is_dir($dir)) @mkdir($dir, 0700, true);
$stateFile = $dir . '/tick.json';
$state = is_file($stateFile) ? (json_decode((string)file_get_contents($stateFile), true) ?: []) : [];

$now = new DateTime('now', new DateTimeZone('Europe/Moscow'));
$today = $now->format('Y-m-d');
$hm = (int)$now->format('Hi');
$dow = (int)$now->format('N'); // 6 = суббота

function dispatch($gh, $wf, $inputs = []) {
    $body = ['ref' => 'main'];
    if ($inputs) $body['inputs'] = $inputs;
    $ch = curl_init("https://api.github.com/repos/iamsitnikov-prog/agregatory-deploy/actions/workflows/$wf/dispatches");
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_HTTPHEADER => ["Authorization: Bearer $gh", 'Accept: application/vnd.github+json',
                               'X-GitHub-Api-Version: 2022-11-28', 'User-Agent: agregatory-tick',
                               'Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code === 204, $code . ' ' . substr((string)$resp, 0, 200)];
}

$log = [];
$run = function ($name, $wf, $inputs = []) use ($gh, &$log, &$state, $today) {
    [$ok, $info] = dispatch($gh, $wf, $inputs);
    $log[] = date('c') . " $name: " . ($ok ? 'запущено' : "ошибка $info");
    if ($ok && $name !== 'monitor') $state[$name] = $today;
    return $ok;
};
$due = function ($name, $after, $onlyDow = 0) use ($state, $today, $hm, $dow) {
    if ($onlyDow && $dow !== $onlyDow) return false;
    return $hm >= $after && ($state[$name] ?? '') !== $today;
};

$run('monitor', 'monitor.yml');
if ($due('deploy', 600)) $run('deploy', 'deploy.yml');
if ($due('backup', 810)) $run('backup', 'backup-check.yml');
if ($due('morning', 820)) $run('morning', 'morning-report.yml', ['test' => 'false']);
if ($due('leads', 945, 6)) $run('leads', 'report.yml');

file_put_contents($stateFile, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
file_put_contents($dir . '/tick.log', implode("\n", $log) . "\n", FILE_APPEND);
// журнал не даём разрастись: оставляем последние 400 строк
$lines = file($dir . '/tick.log');
if (count($lines) > 600) file_put_contents($dir . '/tick.log', implode('', array_slice($lines, -400)));
echo implode("\n", $log) . "\n";
