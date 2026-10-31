<?php

namespace NxNitroCache;

/*
 * 1ファイルの先頭行にメタデータ、続けて本文を保存する。
 * 清掃時は本文を読まず、容量確認・書込み・削除を同じロックで直列化する。
 */
class CacheManager
{
    private const FORMAT_VERSION = 3;
    private const HEADER_ALLOWLIST = [
        'content-type', 'content-language', 'cache-control', 'expires',
        'content-security-policy', 'content-security-policy-report-only',
        'x-frame-options', 'x-content-type-options', 'referrer-policy',
        'permissions-policy', 'strict-transport-security',
        'cross-origin-opener-policy', 'cross-origin-embedder-policy',
        'cross-origin-resource-policy', 'x-permitted-cross-domain-policies',
        'x-xss-protection', 'x-robots-tag',
    ];

    public static function buildKey(string $identifier): string { return hash('sha256', $identifier); }

    public static function get(string $key, int $ttl): ?string
    {
        return self::getResponse($key, $ttl)['html'] ?? null;
    }

    public static function getResponse(string $key, int $ttl, bool $blocking = false): ?array
    {
        $path = self::filePath($key);
        if ($path === null || !is_dir(self::cacheDirectory())) {
            return null;
        }
        return self::locked(static function () use ($path, $key, $ttl): ?array {
            self::maintain();
            clearstatcache(true, $path);
            $record = self::metadata($path);
            $mtime = @filemtime($path);
            $size = @filesize($path);
            if ($record === null || $mtime === false || $size === false ||
                $record['generation'] !== self::readGeneration() ||
                isset(self::loadState('blocked')[$key]) ||
                $size !== $record['_offset'] + $record['length'] ||
                $record['length'] > self::maxPageBytes()) {
                if (is_file($path)) { @unlink($path); }
                return null;
            }
            $age = max(0, time() - $mtime);
            $ttl = min($ttl, self::recordTtl($record));
            foreach ($record['headers'] as $header) {
                if (stripos($header, 'Cache-Control:') === 0 &&
                    preg_match_all('/(?:^|[,:])\s*(?:s-maxage|max-age)\s*=\s*"?([0-9]+)"?/', $header, $matches)) {
                    foreach ($matches[1] as $seconds) { $ttl = min($ttl, (int)$seconds); }
                }
                if (stripos($header, 'Expires:') === 0) {
                    $expires = strtotime(trim(substr($header, 8)));
                    if ($expires !== false) { $ttl = min($ttl, max(0, $expires - $mtime)); }
                }
            }
            if ($age >= $ttl) { @unlink($path); return null; }
            $html = @file_get_contents($path, false, null, $record['_offset'], self::maxPageBytes() + 1);
            if (!is_string($html) || strlen($html) !== $record['length']) { return null; }
            return ['html' => $html, 'headers' => $record['headers'], 'age' => $age];
        }, null, $blocking);
    }

