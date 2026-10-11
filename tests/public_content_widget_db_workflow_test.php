<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/public_content_widget_db_workflow_test.php
 * Module Type: Database Integration Test
 * Purpose: Prove failed migration recovery, widget row backup/restore and real database workflows.
 * Responsibilities:
 *   - Reuse only the existing explicitly owned disposable workflow database.
 *   - Exercise the actual production widget service/model with two independent PDO connections.
 *   - Round-trip complete mixed widget rows through a session-scoped database snapshot.
 *   - Reject a replayed migration checkpoint after its DDL, then retry without changing valid widget state.
 *   - Retry the updater-facing bounded widget-table migration after rows exist and preserve them exactly.
 *   - Leave the fixture unchanged after success or failure.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Core {
    /**
     * Expose only the independently validated disposable workflow PDO to the widget Model.
     *
     * @return \PDO Current owned fixture connection, or a refusal before setup.
     */
    function db(): \PDO
    {
        return $GLOBALS['public_widget_workflow_db']
            ?? throw new \RuntimeException('Widget DB workflow fixture has not been validated.');
    }
}

namespace {
    require_once __DIR__ . '/support/gallery_workflow_safety.php';
    require_once dirname(__DIR__) . '/app/helpers_runtime.php';
    require_once dirname(__DIR__) . '/app/migrations.php';
    require_once dirname(__DIR__) . '/app/services/public_content_widgets.php';

    use Gallery\Services\PublicWidgetInvalidField;
    use function GalleryWorkflow\check;
    use function GalleryWorkflow\fixtureDatabase;
    use function GalleryWorkflow\validateFixture;
    use function Gallery\Core\pending_migrations_exist;
    use function Gallery\Core\run_migrations_bounded;
    use function Gallery\Models\public_widget_model_find;
    use function Gallery\Services\public_widget_admin_list;
    use function Gallery\Services\public_widget_create;
    use function Gallery\Services\public_widget_delete;
    use function Gallery\Services\public_widget_duplicate;
    use function Gallery\Services\public_widget_public_plan;
    use function Gallery\Services\public_widget_public_rows;
    use function Gallery\Services\public_widget_reorder;
    use function Gallery\Services\public_widget_save;

    if (!getenv('GALLERY_WORKFLOW_FIXTURE')) {
        $required = getenv('GALLERY_WORKFLOW_REQUIRED') === '1';
        echo ($required ? 'BLOCKED' : 'SKIP')
            . " public content widget DB workflow requires an owned disposable MySQL fixture\n";
        exit($required ? 1 : 0);
    }

