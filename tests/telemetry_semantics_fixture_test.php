<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/telemetry_semantics_fixture_test.php
 * Module Type: Regression Test
 *
 * Purpose:
 *   Exercises corrected telemetry session semantics with deterministic event sequences.
 *
 * Responsibilities:
 *   - Prove session.started contributes zero page views
 *   - Prove observed sessions exist even when session.started transport is missing
 *   - Prove one-page sessions remain bounce-like under the current definition
 *   - Prove two-page sessions are not bounce-like
 *   - Prove photo view/time counters remain independent from page views
 *
 * Author:
 *   Rudolf Klusal
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 *
 * Notes:
 *   - Keep comments and docstrings intact when modifying this file.
 *   - This fixture stubs only the persistence boundary. Production service logic
 *     still computes every upsert increment passed into the fake model.
 */

declare(strict_types=1);

namespace Gallery\Core {
    /** Return one deterministic SQL timestamp for the telemetry fixture. */
    function now_sql(): string
    {
        return '2026-09-19 08:00:00';
    }

    /** Return an empty request bucket because every fixture event supplies its route. */
    function request_data(?string $bucket = null): array
    {
        return [];
    }
}

namespace Gallery\Models {
    /**
     * Emulate the telemetry_sessions INSERT ... ON DUPLICATE KEY UPDATE contract.
     *
     * @param array<int,mixed> $params Ordered production upsert parameters.
     */
    function telemetry_model_upsert_session(array $params): void
    {
        $sessionHash = (string) $params[0];
        $rows = &$GLOBALS['telemetry_semantics_fixture_rows'];
        if (!isset($rows[$sessionHash])) {
            $rows[$sessionHash] = [
                'page_view_count' => (int) $params[17],
                'photo_view_count' => (int) $params[18],
                'duration_seconds_capped' => (int) $params[19],
                'bounced' => (int) $params[20],
            ];
            return;
        }

        $rows[$sessionHash]['page_view_count'] += (int) $params[17];
        $rows[$sessionHash]['photo_view_count'] += (int) $params[18];
        $rows[$sessionHash]['duration_seconds_capped'] += (int) $params[19];
        $rows[$sessionHash]['bounced'] = $rows[$sessionHash]['page_view_count'] <= 1 ? 1 : 0;
    }
}

namespace Gallery\Services {
    /** Return the fixture's configured visible-time cap. */
    function telemetry_max_photo_view_ms(): int
    {
        return 900_000;
    }

    /** Disable optional geography for the deterministic fixture. */
    function telemetry_setting_enabled(string $key, string $default = '0'): bool
    {
        return false;
    }
}

namespace {
    use function Gallery\Services\telemetry_touch_session;

    /** Throw when one corrected-semantics fixture assertion fails. */
    function telemetry_semantics_fixture_assert(bool $condition, string $label): void
    {
        if (!$condition) {
            throw new RuntimeException($label);
        }
    }

    $root = dirname(__DIR__);
    require_once $root . '/app/services/telemetry_privacy.php';
    require_once $root . '/app/services/telemetry.php';

    $GLOBALS['telemetry_semantics_fixture_rows'] = [];

    /** Apply one normalized fixture event through the real session-touch service. */
    $apply = static function (string $sessionHash, string $eventName, int $durationMs = 0): void {
        telemetry_touch_session(
            $sessionHash,
            $eventName,
            [
                'route_name' => 'gallery',
                'duration_ms' => $durationMs,
                'locale' => 'en',
            ],
            77,
            $eventName === 'public.photo.opened' || $eventName === 'public.photo.visible_time' ? 101 : null,
            'direct',
            'chrome',
            'windows',
            'desktop',
            'xxl'
        );
    };

    // Session A: one page view, one photo activation, five seconds visible time.
    $apply('session-a', 'public.session.started');
    $apply('session-a', 'public.gallery.viewed');
    $apply('session-a', 'public.photo.opened');
    $apply('session-a', 'public.photo.visible_time', 5000);

    // Session B: two explicit page views and therefore not a bounce.
    $apply('session-b', 'public.session.started');
    $apply('session-b', 'public.gallery.viewed');
    $apply('session-b', 'public.page.viewed');

    // Session C deliberately has no session.started transport event. Any observed
    // event with a valid session hash must still create the canonical session row.
    $apply('session-c', 'public.gallery.viewed');

    $rows = $GLOBALS['telemetry_semantics_fixture_rows'];
    $sessions = count($rows);
    $pageViews = array_sum(array_column($rows, 'page_view_count'));
    $photoViews = array_sum(array_column($rows, 'photo_view_count'));
    $photoSeconds = array_sum(array_column($rows, 'duration_seconds_capped'));
    $bounces = array_sum(array_column($rows, 'bounced'));

    telemetry_semantics_fixture_assert($sessions === 3, 'Canonical session reporting must retain a session observed without session.started.');
    telemetry_semantics_fixture_assert($pageViews === 4, 'Fixture must produce exactly four explicit page views.');
    telemetry_semantics_fixture_assert($photoViews === 1, 'Fixture must produce exactly one photo view.');
    telemetry_semantics_fixture_assert($photoSeconds === 5, 'Fixture must accumulate five capped photo-visible seconds.');
    telemetry_semantics_fixture_assert($bounces === 2, 'Only the one-page sessions A and C must remain bounce-like.');
    telemetry_semantics_fixture_assert($rows['session-a']['page_view_count'] === 1, 'session.started plus one gallery view must count as one page view.');
    telemetry_semantics_fixture_assert($rows['session-b']['page_view_count'] === 2 && $rows['session-b']['bounced'] === 0, 'Two explicit page views must clear bounce state.');
    telemetry_semantics_fixture_assert($rows['session-c']['page_view_count'] === 1, 'Missing session.started must not erase the observed page view or session.');

    echo "telemetry_semantics_fixture_test: ok\n";
}
