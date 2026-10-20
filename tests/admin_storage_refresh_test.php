<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/admin_storage_refresh_test.php
 * Module Type: Regression Test
 *
 * Purpose:
 *   Verifies authoritative server-side behavior for the Admin Update all workflow.
 *
 * Responsibilities:
 *   - Exercise workflow ownership, rotating IDs, bounded phase transitions, and retries
 *   - Verify failure continuation and safe partial terminal results without a database
 *   - Protect controller, browser-refresh, and accessible UI boundaries
 *
 * Author:
 *   Rudolf Klusal
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 *
 * Notes:
 *   - Domain dependencies are isolated with namespaced stubs and a unique temporary cache.
 */

declare(strict_types=1);

namespace Gallery\Services;

use RuntimeException;

$GLOBALS['storage_refresh_fixture'] = [
    'file_start_calls' => 0,
    'file_step_calls' => 0,
    'database_calls' => [],
    'inspection_calls' => 0,
];

/**
 * Start the fixture file-statistics job.
 *
 * @return array<string, int|string> Running file-job summary.
 */
function admin_storage_statistics_start_job(): array
{
    $GLOBALS['storage_refresh_fixture']['file_start_calls']++;
    \Gallery\Core\storage_observer_add('counts.source_image_rows', 1);
    return ['status' => 'running', 'processed' => 0, 'total' => 1];
}

/**
 * Complete one fixture file-statistics batch.
 *
 * @param int $batchSize Requested batch bound.
 * @return array{status:string,processed:int,total:int,snapshot:array{
 *     image_count:int,pdf_count:int,pdf_bytes:int,largest_original_name:string}}
 *     Completed summary with a private label that must never enter diagnostics.
 */
function admin_storage_statistics_process_job(int $batchSize): array
{
    $GLOBALS['storage_refresh_fixture']['file_step_calls']++;
    $GLOBALS['storage_refresh_fixture']['file_batch_size'] = $batchSize;
    \Gallery\Core\storage_observer_call(
        'fs.is_file',
        static fn(): bool => false,
    );
    return [
        'status' => 'complete',
        'processed' => 1,
        'total' => 1,
        'snapshot' => [
            'image_count' => 1,
            'pdf_count' => 2,
            'pdf_bytes' => 10,
            'largest_original_name' => '/private/original-secret.pdf',
        ],
    ];
}

/**
 * Capture owned lifecycle log contexts without touching installation storage.
 * @param string $level Requested event level.
 * @param string $eventKey Code-owned event identity.
 * @param string $message Safe event description.
 * @param StorageRefreshLogContext $context Numeric run/stage diagnostics or failure class.
 * @param array{category?:string,severity?:string} $options Event display metadata.
 * @return void Append to the fixture or inject an optional logging failure.
 */
function admin_log_event(
    string $level,
    string $eventKey,
    string $message,
    array $context = [],
    array $options = [],
): void {
    if (!empty($GLOBALS['storage_refresh_log_failure'])) {
        throw new RuntimeException('Fixture log storage unavailable.');
    }
    $GLOBALS['storage_refresh_logs'][] = [
        'event' => $eventKey,
        'context' => $context,
    ];
}

/**
 * Simulate two bounded database ANALYZE batches, including one recoverable table failure.
 *
 * @param int $offset Server-owned database cursor.
 * @param int $batchSize Requested table batch bound.
 * @return array<string, int|bool> Bounded database progress.
 */
function admin_database_usage_recompute_statistics_batch(
    int $offset,
    int $batchSize = 5,
): array {
    $GLOBALS['storage_refresh_fixture']['database_calls'][] = [
        $offset,
        $batchSize,
    ];
    if ($offset === 0) {
        return [
            'processed' => 5,
            'total' => 7,
            'failed_table_count' => 1,
            'complete' => false,
        ];
    }
    if ($offset === 5) {
        return [
            'processed' => 7,
            'total' => 7,
            'failed_table_count' => 0,
            'complete' => true,
        ];
    }
    throw new RuntimeException('Unexpected database cursor.');
}