    $createdIds = [];
    $stage = 'fixture identity and migration';
    $widgetMigrationVersion = '202610100001_public_content_widgets';
    $widgetMigrationAppliedAt = null;
    $retryFailureConstraintName = 'public_widget_retry_prevent_apply';
    $retryFailureConstraintInstalled = false;
    $widgetBackupTableSql = '';
    $widgetBackupTableCreated = false;
    try {
        $token = (string) getenv('GALLERY_WORKFLOW_TOKEN');
        $directory = validateFixture((string) getenv('GALLERY_WORKFLOW_FIXTURE'), $token);
        $pdo = fixtureDatabase($directory, $token);
        $peer = fixtureDatabase($directory, $token);
        $GLOBALS['public_widget_workflow_db'] = $pdo;
        check($pdo !== $peer, 'Concurrent widget editors must use distinct database handles.');

        $table = $pdo->query("SHOW TABLES LIKE 'public_content_widgets'")->fetchColumn();
        check($table === 'public_content_widgets', 'Widget migration missing from the disposable installation.');
        $initialCount = (int) $pdo->query('SELECT COUNT(*) FROM public_content_widgets')->fetchColumn();

        $stage = 'updater bounded migration runner recovers DDL with a missing version record';
        check(!pending_migrations_exist(), 'Workflow fixture must begin with every shipped migration recorded.');
        $migrationRecord = $pdo->prepare('SELECT applied_at FROM schema_migrations WHERE version = ?');
        $migrationRecord->execute([$widgetMigrationVersion]);
        $widgetMigrationAppliedAt = $migrationRecord->fetchColumn();
        check(is_string($widgetMigrationAppliedAt) && $widgetMigrationAppliedAt !== '',
            'Widget migration baseline record is missing.');

        $retryRows = [
            [
                'title' => 'Migration retry draft flow',
                'content_md' => 'Keep this draft and its in-flow slot during migration recovery.',
                'page_scope' => 'gallery',
                'placement_mode' => 'flow',
                'flow_slot' => 'content_bottom',
                'status' => 'draft',
                'source_language' => 'en',
                'sort_order' => 17,
            ],
            [
                'title' => 'Migration retry published flow',
                'content_md' => 'Keep this published Home widget visible after migration recovery.',
                'page_scope' => 'home',
                'placement_mode' => 'flow',
                'flow_slot' => 'home_after_grid',
                'status' => 'published',
                'source_language' => 'en',
                'sort_order' => 18,
            ],
            [
                'title' => 'Migration retry disabled floating',
                'content_md' => 'Keep the disabled floating placement during migration recovery.',
                'page_scope' => 'all',
                'placement_mode' => 'floating',
                'flow_slot' => 'content_top',
                'floating_anchor' => 'middle-right',
                'x_permille' => 720,
                'y_permille' => 500,
                'width_px' => 300,
                'status' => 'disabled',
                'source_language' => 'en',
                'sort_order' => 19,
            ],
        ];
        $retrySnapshots = [];
        foreach ($retryRows as $retryRow) {
            $retryWidgetId = public_widget_create($retryRow);
            $createdIds[] = $retryWidgetId;
            $savedRetryRow = public_widget_model_find($retryWidgetId);
            check(is_array($savedRetryRow), 'Migration recovery fixture row was not created.');
            $retrySnapshots[$retryWidgetId] = $savedRetryRow;
        }
        $beforeRecoveryPublicIds = array_column(public_widget_public_rows('home'), 'widget_id');
        $retryWidgetIds = array_keys($retrySnapshots);
        check(in_array($retryWidgetIds[1], $beforeRecoveryPublicIds, true)
            && !in_array($retryWidgetIds[0], $beforeRecoveryPublicIds, true)
            && !in_array($retryWidgetIds[2], $beforeRecoveryPublicIds, true),
            'Mixed fixture statuses must expose only the eligible published Home widget before retry.');

        $removeMigrationRecord = $pdo->prepare('DELETE FROM schema_migrations WHERE version = ?');
        $removeMigrationRecord->execute([$widgetMigrationVersion]);
        check($removeMigrationRecord->rowCount() === 1, 'Could not model the post-DDL missing-ledger migration state.');

        // Force only the version checkpoint to fail after replay-safe DDL has completed.
        $pdo->exec('ALTER TABLE schema_migrations ADD CONSTRAINT ' . $retryFailureConstraintName
            . ' CHECK (version <> ' . $pdo->quote($widgetMigrationVersion) . ')');
        $retryFailureConstraintInstalled = true;
        $stage = 'updater bounded migration runner leaves failed checkpoint retryable';
        $failedMigrationException = null;
        try {
            run_migrations_bounded(1);
        } catch (\PDOException $exception) {
            $failedMigrationException = $exception;
        }
        check($failedMigrationException instanceof \PDOException
            && in_array((int) ($failedMigrationException->errorInfo[1] ?? 0), [3819, 4025], true),
            'Migration checkpoint did not fail on the disposable version constraint.');
        $migrationRecord->execute([$widgetMigrationVersion]);
        check($migrationRecord->fetchColumn() === false,
            'Failed migration attempt left a false applied ledger/version marker.');
        check($pdo->query("SHOW TABLES LIKE 'public_content_widgets'")->fetchColumn() === 'public_content_widgets',
            'Failed migration attempt removed the existing widget table.');
        foreach ($retrySnapshots as $retryWidgetId => $retryRowBefore) {
            check(public_widget_model_find($retryWidgetId) === $retryRowBefore,
                'Failed migration attempt changed an existing widget row or persisted field.');
        }
        $afterFailurePublicIds = array_column(public_widget_public_rows('home'), 'widget_id');
        check(in_array($retryWidgetIds[1], $afterFailurePublicIds, true)
            && !in_array($retryWidgetIds[0], $afterFailurePublicIds, true)
            && !in_array($retryWidgetIds[2], $afterFailurePublicIds, true),
            'Failed migration attempt changed public widget eligibility.');

        $pdo->exec('ALTER TABLE schema_migrations DROP CONSTRAINT ' . $retryFailureConstraintName);
        $retryFailureConstraintInstalled = false;
        $stage = 'updater bounded migration runner retries the failed checkpoint';
        $boundedResult = run_migrations_bounded(1);
        check($boundedResult === ['ran' => [$widgetMigrationVersion], 'remaining' => 0],
            'Updater bounded migration runner did not replay only the missing widget migration.');
        $migrationRecord->execute([$widgetMigrationVersion]);
        check($migrationRecord->fetchColumn() !== false,
            'Updater bounded migration runner did not record the repaired widget migration.');
        foreach ($retrySnapshots as $retryWidgetId => $retryRowBefore) {
            check(public_widget_model_find($retryWidgetId) === $retryRowBefore,
                'Migration runner replay changed a widget row or any persisted placement/publication field.');
        }
        $afterRecoveryPublicIds = array_column(public_widget_public_rows('home'), 'widget_id');
        check(in_array($retryWidgetIds[1], $afterRecoveryPublicIds, true)
            && !in_array($retryWidgetIds[0], $afterRecoveryPublicIds, true)
            && !in_array($retryWidgetIds[2], $afterRecoveryPublicIds, true),
            'Migration recovery changed which mixed-status widget is eligible for public Home output.');

        $stage = 'database-native widget row backup and restore';
        check(!$pdo->inTransaction(), 'Widget row backup and restore requires a connection without an open transaction.');
        $widgetBackupTableName = 'public_content_widgets_backup_' . substr($token, 0, 12);
        check(preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $widgetBackupTableName) === 1,
            'Generated widget backup table name is invalid.');
        $widgetBackupTableSql = '`' . $widgetBackupTableName . '`';
        // This session-scoped InnoDB table is a database-native row snapshot, not a provider/off-site backup.
        $pdo->exec('CREATE TEMPORARY TABLE ' . $widgetBackupTableSql . ' LIKE public_content_widgets');
        $widgetBackupTableCreated = true;
        $ownedIdPlaceholders = implode(', ', array_fill(0, count($retryWidgetIds), '?'));
        $backupRows = $pdo->prepare('INSERT INTO ' . $widgetBackupTableSql
            . ' SELECT * FROM public_content_widgets WHERE widget_id IN (' . $ownedIdPlaceholders . ')');
        $backupRows->execute($retryWidgetIds);
        check($backupRows->rowCount() === count($retryWidgetIds),
            'Database-native widget backup did not copy every owned row.');
        $backupRowIds = $pdo->query('SELECT widget_id FROM ' . $widgetBackupTableSql . ' ORDER BY widget_id')
            ->fetchAll(\PDO::FETCH_COLUMN);
        $expectedBackupRowIds = $retryWidgetIds;
        sort($expectedBackupRowIds, SORT_STRING);
        check($backupRowIds === $expectedBackupRowIds,
            'Database-native widget backup contains an unexpected row set.');
        $findBackupRow = $pdo->prepare('SELECT * FROM ' . $widgetBackupTableSql . ' WHERE widget_id = ? LIMIT 1');
        foreach ($retrySnapshots as $retryWidgetId => $retryRowBefore) {
            $findBackupRow->execute([$retryWidgetId]);
            check($findBackupRow->fetch(\PDO::FETCH_ASSOC) === $retryRowBefore,
                'Database-native widget backup changed a persisted field.');
        }

