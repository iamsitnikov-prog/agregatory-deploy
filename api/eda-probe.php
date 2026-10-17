<?php
// Временный пробник: отдаёт ли Яндекс Еда карточку ресторана нашему серверу.
// Только чтение публичных страниц, без обхода защиты. Удалить после проверки.
header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex');

$slug = isset($_GET['slug']) ? $_GET['slug'] : 'tanuki';
if (!preg_match('/^[a-z0-9_\-]{2,80}$/i', $slug)) { echo json_encode(['error' => 'bad slug']); exit; }

function get($url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_ENCODING => '',
        CURLOPT_HTTPHEADER => [
            'User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Safari/537.36',
            'Accept: text/html,application/json;q=0.9,*/*;q=0.8',
            'Accept-Language: ru-RU,ru;q=0.9',
        ],
    ]);
    $body = curl_exec($ch);
    $info = curl_getinfo($ch);
    $err = curl_error($ch);
    curl_close($ch);
    return ['code' => $info['http_code'], 'final' => $info['url'], 'len' => strlen((string)$body), 'err' => $err, 'body' => (string)$body];
}

$out = ['slug' => $slug, 'time' => date('c')];

// Режим разведки: произвольный путь на eda.yandex.ru (только GET, только этот домен)
if (isset($_GET['path'])) {
    $path = $_GET['path'];
    if (!preg_match('#^/[A-Za-z0-9_\-/\.?=&%,]*$#', $path)) { echo json_encode(['error' => 'bad path']); exit; }
    $r = get('https://eda.yandex.ru' . $path);
    echo json_encode(['code' => $r['code'], 'final' => $r['final'], 'len' => $r['len'],
        'captcha' => stripos($r['final'], 'captcha') !== false,
        'head' => mb_substr($r['body'], 0, (int)($_GET['n'] ?? 3000))], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

$api = get("https://eda.yandex.ru/api/v2/menu/retrieve/$slug");
$a = ['code' => $api['code'], 'final' => $api['final'], 'len' => $api['len'], 'err' => $api['err'],
      'captcha' => stripos($api['final'] . $api['body'], 'captcha') !== false];
$j = json_decode($api['body'], true);
if (is_array($j)) {
    $p = isset($j['payload']) ? $j['payload'] : $j;
    $cats = isset($p['categories']) ? $p['categories'] : [];
    $items = [];
    foreach ($cats as $c) foreach (($c['items'] ?? []) as $it) $items[] = $it;
    $a['keys'] = array_keys($p);
    $a['categories'] = count($cats);
    $a['items'] = count($items);
    if ($items) $a['sample'] = $items[0];
} else {
    $a['head'] = mb_substr(strip_tags($api['body']), 0, 200);
}
$out['menu_api'] = $a;

$page = get("https://eda.yandex.ru/r/$slug");
$out['page'] = ['code' => $page['code'], 'final' => $page['final'], 'len' => $page['len'], 'err' => $page['err'],
                'captcha' => stripos($page['final'] . $page['body'], 'captcha') !== false,
                'title' => preg_match('#<title[^>]*>(.*?)</title>#su', $page['body'], $m) ? trim($m[1]) : ''];

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
