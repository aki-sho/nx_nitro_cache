<?php

use app\Core\Router;
use NxNitroCache\CacheCleaner;
use NxNitroCache\PageCache;
use NxNitroCache\SettingsController;

/*
 * NexaPress以外から
 * 直接実行された場合は終了
 */
if (
    !defined('BASE_PATH') ||
    !isset($router) ||
    !$router instanceof Router
) {
    return;
}

/*
 * 拡張機能のPHPファイルを読み込む
 */
$includeFiles = [
    __DIR__ . '/includes/Settings.php',
    __DIR__ . '/includes/CacheManager.php',
    __DIR__ . '/includes/CacheCleaner.php',
    __DIR__ . '/includes/PageCache.php',
    __DIR__ . '/includes/SettingsController.php',
];

foreach ($includeFiles as $includeFile) {
    if (is_file($includeFile)) {
        require_once $includeFile;
    }
}

/*
 * キャッシュ設定画面の処理URL
 */
$settingsBase =
    '/admin/extensions/'
    . 'nx_nitro_cache';

/*
 * キャッシュ設定を保存
 */
$router->post(
    $settingsBase . '/settings',
    SettingsController::class . '@save'
);

/*
 * 全キャッシュを削除
 */
$router->post(
    $settingsBase . '/clear-cache',
    SettingsController::class
    . '@clearCache'
);

/*
 * 投稿・固定ページなどの更新後に
 * キャッシュを自動削除
 */
if (class_exists(CacheCleaner::class)) {
    CacheCleaner::boot();
}

/*
 * 公開ページの
 * HTMLキャッシュ処理を開始
 */
if (class_exists(PageCache::class)) {
    PageCache::boot();
}

/*
 * キャッシュ管理画面か確認
 */
$isCacheDashboard =
    static function (): bool {
        $requestPath = parse_url(
            $_SERVER['REQUEST_URI']
            ?? '',
            PHP_URL_PATH
        );

        if (!is_string($requestPath)) {
            return false;
        }

        return str_contains(
            $requestPath,
            '/admin/extensions/'
            . 'nx_nitro_cache/dashboard'
        );
    };

/*
 * キャッシュ管理画面用CSS
 */
if (function_exists('add_action')) {
    add_action(
        'admin_head',
        static function () use (
            $isCacheDashboard
        ): void {
            if (!$isCacheDashboard()) {
                return;
            }

            $cssFile =
                __DIR__
                . '/assets/css/cache.css';

            if (!is_file($cssFile)) {
                return;
            }

            echo '<style>';
            readfile($cssFile);
            echo '</style>';
        }
    );

    /*
     * キャッシュ管理画面用JavaScript
     */
    add_action(
        'admin_footer',
        static function () use (
            $isCacheDashboard
        ): void {
            if (!$isCacheDashboard()) {
                return;
            }

            $jsFile =
                __DIR__
                . '/assets/js/cache.js';

            if (!is_file($jsFile)) {
                return;
            }

            echo '<script>';
            readfile($jsFile);
            echo '</script>';
        }
    );
}