<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/gallery_title_completion_service_test.php
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 *
 * Runtime contracts for bounded model, matching, and administrator JSON output.
 */
declare(strict_types=1);

namespace Gallery\Core {
    /** Authentication fixture stays at the controller boundary. */
    function current_user(): ?array
    {
        if (!empty($GLOBALS['title_completion_auth_failure'])) {
            throw new \RuntimeException('Private authentication database failure.');
        }
        return $GLOBALS['title_completion_user'] ?? null;
    }
}

namespace Gallery\Controllers {
    /** Capture real controller header calls without requiring a network server. */
    function header(string $value): void
    {
        $GLOBALS['title_completion_headers'][] = $value;
    }

    /** Clear captured headers before each isolated controller invocation. */
    function clear_response_cache_headers(): void
    {
        $GLOBALS['title_completion_headers'] = [];
    }
}

namespace {
    require_once __DIR__ . '/support/gallery_title_completion_fixture.php';
    require_once dirname(__DIR__) . '/app/models/galleries.php';
    require_once dirname(__DIR__) . '/app/services/gallery_picker.php';
    require_once dirname(__DIR__) . '/app/controllers/admin_gallery_title_completion.php';

    use function Gallery\Models\gallery_model_title_completion_rows;
    use function Gallery\Services\gallery_title_completion_candidates;
    use function Gallery\Services\gallery_title_completion_empty_result;
    use function Gallery\Services\gallery_title_completion_normalize;
    use function Gallery\Tests\title_completion_fixture_rows;

    /** Fail the standalone regression test when a runtime contract is violated. */
    function completion_assert(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    /** Run the HTTP boundary with PHP-parsed query inputs and capture JSON. */
    function completion_request(array $query, string $method = 'GET'): array
    {
        $_GET = $query;
        $_SERVER['REQUEST_METHOD'] = $method;
        ob_start();
        Gallery\Controllers\cms_admin_gallery_title_completion();
        $body = (string) ob_get_clean();
        completion_assert(strlen($body) <= 16384, 'Every response fits the 16 KiB body cap.');
        completion_assert(in_array('Cache-Control: private, no-store, max-age=0', $GLOBALS['title_completion_headers'], true), 'Every response is private/no-store.');
        completion_assert(in_array('Content-Type: application/json; charset=utf-8', $GLOBALS['title_completion_headers'], true), 'Every response is JSON.');
        $result = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        completion_assert(array_keys($result) === ['ok', 'candidates', 'normalization', 'truncated'], 'Stable JSON envelope on every status.');
        completion_assert(!str_contains($body, 'secret') && !str_contains($body, 'password='), 'No private exception text.');
        return $result;
    }

    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        fwrite(STDOUT, "SKIP: title completion runtime fixtures require pdo_sqlite.\n");
        exit(0);
    }
    $fixture = new Gallery\Tests\TitleCompletionFixtureDatabase();
    $GLOBALS['title_completion_fixture'] = $fixture;
    $fixture->seed([
        ['id' => 1, 'parent_id' => 7, 'title' => 'Flight Old', 'created_at' => '2020-01-01 00:00:00'],
        ['id' => 2, 'parent_id' => 8, 'title' => 'Flight New', 'created_at' => '2026-01-01 00:00:00'],
        ['id' => 3, 'parent_id' => 7, 'title' => 'FLIGHT Tie A', 'created_at' => '2025-01-01 00:00:00'],
        ['id' => 4, 'parent_id' => 7, 'title' => 'Flight Tie B', 'created_at' => '2025-01-01 00:00:00'],
        ['id' => 5, 'parent_id' => null, 'title' => 'Flight Root', 'created_at' => '2026-02-01 00:00:00'],
        ['id' => 6, 'parent_id' => 0, 'title' => 'Flight Zero', 'created_at' => '2026-02-01 00:00:00'],
    ]);
    $result = gallery_title_completion_candidates('fl', 7);
    completion_assert(array_column($result['candidates'], 'id') === [4, 3, 1, 6, 5, 2], 'Siblings precede newer fallback; ties use descending id.');
    completion_assert(!$result['truncated'], 'Exhausted small catalog is not truncated.');
    completion_assert(array_keys($result['candidates'][0]) === ['id', 'parent_id', 'title', 'created_at'], 'No folder paths or surplus row fields.');
    $result = gallery_title_completion_candidates('FL', 0);
    completion_assert(array_column($result['candidates'], 'id') === [6, 5, 2, 4, 3, 1], 'Null and zero are both root siblings.');
    completion_assert(gallery_title_completion_candidates('Flight Tie B', 7)['candidates'] === [], 'Normalized exact title is not a completion.');

