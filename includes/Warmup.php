<?php

namespace NxNitroCache;

use RuntimeException;
use Throwable;

class Warmup
{
    public static function enqueue(array $paths): int
    {
        if (!Settings::get('warmup_enabled', false) || !Settings::get('enabled', true)) { return 0; }
        $base = self::base();
        if ($base === null) { return 0; }
        $valid = [];
        foreach ($paths as $path) {
            if (is_string($path) && ($path = CacheUrl::path($path)) !== null && !CacheUrl::excluded($path)) {
                $valid[] = $path;
            }
        }
        return (int)CacheManager::updateState('warmup', static function (array &$state) use ($valid, $base): int {
            if (($state['base'] ?? '') !== $base) { $state = ['base' => $base]; }
            $state['jobs'] = $state['jobs'] ?? [];
            $added = 0;
            foreach (array_unique($valid) as $path) {
                $key = hash('sha256', $path);
                if (isset($state['jobs'][$key]) || count($state['jobs']) >= 200) { continue; }
                $state['jobs'][$key] = ['path' => $path, 'attempts' => 0, 'after' => 0, 'lease' => 0];
                $added++;
            }
            return $added;
        }, true);
    }

    public static function seed(): int
    {
        return self::enqueue(array_merge((array)Settings::get('warmup_paths', ['/', '/posts']), CacheManager::paths()));
    }

    public static function stats(): array
    {
        $state = CacheManager::readState('warmup', true);
        $state['pending'] = count($state['jobs'] ?? []);
        $state['ready'] = count(array_filter($state['jobs'] ?? [], static fn(array $job): bool =>
            (int)$job['lease'] <= time() && (int)$job['after'] <= time()));
        return $state;
    }

    public static function cancel(): void
    {
        CacheManager::updateState('warmup', static function (array &$state): void { $state = []; }, true);
    }

    /* ネットワーク処理中はキューやキャッシュのロックを保持しない。 */
    public static function run(int $limit = 1): array
    {
        $deadline = microtime(true) + 25;
        for ($i = 0; $i < max(1, min(10, $limit)) && microtime(true) < $deadline; $i++) {
            $base = self::base();
            if (!Settings::get('enabled', true) || !Settings::get('warmup_enabled', false) || $base === null) { break; }
            $job = CacheManager::updateState('warmup', static function (array &$state) use ($base): ?array {
                if (($state['base'] ?? '') !== $base) { $state = ['base' => $base]; return null; }
                foreach ($state['jobs'] ?? [] as $key => $item) {
                    if ((int)$item['lease'] > time() || (int)$item['after'] > time()) { continue; }
                    $token = bin2hex(random_bytes(8));
                    $state['jobs'][$key]['lease'] = time() + 35;
                    $state['jobs'][$key]['token'] = $token;
                    return ['key' => $key, 'path' => $item['path'], 'token' => $token];
                }
                return null;
            }, true);
            if (!is_array($job)) { break; }
            try { $result = self::fetch($base, $job['path']); }
            catch (Throwable $exception) { $result = '接続失敗'; }
            CacheManager::updateState('warmup', static function (array &$state) use ($job, $result): void {
                $item = $state['jobs'][$job['key']] ?? null;
                if (!is_array($item) || ($item['token'] ?? '') !== $job['token']) { return; }
                $state['last_result'] = $result;
                $state['last_at'] = time();
                if ($result === '生成済み') {
                    unset($state['jobs'][$job['key']]);
                    $state['completed'] = (int)($state['completed'] ?? 0) + 1;
                } elseif (in_array($result, ['接続失敗', '保存競合・再試行'], true) && (int)$item['attempts'] < 2) {
                    $state['jobs'][$job['key']]['attempts']++;
                    $state['jobs'][$job['key']]['after'] = time() + 60;
                    $state['jobs'][$job['key']]['lease'] = 0;
                } else {
                    unset($state['jobs'][$job['key']]);
                    $state['skipped'] = (int)($state['skipped'] ?? 0) + 1;
                }
            }, true);
        }
        return self::stats();
    }

    private static function base(): ?string
    {
        $base = CacheUrl::base((string)Settings::get('warm_base_url', ''));
        if ($base === null) { return null; }
        $parts = parse_url($base);
        $authority = $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        return CacheUrl::allowed($authority, $parts['scheme']) ? $base : null;
    }

    private static function fetch(string $base, string $path): string
    {
        if (!function_exists('curl_init')) { return 'PHPのcURL拡張が必要です'; }
        if (CacheUrl::path($path) === null || CacheUrl::excluded($path)) { return '対象外URL'; }
        $parts = parse_url($base);
        $host = trim($parts['host'], '[]');
        $port = $parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80);
        $addresses = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
        if ($addresses === [] && defined('DNS_AAAA')) {
            foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $entry) {
                if (isset($entry['ipv6'])) { $addresses[] = $entry['ipv6']; }
            }
        }
        if ($addresses === []) { return '接続失敗'; }
        foreach ($addresses as $address) {
            if (!Settings::get('warm_allow_private', false) &&
                filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return '内部アドレスへの接続を除外';
            }
        }
        $encoded = implode('/', array_map(static fn(string $segment): string => rawurlencode(rawurldecode($segment)), explode('/', $path)));
        $url = $base . ($encoded === '/' ? '/' : $encoded);
        $handle = curl_init($url);
        if ($handle === false) { return '接続失敗'; }
        $bytes = 0;
        $uncacheable = false;
        $limit = max(16, min(10240, (int)Settings::get('max_page_kb', 2048))) * 1024;
        $options = [
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0,
            CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_TIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROXY => '', CURLOPT_USERAGENT => 'NX-Nitro-Cache-Warmup/1.1',
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER => ['Accept: text/html', 'Connection: close'],
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$uncacheable): int {
                $lower = strtolower($line);
                if (preg_match('/^(?:set-cookie|vary|content-encoding|www-authenticate):/', $lower) ||
                    (str_starts_with($lower, 'cache-control:') &&
                        preg_match('/(?:private|no-store|no-cache)/', $lower))) {
                    $uncacheable = true;
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$bytes, $limit): int {
                $bytes += strlen($chunk);
                return $bytes <= $limit ? strlen($chunk) : 0;
            },
        ];
        // 検証したアドレスに固定し、検証後のDNS差し替えを防ぐ。
        if (!filter_var($host, FILTER_VALIDATE_IP)) {
            $address = str_contains($addresses[0], ':') ? '[' . $addresses[0] . ']' : $addresses[0];
            $options[CURLOPT_RESOLVE] = [$host . ':' . $port . ':' . $address];
        }
        curl_setopt_array($handle, $options);
        $success = curl_exec($handle);
        $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);
        if ($success === false) { return $bytes > $limit ? 'ページサイズ上限' : '接続失敗'; }
        if ($status !== 200) { return 'HTTP ' . $status . '（転送先は取得しません）'; }
        if ($uncacheable) { return '保存対象外・保存不可'; }
        $authority = CacheUrl::host($parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : ''), $parts['scheme']);
        $basePath = rtrim($parts['path'] ?? '', '/');
        $uri = $basePath . ($encoded === '/' ? '/' : $encoded);
        $key = CacheUrl::key($parts['scheme'], $authority, $basePath, $uri);
        return CacheManager::getResponse($key, Settings::ttlForPath($path), true) !== null
            ? '生成済み' : '保存競合・再試行';
    }
}
