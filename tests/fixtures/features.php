<?php

require __DIR__ . '/core.php';
define('BASE_PATH', getenv('NX_CACHE_TEST_ROOT'));
define('BASE_URL', getenv('NX_CACHE_TEST_BASE') ?: '');
require __DIR__ . '/models.php';
$project = dirname(__DIR__, 2);
foreach (['Settings', 'CacheUrl', 'CacheManager', 'Dependencies', 'Metrics', 'Warmup', 'CacheCleaner'] as $class) {
    require $project . '/includes/' . $class . '.php';
}
use NxNitroCache\Settings;
use NxNitroCache\CacheManager;
use NxNitroCache\Warmup;
use NxNitroCache\Metrics;
$input = json_decode(getenv('NX_CACHE_FEATURE_INPUT') ?: '{}', true);
switch (getenv('NX_CACHE_FEATURE_ACTION')) {
    case 'inspect':
        $result = ['metrics' => Metrics::stats(), 'queue' => Warmup::stats(), 'settings' => Settings::all()];
        break;
    case 'configure':
        Settings::save($input);
        $result = Settings::all();
        break;
    case 'validate':
        try {
            Settings::save($input);
            $result = ['valid' => true];
        } catch (Throwable $exception) {
            $result = ['valid' => false, 'message' => $exception->getMessage()];
        }
        break;
    case 'ttl':
        $result = Settings::ttlForPath($input['path']);
        break;
    case 'queue':
        $result = Warmup::enqueue($input['paths']);
        break;
    case 'run':
        $result = Warmup::run($input['limit'] ?? 1);
        break;
    case 'invalidate':
        $result = CacheManager::invalidate($input['paths'] ?? [], $input['tags'] ?? []);
        break;
    case 'token':
        $result = CacheManager::generation();
        break;
    case 'put':
        $result = CacheManager::put(hash('sha256', $input['name']), '<html>fixture</html>', [],
            $input['token'] ?? null, ['path' => $input['path'], 'tags' => $input['tags'] ?? ['dynamic']]);
        break;
    default:
        throw new RuntimeException('unknown action');
}
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
