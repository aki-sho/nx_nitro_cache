<?php

namespace NxNitroCache;

use Throwable;

class PageCache
{
    private static bool $started = false;
    private static ?string $cacheKey = null;
    private static ?string $generation = null;
    private static float $startedAt = 0;
    private static bool $finished = false;
    private static ?string $lastReason = null;

    public static function boot(): void
    {
        if (self::$started) { return; }
        self::$started = true;
        self::$startedAt = microtime(true);
        $reason = headers_sent() ? 'headers_sent' : self::requestReason();
        if ($reason === null) { $reason = self::responseReason('<html>'); }
        if ($reason !== null) {
            self::bypass($reason);
            return;
        }
        $path = self::requestPath();
        $ttl = Settings::ttlForPath($path);
        $key = self::cacheIdentifier();
        try {
            $response = CacheManager::getResponse($key, $ttl);
            if ($response !== null) {
                self::restoreHeaders($response['headers']);
                header('Age: ' . $response['age']);
                header('X-NX-Nitro-Cache: HIT');
                Metrics::record('hit', null, self::elapsed());
                echo $response['html'];
                exit;
            }
            self::$generation = CacheManager::generation();
        } catch (Throwable $exception) {
            self::bypass('storage');
            return;
        }
        if (self::$generation === null) { self::bypass('storage'); return; }
        self::$cacheKey = $key;
        header('X-NX-Nitro-Cache: MISS');
        Metrics::record('miss');
        ob_start([self::class, 'captureOutput']);
    }

    public static function captureOutput(string $html, int $phase = PHP_OUTPUT_HANDLER_FINAL): string
    {
        if (self::$finished || self::$cacheKey === null) { return $html; }
        if (($phase & PHP_OUTPUT_HANDLER_FINAL) === 0 || ($phase & PHP_OUTPUT_HANDLER_CLEAN) !== 0) {
            self::$finished = true;
            Metrics::record('not_stored', 'partial');
            return $html;
        }
        self::$finished = true;
        $reason = self::requestReason() ?? self::responseReason($html);
        if ($reason !== null) {
            Metrics::record('not_stored', $reason);
            return $html;
        }
        try {
            $path = self::requestPath();
            $stored = CacheManager::put(self::$cacheKey, $html, headers_list(), self::$generation, [
                'path' => $path, 'tags' => Dependencies::describe($path),
            ]);
            Metrics::record($stored ? 'stored' : 'not_stored', $stored ? null : 'storage', self::elapsed());
        } catch (Throwable $exception) {
            Metrics::record('not_stored', 'storage');
        }
        return $html;
    }

    private static function bypass(string $reason): void
    {
        self::$lastReason = $reason;
        if (!headers_sent()) {
            header('X-NX-Nitro-Cache: BYPASS');
            header('X-NX-Nitro-Reason: ' . $reason);
        }
        Metrics::record('bypass', $reason);
    }

    private static function requestReason(): ?string
    {
        if (!Settings::get('enabled', true)) { return 'disabled'; }
        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') { return 'method'; }
        if (!empty($_SESSION)) { return 'session'; }
        if (!empty($_COOKIE) || !empty($_SERVER['HTTP_COOKIE'])) { return 'cookie'; }
        foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION', 'PHP_AUTH_USER', 'PHP_AUTH_PW', 'PHP_AUTH_DIGEST'] as $name) {
            if (isset($_SERVER[$name])) { return 'authorization'; }
        }
        if (isset($_SERVER['HTTP_RANGE']) || isset($_SERVER['HTTP_ORIGIN'])) { return 'range_or_origin'; }
        if (self::trustedHost() === null) { return 'host'; }
        $control = strtolower((string)($_SERVER['HTTP_CACHE_CONTROL'] ?? ''));
        if (preg_match('/(?:^|,)\s*(?:no-cache|no-store)(?:\s*(?:=|,|$))/', $control) ||
            preg_match('/(?:^|,)\s*max-age\s*=\s*"?0"?(?:\s*(?:,|$))/', $control) ||
            str_contains(strtolower((string)($_SERVER['HTTP_PRAGMA'] ?? '')), 'no-cache')) { return 'request_no_cache'; }
        $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
        if (!empty($_SERVER['QUERY_STRING']) || str_contains($uri, '?')) { return 'query'; }
        $path = CacheUrl::relative($uri, defined('BASE_URL') ? BASE_URL : '');
        if ($path === null) { return 'path'; }
        if (CacheUrl::excluded($path)) { return 'excluded'; }
        return null;
    }

    private static function responseReason(string $html): ?string
    {
        $error = error_get_last();
        if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
            return 'fatal';
        }
        if (trim($html) === '' || stripos($html, '<html') === false) { return 'empty_or_non_html'; }
        $status = http_response_code();
        if (is_int($status) && $status !== 200) { return 'status'; }
        foreach (headers_list() as $header) {
            $normalized = strtolower($header);
            [$name, $value] = array_pad(explode(':', $normalized, 2), 2, '');
            $name = trim($name);
            if (in_array($name, ['set-cookie', 'set-cookie2'], true)) { return 'set_cookie'; }
            if ($name === 'vary') { return 'vary'; }
            if (in_array($name, ['www-authenticate', 'content-range', 'content-encoding',
                'access-control-allow-origin', 'access-control-allow-credentials'], true)) { return 'response_variant'; }
            if (str_starts_with($name, 'content-security-policy') && str_contains($value, "'nonce-")) { return 'nonce'; }
            if ($name === 'location') { return 'status'; }
            if ($name === 'content-type' && !str_contains($value, 'text/html')) { return 'empty_or_non_html'; }
            if (($name === 'pragma' && str_contains($value, 'no-cache')) ||
                ($name === 'cache-control' &&
                    (preg_match('/(?:^|,)\s*(?:private|no-store|no-cache)(?:\s*(?:=|,|$))/', $value) ||
                        preg_match('/(?:^|,)\s*(?:s-maxage|max-age)\s*=\s*"?0"?(?:\s*(?:,|$))/', $value)))) {
                return 'response_private';
            }
        }
        return null;
    }

    private static function shouldCacheRequest(): bool { return self::requestReason() === null; }
    private static function canStoreResponse(string $html): bool
    {
        return self::requestReason() === null && self::responseReason($html) === null;
    }

    private static function cacheIdentifier(): string
    {
        return CacheUrl::key(self::scheme(), self::trustedHost() ?? '',
            defined('BASE_URL') ? BASE_URL : '', (string)($_SERVER['REQUEST_URI'] ?? '/'));
    }
    private static function scheme(): string
    {
        return !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off' ? 'https' : 'http';
    }
    private static function trustedHost(): ?string
    {
        $authority = (string)($_SERVER['HTTP_HOST'] ?? '');
        return CacheUrl::allowed($authority, self::scheme()) ? CacheUrl::host($authority, self::scheme()) : null;
    }
    private static function requestPath(): string
    {
        return CacheUrl::relative((string)($_SERVER['REQUEST_URI'] ?? '/'), defined('BASE_URL') ? BASE_URL : '') ?? '/';
    }
    private static function elapsed(): float { return (microtime(true) - self::$startedAt) * 1000; }

    private static function restoreHeaders(array $headers): void
    {
        $present = [];
        foreach (headers_list() as $header) { $present[strtolower(trim(explode(':', $header, 2)[0]))] = true; }
        foreach (CacheManager::safeHeaders($headers) as $header) {
            if (!isset($present[strtolower(trim(explode(':', $header, 2)[0]))])) { header($header, false); }
        }
    }
}