    public static function put(
        string $key, string $html, array $headers = [], ?string $generation = null, array $context = []
    ): bool {
        $path = self::filePath($key);
        if ($path === null || $html === '' || strlen($html) > self::maxPageBytes()) { return false; }
        return self::locked(static function () use ($key, $path, $html, $headers, $generation, $context): bool {
            $current = self::ensureGeneration();
            if ($current === null || ($generation !== null && $generation !== self::writeToken())) { return false; }
            $relative = isset($context['path']) && is_string($context['path']) ? CacheUrl::path($context['path']) : null;
            $record = [
                'version' => self::FORMAT_VERSION, 'generation' => $current,
                'headers' => self::safeHeaders($headers), 'path' => $relative,
                'tags' => self::tags($context['tags'] ?? ['dynamic']),
                'ttl' => $relative === null ? (int)Settings::get('ttl', 3600) : Settings::ttlForPath($relative),
                'length' => strlen($html),
            ];
            $json = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!is_string($json) || strlen($json) > 65534 || is_link($path)) { return false; }
            $contents = $json . "\n" . $html;
            $recordBytes = strlen($contents);
            $maxBytes = self::maxBytes();
            $maxFiles = self::maxFiles();
            if ($recordBytes > $maxBytes) { return false; }
            $files = self::prune();
            $bytes = array_sum(array_column($files, 'size'));
            $count = count($files);
            if (isset($files[$path])) {
                $bytes -= $files[$path]['size']; $count--; unset($files[$path]);
            }
            uasort($files, static fn(array $a, array $b): int => $a['mtime'] <=> $b['mtime']);
            foreach ($files as $file => $info) {
                if ($count + 1 <= $maxFiles && $bytes + $recordBytes <= $maxBytes) { break; }
                if (@unlink($file)) { $count--; $bytes -= $info['size']; }
            }
            if ($count + 1 > $maxFiles || $bytes + $recordBytes > $maxBytes ||
                !self::atomicWrite($path, $contents)) { return false; }
            $blocked = self::loadState('blocked');
            if (isset($blocked[$key])) {
                unset($blocked[$key]);
                self::saveState('blocked', $blocked);
            }
            return true;
        }, false);
    }

    /* 部分削除も生成中の保存を拒否するが、無関係の保存済みページは有効のまま。 */
    public static function generation(): ?string
    {
        return self::locked(static fn(): ?string => self::writeToken(), null);
    }

    public static function clear(): int
    {
        // 新しい本体の旧版向け互換コールだけを抑止する。管理者の全削除は常に実行。
        if (class_exists(CacheCleaner::class, false) && CacheCleaner::handlesLegacyClear()) { return 0; }
        if (!is_dir(self::cacheDirectory())) { return 0; }
        return self::locked(static function (): int {
            if (!self::atomicWrite(self::cacheDirectory() . '/.generation', bin2hex(random_bytes(16)))) {
                throw new \RuntimeException('キャッシュの世代を更新できません。');
            }
            self::saveState('blocked', []);
            $count = 0;
            foreach (self::cacheFiles() as $file) { if (@unlink($file)) { $count++; } }
            return $count;
        }, 0, true);
    }

    public static function invalidate(array $paths = [], array $tags = []): array
    {
        return self::locked(static function () use ($paths, $tags): array {
            if (!self::atomicWrite(self::cacheDirectory() . '/.epoch', bin2hex(random_bytes(16)))) {
                throw new \RuntimeException('キャッシュの更新番号を保存できません。');
            }
            $blocked = self::loadState('blocked');
            $matched = [];
            $affected = [];
            $affectedTags = [];
            $decodedPaths = array_map('rawurldecode', $paths);
            foreach (self::cacheFiles() as $file) {
                $record = self::metadata($file);
                if ($record === null || ($record['path'] !== null &&
                    in_array(rawurldecode($record['path']), $decodedPaths, true)) ||
                    array_intersect($record['tags'], $tags) !== [] ||
                    (in_array('listing:category:*', $tags, true) &&
                        array_filter($record['tags'], static fn(string $tag): bool => str_starts_with($tag, 'listing:category:')) !== [])) {
                    $key = pathinfo($file, PATHINFO_FILENAME);
                    $blocked[$key] = true;
                    $matched[$key] = $file;
                    if ($record !== null && $record['path'] !== null) { $affected[] = $record['path']; }
                    if ($record !== null) { $affectedTags = array_merge($affectedTags, $record['tags']); }
                }
            }
            // 削除が失敗したファイルも再配信しないよう、先に無効化を記録する。
            self::saveState('blocked', $blocked);
            foreach ($matched as $key => $file) {
                if (@unlink($file)) { unset($blocked[$key]); }
            }
            self::saveState('blocked', $blocked);
            return ['count' => count($matched), 'paths' => array_values(array_unique($affected)),
                'tags' => array_values(array_unique($affectedTags))];
        }, ['count' => 0, 'paths' => [], 'tags' => []], true);
    }

    public static function paths(): array
    {
        return self::locked(static function (): array {
            $paths = [];
            foreach (self::cacheFiles() as $file) {
                $record = self::metadata($file);
                if ($record !== null && $record['path'] !== null) { $paths[] = $record['path']; }
            }
            return array_values(array_unique($paths));
        }, []);
    }

    public static function tagsFor(string $tag): array
    {
        return self::locked(static function () use ($tag): array {
            $tags = [];
            foreach (self::cacheFiles() as $file) {
                $record = self::metadata($file);
                if ($record !== null && in_array($tag, $record['tags'], true)) {
                    $tags = array_merge($tags, $record['tags']);
                }
            }
            return array_values(array_unique($tags));
        }, [], true);
    }

    public static function updateState(string $name, callable $callback, bool $blocking = false): mixed
    {
        if (!in_array($name, ['metrics', 'warmup'], true)) { return null; }
        return self::locked(static function () use ($name, $callback): mixed {
            $state = self::loadState($name);
            $result = $callback($state);
            self::saveState($name, $state);
            return $result;
        }, null, $blocking, '.' . $name . '.lock');
    }

    public static function readState(string $name, bool $blocking = false): array
    {
        if (!in_array($name, ['metrics', 'warmup'], true) || !is_dir(self::cacheDirectory())) { return []; }
        return self::locked(static fn(): array => self::loadState($name), [], $blocking, '.' . $name . '.lock');
    }

    public static function stats(): array
    {
        $empty = ['file_count' => 0, 'size_bytes' => 0, 'formatted_size' => '0 B', 'oldest_at' => null, 'newest_at' => null];
        if (!is_dir(self::cacheDirectory())) { return $empty; }
        return self::locked(static function () use ($empty): array {
            $stats = $empty;
            foreach (self::prune() as $info) {
                $stats['file_count']++; $stats['size_bytes'] += $info['size'];
                $stats['oldest_at'] = $stats['oldest_at'] === null ? $info['mtime'] : min($stats['oldest_at'], $info['mtime']);
                $stats['newest_at'] = max($stats['newest_at'] ?? 0, $info['mtime']);
            }
            $stats['formatted_size'] = self::formatBytes($stats['size_bytes']);
            return $stats;
        }, $empty);
    }

    public static function safeHeaders(array $headers): array
    {
        $safe = []; $bytes = 0;
        foreach ($headers as $header) {
            if (!is_string($header) || preg_match('/[\x00-\x1f\x7f]/', $header)) { continue; }
            $name = strtolower(trim(explode(':', $header, 2)[0]));
            if (!str_contains($header, ':') || !in_array($name, self::HEADER_ALLOWLIST, true)) { continue; }
            $bytes += strlen($header);
            if ($bytes > 32768 || count($safe) >= 64) { break; }
            $safe[] = $header;
        }
        return $safe;
    }

    public static function cacheDirectory(): string { return BASE_PATH . '/storage/cache/nx_nitro_cache'; }

    private static function tags(mixed $tags): array
    {
        return array_slice(array_values(array_unique(array_filter(is_array($tags) ? $tags : [],
            static fn($tag): bool => is_string($tag) &&
                preg_match('/\A(?:(?:post|page|category|listing:category):[1-9][0-9]*|listing:posts|dynamic)\z/', $tag) === 1
        ))), 0, 16) ?: ['dynamic'];
    }

    private static function metadata(string $path): ?array
    {
        if (!is_file($path) || is_link($path)) { return null; }
        $handle = @fopen($path, 'rb');
        if ($handle === false) { return null; }
        $line = fgets($handle, 65536);
        fclose($handle);
        if (!is_string($line) || !str_ends_with($line, "\n")) { return null; }
        $record = json_decode($line, true);
        if (!is_array($record) || ($record['version'] ?? null) !== self::FORMAT_VERSION ||
            !is_string($record['generation'] ?? null) || !preg_match('/\A[a-f0-9]{32}\z/', $record['generation']) ||
            !is_array($record['headers'] ?? null) || self::safeHeaders($record['headers']) !== $record['headers'] ||
            !is_array($record['tags'] ?? null) || self::tags($record['tags']) !== $record['tags'] ||
            !is_int($record['length'] ?? null) || $record['length'] < 1 ||
            !is_int($record['ttl'] ?? null) || $record['ttl'] < 1 || $record['ttl'] > 604800 ||
            !array_key_exists('path', $record) ||
            ($record['path'] !== null && (!is_string($record['path']) || CacheUrl::path($record['path']) !== $record['path']))) {
            return null;
        }
        $record['_offset'] = strlen($line);
        return $record;
    }

    private static function recordTtl(array $record, ?array $settings = null): int
    {
        $settings = $settings ?? Settings::all();
        return min($record['ttl'], $record['path'] === null ? (int)$settings['ttl'] : Settings::ttlForPath($record['path'], $settings));
    }

    private static function filePath(string $key): ?string
    {
        return preg_match('/\A[a-f0-9]{64}\z/', $key) ? self::cacheDirectory() . '/' . $key . '.cache' : null;
    }

    private static function locked(callable $callback, mixed $fallback, bool $blocking = false, string $lockName = '.lock'): mixed
    {
        $directory = self::cacheDirectory();
        if (is_link($directory) || (!is_dir($directory) && !@mkdir($directory, 0750, true) && !is_dir($directory))) { return $fallback; }
        $lockPath = $directory . '/' . $lockName;
        if (is_link($lockPath)) { return $fallback; }
        $lock = @fopen($lockPath, 'c');
        if ($lock === false) { return $fallback; }
        if (!flock($lock, $blocking ? LOCK_EX : LOCK_EX | LOCK_NB)) { fclose($lock); return $fallback; }
        try { return $callback(); }
        finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    private static function atomicWrite(string $path, string $contents): bool
    {
        $temporary = @tempnam(self::cacheDirectory(), '.write-');
        if ($temporary === false) { return false; }
        try {
            return @file_put_contents($temporary, $contents) === strlen($contents) && @rename($temporary, $path);
        } finally { if (is_file($temporary)) { @unlink($temporary); } }
    }

    private static function readGeneration(): ?string
    {
        $path = self::cacheDirectory() . '/.generation';
        $value = !is_link($path) && is_file($path) ? @file_get_contents($path) : false;
        return is_string($value) && preg_match('/\A[a-f0-9]{32}\z/', $value) ? $value : null;
    }

    private static function ensureGeneration(): ?string
    {
        $value = self::readGeneration();
        if ($value !== null) { return $value; }
        $value = bin2hex(random_bytes(16));
        return self::atomicWrite(self::cacheDirectory() . '/.generation', $value) ? $value : null;
    }

    private static function writeToken(): ?string
    {
        $generation = self::ensureGeneration();
        if ($generation === null) { return null; }
        $path = self::cacheDirectory() . '/.epoch';
        $epoch = !is_link($path) && is_file($path) ? @file_get_contents($path) : '';
        return hash('sha256', $generation . '|' . (is_string($epoch) ? $epoch : ''));
    }

    private static function loadState(string $name): array
    {
        $path = self::cacheDirectory() . '/.' . $name . '.json';
        if (!is_file($path) || is_link($path) || @filesize($path) > 1048576) { return []; }
        $json = @file_get_contents($path);
        $state = is_string($json) ? json_decode($json, true) : null;
        if (!is_array($state) && $name === 'blocked') { throw new \RuntimeException('キャッシュ無効化情報を読み込めません。'); }
        return is_array($state) ? $state : [];
    }

    private static function saveState(string $name, array $state): void
    {
        $json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || strlen($json) > 1048576 ||
            !self::atomicWrite(self::cacheDirectory() . '/.' . $name . '.json', $json)) {
            throw new \RuntimeException('キャッシュの管理情報を保存できません。');
        }
    }

    private static function maintain(): void
    {
        $marker = self::cacheDirectory() . '/.gc';
        $last = is_file($marker) ? @filemtime($marker) : false;
        if ($last === false || time() - $last >= 60) { self::prune(); @touch($marker); }
    }

    private static function cacheFiles(): array
    {
        return array_values(array_filter(glob(self::cacheDirectory() . '/*') ?: [],
            static fn(string $file): bool => preg_match('/\A[a-f0-9]{64}\.(?:cache|html)\z/', basename($file)) === 1 &&
                (is_file($file) || is_link($file))));
    }

    private static function prune(): array
    {
        $files = []; $blocked = self::loadState('blocked');
        $settings = Settings::all();
        $generation = self::readGeneration();
        $maxRecordBytes = self::maxPageBytes() + 65536;
        $maxBytes = self::maxBytes();
        foreach (self::cacheFiles() as $file) {
            clearstatcache(true, $file);
            $mtime = @filemtime($file); $size = @filesize($file);
            $record = str_ends_with($file, '.html') ? null : self::metadata($file);
            if ($record === null || $mtime === false || $size === false ||
                $record['generation'] !== $generation || isset($blocked[pathinfo($file, PATHINFO_FILENAME)]) ||
                time() - $mtime >= self::recordTtl($record, $settings) || $size > $maxRecordBytes) {
                if (!@unlink($file) && is_file($file)) {
                    // 削除できない古いファイルも物理容量として上限へ算入する。
                    $files[$file] = ['mtime' => $mtime ?: 0, 'size' => $size === false ? $maxBytes : $size];
                }
                continue;
            }
            $files[$file] = ['mtime' => $mtime, 'size' => $size];
        }
        return $files;
    }

    private static function maxBytes(): int { return max(1, min(1024, (int)Settings::get('max_size_mb', 100))) * 1048576; }
    private static function maxFiles(): int { return max(1, min(10000, (int)Settings::get('max_files', 1000))); }
    private static function maxPageBytes(): int { return max(16, min(10240, (int)Settings::get('max_page_kb', 2048))) * 1024; }
    private static function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) { return $bytes . ' B'; }
        $size = $bytes / 1024;
        foreach (['KB', 'MB', 'GB', 'TB'] as $unit) {
            if ($size < 1024 || $unit === 'TB') { return number_format($size, 2) . ' ' . $unit; }
            $size /= 1024;
        }
        return $bytes . ' B';
    }
}
