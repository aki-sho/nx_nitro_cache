<?php

namespace NxNitroCache;

/*
 * 投稿や設定の更新後に
 * ページキャッシュを自動削除
 */
class CacheCleaner
{
    private static bool $registered = false;

    /*
     * 自動削除処理を登録
     */
    public static function boot(): void
    {
        if (self::$registered) {
            return;
        }

        if (
            !Settings::get(
                'auto_clear',
                true
            )
        ) {
            return;
        }

        $method =
            strtoupper(
                $_SERVER['REQUEST_METHOD']
                ?? 'GET'
            );

        /*
         * 更新処理以外では何もしない
         */
        if ($method !== 'POST') {
            return;
        }

        $requestPath =
            self::requestPath();

        if (
            !self::shouldClear(
                $requestPath
            )
        ) {
            return;
        }

        self::$registered = true;

        /*
         * コントローラー処理とリダイレクトが
         * 完了した後にキャッシュを削除
         */
        register_shutdown_function(
            static function (): void {
                $statusCode =
                    http_response_code();

                /*
                 * エラー終了時は削除しない
                 */
                if (
                    is_int($statusCode) &&
                    $statusCode >= 400
                ) {
                    return;
                }

                CacheManager::clear();
            }
        );
    }

    /*
     * キャッシュ削除対象のURLか確認
     */
    private static function shouldClear(
        string $requestPath
    ): bool {
        $patterns = [
            /*
             * 投稿
             */
            '#^/admin/posts/store$#',
            '#^/admin/posts/update/[^/]+$#',
            '#^/admin/posts/delete/[^/]+$#',
            '#^/admin/posts/status/[^/]+$#',

            /*
             * 固定ページ
             */
            '#^/admin/pages/store$#',
            '#^/admin/pages/update/[^/]+$#',
            '#^/admin/pages/delete/[^/]+$#',
            '#^/admin/pages/status/[^/]+$#',

            /*
             * カテゴリ
             */
            '#^/admin/categories/store$#',
            '#^/admin/categories/update/[^/]+$#',
            '#^/admin/categories/delete/[^/]+$#',

            /*
             * テーマ
             */
            '#^/admin/themes/update$#',

            /*
             * サイト基本設定
             */
            '#^/admin/settings/general/update$#',
            '#^/admin/settings/url/update$#',

            /*
             * 拡張機能の変更
             */
            '#^/admin/extensions/install/[^/]+$#',
            '#^/admin/extensions/enable/[^/]+$#',
            '#^/admin/extensions/disable/[^/]+$#',
            '#^/admin/extensions/delete/[^/]+$#',
        ];

        foreach ($patterns as $pattern) {
            if (
                preg_match(
                    $pattern,
                    $requestPath
                ) === 1
            ) {
                return true;
            }
        }

        return false;
    }

    /*
     * NexaPressの設置先を除いた
     * リクエストパスを取得
     */
    private static function requestPath(): string
    {
        $requestUri =
            $_SERVER['REQUEST_URI']
            ?? '/';

        $path = parse_url(
            $requestUri,
            PHP_URL_PATH
        );

        if (!is_string($path)) {
            return '/';
        }

        $baseUrl = defined('BASE_URL')
            ? rtrim(BASE_URL, '/')
            : '';

        if (
            $baseUrl !== '' &&
            str_starts_with(
                $path,
                $baseUrl
            )
        ) {
            $path = substr(
                $path,
                strlen($baseUrl)
            );
        }

        $path =
            '/' . ltrim($path, '/');

        if ($path !== '/') {
            $path = rtrim($path, '/');
        }

        return $path;
    }
}