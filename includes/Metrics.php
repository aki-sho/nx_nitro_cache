<?php

namespace NxNitroCache;

class Metrics
{
    public const REASONS = [
        'disabled' => 'キャッシュ無効', 'method' => 'GET以外',
        'session' => '個別セッション', 'cookie' => 'Cookie付き',
        'authorization' => '認証情報付き', 'range_or_origin' => 'Range・Origin付き',
        'host' => '許可Host未設定・不一致', 'request_no_cache' => '再取得を要求',
        'query' => 'クエリー付き', 'path' => 'URL不正・設置先外',
        'excluded' => '除外URL・管理画面', 'headers_sent' => 'ヘッダー送信済み',
        'status' => 'HTTP 200以外', 'empty_or_non_html' => 'HTML以外・空本文',
        'response_private' => 'private・no-store・no-cache',
        'set_cookie' => 'Cookieを設定する応答', 'vary' => 'Vary付き応答',
        'response_variant' => '圧縮・認証・CORS等の応答',
        'nonce' => 'リクエスト固有のCSP', 'fatal' => 'PHPの致命的エラー',
        'partial' => '途中出力・バッファ破棄', 'storage' => '容量上限・保存不可・更新競合',
    ];

    public static function record(string $kind, ?string $reason = null, float $milliseconds = 0): void
    {
        if (!Settings::get('metrics_enabled', true) ||
            !in_array($kind, ['hit', 'miss', 'bypass', 'stored', 'not_stored'], true)) {
            return;
        }
        try {
            CacheManager::updateState('metrics', static function (array &$state) use ($kind, $reason, $milliseconds): void {
            $state['since'] = $state['since'] ?? time();
            $state[$kind] = min(PHP_INT_MAX - 1, (int)($state[$kind] ?? 0) + 1);
            if ($reason !== null && isset(self::REASONS[$reason])) {
                $state['reasons'][$reason] = (int)($state['reasons'][$reason] ?? 0) + 1;
            }
            if (in_array($kind, ['hit', 'stored'], true)) {
                $state['time_ms'][$kind] = (float)($state['time_ms'][$kind] ?? 0) + max(0, $milliseconds);
            }
            });
        } catch (\Throwable $exception) {
            // 計測情報の保存失敗でページ表示を止めない。
        }
    }

    public static function stats(): array
    {
        $state = CacheManager::readState('metrics');
        $eligible = (int)($state['hit'] ?? 0) + (int)($state['miss'] ?? 0);
        $state['hit_rate'] = $eligible > 0 ? round(100 * (int)($state['hit'] ?? 0) / $eligible, 1) : 0;
        foreach (['hit', 'stored'] as $kind) {
            $state['average_' . $kind . '_ms'] = empty($state[$kind]) ? 0
                : round((float)($state['time_ms'][$kind] ?? 0) / $state[$kind], 2);
        }
        $state['reasons'] = $state['reasons'] ?? [];
        arsort($state['reasons']);
        return $state;
    }

    public static function reset(): void
    {
        CacheManager::updateState('metrics', static function (array &$state): void { $state = []; }, true);
    }
}