        $publicIdsBeforeWidgetBackup = [];
        foreach (['home', 'gallery'] as $pageType) {
            $publicIdsBeforeWidgetBackup[$pageType] = array_column(public_widget_public_rows($pageType), 'widget_id');
            sort($publicIdsBeforeWidgetBackup[$pageType], SORT_STRING);
        }
        $deleteOwnedWidget = $pdo->prepare('DELETE FROM public_content_widgets WHERE widget_id = ?');
        foreach ($retryWidgetIds as $retryWidgetId) {
            $deleteOwnedWidget->execute([$retryWidgetId]);
            check($deleteOwnedWidget->rowCount() === 1,
                'Widget row backup fixture could not remove exactly one owned source row.');
        }
        check((int) $pdo->query('SELECT COUNT(*) FROM public_content_widgets')->fetchColumn() === $initialCount,
            'Removing the backed-up widget rows changed the pre-existing fixture baseline.');

        $restoreOwnedWidget = $pdo->prepare('INSERT INTO public_content_widgets SELECT * FROM '
            . $widgetBackupTableSql . ' WHERE widget_id = ?');
        foreach ($retryWidgetIds as $retryWidgetId) {
            $restoreOwnedWidget->execute([$retryWidgetId]);
            check($restoreOwnedWidget->rowCount() === 1,
                'Database-native widget restore did not insert exactly one owned row.');
        }
        foreach ($retrySnapshots as $retryWidgetId => $retryRowBefore) {
            check(public_widget_model_find($retryWidgetId) === $retryRowBefore,
                'Database-native widget restore changed a persisted field.');
        }
        check((int) $pdo->query('SELECT COUNT(*) FROM public_content_widgets')->fetchColumn()
                === $initialCount + count($retryWidgetIds),
            'Database-native widget restore did not return the table to its pre-snapshot row count.');
        foreach (['home', 'gallery'] as $pageType) {
            $publicIdsAfterWidgetRestore = array_column(public_widget_public_rows($pageType), 'widget_id');
            sort($publicIdsAfterWidgetRestore, SORT_STRING);
            check($publicIdsAfterWidgetRestore === $publicIdsBeforeWidgetBackup[$pageType],
                'Database-native widget restore changed public ' . $pageType . ' eligibility.');
        }
        $pdo->exec('DROP TEMPORARY TABLE ' . $widgetBackupTableSql);
        $widgetBackupTableCreated = false;

