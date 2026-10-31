/*
 * NX Nitro Cache
 * 管理画面操作
 */
(() => {
  'use strict';

  /*
   * 全キャッシュ削除フォーム
   */
  const clearForm = document.querySelector(
    '[data-nitro-clear-form]'
  );

  clearForm?.addEventListener(
    'submit',
    (event) => {
      const confirmed = window.confirm(
        '保存されている全キャッシュを削除します。'
        + '\nこの操作を実行しますか？'
      );

      if (!confirmed) {
        event.preventDefault();
        return;
      }

      const clearButton =
        clearForm.querySelector(
          '[data-nitro-clear-button]'
        );

      if (!clearButton) {
        return;
      }

      clearButton.disabled = true;
      clearButton.textContent =
        'キャッシュを削除中...';
    }
  );

  const warmup = document.querySelector('[data-nitro-warmup]');
  const status = warmup?.querySelector('[data-nitro-warmup-status]');
  let stopped = false;
  warmup?.querySelector('[data-nitro-warmup-stop]')?.addEventListener('click', () => {
    stopped = true;
  });

  const runNext = async () => {
    if (stopped || !warmup || !status) return;
    status.textContent = '事前生成を実行中...';
    try {
      const response = await fetch(warmup.dataset.endpoint, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ _csrf_token: warmup.dataset.token }),
      });
      if (!response.ok) throw new Error('事前生成を継続できません。画面を更新して確認してください。');
      const result = await response.json();
      status.textContent = '待機 ' + (result.pending || 0) + '件 / 完了 '
        + (result.completed || 0) + '件 / 対象外・失敗 ' + (result.skipped || 0)
        + '件' + (result.last_result ? '：' + result.last_result : '');
      if (result.ready > 0 && !stopped) window.setTimeout(runNext, 1000);
    } catch (error) {
      status.textContent = error.message;
    }
  };
  if (Number(warmup?.dataset.ready || 0) > 0) runNext();
})();
