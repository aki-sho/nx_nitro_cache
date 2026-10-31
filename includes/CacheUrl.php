<?php

namespace NxNitroCache;

class CacheUrl
{
    public static function path(string $path): ?string
    {
        if ($path === '' || $path[0] !== '/' || str_starts_with($path, '//') ||
            strlen($path) > 2048 || strpbrk($path, "?#\\") !== false ||
            preg_match('/%(?![a-f0-9]{2})/i', $path)) {
            return null;
        }
        $decoded = rawurldecode($path);
        if (preg_match('/[\x00-\x20\x7f\\\\]/', $decoded)) {
            return null;
        }
        foreach (explode('/', $decoded) as $segment) {
            if ($segment === '.' || $segment === '..') {
                return null;
            }
        }
        return rtrim($path, '/') ?: '/';
    }

    public static function relative(string $uri, string $base): ?string
    {
        $path = self::path($uri);
        $base = rtrim($base, '/');
        if ($path === null) {
            return null;
        }
        if ($base !== '') {
            if (!str_starts_with($path . '/', $base . '/')) {
                return null;
            }
            $path = substr($path, strlen($base));
        }
        return self::path($path === '' ? '/' : $path);
    }

    public static function host(string $authority, string $scheme = 'https'): ?string
    {
        if ($authority === '' || strlen($authority) > 260 ||
            preg_match('/[^a-z0-9.\-:\[\]]/i', $authority)) {
            return null;
        }
        $parts = parse_url($scheme . '://' . $authority);
        if (!is_array($parts) || empty($parts['host'])) {
            return null;
        }
        $host = strtolower($parts['host']);
        if (filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) === false &&
            filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            return null;
        }
        $port = $parts['port'] ?? null;
        if ($port !== null && ($port < 1 || $port > 65535)) {
            return null;
        }
        if ($port === ($scheme === 'https' ? 443 : 80)) {
            $port = null;
        }
        return $host . ($port === null ? '' : ':' . $port);
    }

    public static function allowed(string $authority, string $scheme): bool
    {
        $host = self::host($authority, $scheme);
        foreach ((array)Settings::get('allowed_hosts', []) as $candidate) {
            if (is_string($candidate) && $host !== null && self::host($candidate, $scheme) === $host) {
                return true;
            }
        }
        return false;
    }

    public static function excluded(string $path): bool
    {
        $decoded = rawurldecode($path);
        foreach (['/admin', '/install', '/login', '/logout'] as $reserved) {
            if ($decoded === $reserved || str_starts_with($decoded, $reserved . '/')) {
                return true;
            }
        }
        foreach ((array)Settings::get('excluded_paths', []) as $rule) {
            if (!is_string($rule)) {
                continue;
            }
            $rule = rawurldecode($rule);
            if ($decoded === $rule ||
                (str_ends_with($rule, '*') && str_starts_with($decoded, substr($rule, 0, -1)))) {
                return true;
            }
        }
        return false;
    }

    public static function base(string $url): ?string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !in_array($parts['scheme'] ?? '', ['http', 'https'], true) ||
            empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) ||
            isset($parts['query']) || isset($parts['fragment'])) {
            return null;
        }
        $authority = $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $host = self::host($authority, $parts['scheme']);
        $path = self::path($parts['path'] ?? '/');
        if ($host === null || $path === null) {
            return null;
        }
        return $parts['scheme'] . '://' . $host . ($path === '/' ? '' : $path);
    }

    public static function key(string $scheme, string $host, string $base, string $uri): string
    {
        return CacheManager::buildKey($scheme . '|' . $host . '|' . rtrim($base, '/') . '|' . $uri);
    }
}