        foreach ($retrySnapshots as $retryWidgetId => $retryRowBefore) {
            public_widget_delete($retryWidgetId, (int) $retryRowBefore['revision']);
            $createdIds = array_values(array_diff($createdIds, [$retryWidgetId]));
        }
        check((int) $pdo->query('SELECT COUNT(*) FROM public_content_widgets')->fetchColumn() === $initialCount,
            'Migration recovery fixture did not restore the original widget row count.');

        $stage = 'create and public scope';
        $content = "Curated **links**:\n\n- [Custom visible label](https://example.org/galleries?q=1&n=2)";
        $original = [
            'title' => 'Partner galleries', 'content_md' => $content,
            'page_scope' => 'all', 'placement_mode' => 'flow',
            'flow_slot' => 'left_rail', 'status' => 'published',
            'source_language' => 'cs', 'sort_order' => 30,
        ];
        $id = public_widget_create($original);
        $createdIds[] = $id;
        check(preg_match('/^[a-f0-9]{32}$/D', $id) === 1, 'Created widget ID is not stable hex.');
        $saved = public_widget_model_find($id);
        check(is_array($saved) && (int) $saved['revision'] === 1
            && $saved['status'] === 'published'
            && $saved['source_language'] === 'cs'
            && $saved['content_md'] === $content, 'Initial widget row did not persist.');

        $stage = 'public read fallback for unsupported stored source language';
        $setUnsupportedLanguage = $pdo->prepare(
            'UPDATE public_content_widgets SET source_language = ? WHERE widget_id = ?',
        );
        $setUnsupportedLanguage->execute(['xx', $id]);
        check($setUnsupportedLanguage->rowCount() === 1,
            'Could not create the unsupported stored-language fixture.');
        $publicLanguageRows = array_values(array_filter(public_widget_public_rows('home'),
            static fn (array $row): bool => $row['widget_id'] === $id));
        $publicLanguagePlan = public_widget_public_plan('home');
        $plannedLanguageRows = array_values(array_filter($publicLanguagePlan['left_rail'],
            static fn (array $row): bool => $row['widget_id'] === $id));
        check(count($publicLanguageRows) === 1
            && $publicLanguageRows[0]['source_language'] === 'en'
            && count($plannedLanguageRows) === 1
            && $plannedLanguageRows[0]['source_language'] === 'en'
            && str_contains($plannedLanguageRows[0]['body_html'], 'Custom visible label'),
            'Unsupported stored language metadata hid or relabelled otherwise valid public widget content.');
        $storedUnsupportedLanguage = public_widget_model_find($id);
        check(is_array($storedUnsupportedLanguage) && $storedUnsupportedLanguage['source_language'] === 'xx',
            'The public read fallback unexpectedly rewrote persisted Admin data.');
        $restoreSupportedLanguage = $pdo->prepare(
            'UPDATE public_content_widgets SET source_language = ? WHERE widget_id = ?',
        );
        $restoreSupportedLanguage->execute(['cs', $id]);
        check($restoreSupportedLanguage->rowCount() === 1,
            'Could not restore the supported authored language after fallback acceptance.');

