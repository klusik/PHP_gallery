<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: scripts/gallery_workflow_ci.php
 * Module Type: CLI Tool
 * Purpose: Prepare the explicitly named disposable CI workflow database.
 * Responsibilities:
 *   - Apply isolation guards before invoking the central audit workflow.
 * Author: Rudolf Klusal
 * Provision only the explicitly named disposable CI database service, then use the central audit.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
if (!is_file(__DIR__ . '/../tests/support/gallery_workflow_safety.php')) {
    fwrite(STDERR, "BLOCKED gallery workflow source checkout with test support required\n");
    exit(1);
}
require_once __DIR__ . '/../tests/support/gallery_workflow_safety.php';
use function GalleryWorkflow\check;
use function GalleryWorkflow\databaseOptions;

try {
    check(getenv('GITHUB_ACTIONS') === 'true' && getenv('GALLERY_WORKFLOW_ENABLE') === 'disposable-only',
        'This provisioner requires the disposable GitHub Actions service.');
    $password = bin2hex(random_bytes(24));
    putenv('GALLERY_WORKFLOW_DB_PASSWORD=' . $password);
    $options = databaseOptions(getenv());
    check((string) getenv('GALLERY_WORKFLOW_CI_ROOT_PASSWORD') !== '', 'CI service bootstrap credential missing.');
    $pdo = null;
    for ($attempt = 0; $attempt < 60; $attempt++) {
        try {
            $pdo = new PDO('mysql:host=127.0.0.1;port=' . $options['port'], 'root',
                (string) getenv('GALLERY_WORKFLOW_CI_ROOT_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            break;
        } catch (Throwable) {
            usleep(500000);
        }
    }
    check($pdo instanceof PDO && $pdo->query('SELECT @@hostname')->fetchColumn() === 'gallery-workflow-ci',
        'Disposable CI database identity mismatch.');
    $pdo->exec("CREATE USER 'gallery_workflow_runner'@'%' IDENTIFIED BY " . $pdo->quote($password));
    $quotedSchemaPattern = chr(96) . 'gallery\\_workflow\\_%' . chr(96);
    // Match ordinary application ownership: schema-local data and portable DDL
    // only. Migrations must not depend on TRIGGER, SUPER or server-global state.
    $pdo->exec("GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, DROP, INDEX, REFERENCES ON "
        . $quotedSchemaPattern . ".* TO 'gallery_workflow_runner'@'%'");
    $pdo = null;
    putenv('GALLERY_WORKFLOW_REQUIRED=1');
    // Keep the service root credential out of application/audit child environments.
    putenv('GALLERY_WORKFLOW_CI_ROOT_PASSWORD');
    $argv = [__FILE__, '--audit'];
    require __DIR__ . '/gallery_workflow_run.php';
} catch (Throwable) {
    fwrite(STDERR, "BLOCKED gallery workflow disposable CI provisioning\n");
    exit(1);
}
