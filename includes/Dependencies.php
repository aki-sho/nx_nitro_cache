<?php

namespace NxNitroCache;

use app\Models\Post;
use app\Models\Page;
use app\Models\Category;
use Throwable;

class Dependencies
{
    public static function describe(string $path): array
    {
        $decoded = rawurldecode($path);
        try {
            // 固定URLより先に、同じURLを使う既存の投稿・固定ページを確認する。
            $slug = basename($decoded);
            foreach ([
                [Post::class, 'post', 'post_url'],
                [Page::class, 'page', 'page_url'],
            ] as [$model, $kind, $helper]) {
                if (class_exists($model) && is_callable([$model, 'findBySlug']) && function_exists($helper)) {
                    $entity = $model::findBySlug($slug);
                    if (is_array($entity) && self::relativeUrl($helper($entity)) === $decoded) {
                        $tags = [$kind . ':' . (int)$entity['id']];
                        if ($kind === 'post' && !empty($entity['category_id'])) {
                            $tags[] = 'category:' . (int)$entity['category_id'];
                        }
                        return $tags;
                    }
                }
            }
            if (in_array($decoded, ['/', '/posts', '/post/archive/posts'], true)) {
                return ['listing:posts'];
            }
            if (preg_match('#^/(?:category|post/archive/category)/([^/]+)$#', $decoded, $match) &&
                class_exists(Category::class)) {
                $category = Category::findBySlug($match[1]);
                if (is_array($category)) {
                    return ['listing:category:' . (int)$category['id']];
                }
            }
        } catch (Throwable $exception) {
            // 判定できないページはすべてのコンテンツ更新で無効にする。
        }
        return ['dynamic'];
    }

    public static function entityPath(string $kind, int $id): ?string
    {
        try {
            $model = $kind === 'post' ? Post::class : Page::class;
            $helper = $kind === 'post' ? 'post_url' : 'page_url';
            if (!class_exists($model) || !function_exists($helper)) {
                return null;
            }
            $entity = $model::find($id);
            if (!is_array($entity) || ($entity['status'] ?? '') !== 'published' ||
                !empty($entity['deleted_at'])) {
                return null;
            }
            return CacheUrl::path(self::relativeUrl($helper($entity)));
        } catch (Throwable $exception) {
            return null;
        }
    }

    private static function relativeUrl(string $url): string
    {
        $path = (string)parse_url($url, PHP_URL_PATH);
        $base = defined('BASE_URL') ? rtrim(BASE_URL, '/') : '';
        return rawurldecode(CacheUrl::relative($path, $base) ?? '');
    }
}
