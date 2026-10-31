<?php

namespace app\Models {
    trait TestRecords {
        private static function rows(): array {
            $path = BASE_PATH . '/model-state.json';
            return is_file($path) ? (json_decode(file_get_contents($path), true)[static::TABLE] ?? []) : [];
        }
        public static function find(int $id): ?array {
            foreach (self::rows() as $row) { if ((int)$row['id'] === $id) { return $row; } }
            return null;
        }
        public static function findBySlug(string $slug): ?array {
            foreach (self::rows() as $row) {
                if (($row['slug'] ?? '') === $slug && ($row['status'] ?? 'published') === 'published' && empty($row['deleted_at'])) {
                    return $row;
                }
            }
            return null;
        }
    }
    class Post { use TestRecords; public const TABLE = 'posts'; }
    class Page { use TestRecords; public const TABLE = 'pages'; }
    class Category { use TestRecords; public const TABLE = 'categories'; }
}

namespace {
    function url(string $path = ''): string { return (defined('BASE_URL') ? BASE_URL : '') . '/' . ltrim($path, '/'); }
    function e(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
    function post_url(array $post): string { return url('post/' . $post['slug']); }
    function page_url(array $page): string { return url('page/' . $page['slug']); }
}
