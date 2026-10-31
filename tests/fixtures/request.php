<?php

require __DIR__ . '/core.php';
define('BASE_PATH', getenv('NX_CACHE_TEST_ROOT'));
define('BASE_URL', getenv('NX_CACHE_TEST_BASE') ?: '');
require __DIR__ . '/models.php';
$project = dirname(__DIR__, 2);
foreach (['Settings', 'CacheUrl', 'CacheManager', 'Dependencies', 'Metrics', 'Warmup', 'CacheCleaner', 'PageCache', 'SettingsController'] as $class) {
    require $project . '/includes/' . $class . '.php';
}

function add_action(string $event, callable $callback, int $priority = 10): void
{
    $GLOBALS['test_hooks'][$event][$priority][] = $callback;
}
function do_action(string $event, mixed ...$arguments): void
{
    $priorities = $GLOBALS['test_hooks'][$event] ?? [];
    ksort($priorities);
    foreach ($priorities as $callbacks) {
        foreach ($callbacks as $callback) { $callback(...$arguments); }
    }
}
function redirect_to(string $path): void
{
    header('Location: /' . $path, true, 302);
    exit;
}

$_SESSION = [];
$case = $_SERVER['HTTP_X_TEST_CASE'] ?? 'public';
if (!empty($_SERVER['HTTP_X_TEST_AUTH'])) {
    $_SESSION['user'] = ['id' => 7, 'role' => $_SERVER['HTTP_X_TEST_ROLE'] ?? 'admin'];
}
if ($case === 'session') {
    $_SESSION['member'] = ['secret' => 'private'];
}
if ($case === 'controller-clear') {
    (new \NxNitroCache\SettingsController())->clearCache();
    exit;
}
if ($case === 'controller-action') {
    $methods = [
        'delete' => 'deleteUrls', 'queue' => 'queueWarmup', 'run' => 'runWarmup',
        'cancel' => 'cancelWarmup', 'reset' => 'resetMetrics', 'save' => 'save',
    ];
    $method = $methods[$_SERVER['HTTP_X_TEST_ACTION'] ?? ''] ?? null;
    if ($method === null) { http_response_code(404); exit; }
    (new \NxNitroCache\SettingsController())->$method();
    exit;
}
if ($case === 'dashboard') {
    require $project . '/admin/dashboard.php';
    exit;
}

if ($case === 'cleaner') {
    \NxNitroCache\CacheCleaner::boot();
    if (!empty($_SERVER['HTTP_X_TEST_COMPAT_SHIM'])) {
        \app\Core\Extension::registerLegacyCacheBridge();
    }
    if (!empty($_SERVER['HTTP_X_TEST_MODEL_MUTATE'])) {
        $file = BASE_PATH . '/model-state.json';
        $models = json_decode(file_get_contents($file), true);
        $models['posts'][0]['slug'] = $_SERVER['HTTP_X_TEST_NEW_SLUG'] ?? 'renamed';
        $models['posts'][0]['category_id'] = (int)($_SERVER['HTTP_X_TEST_NEW_CATEGORY'] ?? 2);
        file_put_contents($file, json_encode($models));
    }
    if (!empty($_SERVER['HTTP_X_TEST_MUTATE'])) {
        file_put_contents(BASE_PATH . '/database-state.json', '{"id":1,"revision":"changed"}');
    }
    if (!empty($_SERVER['HTTP_X_TEST_CONFIG_MUTATE'])) {
        file_put_contents(BASE_PATH . '/config/theme.php', 'changed theme');
    }
    if (!empty($_SERVER['HTTP_X_TEST_EVENT'])) {
        if (isset($_SERVER['HTTP_X_TEST_EVENT_ID'])) {
            do_action($_SERVER['HTTP_X_TEST_EVENT'], (int)$_SERVER['HTTP_X_TEST_EVENT_ID']);
        } else {
            do_action($_SERVER['HTTP_X_TEST_EVENT']);
        }
    }
    header('Location: /admin/login');
    http_response_code((int)($_SERVER['HTTP_X_TEST_STATUS'] ?? 302));
    echo 'controller completed';
    exit;
}

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: public, max-age=3600');
if ($case === 'current-csp') {
    header("Content-Security-Policy: default-src 'none'");
}
if ($case === 'early-private') {
    header('Cache-Control: private, no-store');
}

\NxNitroCache\PageCache::boot();

// ここからはキャッシュHITでは実行されない、本体・テーマの処理。
header('X-Origin-Rendered: yes');
if ($case !== 'current-csp') {
    header("Content-Security-Policy: default-src 'self'");
}
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('Permissions-Policy: camera=()');
header('Cross-Origin-Opener-Policy: same-origin');

$responseHeaders = [
    'set-cookie' => 'Set-Cookie: member=secret; HttpOnly',
    'vary' => 'Vary: Cookie',
    'vary-star' => 'Vary: *',
    'private' => 'Cache-Control: private, max-age=3600',
    'no-store' => 'Cache-Control: no-store',
    'no-cache' => 'Cache-Control: public, no-cache',
    'zero-age' => 'Cache-Control: max-age=0',
    'pragma' => 'Pragma: no-cache',
    'nonce' => "Content-Security-Policy: script-src 'nonce-request-value'",
    'encoding' => 'Content-Encoding: gzip',
    'cors' => 'Access-Control-Allow-Origin: https://private.example',
    'non-html' => 'Content-Type: application/json',
];
if (isset($responseHeaders[$case])) {
    header($responseHeaders[$case]);
}
if ($case === 'redirect') {
    header('Location: /admin/login', true, 302);
}
if ($case === 'error') {
    http_response_code(404);
}
if ($case === 'late-session') {
    $_SESSION['private'] = 'secret';
}
if ($case === 'invalidate') {
    \NxNitroCache\CacheManager::clear();
}
if ($case === 'partial') {
    echo '<html>partial';
    ob_flush();
}
if ($case === 'clean-buffer') {
    echo '<html>discarded</html>';
    ob_clean();
}
echo '<html><body>public fixture</body></html>';
