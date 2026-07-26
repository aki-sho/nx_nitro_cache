<?php

namespace NxNitroCache;

use RuntimeException;

/*
 * NX Nitro Cacheの設定を管理
 */
class Settings
{
    /*
     * 初期設定
     */
    public static function defaults(): array
    {
        return [
            /*
             * ページキャッシュの有効・無効
             */
            'enabled' => true,

            /*
             * キャッシュ保存時間
             * 初期値：1時間
             */
            'ttl' => 3600,

            /*
             * キャッシュから除外するURL
             */
            'excluded_paths' => [],

            /*
             * コンテンツ更新時の自動削除
             */
            'auto_clear' => true,
        ];
    }

    /*
     * 全設定を取得
     */
    public static function all(): array
    {
        $defaults = self::defaults();
        $path = self::settingsPath();

        if (!is_file($path)) {
            return $defaults;
        }

        $json = file_get_contents($path);

        if ($json === false) {
            return $defaults;
        }

        $savedSettings = json_decode(
            $json,
            true
        );

        if (!is_array($savedSettings)) {
            return $defaults;
        }

        return array_replace(
            $defaults,
            $savedSettings
        );
    }

    /*
     * 指定した設定項目を取得
     */
    public static function get(
        string $key,
        mixed $default = null
    ): mixed {
        $settings = self::all();

        return $settings[$key] ?? $default;
    }

    /*
     * 管理画面から送信された設定を保存
     */
    public static function save(
        array $input
    ): void {
        $ttl = (int)($input['ttl'] ?? 3600);

        /*
         * 保存時間は1分から7日まで
         */
        $ttl = max(
            60,
            min($ttl, 604800)
        );

        $excludedPaths =
            self::normalizeExcludedPaths(
                $input['excluded_paths'] ?? []
            );

        $settings = [
            'enabled' =>
                !empty($input['enabled']),

            'ttl' => $ttl,

            'excluded_paths' =>
                $excludedPaths,

            'auto_clear' =>
                !empty($input['auto_clear']),
        ];

        self::write($settings);
    }

    /*
     * 保存結果のメッセージを登録
     */
    public static function setNotice(
        string $message,
        string $type = 'success'
    ): void {
        $_SESSION['nx_nitro_cache_notice'] = [
            'message' => $message,
            'type' => $type,
        ];
    }

    /*
     * 保存結果のメッセージを取得して削除
     */
    public static function pullNotice(): ?array
    {
        $notice =
            $_SESSION['nx_nitro_cache_notice']
            ?? null;

        unset(
            $_SESSION['nx_nitro_cache_notice']
        );

        return is_array($notice)
            ? $notice
            : null;
    }

    /*
     * 除外URLを整理
     */
    private static function normalizeExcludedPaths(
        mixed $paths
    ): array {
        /*
         * テキストエリアから送信された場合
         */
        if (is_string($paths)) {
            $paths = preg_split(
                '/\R/',
                $paths
            );
        }

        if (!is_array($paths)) {
            return [];
        }

        $normalized = [];

        foreach ($paths as $path) {
            if (!is_string($path)) {
                continue;
            }

            $path = trim($path);

            if ($path === '') {
                continue;
            }

            /*
             * 完全なURLが入力された場合は
             * URLパス部分だけを使用
             */
            $parsedPath = parse_url(
                $path,
                PHP_URL_PATH
            );

            if (
                is_string($parsedPath) &&
                $parsedPath !== ''
            ) {
                $path = $parsedPath;
            }

            $hasWildcard =
                str_ends_with($path, '*');

            if ($hasWildcard) {
                $path = substr($path, 0, -1);
            }

            $path = str_replace(
                '\\',
                '/',
                $path
            );

            $path =
                '/' . ltrim($path, '/');

            if ($path !== '/') {
                $path = rtrim($path, '/');
            }

            if ($hasWildcard) {
                $path .= '*';
            }

            $normalized[] = $path;
        }

        $normalized = array_values(
            array_unique($normalized)
        );

        sort($normalized);

        return $normalized;
    }

    /*
     * 設定ファイルへ書き込む
     */
    private static function write(
        array $settings
    ): void {
        $path = self::settingsPath();
        $directory = dirname($path);

        if (
            !is_dir($directory) &&
            !mkdir($directory, 0755, true)
        ) {
            throw new RuntimeException(
                '設定保存フォルダを作成できません。'
            );
        }

        $json = json_encode(
            $settings,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES |
            JSON_PRETTY_PRINT
        );

        if ($json === false) {
            throw new RuntimeException(
                '設定データを作成できません。'
            );
        }

        if (
            file_put_contents(
                $path,
                $json,
                LOCK_EX
            ) === false
        ) {
            throw new RuntimeException(
                '設定を保存できません。'
            );
        }
    }

    /*
     * 設定ファイルの保存場所
     */
    private static function settingsPath(): string
    {
        return BASE_PATH
            . '/storage/extensions/'
            . 'nx_nitro_cache/settings.json';
    }
}