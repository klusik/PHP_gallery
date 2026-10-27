<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/admin_telemetry_workspace_test.php
 * Module Type: Regression Test and Browser Render Fixture
 * Purpose: Exercise scoped telemetry writes and the real overview view model.
 * Responsibilities: Cover auth/CSRF, readback, links, daily scope and preview markup.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Core {
    /**
     * Require the fixture's explicit Admin identity.
     * @return void Reject anonymous writes.
     */
    function require_admin(): void
    {
        if (empty($GLOBALS['telemetry_fixture_admin'])) throw new \RuntimeException('Admin required.');
    }
    /**
     * Verify the disposable CSRF token.
     * @return void Reject missing or invalid CSRF.
     */
    function verify_csrf(): void
    {
        if (($_POST['csrf'] ?? '') !== 'fixture-csrf') throw new \RuntimeException('CSRF required.');
    }
    /**
     * Return the synthetic signed-in identity.
     * @return array{id:int}|null Current fixture identity.
     */
    function current_user(): ?array { return empty($GLOBALS['telemetry_fixture_admin']) ? null : ['id' => 1]; }
    /**
     * Return a stable SQL fixture timestamp.
     * @return string Timestamp independent of host time.
     */
    function now_sql(): string { return '2026-10-27 12:00:00'; }
    /**
     * Escape prepared markup values.
     * @param string $text Raw text.
     * @return string Safe HTML.
     */
    function e(string $text): string { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
    /**
     * Build an installation-local fixture route.
     * @param string $page Route key.
     * @param array<string,int|string> $parameters Query fields.
     * @return string Local route.
     */
    function url_for(string $page, array $parameters = []): string
    {
        return '/index.php?' . http_build_query(['page' => $page] + $parameters);
    }
    /**
     * Supply a fixed public mount to the real URL owner.
     * @return string Fixture installation base.
     */
    function public_base_url(): string { return '/fixture'; }
    /**
     * Resolve a fixture-relative route at its fixed mount.
     * @param string $path Relative route.
     * @return string Mounted URL.
     */
    function base_url(string $path = ''): string { return '/fixture/' . ltrim($path, '/'); }
    /**
     * Encode route segments without losing hierarchy.
     * @param string $path Persisted gallery/image path.
     * @return string Encoded path retaining its separators.
     */
    function public_path_segment(string $path): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $path)));
    }
    /**
     * Supply the real URL owner's legacy filename normalization boundary.
     * @param string $value Fixture filename stem.
     * @return string Stable fixture slug.
     */
    function slugify(string $value): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($value)), '-');
    }
    /**
     * Issue the known disposable native CSRF field.
     * @return string Trusted test form field.
     */
    function csrf_field(): string { return '<input type="hidden" name="csrf" value="fixture-csrf">'; }
    /**
     * Keep byte presentation deterministic while testing metric scope.
     * @param int|float $bytes Already aggregated bytes.
     * @param int $precision Decimal places.
     * @return string Fixture value with an explicit byte unit.
     */
    function format_bytes(int|float $bytes, int $precision): string
    {
        return number_format($bytes, $precision) . ' B';
    }
    /**
     * Record native navigation.
     * @param string $url Prepared local URL.
     * @return void Preserve the redirect for assertions.
     */
    function redirect_to(string $url): void { $GLOBALS['telemetry_fixture_redirect'] = $url; }
    /**
     * Omit unrelated outer chrome.
     * @param string $title Prepared page title.
     * @return void Emit no extra fixture markup.
     */
    function render_header(string $title): void {}
    /**
     * Omit unrelated footer chrome.
     * @return void Emit no extra fixture markup.
     */
    function render_footer(): void {}
}