/**
 * Fail read-only inspection with a private diagnostic to verify response normalization.
 *
 * @return array<string, bool> Unused successful inspection result.
 */
function database_maintenance_inspect(): array
{
    $GLOBALS['storage_refresh_fixture']['inspection_calls']++;
    throw new RuntimeException('private inspection secret');
}

/**
 * Return the localized fallback used by the workflow service.
 *
 * @param string $key Translation key.
 * @param string $fallback Safe fallback text.
 * @return string Translation fallback.
 */
function t(string $key, string $fallback): string
{
    return $fallback;
}

$fixtureDirectory =
    sys_get_temp_dir()
    . DIRECTORY_SEPARATOR
    . 'gallery-storage-refresh-'
    . bin2hex(random_bytes(8));
if (!mkdir($fixtureDirectory, 0700) && !is_dir($fixtureDirectory)) {
    throw new RuntimeException(
        'Could not create the isolated workflow fixture directory.',
    );
}
define('ADMIN_STORAGE_REFRESH_TEST_CACHE_DIR', $fixtureDirectory);
require_once __DIR__ . '/../app/services/admin_storage_refresh.php';

/**
 * Assert a storage-refresh fixture expectation.
 *
 * @param bool $condition Whether the expected condition holds.
 * @param string $message Failure description.
 * @return void Throws when the condition is false.
 */
function storage_refresh_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * Read one named function body for focused source-contract checks.
 *
 * @param string $source PHP source text.
 * @param string $name Named function to locate.
 * @return string Function source or an empty string when absent.
 */
function storage_refresh_function_source(string $source, string $name): string
{
    $pattern =
        '/function\s+'
        . preg_quote($name, '/')
        . '\s*\([^)]*\)\s*:\s*[^\{]+\{/';
    if (!preg_match($pattern, $source, $match, PREG_OFFSET_CAPTURE)) {
        return '';
    }
    $start = $match[0][1] + strlen($match[0][0]) - 1;
    $depth = 0;
    $length = strlen($source);
    for ($index = $start; $index < $length; $index++) {
        if ($source[$index] === '{') {
            $depth++;
        } elseif ($source[$index] === '}') {
            $depth--;
            if ($depth === 0) {
                return substr($source, $match[0][1], $index - $match[0][1] + 1);
            }
        }
    }
    return '';
}

/**
 * Remove only files created inside this test's unique cache directory.
 *
 * @param string $directory Test-owned temporary directory.
 * @return void Removes known workflow state and lock files.
 */
function storage_refresh_cleanup(string $directory): void
{
    foreach (['workflow.json', 'workflow.lock'] as $filename) {
        $path = $directory . DIRECTORY_SEPARATOR . $filename;
        if (is_file($path)) {
            unlink($path);
        }
    }
    foreach (glob($directory . DIRECTORY_SEPARATOR . 'workflow.json.*.tmp')
        ?: [] as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }
    if (is_dir($directory)) {
        rmdir($directory);
    }
}

