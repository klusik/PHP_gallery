<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/admin_log_export_selection_test.php
 * Module Type: Regression Test
 * Purpose: Exercise scoped diagnostic text exports against a disposable SQLite log table.
 * Responsibilities:
 *   - Verify whole-group expansion, selection deduplication and invalid identity rejection.
 *   - Verify filtered keyset exports across batch boundaries and timestamp ties.
 *   - Preserve escaped native download and selection markup.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Core {
    /**
     * Return the disposable log-export database owned by this test process.
     * @return \PDO In-memory SQLite fixture, never an installation database.
     */
    function db(): \PDO
    {
        return $GLOBALS['log_export_fixture'];
    }

    /**
     * Escape fixture view values with the production HTML escaping semantics.
     * @param string $value Untrusted presentation text.
     * @return string HTML-safe UTF-8 text.
     */
    function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

namespace {
    /** Adapt the model's MySQL column inventory to the disposable SQLite schema. */
    final class AdminLogExportFixturePdo extends \PDO
    {
        /**
         * Initialize a disposable relational fixture with associative row results.
         * @return void Creates only an in-memory database.
         */
        public function __construct()
        {
            parent::__construct('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]);
        }

        /**
         * Translate MySQL schema observation while executing all export reads unchanged.
         * @param string $query Model-owned SQL query.
         * @param int|null $fetchMode Optional PDO fetch mode.
         * @param int|string $fetchModeArgs Additional fetch-mode arguments forwarded unchanged.
         * @return \PDOStatement|false Executed SQLite statement, or the PDO failure result.
         */
        public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): \PDOStatement|false
        {
            if ($query === 'SHOW COLUMNS FROM admin_logs') {
                $query = "SELECT name AS Field FROM pragma_table_info('admin_logs')";
            }
            return $fetchMode === null ? parent::query($query) : parent::query($query, $fetchMode, ...$fetchModeArgs);
        }
    }

    /**
     * Fail when a scoped export violates its observable data contract.
     * @param bool $condition Expected invariant.
     * @param string $message Diagnostic shown on failure.
     * @return void Returns only when the invariant holds.
     */
    function assert_log_export(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new \RuntimeException($message);
        }
    }

    /**
     * Read emitted diagnostic IDs without depending on translated report labels.
     * @param string $text Complete diagnostic export.
     * @return array<int,int> Ordered emitted log IDs.
     */
    function log_export_ids(string $text): array
    {
        preg_match_all('/^ID: ([0-9]+)$/m', $text, $matches);
        return array_map('intval', $matches[1]);
    }

    require_once __DIR__ . '/../app/models/admin_logs.php';
    require_once __DIR__ . '/../app/services/logs.php';
    require_once __DIR__ . '/../app/views/admin_logs.php';

    $database = new AdminLogExportFixturePdo();
    $GLOBALS['log_export_fixture'] = $database;
    $database->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT)');
    $database->exec("INSERT INTO users VALUES (1, 'operator')");
    $database->exec('CREATE TABLE admin_logs (id INTEGER PRIMARY KEY, user_id INTEGER, created_at TEXT, status TEXT, event_key TEXT, level TEXT, severity TEXT, category TEXT, message TEXT, context_json TEXT, request_id TEXT, route_name TEXT)');
    $insert = $database->prepare('INSERT INTO admin_logs VALUES (?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    for ($id = 1; $id <= 520; $id++) {
        $firstGroup = $id <= 260;
        $insert->execute([$id, '2026-10-15 00:00:00', 'todo', $firstGroup ? 'fixture.alpha' : 'fixture.beta',
            $firstGroup ? 'info' : 'warning', $firstGroup ? 'info' : 'warning', $firstGroup ? 'system' : 'upload',
            'Message ' . $id, '{"unicode":"Příliš žluťoučký","source_id":' . $id . '}', 'request-' . $id, 'admin_logs']);
    }
    $entry = \Gallery\Services\admin_log_find(260);
    $hash = \Gallery\Services\admin_log_group_hash_for_entry($entry);
    $selection = \Gallery\Services\admin_log_export_selection(['260:' . $hash, '1', '260:' . $hash, '259:' . $hash, '520', '520']);
    $selectedText = implode('', iterator_to_array(\Gallery\Services\admin_log_selection_text_chunks($selection)));
    assert_log_export(log_export_ids($selectedText) === array_merge(range(260, 1), [520]), 'Entire groups must cross batch boundaries exactly once and suppress overlapping individual selections.');
    assert_log_export(str_contains($selectedText, 'Příliš žluťoučký') && str_contains($selectedText, 'request-1'), 'Exports must retain complete Unicode context and request metadata.');
    $individuals = \Gallery\Services\admin_log_export_selection(['520', '1', '520']);
    assert_log_export(log_export_ids(implode('', iterator_to_array(\Gallery\Services\admin_log_selection_text_chunks($individuals)))) === [520, 1], 'Individual selection order and deduplication must be stable.');
    foreach (['0', 'abc', '9999', '260:' . str_repeat('0', 64), '260:' . $hash . ':extra'] as $invalid) {
        $rejected = false;
        try {
            \Gallery\Services\admin_log_export_selection([$invalid]);
        } catch (\RuntimeException) {
            $rejected = true;
        }
        assert_log_export($rejected, 'Missing, malformed and mismatched group identities must be rejected before streaming.');
    }
    $filters = ['category' => 'system', 'severities' => ['info'], 'q' => 'Příliš', 'time_sort' => 'asc'];
    $filteredText = implode('', iterator_to_array(\Gallery\Services\admin_log_filtered_text_chunks($filters)));
    assert_log_export(log_export_ids($filteredText) === range(1, 260), 'Combined filters must export all matching pages with stable timestamp-tie ordering.');
    $filters['time_sort'] = 'desc';
    assert_log_export(log_export_ids(implode('', iterator_to_array(\Gallery\Services\admin_log_filtered_text_chunks($filters)))) === range(260, 1), 'Descending filtered export must retain all timestamp-tied rows across batches.');
    $filters['severities'] = ['warning'];
    assert_log_export(implode('', iterator_to_array(\Gallery\Services\admin_log_filtered_text_chunks($filters))) === '', 'Conflicting filters must not export unrelated records.');

    $labels = ['copy' => 'Copy log', 'save' => 'Save log', 'copy_group' => 'Copy entire group', 'save_group' => 'Save entire group', 'select_log' => 'Select log'];
    $row = array_merge($entry, ['group_count' => 260, 'selection_value' => 'group:' . $hash, 'export_token' => '260:' . $hash, 'export_url' => '?page=admin_log_export&id=260&group=' . $hash, 'single_export_url' => '?page=admin_log_export&id=260', 'message' => '<script>alert(1)</script>']);
    $html = \Gallery\Views\view_render_admin_log_table_rows([$row], $labels);
    assert_log_export(str_contains($html, '&lt;script&gt;') && !str_contains($html, '<script>'), 'Diagnostic messages must stay escaped in compact rows.');
    assert_log_export(str_contains($html, 'aria-label="Copy entire group"') && str_contains($html, 'form="admin-log-bulk-form"'), 'Compact controls must preserve accessible scope and native form ownership.');
    assert_log_export(str_contains($html, 'download target="_blank" rel="noopener"'), 'Row exports must retain an authenticated native HTTP download path.');
    $contextRow = array_merge($row, ['event_key'=>'migrations.ran','message'=>'Admin ran migrations.',
        'context_pretty_json'=>'{"migration":"cache_regions","unsafe":"<img src=x>"}']);
    $contextHtml = \Gallery\Views\view_render_admin_log_table_rows([$contextRow], $labels, 'cache');
    assert_log_export(str_contains($contextHtml, 'data-admin-log-context-match') && str_contains($contextHtml, 'Match in details')
        && str_contains($contextHtml, '&lt;img src=x&gt;') && !str_contains($contextHtml, '<img'), 'A context-only search match must be explained while its JSON remains escaped.');
    assert_log_export(!str_contains(\Gallery\Views\view_render_admin_log_table_rows([$contextRow], $labels, 'migrations'), 'data-admin-log-context-match'),
        'A visible event-name match needs no hidden-context explanation.');
    fwrite(STDOUT, "Admin log scoped export tests passed.\n");
}
