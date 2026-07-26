<?php

namespace NxNitroCache;

/*
 * 公開ページのHTMLキャッシュを制御
 */
class PageCache
{
    /*
     * 二重起動防止
     */
    private static bool $started = false;

    /*
     * 現在のキャッシュキー
     */
    private static ?string $cacheKey = null;

    /*
     * ページキャッシュ処理を開始
     */
    public static function boot(): void
    {
        if (self::$started) {
            return;
        }

        self::$started = true;

        /*
         * キャッシュ対象外の場合は終了
         */
        if (!self::shouldCacheRequest()) {
            return;
        }

        $ttl = (int)Settings::get(
            'ttl',
            3600
        );

        $identifier =
            self::cacheIdentifier();

        $cacheKey =
            CacheManager::buildKey(
                $identifier
            );

        /*
         * 保存済みキャッシュを取得
         */
        $cachedHtml =
            CacheManager::get(
                $cacheKey,
                $ttl
            );

        /*
         * キャッシュが見つかった場合は
         * NexaPress本体の処理を行わず表示
         */
        if ($cachedHtml !== null) {
            if (!headers_sent()) {
                header(
                    'X-NX-Nitro-Cache: HIT'
                );
            }

            echo $cachedHtml;
            exit;
        }

        /*
         * キャッシュがない場合は
         * NexaPressが生成するHTMLを取得
         */
        self::$cacheKey = $cacheKey;

        if (!headers_sent()) {
            header(
                'X-NX-Nitro-Cache: MISS'
            );
        }

        ob_start(
            [self::class, 'captureOutput']
        );
    }

    /*
     * NexaPressが生成したHTMLを保存
     */
    public static function captureOutput(
        string $html,
        int $phase = PHP_OUTPUT_HANDLER_FINAL
    ): string {
        /*
         * 最終出力以外は保存しない
         */
        if (
            ($phase & PHP_OUTPUT_HANDLER_FINAL)
            !== PHP_OUTPUT_HANDLER_FINAL
        ) {
            return $html;
        }

        if (self::$cacheKey === null) {
            return $html;
        }

        if (!self::canStoreResponse($html)) {
            return $html;
        }

        CacheManager::put(
            self::$cacheKey,
            $html
        );

        return $html;
    }

    /*
     * 現在のアクセスを
     * キャッシュしてよいか判定
     */
    private static function shouldCacheRequest(): bool
    {
        /*
         * キャッシュ機能が無効
         */
        if (
            !Settings::get(
                'enabled',
                true
            )
        ) {
            return false;
        }

        /*
         * GET以外はキャッシュしない
         */
        $method = strtoupper(
            $_SERVER['REQUEST_METHOD']
            ?? 'GET'
        );

        if ($method !== 'GET') {
            return false;
        }

        /*
         * ログイン中はキャッシュしない
         */
        if (!empty($_SESSION['user'])) {
            return false;
        }

        /*
         * クエリーパラメータ付きURLは
         * 初期版ではキャッシュしない
         */
        $queryString =
            $_SERVER['QUERY_STRING']
            ?? '';

        if ($queryString !== '') {
            return false;
        }

        $requestPath =
            self::requestPath();

        /*
         * 管理画面やインストール画面を除外
         */
        $reservedPaths = [
            '/admin',
            '/install',
        ];

        foreach ($reservedPaths as $reservedPath) {
            if (
                $requestPath === $reservedPath ||
                str_starts_with(
                    $requestPath,
                    $reservedPath . '/'
                )
            ) {
                return false;
            }
        }

        /*
         * ユーザー設定の除外URLを確認
         */
        if (
            self::isExcludedPath(
                $requestPath
            )
        ) {
            return false;
        }

        return true;
    }

    /*
     * 生成されたレスポンスを
     * 保存してよいか確認
     */
    private static function canStoreResponse(
        string $html
    ): bool {
        if (trim($html) === '') {
            return false;
        }

        /*
         * HTMLページ以外は保存しない
         */
        if (
            stripos(
                $html,
                '<html'
            ) === false
        ) {
            return false;
        }

        /*
         * HTTP 200以外は保存しない
         */
        $statusCode =
            http_response_code();

        if (
            is_int($statusCode) &&
            $statusCode !== 200
        ) {
            return false;
        }

        /*
         * リダイレクトや
         * キャッシュ禁止ヘッダーを確認
         */
        foreach (headers_list() as $header) {
            $normalizedHeader =
                strtolower($header);

            if (
                str_starts_with(
                    $normalizedHeader,
                    'location:'
                )
            ) {
                return false;
            }

            if (
                str_starts_with(
                    $normalizedHeader,
                    'content-type:'
                ) &&
                !str_contains(
                    $normalizedHeader,
                    'text/html'
                )
            ) {
                return false;
            }

            if (
                str_starts_with(
                    $normalizedHeader,
                    'cache-control:'
                ) &&
                (
                    str_contains(
                        $normalizedHeader,
                        'no-store'
                    ) ||
                    str_contains(
                        $normalizedHeader,
                        'private'
                    )
                )
            ) {
                return false;
            }
        }

        return true;
    }

    /*
     * 設定された除外URLか確認
     */
    private static function isExcludedPath(
        string $requestPath
    ): bool {
        $excludedPaths =
            Settings::get(
                'excluded_paths',
                []
            );

        if (!is_array($excludedPaths)) {
            return false;
        }

        foreach ($excludedPaths as $excludedPath) {
            if (!is_string($excludedPath)) {
                continue;
            }

            $excludedPath =
                trim($excludedPath);

            if ($excludedPath === '') {
                continue;
            }

            $excludedPath =
                rawurldecode(
                    $excludedPath
                );

            /*
             * 末尾の*は前方一致
             *
             * 例：
             * /member/*
             */
            if (
                str_ends_with(
                    $excludedPath,
                    '*'
                )
            ) {
                $prefix = substr(
                    $excludedPath,
                    0,
                    -1
                );

                if (
                    str_starts_with(
                        $requestPath,
                        $prefix
                    )
                ) {
                    return true;
                }

                continue;
            }

            if (
                $requestPath
                === $excludedPath
            ) {
                return true;
            }
        }

        return false;
    }

    /*
     * キャッシュキー作成用の
     * 識別文字列を取得
     */
    private static function cacheIdentifier(): string
    {
        $host = strtolower(
            (string)(
                $_SERVER['HTTP_HOST']
                ?? ''
            )
        );

        $baseUrl = defined('BASE_URL')
            ? rtrim(BASE_URL, '/')
            : '';

        return $host
            . '|'
            . $baseUrl
            . '|'
            . self::requestPath();
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

        $path = rawurldecode($path);

        $path =
            '/' . ltrim($path, '/');

        if ($path !== '/') {
            $path = rtrim(
                $path,
                '/'
            );
        }

        return $path;
    }
}