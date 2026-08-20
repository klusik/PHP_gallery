<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/viewer_phase07_mysql_concurrency_test.php
 * Module Type: Optional Integration Test Script
 *
 * Purpose:
 *   Exercises viewer transaction/row-locking guarantees against real MySQL/MariaDB connections.
 *
 * Responsibilities:
 *   - Use independent PHP processes and independent PDO connections
 *   - Coordinate race starts through process pipes rather than timing sleeps
 *   - Report each race independently and clean all uniquely prefixed fixture rows
 *   - Skip explicitly when pdo_mysql or required environment configuration is unavailable
 *
 * Environment:
 *   GALLERY_TEST_MYSQL_DSN
 *   GALLERY_TEST_MYSQL_USER
 *   GALLERY_TEST_MYSQL_PASSWORD
 *
 * Notes:
 *   - Run only against a dedicated test database with the current application migrations applied.
 *   - This harness intentionally does not run as part of the default driverless test suite.
 *   - A SKIP result is not a successful live race execution.
 *
 * Last Updated:
 *   2026-08-18
 */

declare(strict_types=1);

/**
 * Return a configured independent PDO connection for the optional race harness.
 *
 * @return PDO Configured MySQL/MariaDB connection.
 */
function phase07_mysql_connection(): PDO
{
    $dsn = (string) getenv('GALLERY_TEST_MYSQL_DSN');
    $user = (string) getenv('GALLERY_TEST_MYSQL_USER');
    $password = (string) getenv('GALLERY_TEST_MYSQL_PASSWORD');
    return new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

/**
 * Throw when one live race expectation fails.
 *
 * @param bool $condition Condition value.
 * @param string $label Assertion label.
 */
function phase07_mysql_assert(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException($label);
    }
}

/**
 * Wait for the parent process start barrier.
 */
function phase07_mysql_worker_wait_for_go(): void
{
    $line = fgets(STDIN);
    if (!is_string($line) || trim($line) !== 'GO') {
        throw new RuntimeException('Concurrency worker did not receive the GO barrier signal.');
    }
}

/**
 * Execute one low-level worker action representing an application transaction invariant.
 *
 * @param string $action Worker action key.
 * @param array<string,mixed> $payload Action payload.
 * @return array<string,mixed> Worker result.
 */
