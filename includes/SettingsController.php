<?php

namespace NxNitroCache;

use app\Core\Auth;
use app\Core\Csrf;
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

            /*
             * 設定変更前のキャッシュを削除
             */
            $deletedCount =
                CacheManager::clear();

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
            $deletedCount =
                CacheManager::clear();

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

    /*
     * ログイン状態と
     * CSRFトークンを確認
     */
    private function authorize(): void
    {
        Auth::requireLogin();

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