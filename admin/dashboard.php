<?php

use app\Core\Csrf;
use NxNitroCache\CacheManager;
use NxNitroCache\Settings;
use NxNitroCache\Metrics;
use NxNitroCache\Warmup;

/*
 * キャッシュ設定を取得
 */
$settings = Settings::all();

$enabled = !empty(
    $settings['enabled']
);

$autoClear = !empty(
    $settings['auto_clear']
);

$ttl = (int)(
    $settings['ttl']
    ?? 3600
);

$excludedPaths =
    $settings['excluded_paths']
    ?? [];

if (!is_array($excludedPaths)) {
    $excludedPaths = [];
}

$excludedPathsText = implode(
    PHP_EOL,
    $excludedPaths
);

/*
 * キャッシュ状況を取得
 */
$stats = CacheManager::stats();
$metrics = Metrics::stats();
$warmup = Warmup::stats();
$ttlRulesText = implode(PHP_EOL, array_map(
    static fn(array $rule): string => $rule['path'] . ' ' . $rule['ttl'],
    is_array($settings['ttl_rules'] ?? null) ? $settings['ttl_rules'] : []
));

$notice = Settings::pullNotice();

$csrfToken = Csrf::token();

/*
 * キャッシュ保存時間の選択肢
 */
$ttlOptions = [
    300 => '5分',
    900 => '15分',
    1800 => '30分',
    3600 => '1時間',
    10800 => '3時間',
    21600 => '6時間',
    43200 => '12時間',
    86400 => '1日',
    259200 => '3日',
    604800 => '7日',
];

/*
 * 保存時刻を表示用に変換
 */
$formatDate = static function (
    mixed $timestamp
): string {
    if (
        !is_int($timestamp) &&
        !ctype_digit((string)$timestamp)
    ) {
        return '—';
    }

    return date(
        'Y/m/d H:i:s',
        (int)$timestamp
    );
};
?>