        $home = public_widget_public_plan('home');
        $gallery = public_widget_public_plan('gallery');
        check(count(array_filter($home['left_rail'], static fn (array $row): bool => $row['widget_id'] === $id)) === 1,
            'Published all-scope widget not prepared for homepage.');
        check(count(array_filter($gallery['left_rail'], static fn (array $row): bool => $row['widget_id'] === $id)) === 1,
            'Published all-scope widget not prepared for gallery detail.');
        check(public_widget_public_rows('admin') === [], 'Forbidden Admin page must not leak public widget rows.');

        $stage = 'independent connection and optimistic editing';
        $GLOBALS['public_widget_workflow_db'] = $peer;
        $peerRow = public_widget_model_find($id);
        check(is_array($peerRow) && (int) $peerRow['revision'] === 1, 'Independent connection cannot read committed widget.');
        $nextRevision = public_widget_save($id, 1, [...$original,
            'title' => 'Updated by administrator B',
            'placement_mode' => 'floating', 'floating_anchor' => 'top-left',
            'x_permille' => 235, 'y_permille' => 410, 'width_px' => 360,
        ]);
        check($nextRevision === 2, 'Second connection did not commit an incremented revision.');

        $GLOBALS['public_widget_workflow_db'] = $pdo;
        $staleEditDenied = false;
        try {
            public_widget_save($id, 1, [...$original,
                'title' => 'Stale administrator A',
                'placement_mode' => 'flow', 'flow_slot' => 'content_bottom', 'width_px' => 220,
            ]);
        } catch (PublicWidgetInvalidField $exception) {
            $staleEditDenied = $exception->field === 'revision';
        }
        check($staleEditDenied, 'Stale writer unexpectedly replaced another administrator revision.');
        $afterConflict = public_widget_model_find($id);
        check(is_array($afterConflict) && $afterConflict['title'] === 'Updated by administrator B'
            && $afterConflict['placement_mode'] === 'floating' && $afterConflict['floating_anchor'] === 'top-left'
            && $afterConflict['flow_slot'] === 'left_rail'
            && (int) $afterConflict['x_permille'] === 235 && (int) $afterConflict['y_permille'] === 410
            && (int) $afterConflict['width_px'] === 360 && (int) $afterConflict['revision'] === 2,
            'Stale cross-field placement/width edit changed the committed widget row.');

        $stage = 'rejected unsafe Markdown without persistence';
        $badLinkDenied = false;
        try {
            public_widget_save($id, 2, [...$original, 'content_md' => '[bad](javascript:alert(1))']);
        } catch (PublicWidgetInvalidField $exception) {
            $badLinkDenied = $exception->field === 'content_md';
        }
        check($badLinkDenied, 'Unsafe link publication was not refused.');
        check((int) public_widget_model_find($id)['revision'] === 2, 'Unsafe rejected edit changed revision.');

        $stage = 'unpublished duplication and ordering';
        $copy = public_widget_duplicate($id);
        $createdIds[] = $copy;
        $copied = public_widget_model_find($copy);
        check(is_array($copied) && $copy !== $id && $copied['status'] === 'draft'
            && $copied['title'] === 'Updated by administrator B'
            && $copied['content_md'] === $content
            && (int) $copied['revision'] === 1, 'Duplicate must be independent and unpublished.');
        check(count(array_filter(public_widget_public_rows('home'),
            static fn (array $row): bool => $row['widget_id'] === $copy)) === 0,
            'New duplicate leaked to public content before publishing.');

        public_widget_reorder([
            ['widget_id' => $id, 'revision' => 2, 'sort_order' => 100],
            ['widget_id' => $copy, 'revision' => 1, 'sort_order' => 200],
        ]);
        check((int) public_widget_model_find($id)['revision'] === 3
            && (int) public_widget_model_find($copy)['revision'] === 2,
            'Atomic reorder did not advance both revisions.');
        $staleOrderDenied = false;
        try {
            public_widget_reorder([
                ['widget_id' => $id, 'revision' => 3, 'sort_order' => 400],
                ['widget_id' => $copy, 'revision' => 1, 'sort_order' => 500],
            ]);
        } catch (PublicWidgetInvalidField $exception) {
            $staleOrderDenied = $exception->field === 'revision';
        }
        check($staleOrderDenied, 'Transactional reorder accepted one stale item.');
        check((int) public_widget_model_find($id)['sort_order'] === 100
            && (int) public_widget_model_find($copy)['sort_order'] === 200
            && (int) public_widget_model_find($id)['revision'] === 3,
            'Failed multi-row reorder left partial writes or revision bumps.');