    $fixture->queries = [];
    foreach (['', 'f', '  ', "\u{00A0}\u{00A0}"] as $query) {
        completion_assert(gallery_title_completion_candidates($query, 7)['candidates'] === [], 'Short and blank queries do not scan.');
    }
    completion_assert($fixture->queries === [], 'Zero SQL for ineligible queries.');
    completion_assert(gallery_title_completion_normalize('FLight', 'ascii-lowercase') === 'flight', 'ASCII fallback is case insensitive.');
    completion_assert(gallery_title_completion_normalize("Fl\u{00E9}che", 'ascii-lowercase') === null, 'ASCII fallback declines Unicode titles.');

    if (gallery_title_completion_empty_result()['normalization'] === 'nfkc-lowercase') {
        $fixture->seed([
            ['id' => 1, 'parent_id' => 7, 'title' => "\u{FB00}light", 'created_at' => '2026-01-01 00:00:00'],
            ['id' => 2, 'parent_id' => 7, 'title' => "E\u{0301}cole", 'created_at' => '2026-01-01 00:00:00'],
            ['id' => 3, 'parent_id' => 7, 'title' => "\u{FF26}\u{FF4C}ight", 'created_at' => '2026-01-01 00:00:00'],
        ]);
        completion_assert(array_column(gallery_title_completion_candidates('ff', 7)['candidates'], 'id') === [1], 'NFKC ligature expansion matches.');
        completion_assert(array_column(gallery_title_completion_candidates("\u{00C9}c", 7)['candidates'], 'id') === [2], 'Decomposed accent and Unicode case match.');
        completion_assert(array_column(gallery_title_completion_candidates('fl', 7)['candidates'], 'id') === [3], 'Fullwidth compatibility characters match.');
    }

    foreach ([100, 1000, 10000] as $size) {
        $fixture->seed(title_completion_fixture_rows($size));
        $started = hrtime(true);
        $result = gallery_title_completion_candidates('no-match', 7);
        completion_assert($result['ok'] && $result['candidates'] === [], 'No-match fixture succeeds.');
        completion_assert($result['truncated'] === ($size > 1024), 'Budget truncation remains explicit.');
        completion_assert(count($fixture->queries) <= 3, 'At most three sorted page queries at every catalog size.');
        completion_assert(array_sum(array_column($fixture->queries, 'rows')) <= 1027, 'Bounded materialized rows including lookahead.');
        completion_assert((hrtime(true) - $started) / 1000000000 < 5, 'Synthetic 10k request completes within generous 5-second guard.');
        foreach ($fixture->queries as $query) {
            completion_assert(preg_match('/ LIMIT ([0-9]+)$/', $query['sql'], $matches) === 1 && (int) $matches[1] <= 513, 'Every model page has a hard SQL limit.');
            completion_assert(!str_contains($query['sql'], 'OFFSET') && !str_contains($query['sql'], 'folder_path'), 'No offset traversal or unused paths.');
        }
        $fixture->queries = [];
        $result = gallery_title_completion_candidates('fl', 7);
        completion_assert(count($result['candidates']) === 8 && $result['truncated'], 'Candidate cap stops matching without claiming exhaustion.');
        completion_assert(count($fixture->queries) === 1, 'Frequent sibling match uses one query.');
    }

    // Cursor traversal must find a match on the second fallback page with tied dates.
    $rows = title_completion_fixture_rows(800);
    foreach ($rows as &$row) {
        $row['parent_id'] = null;
        $row['title'] = $row['id'] === 100 ? 'Needle Found' : 'Other';
    }
    unset($row);
    $fixture->seed($rows);
    $result = gallery_title_completion_candidates('needle', 7);
    completion_assert(array_column($result['candidates'], 'id') === [100] && !$result['truncated'], 'Keyset fallback reaches second page without skips at timestamp ties.');
    completion_assert(count($fixture->queries) === 3 && str_contains($fixture->queries[2]['sql'], 'id < ?'), 'Fallback continuation uses an exclusive keyset.');
    $fixture->queries = [];
    completion_assert(count(gallery_model_title_completion_rows(7, false, PHP_INT_MAX)) === 513, 'Direct model callers cannot remove the cap.');