<div class="nx-nitro-cache">
    <!-- ページ上部 -->
    <div class="nx-nitro-cache-header">
        <div>
            <div class="nx-nitro-cache-label">
                NX PERFORMANCE EXTENSION
            </div>

            <h1>NX Nitro Cache</h1>

            <p>
                公開ページのHTMLを保存し、
                NexaPressの表示処理を高速化します。
            </p>
        </div>

        <div
            class="
                nx-nitro-cache-status
                <?= $enabled
                    ? 'is-enabled'
                    : 'is-disabled' ?>
            "
        >
            <span class="nx-nitro-cache-status-dot"></span>

            <span>
                <?= $enabled
                    ? 'キャッシュ有効'
                    : 'キャッシュ無効' ?>
            </span>
        </div>
    </div>

    <!-- 保存結果 -->
    <?php if ($notice !== null): ?>
        <?php
        $noticeType =
            ($notice['type'] ?? '') === 'error'
                ? 'error'
                : 'success';
        ?>

        <div
            class="
                nx-nitro-cache-notice
                nx-nitro-cache-notice-<?= e(
                    $noticeType
                ) ?>
            "
        >
            <?= e(
                (string)(
                    $notice['message']
                    ?? ''
                )
            ) ?>
        </div>
    <?php endif; ?>

    <!-- キャッシュ状況 -->
    <div class="nx-nitro-cache-stats">
        <section class="nx-nitro-cache-stat">
            <span>キャッシュファイル</span>

            <strong>
                <?= number_format(
                    (int)$stats['file_count']
                ) ?>
            </strong>

            <small>ファイル</small>
        </section>

        <section class="nx-nitro-cache-stat">
            <span>使用容量</span>

            <strong>
                <?= e(
                    (string)$stats[
                        'formatted_size'
                    ]
                ) ?>
            </strong>

            <small>現在の保存容量</small>
        </section>

        <section class="nx-nitro-cache-stat">
            <span>最終キャッシュ</span>

            <strong class="nx-nitro-cache-stat-date">
                <?= e(
                    $formatDate(
                        $stats['newest_at']
                    )
                ) ?>
            </strong>

            <small>最新の更新日時</small>
        </section>
    </div>

    <section class="nx-nitro-cache-card nx-nitro-cache-metrics">
        <h2>キャッシュの効果</h2>
        <div class="nx-nitro-cache-stats">
            <section class="nx-nitro-cache-stat">
                <span>HIT率</span>
                <strong><?= e((string)$metrics['hit_rate']) ?>%</strong>
                <small>HIT ÷（HIT＋MISS）</small>
            </section>
            <section class="nx-nitro-cache-stat">
                <span>HIT / MISS / 対象外</span>
                <strong class="nx-nitro-cache-stat-date">
                    <?= number_format((int)($metrics['hit'] ?? 0)) ?> /
                    <?= number_format((int)($metrics['miss'] ?? 0)) ?> /
                    <?= number_format((int)($metrics['bypass'] ?? 0)) ?>
                </strong>
                <small>事前生成のアクセスを含む概数です。</small>
            </section>
            <section class="nx-nitro-cache-stat">
                <span>平均処理時間（HIT / 生成）</span>
                <strong class="nx-nitro-cache-stat-date">
                    <?= e((string)$metrics['average_hit_ms']) ?> /
                    <?= e((string)$metrics['average_stored_ms']) ?> ms
                </strong>
                <small>拡張機能開始からの経過時間。総表示時間の計測ではありません。</small>
            </section>
        </div>
        <p>保存成功 <?= number_format((int)($metrics['stored'] ?? 0)) ?>件 /
            MISS後に保存しなかった応答 <?= number_format((int)($metrics['not_stored'] ?? 0)) ?>件</p>
        <details>
            <summary>対象外・保存しなかった理由</summary>
            <?php if ($metrics['reasons'] === []): ?>
                <p>計測データはまだありません。</p>
            <?php else: ?>
                <dl class="nx-nitro-cache-details">
                    <?php foreach ($metrics['reasons'] as $reason => $count): ?>
                        <div><dt><?= e(Metrics::REASONS[$reason] ?? 'その他') ?></dt>
                            <dd><?= number_format((int)$count) ?>件</dd></div>
                    <?php endforeach; ?>
                </dl>
            <?php endif; ?>
        </details>
        <form method="post" action="<?= e(url('admin/extensions/nx_nitro_cache/reset-metrics')) ?>">
            <input type="hidden" name="_csrf_token" value="<?= e($csrfToken) ?>">
            <button type="submit" class="nx-nitro-cache-button">集計をリセット</button>
        </form>
    </section>

    <div class="nx-nitro-cache-grid">
        <!-- キャッシュ設定 -->
        <section class="nx-nitro-cache-card">
            <div class="nx-nitro-cache-card-header">
                <div>
                    <h2>キャッシュ設定</h2>

                    <p>
                        ページキャッシュの動作を設定します。
                    </p>
                </div>
            </div>

            <form
                method="post"
                action="<?= url(
                    'admin/extensions/'
                    . 'nx_nitro_cache/settings'
                ) ?>"
                class="nx-nitro-cache-form"
            >
                <input
                    type="hidden"
                    name="_csrf_token"
                    value="<?= e($csrfToken) ?>"
                >

                <!-- キャッシュ有効設定 -->
                <div class="nx-nitro-cache-setting">
                    <div>
                        <label
                            for="nx-nitro-enabled"
                            class="nx-nitro-cache-setting-title"
                        >
                            ページキャッシュ
                        </label>

                        <p>
                            公開ページのHTMLキャッシュを
                            有効にします。
                        </p>
                    </div>

                    <label class="nx-nitro-cache-switch">
                        <input
                            id="nx-nitro-enabled"
                            type="checkbox"
                            name="enabled"
                            value="1"
                            <?= $enabled
                                ? 'checked'
                                : '' ?>
                        >

                        <span></span>
                    </label>
                </div>

                <!-- 自動削除設定 -->
                <div class="nx-nitro-cache-setting">
                    <div>
                        <label
                            for="nx-nitro-auto-clear"
                            class="nx-nitro-cache-setting-title"
                        >
                            更新時の自動削除
                        </label>

                        <p>
                            更新成功時に、指定した範囲のキャッシュを削除します。
                        </p>
                    </div>

                    <label class="nx-nitro-cache-switch">
                        <input
                            id="nx-nitro-auto-clear"
                            type="checkbox"
                            name="auto_clear"
                            value="1"
                            <?= $autoClear
                                ? 'checked'
                                : '' ?>
                        >

                        <span></span>
                    </label>
                </div>

                <div class="nx-nitro-cache-field">
                    <label for="nx-nitro-invalidation">更新時に削除する範囲</label>
                    <select id="nx-nitro-invalidation" name="invalidation_mode">
                        <option value="related" <?= ($settings['invalidation_mode'] ?? 'related') === 'related' ? 'selected' : '' ?>>関連ページだけ</option>
                        <option value="all" <?= ($settings['invalidation_mode'] ?? '') === 'all' ? 'selected' : '' ?>>全ページ</option>
                    </select>
                    <p>記事・固定ページ、トップ、関連カテゴリ一覧を削除します。
                        テーマ・共通設定の変更や依存先を判定できない更新は全削除します。
                        全ページに記事一覧やメニューを独自表示するテーマでは「全ページ」を選択してください。</p>
                </div>

                <!-- 保存時間 -->
                <div class="nx-nitro-cache-field">
                    <label for="nx-nitro-ttl">
                        キャッシュ保存時間
                    </label>

                    <select
                        id="nx-nitro-ttl"
                        name="ttl"
                    >
                        <?php foreach (
                            $ttlOptions
                            as $seconds => $label
                        ): ?>
                            <option
                                value="<?= $seconds ?>"
                                <?= $ttl === $seconds
                                    ? 'selected'
                                    : '' ?>
                            >
                                <?= e($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <p>
                        保存時間を過ぎたキャッシュは、
                        次回アクセス時に再生成されます。
                    </p>
                </div>

                <!-- 除外URL -->
                <div class="nx-nitro-cache-field">
                    <label for="nx-nitro-ttl-rules">URLごとの保存時間（秒）</label>
                    <textarea id="nx-nitro-ttl-rules" name="ttl_rules" rows="5"
                        placeholder="/ 300&#10;/post/* 3600&#10;/page/about 86400"><?= e($ttlRulesText) ?></textarea>
                    <p>1行につき「パス 秒数」を入力します。60～604800秒。
                        長く具体的なパスを優先し、同じパスでは完全一致を優先します。
                        指定のないURLには共通の保存時間を使います。</p>
                </div>

                <div class="nx-nitro-cache-field">
                    <label for="nx-nitro-excluded-paths">
                        キャッシュ対象外URL
                    </label>

                    <textarea
                        id="nx-nitro-excluded-paths"
                        name="excluded_paths"
                        rows="8"
                        placeholder="/contact&#10;/member/*&#10;/special-page"
                    ><?= e($excludedPathsText) ?></textarea>

                    <p>
                        1行につき1件入力します。
                        末尾に「*」を付けると、
                        そのURL以下をまとめて除外できます。
                    </p>
                </div>

                <div class="nx-nitro-cache-field">
                    <label for="nx-nitro-max-size">キャッシュ容量の上限（MiB）</label>
                    <input id="nx-nitro-max-size" type="number" name="max_size_mb"
                        min="1" max="1024" required
                        value="<?= (int)($settings['max_size_mb'] ?? 100) ?>">
                    <p>上限に達した場合は古いキャッシュから削除します。</p>
                </div>

                <div class="nx-nitro-cache-field">
                    <label for="nx-nitro-max-files">キャッシュ件数の上限</label>
                    <input id="nx-nitro-max-files" type="number" name="max_files"
                        min="1" max="10000" required
                        value="<?= (int)($settings['max_files'] ?? 1000) ?>">
                </div>

                <div class="nx-nitro-cache-field">
                    <label for="nx-nitro-max-page">1ページの上限（KiB）</label>
                    <input id="nx-nitro-max-page" type="number" name="max_page_kb"
                        min="16" max="10240" required
                        value="<?= (int)($settings['max_page_kb'] ?? 2048) ?>">
                    <p>上限を超えるページは保存せず、そのまま表示します。</p>
                </div>

                <div class="nx-nitro-cache-field">
                    <label for="nx-nitro-hosts">キャッシュを許可するHost</label>
                    <textarea id="nx-nitro-hosts" name="allowed_hosts" rows="3"
                        placeholder="example.com&#10;www.example.com"><?= e(implode(
                            PHP_EOL,
                            is_array($settings['allowed_hosts'] ?? null)
                                ? $settings['allowed_hosts'] : []
                        )) ?></textarea>
                    <p>
                        ドメイン名を1行につき1件入力します。標準以外のポートは
                        example.com:8080 の形式で指定します。
                        空欄ではキャッシュを使わず通常表示します。
                        サイトで使用するドメインを明示してください。
                    </p>
                </div>

                <div class="nx-nitro-cache-field">
                    <label><input type="checkbox" name="metrics_enabled" value="1"
                        <?= !empty($settings['metrics_enabled']) ? 'checked' : '' ?>> 効果測定を有効にする</label>
                    <p>件数・理由・処理時間だけを集計します。IPや認証情報は記録しません。</p>
                </div>

                <div class="nx-nitro-cache-field">
                    <label><input type="checkbox" name="warmup_enabled" value="1"
                        <?= !empty($settings['warmup_enabled']) ? 'checked' : '' ?>> 更新・削除後に事前生成する</label>
                    <p>対象URLをキューへ追加します。この管理画面を開いている間、
                        または定期実行ワーカーから、1件ずつ取得します。</p>
                </div>
                <div class="nx-nitro-cache-field">
                    <label for="nx-nitro-warm-base">事前生成するサイトURL</label>
                    <input id="nx-nitro-warm-base" type="url" name="warm_base_url"
                        value="<?= e((string)($settings['warm_base_url'] ?? '')) ?>"
                        placeholder="https://example.com/blog">
                    <p>設置先のパスまで指定します。Hostの許可設定にも登録してください。</p>
                </div>
                <div class="nx-nitro-cache-field">
                    <label for="nx-nitro-warm-paths">事前生成する主なURL</label>
                    <textarea id="nx-nitro-warm-paths" name="warmup_paths" rows="5"
                        placeholder="/&#10;/posts"><?= e(implode(PHP_EOL, (array)($settings['warmup_paths'] ?? ['/', '/posts']))) ?></textarea>
                    <p>設置先からのパスを1行に1件、200件まで指定します。
                        更新した記事と削除対象の保存済みURLも自動追加します。
                        Cookieや認証情報は送信せず、転送先へは移動しません。
                        キャッシュ禁止の応答は保存しません。</p>
                </div>
                <div class="nx-nitro-cache-field">
                    <label><input type="checkbox" name="warm_allow_private" value="1"
                        <?= !empty($settings['warm_allow_private']) ? 'checked' : '' ?>> 開発・イントラ環境の内部アドレスへの接続を許可する</label>
                    <p>通常の公開サイトではオフにします。</p>
                </div>

                <div class="nx-nitro-cache-actions">
                    <button
                        type="submit"
                        class="
                            nx-nitro-cache-button
                            nx-nitro-cache-button-primary
                        "
                    >
                        設定を保存
                    </button>
                </div>
            </form>
        </section>

        <!-- キャッシュ管理 -->
        <aside class="nx-nitro-cache-side">
            <section class="nx-nitro-cache-card">
                <div class="nx-nitro-cache-card-header">
                    <div>
                        <h2>キャッシュ管理</h2>

                        <p>
                            保存済みキャッシュを管理します。
                        </p>
                    </div>
                </div>

                <dl class="nx-nitro-cache-details">
                    <div>
                        <dt>最古のキャッシュ</dt>

                        <dd>
                            <?= e(
                                $formatDate(
                                    $stats['oldest_at']
                                )
                            ) ?>
                        </dd>
                    </div>

                    <div>
                        <dt>最新のキャッシュ</dt>

                        <dd>
                            <?= e(
                                $formatDate(
                                    $stats['newest_at']
                                )
                            ) ?>
                        </dd>
                    </div>

                    <div>
                        <dt>保存場所</dt>

                        <dd>
                            <code>
                                storage/cache/nx_nitro_cache
                            </code>
                        </dd>
                    </div>
                </dl>

                <form
                    method="post"
                    action="<?= url(
                        'admin/extensions/'
                        . 'nx_nitro_cache/clear-cache'
                    ) ?>"
                    data-nitro-clear-form
                >
                    <input
                        type="hidden"
                        name="_csrf_token"
                        value="<?= e($csrfToken) ?>"
                    >

                    <button
                        type="submit"
                        class="
                            nx-nitro-cache-button
                            nx-nitro-cache-button-danger
                        "
                        data-nitro-clear-button
                    >
                        全キャッシュを削除
                    </button>
                </form>
            </section>

            <section class="nx-nitro-cache-card">
                <h2>URLを指定して削除</h2>
                <form method="post" action="<?= e(url('admin/extensions/nx_nitro_cache/delete-urls')) ?>">
                    <input type="hidden" name="_csrf_token" value="<?= e($csrfToken) ?>">
                    <div class="nx-nitro-cache-field">
                        <label for="nx-nitro-delete-paths">削除するURL</label>
                        <textarea id="nx-nitro-delete-paths" name="paths" rows="4"
                            placeholder="/post/example&#10;/page/about" required></textarea>
                        <p>設置先からのパスを1行に1件入力します。前方一致の*は使いません。</p>
                    </div>
                    <button type="submit" class="nx-nitro-cache-button">指定したキャッシュを削除</button>
                </form>
            </section>

            <section class="nx-nitro-cache-card" data-nitro-warmup
                data-endpoint="<?= e(url('admin/extensions/nx_nitro_cache/run-warmup')) ?>"
                data-token="<?= e($csrfToken) ?>"
                data-ready="<?= !empty($settings['warmup_enabled']) ? (int)$warmup['ready'] : 0 ?>">
                <h2>キャッシュの事前生成</h2>
                <p data-nitro-warmup-status aria-live="polite">
                    待機 <?= (int)$warmup['pending'] ?>件 /
                    完了 <?= (int)($warmup['completed'] ?? 0) ?>件 /
                    対象外・失敗 <?= (int)($warmup['skipped'] ?? 0) ?>件
                </p>
                <p><?= e((string)($warmup['last_result'] ?? '')) ?></p>
                <form method="post" action="<?= e(url('admin/extensions/nx_nitro_cache/queue-warmup')) ?>">
                    <input type="hidden" name="_csrf_token" value="<?= e($csrfToken) ?>">
                    <button type="submit" class="nx-nitro-cache-button nx-nitro-cache-button-primary">事前生成を開始</button>
                </form>
                <form method="post" action="<?= e(url('admin/extensions/nx_nitro_cache/cancel-warmup')) ?>">
                    <input type="hidden" name="_csrf_token" value="<?= e($csrfToken) ?>">
                    <button type="submit" class="nx-nitro-cache-button" data-nitro-warmup-stop>キューを停止</button>
                </form>
                <p>停止時も実行中の1件は完了します。
                    画面を閉じても実行するには、サーバーで同梱のwarm-cache.phpを定期実行してください。
                    PHPのcURL拡張が必要です。</p>
            </section>

            <!-- キャッシュ対象 -->
            <section
                class="
                    nx-nitro-cache-card
                    nx-nitro-cache-info-card
                "
            >
                <h2>キャッシュ対象</h2>

                <ul>
                    <li>トップページ</li>
                    <li>投稿ページ</li>
                    <li>固定ページ</li>
                    <li>公開中のHTMLページ</li>
                </ul>

                <h3>自動的に除外</h3>

                <ul>
                    <li>管理画面・ログイン画面</li>
                    <li>ログイン中のアクセス</li>
                    <li>Cookie・認証情報付きのアクセス</li>
                    <li>POSTリクエスト</li>
                    <li>クエリー付きURL</li>
                    <li>エラー・リダイレクトページ</li>
                </ul>
            </section>
        </aside>
    </div>
</div>