function phase07_mysql_worker_action(string $action, array $payload): array
{
    $pdo = phase07_mysql_connection();
    phase07_mysql_worker_wait_for_go();

    if ($action === 'activate_same_registration') {
        $pdo->beginTransaction();
        try {
            $request = $pdo->prepare('SELECT * FROM viewer_registration_requests WHERE id = ? LIMIT 1 FOR UPDATE');
            $request->execute([(int) $payload['request_id']]);
            $row = $request->fetch();
            if (!$row || (string) $row['status'] !== 'email_verified') {
                $pdo->commit();
                return ['won' => false];
            }
            $email = (string) $row['normalized_email'];
            $insert = $pdo->prepare(
                "INSERT INTO viewer_accounts (email, normalized_email, password_hash, status, security_version, email_verified_at, password_changed_at, created_at, updated_at) "
                . "VALUES (?, ?, 'phase07-test-hash', 'active', 1, NOW(), NOW(), NOW(), NOW())"
            );
            $insert->execute([$email, $email]);
            $delete = $pdo->prepare('DELETE FROM viewer_registration_requests WHERE id = ?');
            $delete->execute([(int) $payload['request_id']]);
            $pdo->commit();
            return ['won' => true];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($e instanceof PDOException && (string) $e->getCode() === '23000') {
                return ['won' => false];
            }
            throw $e;
        }
    }

    if ($action === 'capacity_insert') {
        $pdo->beginTransaction();
        try {
            $pdo->exec("INSERT INTO viewer_account_state (state_key, account_count, updated_at) VALUES ('accounts', 0, NOW()) ON DUPLICATE KEY UPDATE updated_at = updated_at");
            $lock = $pdo->query("SELECT account_count FROM viewer_account_state WHERE state_key = 'accounts' LIMIT 1 FOR UPDATE");
            $lock->fetchColumn();
            $count = (int) $pdo->query('SELECT COUNT(*) FROM viewer_accounts')->fetchColumn();
            $cap = (int) $payload['cap'];
            if ($count >= $cap) {
                $pdo->commit();
                return ['won' => false];
            }
            $email = (string) $payload['email'];
            $insert = $pdo->prepare(
                "INSERT INTO viewer_accounts (email, normalized_email, password_hash, status, security_version, email_verified_at, password_changed_at, created_at, updated_at) "
                . "VALUES (?, ?, 'phase07-test-hash', 'active', 1, NOW(), NOW(), NOW(), NOW())"
            );
            $insert->execute([$email, $email]);
            $pdo->prepare("UPDATE viewer_account_state SET account_count = ?, updated_at = NOW() WHERE state_key = 'accounts'")
                ->execute([$count + 1]);
            $pdo->commit();
            return ['won' => true];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    if ($action === 'session_insert_capped') {
        $pdo->beginTransaction();
        try {
            $accountId = (int) $payload['account_id'];
            $lock = $pdo->prepare('SELECT security_version FROM viewer_accounts WHERE id = ? LIMIT 1 FOR UPDATE');
            $lock->execute([$accountId]);
            $version = (int) $lock->fetchColumn();
            $active = $pdo->prepare('SELECT id FROM viewer_sessions WHERE viewer_account_id = ? AND revoked_at IS NULL AND expires_at >= NOW() ORDER BY created_at ASC, id ASC');
            $active->execute([$accountId]);
            $ids = array_map('intval', $active->fetchAll(PDO::FETCH_COLUMN));
            $cap = (int) $payload['cap'];
            while (count($ids) >= $cap) {
                $id = array_shift($ids);
                $pdo->prepare('UPDATE viewer_sessions SET revoked_at = NOW() WHERE id = ? AND revoked_at IS NULL')->execute([$id]);
            }
            $hash = hash('sha256', (string) $payload['token_seed']);
            $pdo->prepare('INSERT INTO viewer_sessions (viewer_account_id, session_hash, security_version, created_at, expires_at) VALUES (?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 1 HOUR))')
                ->execute([$accountId, $hash, $version]);
            $pdo->commit();
            return ['won' => true];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    if ($action === 'remember_rotate') {
        $pdo->beginTransaction();
        try {
            $id = (int) $payload['token_id'];
            $oldSelector = (string) $payload['old_selector'];
            $token = $pdo->prepare('SELECT * FROM viewer_remember_tokens WHERE id = ? LIMIT 1 FOR UPDATE');
            $token->execute([$id]);
            $row = $token->fetch();
            if (!$row || (string) $row['selector'] !== $oldSelector || !empty($row['revoked_at'])) {
                $pdo->commit();
                return ['won' => false];
            }
            $newSelector = substr(hash('sha256', (string) $payload['new_seed']), 0, 36);
            $newVerifierHash = hash('sha256', 'verifier:' . (string) $payload['new_seed']);
            $update = $pdo->prepare('UPDATE viewer_remember_tokens SET selector = ?, verifier_hash = ?, last_used_at = NOW() WHERE id = ? AND selector = ? AND revoked_at IS NULL');
            $update->execute([$newSelector, $newVerifierHash, $id, $oldSelector]);
            $won = $update->rowCount() === 1;
            $pdo->commit();
            return ['won' => $won];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    if ($action === 'reset_consume') {
        $pdo->beginTransaction();
        try {
            $accountId = (int) $payload['account_id'];
            $expectedVersion = (int) $payload['security_version'];
            $account = $pdo->prepare('SELECT security_version FROM viewer_accounts WHERE id = ? LIMIT 1 FOR UPDATE');
            $account->execute([$accountId]);
            $version = (int) $account->fetchColumn();
            $token = $pdo->prepare('SELECT * FROM viewer_password_reset_tokens WHERE id = ? LIMIT 1 FOR UPDATE');
            $token->execute([(int) $payload['token_id']]);
            $row = $token->fetch();
            if ($version !== $expectedVersion || !$row || !empty($row['consumed_at']) || !empty($row['invalidated_at']) || (int) $row['security_version'] !== $expectedVersion) {
                $pdo->commit();
                return ['won' => false];
            }
            $pdo->prepare('UPDATE viewer_accounts SET security_version = security_version + 1, updated_at = NOW() WHERE id = ? AND security_version = ?')
                ->execute([$accountId, $expectedVersion]);
            $consume = $pdo->prepare('UPDATE viewer_password_reset_tokens SET consumed_at = NOW() WHERE id = ? AND consumed_at IS NULL AND invalidated_at IS NULL');
            $consume->execute([(int) $payload['token_id']]);
            $pdo->commit();
            return ['won' => $consume->rowCount() === 1];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    if ($action === 'auth_session_expected_version') {
        $pdo->beginTransaction();
        try {
            $accountId = (int) $payload['account_id'];
            $expected = (int) $payload['security_version'];
            $lock = $pdo->prepare('SELECT security_version FROM viewer_accounts WHERE id = ? LIMIT 1 FOR UPDATE');
            $lock->execute([$accountId]);
            $version = (int) $lock->fetchColumn();
            if ($version !== $expected) {
                $pdo->commit();
                return ['won' => false];
            }
            $pdo->prepare('INSERT INTO viewer_sessions (viewer_account_id, session_hash, security_version, created_at, expires_at) VALUES (?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 1 HOUR))')
                ->execute([$accountId, hash('sha256', (string) $payload['token_seed']), $expected]);
            $pdo->commit();
            return ['won' => true];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    if ($action === 'invalidate_security_version') {
        $pdo->beginTransaction();
        try {
            $accountId = (int) $payload['account_id'];
            $lock = $pdo->prepare('SELECT security_version FROM viewer_accounts WHERE id = ? LIMIT 1 FOR UPDATE');
            $lock->execute([$accountId]);
            $lock->fetchColumn();
            $pdo->prepare('UPDATE viewer_accounts SET security_version = security_version + 1, updated_at = NOW() WHERE id = ?')->execute([$accountId]);
            $pdo->prepare('UPDATE viewer_sessions SET revoked_at = NOW() WHERE viewer_account_id = ? AND revoked_at IS NULL')->execute([$accountId]);
            $pdo->commit();
            return ['won' => true];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    if ($action === 'delete_and_reconcile') {
        $pdo->beginTransaction();
        try {
            $accountId = (int) $payload['account_id'];
            $account = $pdo->prepare('SELECT security_version FROM viewer_accounts WHERE id = ? LIMIT 1 FOR UPDATE');
            $account->execute([$accountId]);
            if ($account->fetchColumn() === false) {
                $pdo->commit();
                return ['won' => false];
            }
            $pdo->exec("INSERT INTO viewer_account_state (state_key, account_count, updated_at) VALUES ('accounts', 0, NOW()) ON DUPLICATE KEY UPDATE updated_at = updated_at");
            $pdo->query("SELECT account_count FROM viewer_account_state WHERE state_key = 'accounts' LIMIT 1 FOR UPDATE")->fetchColumn();
            $pdo->prepare('DELETE FROM viewer_accounts WHERE id = ?')->execute([$accountId]);
            $count = (int) $pdo->query('SELECT COUNT(*) FROM viewer_accounts')->fetchColumn();
            $pdo->prepare("UPDATE viewer_account_state SET account_count = ?, updated_at = NOW() WHERE state_key = 'accounts'")->execute([$count]);
            $pdo->commit();
            return ['won' => true];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    throw new InvalidArgumentException('Unknown Phase 0.7 MySQL worker action: ' . $action);
}

/**
 * Spawn independent workers, release them through a common pipe barrier, and collect JSON results.
 *
 * @param array<int,array{action:string,payload:array<string,mixed>}> $workers Worker specifications.
 * @return array<int,array<string,mixed>> Worker results.
 */
function phase07_mysql_run_workers(array $workers): array
{
    $processes = [];
    foreach ($workers as $index => $worker) {
        $payloadFile = tempnam(sys_get_temp_dir(), 'gallery-phase07-');
        if ($payloadFile === false) {
            throw new RuntimeException('Could not create concurrency worker payload file.');
        }
        file_put_contents($payloadFile, json_encode($worker['payload'], JSON_THROW_ON_ERROR));
        $command = [PHP_BINARY, __FILE__, '--worker', $worker['action'], $payloadFile];
        $pipes = [];
        $process = proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);
        if (!is_resource($process)) {
            @unlink($payloadFile);
            throw new RuntimeException('Could not start concurrency worker.');
        }
        $processes[$index] = compact('process', 'pipes', 'payloadFile');
    }

    foreach ($processes as $entry) {
        fwrite($entry['pipes'][0], "GO\n");
        fclose($entry['pipes'][0]);
    }

    $results = [];
    foreach ($processes as $index => $entry) {
        $stdout = stream_get_contents($entry['pipes'][1]);
        $stderr = stream_get_contents($entry['pipes'][2]);
        fclose($entry['pipes'][1]);
        fclose($entry['pipes'][2]);
        $status = proc_close($entry['process']);
        @unlink($entry['payloadFile']);
        if ($status !== 0) {
            throw new RuntimeException('Concurrency worker failed: ' . trim((string) $stderr));
        }
        $decoded = json_decode(trim((string) $stdout), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new RuntimeException('Concurrency worker returned invalid result.');
        }
        $results[$index] = $decoded;
    }
    ksort($results);
    return array_values($results);
}

/**
 * Insert one active viewer fixture and return its id.
 *
 * @param PDO $pdo Test connection.
 * @param string $email Unique fixture email.
 * @param int $securityVersion Initial security version.
 * @return int Viewer account id.
 */
function phase07_mysql_insert_account(PDO $pdo, string $email, int $securityVersion = 1): int
{
    $stmt = $pdo->prepare(
        "INSERT INTO viewer_accounts (email, normalized_email, password_hash, status, security_version, email_verified_at, password_changed_at, created_at, updated_at) "
        . "VALUES (?, ?, 'phase07-test-hash', 'active', ?, NOW(), NOW(), NOW(), NOW())"
    );
    $stmt->execute([$email, $email, $securityVersion]);
    return (int) $pdo->lastInsertId();
}

if (($argv[1] ?? '') === '--worker') {
    try {
        $action = (string) ($argv[2] ?? '');
        $payloadFile = (string) ($argv[3] ?? '');
        $raw = $payloadFile !== '' ? file_get_contents($payloadFile) : false;
        $payload = is_string($raw) ? json_decode($raw, true, 512, JSON_THROW_ON_ERROR) : null;
        if (!is_array($payload)) {
            throw new RuntimeException('Worker payload is invalid.');
        }
        echo json_encode(phase07_mysql_worker_action($action, $payload), JSON_THROW_ON_ERROR) . "\n";
        exit(0);
    } catch (Throwable $e) {
        fwrite(STDERR, $e->getMessage() . "\n");
        exit(1);
    }
}

if (!extension_loaded('pdo_mysql')) {
    echo "SKIP viewer Phase 0.7 MySQL/MariaDB concurrency tests: pdo_mysql is not available.\n";
    exit(0);
}
if (trim((string) getenv('GALLERY_TEST_MYSQL_DSN')) === '') {
    echo "SKIP viewer Phase 0.7 MySQL/MariaDB concurrency tests: GALLERY_TEST_MYSQL_DSN is not configured.\n";
    exit(0);
}

$pdo = phase07_mysql_connection();
$requiredTables = [
    'viewer_accounts',
    'viewer_account_state',
    'viewer_registration_requests',
    'viewer_sessions',
    'viewer_remember_tokens',
    'viewer_password_reset_tokens',
];
foreach ($requiredTables as $table) {
    $stmt = $pdo->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1');
    $stmt->execute([$table]);
    if (!$stmt->fetchColumn()) {
        echo 'SKIP viewer Phase 0.7 MySQL/MariaDB concurrency tests: required migrated table is missing: ' . $table . "\n";
        exit(0);
    }
}

$prefix = 'phase07_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '_';
$reports = [];
try {
    // 1. Two concurrent verified activations for the same staging registration.
    $registrationEmail = $prefix . 'activation@example.test';
    $stmt = $pdo->prepare(
        "INSERT INTO viewer_registration_requests (email, normalized_email, email_fingerprint, status, verification_token_hash, verification_token_expires_at, expires_at, verified_at, created_at, updated_at) "
        . "VALUES (?, ?, ?, 'email_verified', ?, DATE_ADD(NOW(), INTERVAL 1 HOUR), DATE_ADD(NOW(), INTERVAL 1 HOUR), NOW(), NOW(), NOW())"
    );
    $stmt->execute([$registrationEmail, $registrationEmail, hash('sha256', $registrationEmail), hash('sha256', $registrationEmail . ':verify')]);
    $requestId = (int) $pdo->lastInsertId();
    $results = phase07_mysql_run_workers([
        ['action' => 'activate_same_registration', 'payload' => ['request_id' => $requestId]],
        ['action' => 'activate_same_registration', 'payload' => ['request_id' => $requestId]],
    ]);
    phase07_mysql_assert(count(array_filter($results, static fn (array $r): bool => !empty($r['won']))) === 1, 'Exactly one concurrent verified activation must win.');
    $reports[] = 'PASS concurrent verified activation';

    // 2. Durable viewer-account hard-cap race.
    $baselineCount = (int) $pdo->query('SELECT COUNT(*) FROM viewer_accounts')->fetchColumn();
    $results = phase07_mysql_run_workers([
        ['action' => 'capacity_insert', 'payload' => ['cap' => $baselineCount + 1, 'email' => $prefix . 'cap-a@example.test']],
        ['action' => 'capacity_insert', 'payload' => ['cap' => $baselineCount + 1, 'email' => $prefix . 'cap-b@example.test']],
    ]);
    phase07_mysql_assert(count(array_filter($results, static fn (array $r): bool => !empty($r['won']))) === 1, 'Exactly one hard-cap insertion must win.');
    $reports[] = 'PASS durable viewer-account capacity race';

    // 3. Active viewer-session cap race.
    $sessionAccountId = phase07_mysql_insert_account($pdo, $prefix . 'session@example.test');
    $results = phase07_mysql_run_workers([
        ['action' => 'session_insert_capped', 'payload' => ['account_id' => $sessionAccountId, 'cap' => 1, 'token_seed' => $prefix . 'session-a']],
        ['action' => 'session_insert_capped', 'payload' => ['account_id' => $sessionAccountId, 'cap' => 1, 'token_seed' => $prefix . 'session-b']],
    ]);
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM viewer_sessions WHERE viewer_account_id = ? AND revoked_at IS NULL AND expires_at >= NOW()');
    $stmt->execute([$sessionAccountId]);
    phase07_mysql_assert((int) $stmt->fetchColumn() === 1, 'Concurrent viewer-session creation must preserve the active cap.');
    $reports[] = 'PASS active viewer-session cap race';

    // 4. Remember-token restore/rotation race.
    $rememberAccountId = phase07_mysql_insert_account($pdo, $prefix . 'remember@example.test');
    $oldSelector = substr(hash('sha256', $prefix . 'old-selector'), 0, 36);
    $stmt = $pdo->prepare('INSERT INTO viewer_remember_tokens (viewer_account_id, selector, verifier_hash, security_version, created_at, expires_at) VALUES (?, ?, ?, 1, NOW(), DATE_ADD(NOW(), INTERVAL 1 HOUR))');
    $stmt->execute([$rememberAccountId, $oldSelector, hash('sha256', $prefix . 'old-verifier')]);
    $rememberTokenId = (int) $pdo->lastInsertId();
    $results = phase07_mysql_run_workers([
        ['action' => 'remember_rotate', 'payload' => ['token_id' => $rememberTokenId, 'old_selector' => $oldSelector, 'new_seed' => $prefix . 'remember-a']],
        ['action' => 'remember_rotate', 'payload' => ['token_id' => $rememberTokenId, 'old_selector' => $oldSelector, 'new_seed' => $prefix . 'remember-b']],
    ]);
    phase07_mysql_assert(count(array_filter($results, static fn (array $r): bool => !empty($r['won']))) === 1, 'Exactly one remember-token rotation must consume the old selector authority.');
    $reports[] = 'PASS remember-token rotation race';

    // 5. Concurrent use of one password-reset token.
    $resetAccountId = phase07_mysql_insert_account($pdo, $prefix . 'reset@example.test', 5);
    $stmt = $pdo->prepare('INSERT INTO viewer_password_reset_tokens (viewer_account_id, token_hash, security_version, created_at, expires_at) VALUES (?, ?, 5, NOW(), DATE_ADD(NOW(), INTERVAL 1 HOUR))');
    $stmt->execute([$resetAccountId, hash('sha256', $prefix . 'reset-token')]);
    $resetTokenId = (int) $pdo->lastInsertId();
    $results = phase07_mysql_run_workers([
        ['action' => 'reset_consume', 'payload' => ['account_id' => $resetAccountId, 'security_version' => 5, 'token_id' => $resetTokenId]],
        ['action' => 'reset_consume', 'payload' => ['account_id' => $resetAccountId, 'security_version' => 5, 'token_id' => $resetTokenId]],
    ]);
    phase07_mysql_assert(count(array_filter($results, static fn (array $r): bool => !empty($r['won']))) === 1, 'Exactly one concurrent reset-token consumer must win.');
    $reports[] = 'PASS password-reset single-use race';

    // 6. Authentication/session creation competing with security-version invalidation.
    $securityAccountId = phase07_mysql_insert_account($pdo, $prefix . 'security-version@example.test', 9);
    phase07_mysql_run_workers([
        ['action' => 'auth_session_expected_version', 'payload' => ['account_id' => $securityAccountId, 'security_version' => 9, 'token_seed' => $prefix . 'auth-session']],
        ['action' => 'invalidate_security_version', 'payload' => ['account_id' => $securityAccountId]],
    ]);
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM viewer_sessions WHERE viewer_account_id = ? AND revoked_at IS NULL');
    $stmt->execute([$securityAccountId]);
    phase07_mysql_assert((int) $stmt->fetchColumn() === 0, 'Security-version invalidation must leave no active stale viewer session.');
    $reports[] = 'PASS security-version invalidation race';

    // 7. Account deletion plus durable-capacity counter consistency.
    $deleteAccountId = phase07_mysql_insert_account($pdo, $prefix . 'delete@example.test', 3);
    $pdo->exec("INSERT INTO viewer_account_state (state_key, account_count, updated_at) VALUES ('accounts', 0, NOW()) ON DUPLICATE KEY UPDATE account_count = account_count");
    phase07_mysql_run_workers([
        ['action' => 'delete_and_reconcile', 'payload' => ['account_id' => $deleteAccountId]],
        ['action' => 'capacity_insert', 'payload' => ['cap' => 100000, 'email' => $prefix . 'delete-race-insert@example.test']],
    ]);
    $actual = (int) $pdo->query('SELECT COUNT(*) FROM viewer_accounts')->fetchColumn();
    $stored = (int) $pdo->query("SELECT account_count FROM viewer_account_state WHERE state_key = 'accounts'")->fetchColumn();
    phase07_mysql_assert($stored === $actual, 'Account deletion/insertion race must leave the durable account counter reconciled.');
    $reports[] = 'PASS account deletion/capacity reconciliation race';

    foreach ($reports as $report) {
        echo $report . "\n";
    }
} finally {
    $pdo->prepare('DELETE FROM viewer_registration_requests WHERE normalized_email LIKE ?')->execute([$prefix . '%']);
    $pdo->prepare('DELETE FROM viewer_accounts WHERE normalized_email LIKE ?')->execute([$prefix . '%']);
    $pdo->exec("INSERT INTO viewer_account_state (state_key, account_count, updated_at) VALUES ('accounts', 0, NOW()) ON DUPLICATE KEY UPDATE updated_at = updated_at");
    $actual = (int) $pdo->query('SELECT COUNT(*) FROM viewer_accounts')->fetchColumn();
    $pdo->prepare("UPDATE viewer_account_state SET account_count = ?, updated_at = NOW() WHERE state_key = 'accounts'")->execute([$actual]);
}
