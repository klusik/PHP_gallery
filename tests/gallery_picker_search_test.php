<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/gallery_picker_search_test.php
 * Module Type: Regression Test
 * Purpose: Measure bounded destination lookup using disposable SQLite.
 * Responsibilities:
 *   - Exercise real search and renderer behavior without loading installation configuration.
 * Disposable SQLite runtime and source-renderer measurement for destination search.
 * Author: Rudolf Klusal
 * No application bootstrap, config, live SQL connection or persistent fixture data.
 */
declare(strict_types=1);

namespace Gallery\Core {
    /**
     * Resolve the disposable in-memory database for actual model calls.
     * @return \PDO Isolated SQLite fixture.
     */
    function db(): \PDO { return $GLOBALS['picker_fixture']; }
    /**
     * Escape the same text/attribute characters as the production renderer.
     * @param string $value Untrusted fixture text.
     * @return string HTML-safe text.
     */
    function e(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
    /**
     * Supply an explicit controller principal without application sessions.
     * @return ?array<string,mixed> Fixture administrator/viewer or anonymous state.
     */
    function current_user(): ?array { return $GLOBALS['picker_user'] ?? null; }
    /**
     * Make an installation-relative URL without reading application configuration.
     * @param string $page Endpoint name.
     * @param array<string,mixed> $parameters Query parameters.
     * @return string Fixture URL.
     */
    function url_for(string $page, array $parameters = []): string {
        return '/index.php?' . http_build_query(['page' => $page] + $parameters);
    }
}
namespace Gallery\Services {
    /**
     * Use supplied English translations without touching a catalog/configuration.
     * @param string $key Translation identifier.
     * @param ?string $fallback Default display label.
     * @param array<string,mixed> $replace Unused fixture substitutions.
     * @return string Default display label.
     */
    function t(string $key, ?string $fallback = null, array $replace = []): string { return $fallback ?? $key; }
}
namespace Gallery\Controllers {
    /**
     * Capture response headers without running an HTTP server.
     * @param string $header Header line.
     * @return void
     */
    function header(string $header): void { $GLOBALS['picker_headers'][] = $header; }
    /**
     * Reset only the fixture's captured header list.
     * @return void
     */
    function clear_response_cache_headers(): void { $GLOBALS['picker_headers'] = []; }
}
namespace Gallery\Models {
    /**
     * Keep optional bulk-toolbar child hints independent of unrelated model fields.
     * @param int $galleryId Fixture source gallery.
     * @return int No optional default hint in this fixture.
     */
    function gallery_model_likely_destination_id(int $galleryId): int { return 0; }
}
namespace Gallery\Tests {
    /**
     * Real SQLite engine with a read-query inventory for bounded model assertions.
     * Author: Rudolf Klusal
     */
    class PickerDatabase extends \PDO {
        /** Record prepared model reads for bounded-query assertions. @var array<int,string> Actual SQL prepared by the application model. */
        public array $queries = [];
        /**
         * Create a fresh private in-memory catalog.
         * @return void
         */
        public function __construct() {
            parent::__construct('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
            $this->exec('CREATE TABLE galleries (id INTEGER PRIMARY KEY, title TEXT, folder_path TEXT)');
        }
        /**
         * Capture actual model SQL, then let SQLite prepare/execute normally.
         * @param string $query SQL owned by the model or disposable seed.
         * @param array<int,mixed> $options Driver-specific PDO option IDs and values; ordinary fixture calls use an empty map.
         * @return \PDOStatement|false Real prepared statement.
         */
        public function prepare(string $query, array $options = []): \PDOStatement|false {
            if (str_starts_with($query, 'SELECT')) $this->queries[] = $query;
            return parent::prepare($query, $options);
        }
    }
}
namespace {
    require_once dirname(__DIR__) . '/app/models/gallery_picker_search.php';
    require_once dirname(__DIR__) . '/app/services/gallery_picker.php';
    require_once dirname(__DIR__) . '/app/views/admin_gallery_renderers.php';
    require_once dirname(__DIR__) . '/app/controllers/admin_gallery_renderers.php';
    require_once dirname(__DIR__) . '/app/controllers/admin_gallery_picker_search.php';
    require_once dirname(__DIR__) . '/app/views/admin_gallery_edit_components.php';
    require_once dirname(__DIR__) . '/app/controllers/admin_galleries_edit_views.php';
    require_once __DIR__ . '/support/gallery_picker_legacy_view.php';

