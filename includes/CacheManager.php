<?php

namespace NxNitroCache;

/*
 * キャッシュファイルの保存・取得・削除を管理
 */
class CacheManager
{
    /*
     * URLなどの識別文字列から
     * キャッシュキーを作成
     */
    public static function buildKey(
        string $identifier
    ): string {
        return hash(
            'sha256',
            $identifier
        );
    }

    /*
     * キャッシュを取得
     */
    public static function get(
        string $cacheKey,
        int $ttl
    ): ?string {
        $path = self::filePath(
            $cacheKey
        );

        if ($path === null) {
            return null;
        }

        if (!is_file($path)) {
            return null;
        }

        $modifiedTime = filemtime($path);

        if ($modifiedTime === false) {
            return null;
        }

        /*
         * 保存時間を過ぎたキャッシュは削除
         */
        if (
            $ttl <= 0 ||
            time() - $modifiedTime >= $ttl
        ) {
            @unlink($path);

            return null;
        }

        $html = file_get_contents($path);

        if ($html === false) {
            return null;
        }

        return $html;
    }

    /*
     * HTMLをキャッシュへ保存
     */
    public static function put(
        string $cacheKey,
        string $html
    ): bool {
        if ($html === '') {
            return false;
        }

        if (!self::ensureDirectory()) {
            return false;
        }

        $path = self::filePath(
            $cacheKey
        );

        if ($path === null) {
            return false;
        }

        return file_put_contents(
            $path,
            $html,
            LOCK_EX
        ) !== false;
    }

    /*
     * 全キャッシュを削除
     *
     * 戻り値は削除したファイル数
     */
    public static function clear(): int
    {
        $directory =
            self::cacheDirectory();

        if (!is_dir($directory)) {
            return 0;
        }

        $files = glob(
            $directory . '/*'
        );

        if ($files === false) {
            return 0;
        }

        $deletedCount = 0;

        foreach ($files as $file) {
            if (!is_file($file)) {
                continue;
            }

            if (@unlink($file)) {
                $deletedCount++;
            }
        }

        return $deletedCount;
    }

    /*
     * キャッシュ状況を取得
     */
    public static function stats(): array
    {
        $directory =
            self::cacheDirectory();

        $stats = [
            'file_count' => 0,
            'size_bytes' => 0,
            'formatted_size' => '0 B',
            'oldest_at' => null,
            'newest_at' => null,
        ];

        if (!is_dir($directory)) {
            return $stats;
        }

        $files = glob(
            $directory . '/*.html'
        );

        if ($files === false) {
            return $stats;
        }

        $oldestAt = null;
        $newestAt = null;

        foreach ($files as $file) {
            if (!is_file($file)) {
                continue;
            }

            $size = filesize($file);
            $modifiedTime = filemtime($file);

            $stats['file_count']++;

            if ($size !== false) {
                $stats['size_bytes'] += $size;
            }

            if ($modifiedTime === false) {
                continue;
            }

            if (
                $oldestAt === null ||
                $modifiedTime < $oldestAt
            ) {
                $oldestAt = $modifiedTime;
            }

            if (
                $newestAt === null ||
                $modifiedTime > $newestAt
            ) {
                $newestAt = $modifiedTime;
            }
        }

        $stats['formatted_size'] =
            self::formatBytes(
                $stats['size_bytes']
            );

        $stats['oldest_at'] = $oldestAt;
        $stats['newest_at'] = $newestAt;

        return $stats;
    }

    /*
     * キャッシュ保存フォルダを取得
     */
    public static function cacheDirectory(): string
    {
        return BASE_PATH
            . '/storage/cache/'
            . 'nx_nitro_cache';
    }

    /*
     * キャッシュファイルのパスを取得
     */
    private static function filePath(
        string $cacheKey
    ): ?string {
        /*
         * ファイル名へ不正な値が入らないように制限
         */
        if (
            !preg_match(
                '/^[a-f0-9]{64}$/',
                $cacheKey
            )
        ) {
            return null;
        }

        return self::cacheDirectory()
            . '/' . $cacheKey . '.html';
    }

    /*
     * キャッシュ保存フォルダを作成
     */
    private static function ensureDirectory(): bool
    {
        $directory =
            self::cacheDirectory();

        if (is_dir($directory)) {
            return is_writable($directory);
        }

        return mkdir(
            $directory,
            0755,
            true
        );
    }

    /*
     * バイト数を読みやすい形式へ変換
     */
    private static function formatBytes(
        int $bytes
    ): string {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }

        $units = [
            'KB',
            'MB',
            'GB',
            'TB',
        ];

        $size = $bytes / 1024;

        foreach ($units as $unit) {
            if (
                $size < 1024 ||
                $unit === 'TB'
            ) {
                return number_format(
                    $size,
                    2
                ) . ' ' . $unit;
            }

            $size /= 1024;
        }

        return $bytes . ' B';
    }
}