namespace Gallery\Models {
    /**
     * Supply canonical session totals to the real overview controller.
     * @param int $days Rolling lookback.
     * @param string $trafficSegment Validated segment.
     * @return array{sessions:int} Retained session count.
     */
    function telemetry_model_report_session_summary(int $days, string $trafficSegment): array { return ['sessions' => 2]; }
    /**
     * Supply bounded event totals without an installation database.
     * @param string $metricName Requested metric.
     * @param int $days Rolling lookback.
     * @param string $trafficSegment Validated segment.
     * @return int Recorded page/photo/error count.
     */
    function telemetry_model_metric_events(string $metricName, int $days, string $trafficSegment): int
    {
        return ['public.page_views' => 23, 'photo.views' => 4, 'client.errors' => 0][$metricName] ?? 0;
    }
    /**
     * Supply distinct image, thumbnail and viewing-time totals.
     * @param string $metricName Requested metric.
     * @param int $days Rolling lookback.
     * @param string $trafficSegment Validated segment.
     * @return float Aggregated bytes or seconds.
     */
    function telemetry_model_metric_sum(string $metricName, int $days, string $trafficSegment): float
    {
        return (float) (['media.image.bytes' => 150, 'media.thumbnail.bytes' => 450, 'photo.view_seconds' => 12][$metricName] ?? 0);
    }
    /**
     * Expose two observed days separated by a genuine date gap.
     * @param int $days Rolling lookback.
     * @param string $trafficSegment Validated segment.
     * @return list<array{report_date:string,page_views:int,photo_views:int,
     *     photo_seconds:int,client_errors:int,media_bytes:int,image_bytes:int}>
     *     Daily measurements; media totals deliberately include extra downloads.
     */
    function telemetry_model_report_daily_trends(int $days, string $trafficSegment): array
    {
        if (!empty($GLOBALS['telemetry_fixture_empty_trends'])) return [];
        return [
            ['report_date' => '2026-10-25', 'page_views' => 7, 'photo_views' => 0, 'photo_seconds' => 0, 'client_errors' => 0, 'media_bytes' => 999, 'image_bytes' => 200],
            ['report_date' => '2026-10-27', 'page_views' => 16, 'photo_views' => 4, 'photo_seconds' => 12, 'client_errors' => 0, 'media_bytes' => 999, 'image_bytes' => 400],
        ];
    }
    /**
     * Preserve absent retained session days instead of inventing zeros.
     * @param int $days Rolling lookback.
     * @param string $trafficSegment Validated segment.
     * @return list<array{report_date:string,sessions:int}> One observed session day.
     */
    function telemetry_model_report_daily_sessions(int $days, string $trafficSegment): array
    {
        return !empty($GLOBALS['telemetry_fixture_empty_trends']) ? [] : [['report_date' => '2026-10-27', 'sessions' => 2]];
    }
    /**
     * Supply a renamed image inside a nested public gallery.
     * @param int $days Rolling lookback.
     * @param int $limit Maximum requested rows.
     * @param string $trafficSegment Validated segment.
     * @param bool $includePublicPaths Confirmed optional-column capability.
     * @return list<array{id:int,filename:string,gallery_id:int,gallery_slug:string,
     *     gallery_title:string,image_url_slug:?string,gallery_url_path:?string,
     *     image_modified_at:?string,photo_views:int}> Ranked identity and metadata.
     */
    function telemetry_model_top_photos(int $days, int $limit, string $trafficSegment, bool $includePublicPaths = false): array
    {
        return [[
            'id' => 17, 'filename' => 'photo <&>.png', 'gallery_id' => 9,
            'gallery_slug' => 'child', 'gallery_title' => 'Gallery <&>',
            'image_url_slug' => $includePublicPaths ? 'renamed-photo' : null,
            'gallery_url_path' => $includePublicPaths ? 'parent/child' : null,
            'image_modified_at' => $includePublicPaths ? '2026-10-27 11:00:00' : null,
            'photo_views' => 4,
        ]];
    }
    /**
     * Supply the same public target under a different ranking metric.
     * @param int $days Rolling lookback.
     * @param int $limit Maximum requested rows.
     * @param string $trafficSegment Validated segment.
     * @param bool $includePublicPaths Confirmed optional-column capability.
     * @return list<array{id:int,filename:string,gallery_id:int,gallery_slug:string,
     *     gallery_title:string,image_url_slug:?string,gallery_url_path:?string,
     *     image_modified_at:?string,photo_views:int,avg_view_seconds:float,
     *     view_count:int}> Ranked identity, route metadata and capped duration.
     */
    function telemetry_model_longest_viewed_photos(int $days, int $limit, string $trafficSegment, bool $includePublicPaths = false): array
    {
        $rows = telemetry_model_top_photos($days, $limit, $trafficSegment, $includePublicPaths);
        $rows[0]['avg_view_seconds'] = 3.0;
        $rows[0]['view_count'] = 4;
        return $rows;
    }
    /**
     * Keep unrelated browser aggregation empty.
     * @param int $days Rolling lookback.
     * @param string $trafficSegment Validated segment.
     * @return list<array{browser_family:string,sessions:int}> No fixture browser rows.
     */
    function telemetry_model_browser_mix(int $days, string $trafficSegment): array { return []; }
    /**
     * Keep unrelated cache aggregation empty.
     * @param int $days Rolling lookback.
     * @param string $trafficSegment Validated segment.
     * @return list<array{cache_result:?string,events:int}> No fixture cache rows.
     */
    function telemetry_model_cache_mix(int $days, string $trafficSegment): array { return []; }
    /**
     * Read the disposable preference store.
     * @return list<array{setting_key:string,setting_value:string}> Persisted values; injected read failures throw.
     */
    function telemetry_model_all_settings(): array
    {
        if (!empty($GLOBALS['telemetry_fixture_read_failure'])) throw new \RuntimeException('Read failed.');
        $rows = [];
        foreach ($GLOBALS['telemetry_fixture_settings'] as $key => $value) {
            $rows[] = ['setting_key' => $key, 'setting_value' => $value];
        }
        return $rows;
    }
    /**
     * Read one persisted scalar.
     * @param string $key Preference ID.
     * @return string|false Stored value or missing.
     */
    function telemetry_model_setting(string $key): string|false { return $GLOBALS['telemetry_fixture_settings'][$key] ?? false; }
    /**
     * Persist through the same service boundary as production.
     * @param string $key Preference ID.
     * @param string $value Normalized value.
     * @param string $updatedAt SQL timestamp.
     * @return void Record one write; injected failures can occur before or after persistence.
     */
    function telemetry_model_set_setting(string $key, string $value, string $updatedAt): void
    {
        if (($GLOBALS['telemetry_fixture_write_failure'] ?? '') === 'before') throw new \RuntimeException('Write failed.');
        $GLOBALS['telemetry_fixture_settings'][$key] = $value;
        $GLOBALS['telemetry_fixture_writes'][] = $key;
        if (($GLOBALS['telemetry_fixture_write_failure'] ?? '') === 'after') throw new \RuntimeException('Reply lost after write.');
        if (($GLOBALS['telemetry_fixture_write_failure'] ?? '') === 'readback') $GLOBALS['telemetry_fixture_read_failure'] = true;
        // Simulate another writer winning before the readback.
        if (($GLOBALS['telemetry_fixture_write_failure'] ?? '') === 'mismatch') $GLOBALS['telemetry_fixture_settings'][$key] = '0';
    }
}