    /**
     * Fail a fixture contract without relying on PHP's optional assert setting.
     * @param bool $condition Observed contract result.
     * @param string $message Failure explanation.
     * @return void
     */
    function picker_assert(bool $condition, string $message): void {
        if (!$condition) throw new RuntimeException($message);
    }
    /**
     * Seed deterministic duplicates, deep paths and literal LIKE metacharacters.
     * @param int $size Number of galleries.
     * @return void
     */
    function picker_seed(int $size): void {
        $database = new Gallery\Tests\PickerDatabase();
        $GLOBALS['picker_fixture'] = $database;
        $statement = $database->prepare('INSERT INTO galleries VALUES (?, ?, ?)');
        $database->beginTransaction();
        for ($id = 1; $id <= $size; ++$id) {
            $title = $id % 5 === 0 ? 'Repeated title' : 'Gallery ' . $id;
            $path = 'branch-' . ($id % 9) . '/deep/level/item-' . $id;
            if ($id === 1) $path = 'literal_%!';
            if ($id === 2) $path = 'literal_%!/child';
            if ($id === 3) $path = 'literal_other/child';
            if ($id === 4) $title = '<script>alert("fixture")</script>';
            if ($id === 6) $title = '100%_literal!';
            $statement->execute([$id, $title, $path]);
        }
        $database->commit();
        $database->queries = [];
    }
    /**
     * Capture actual HTTP boundary status, headers and body.
     * @param array<string,mixed> $parameters Parsed request query.
     * @param string $method Request method.
     * @return array{status:int,body:string,headers:array} Captured response.
     */
    function picker_request(array $parameters, string $method = 'GET'): array {
        $_GET = $parameters;
        $_SERVER['REQUEST_METHOD'] = $method;
        ob_start();
        Gallery\Controllers\cms_admin_gallery_picker_search();
        return ['status' => http_response_code(), 'body' => (string) ob_get_clean(),
            'headers' => $GLOBALS['picker_headers']];
    }
    /**
     * Render the frozen former source against the same fixture and old row shape.
     * @return string Whole-catalog former picker HTML.
     */
    function picker_legacy_html(): string {
        $rows = [];
        foreach (Gallery\Core\db()->query('SELECT id, title, folder_path FROM galleries ORDER BY folder_path')->fetchAll(PDO::FETCH_ASSOC) as $gallery) {
            $path = trim($gallery['folder_path'], '/');
            $title = $gallery['title'];
            $rows[] = ['id' => (int) $gallery['id'], 'title' => $title, 'path' => $path,
                'path_label' => '/' . $path, 'depth' => substr_count($path, '/'),
                'label' => $title . ' /' . $path,
                'search' => trim($title . ' ' . $path . ' ' . str_replace(['/', '-', '_'], ' ', $path))];
        }
        return Gallery\Tests\PickerBaseline\view_render_gallery_search_picker(['rows' => $rows, 'field_name' => 'parent_id']);
    }
    /**
     * Measure actual rendered bytes/elements, elapsed execution and peak allocation.
     * @param callable():string $render Renderer using the active disposable fixture.
     * @return array<string,int|float|null> One diagnostic sample, not a latency SLA.
     */
    function picker_measure(callable $render): array {
        if (function_exists('memory_reset_peak_usage')) memory_reset_peak_usage();
        $before = memory_get_usage();
        $started = hrtime(true);
        $html = $render();
        $elapsed = (hrtime(true) - $started) / 1000000;
        $peak = function_exists('memory_reset_peak_usage') ? max(0, memory_get_peak_usage() - $before) : null;
        $document = new DOMDocument();
        @$document->loadHTML($html);
        return ['html_bytes' => strlen($html), 'element_nodes' => $document->getElementsByTagName('*')->length,
            'options' => substr_count($html, 'data-gallery-search-picker-option '),
            'elapsed_ms' => round($elapsed, 3), 'peak_extra_bytes' => $peak];
    }

