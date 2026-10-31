<?php

define('BASE_PATH', getenv('NX_CACHE_TEST_ROOT'));
$project = dirname(__DIR__, 2);
require $project . '/includes/Settings.php';
require $project . '/includes/CacheUrl.php';
require $project . '/includes/CacheManager.php';

use NxNitroCache\CacheManager;
use NxNitroCache\Settings;

$checks = 0;
function check(bool $value, string $message): void
{
    global $checks;
    if (!$value) {
        throw new RuntimeException($message);
    }
    $checks++;
}
function configure(array $overrides = []): void
{
    Settings::save(array_replace([
        'enabled' => '1', 'auto_clear' => '1', 'ttl' => 3600,
        'allowed_hosts' => ['example.test'],
    ], $overrides));
}

configure(['max_files' => 2]);
$key = CacheManager::buildKey('/one');
$oldGeneration = CacheManager::generation();
check(CacheManager::put($key, '<html>one</html>', [
    'X-Frame-Options: DENY', 'Set-Cookie: secret=1',
    "Content-Type: text/html\r\nSet-Cookie: injected=1",
], $oldGeneration), 'save response');
$record = CacheManager::getResponse($key, 3600);
check($record['html'] === '<html>one</html>', 'body roundtrip');
check($record['headers'] === ['X-Frame-Options: DENY'], 'cookie and CRLF are never replayed');
$directory = CacheManager::cacheDirectory();
touch($directory . '/' . $key . '.cache', time() - 10);
check(CacheManager::getResponse($key, 3600)['age'] >= 10, 'cached response carries its actual age');
check(CacheManager::put(CacheManager::buildKey('/two'), '<html>two</html>'), 'second entry');
check(CacheManager::put(CacheManager::buildKey('/three'), '<html>three</html>'), 'third entry');
check(CacheManager::stats()['file_count'] === 2, 'file count limit');
check(CacheManager::get($key, 3600) === null, 'oldest entry evicted');

CacheManager::clear();
configure(['max_size_mb' => 1, 'max_page_kb' => 1024]);
for ($i = 0; $i < 4; $i++) {
    check(CacheManager::put(CacheManager::buildKey('/large' . $i), str_repeat('a', 400000)), 'quota write');
}
check(CacheManager::stats()['size_bytes'] <= 1048576, 'byte limit includes metadata');
check(!CacheManager::put($key, str_repeat('b', 1048577)), 'oversize page rejected');
check(!CacheManager::put('../escape', '<html>bad key</html>'), 'invalid key rejected');
check(!CacheManager::put($key . "\n", '<html>bad key</html>'), 'trailing newline key rejected');

CacheManager::clear();
configure();
check(CacheManager::put($key, '<html>expired</html>'), 'expiry fixture');
touch($directory . '/' . $key . '.cache', time() - 7200);
check(CacheManager::put(CacheManager::buildKey('/fresh'), '<html>fresh</html>'), 'fresh write');
check(!is_file($directory . '/' . $key . '.cache'), 'expired other URL removed without revisiting');
file_put_contents($directory . '/' . $key . '.html', '<html>legacy secret</html>');
check(CacheManager::get($key, 3600) === null, 'legacy HTML is never served');
CacheManager::put(CacheManager::buildKey('/fresh'), '<html>fresh</html>');
check(!is_file($directory . '/' . $key . '.html'), 'legacy file cleaned');

CacheManager::clear();
$oldGeneration = CacheManager::generation();
check(CacheManager::put($key, '<html>before</html>', [], $oldGeneration), 'generation write');
$oldRecord = file_get_contents($directory . '/' . $key . '.cache');
check(CacheManager::clear() === 1, 'clear removes cached entry');
check(CacheManager::generation() !== $oldGeneration, 'clear changes generation');
check(!CacheManager::put($key, '<html>stale</html>', [], $oldGeneration), 'inflight old generation cannot repopulate');
check(is_file($directory . '/.lock'), 'clear retains shared lock identity');
file_put_contents($directory . '/' . $key . '.cache', $oldRecord);
check(CacheManager::get($key, 3600) === null, 'old-generation file is rejected even if deletion failed');

file_put_contents($directory . '/' . $key . '.cache', '{broken');
check(CacheManager::get($key, 3600) === null, 'corrupt record ignored');
check(!is_file($directory . '/' . $key . '.cache'), 'corrupt record removed');
CacheManager::put($key, '<html>short lifetime</html>', ['Cache-Control: public, max-age=5']);
touch($directory . '/' . $key . '.cache', time() - 10);
check(CacheManager::get($key, 3600) === null, 'origin max-age limits configured TTL');
CacheManager::put($key, '<html>past expiry</html>', ['Expires: Thu, 01 Jan 1970 00:00:00 GMT']);
check(CacheManager::get($key, 3600) === null, 'expired origin response is never reused');
configure(['max_size_mb' => -1, 'max_files' => 999999, 'max_page_kb' => 0]);
$settings = Settings::all();
check($settings['max_size_mb'] === 1 && $settings['max_files'] === 10000 &&
    $settings['max_page_kb'] === 16, 'settings bounds enforced');
try {
    configure(['allowed_hosts' => "example.test\nhttp://attacker.test/path"]);
    check(false, 'invalid authority must be rejected');
} catch (RuntimeException $exception) {
    check(true, 'invalid authority rejected');
}
file_put_contents(BASE_PATH . '/storage/extensions/nx_nitro_cache/settings.json', '{broken');
check(Settings::get('enabled') === false, 'broken settings fail closed');
echo json_encode(['checks' => $checks]);
