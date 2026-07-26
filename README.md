# NX Nitro Cache

NX Nitro Cacheは、NexaPressの公開ページをHTMLファイルとして保存し、ページ表示を高速化する拡張機能です。

一度生成されたページをキャッシュとして保存し、次回以降のアクセスではデータベース処理やHTML生成処理を減らします。

## 主な機能

- 公開ページのHTMLキャッシュ
- キャッシュ機能の有効化・無効化
- キャッシュ保存時間の設定
- キャッシュ対象外URLの設定
- 管理画面からの全キャッシュ削除
- キャッシュファイル数の表示
- キャッシュ使用容量の表示
- 投稿更新時のキャッシュ自動削除
- 固定ページ更新時のキャッシュ自動削除
- カテゴリ更新時のキャッシュ自動削除
- テーマ変更時のキャッシュ自動削除
- ログイン中の管理者はキャッシュ対象外
- 管理画面はキャッシュ対象外
- POST通信はキャッシュ対象外

## 動作環境

- NexaPress 2.2.0以上
- PHP 8.0以上

## インストール

NexaPress管理画面の拡張機能ページから、ZIPファイルをアップロードしてインストールします。

```text
nx_nitro_cache-1.0.0.zip
```

インストール後、拡張機能を有効化してください。

## キャッシュ保存先

キャッシュファイルは、NexaPress本体の次の場所へ保存されます。

```text
storage/cache/nx_nitro_cache/
```

拡張機能のリポジトリ内にはキャッシュファイルを保存しません。

## キャッシュされるページ

初期設定では、次の公開ページがキャッシュ対象になります。

- トップページ
- 投稿ページ
- 固定ページ
- カテゴリを含む投稿ページ
- カスタムURL形式の公開ページ

## キャッシュされないページ

次のアクセスはキャッシュされません。

- NexaPress管理画面
- ログインページ
- インストール画面
- POSTリクエスト
- ログイン中の管理者
- クエリーパラメータを含むURL
- 設定で除外したURL
- エラーページ
- リダイレクトレスポンス

## フォルダ構成

```text
nx_nitro_cache/
│  .gitignore
│  LICENSE
│  README.md
│  bootstrap.php
│  img.png
│  manifest.json
│
├─admin
│      dashboard.php
│
├─assets
│  ├─css
│  │      cache.css
│  │
│  └─js
│          cache.js
│
└─includes
        CacheCleaner.php
        CacheManager.php
        PageCache.php
        Settings.php
        SettingsController.php
```

## 各ファイルの役割

### `bootstrap.php`

拡張機能のPHPファイルを読み込み、管理画面のルートとページキャッシュ処理を登録します。

### `includes/Settings.php`

キャッシュの有効状態、保存時間、除外URLなどの設定を管理します。

### `includes/SettingsController.php`

管理画面から送信された設定の保存とキャッシュ削除を処理します。

### `includes/CacheManager.php`

キャッシュファイルの保存、取得、削除、件数取得、容量計算を担当します。

### `includes/PageCache.php`

公開ページへのアクセスを判定し、キャッシュの読込みとHTML保存を行います。

### `includes/CacheCleaner.php`

投稿、固定ページ、カテゴリ、テーマなどが更新された場合にキャッシュを削除します。

### `admin/dashboard.php`

キャッシュ設定とキャッシュ状況を表示する管理画面です。

## リリースZIP

リリース用ZIPには、拡張機能フォルダを1つ含めてください。

正しい構造：

```text
nx_nitro_cache-1.0.0.zip
└─nx_nitro_cache
    │  manifest.json
    │  bootstrap.php
    │  README.md
    │  LICENSE
    │  img.png
    │
    ├─admin
    ├─assets
    └─includes
```

ZIP直下には、必ず `nx_nitro_cache` フォルダを配置します。

`manifest.json` などのファイルをZIP直下へ直接配置すると、NexaPressの拡張機能アップロードでは認識されません。

## ライセンス

MIT License