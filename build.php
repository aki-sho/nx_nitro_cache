<?php

declare(strict_types=1);

/*
 * NX Nitro Cache
 * リリースZIP作成スクリプト
 */

$projectDirectory = __DIR__;
$manifestPath = $projectDirectory . '/manifest.json';

/*
 * ZipArchiveが使用できるか確認
 */
if (!class_exists(ZipArchive::class)) {
    exit(
        "エラー：PHPのZipArchiveが有効ではありません。"
        . PHP_EOL
    );
}

/*
 * manifest.jsonを確認
 */
if (!is_file($manifestPath)) {
    exit(
        "エラー：manifest.jsonが見つかりません。"
        . PHP_EOL
    );
}

try {
    $manifest = json_decode(
        (string)file_get_contents($manifestPath),
        true,
        512,
        JSON_THROW_ON_ERROR
    );
} catch (JsonException $exception) {
    exit(
        'エラー：manifest.jsonが正しくありません。'
        . PHP_EOL
        . $exception->getMessage()
        . PHP_EOL
    );
}

$extensionId = trim(
    (string)($manifest['id'] ?? '')
);

$version = trim(
    (string)($manifest['version'] ?? '')
);

/*
 * 拡張機能IDを確認
 */
if (
    $extensionId === '' ||
    !preg_match(
        '/^[a-zA-Z0-9_-]+$/',
        $extensionId
    )
) {
    exit(
        "エラー：拡張機能IDが正しくありません。"
        . PHP_EOL
    );
}

/*
 * バージョン番号を確認
 */
if (
    $version === '' ||
    !preg_match(
        '/^[0-9]+(?:\.[0-9]+){1,3}(?:[-+][a-zA-Z0-9.-]+)?$/',
        $version
    )
) {
    exit(
        "エラー：バージョン番号が正しくありません。"
        . PHP_EOL
    );
}

/*
 * ZIPに含めるトップ階層ファイル
 */
$releaseFiles = [
    'manifest.json',
    'bootstrap.php',
    'README.md',
    'LICENSE',
    'img.png',
];

/*
 * ZIPに含めるフォルダ
 */
$releaseDirectories = [
    'admin',
    'assets',
    'includes',
];

/*
 * 必須ファイルを確認
 */
foreach ($releaseFiles as $releaseFile) {
    $filePath =
        $projectDirectory
        . '/'
        . $releaseFile;

    if (!is_file($filePath)) {
        exit(
            'エラー：必要なファイルがありません。'
            . PHP_EOL
            . $releaseFile
            . PHP_EOL
        );
    }
}

/*
 * 必須フォルダを確認
 */
foreach ($releaseDirectories as $releaseDirectory) {
    $directoryPath =
        $projectDirectory
        . '/'
        . $releaseDirectory;

    if (!is_dir($directoryPath)) {
        exit(
            'エラー：必要なフォルダがありません。'
            . PHP_EOL
            . $releaseDirectory
            . PHP_EOL
        );
    }
}

/*
 * 出力ZIP名
 */
$zipFileName =
    $extensionId
    . '-'
    . $version
    . '.zip';

$zipPath =
    $projectDirectory
    . '/'
    . $zipFileName;

/*
 * 古い同名ZIPを削除
 */
if (
    is_file($zipPath) &&
    !unlink($zipPath)
) {
    exit(
        "エラー：古いZIPを削除できません。"
        . PHP_EOL
    );
}

$zip = new ZipArchive();

$openResult = $zip->open(
    $zipPath,
    ZipArchive::CREATE |
    ZipArchive::OVERWRITE
);

if ($openResult !== true) {
    exit(
        'エラー：ZIPファイルを作成できません。'
        . PHP_EOL
        . 'エラーコード：'
        . $openResult
        . PHP_EOL
    );
}

/*
 * ZIP内のルートフォルダを作成
 *
 * nx_nitro_cache-1.0.0.zip
 * └─nx_nitro_cache
 */
$zipRoot = $extensionId;

$zip->addEmptyDir($zipRoot);

/*
 * トップ階層ファイルを追加
 */
foreach ($releaseFiles as $releaseFile) {
    $sourcePath =
        $projectDirectory
        . '/'
        . $releaseFile;

    $zipEntry =
        $zipRoot
        . '/'
        . $releaseFile;

    if (
        !$zip->addFile(
            $sourcePath,
            $zipEntry
        )
    ) {
        $zip->close();
        @unlink($zipPath);

        exit(
            'エラー：ZIPへ追加できませんでした。'
            . PHP_EOL
            . $releaseFile
            . PHP_EOL
        );
    }
}

/*
 * フォルダ内のファイルを再帰的に追加
 */
foreach ($releaseDirectories as $releaseDirectory) {
    $sourceDirectory =
        $projectDirectory
        . '/'
        . $releaseDirectory;

    $zip->addEmptyDir(
        $zipRoot
        . '/'
        . $releaseDirectory
    );

    $iterator =
        new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $sourceDirectory,
                FilesystemIterator::SKIP_DOTS
            ),
            RecursiveIteratorIterator::SELF_FIRST
        );

    foreach ($iterator as $item) {
        /*
         * シンボリックリンクは含めない
         */
        if ($item->isLink()) {
            continue;
        }

        $fullPath =
            $item->getPathname();

        $relativePath = substr(
            $fullPath,
            strlen($projectDirectory) + 1
        );

        $relativePath = str_replace(
            '\\',
            '/',
            $relativePath
        );

        $zipEntry =
            $zipRoot
            . '/'
            . $relativePath;

        if ($item->isDir()) {
            $zip->addEmptyDir(
                rtrim($zipEntry, '/')
            );

            continue;
        }

        if (!$item->isFile()) {
            continue;
        }

        if (
            !$zip->addFile(
                $fullPath,
                $zipEntry
            )
        ) {
            $zip->close();
            @unlink($zipPath);

            exit(
                'エラー：ZIPへ追加できませんでした。'
                . PHP_EOL
                . $relativePath
                . PHP_EOL
            );
        }
    }
}

if (!$zip->close()) {
    @unlink($zipPath);

    exit(
        "エラー：ZIPを正常に保存できませんでした。"
        . PHP_EOL
    );
}

$zipSize = filesize($zipPath);

echo PHP_EOL;
echo "リリースZIPを作成しました。" . PHP_EOL;
echo "ファイル：" . $zipFileName . PHP_EOL;
echo "保存場所：" . $zipPath . PHP_EOL;

if ($zipSize !== false) {
    echo "サイズ：" . number_format($zipSize) . " bytes" . PHP_EOL;
}

echo PHP_EOL;