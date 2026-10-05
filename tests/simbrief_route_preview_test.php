<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/simbrief_route_preview_test.php
 * Module Type: Regression Test
 * Purpose: Verify complete SimBrief routes cross the preview endpoint without early persistence.
 * Responsibilities:
 *   - Exercise the actual controller, route extraction, localized prose and private drafts.
 *   - Keep filed route text independent from the saved OFP coordinate geometry.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Core {
    /** Require the disposable administrator fixture. @return void Admit the fixture request. */
    function require_admin(): void {}
    /** Return the tested request transport. @return string POST for preview requests. */
    function request_method(): string { return 'POST'; }
    /** Check the synthetic CSRF input. @return void Refuse unprotected fixture requests. */
    function verify_csrf(): void {
        if (($_POST['csrf_token'] ?? '') !== 'fixture-csrf') throw new \RuntimeException('Missing fixture CSRF.');
    }
    /** Return the private draft owner. @return array{id:int} Disposable administrator identity. */
    function current_user(): array { return ['id' => 11]; }
}

namespace Gallery\Services {
    /** Find the fixture gallery without database access. @param int $id Gallery identity. @return array{id:int}|null Prepared gallery row. */
    function find_gallery(int $id): ?array { return $id === 170 ? ['id' => $id] : null; }
    /** Supply the synthetic OFP through the production fetch seam. @param string $url Requested trusted endpoint. @param int $timeout Request timeout in seconds. @param array<int,string> $headers Request headers. @return string JSON fixture body. */
    function http_fetch_with_headers(string $url, int $timeout, array $headers): string {
        if (!str_contains($url, 'userid=12345') || !str_contains($url, 'json=v2')) throw new \RuntimeException('Unexpected OFP request.');
        return json_encode($GLOBALS['simbrief_preview_payload'], JSON_THROW_ON_ERROR);
    }
    /** Enable fixture localization. @return bool Localization is available. */
    function content_localization_enabled(): bool { return true; }
    /** Resolve fixture translation storage. @param string $entity Entity kind. @return bool Gallery translations are available. */
    function content_localization_schema_ready(string $entity): bool { return $entity === 'gallery'; }
    /** Offer the maintained content languages. @return list<string> Supported languages. */
    function content_supported_languages(): array { return ['en', 'cs', 'de', 'sv']; }
    /** Select fixture public content language. @return string Czech public language. */
    function translation_public_language(): string { return 'cs'; }
    /** Resolve safe test copy. @param string $key Translation key. @param string $fallback English fallback. @param array<string,mixed> $parameters Replacements. @return string Fixture copy. */
    function t(string $key, string $fallback = '', array $parameters = []): string { return $fallback; }
    /** Resolve the fixture map schema. @return array{state:string} Available map storage. */
    function presentation_flight_map_schema_status(): array { return ['state' => 'available']; }
    /** Recognize verified fixture storage. @param array<string,mixed> $status Schema observation. @return bool Whether storage is available. */
    function schema_inspection_is_available(array $status): bool { return ($status['state'] ?? '') === 'available'; }
    /** Capture the actual resolved route save without a database. @param int $galleryId Saved gallery identity. @param string $routeText Complete filed route. @param array<int,array<string,mixed>> $points Original OFP geometry. @param array<int,array<string,mixed>> $unresolved Skipped-point diagnostics. @return array<string,mixed> Captured save data. */
    function save_gallery_flight_path_resolved_points(int $galleryId, string $routeText, array $points, array $unresolved = []): array {
        $GLOBALS['simbrief_preview_saves'][] = compact('galleryId', 'routeText', 'points', 'unresolved');
        return ['points' => $points, 'point_count' => count($points)];
    }
}

namespace {
    require_once dirname(__DIR__) . '/app/services/simbrief_descriptions.php';
    require_once dirname(__DIR__) . '/app/services/simbrief_description_drafts.php';
    require_once dirname(__DIR__) . '/app/views/simbrief_descriptions.php';
    require_once dirname(__DIR__) . '/app/controllers/admin_simbrief.php';