try {
    $started = admin_storage_refresh_all_start(41);
    $firstId = (string) ($started['workflow_id'] ?? '');
    storage_refresh_assert(
        !empty($started['ok'])
            && preg_match('/^[a-f0-9]{32}$/D', $firstId) === 1,
        'Start must issue an opaque workflow capability.',
    );
    storage_refresh_assert(
        $started['phase'] === 'files'
            && $started['workflow_status'] === 'running',
        'Start must begin at the canonical files phase.',
    );

    $duplicateStart = admin_storage_refresh_all_start(41);
    storage_refresh_assert(
        $duplicateStart['workflow_id'] === $firstId
            && $GLOBALS['storage_refresh_fixture']['file_start_calls'] === 1,
        'An owner retry must recover the existing active workflow without restarting file work.',
    );
    storage_refresh_assert(
        empty(admin_storage_refresh_all_start(42)['ok'])
            && $GLOBALS['storage_refresh_fixture']['file_start_calls'] === 1,
        'A different administrator must not attach to or replace an active workflow.',
    );
    storage_refresh_assert(
        empty(admin_storage_refresh_all_step($firstId, 42)['ok'])
            && $GLOBALS['storage_refresh_fixture']['file_step_calls'] === 0,
        'A non-owner must not advance the active workflow.',
    );
    storage_refresh_assert(
        empty(admin_storage_refresh_all_step(str_repeat('0', 32), 41)['ok'])
            && $GLOBALS['storage_refresh_fixture']['file_step_calls'] === 0,
        'An unknown workflow ID must not advance work.',
    );

    $files = admin_storage_refresh_all_step($firstId, 41);
    $databaseId = (string) ($files['workflow_id'] ?? '');
    storage_refresh_assert(
        $files['phase'] === 'database'
            && $files['workflow_status'] === 'running',
        'Completing files must move to the server-owned database phase.',
    );
    storage_refresh_assert(
        $databaseId !== $firstId
            && preg_match('/^[a-f0-9]{32}$/D', $databaseId) === 1,
        'Each successful step must rotate the one-step capability.',
    );
    storage_refresh_assert(
        $GLOBALS['storage_refresh_fixture']['file_batch_size'] === 20,
        'File processing must use its bounded server-side batch size.',
    );
    storage_refresh_assert(
        empty(admin_storage_refresh_all_step($firstId, 41)['ok'])
            && $GLOBALS['storage_refresh_fixture']['file_step_calls'] === 1,
        'Replaying a stale capability must not repeat file work.',
    );

    $databaseFirst = admin_storage_refresh_all_step($databaseId, 41);
    $inspectionId = (string) ($databaseFirst['workflow_id'] ?? '');
    storage_refresh_assert(
        $databaseFirst['phase'] === 'database'
            && $databaseFirst['processed'] === 5,
        'The first database step must expose bounded cursor progress.',
    );
    $databaseSecond = admin_storage_refresh_all_step($inspectionId, 41);
    $finalId = (string) ($databaseSecond['workflow_id'] ?? '');
    storage_refresh_assert(
        $databaseSecond['phase'] === 'inspection',
        'Database batches must continue from the stored cursor and then enter inspection.',
    );
    storage_refresh_assert(
        $GLOBALS['storage_refresh_fixture']['database_calls'] === [
            [0, 5],
            [5, 5],
        ],
        'Database batches must use the persisted cursor and never exceed five tables.',
    );

    $terminal = admin_storage_refresh_all_step($finalId, 41);
    storage_refresh_assert(
        $terminal['phase'] === 'complete'
            && $terminal['workflow_status'] === 'partial',
        'Recoverable database and inspection errors must yield a partial terminal result after later safe stages run.',
    );
    storage_refresh_assert(
        count($terminal['stages']) === 3
            && $terminal['stages'][1]['status'] === 'partial'
            && $terminal['stages'][2]['status'] === 'failed',
        'The terminal result must preserve bounded per-stage outcomes.',
    );
    storage_refresh_assert(
        !str_contains(
            json_encode($terminal, JSON_THROW_ON_ERROR),
            'private inspection secret',
        ),
        'Raw inspection exceptions must never reach the browser payload.',
    );
    $terminalRetry = admin_storage_refresh_all_step(
        $terminal['workflow_id'],
        41,
    );
    storage_refresh_assert(
        $terminalRetry['workflow_status'] === 'partial'
            && $GLOBALS['storage_refresh_fixture']['inspection_calls'] === 1,
        'A terminal retry must return its saved result without repeating inspection.',
    );
    storage_refresh_assert(
        empty(admin_storage_refresh_all_step($finalId, 41)['ok'])
            && $GLOBALS['storage_refresh_fixture']['inspection_calls'] === 1,
        'The superseded pre-terminal capability must remain rejected.',
    );
    $completedLogs = array_values(array_filter(
        $GLOBALS['storage_refresh_logs'],
        static fn(array $row): bool => (
            $row['event'] === 'storage.refresh_all.completed'
        ),
    ));
    storage_refresh_assert(
        count($completedLogs) === 1,
        'Persisted completion and terminal retries must emit one final diagnostic.',
    );
    $context = $completedLogs[0]['context'];
    $diagnostics = $context['diagnostics'];
    storage_refresh_assert(
        $diagnostics['resume_count'] === 1
            && $diagnostics['run_id'] !== $firstId,
        'Owner resumes must retain one non-authorizing diagnostic identity.',
    );
    storage_refresh_assert(
        $diagnostics['phases']['files']['steps'] === 2
            && $diagnostics['phases']['database']['steps'] === 2
            && $diagnostics['phases']['inspection']['steps'] === 1,
        'Run diagnostics must include every phase request, including thrown inspection.',
    );
    storage_refresh_assert(
        $diagnostics['phases']['files']['metrics']['fs.is_file.calls'] === 1
            && $diagnostics['phases']['files']['metrics']['fs.is_file.false_results']
                === 1
            && $diagnostics['files']['pdf_count'] === 2,
        'File-operation attempts, absent results and final DB totals must remain distinct.',
    );
    $logged = json_encode($context, JSON_THROW_ON_ERROR);
    storage_refresh_assert(
        !str_contains($logged, $firstId)
            && !str_contains($logged, '/private/original-secret.pdf')
            && !str_contains($logged, 'private inspection secret'),
        'Log contexts must exclude capabilities, media names/paths and exception text.',
    );
    storage_refresh_assert(
        $context['wall_seconds'] >= 0
            && $diagnostics['phase_work_seconds'] >= 0
            && $diagnostics['peak_memory_bytes'] > 0,
        'Timings and memory diagnostics must be available without extra I/O.',
    );
    \Gallery\Core\storage_observer_add('passive.reader');
    storage_refresh_assert(
        \Gallery\Core\storage_observer_finish() === [],
        'Observation must be disabled after each request, including a failed phase.',
    );
    $GLOBALS['storage_refresh_log_failure'] = true;
    admin_storage_refresh_log_run(
        admin_storage_refresh_read_state(),
        'completed',
    );
    $GLOBALS['storage_refresh_log_failure'] = false;
    \Gallery\Core\storage_observer_start();
    $originalFailure = new RuntimeException('private callback payload');
    try {
        \Gallery\Core\storage_observer_call('fixture.throw', static function () use (
            $originalFailure,
        ): void {
            throw $originalFailure;
        });
    } catch (RuntimeException $caught) {
        storage_refresh_assert(
            $caught === $originalFailure,
            'Operation observation must preserve the original exception object.',
        );
    }
    $readValue = \Gallery\Core\storage_observer_call(
        'fs.read.fixture',
        static fn(): string => 'abc',
    );
    $observed = \Gallery\Core\storage_observer_finish();
    storage_refresh_assert(
        $readValue === 'abc'
            && $observed['fs.read.fixture.bytes'] === 3
            && $observed['fixture.throw.exceptions'] === 1,
        'Read-byte and exception counters must preserve original operation behavior.',
    );
    \Gallery\Core\storage_observer_start();
    // Exceed the declared cardinality by one structural boundary sample.
    for (
        $index = 0;
        $index <= \Gallery\Core\STORAGE_OBSERVER_MAX_METRICS;
        $index++
    ) {
        \Gallery\Core\storage_observer_add('fixture.' . $index);
    }
    $bounded = \Gallery\Core\storage_observer_finish();
    storage_refresh_assert(
        count($bounded) <= \Gallery\Core\STORAGE_OBSERVER_MAX_METRICS
            && $bounded['observer.metric_updates_dropped'] > 0,
        'Metric cardinality must remain bounded and report discarded updates.',
    );

    $statePath = $fixtureDirectory . DIRECTORY_SEPARATOR . 'workflow.json';
    file_put_contents($statePath, json_encode([
        'workflow_id' => 'malformed-token',
        'owner_id' => 41,
        'phase' => 'files',
        'workflow_status' => 'running',
        'stages' => [],
    ], JSON_THROW_ON_ERROR));
    $startsBeforeRecovery =
        $GLOBALS['storage_refresh_fixture']['file_start_calls'];
    $recovered = admin_storage_refresh_all_start(41);
    storage_refresh_assert(
        !empty($recovered['ok'])
            && preg_match(
                '/^[a-f0-9]{32}$/D',
                (string) $recovered['workflow_id'],
            ) === 1
            && $recovered['workflow_id'] !== 'malformed-token',
        'An incompatible active cache record must be replaced with a fresh server-owned workflow.',
    );
    storage_refresh_assert(
        $GLOBALS['storage_refresh_fixture']['file_start_calls']
            === ($startsBeforeRecovery + 1),
        'Malformed-state recovery must start exactly one new bounded file job.',
    );
    storage_refresh_assert(
        empty(admin_storage_refresh_all_start(42)['ok'])
            && $GLOBALS['storage_refresh_fixture']['file_start_calls']
                === ($startsBeforeRecovery + 1),
        'A valid recovered workflow must still reject a different administrator.',
    );

    $controllerPath = dirname(__DIR__) . '/app/controllers/admin_dashboard.php';
    $controller = file_get_contents($controllerPath);
    $handler = is_string($controller)
        ? storage_refresh_function_source(
            $controller,
            'cms_admin_storage_statistics_update',
        )
        : '';
    storage_refresh_assert(
        $handler !== ''
            && strpos($handler, 'require_admin();') < strpos(
                $handler,
                "request_method() !== 'POST'",
            )
            && strpos($handler, "request_method() !== 'POST'") < strpos(
                $handler,
                'verify_csrf();',
            ),
        'The unified update action must preserve admin, POST, and CSRF boundaries before work starts.',
    );
    storage_refresh_assert(
        str_contains($handler, "\$_POST['workflow_id']")
            && !str_contains($handler, "\$_POST['phase']")
            && !str_contains($handler, "\$_POST['stages_json']"),
        'The controller must accept only the opaque workflow capability, not client-selected phase state.',
    );

    $service = file_get_contents(__DIR__
        . '/../app/services/admin_storage_refresh.php');
    storage_refresh_assert(
        is_string($service)
            && !preg_match(
                '/\b(?:OPTIMIZE\s+TABLE|DROP\s+TABLE|TRUNCATE\s+TABLE|admin_maintenance_center_execute|database_maintenance_(?:repair|optimize|cleanup))\b/i',
                $service,
            ),
        'The update workflow must remain free of destructive or Maintenance Center execution paths.',
    );

    $browserPath =
        dirname(__DIR__)
        . '/public/assets/gallery-modules/admin-storage-statistics.js';
    $browser = file_get_contents($browserPath);
    storage_refresh_assert(
        is_string($browser)
            && str_contains($browser, 'fetch(window.location.href')
            && str_contains($browser, "'Accept': 'text/html'")
            && str_contains($browser, '[data-admin-storage-content]'),
        'Completion must fetch the current page as HTML and replace only the owned tab content.',
    );
    storage_refresh_assert(
        !str_contains($browser, 'window.location =')
            && !str_contains($browser, 'window.location.href =')
            && !str_contains($browser, 'window.location.assign('),
        'In-place refresh must preserve the active URL and tab.',
    );
    storage_refresh_assert(
        str_contains($browser, "payload.workflow_status === 'partial'")
            && str_contains($browser, "payload.workflow_status !== 'complete'"),
        'The client must honor server partial and complete terminal states distinctly.',
    );

    $storageViewPath =
        dirname(__DIR__) . '/app/views/admin_storage_statistics.php';
    $storageView = file_get_contents($storageViewPath);
    storage_refresh_assert(
        is_string($storageView)
            && str_contains($storageView, '<details')
            && str_contains($storageView, '<summary')
            && str_contains($storageView, 'data-admin-storage-update-button'),
        'Help and Update all controls must retain native, keyboard-operable disclosure/button semantics.',
    );
    $databaseView = file_get_contents(
        dirname(__DIR__) . '/app/views/admin_database_maintenance.php',
    );
    storage_refresh_assert(
        is_string($databaseView)
            && str_contains($databaseView, 'optimize_warning')
            && str_contains($databaseView, '<details'),
        'Destructive safety warnings must remain visible outside optional explanatory help.',
    );

    echo "Admin storage refresh workflow tests passed.\n";
} finally {
    storage_refresh_cleanup($fixtureDirectory);
}