    if (!in_array('sqlite', PDO::getAvailableDrivers(), true) || !class_exists(DOMDocument::class)) {
        fwrite(STDOUT, "SKIP: bounded picker fixture requires pdo_sqlite and DOM.\n");
        exit(0);
    }
    if (($argv[1] ?? '') === '--render-browser-fixture') {
        picker_seed(10000);
        echo '<!doctype html><html><head><meta charset="utf-8"><title>Picker browser fixture</title></head><body>';
        echo '<div id="panel"><form id="parent-form"><input type="hidden" name="parent_id" value="666" disabled>';
        echo '<div data-gallery-title-completion data-gallery-title-completion-url="/title-search"><input id="title" value="Ne" data-gallery-title-completion-input>';
        echo '<span data-gallery-title-completion-overlay hidden><span data-gallery-title-completion-prefix></span><span data-gallery-title-completion-tail></span></span></div>';
        echo Gallery\Controllers\render_gallery_parent_picker(9000);
        echo '<button id="save" type="submit">Save fixture</button></form></div>';
        echo '<form id="bulk-form">';
        Gallery\Controllers\render_admin_image_bulk_toolbar(['id' => 10000]);
        echo '</form><pre id="results">BROWSER PENDING</pre><script type="module" src="/fixture.js"></script></body></html>';
        exit(0);
    }
    $measurements = [];
    foreach ([100, 1000, 10000] as $size) {
        picker_seed($size);
        $page = Gallery\Services\gallery_picker_search_page('', 0, $size);
        picker_assert(count($page['rows']) === 30 && $page['selected']['id'] === $size, 'Bounded page preserves an off-page committed selection.');
        picker_assert(count(Gallery\Core\db()->queries) === 2, 'One bounded search and one selected lookup.');
        picker_assert(str_contains($page['selected']['label'], '/deep/level/') && str_contains($page['selected']['label'], '(#' . $size . ')'), 'Selection carries full ancestry and identity.');
        $next = Gallery\Services\gallery_picker_search_page('', $page['next_after_id']);
        picker_assert(array_column($next['rows'], 'id') === range(31, 60), 'Stable keyset page without overlapping IDs.');
        foreach (Gallery\Core\db()->queries as $sql) {
            picker_assert(preg_match('/ LIMIT (1|30)$/', $sql) === 1 && !str_contains($sql, 'OFFSET'), 'Actual SQL limits every materialized page.');
        }
        $measurements[] = ['galleries' => $size,
            'before' => picker_measure('picker_legacy_html'),
            'after' => picker_measure(
                /** Render the bounded parent picker for this synthetic catalog. @return string Current production picker HTML. */
                static fn(): string => Gallery\Controllers\render_gallery_parent_picker($size))];
        $latest = $measurements[count($measurements) - 1];
        picker_assert($latest['before']['options'] === $size && $latest['after']['options'] === 31, 'DOM options are thirty results plus the root action.');
        picker_assert($latest['after']['html_bytes'] < Gallery\Core\GALLERY_PICKER_HTML_MAX_BYTES, 'Initial picker HTML remains below the 1 MiB ceiling.');
    }
    picker_assert(array_column(Gallery\Services\gallery_picker_search_page('100%_literal!')['rows'], 'id') === [6], 'LIKE metacharacters are literal.');
    picker_assert(Gallery\Services\gallery_picker_search_page("' OR 1=1 --")['rows'] === [], 'SQL-like input remains a bound literal.');
    $excluded = Gallery\Services\gallery_picker_search_page('', 0, 2, 1, true);
    picker_assert(!in_array(1, array_column($excluded['rows'], 'id'), true) && !in_array(2, array_column($excluded['rows'], 'id'), true), 'Parent picker excludes source and descendant.');
    picker_assert(in_array(3, array_column($excluded['rows'], 'id'), true) && $excluded['selected'] === null, 'Literal path escaping retains unrelated branches and refuses excluded selection.');
    picker_assert(in_array(2, array_column(Gallery\Services\gallery_picker_search_page('', 0, 0, 1, false)['rows'], 'id'), true), 'Photo destinations can include source children.');
    picker_assert(Gallery\Services\gallery_picker_search_page('', 0, 999999)['selected'] === null, 'Missing committed ID is not invented.');
    $hint = Gallery\Controllers\render_gallery_search_picker('destination_gallery_id', 0, 1, ['prefill_gallery_id' => 9999]);
    picker_assert(str_contains($hint, '(#9999)') && str_contains($hint, 'name="destination_gallery_id" value=""'), 'Off-page child hint remains visible without committing its ID.');
    $duplicate = Gallery\Services\gallery_picker_search_page('Repeated title')['rows'];
    picker_assert(count(array_unique(array_column($duplicate, 'label'))) === count($duplicate), 'Duplicate titles remain distinguishable.');

