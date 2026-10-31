<?php

define('BASE_PATH', getenv('NX_CACHE_TEST_ROOT'));
$project = dirname(__DIR__, 2);
require $project . '/includes/Settings.php';
require $project . '/includes/CacheUrl.php';
require $project . '/includes/CacheManager.php';
for ($i = 0; $i < 10; $i++) {
    \NxNitroCache\CacheManager::put(
        hash('sha256', getenv('NX_CACHE_WORKER') . '/' . $i),
        str_repeat('a', 200000),
        ['X-Frame-Options: DENY']
    );
}