    /** Require an observable preview outcome. @param bool $condition Expected state. @param string $message Failure explanation. @return void Throw on a violated outcome. */
    function simbrief_preview_assert(bool $condition, string $message): void {
        if (!$condition) throw new \RuntimeException($message);
    }

    $filedRoute = 'DCT TREEL DCT UQQ DCT YZT DCT PR DCT';
    $completeRoute = 'CYVR ' . $filedRoute . ' CYPR';
    $GLOBALS['simbrief_preview_payload'] = [
        'origin' => ['icao_code' => 'CYVR', 'pos_lat' => '49.1947', 'pos_long' => '-123.1792'],
        'destination' => ['icao_code' => 'CYPR', 'pos_lat' => '54.2861', 'pos_long' => '-130.4447'],
        'general' => ['route' => $filedRoute],
        'navlog' => ['fix' => [['ident' => 'TREEL', 'pos_lat' => '50.0', 'pos_long' => '-124.0']]],
    ];
    $GLOBALS['simbrief_preview_saves'] = [];
    session_save_path(sys_get_temp_dir());
    session_id('simbrief-preview-test-' . bin2hex(random_bytes(8)));
    session_start();
    $draftPaths = [];
    try {
        foreach ([170, 0] as $galleryId) {
            $_POST = ['csrf_token' => 'fixture-csrf', 'gallery_id' => $galleryId, 'simbrief_pilot_id' => '12345'];
            ob_start();
            \Gallery\Controllers\cms_admin_simbrief_description();
            $result = json_decode((string) ob_get_clean(), true, 512, JSON_THROW_ON_ERROR);
            simbrief_preview_assert(!empty($result['ok']), 'Preview request failed.');
            $token = (string) ($result['draft_ref'] ?? '');
            simbrief_preview_assert(preg_match('/\A[a-f0-9]{48}\z/D', $token) === 1, 'Missing private draft reference.');
            $draftPaths[] = \Gallery\Services\simbrief_description_draft_directory() . '/' . $token . '.json';
            simbrief_preview_assert(($result['route']['route_text'] ?? '') === $completeRoute, 'Endpoint did not return the complete filed route.');
            simbrief_preview_assert(($result['route']['point_count'] ?? 0) === 3 && ($result['route']['saved'] ?? true) === false, 'Preview must return staged geometry, without saving it.');
            simbrief_preview_assert($GLOBALS['simbrief_preview_saves'] === [], 'Preview persisted a gallery route prematurely.');
            foreach (['description', ...array_keys($result['translations'] ?? [])] as $language) {
                $description = $language === 'description' ? $result['description'] : $result['translations'][$language];
                simbrief_preview_assert(str_contains($description, '`' . $completeRoute . '`'), $language . ' lost a route endpoint.');
            }
            $draft = \Gallery\Services\simbrief_description_draft_read(11, $token);
            simbrief_preview_assert($draft['payload'] === $GLOBALS['simbrief_preview_payload'], 'Private draft changed the original OFP.');
        }
        $route = \Gallery\Services\simbrief_description_save_route_map_from_ofp(170, $draft['payload'], $draft['details']);
        simbrief_preview_assert($route['saved'] === true && $route['route_text'] === $completeRoute, 'Saved route lost filed tokens or endpoint airports.');
        simbrief_preview_assert(count($GLOBALS['simbrief_preview_saves']) === 1 && $GLOBALS['simbrief_preview_saves'][0]['points'][1]['longitude'] === -124.0, 'Save must retain the OFP coordinates.');
    } finally {
        foreach ($draftPaths as $draftPath) if (is_file($draftPath)) unlink($draftPath);
        session_destroy();
    }
    fwrite(STDOUT, "SimBrief route preview: PASS\n");
}
