<?php
declare(strict_types=1);

const APP_NAME = 'GHabits';

$rootDir = dirname(__DIR__);
$dataDir = $rootDir . DIRECTORY_SEPARATOR . 'data';
$dbPath = $dataDir . DIRECTORY_SEPARATOR . 'ghabits.sqlite';

if (!is_dir($dataDir)) {
    mkdir($dataDir, 0755, true);
}

$sessionDir = $dataDir . DIRECTORY_SEPARATOR . 'sessions';
if (!is_dir($sessionDir)) {
    mkdir($sessionDir, 0755, true);
}
session_save_path($sessionDir);

$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
session_set_cookie_params([
    'httponly' => true,
    'secure' => $isHttps,
    'samesite' => 'Lax',
]);
session_start();

function db(): PDO
{
    static $pdo = null;
    global $dbPath;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');
    init_schema($pdo);

    return $pdo;
}

function init_schema(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS settings (
            key TEXT PRIMARY KEY,
            value TEXT NOT NULL
        );

        CREATE TABLE IF NOT EXISTS habits (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            question TEXT NOT NULL DEFAULT '',
            type TEXT NOT NULL CHECK (type IN ('boolean', 'measurable')),
            color TEXT NOT NULL DEFAULT '#66bb6a',
            schedule_type TEXT NOT NULL DEFAULT 'daily'
                CHECK (schedule_type IN ('daily', 'weekdays', 'times_per_week')),
            schedule_value TEXT NOT NULL DEFAULT '{}',
            target REAL NOT NULL DEFAULT 1,
            unit TEXT NOT NULL DEFAULT '',
            position INTEGER NOT NULL DEFAULT 0,
            archived INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        );

        CREATE TABLE IF NOT EXISTS entries (
            habit_id INTEGER NOT NULL,
            entry_date TEXT NOT NULL,
            value REAL NOT NULL DEFAULT 1,
            note TEXT NOT NULL DEFAULT '',
            updated_at TEXT NOT NULL,
            PRIMARY KEY (habit_id, entry_date),
            FOREIGN KEY (habit_id) REFERENCES habits(id) ON DELETE CASCADE
        );
    ");
}

function now_iso(): string
{
    return gmdate('c');
}

function setting_get(string $key): ?string
{
    $stmt = db()->prepare('SELECT value FROM settings WHERE key = :key');
    $stmt->execute([':key' => $key]);
    $value = $stmt->fetchColumn();

    return $value === false ? null : (string) $value;
}

function setting_set(string $key, string $value): void
{
    $stmt = db()->prepare("
        INSERT INTO settings (key, value)
        VALUES (:key, :value)
        ON CONFLICT(key) DO UPDATE SET value = excluded.value
    ");
    $stmt->execute([':key' => $key, ':value' => $value]);
}

function setup_complete(): bool
{
    return setting_get('password_hash') !== null;
}

function is_authenticated(): bool
{
    return !empty($_SESSION['authenticated']);
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function require_auth(): void
{
    if (!is_authenticated()) {
        json_response(['ok' => false, 'error' => 'Authentication required.'], 401);
    }
}

function require_csrf(): void
{
    $header = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals(csrf_token(), $header)) {
        json_response(['ok' => false, 'error' => 'Invalid security token.'], 403);
    }
}

function json_input(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) {
        return [];
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        json_response(['ok' => false, 'error' => 'Invalid JSON payload.'], 400);
    }

    return $data;
}

function json_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function clean_text(mixed $value, int $maxLength): string
{
    $value = trim((string) $value);
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $maxLength);
    }

    return substr($value, 0, $maxLength);
}

function valid_date(string $date): bool
{
    $dt = DateTime::createFromFormat('Y-m-d', $date);
    return $dt !== false && $dt->format('Y-m-d') === $date;
}

