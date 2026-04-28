<?php

return [
    "CREATE TABLE IF NOT EXISTS admin_logs (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED NULL,
        level ENUM('info','warning','error') NOT NULL DEFAULT 'error',
        event_key VARCHAR(120) NOT NULL,
        message TEXT NOT NULL,
        context_json LONGTEXT NULL,
        created_at DATETIME NOT NULL,
        KEY admin_logs_level_created_index (level, created_at),
        KEY admin_logs_user_created_index (user_id, created_at),
        CONSTRAINT admin_logs_user_id_foreign FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "ALTER TABLE admin_logs ADD COLUMN status ENUM('todo','doing','done','waiting') NOT NULL DEFAULT 'todo' AFTER level",
    "ALTER TABLE admin_logs ADD COLUMN status_updated_at DATETIME NULL AFTER status",
    "ALTER TABLE admin_logs ADD KEY admin_logs_status_created_index (status, created_at)",
];
