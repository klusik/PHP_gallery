<?php

/**
 * Project: PHP Gallery
 * File: tests/admin_side_panel_gallery_mutation_test.php
 * Module Type: Regression Test
 * Purpose: Exercise real create-gallery controller mutation responses with isolated stubs.
 * Responsibilities:
 *   - Verify POST normalization and canonical JSON completion without a live database.
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * Focused regression coverage for the gallery side-panel create mutation.
 *
 * The production controller is executed with narrowly scoped Core and Services
 * stubs so the test can verify its POST/JSON contract without requiring a live
 * database or a configured gallery filesystem. The test deliberately exercises
 * the real controller functions responsible for normalizing the input,
 * persisting through create_empty_gallery(), enforcing the admin and CSRF
 * gates, and building the JSON refresh metadata consumed by the side panel.
  *
 * Author:
 *   Rudolf Klusal
*/

declare(strict_types=1);

namespace Gallery\Core {
    /** Track that the production controller still enforces the admin gate. */
    function require_admin(): void
    {
        $GLOBALS['side_panel_test_require_admin_calls'] = ($GLOBALS['side_panel_test_require_admin_calls'] ?? 0) + 1;
    }

    /** Track that the production controller still enforces CSRF validation. */
    function verify_csrf(): void
    {
        $GLOBALS['side_panel_test_verify_csrf_calls'] = ($GLOBALS['side_panel_test_verify_csrf_calls'] ?? 0) + 1;
    }

    /** Return POST so the real create controller takes its mutation branch. */
    function request_method(): string
    {
        return 'POST';
    }

    require_once __DIR__ . '/support/admin_operation_fixture.php';

    /**
     * Authenticated fixture identity, independent of the operation key.
     *
     * @return array<string,mixed> Authenticated fixture identity, independent of the operation key.
     */
    function current_user(): array
    {
        return ['id' => 1, 'role' => 'admin'];
    }

    /** Build deterministic public URLs for assertions. */
    function gallery_public_url(array $gallery): string
    {
        return '/gallery/' . rawurlencode((string) ($gallery['slug'] ?? ''));
    }

    /** Build only the route URLs needed by the success payload. */
    function url_for(string $route, array $params = []): string
    {
        if ($route === 'home') {
            return '/';
        }
        if ($route === 'admin_edit_gallery') {
            return '/admin/gallery/edit?id=' . (int) ($params['id'] ?? 0) . '&created=' . (int) ($params['created'] ?? 0);
        }
        return '/' . rawurlencode($route);
    }

    /** Direct-page fallbacks are not expected on the JSON branch. */
    function redirect_to(string $url): never
    {
        throw new \RuntimeException('Unexpected redirect to ' . $url);
    }

    /** Direct-page rendering helpers must remain unused in this focused test. */
    function flash_message(string $key, string $message): void
    {
        throw new \RuntimeException('Unexpected flash message: ' . $key . ' ' . $message);
    }

    /** Return an empty CSRF field for rendering paths that must stay unused. */
    function csrf_field(): string
    {
        return '';
    }

    /** Return a deterministic fixture CSRF token. */
    function csrf_token(): string
    {
        return 'test-csrf-token';
    }

    /** Escape fixture output through the production-compatible HTML flags. */
    function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** Fail if the JSON branch attempts direct-page header rendering. */
    function render_header(string $title): void
    {
        throw new \RuntimeException('Unexpected direct-page header: ' . $title);
    }

    /** Fail if the JSON branch attempts direct-page footer rendering. */
    function render_footer(): void
    {
        throw new \RuntimeException('Unexpected direct-page footer.');
    }
}

