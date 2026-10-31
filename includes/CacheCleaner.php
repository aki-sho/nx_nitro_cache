<?php

namespace NxNitroCache;

use app\Core\Auth;
use app\Core\Csrf;
use app\Core\Database;
use Throwable;

class CacheCleaner
{
    private static bool $registered = false;
    private static array $eventStack = [];
    private static ?array $legacyTarget = null;
    private static ?array $legacyBefore = null;

    public static function boot(): void
    {
        if (self::$registered || !Settings::get('auto_clear', true)) { return; }
        self::$registered = true;
        if (function_exists('add_action')) {
            foreach ([
                'post_saved', 'post_trashed', 'post_restored', 'post_deleted',
                'page_saved', 'page_trashed', 'page_restored', 'page_deleted',
                'category_saved', 'category_deleted', 'theme_changed',
                'navigation_saved', 'site_settings_saved', 'profile_saved',
                'extension_installed', 'extension_enabled', 'extension_disabled',
                'extension_deleted', 'extension_updated',
            ] as $event) {
                add_action($event, static function () use ($event): void {
                    self::$eventStack[] = ['event' => $event, 'handled' => false];
                }, PHP_INT_MIN);
                add_action($event, static function (mixed $id = null) use ($event): void {
                    self::onUpdated($event, $id);
                    $last = count(self::$eventStack) - 1;
                    if ($last >= 0) { self::$eventStack[$last]['handled'] = true; }
                });
                add_action($event, static function (): void { array_pop(self::$eventStack); }, PHP_INT_MAX);
            }
        }
        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST' ||
            !class_exists(Auth::class) || !Auth::check()) { return; }
        if (isset($_POST['_csrf_token']) &&
            (!class_exists(Csrf::class) || !Csrf::validate($_POST['_csrf_token']))) { return; }
        $target = self::target(self::requestPath());
        if ($target === null || ($before = self::snapshot($target)) === null) { return; }
        $userId = Auth::user()['id'] ?? null;
        if ($userId === null) { return; }
        self::$legacyTarget = $target;
        self::$legacyBefore = $before;
        register_shutdown_function(static function () use ($target, $before, $userId): void {
            $status = http_response_code();
            $error = error_get_last();
            if (!Auth::check() || (Auth::user()['id'] ?? null) !== $userId ||
                !is_int($status) || $status < 200 || $status >= 400 ||
                ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true))) {
                return;
            }
            $after = self::snapshot($target);
            if ($after !== null && !hash_equals($before['hash'], $after['hash'])) {
                self::invalidateTarget($target, $before['row'], $after['row']);
            }
        });
    }

    public static function onUpdated(string $event = 'global', mixed $id = null): void
    {
        if (!Settings::get('auto_clear', true)) { return; }
        $id = is_array($id) ? ($id['id'] ?? null) : $id;
        if (preg_match('/^(post|page|category)_/', $event, $match) && is_numeric($id) && (int)$id > 0) {
            $table = ['post' => 'posts', 'page' => 'pages', 'category' => 'categories'][$match[1]];
            $target = ['table' => $table, 'id' => (string)(int)$id];
            $before = self::$legacyTarget === $target ? (self::$legacyBefore['row'] ?? null) : null;
            $after = self::snapshot($target);
            self::invalidateTarget($target, $before, $after['row'] ?? null);
        } else {
            self::invalidateAll();
        }
    }

    /*
     * NexaPressの旧版向けイベント橋渡しが追加でclear()を呼ぶ場合だけ抑止。
     * 呼出元クラスも確認し、他の拡張機能や管理者の明示的な全削除には影響しない。
     */
    public static function handlesLegacyClear(): bool
    {
        $event = end(self::$eventStack);
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 4) as $frame) {
            if (($frame['class'] ?? '') === 'app\Core\Extension' &&
                str_ends_with($frame['function'] ?? '', '{closure}')) {
                return !Settings::get('auto_clear', true) || (is_array($event) && !empty($event['handled']));
            }
        }
        return false;
    }

    private static function invalidateAll(): void
    {
        $paths = CacheManager::paths();
        CacheManager::clear();
        Warmup::enqueue(array_merge((array)Settings::get('warmup_paths', ['/', '/posts']), $paths));
    }

    private static function invalidateTarget(array $target, mixed $before, mixed $after): void
    {
        if (!Settings::get('auto_clear', true)) { return; }
        if (isset($target['table']) && ($target['id'] ?? null) === null &&
            is_array($after) && is_numeric($after['latest'] ?? null) &&
            (int)$after['latest'] > 0 && (int)($after['total'] ?? 0) > (int)($before['total'] ?? 0)) {
            $target['id'] = (string)(int)$after['latest'];
            $created = self::snapshot($target);
            if ($created === null || !is_array($created['row'])) { self::invalidateAll(); return; }
            $before = null;
            $after = $created['row'] ?? null;
        }
        if (Settings::get('invalidation_mode', 'related') === 'all' ||
            !isset($target['table']) || ($target['id'] ?? null) === null) {
            // 追加されたIDを確認できない旧版では全削除に戻す。
            self::invalidateAll();
            return;
        }
        $kind = ['posts' => 'post', 'pages' => 'page', 'categories' => 'category'][$target['table']] ?? null;
        if ($kind === null) { self::invalidateAll(); return; }
        $id = (int)$target['id'];
        $tags = [$kind . ':' . $id, 'listing:posts', 'dynamic'];
        $cached = CacheManager::tagsFor($kind . ':' . $id);
        if ($kind === 'post') {
            $oldCategoryKnown = $cached !== [] ||
                (is_array($before) && array_key_exists('category_id', $before));
            foreach ([$before, $after] as $row) {
                if (is_array($row) && !empty($row['category_id'])) { $cached[] = 'category:' . (int)$row['category_id']; }
            }
            if (!$oldCategoryKnown) { $tags[] = 'listing:category:*'; }
            foreach ($cached as $tag) {
                if (str_starts_with($tag, 'category:')) { $tags[] = 'listing:' . $tag; }
            }
        } elseif ($kind === 'category') {
            $tags[] = 'listing:category:' . $id;
        }
        $result = CacheManager::invalidate([], array_values(array_unique($tags)));
        $paths = array_merge(['/', '/posts'], $result['paths']);
        foreach (array_slice($result['tags'], 0, 200) as $tag) {
            if (preg_match('/^(post|page):([0-9]+)$/', $tag, $match)) {
                $current = Dependencies::entityPath($match[1], (int)$match[2]);
                if ($current !== null) { $paths[] = $current; }
            }
        }
        if ($kind !== 'category') {
            $new = Dependencies::entityPath($kind, $id);
            if ($new !== null) { $paths[] = $new; }
        } elseif (is_array($after) && is_string($after['slug'] ?? null)) {
            $paths[] = '/category/' . rawurlencode($after['slug']);
            $paths[] = '/post/archive/category/' . rawurlencode($after['slug']);
        }
        Warmup::enqueue($paths);
    }

    private static function target(string $path): ?array
    {
        if (preg_match('#^/admin/(posts|pages|categories)/(store|update|delete|status|restore|destroy)(?:/([0-9]+))?$#', $path, $matches)) {
            if (($matches[2] === 'store') !== !isset($matches[3])) { return null; }
            return ['table' => $matches[1], 'id' => $matches[3] ?? null];
        }
        $config = [
            '/admin/themes/update' => 'theme.php', '/admin/settings/general/update' => 'general.php',
            '/admin/settings/url/update' => 'url.php',
        ];
        if (isset($config[$path])) { return ['config' => $config[$path]]; }
        if (preg_match('#^/admin/extensions/(?:install|enable|disable|delete|update)/([a-zA-Z0-9_-]+)$#', $path, $matches)) {
            return ['extension' => $matches[1]];
        }
        return null;
    }

    private static function snapshot(array $target): ?array
    {
        try {
            if (isset($target['config'])) {
                $file = BASE_PATH . '/config/' . $target['config'];
                clearstatcache(true, $file);
                $hash = is_file($file) ? @hash_file('sha256', $file) : hash('sha256', 'missing');
                return is_string($hash) ? ['hash' => $hash, 'row' => null] : null;
            }
            $pdo = Database::connect();
            if (isset($target['extension'])) {
                $table = Database::table('extension_settings');
                $statement = $pdo->prepare("SELECT * FROM {$table} WHERE extension_key = ?");
                $statement->execute([$target['extension']]);
                $row = $statement->fetch(\PDO::FETCH_ASSOC);
                $manifest = BASE_PATH . '/extensions/' . $target['extension'] . '/manifest.json';
                clearstatcache(true, $manifest);
                $hash = is_file($manifest) ? @hash_file('sha256', $manifest) : 'missing';
                return $hash === false ? null : ['hash' => hash('sha256', serialize([$row, $hash])), 'row' => $row];
            }
            $table = Database::table($target['table']);
            if ($target['id'] === null) {
                $statement = $pdo->query("SELECT COUNT(*) AS total, MAX(id) AS latest FROM {$table}");
            } else {
                $statement = $pdo->prepare("SELECT * FROM {$table} WHERE id = ?");
                $statement->execute([$target['id']]);
            }
            if ($statement === false || $pdo->inTransaction()) { return null; }
            $row = $statement->fetch(\PDO::FETCH_ASSOC);
            return ['hash' => hash('sha256', serialize($row)), 'row' => $row];
        } catch (Throwable $exception) { return null; }
    }

    private static function requestPath(): string
    {
        return CacheUrl::relative((string)($_SERVER['REQUEST_URI'] ?? ''), defined('BASE_URL') ? BASE_URL : '') ?? '';
    }
}
