<?php

use app\Core\Csrf;
use NxNitroCache\CacheManager;
use NxNitroCache\Settings;

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
                            投稿や固定ページなどの更新後に
                            キャッシュを削除します。
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
                    <li>POSTリクエスト</li>
                    <li>クエリー付きURL</li>
                    <li>エラー・リダイレクトページ</li>
                </ul>
            </section>
        </aside>
    </div>
</div>