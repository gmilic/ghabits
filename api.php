<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    match ($action) {
        'status' => status_action(),
        'setup' => setup_action($method),
        'login' => login_action($method),
        'logout' => logout_action($method),
        'list' => list_action(),
        'save_habit' => save_habit_action($method),
        'delete_habit' => delete_habit_action($method),
        'set_entry' => set_entry_action($method),
        'export' => export_action(),
        default => json_response(['ok' => false, 'error' => 'Unknown action.'], 404),
    };
} catch (Throwable $e) {
    json_response(['ok' => false, 'error' => 'Server error.'], 500);
}

function status_action(): void
{
    $authenticated = is_authenticated();
    json_response([
        'ok' => true,
        'setupRequired' => !setup_complete(),
        'authenticated' => $authenticated,
        'csrfToken' => $authenticated ? csrf_token() : null,
        'appName' => APP_NAME,
    ]);
}

function setup_action(string $method): void
{
    if ($method !== 'POST') {
        json_response(['ok' => false, 'error' => 'Method not allowed.'], 405);
    }

    if (setup_complete()) {
        json_response(['ok' => false, 'error' => 'Setup has already been completed.'], 409);
    }

    $data = json_input();
    $password = (string) ($data['password'] ?? '');
    if (strlen($password) < 8) {
        json_response(['ok' => false, 'error' => 'Use at least 8 characters.'], 422);
    }

    setting_set('password_hash', password_hash($password, PASSWORD_DEFAULT));
    session_regenerate_id(true);
    $_SESSION['authenticated'] = true;
    csrf_token();

    json_response(['ok' => true, 'csrfToken' => csrf_token()]);
}

function login_action(string $method): void
{
    if ($method !== 'POST') {
        json_response(['ok' => false, 'error' => 'Method not allowed.'], 405);
    }

    if (!setup_complete()) {
        json_response(['ok' => false, 'error' => 'Setup required.'], 409);
    }

    $attempts = (int) ($_SESSION['login_attempts'] ?? 0);
    if ($attempts >= 8) {
        json_response(['ok' => false, 'error' => 'Too many attempts. Reload the page and try again.'], 429);
    }

    $data = json_input();
    $hash = setting_get('password_hash') ?? '';
    if (!password_verify((string) ($data['password'] ?? ''), $hash)) {
        $_SESSION['login_attempts'] = $attempts + 1;
        json_response(['ok' => false, 'error' => 'Wrong password.'], 401);
    }

    session_regenerate_id(true);
    $_SESSION['authenticated'] = true;
    $_SESSION['login_attempts'] = 0;
    csrf_token();

    json_response(['ok' => true, 'csrfToken' => csrf_token()]);
}

function logout_action(string $method): void
{
    if ($method !== 'POST') {
        json_response(['ok' => false, 'error' => 'Method not allowed.'], 405);
    }

    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();

    json_response(['ok' => true]);
}