namespace Gallery\Services {
    const ADMIN_GALLERY_DISCOVERY_DEFAULT_BATCH_SIZE = 10;
    const ADMIN_GALLERY_DISCOVERY_MAX_BATCH_SIZE = 100;
    /**
     * The single public root result in this fixture.
     *
     * @param int $parentGalleryId Public gallery context.
     * @return int The single public root result in this fixture.
     */
    function gallery_mutation_context_count(int $parentGalleryId): int
    {
        return 1;
    }
    /**
     * Simulate creation once and retain the resulting catalog row for replay validation.
     * @param array<string,mixed> $input Normalized controller creation fields captured for assertions.
     * @return array{id:int,parent_id:int,title:string,slug:string,folder_path:string} Stable fixture gallery identity and public metadata.
     */
    function create_empty_gallery(array $input): array
    {
        $GLOBALS['side_panel_test_create_calls'] = ($GLOBALS['side_panel_test_create_calls'] ?? 0) + 1;
        $GLOBALS['side_panel_test_create_input'] = $input;

        return $GLOBALS['operation_fixture_galleries'][77] = [
            'id' => 77,
            'parent_id' => (int) ($input['parent_id'] ?? 0),
            'title' => (string) ($input['title'] ?? ''),
            'slug' => 'new-panel-gallery',
            'folder_path' => 'new-panel-gallery',
        ];
    }

    /** Mirror the public root listing policy used by the Stage 4 context-count helper. */
    function public_gallery_listing_sql_fragment(string $alias = 'g'): string
    {
        return $alias . ".visibility = 'public'";
    }

    /** Treat this focused fixture as a listed public root gallery. */
    function gallery_is_public_listed(array $gallery): bool
    {
        return true;
    }

    /** Preserve the controller's visibility normalization dependency. */
    function gallery_visibility_storage_value(string $visibility): string
    {
        return $visibility;
    }

    /**
     * Retained fixture result for replay validation.
     *
     * @param int $galleryId Original result identity.
     * @param bool $includeNonPublic Fresh administrative lookup.
     * @return array<string,mixed>|null Retained fixture result for replay validation.
     */
    function find_gallery(int $galleryId, bool $includeNonPublic = false): ?array
    {
        return $GLOBALS['operation_fixture_galleries'][$galleryId] ?? null;
    }

    /** Keep translation calls deterministic while preserving their call sites. */
    function t(string $key, string|array|null $fallback = null, array $variables = []): string
    {
        if (is_string($fallback) && $fallback !== '') {
            return $fallback;
        }
        return $key;
    }

    /** Record logging without coupling the test to the configured log backend. */
    function admin_log_event(string $level, string $event, string $message, array $context = [], array $options = []): void
    {
        $GLOBALS['side_panel_test_log_events'][] = [$level, $event, $message, $context, $options];
    }
}

namespace Gallery\Views {
    /** Fail if the JSON branch renders gallery date fields. */
    function view_render_admin_gallery_date_range_fields(...$args): void
    {
        throw new \RuntimeException('Unexpected date-range rendering.');
    }

    /** Fail if the JSON branch renders new-gallery fields. */
    function view_render_admin_new_gallery_fields(...$args): void
    {
        throw new \RuntimeException('Unexpected new-gallery field rendering.');
    }

    /** Fail if the JSON branch renders the side-panel form. */
    function view_render_admin_new_gallery_side_panel(...$args): void
    {
        throw new \RuntimeException('Unexpected side-panel rendering after JSON mutation.');
    }

    /** Fail if the JSON branch renders description guidance. */
    function view_render_gallery_description_formatting_hint(...$args): void
    {
        throw new \RuntimeException('Unexpected description hint rendering.');
    }
}

namespace Gallery\Controllers {
    /** Resolve the parent query without loading the rest of the controller stack. */
    function selected_gallery_id_from_query(string $key): int
    {
        return (int) ($_REQUEST[$key] ?? $_POST[$key] ?? $_GET[$key] ?? 0);
    }

    /** Mirror the application's existing AJAX detection for this focused test. */
    function admin_wants_json(): bool
    {
        return !empty($_POST['ajax']) || !empty($_POST['panel']);
    }

    require_once __DIR__ . '/../app/helpers_mutation.php';
    require_once __DIR__ . '/../app/controllers/admin_galleries_discovery.php';

