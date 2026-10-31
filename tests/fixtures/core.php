<?php

namespace app\Core;

class Auth
{
    public static function check(): bool { return isset($_SESSION['user']); }
    public static function user(): ?array { return $_SESSION['user'] ?? null; }
    public static function requireLogin(): void
    {
        if (!self::check()) {
            header('Location: /admin/login', true, 302);
            exit;
        }
    }
}

class Csrf
{
    public static function token(): string { return 'valid-test-token'; }
    public static function validate(mixed $token): bool
    {
        return is_string($token) && $token === 'valid-test-token';
    }
    public static function requireValid(mixed $token): void
    {
        if (!self::validate($token)) {
            http_response_code(419);
            exit('invalid token');
        }
    }
}

class Permission
{
    public static function require(string $permission): void
    {
        if ($permission !== 'extensions.manage' || (Auth::user()['role'] ?? '') !== 'admin') {
            http_response_code(403);
            exit('forbidden');
        }
    }
}

/* SQLの実行先だけを差し替える。前後の状態は別CGIプロセスにも共有する。 */
class Database
{
    public static function table(string $table): string { return 'test_' . $table; }
    public static function connect(): TestConnection { return new TestConnection(); }
}

class TestConnection
{
    public function prepare(string $sql): TestStatement { return new TestStatement($sql); }
    public function query(string $sql): TestStatement { return new TestStatement($sql); }
    public function inTransaction(): bool { return false; }
}

class TestStatement
{
    private array $params = [];
    public function __construct(private string $sql = '') {}
    public function execute(array $params): void { $this->params = $params; }
    public function fetch(int $mode): mixed
    {
        $models = BASE_PATH . '/model-state.json';
        if (is_file($models) && preg_match('/test_(posts|pages|categories)/', $this->sql, $match)) {
            $rows = json_decode(file_get_contents($models), true)[$match[1]] ?? [];
            if (str_contains($this->sql, 'COUNT(*)')) {
                return ['total' => count($rows), 'latest' => $rows === [] ? null : max(array_column($rows, 'id'))];
            }
            foreach ($rows as $row) { if ($row['id'] == ($this->params[0] ?? null)) { return $row; } }
            return false;
        }
        $path = BASE_PATH . '/database-state.json';
        return is_file($path) ? json_decode(file_get_contents($path), true) : false;
    }
}

class Extension
{
    public static function registerLegacyCacheBridge(): void
    {
        \add_action('page_saved', static function (): void { \NxNitroCache\CacheManager::clear(); });
    }
}
