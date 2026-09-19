<?php
/**
 * Project: PHP Gallery
 * Author: Rudolf Klusal
 * Apply actual migrations and generate synthetic galleries and photographs.
 */
declare(strict_types=1);

require_once __DIR__ . '/gallery_workflow_safety.php';

use function GalleryWorkflow\check;
use function GalleryWorkflow\validateFixture;

try {
    $directory = validateFixture((string) ($argv[1] ?? ''), (string) ($argv[2] ?? ''));
    require $directory . '/app/bootstrap.php';
    $migrations = \Gallery\Core\run_migrations();
    check(count($migrations) > 0, 'Fresh fixture must apply real migrations.');
    $pdo = \Gallery\Core\db();
    $password = bin2hex(random_bytes(24));
    $pdo->prepare("INSERT INTO users (username, password_hash, role, created_at, updated_at) VALUES (?, ?, 'admin', NOW(), NOW())")
        ->execute(['workflow_admin', password_hash($password, PASSWORD_DEFAULT)]);
    \Gallery\Services\set_gallery_trash_settings(true, false, 30, 10);
    \Gallery\Services\set_app_setting('password_reset_enabled', '0');
    \Gallery\Services\set_app_setting('admin_upload_auto_rename_enabled', '0');
    $root = \Gallery\Services\create_empty_gallery(['title' => 'Workflow seed', 'folder_name' => 'seed', 'visibility' => 'public']);
    $protected = \Gallery\Services\create_empty_gallery(['title' => 'Workflow protected', 'folder_name' => 'protected',
        'parent_id' => $root['id'], 'visibility' => 'public']);
    $pdo->prepare("UPDATE galleries SET access_mode = 'password', access_password_hash = ? WHERE id = ?")
        ->execute([password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT), $protected['id']]);
    foreach ([$root, $protected] as $gallery) {
        for ($index = 1; $index <= 2; $index++) {
            $image = imagecreatetruecolor(48, 32);
            imagefill($image, 0, 0, imagecolorallocate($image, $index * 60, 80, 130));
            imagejpeg($image, $directory . '/galleries/' . $gallery['folder_path'] . '/sample-' . $index . '.jpg');
            imagedestroy($image);
        }
        \Gallery\Services\scan_gallery_images((int) $gallery['id']);
    }
    file_put_contents($directory . '/seed.json', json_encode(['username' => 'workflow_admin', 'password' => $password,
        'root_id' => (int) $root['id'], 'protected_id' => (int) $protected['id']], JSON_THROW_ON_ERROR));
    @chmod($directory . '/seed.json', 0600);
} catch (Throwable $exception) {
    fwrite(STDERR, 'FAIL gallery workflow isolated migration or seed at ' . basename($exception->getFile()) . ' line ' . $exception->getLine() . "\n");
    exit(1);
}
