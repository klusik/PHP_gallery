<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/admin_gallery_images_deferred_test.php
 * Module Type: Regression Test
 * Purpose: Exercise real editor orchestration, bounded persistence and canonical rows in an isolated database.
 * Responsibilities: Prove drawer shell independence, ordered batches, drift refusal and ordinary fallback.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Core {
    /** Return the disposable SQLite connection.
     * @return \PDO Test-owned database without installation bootstrap.
     */
    function db(): \PDO { return $GLOBALS['editor_db']; }
    /** Escape presentation text.
     * @param string $value Plain presentation value.
     * @return string Safe HTML text.
     */
    function e(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
    /** Build rewrite-disabled synthetic routes.
     * @param string $page Logical route name.
     * @param array<string,int|string> $params Route parameters.
     * @return string Synthetic local route.
     */
    function url_for(string $page, array $params = []): string { return '/index.php?' . http_build_query(['page' => $page] + $params); }
    /** Return an initialized synthetic CSRF field.
     * @return string Trusted transport markup.
     */
    function csrf_field(): string { return '<input name="csrf_token" value="fixture">'; }
    /** Read the synthetic request method.
     * @return string GET for the read-only fixture.
     */
    function request_method(): string { return 'GET'; }
    /** Require explicit fixture administrator authority before any response.
     * @return void Throw for an unauthorized fixture request.
     */
    function require_admin(): void { if (!$GLOBALS['editor_authorized']) { throw new \RuntimeException('Unauthorized fixture.'); } }
    /** Render a lightweight header.
     * @param string $title Page title.
     * @return void Emit a small header.
     */
    function render_header(string $title): void { echo '<main>'; }
    /** Close the lightweight page.
     * @return void Emit its closing markup.
     */
    function render_footer(): void { echo '</main>'; }
}
namespace Gallery\Services {
    /** Return fixture labels with parameter interpolation.
     * @param string $key Catalog identity.
     * @param string $fallback Default text.
     * @param array<string,int|string> $params Label substitutions.
     * @return string Prepared label.
     */
    function t(string $key, string $fallback = '', array $params = []): string {
        foreach ($params as $name => $value) { $fallback = str_replace('{' . $name . '}', (string) $value, $fallback); }
        return $fallback !== '' ? $fallback : $key;
    }
    /** Keep optional tools disabled in the isolated renderer.
     * @param string $key Capability identity.
     * @return bool False for this fixture.
     */
    function feature_capability_effective_enabled(string $key): bool { return false; }
    /** Preserve the stored fixture visibility vocabulary.
     * @param string $value Stored visibility.
     * @return string Unchanged visibility.
     */
    function normalize_gallery_visibility(string $value): string { return $value; }
    /** Build an ordinary thumbnail or on-demand preview address.
     * @param array{id:int|string} $image Stored image identity.
     * @param int $size Requested pixel size.
     * @return string Synthetic thumbnail address.
     */
    function thumbnail_url(array $image, int $size): string { return '/thumb?id=' . $image['id'] . '&size=' . $size; }
    /** Build the exact read-only preload address.
     * @param array{id:int|string} $image Stored image identity.
     * @return string Synthetic read-only 300px address.
     */
    function thumbnail_editor_preload_url(array $image): string { return thumbnail_url($image, 300) . '&existing_only=1'; }
    /** Build an on-demand preview address without source reads.
     * @param array{id:int|string} $image Stored image identity.
     * @param array{id:int|string} $gallery Owning gallery identity.
     * @return string Synthetic preview address.
     */
    function thumbnail_editor_preview_url(array $image, array $gallery): string { return thumbnail_url($image, 900); }
}
namespace Gallery\Views {
    /** Render a bounded Images intro.
     * @param array{title?:string,kicker?:string,actions?:list<array<string,mixed>>,actions_html?:string} $model Prepared intro state.
     * @return void Emit the intro marker.
     */
    function view_render_admin_tab_intro(array $model): void { echo '<h2>Images</h2>'; }
    /** Render a visibility control marker.
     * @param array{image_bulk:bool,entity_id:int,kind:string,name:string,visibility:string} $model Prepared image control.
     * @return void Emit its marker.
     */
    function view_render_public_admin_visibility_menu(array $model): void { echo '<button data-fixture-visibility>Visibility</button>'; }
    /** Render an edit control marker.
     * @param array{name:string,url:string,panel_url:string} $model Prepared edit destination.
     * @return void Emit its marker.
     */
    function view_render_public_image_admin_edit_link(array $model): void { echo '<a data-fixture-edit href="' . \Gallery\Core\e($model['url']) . '">Edit</a>'; }
    /** Render a delete control marker.
     * @param array{image_bulk:bool,name:string,image_id:int} $model Prepared deletion identity.
     * @return void Emit its marker.
     */
    function view_render_public_image_admin_delete_form(array $model): void { echo '<button data-fixture-delete>Delete</button>'; }
}
namespace Gallery\Controllers {
    /** Resolve fixture capabilities without optional storage probes.
     * @return array<string,bool> Empty optional capability map.
     */
    function admin_edit_gallery_capabilities(): array { return []; }
    /** Declare whether this synthetic GET expects JSON.
     * @return bool Fixture transport selection.
     */
    function admin_wants_json(): bool { return $GLOBALS['editor_json']; }
    /** Keep a selected fixture editor tab.
     * @param string $tab Requested tab identity.
     * @return string Unchanged tab.
     */
    function admin_edit_gallery_tab_id(string $tab): string { return $tab; }
    /** Render no transient fixture notices.
     * @param array{id:int|string} $gallery Gallery identity.
     * @param array<string,bool> $capabilities Optional capability map.
     * @return void Emit no notice.
     */
    function admin_edit_gallery_render_notices(array $gallery, array $capabilities): void {}
    /** Render only lightweight fixture context.
     * @param array{id:int|string} $gallery Gallery identity.
     * @param int $imageCount Direct image count.
     * @param string $activeEditTab Initial tab.
     * @param array<string,bool> $capabilities Optional capability map.
     * @param bool $deferredImages Whether the drawer holds Images unavailable.
     * @return void Emit a bounded context marker.
     */
    function admin_edit_gallery_render_overview(array $gallery, int $imageCount, string $activeEditTab, array $capabilities, bool $deferredImages = false): void { echo '<nav data-count="' . $imageCount . '"></nav>'; }
    /** Render a lightweight identity fixture control.
     * @param array{id:int|string} $gallery Gallery identity.
     * @param string $activeEditTab Initial tab.
     * @param array<string,bool> $capabilities Optional capability map.
     * @return void Emit a marker without enumerating images.
     */
    function admin_edit_gallery_render_identity_tab(array $gallery, string $activeEditTab, array $capabilities): void { echo '<section data-identity><input name="fixture_identity"></section>'; }
    /** Render a lightweight access fixture control.
     * @param array{id:int|string} $gallery Gallery identity.
     * @param string $activeEditTab Initial tab.
     * @param array<string,bool> $capabilities Optional capability map.
     * @return void Emit a marker without enumerating images.
     */
    function admin_edit_gallery_render_access_tab(array $gallery, string $activeEditTab, array $capabilities): void { echo '<section data-access><input name="fixture_access"></section>'; }
    /** Render a lightweight display fixture control.
     * @param array{id:int|string} $gallery Gallery identity.
     * @param string $activeEditTab Initial tab.
     * @param array<string,bool> $capabilities Optional capability map.
     * @return void Emit a marker without enumerating images.
     */
    function admin_edit_gallery_render_display_tab(array $gallery, string $activeEditTab, array $capabilities): void { echo '<section data-display><input name="fixture_display"></section>'; }
    /** Render a lightweight media fixture control.
     * @param array{id:int|string} $gallery Gallery identity.
     * @param string $activeEditTab Initial tab.
     * @return void Emit a marker without enumerating images.
     */
    function admin_edit_gallery_render_media_tab(array $gallery, string $activeEditTab): void { echo '<section data-media><input name="fixture_media"></section>'; }
    /** Render a lightweight documents fixture control.
     * @param array{id:int|string} $gallery Gallery identity.
     * @param string $activeEditTab Initial tab.
     * @return void Emit a marker without enumerating images.
     */
    function admin_edit_gallery_render_documents_tab(array $gallery, string $activeEditTab): void { echo '<section data-documents><input name="fixture_documents"></section>'; }
    /** Render a lightweight organizer fixture control.
     * @param array{id:int|string} $gallery Gallery identity.
     * @param string $activeEditTab Initial tab.
     * @return void Emit a marker without enumerating images.
     */
    function admin_edit_gallery_render_organizer_tab(array $gallery, string $activeEditTab): void { echo '<section data-organizer><input name="fixture_organizer"></section>'; }
    /** Render a lightweight renamer fixture control.
     * @param array{id:int|string} $gallery Gallery identity.
     * @param string $activeEditTab Initial tab.
     * @param array<string,bool> $capabilities Optional capability map.
     * @param bool $deferred Whether the drawer defers its plan.
     * @return void Emit a marker without enumerating images.
     */
    function admin_edit_gallery_render_renamer_tab(array $gallery, string $activeEditTab, array $capabilities, bool $deferred = false): void { echo '<section data-renamer><input name="fixture_renamer"></section>'; }
    /** Render a lightweight api fixture control.
     * @param array{id:int|string} $gallery Gallery identity.
     * @param string $activeEditTab Initial tab.
     * @param array<string,bool> $capabilities Optional capability map.
     * @return void Emit a marker without enumerating images.
     */
    function admin_edit_gallery_render_api_tab(array $gallery, string $activeEditTab, array $capabilities): void { echo '<section data-api><input name="fixture_api"></section>'; }
    /** Render a synthetic canonical bulk toolbar.
     * @param array{id:int|string} $gallery Gallery identity.
     * @return void Emit its marker.
     */
    function render_admin_image_bulk_toolbar(array $gallery): void { echo '<div data-fixture-bulk>Bulk actions</div>'; }
    /** Omit inline reorder initialization in the server fixture.
     * @return void Emit no script.
     */
    function render_admin_image_reorder_script(): void {}
    /** Omit installation diagnostics in the fixture.
     * @return void Emit no diagnostics.
     */
    function render_admin_devmode_panel(): void {}
    /** Resolve a missing fixture gallery.
     * @return void Set not-found status.
     */
    function cms_not_found(): void { http_response_code(404); }
}
namespace {
    if (!in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
        echo "SKIP: SQLite is unavailable for the isolated editor contract.\n";
        exit(0);
    }
    $GLOBALS['editor_db'] = new \PDO('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]);
    $GLOBALS['editor_authorized'] = true;
    $GLOBALS['editor_json'] = false;
    $db = $GLOBALS['editor_db'];
    $db->exec('CREATE TABLE galleries (id INTEGER PRIMARY KEY, edit_revision INTEGER, cover_image_id INTEGER)');
    $db->exec("INSERT INTO galleries VALUES (1, 1, 0), (2, 1, 0)");
    $db->exec('CREATE TABLE images (id INTEGER PRIMARY KEY, gallery_id INTEGER, relative_path TEXT, filename TEXT, visibility TEXT, sort_order INTEGER, exif_taken_at TEXT, updated_at TEXT)');
    require_once dirname(__DIR__) . '/app/models/images.php';
    require_once dirname(__DIR__) . '/app/models/galleries.php';
    require_once dirname(__DIR__) . '/app/services/gallery_lookup.php';
    require_once dirname(__DIR__) . '/app/services/gallery_edit_concurrency.php';
    require_once dirname(__DIR__) . '/app/views/admin_chrome.php';
    require_once dirname(__DIR__) . '/app/views/admin_gallery_edit_tabs.php';
    require_once dirname(__DIR__) . '/app/controllers/admin_galleries_edit_page/tab_images.php';
    require_once dirname(__DIR__) . '/app/controllers/admin_galleries_edit_page/controller.php';

    /** Require one meaningful fixture postcondition.
     * @param bool $condition Observed behavior.
     * @param string $message Failure context.
     * @return void Throw when the behavior is wrong.
     */
    function deferred_editor_assert(bool $condition, string $message): void {
        if (!$condition) { throw new \RuntimeException($message); }
    }
    /** Capture one actual controller GET response.
     * @param array<string,int|string> $query Synthetic route query.
     * @param bool $json Whether the client requests JSON.
     * @return array{body:string,status:int} Captured controller output and HTTP status.
     */
    function deferred_editor_response(array $query, bool $json): array {
        $_GET = ['id' => 1] + $query;
        $_POST = [];
        $GLOBALS['editor_json'] = $json;
        http_response_code(200);
        \Gallery\Services\find_gallery(1, true);
        ob_start();
        try { \Gallery\Controllers\cms_admin_edit_gallery(); return ['body' => (string) ob_get_contents(), 'status' => http_response_code()]; }
        finally { ob_end_clean(); }
    }
    ob_start();
    \Gallery\Views\view_render_admin_tabs([
        ['id' => 'admin-edit-identity', 'label' => 'Identity'],
        ['id' => 'admin-edit-images', 'label' => 'Images', 'disabled' => true],
    ], 'admin-edit-images');
    $tabMarkup = (string) ob_get_clean();
    preg_match('/<a[^>]+id="admin-edit-images-control"[^>]*>/', $tabMarkup, $disabledControl);
    deferred_editor_assert(isset($disabledControl[0]) && str_contains($disabledControl[0], 'aria-disabled="true"')
        && str_contains($disabledControl[0], 'aria-selected="false"') && !str_contains($disabledControl[0], 'href='), 'First paint exposes disabled Images without a navigation target.');
    $insert = $db->prepare("INSERT INTO images VALUES (?, 1, ?, ?, 'private', ?, NULL, '2026-10-13 00:00:00')");
    $measurements = [];
    foreach ([0, 1, 50, 500, 1000, 3001] as $count) {
        $db->exec('DELETE FROM images');
        $db->beginTransaction();
        for ($index = 1; $index <= $count; $index++) { $insert->execute([$index, 'photo-' . $index . '.jpg', 'photo-' . $index . '.jpg', $index]); }
        $db->exec("INSERT INTO images VALUES (99999, 1, 'nested/hidden.jpg', 'hidden.jpg', 'public', 0, NULL, '2026-10-13 00:00:00')");
        $db->commit();
        $started = hrtime(true);
        $shell = deferred_editor_response(['panel' => 1], false);
        $shellMs = (hrtime(true) - $started) / 1000000;
        deferred_editor_assert(!str_contains($shell['body'], 'data-admin-image-order-row'), 'Drawer shell cannot enumerate or render photo rows.');
        deferred_editor_assert(str_contains($shell['body'], 'data-access') && str_contains($shell['body'], 'data-admin-gallery-settings-form'), 'Settings and Access exist before photo work.');
        deferred_editor_assert(str_contains($shell['body'], 'data-count="' . $count . '"'), 'Cheap count preserves direct-image scope.');
        $started = hrtime(true);
        $full = deferred_editor_response([], false);
        $fullMs = (hrtime(true) - $started) / 1000000;
        deferred_editor_assert(substr_count($full['body'], 'data-admin-image-order-row') === $count, 'Normal page retains every canonical row.');
        $offset = 0; $revision = ''; $seen = [];
        do {
            $batch = deferred_editor_response(['action' => 'images_batch', 'offset' => $offset, 'revision' => $revision], true);
            $payload = json_decode($batch['body'], true, 512, JSON_THROW_ON_ERROR);
            deferred_editor_assert($batch['status'] === 200 && $payload['ok'] === true && $payload['total'] === $count, 'Batches preserve direct count and authorization identity.');
            // Count canonical rows, not action buttons that repeat their row identity.
            preg_match_all('/<tr\b[^>]*\bdata-admin-image-order-row\b[^>]*\bdata-image-id="([0-9]+)"/', $payload['rows_html'], $matches);
            deferred_editor_assert(count($matches[1]) <= 100 && count($matches[1]) === $payload['next_offset'] - $offset, 'Transport contains one bounded canonical page.');
            deferred_editor_assert(!preg_match('/<img[^>]+\\ssrc=/', $payload['rows_html']) && ($count === 0 || str_contains($payload['rows_html'], 'data-admin-preload-src')), 'Thumbnail resources remain withheld until worker admission.');
            foreach ($matches[1] as $id) { deferred_editor_assert(!isset($seen[$id]), 'No duplicate row across pages.'); $seen[$id] = true; }
            $offset = $payload['next_offset']; $revision = $payload['revision'];
        } while (!$payload['done']);
        deferred_editor_assert(count($seen) === $count, 'End-of-list proves complete membership.');
        $measurements[] = ['images' => $count, 'shell_bytes' => strlen($shell['body']), 'shell_ms' => round($shellMs, 3), 'full_bytes' => strlen($full['body']), 'full_ms' => round($fullMs, 3)];
    }
    $db->exec('UPDATE galleries SET edit_revision = 2 WHERE id = 1');
    $stale = deferred_editor_response(['action' => 'images_batch', 'offset' => 100, 'revision' => $revision], true);
    deferred_editor_assert($stale['status'] === 409 && json_decode($stale['body'], true)['ok'] === false, 'Settings revision drift refuses stale batches.');
    $first = deferred_editor_response(['action' => 'images_batch'], true);
    $revision = json_decode($first['body'], true)['revision'];
    $db->exec('UPDATE images SET sort_order = sort_order + 10 WHERE id = 1');
    deferred_editor_assert(deferred_editor_response(['action' => 'images_batch', 'offset' => 100, 'revision' => $revision], true)['status'] === 409, 'Reordering invalidates a running inventory.');
    deferred_editor_assert(deferred_editor_response(['action' => 'images_batch', 'offset' => -1], true)['status'] === 400, 'Malformed paging is refused.');
    deferred_editor_assert(deferred_editor_response(['action' => 'images_batch', 'revision' => 'malformed'], true)['status'] === 400, 'Malformed revision tokens are refused before rendering.');
    $GLOBALS['editor_authorized'] = false;
    try { deferred_editor_response(['action' => 'images_batch'], true); throw new \RuntimeException('Unauthorized batch was accepted.'); }
    catch (\RuntimeException $error) { deferred_editor_assert($error->getMessage() === 'Unauthorized fixture.', 'Authentication precedes all batch rendering.'); }
    echo "PASS: isolated deferred editor contract; synthetic orchestration/markup timings only, not live hosting measurements.\n";
    echo json_encode($measurements, JSON_PRETTY_PRINT) . "\n";
}
