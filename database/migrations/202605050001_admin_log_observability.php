<?php

return [
    "ALTER TABLE admin_logs ADD COLUMN category ENUM('system','gallery','media','upload','thumbnail','update','security','database','telemetry','admin','other') NOT NULL DEFAULT 'other' AFTER level",
    "ALTER TABLE admin_logs ADD COLUMN severity ENUM('debug','info','notice','warning','error','critical') NOT NULL DEFAULT 'info' AFTER category",
    "ALTER TABLE admin_logs ADD COLUMN subject_type VARCHAR(40) NULL AFTER event_key",
    "ALTER TABLE admin_logs ADD COLUMN subject_id BIGINT UNSIGNED NULL AFTER subject_type",
    "ALTER TABLE admin_logs ADD COLUMN request_id CHAR(26) NULL AFTER subject_id",
    "ALTER TABLE admin_logs ADD COLUMN route_name VARCHAR(80) NULL AFTER request_id",
    "ALTER TABLE admin_logs ADD COLUMN resolved_at DATETIME NULL AFTER status_updated_at",
    "ALTER TABLE admin_logs ADD COLUMN resolution_note VARCHAR(500) NULL AFTER resolved_at",
    "ALTER TABLE admin_logs ADD KEY admin_logs_category_created_index (category, created_at)",
    "ALTER TABLE admin_logs ADD KEY admin_logs_severity_created_index (severity, created_at)",
    "ALTER TABLE admin_logs ADD KEY admin_logs_event_created_index (event_key, created_at)",
    "ALTER TABLE admin_logs ADD KEY admin_logs_subject_index (subject_type, subject_id, created_at)",
    "ALTER TABLE admin_logs ADD KEY admin_logs_request_index (request_id)"
];