    // Older matching siblings are allowed to be omitted, but never silently.
    $rows = title_completion_fixture_rows(1000);
    foreach ($rows as &$row) {
        $row['parent_id'] = 7;
        $row['title'] = $row['id'] === 1 ? 'Needle Old' : 'Other';
    }
    unset($row);
    $fixture->seed($rows);
    $result = gallery_title_completion_candidates('needle', 7);
    completion_assert($result['candidates'] === [] && $result['truncated'], 'Capped sibling search admits omitted old matches.');
    $rows[] = ['id' => 1001, 'parent_id' => null, 'title' => 'Needle Fallback', 'created_at' => '2026-09-20 12:00:00'];
    $fixture->seed($rows);
    $result = gallery_title_completion_candidates('needle', 7);
    completion_assert($result['candidates'] === [] && $result['truncated'] && count($fixture->queries) === 1, 'Truncated siblings suppress fallback to preserve ranking.');

    // Missing selected parent exercises the full fallback budget at 10k rows.
    $fixture->seed(title_completion_fixture_rows(10000));
    $result = gallery_title_completion_candidates('needle', 99999);
    completion_assert($result['truncated'] && count($fixture->queries) === 3, 'Fallback no-match work stops at the fixed scan budget.');
    completion_assert(array_sum(array_column($fixture->queries, 'rows')) <= 1027, 'Large fallback fetch remains bounded.');

    $fixture->seed([
        ['id' => 1, 'parent_id' => 7, 'title' => 'Flight Sibling', 'created_at' => '2026-09-20 12:00:00'],
        ['id' => 2, 'parent_id' => null, 'title' => 'Flight Fallback', 'created_at' => '2026-09-20 12:00:00'],
    ]);
    $fixture->failAtQuery = 2;
    $result = gallery_title_completion_candidates('fl', 7);
    completion_assert(!$result['ok'] && $result['candidates'] === [] && $result['truncated'], 'Failure after a partial result drops all optional suggestions.');
    $fixture->failAtQuery = null;

    $GLOBALS['title_completion_user'] = null;
    $fixture->queries = [];
    completion_assert(!completion_request(['q' => 'fl'])['ok'] && http_response_code() === 401, 'Anonymous requests get bounded JSON 401.');
    $GLOBALS['title_completion_user'] = ['id' => 3, 'role' => 'viewer'];
    completion_assert(!completion_request(['q' => 'fl'])['ok'] && http_response_code() === 401, 'A viewer is not an administrator.');
    completion_assert($fixture->queries === [], 'Unauthorized callers never query titles.');
    $GLOBALS['title_completion_user'] = ['id' => 3, 'role' => 'admin'];
    completion_assert(!completion_request(['q' => 'fl'], 'POST')['ok'] && http_response_code() === 405, 'POST is refused.');
    completion_assert(in_array('Allow: GET', $GLOBALS['title_completion_headers'], true), '405 advertises GET only.');
    foreach ([['q' => ['fl']], ['q' => str_repeat('x', 256)], ['q' => str_repeat('x', 1025)], ['q' => "\xFF"], ['parent_id' => ['7']], ['parent_id' => '-1'], ['parent_id' => '1 OR 1=1'], ['parent_id' => str_repeat('9', 19)]] as $query) {
        completion_assert(!completion_request($query)['ok'] && http_response_code() === 400, 'Malformed query refused before SQL.');
    }
    completion_assert($fixture->queries === [], 'Invalid requests never touch titles.');
    completion_assert(completion_request(['q' => str_repeat('x', 255)])['ok'] && http_response_code() === 200, '255-character boundary accepted.');
    completion_assert(completion_request(['q' => str_repeat("\u{1F600}", 255)])['ok'], '255 four-byte Unicode scalars fit the byte cap.');
    $fixture->queries = [];
    $fixture->failAtQuery = 1;
    $result = completion_request(['q' => 'fl']);
    completion_assert(!$result['ok'] && $result['candidates'] === [] && $result['truncated'] && http_response_code() === 503, 'Query failures safely disable optional suggestions.');
    $GLOBALS['title_completion_auth_failure'] = true;
    completion_assert(!completion_request(['q' => 'fl'])['ok'] && http_response_code() === 503, 'Authentication failure remains closed JSON.');
    $GLOBALS['title_completion_auth_failure'] = false;

    // Maximum-sized stored titles still fit the actual response budget.
    $rows = title_completion_fixture_rows(8);
    foreach ($rows as &$row) {
        $row['parent_id'] = 7;
        $row['title'] = 'Fl' . str_repeat("\u{1F600}", 253);
    }
    unset($row);
    $fixture->seed($rows);
    completion_request(['q' => 'fl', 'parent_id' => '7']);

    fwrite(STDOUT, "PASS: bounded gallery title completion model, service, and JSON controller fixtures.\n");
}
