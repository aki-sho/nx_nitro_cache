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
            'max_size_mb' => 100,
            'max_files' => 1000,
            'max_page_kb' => 2048,
            'allowed_hosts' => [],
            'invalidation_mode' => 'related',
            'metrics_enabled' => true,
            'ttl_rules' => [],
            'warmup_enabled' => false,
            'warm_base_url' => '',
            'warmup_paths' => ['/', '/posts'],
            'warm_allow_private' => false,
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
            return array_replace($defaults, ['enabled' => false]);
        }

        $savedSettings = json_decode(
            $json,
            true
        );

        if (!is_array($savedSettings)) {
            return array_replace($defaults, ['enabled' => false]);
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

            'max_size_mb' => max(1, min(1024, (int)($input['max_size_mb'] ?? 100))),
            'max_files' => max(1, min(10000, (int)($input['max_files'] ?? 1000))),
            'max_page_kb' => max(16, min(10240, (int)($input['max_page_kb'] ?? 2048))),
            'allowed_hosts' => self::normalizeHosts($input['allowed_hosts'] ?? []),
            'invalidation_mode' => ($input['invalidation_mode'] ?? 'related') === 'all' ? 'all' : 'related',
            'metrics_enabled' => !empty($input['metrics_enabled']),
            'ttl_rules' => self::normalizeTtlRules($input['ttl_rules'] ?? []),
            'warmup_enabled' => !empty($input['warmup_enabled']),
            'warm_base_url' => trim((string)($input['warm_base_url'] ?? '')),
            'warmup_paths' => self::normalizePublicPaths($input['warmup_paths'] ?? ['/', '/posts']),
            'warm_allow_private' => !empty($input['warm_allow_private']),
        ];

        if ($settings['warm_base_url'] !== '') {
            $base = CacheUrl::base($settings['warm_base_url']);
            if ($base === null) {
                throw new RuntimeException('事前生成のサイトURLをhttp://またはhttps://で入力してください。');
            }
            $parts = parse_url($base);
            $authority = $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
            $allowed = array_filter($settings['allowed_hosts'], static function (string $host) use ($authority, $parts): bool {
                return CacheUrl::host($host, $parts['scheme']) === CacheUrl::host($authority, $parts['scheme']);
            });
            if ($allowed === []) {
                throw new RuntimeException('事前生成のサイトURLは許可するHostに登録してください。');
            }
            $settings['warm_base_url'] = $base;
        }
        if ($settings['warmup_enabled'] && $settings['warm_base_url'] === '') {
            throw new RuntimeException('事前生成を有効にする場合はサイトURLを入力してください。');
        }
        self::write($settings);
    }

    public static function ttlForPath(string $path, ?array $settings = null): int
    {
        $settings = $settings ?? self::all();
        $ttl = max(60, min(604800, (int)$settings['ttl']));
        $best = -1;
        $path = rawurldecode($path);
        foreach ((array)$settings['ttl_rules'] as $rule) {
            if (!is_array($rule) || !is_string($rule['path'] ?? null)) { continue; }
            $pattern = rawurldecode($rule['path']);
            $wildcard = str_ends_with($pattern, '*');
            $prefix = $wildcard ? substr($pattern, 0, -1) : $pattern;
            $rank = strlen($prefix) * 2 + ($wildcard ? 0 : 1);
            if (($wildcard ? str_starts_with($path, $prefix) : $path === $prefix) && $rank > $best) {
                $ttl = max(60, min(604800, (int)($rule['ttl'] ?? $ttl)));
                $best = $rank;
            }
        }
        return $ttl;
    }

    public static function normalizePublicPaths(mixed $paths): array
    {
        if (is_string($paths)) { $paths = preg_split('/\R/', $paths); }
        if (!is_array($paths) || count($paths) > 200) {
            throw new RuntimeException('URLは1行につき1件、200件以内で入力してください。');
        }
        $result = [];
        foreach ($paths as $path) {
            if (!is_string($path)) { throw new RuntimeException('URLの形式が正しくありません。'); }
            $path = trim($path);
            if ($path === '') { continue; }
            $valid = CacheUrl::path($path);
            if ($valid === null) { throw new RuntimeException('サイト内のパスを入力してください（例：/post/example）。'); }
            $result[] = $valid;
        }
        return array_values(array_unique($result));
    }

    private static function normalizeTtlRules(mixed $rules): array
    {
        if (is_string($rules)) {
            $lines = preg_split('/\R/', $rules);
            $rules = [];
            foreach ($lines as $line) {
                if (trim($line) === '') { continue; }
                if (!preg_match('/^\s*(\S+)\s+([0-9]+)\s*$/', $line, $matches)) {
                    throw new RuntimeException('保存時間のルールは「/post/* 3600」の形式で入力してください。');
                }
                $rules[] = ['path' => $matches[1], 'ttl' => (int)$matches[2]];
            }
        }
        if (!is_array($rules) || count($rules) > 200) {
            throw new RuntimeException('保存時間のルールは200件以内で入力してください。');
        }
        $normalized = [];
        foreach ($rules as $rule) {
            $pattern = is_array($rule) && is_string($rule['path'] ?? null) ? $rule['path'] : '';
            $wildcard = str_ends_with($pattern, '*');
            $prefix = $wildcard ? substr($pattern, 0, -1) : $pattern;
            $path = CacheUrl::path($prefix);
            $seconds = (int)($rule['ttl'] ?? 0);
            if ($path === null || $seconds < 60 || $seconds > 604800 || str_contains($prefix, '*')) {
                throw new RuntimeException('保存時間は60～604800秒で、URLの末尾だけに*を指定できます。');
            }
            if ($wildcard && $path !== '/' && str_ends_with($prefix, '/')) { $path .= '/'; }
            $normalized[] = ['path' => $path . ($wildcard ? '*' : ''), 'ttl' => $seconds];
        }
        return $normalized;
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

        $temporary = tempnam($directory, '.settings-');
        if ($temporary === false) {
            throw new RuntimeException('設定を保存できません。');
        }
        try {
            if (file_put_contents($temporary, $json) !== strlen($json) ||
                !rename($temporary, $path)) {
                throw new RuntimeException('設定を保存できません。');
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    private static function normalizeHosts(mixed $hosts): array
    {
        if (is_string($hosts)) {
            $hosts = preg_split('/\R/', $hosts);
        }
        if (!is_array($hosts)) {
            throw new RuntimeException('許可するHostを1行につき1件入力してください。');
        }
        $normalized = [];
        foreach ($hosts as $host) {
            if (!is_string($host)) {
                throw new RuntimeException('許可するHostが正しくありません。');
            }
            $host = strtolower(trim($host));
            if ($host === '') {
                continue;
            }
            $parts = parse_url('http://' . $host);
            if (strlen($host) > 260 || preg_match('/[^a-z0-9.\-:\[\]]/', $host) ||
                !is_array($parts) || empty($parts['host']) ||
                (filter_var(trim($parts['host'], '[]'), FILTER_VALIDATE_IP) === false &&
                    filter_var($parts['host'], FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) ||
                (isset($parts['port']) && $parts['port'] < 1)) {
                throw new RuntimeException('許可するHostにはドメイン名と必要なポート番号を入力してください。');
            }
            $normalized[] = $host;
        }
        if (count($normalized) > 50) {
            throw new RuntimeException('許可するHostは50件以内で入力してください。');
        }
        return array_values(array_unique($normalized));
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