namespace Gallery\Services {
    /**
     * Supply the fixture's ordinary URL rewrite mode to the real URL owner.
     * @return bool Whether clean routes are selected.
     */
    function url_rewrite_should_emit_clean_urls(): bool { return $GLOBALS['telemetry_fixture_rewrite'] ?? true; }
    /**
     * Observe reporting storage without touching installation tables.
     * @return array{state:string} Fixture reporting availability.
     */
    function presentation_telemetry_schema_status(): array { return ['state' => $GLOBALS['telemetry_fixture_schema']]; }
    /**
     * Preserve optional route-column availability as an independent observation.
     * @param string $table Schema owner.
     * @param string $column Requested optional column.
     * @return array{state:string} Available, missing or unknown route metadata.
     */
    function schema_inspection_column(string $table, string $column): array { return ['state' => $GLOBALS['telemetry_fixture_paths'] ?? 'available']; }
    /**
     * Keep the existing privacy destination explicit in the rendered fixture.
     * @param string $section Settings section.
     * @return string Local settings destination.
     */
    function admin_settings_url(string $section): string { return '/settings/' . rawurlencode($section); }
    /**
     * Return the controlled schema observation.
     * @return array{state:string} Available, missing or unknown state.
     */
    function presentation_telemetry_settings_schema_status(): array { return ['state' => $GLOBALS['telemetry_fixture_schema']]; }
    /**
     * Interpret the fixture schema state.
     * @param array{state:string} $status Observation.
     * @return bool Whether settings can be read.
     */
    function schema_inspection_is_available(array $status): bool { return $status['state'] === 'available'; }
    /**
     * Preserve the passive availability boundary.
     * @param array{state:string} $status Observation.
     * @param string $surface Calling owner.
     * @return bool Whether rendering is available.
     */
    function presentation_schema_render_available(array $status, string $surface): bool { return schema_inspection_is_available($status); }
    /**
     * Preserve the writer's explicit schema boundary.
     * @param array{state:string} $status Observation.
     * @param string $surface Operation owner.
     * @param string $missing Missing-schema message.
     * @param string $unknown Unknown-schema message.
     * @return void Refuse unavailable writes.
     */
    function presentation_schema_assert_write_available(array $status, string $surface, string $missing, string $unknown): void
    {
        if (!schema_inspection_is_available($status)) throw new \RuntimeException($unknown);
    }
    /**
     * Localize production markup using the active catalog.
     * @param string $key Catalog ID.
     * @param string $fallback Default copy.
     * @param array<string,int|float|string> $parameters Named replacements.
     * @return string Localized fixture text.
     */
    function t(string $key, string $fallback = '', array $parameters = []): string
    {
        $text = $GLOBALS['telemetry_fixture_catalog'][$key] ?? $fallback;
        foreach ($parameters as $name => $value) $text = str_replace('{' . $name . '}', (string) $value, $text);
        return $text;
    }
    /**
     * Record the operational event without installation logs.
     * @param string $level Severity.
     * @param string $event Event ID.
     * @param string $message Safe description.
     * @param array<string,string> $context Changed setting ID.
     * @param array<string,string> $options Category/route metadata.
     * @return void Capture the event name.
     */
    function admin_log_event(string $level, string $event, string $message, array $context = [], array $options = []): void
    {
        $GLOBALS['telemetry_fixture_log'] = $event;
    }
}

