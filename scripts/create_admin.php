<?php

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

// Variable $username stores this steps working value.
$username = $argv[1] ?? null;
// Variable $password stores this steps working value.
$password = $argv[2] ?? null;
if (!$username || !$password) {
    echo "Usage: php scripts/create_admin.php <username> <password>\n";
    exit(1);
}

run_migrations();
// Variable $stmt stores this steps working value.
$stmt = db()->prepare('INSERT INTO users (username, password_hash, role, created_at, updated_at) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), updated_at = VALUES(updated_at)');
$stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT), 'admin', now_sql(), now_sql()]);
echo "Admin user created or updated.\n";