    /** Fail fast with a useful message instead of relying on assert.ini. */
    function side_panel_test_expect(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new \RuntimeException($message);
        }
    }

    $GLOBALS['operation_fixture_db'] = new \Gallery\Core\AdminOperationFixtureDatabase();
    $GLOBALS['operation_fixture_galleries'] = [];
    /**
     * Verified fixture schema; no production database is inspected.
     *
     * @return bool Verified fixture schema; no production database is inspected.
     */
    function side_panel_operation_schema_probe(): bool { return true; }
    \Gallery\Services\schema_inspection_set_query_executor_for_tests(__NAMESPACE__ . '\\side_panel_operation_schema_probe');
    $_GET = [];
    $_POST = [
        'operation_key' => \Gallery\Services\admin_operation_new_key(),
        'ajax' => '1',
        'panel' => '1',
        'title' => 'New Panel Gallery',
        'folder_name' => 'new-panel-gallery',
        'description' => 'Created through the embedded admin panel.',
        'visibility' => 'public',
        'parent_id' => '0',
    ];
    $_REQUEST = $_POST;

    ob_start();
    cms_admin_new_gallery();
    $rawResponse = (string) ob_get_clean();
    $payload = json_decode($rawResponse, true, 512, JSON_THROW_ON_ERROR);

    side_panel_test_expect(($GLOBALS['side_panel_test_require_admin_calls'] ?? 0) === 1, 'Admin authorization gate was not executed exactly once.');
    side_panel_test_expect(($GLOBALS['side_panel_test_verify_csrf_calls'] ?? 0) === 1, 'CSRF validation was not executed exactly once.');
    side_panel_test_expect(($GLOBALS['side_panel_test_create_calls'] ?? 0) === 1, 'Shared create_empty_gallery() mutation was not executed exactly once.');
    side_panel_test_expect(($GLOBALS['side_panel_test_create_input']['title'] ?? '') === 'New Panel Gallery', 'Create input was not normalized and passed to persistence.');
    side_panel_test_expect(($payload['ok'] ?? false) === true, 'AJAX create response did not report success.');
    side_panel_test_expect((int) ($payload['gallery_id'] ?? 0) === 77, 'AJAX create response did not identify the newly persisted gallery.');
    side_panel_test_expect(($payload['gallery_url'] ?? '') === '/gallery/new-panel-gallery', 'AJAX create response did not include the new public gallery URL.');
    side_panel_test_expect(($payload['refresh_url'] ?? null) === '/', 'Root create response did not provide the canonical background refresh URL.');
    side_panel_test_expect(($payload['refresh_gallery_id'] ?? null) === 0, 'Root create response returned an unexpected refresh gallery id.');
    side_panel_test_expect(array_keys(array_intersect_key($payload, array_flip(['ok', 'message', 'mutation', 'panel', 'contexts', 'fallback']))) === ['ok', 'message', 'mutation', 'panel', 'contexts', 'fallback'], 'Create response must expose the canonical mutation envelope.');
    side_panel_test_expect(($payload['mutation']['type'] ?? '') === 'gallery.create', 'Create response must identify the typed gallery mutation.');
    side_panel_test_expect(($payload['mutation']['entity_ids'] ?? []) === [77], 'Create response must preserve the stable created gallery id.');
    side_panel_test_expect(($payload['panel']['keep_open'] ?? false) === true, 'Enhanced create must keep the side panel open.');
    side_panel_test_expect(($payload['contexts'][0]['postcondition']['type'] ?? '') === 'gallery_membership', 'Create response must use pagination-safe gallery membership verification.');
    side_panel_test_expect(($payload['contexts'][0]['postcondition']['present'] ?? false) === true, 'Create response must expect the new public card to be present.');
    side_panel_test_expect((int) ($payload['contexts'][0]['postcondition']['count'] ?? -1) === 1, 'Create response must carry the authoritative full root gallery count.');
    side_panel_test_expect(($payload['fallback']['redirect_url'] ?? '') !== '', 'Classic redirect must remain fallback metadata.');

    ob_start();
    cms_admin_new_gallery();
    $replayed = json_decode((string) ob_get_clean(), true, 512, JSON_THROW_ON_ERROR);
    side_panel_test_expect($replayed === $payload, 'Same-key retry did not return the original canonical response.');
    side_panel_test_expect($GLOBALS['side_panel_test_create_calls'] === 1, 'Same-key retry executed gallery creation again.');
    side_panel_test_expect($GLOBALS['side_panel_test_require_admin_calls'] === 2 && $GLOBALS['side_panel_test_verify_csrf_calls'] === 2, 'Replay skipped current authorization or CSRF checks.');
    side_panel_test_expect($GLOBALS['operation_fixture_db']->locks === [], 'Create or replay leaked ledger ownership.');
    fwrite(STDOUT, "PASS admin_side_panel_gallery_mutation_test\n");
}