function list_action(): void
{
    require_auth();

    $days = max(14, min(730, (int) ($_GET['days'] ?? 90)));
    $start = (new DateTimeImmutable('today'))->modify('-' . ($days - 1) . ' days')->format('Y-m-d');

    $habits = db()->query('SELECT * FROM habits WHERE archived = 0 ORDER BY position ASC, id ASC')->fetchAll();
    foreach ($habits as &$habit) {
        $habit['id'] = (int) $habit['id'];
        $habit['target'] = (float) $habit['target'];
        $habit['position'] = (int) $habit['position'];
        $habit['schedule'] = json_decode($habit['schedule_value'], true) ?: [];
        unset($habit['schedule_value'], $habit['archived']);
    }

    $stmt = db()->prepare("
        SELECT habit_id, entry_date, value, note
        FROM entries
        WHERE entry_date >= :start
        ORDER BY entry_date ASC
    ");
    $stmt->execute([':start' => $start]);
    $entries = $stmt->fetchAll();
    foreach ($entries as &$entry) {
        $entry['habit_id'] = (int) $entry['habit_id'];
        $entry['value'] = (float) $entry['value'];
    }

    json_response([
        'ok' => true,
        'habits' => $habits,
        'entries' => $entries,
        'startDate' => $start,
        'today' => (new DateTimeImmutable('today'))->format('Y-m-d'),
        'csrfToken' => csrf_token(),
    ]);
}

function save_habit_action(string $method): void
{
    if ($method !== 'POST') {
        json_response(['ok' => false, 'error' => 'Method not allowed.'], 405);
    }
    require_auth();
    require_csrf();

    $data = json_input();
    $id = isset($data['id']) ? (int) $data['id'] : 0;
    $name = clean_text($data['name'] ?? '', 80);
    if ($name === '') {
        json_response(['ok' => false, 'error' => 'Habit name is required.'], 422);
    }

    $type = (string) ($data['type'] ?? 'boolean');
    if (!in_array($type, ['boolean', 'measurable'], true)) {
        json_response(['ok' => false, 'error' => 'Invalid habit type.'], 422);
    }

    $scheduleType = (string) ($data['schedule_type'] ?? 'daily');
    if (!in_array($scheduleType, ['daily', 'weekdays', 'times_per_week'], true)) {
        json_response(['ok' => false, 'error' => 'Invalid schedule.'], 422);
    }

    $schedule = normalize_schedule($scheduleType, $data['schedule'] ?? []);
    $target = max(0.01, min(1000000, (float) ($data['target'] ?? 1)));
    $unit = clean_text($data['unit'] ?? '', 24);
    $question = clean_text($data['question'] ?? '', 160);
    $color = normalize_color((string) ($data['color'] ?? '#66bb6a'));
    $now = now_iso();

    if ($id > 0) {
        $stmt = db()->prepare("
            UPDATE habits
            SET name = :name, question = :question, type = :type, color = :color,
                schedule_type = :schedule_type, schedule_value = :schedule_value,
                target = :target, unit = :unit, updated_at = :updated_at
            WHERE id = :id AND archived = 0
        ");
        $stmt->execute([
            ':id' => $id,
            ':name' => $name,
            ':question' => $question,
            ':type' => $type,
            ':color' => $color,
            ':schedule_type' => $scheduleType,
            ':schedule_value' => json_encode($schedule, JSON_UNESCAPED_SLASHES),
            ':target' => $target,
            ':unit' => $unit,
            ':updated_at' => $now,
        ]);
    } else {
        $position = (int) db()->query('SELECT COALESCE(MAX(position), 0) + 1 FROM habits')->fetchColumn();
        $stmt = db()->prepare("
            INSERT INTO habits
                (name, question, type, color, schedule_type, schedule_value, target, unit, position, created_at, updated_at)
            VALUES
                (:name, :question, :type, :color, :schedule_type, :schedule_value, :target, :unit, :position, :created_at, :updated_at)
        ");
        $stmt->execute([
            ':name' => $name,
            ':question' => $question,
            ':type' => $type,
            ':color' => $color,
            ':schedule_type' => $scheduleType,
            ':schedule_value' => json_encode($schedule, JSON_UNESCAPED_SLASHES),
            ':target' => $target,
            ':unit' => $unit,
            ':position' => $position,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
    }

    json_response(['ok' => true]);
}

function delete_habit_action(string $method): void
{
    if ($method !== 'POST') {
        json_response(['ok' => false, 'error' => 'Method not allowed.'], 405);
    }
    require_auth();
    require_csrf();

    $data = json_input();
    $id = (int) ($data['id'] ?? 0);
    if ($id <= 0) {
        json_response(['ok' => false, 'error' => 'Invalid habit.'], 422);
    }

    $stmt = db()->prepare('DELETE FROM habits WHERE id = :id');
    $stmt->execute([':id' => $id]);

    json_response(['ok' => true]);
}

function set_entry_action(string $method): void
{
    if ($method !== 'POST') {
        json_response(['ok' => false, 'error' => 'Method not allowed.'], 405);
    }
    require_auth();
    require_csrf();

    $data = json_input();
    $habitId = (int) ($data['habit_id'] ?? 0);
    $date = (string) ($data['date'] ?? '');
    $value = (float) ($data['value'] ?? 0);
    $note = clean_text($data['note'] ?? '', 240);

    if ($habitId <= 0 || !valid_date($date)) {
        json_response(['ok' => false, 'error' => 'Invalid entry.'], 422);
    }

    if ($value <= 0) {
        $stmt = db()->prepare('DELETE FROM entries WHERE habit_id = :habit_id AND entry_date = :entry_date');
        $stmt->execute([':habit_id' => $habitId, ':entry_date' => $date]);
        json_response(['ok' => true]);
    }

    $value = min(1000000, $value);
    $stmt = db()->prepare("
        INSERT INTO entries (habit_id, entry_date, value, note, updated_at)
        VALUES (:habit_id, :entry_date, :value, :note, :updated_at)
        ON CONFLICT(habit_id, entry_date) DO UPDATE
        SET value = excluded.value, note = excluded.note, updated_at = excluded.updated_at
    ");
    $stmt->execute([
        ':habit_id' => $habitId,
        ':entry_date' => $date,
        ':value' => $value,
        ':note' => $note,
        ':updated_at' => now_iso(),
    ]);

    json_response(['ok' => true]);
}

function export_action(): void
{
    require_auth();

    $habits = db()->query('SELECT * FROM habits ORDER BY position ASC, id ASC')->fetchAll();
    $entries = db()->query('SELECT * FROM entries ORDER BY entry_date ASC, habit_id ASC')->fetchAll();

    json_response([
        'ok' => true,
        'exportedAt' => now_iso(),
        'habits' => $habits,
        'entries' => $entries,
    ]);
}

function normalize_schedule(string $type, mixed $schedule): array
{
    if (!is_array($schedule)) {
        $schedule = [];
    }

    if ($type === 'weekdays') {
        $days = $schedule['days'] ?? [];
        if (!is_array($days)) {
            $days = [];
        }
        $days = array_values(array_unique(array_filter(array_map('intval', $days), fn ($day) => $day >= 1 && $day <= 7)));
        sort($days);
        return ['days' => $days === [] ? [1, 2, 3, 4, 5, 6, 7] : $days];
    }

    if ($type === 'times_per_week') {
        $times = max(1, min(7, (int) ($schedule['times'] ?? 3)));
        return ['times' => $times];
    }

    return [];
}

function normalize_color(string $color): string
{
    return preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? strtolower($color) : '#66bb6a';
}

