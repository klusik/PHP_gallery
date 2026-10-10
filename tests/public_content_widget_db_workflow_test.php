<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/public_content_widget_db_workflow_test.php
 * Module Type: Database Integration Test
 * Purpose: Prove widget CRUD, publication, revisions and atomic ordering on real isolated MySQL/MariaDB.
 * Responsibilities:
 *   - Reuse only the existing explicitly owned disposable workflow database.
 *   - Exercise the actual production widget service/model with two independent PDO connections.
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
    require_once dirname(__DIR__) . '/app/services/public_content_widgets.php';

    use Gallery\Services\PublicWidgetInvalidField;
    use function GalleryWorkflow\check;
    use function GalleryWorkflow\fixtureDatabase;
    use function GalleryWorkflow\validateFixture;
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
        $nextRevision = public_widget_save($id, 1, [...$original, 'title' => 'Updated by administrator B']);
        check($nextRevision === 2, 'Second connection did not commit an incremented revision.');

        $GLOBALS['public_widget_workflow_db'] = $pdo;
        $staleEditDenied = false;
        try {
            public_widget_save($id, 1, [...$original, 'title' => 'Stale administrator A']);
        } catch (PublicWidgetInvalidField $exception) {
            $staleEditDenied = $exception->field === 'revision';
        }
        check($staleEditDenied, 'Stale writer unexpectedly replaced another administrator revision.');
        $afterConflict = public_widget_model_find($id);
        check(is_array($afterConflict) && $afterConflict['title'] === 'Updated by administrator B'
            && (int) $afterConflict['revision'] === 2, 'Stale edit changed stored widget content.');

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

        $stage = 'disable, stale delete and committed cleanup';
        public_widget_save($id, 3, [...$original, 'status' => 'disabled', 'sort_order' => 100]);
        check(count(array_filter(public_widget_public_rows('home'),
            static fn (array $row): bool => $row['widget_id'] === $id)) === 0,
            'Disabled item remains publicly visible.');
        $staleDeleteDenied = false;
        try {
            public_widget_delete($id, 3);
        } catch (PublicWidgetInvalidField $exception) {
            $staleDeleteDenied = $exception->field === 'revision';
        }
        check($staleDeleteDenied, 'Stale delete unexpectedly removed the newer revision.');
        public_widget_delete($id, 4);
        $createdIds = array_values(array_diff($createdIds, [$id]));
        public_widget_delete($copy, 2);
        $createdIds = array_values(array_diff($createdIds, [$copy]));
        check(public_widget_model_find($id) === null && public_widget_model_find($copy) === null,
            'Deleted widget still exists.');
        check((int) $pdo->query('SELECT COUNT(*) FROM public_content_widgets')->fetchColumn() === $initialCount,
            'DB widget workflow failed to restore the original table row count.');
        echo "PASS public content widget real DB CRUD, scope, optimistic revision, duplication and atomic reorder\n";
    } catch (\Throwable $exception) {
        throw new \RuntimeException('FAIL public content widget DB workflow at ' . $stage . ': '
            . get_class($exception) . ' ' . $exception->getMessage(), 0, $exception);
    } finally {
        if (isset($pdo) && $pdo instanceof \PDO) {
            $delete = $pdo->prepare('DELETE FROM public_content_widgets WHERE widget_id = ?');
            foreach ($createdIds as $createdId) {
                $delete->execute([$createdId]);
            }
        }
        unset($GLOBALS['public_widget_workflow_db']);
    }
}
