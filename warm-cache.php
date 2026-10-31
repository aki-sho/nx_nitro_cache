<?php

/* cron等から実行する、公開HTTPエンドポイントを持たないワーカー。 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$options = getopt('', ['root:', 'limit:']);
$root = realpath($options['root'] ?? dirname(__DIR__, 2));
if ($root === false || !is_dir($root . '/storage')) {
    fwrite(STDERR, "NexaPressのルートを --root=... で指定してください。\n");
    exit(1);
}
define('BASE_PATH', $root);
foreach (['Settings', 'CacheUrl', 'CacheManager', 'Warmup'] as $class) {
    require_once __DIR__ . '/includes/' . $class . '.php';
}
try {
    $stats = \NxNitroCache\Warmup::run((int)($options['limit'] ?? 10));
    echo json_encode([
        'pending' => $stats['pending'], 'completed' => $stats['completed'] ?? 0,
        'skipped' => $stats['skipped'] ?? 0, 'last_result' => $stats['last_result'] ?? '',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, "事前生成の処理に失敗しました。\n");
    exit(1);
}