    $GLOBALS['picker_user'] = null;
    Gallery\Core\db()->queries = [];
    picker_assert(picker_request([])['status'] === 401, 'Anonymous requests refused.');
    $GLOBALS['picker_user'] = ['role' => 'viewer'];
    picker_assert(picker_request([])['status'] === 401, 'Viewers cannot enumerate admin destinations.');
    $GLOBALS['picker_user'] = ['role' => 'admin'];
    picker_assert(picker_request([], 'POST')['status'] === 405, 'Only GET discovery is accepted.');
    foreach ([['q' => []], ['q' => str_repeat('x', 256)], ['q' => "\xFF"], ['selected_id' => []],
        ['after_id' => '-1'], ['excluded_id' => '01'], ['after_id' => str_repeat('9', 19)],
        ['exclude_descendants' => '2'], ['allow_root' => '2'], ['format' => []]] as $invalid) {
        picker_assert(picker_request($invalid)['status'] === 400, 'Malformed request rejected.');
    }
    picker_assert(Gallery\Core\db()->queries === [], 'Unauthorized/malformed requests never access the catalog.');
    $response = picker_request(['selected_id' => '9999']);
    picker_assert($response['status'] === 200 && strlen($response['body']) < Gallery\Core\GALLERY_PICKER_JSON_MAX_BYTES, 'JSON bounded.');
    picker_assert(is_string(json_decode($response['body'], true)['rows'][0]['id']), 'JSON identifiers are decimal strings to preserve BIGINT identity.');
    picker_assert(in_array('Cache-Control: private, no-store, max-age=0', $response['headers'], true), 'Private no-store search boundary.');
    $directory = picker_request(['format' => 'html', 'q' => 'Repeated title', 'allow_root' => '1']);
    picker_assert($directory['status'] === 200 && str_contains($directory['body'], 'method="get"')
        && str_contains($directory['body'], 'after_id=') && str_contains($directory['body'], 'name="page"'), 'No-JavaScript directory supports search, keyset continuation and query-string routing.');
    $html = Gallery\Controllers\render_gallery_parent_picker(10000);
    picker_assert(str_contains($html, '<noscript>') && str_contains($html, 'type="number"')
        && str_contains($html, 'type="hidden" disabled') && str_contains($html, 'value="10000"'), 'Form fallback preserves commitment without a hidden tree.');
    picker_assert(!str_contains($html, '<script>alert('), 'Stored titles are escaped.');
    ob_start();
    Gallery\Controllers\render_admin_image_bulk_toolbar(['id' => 10000]);
    $bulkHtml = (string) ob_get_clean();
    picker_assert(str_contains($bulkHtml, 'name="new_gallery_parent_id" value="10000"'), 'Bulk new-gallery parent uses its bounded committed picker.');
    picker_assert(!str_contains($bulkHtml, '<select name="new_gallery_parent_id"')
        && substr_count($bulkHtml, 'data-gallery-search-picker-option ') <= 61, 'Bulk toolbar contains two bounded destination pages and no parent catalog select.');

    Gallery\Core\db()->queries = [];
    Gallery\Services\gallery_picker_search_page('absent-query');
    $searchSql = Gallery\Core\db()->queries[0];
    $explain = Gallery\Core\db()->prepare('EXPLAIN QUERY PLAN ' . $searchSql);
    $explain->execute([0, 0, '%absent-query%', '%absent-query%']);
    $queryPlan = array_column($explain->fetchAll(PDO::FETCH_ASSOC), 'detail');

    // Worst schema-length escaped ASCII still obeys the explicit HTML ceiling.
    Gallery\Core\db()->exec('DELETE FROM galleries');
    $insert = Gallery\Core\db()->prepare('INSERT INTO galleries VALUES (?, ?, ?)');
    for ($id = 1; $id <= 31; ++$id) $insert->execute([$id, str_repeat('"', 255), str_repeat('"', 1024)]);
    $largest = Gallery\Controllers\render_gallery_parent_picker(31);
    picker_assert(strlen($largest) < Gallery\Core\GALLERY_PICKER_HTML_MAX_BYTES, 'Worst escaped schema-sized labels obey 1 MiB HTML budget.');
    picker_assert(picker_request(['selected_id' => '31'])['status'] === 200, 'Worst schema-sized JSON fits.');

    Gallery\Core\db()->exec('DROP TABLE galleries');
    $failure = picker_request([]);
    picker_assert($failure['status'] === 503 && !str_contains($failure['body'], 'SQLSTATE')
        && json_decode($failure['body'], true)['rows'] === [], 'Database failure emits generic empty discovery.');
    picker_assert(str_contains(Gallery\Controllers\render_gallery_parent_picker(31), 'value="31"'), 'Optional search failure must not clear an existing parent ID.');
    fwrite(STDOUT, "PASS: bounded destination SQL/service/controller/rendering and no-JavaScript fixtures.\n");
    fwrite(STDOUT, json_encode(['synthetic_single_samples' => $measurements, 'sqlite_actual_search_plan' => $queryPlan], JSON_UNESCAPED_SLASHES) . "\n");
}
