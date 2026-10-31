<?php

namespace NxNitroCache;

use app\Core\Auth;
use app\Core\Csrf;
use app\Core\Permission;
use Throwable;

/*
 * キャッシュ設定画面の処理
 */
class SettingsController
{
    /*
     * キャッシュ設定を保存
     */
    public function save(): void
    {
        $this->authorize();

        try {
            Settings::save($_POST);
            Warmup::cancel();

            /*
             * 設定変更前のキャッシュを削除
             */
            $deletedCount =
                CacheManager::clear();
            Warmup::seed();

            Settings::setNotice(
                'キャッシュ設定を保存しました。'
                . ' '
                . $deletedCount
                . '件のキャッシュを削除しました。'
            );
        } catch (Throwable $exception) {
            Settings::setNotice(
                $exception->getMessage(),
                'error'
            );
        }

        $this->redirectBack();
    }

    /*
     * 全キャッシュを手動削除
     */
    public function clearCache(): void
    {
        $this->authorize();

        try {
            $paths = CacheManager::paths();
            $deletedCount =
                CacheManager::clear();
            Warmup::enqueue(array_merge((array)Settings::get('warmup_paths', ['/', '/posts']), $paths));

            Settings::setNotice(
                $deletedCount
                . '件のキャッシュを削除しました。'
            );
        } catch (Throwable $exception) {
            Settings::setNotice(
                $exception->getMessage(),
                'error'
            );
        }

        $this->redirectBack();
    }

    public function deleteUrls(): void
    {
        $this->authorize();
        try {
            $paths = Settings::normalizePublicPaths($_POST['paths'] ?? '');
            if ($paths === []) { throw new \RuntimeException('削除するURLを入力してください。'); }
            $result = CacheManager::invalidate($paths);
            Warmup::enqueue($paths);
            Settings::setNotice($result['count'] . '件のキャッシュを削除しました。');
        } catch (Throwable $exception) {
            Settings::setNotice($exception->getMessage(), 'error');
        }
        $this->redirectBack();
    }

    public function queueWarmup(): void
    {
        $this->authorize();
        try {
            if (!Settings::get('warmup_enabled', false)) {
                throw new \RuntimeException('設定で事前生成を有効にし、サイトURLを指定してください。');
            }
            $count = Warmup::seed();
            Settings::setNotice($count . '件を事前生成キューに追加しました。');
        } catch (Throwable $exception) {
            Settings::setNotice($exception->getMessage(), 'error');
        }
        $this->redirectBack();
    }

    public function runWarmup(): void
    {
        $this->authorize();
        if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store');
        try {
            echo json_encode(Warmup::run(1), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
        } catch (Throwable $exception) {
            http_response_code(500);
            echo json_encode(['error' => '事前生成の状態を更新できません。'], JSON_UNESCAPED_UNICODE);
        }
    }

    public function cancelWarmup(): void
    {
        $this->authorize();
        Warmup::cancel();
        Settings::setNotice('事前生成キューを停止しました。');
        $this->redirectBack();
    }

    public function resetMetrics(): void
    {
        $this->authorize();
        Metrics::reset();
        Settings::setNotice('効果測定の集計をリセットしました。');
        $this->redirectBack();
    }

    /*
     * ログイン状態と
     * CSRFトークンを確認
     */
    private function authorize(): void
    {
        Auth::requireLogin();

        if (class_exists(Permission::class) && is_callable([Permission::class, 'require'])) {
            Permission::require('extensions.manage');
        } elseif ((Auth::user()['role'] ?? '') !== 'admin') {
            http_response_code(403);
            exit('この操作を行う権限がありません。');
        }

        Csrf::requireValid(
            $_POST['_csrf_token']
            ?? null
        );
    }

    /*
     * キャッシュ管理画面へ戻る
     */
    private function redirectBack(): void
    {
        redirect_to(
            'admin/extensions/'
            . 'nx_nitro_cache/dashboard'
        );
    }
}
