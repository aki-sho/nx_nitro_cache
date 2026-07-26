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

  if (!clearForm) {
    return;
  }

  clearForm.addEventListener(
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
})();