        $stage = 'disable, re-enable, stale delete and committed cleanup';
        $beforeDisable = public_widget_model_find($id);
        check(is_array($beforeDisable) && (int) $beforeDisable['revision'] === 3,
            'Published widget is missing before the visibility lifecycle.');
        public_widget_save($id, 3, [...$beforeDisable, 'status' => 'disabled']);
        check(count(array_filter(public_widget_public_rows('home'),
            static fn (array $row): bool => $row['widget_id'] === $id)) === 0,
            'Disabled item remains publicly visible.');
        $afterDisable = public_widget_model_find($id);
        check(is_array($afterDisable) && (int) $afterDisable['revision'] === 4
            && $afterDisable['status'] === 'disabled',
            'Disabled widget did not persist its state and revision.');
        public_widget_save($id, 4, [...$beforeDisable, 'status' => 'published']);
        $restored = public_widget_model_find($id);
        check(is_array($restored) && (int) $restored['revision'] === 5
            && $restored['status'] === 'published'
            && $restored['content_md'] === $beforeDisable['content_md']
            && $restored['title'] === $beforeDisable['title']
            && $restored['flow_slot'] === $beforeDisable['flow_slot']
            && (int) $restored['sort_order'] === (int) $beforeDisable['sort_order'],
            'Re-enabling changed saved content, placement or ordering.');
        check(count(array_filter(public_widget_public_rows('home'),
            static fn (array $row): bool => $row['widget_id'] === $id)) === 1,
            'Re-enabled published item is not visible exactly once.');
        public_widget_save($id, 5, [...$beforeDisable, 'status' => 'disabled']);
        $staleDeleteDenied = false;
        try {
            public_widget_delete($id, 5);
        } catch (PublicWidgetInvalidField $exception) {
            $staleDeleteDenied = $exception->field === 'revision';
        }
        check($staleDeleteDenied, 'Stale delete unexpectedly removed the newer revision.');
        public_widget_delete($id, 6);
        $createdIds = array_values(array_diff($createdIds, [$id]));
        public_widget_delete($copy, 2);
        $createdIds = array_values(array_diff($createdIds, [$copy]));
        check(public_widget_model_find($id) === null && public_widget_model_find($copy) === null,
            'Deleted widget still exists.');
        check((int) $pdo->query('SELECT COUNT(*) FROM public_content_widgets')->fetchColumn() === $initialCount,
            'DB widget workflow failed to restore the original table row count.');
        echo "PASS public content widget DB migration recovery, row backup and restore, CRUD, scope, visibility, cross-field stale revision, duplication and atomic reorder\n";
    } catch (\Throwable $exception) {
        throw new \RuntimeException('FAIL public content widget DB workflow at ' . $stage . ': '
            . get_class($exception) . ' ' . $exception->getMessage(), 0, $exception);
    } finally {
        if (isset($pdo) && $pdo instanceof \PDO) {
            try {
                if ($retryFailureConstraintInstalled) {
                    $pdo->exec('ALTER TABLE schema_migrations DROP CONSTRAINT ' . $retryFailureConstraintName);
                    $retryFailureConstraintInstalled = false;
                }
                $delete = $pdo->prepare('DELETE FROM public_content_widgets WHERE widget_id = ?');
                foreach ($createdIds as $createdId) {
                    $delete->execute([$createdId]);
                }
                if ($widgetBackupTableCreated && $widgetBackupTableSql !== '') {
                    $pdo->exec('DROP TEMPORARY TABLE IF EXISTS ' . $widgetBackupTableSql);
                    $widgetBackupTableCreated = false;
                }
            } finally {
                if (is_string($widgetMigrationAppliedAt) && $widgetMigrationAppliedAt !== '') {
                    $restoreMigrationRecord = $pdo->prepare(
                        'INSERT INTO schema_migrations (version, applied_at) VALUES (?, ?) '
                        . 'ON DUPLICATE KEY UPDATE applied_at = VALUES(applied_at)'
                    );
                    $restoreMigrationRecord->execute([$widgetMigrationVersion, $widgetMigrationAppliedAt]);
                }
            }
        }
        unset($GLOBALS['public_widget_workflow_db']);
    }
}
