<?php
/**
 * Administrative log service model.
 *
 * This module owns the persistent admin log data model and status workflow.
 * It was separated from app/services.php so diagnostic storage can evolve without
 * changing gallery discovery, thumbnail generation, theme handling, or public path logic.
 *
 * The functions intentionally keep their original names and signatures. Existing
 * controllers and templates can keep calling admin_log_event(), admin_log_list(),
 * and related helpers without any routing or bootstrap migration.
 */

function admin_log_schema_ready(): bool
{
    try {
        $stmt = db()->query("SHOW COLUMNS FROM admin_logs LIKE 'status'");
        return $stmt && (bool) $stmt->fetch();
    } catch (PDOException) {
        return false;
    }
}

function ensure_admin_log_status_schema(): bool
{
    try {
        $tableExists = db()->query("SHOW TABLES LIKE 'admin_logs'");
        if (!$tableExists || !$tableExists->fetch()) {
            return false;
        }
        $statusColumn = db()->query("SHOW COLUMNS FROM admin_logs LIKE 'status'");
        if (!$statusColumn || !$statusColumn->fetch()) {
            db()->exec("ALTER TABLE admin_logs ADD COLUMN status ENUM('todo','doing','done','waiting') NOT NULL DEFAULT 'todo' AFTER level");
        }
        $statusUpdatedAtColumn = db()->query("SHOW COLUMNS FROM admin_logs LIKE 'status_updated_at'");
        if (!$statusUpdatedAtColumn || !$statusUpdatedAtColumn->fetch()) {
            db()->exec("ALTER TABLE admin_logs ADD COLUMN status_updated_at DATETIME NULL AFTER status");
        }
        $statusIndex = db()->query("SHOW INDEX FROM admin_logs WHERE Key_name = 'admin_logs_status_created_index'");
        if (!$statusIndex || !$statusIndex->fetch()) {
            db()->exec("ALTER TABLE admin_logs ADD KEY admin_logs_status_created_index (status, created_at)");
        }
        return true;
    } catch (PDOException) {
        return false;
    }
}

function admin_log_event(string $level, string $eventKey, string $message, array $context = []): void
{
    if (!admin_log_schema_ready()) {
        return;
    }
    $allowedLevels = ['info', 'warning', 'error'];
    $level = in_array($level, $allowedLevels, true) ? $level : 'error';
    try {
        $user = current_user();
        $stmt = db()->prepare('INSERT INTO admin_logs (user_id, level, event_key, message, context_json, created_at) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            $user ? (int) $user['id'] : null,
            $level,
            $eventKey,
            $message,
            $context ? json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            now_sql(),
        ]);
    } catch (Throwable) {
    }
}

function admin_log_status_options(): array
{
    return [
        'todo' => 'To be done',
        'doing' => 'Will be done',
        'waiting' => 'Waiting',
        'done' => 'Done',
    ];
}

function admin_log_status_label(string $status): string
{
    $statuses = admin_log_status_options();
    return $statuses[$status] ?? $status;
}

function admin_log_recent(int $limit = 12): array
{
    if (!admin_log_schema_ready()) {
        return [];
    }
    $stmt = db()->prepare('SELECT l.*, u.username FROM admin_logs l LEFT JOIN users u ON u.id = l.user_id ORDER BY l.created_at DESC, l.id DESC LIMIT ' . max(1, min(50, $limit)));
    $stmt->execute();
    return $stmt->fetchAll();
}

function admin_log_list(?string $status = null, int $limit = 100): array
{
    if (!admin_log_schema_ready()) {
        return [];
    }
    $sql = 'SELECT l.*, u.username FROM admin_logs l LEFT JOIN users u ON u.id = l.user_id';
    $params = [];
    $statuses = admin_log_status_options();
    if ($status !== null && isset($statuses[$status])) {
        $sql .= ' WHERE l.status = ?';
        $params[] = $status;
    }
    $sql .= ' ORDER BY l.status <> "done", l.status, l.created_at DESC, l.id DESC LIMIT ' . max(1, min(200, $limit));
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function admin_log_update_status(int $logId, string $status): void
{
    $statuses = admin_log_status_options();
    if (!isset($statuses[$status])) {
        throw new RuntimeException('Invalid log status.');
    }
    if (!admin_log_schema_ready() && !ensure_admin_log_status_schema()) {
        throw new RuntimeException('Admin log schema is not ready.');
    }
    $stmt = db()->prepare('UPDATE admin_logs SET status = ?, status_updated_at = ? WHERE id = ?');
    $stmt->execute([$status, now_sql(), $logId]);
    $check = db()->prepare('SELECT status FROM admin_logs WHERE id = ?');
    $check->execute([$logId]);
    if ($check->fetchColumn() !== $status) {
        throw new RuntimeException('Admin log entry was not updated.');
    }
}