namespace Gallery\Controllers {
    /**
     * Capture native flash feedback.
     * @param string $key Flash name.
     * @param string $message Safe text.
     * @return void Retain the native response message.
     */
    function flash_message(string $key, string $message): void { $GLOBALS['telemetry_fixture_flash'] = $message; }
}

namespace {
    require_once dirname(__DIR__) . '/app/helpers_mutation.php';
    require_once dirname(__DIR__) . '/app/helpers_public_urls.php';
    require_once dirname(__DIR__) . '/app/services/telemetry_settings.php';
    require_once dirname(__DIR__) . '/app/services/telemetry.php';
    require_once dirname(__DIR__) . '/app/controllers/admin_telemetry.php';
    require_once dirname(__DIR__) . '/app/views/admin_ui.php';
    require_once dirname(__DIR__) . '/app/views/admin_telemetry.php';

    /**
     * Render the real overview controller against isolated aggregate models.
     * @return string Complete production workspace markup.
     */
    function telemetry_workspace_render(): string
    {
        ob_start();
        try {
            \Gallery\Controllers\cms_admin_telemetry();
            return (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
    }

    /**
     * Restore one independent in-memory installation.
     * @return void Reset store, failures and identity.
     */
    function telemetry_workspace_reset(): void
    {
        $GLOBALS['telemetry_fixture_admin'] = true;
        $GLOBALS['telemetry_fixture_schema'] = 'available';
        $GLOBALS['telemetry_fixture_read_failure'] = false;
        $GLOBALS['telemetry_fixture_write_failure'] = '';
        $GLOBALS['telemetry_fixture_writes'] = [];
        $GLOBALS['telemetry_fixture_log'] = '';
        $GLOBALS['telemetry_fixture_flash'] = '';
        $GLOBALS['telemetry_fixture_redirect'] = '';
        $GLOBALS['telemetry_fixture_paths'] = 'available';
        $GLOBALS['telemetry_fixture_rewrite'] = true;
        $GLOBALS['telemetry_fixture_empty_trends'] = false;
        $GLOBALS['telemetry_fixture_settings'] = [];
        foreach (\Gallery\Services\telemetry_admin_editable_setting_definitions() as $key => $definition) {
            $GLOBALS['telemetry_fixture_settings'][$key] = $definition['default'];
        }
        $_GET = [];
        $_POST = [];
        $_SERVER = ['REQUEST_METHOD' => 'POST'];
    }
    /**
     * Assert an observable transport or persistence invariant.
     * @param bool $condition Expected invariant.
     * @param string $message Fixed failure description.
     * @return void Throw on mismatch.
     */
    function telemetry_workspace_assert(bool $condition, string $message): void
    {
        if (!$condition) throw new \RuntimeException($message);
    }
    /**
     * Submit one JSON request through production auth and CSRF boundaries.
     * @param array<string,string|list<string>> $fields Submitted fields.
     * @return array{status:int,payload:array{ok:bool,confirmed:bool,message:string,
     *     setting_values:array<string,string>,public_enabled:bool|null,
     *     admin_excluded:bool|null,mutation:array{type:string,entity:string,
     *     action:string,entity_ids:list<int>},panel:null,contexts:array{},
     *     fallback:array{redirect_url:string},error?:string,error_code?:string}}
     *     Actual HTTP status and complete canonical response envelope.
     */
    function telemetry_workspace_submit(array $fields): array
    {
        $_POST = $fields + ['csrf' => 'fixture-csrf', 'ajax' => '1'];
        http_response_code(200);
        ob_start();
        try {
            \Gallery\Controllers\cms_admin_telemetry_settings();
            $payload = json_decode((string) ob_get_contents(), true, flags: JSON_THROW_ON_ERROR);
        } finally {
            ob_end_clean();
        }
        return ['status' => http_response_code(), 'payload' => $payload];
    }

    telemetry_workspace_reset();
    $GLOBALS['telemetry_fixture_catalog'] = json_decode(
        (string) file_get_contents(dirname(__DIR__) . '/app/lang/en.json'), true,
        flags: JSON_THROW_ON_ERROR,
    );
    if (($argv[1] ?? '') === '--render') {
        echo telemetry_workspace_render();
        exit;
    }

    $overview = telemetry_workspace_render();
    preg_match_all('/<svg class="telemetry-sparkline".*?<\/svg>/s', $overview, $charts);
    telemetry_workspace_assert(count($charts[0]) === 6, 'All six overview metrics retain daily charts.');
    telemetry_workspace_assert(!str_contains($charts[0][0], '2026-10-25')
        && str_contains($charts[0][0], '2026-10-27'), 'Absent retained session days must not become measured zero points.');
    telemetry_workspace_assert(str_contains($charts[0][1], '2026-10-25')
        && !str_contains($charts[0][1], '2026-10-26'), 'Missing aggregate dates must remain genuine gaps.');
    telemetry_workspace_assert(str_contains($charts[0][5], '200.0 B')
        && str_contains($charts[0][5], '400.0 B')
        && !str_contains($charts[0][5], '999'), 'Image-byte charts must exclude download totals.');
    telemetry_workspace_assert(!str_contains($charts[0][4], 'NAN')
        && !str_contains($charts[0][4], 'INF'), 'All-zero series must render a finite baseline.');
    telemetry_workspace_assert(str_contains($overview,
        'href="/fixture/gallery/parent/child/renamed-photo/"')
        && str_contains($overview, 'href="/fixture/gallery/parent/child/"'),
        'Rankings must use persisted image slugs and nested gallery paths.');
    $version = \Gallery\Core\image_public_asset_version([
        'id' => 17, 'modified_at' => '2026-10-27 11:00:00',
    ]);
    telemetry_workspace_assert(str_contains($overview,
        '/thumb-600.webp?v=' . $version), 'Hover previews retain a bounded derivative and persisted cache identity.');
    telemetry_workspace_assert(str_contains($overview, 'photo &lt;&amp;&gt;.png')
        && !str_contains($overview, 'photo <&>.png'), 'Names and tooltip attributes must remain escaped.');
    $GLOBALS['telemetry_fixture_rewrite'] = false;
    $queryOverview = telemetry_workspace_render();
    telemetry_workspace_assert(str_contains($queryOverview,
        'public_path=parent%2Fchild%2Frenamed-photo')
        && str_contains($queryOverview, 'page=public_thumb'),
        'Query-route installs retain proper image and thumbnail destinations.');
    foreach (['missing', 'unknown'] as $pathState) {
        $GLOBALS['telemetry_fixture_paths'] = $pathState;
        $unlinked = telemetry_workspace_render();
        telemetry_workspace_assert(!str_contains($unlinked, 'class="telemetry-entity-link"')
            && str_contains($unlinked, 'photo &lt;&amp;&gt;.png'),
            'Unverified route metadata must preserve readable rankings without guessing links.');
    }
    $GLOBALS['telemetry_fixture_paths'] = 'available';
    $GLOBALS['telemetry_fixture_empty_trends'] = true;
    $withoutDaily = telemetry_workspace_render();
    telemetry_workspace_assert(!str_contains($withoutDaily, 'class="telemetry-sparkline"')
        && !str_contains($withoutDaily, 'class="telemetry-daily-table"')
        && str_contains($withoutDaily, '<strong>23</strong>'),
        'Missing daily data must not erase headline totals or invent a series.');
    telemetry_workspace_reset();

    $before = $GLOBALS['telemetry_fixture_settings'];
    $result = telemetry_workspace_submit([
        'telemetry_preference' => 'telemetry_cache_enabled',
        'telemetry_cache_enabled' => '0', 'telemetry_enabled' => '1',
    ]);
    $expected = $before;
    $expected['telemetry_cache_enabled'] = '0';
    telemetry_workspace_assert($result['status'] === 200 && $result['payload']['ok'] === true
        && $result['payload']['confirmed'] === true, 'A scoped write must be confirmed.');
    telemetry_workspace_assert($GLOBALS['telemetry_fixture_settings'] === $expected,
        'A scoped request must preserve every unrelated stored value.');
    telemetry_workspace_assert($result['payload']['setting_values'] === ['telemetry_cache_enabled' => '0']
        && $result['payload']['mutation']['type'] === 'settings.update', 'Return canonical values and completion metadata.');
    telemetry_workspace_assert($GLOBALS['telemetry_fixture_log'] === 'telemetry.settings_updated', 'Retain operational logging.');

    $writes = $GLOBALS['telemetry_fixture_writes'];
    foreach (['0', '91', ['invalid']] as $value) {
        $result = telemetry_workspace_submit(['telemetry_preference' => 'telemetry_raw_retention_days', 'telemetry_raw_retention_days' => $value]);
        telemetry_workspace_assert($result['status'] === 422 && $result['payload']['confirmed'] === true
            && $result['payload']['setting_values'] === ['telemetry_raw_retention_days' => '7'], 'Invalid values must return the stored scalar.');
    }
    telemetry_workspace_assert($GLOBALS['telemetry_fixture_writes'] === $writes, 'Validation failure must not write.');
    $result = telemetry_workspace_submit(['telemetry_preference' => 'not_a_setting']);
    telemetry_workspace_assert($result['status'] === 422 && $GLOBALS['telemetry_fixture_writes'] === $writes, 'Unsupported settings must not write.');
    foreach (['missing', 'unknown'] as $state) {
        $GLOBALS['telemetry_fixture_schema'] = $state;
        $result = telemetry_workspace_submit(['telemetry_preference' => 'telemetry_enabled', 'telemetry_enabled' => '1']);
        telemetry_workspace_assert($result['status'] === 503 && $result['payload']['confirmed'] === false
            && $GLOBALS['telemetry_fixture_writes'] === $writes, 'Unavailable schema must refuse writes.');
    }
    foreach (['before', 'after', 'readback'] as $failure) {
        telemetry_workspace_reset();
        $GLOBALS['telemetry_fixture_write_failure'] = $failure;
        $result = telemetry_workspace_submit(['telemetry_preference' => 'telemetry_enabled', 'telemetry_enabled' => '1']);
        telemetry_workspace_assert($result['status'] === 503 && $result['payload']['confirmed'] === false
            && $result['payload']['setting_values'] === [], 'Write failures must never invent rollback or success.');
        // A failed readback follows persistence just like a lost write reply.
        $persisted = $failure === 'before' ? '0' : '1';
        telemetry_workspace_assert(
            $GLOBALS['telemetry_fixture_settings']['telemetry_enabled']
                === $persisted,
            'Cover failure before persistence and uncertain completion after it.',
        );
    }
    telemetry_workspace_reset();
    $GLOBALS['telemetry_fixture_write_failure'] = 'mismatch';
    $result = telemetry_workspace_submit(['telemetry_preference' => 'telemetry_enabled', 'telemetry_enabled' => '1']);
    telemetry_workspace_assert($result['status'] === 409 && $result['payload']['confirmed'] === true
        && $result['payload']['setting_values'] === ['telemetry_enabled' => '0'], 'A concurrent differing value is an authoritative refusal.');
    telemetry_workspace_reset();
    $GLOBALS['telemetry_fixture_read_failure'] = true;
    $result = telemetry_workspace_submit(['telemetry_preference' => 'telemetry_enabled', 'telemetry_enabled' => '1']);
    telemetry_workspace_assert($result['status'] === 503 && $GLOBALS['telemetry_fixture_writes'] === [], 'Unavailable readback must not become a default-valued save.');
    foreach (['auth', 'csrf'] as $boundary) {
        telemetry_workspace_reset();
        $GLOBALS['telemetry_fixture_admin'] = $boundary !== 'auth';
        $rejected = false;
        try {
            telemetry_workspace_submit([
                'telemetry_preference' => 'telemetry_enabled',
                'telemetry_enabled' => '1',
                'csrf' => $boundary === 'auth' ? 'fixture-csrf' : 'invalid',
            ]);
        } catch (\RuntimeException) { $rejected = true; }
        telemetry_workspace_assert($rejected && $GLOBALS['telemetry_fixture_writes'] === [], 'Auth and CSRF must run before scoped persistence.');
    }
    telemetry_workspace_reset();
    $_POST = ['csrf' => 'fixture-csrf', 'telemetry_preference' => 'telemetry_raw_retention_days', 'telemetry_raw_retention_days' => '9', 'telemetry_tab' => 'storage'];
    \Gallery\Controllers\cms_admin_telemetry_settings();
    telemetry_workspace_assert($GLOBALS['telemetry_fixture_settings']['telemetry_raw_retention_days'] === '9'
        && str_contains($GLOBALS['telemetry_fixture_redirect'], 'telemetry_tab=storage'), 'Native scoped forms retain their tab and one-field save.');
    echo "admin_telemetry_workspace_test: ok\n";
}
