<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/public_search_compound_test.php
 * Module Type: Regression Test
 * Purpose: Verify AND/OR and quoted phrase search against isolated phase-aware SQL fixtures.
 * Responsibilities:
 *   - Test Unicode quotation parsing, separators, boolean grouping, and bounds
 *   - Test required terms spanning title, tag, description and translation attributes
 *   - Verify image-only eligibility, progressive phase separation, and public scope
 *   - Protect ordinary single-term and exact-phrase parsing contracts
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 * Last Updated: 2026-10-13
 */

declare(strict_types=1);

namespace Gallery\Core {
    /**
     * Normalize the fixture's optional gallery scope without bootstrapping storage.
     *
     * @param string $path Relative folder path.
     * @return string Normalized fixture path.
     */
    function normalize_relative_path(string $path): string
    {
        return trim($path, '/');
    }
}

namespace Gallery\Models {
    /**
     * Execute one isolated candidate statement on the disposable SQLite fixture.
     *
     * @param string $name Diagnostics name from the model.
     * @param string $sql Model-owned SELECT statement.
     * @param array<int,mixed> $params Positional bindings.
     * @return array<int,array<string,mixed>> Selected fixture rows.
     */
    function public_search_diagnostics_fetch_all(
        string $name,
        string $sql,
        array $params,
    ): array {
        $db = $GLOBALS['public_search_compound_fixture_db'];
        $statement = $db->prepare($sql);
        $statement->execute($params);
        return $statement->fetchAll(\PDO::FETCH_ASSOC);
    }
}

namespace {
    require_once dirname(__DIR__) . '/app/models/public_search.php';
    require_once dirname(__DIR__) . '/app/models/public_search_progressive.php';
    require_once dirname(__DIR__) . '/app/services/public_search.php';

    use function Gallery\Models\public_search_model_compound_gallery_rows;
    use function Gallery\Models\public_search_model_compound_image_rows;
    use function Gallery\Models\public_search_model_like_pattern;
    use function Gallery\Services\public_search_parse_groups;
    use function Gallery\Services\public_search_normalize_query;

    /**
     * Assert a compound-search fixture invariant.
     *
     * @param bool $condition Evaluated invariant.
     * @param string $label Failure context.
     * @return void
     */
    function public_search_compound_assert(bool $condition, string $label): void
    {
        if (!$condition) {
            throw new \RuntimeException($label);
        }
    }

    /**
     * Return matching IDs for one gallery phase using model-level fixture SQL.
     *
     * @param string $query Raw browser query.
     * @param string $phase Public search phase.
     * @param bool $translated Enable the fixture's translation table.
     * @param ?array $context Optional branch context.
     * @return array<int,int> Candidate gallery identifiers.
     */
    function public_search_compound_test_galleries(
        string $query,
        string $phase = 'primary',
        bool $translated = false,
        ?array $context = null,
    ): array {
        $rows = public_search_model_compound_gallery_rows(
            public_search_parse_groups(public_search_normalize_query($query)),
            $phase,
            true,
            $context,
            $translated,
            'cs',
            'missing',
            60,
        );
        return array_map('intval', array_column($rows, 'id'));
    }

    /**
     * Return matching IDs for one photo phase using model-level fixture SQL.
     *
     * @param string $query Raw browser query.
     * @param string $phase Public search phase.
     * @param bool $translated Include image translation fields.
     * @param bool $aiReady Include optional AI image metadata.
     * @return array<int,int> Candidate image identifiers.
     */
    function public_search_compound_test_images(
        string $query,
        string $phase = 'media',
        bool $translated = false,
        bool $aiReady = false,
    ): array {
        $rows = public_search_model_compound_image_rows(
            public_search_parse_groups(public_search_normalize_query($query)),
            $phase,
            true,
            null,
            $translated,
            'cs',
            $aiReady,
            'missing',
            60,
        );
        return array_map('intval', array_column($rows, 'id'));
    }

    // Production requests use selective runtime modules, not app/services.php.
    // These checks prevent BASIC-only green status when new functions live in
    // a separate file missing from the generated route plan/package whitelist.
    $runtimePlan = require dirname(__DIR__) . '/app/runtime/modules.php';
    $publicSearchFiles = $runtimePlan['modules']['domain-public-search']['files']
    ?? [];
    $productionPolicy = json_decode(
        (string) file_get_contents(
            dirname(__DIR__) . '/app/production-files.json',
        ),
        true,
    );
    $productionFiles = is_array($productionPolicy)
        ? $productionPolicy['production_files'] ?? []
        : [];
    foreach ([
        'app/models/public_search_progressive.php' => 'function public_search_model_compound_gallery_rows(',
        'app/services/public_search_progressive.php' => 'function public_search_compound_phase_results(',
    ] as $path => $declaration) {
        public_search_compound_assert(
            in_array($path, $publicSearchFiles, true),
            $path . ' must be loaded by the selective public-search route.',
        );
        public_search_compound_assert(
            in_array($path, $productionFiles, true),
            $path . ' must be part of production packaging.',
        );
        public_search_compound_assert(
            str_contains(
                (string) file_get_contents(dirname(__DIR__) . '/' . $path),
                $declaration,
            ),
            $path
                . ' must actually declare the compound entrypoint loaded by the route.',
        );
    }

    // Generated runtime plans reject unreviewed indirect calls in public routes.
    // Protect this source-level contract so BASIC passing is not mistaken for
    // evidence that prepare_candidate.php can compile this dynamic call graph.
    $parserSource = (string) file_get_contents(
        dirname(__DIR__) . '/app/services/public_search.php',
    );
    public_search_compound_assert(
        str_contains(
            $parserSource,
            'function public_search_flush_query_token(',
        ),
        'Public search token flushing must use a statically resolved named helper.',
    );
    public_search_compound_assert(
        !str_contains($parserSource, '$flush();')
            && !str_contains($parserSource, '$flush ='),
        'Public search must not introduce an unreviewed variable callable in the runtime route.',
    );

    $normal =
        static fn(string $query): array => public_search_parse_groups(public_search_normalize_query(
            $query,
        ));
    public_search_compound_assert(
        $normal('A380') === [['A380']],
        'A single term must remain a single optimized search.',
    );
    public_search_compound_assert(
        $normal('LKPR') === [['LKPR']],
        'An airport code must remain a single search.',
    );
    public_search_compound_assert(
        $normal('A380 LKPR') === [['A380', 'LKPR']],
        'Space-separated terms must imply AND.',
    );
    public_search_compound_assert(
        $normal(" A380,\tLKPR ; gate ") === [['A380', 'LKPR', 'gate']],
        'Ordinary separators must imply AND.',
    );
    public_search_compound_assert(
        $normal('A380/LKPR & arrival') === [['A380', 'LKPR', 'arrival']],
        'Slash/ampersand should act as separators.',
    );
    public_search_compound_assert(
        $normal('A380 OR B737') === [['A380'], ['B737']],
        'Explicit uppercase OR must create alternatives.',
    );
    public_search_compound_assert(
        $normal('A380 LKPR | B737') === [['A380', 'LKPR'], ['B737']],
        'AND must bind within OR groups.',
    );
    public_search_compound_assert(
        $normal('A380 AND LKPR') === [['A380', 'LKPR']],
        'Explicit uppercase AND must not become a term.',
    );
    public_search_compound_assert(
        $normal("O'Connor") === [["O'Connor"]],
        'Unquoted internal apostrophes must remain literal.',
    );
    public_search_compound_assert(
        $normal('„A380 LKPR“') === [['A380 LKPR']],
        'Czech quotation marks must denote a phrase.',
    );
    foreach ([
        '"A380 LKPR"',
        "'A380 LKPR'",
        '“A380 LKPR”',
        '”A380 LKPR“',
        '«A380 LKPR»',
        '‹A380 LKPR›',
        '‚A380 LKPR‘',
        '‘A380 LKPR’',
        '「A380 LKPR」',
        '『A380 LKPR』',
    ] as $quoted) {
        public_search_compound_assert(
            $normal($quoted) === [['A380 LKPR']],
            'Phrase delimiter failed: ' . $quoted,
        );
    }
    public_search_compound_assert(
        $normal('A380 "LKPR arrival"') === [['A380', 'LKPR arrival']],
        'Mixed phrases and independent terms must remain distinguishable.',
    );
    public_search_compound_assert(
        $normal('"A380 LKPR') === [['A380', 'LKPR']],
        'Unfinished quoted input must remain usable during incremental typing.',
    );
    public_search_compound_assert(
        $normal(str_repeat('x ', 13)) === [],
        'Unbounded compound query terms must fail closed rather than silently widen the results.',
    );
    public_search_compound_assert(
        public_search_model_like_pattern('A_3%') === '%A\_3\%%',
        'User-supplied wildcard characters must stay escaped.',
    );

    if (
        !class_exists(\PDO::class)
        || !in_array('sqlite', \PDO::getAvailableDrivers(), true)
    ) {
        echo
            "public_search_compound_test: SQLite extension unavailable, parser contracts passed; SQL fixture skipped.\n";
        exit(0);
    }

    $db = new \PDO('sqlite::memory:');
    $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    $GLOBALS['public_search_compound_fixture_db'] = $db;
    foreach ([
        'CREATE TABLE galleries (id INTEGER PRIMARY KEY, title TEXT, description TEXT, visibility TEXT, access_listing TEXT, folder_path TEXT)',
        'CREATE TABLE tags (id INTEGER PRIMARY KEY, name TEXT, description TEXT)',
        'CREATE TABLE gallery_tags (gallery_id INTEGER, tag_id INTEGER)',
        'CREATE TABLE images (id INTEGER PRIMARY KEY, gallery_id INTEGER, visibility TEXT, filename TEXT, title TEXT, description TEXT)',
        'CREATE TABLE image_tags (image_id INTEGER, tag_id INTEGER)',
        'CREATE TABLE gallery_translations (gallery_id INTEGER, language_code TEXT, title TEXT, description TEXT)',
        'CREATE TABLE image_translations (image_id INTEGER, language_code TEXT, title TEXT, description TEXT)',
        'CREATE TABLE image_ai_metadata (image_id INTEGER, searchable_text TEXT)',
    ] as $sql) {
        $db->exec($sql);
    }
    $galleries = [
        [1, 'A380 spotting', '', 'public', 'listed', 'Flights/Prague'],
        [2, 'A380 standalone', '', 'public', 'listed', 'Flights/Prague'],
        [3, 'LKPR A380 at sunset', '', 'public', 'listed', 'Flights/Prague'],
        [4, 'A380 LKPR arrival', '', 'public', 'listed', 'Flights/Prague'],
        [
            5,
            'A380 approach',
            'Near LKPR arrival',
            'public',
            'listed',
            'Flights/Prague',
        ],
        [
            6,
            'LKPR overview',
            'A380 aircraft',
            'public',
            'listed',
            'Flights/Prague',
        ],
        [7, 'A380 secret LKPR', '', 'private', 'listed', 'Flights/Prague'],
        [8, 'A380 unlisted LKPR', '', 'public', 'unlisted', 'Flights/Prague'],
        [9, 'A380 localized', '', 'public', 'listed', 'Flights/Prague'],
        [
            10,
            'A380 deep localization',
            '',
            'public',
            'listed',
            'Flights/Prague',
        ],
        [11, 'LKPR elsewhere A380', '', 'public', 'listed', 'Other'],
        [12, 'A380 photography', '', 'public', 'listed', 'Flights/Prague'],
        [13, 'Flight LKPR', '', 'public', 'listed', 'Flights/Prague'],
        [14, 'B737 aircraft', '', 'public', 'listed', 'Flights/Prague'],
        [15, 'Piper Petrovi', '', 'public', 'listed', 'Flights/Prague'],
    ];
    $insert = $db->prepare('INSERT INTO galleries VALUES (?, ?, ?, ?, ?, ?)');
    foreach ($galleries as $gallery) {
        $insert->execute($gallery);
    }
    $db->exec(
        "INSERT INTO tags VALUES (1, 'LKPR', ''), (2, 'A380', ''), (3, 'flight', 'LKPR airport')",
    );
    $db->exec('INSERT INTO gallery_tags VALUES (1, 1), (12, 1), (13, 2)');
    $db->exec(
        "INSERT INTO gallery_translations VALUES (9, 'cs', 'Airbus LKPR', ''), (10, 'cs', '', 'Arrival at LKPR')",
    );
    $db->exec("INSERT INTO images VALUES
        (1, 1, 'public', 'A380-takeoff.jpg', '', ''),
        (2, 1, 'public', 'A380-only.jpg', '', ''),
        (3, 1, 'public', 'LKPR-A380.jpg', '', ''),
        (4, 1, 'public', 'A380-climb.jpg', '', 'LKPR arrival'),
        (5, 1, 'private', 'A380 LKPR private.jpg', '', ''),
        (6, 7, 'public', 'A380 LKPR inaccessible.jpg', '', ''),
        (7, 1, 'public', 'A380-translated.jpg', '', ''),
        (8, 1, 'public', 'LKPR-tagged.jpg', '', ''),
        (9, 1, 'public', 'A380-ai.jpg', '', '')");
    $db->exec('INSERT INTO image_tags VALUES (1, 1), (8, 2)');
    $db->exec(
        "INSERT INTO image_translations VALUES (7, 'cs', '', 'LKPR translated caption')",
    );
    $db->exec("INSERT INTO image_ai_metadata VALUES (9, 'LKPR runway')");

    // Reported real-world regression: quoted contiguous text works, but the
    // unquoted AND form must find the same gallery irrespective of term order.
    public_search_compound_assert(
        in_array(
            15,
            public_search_compound_test_galleries('piper petrovi'),
            true,
        ),
        'Piper Petrovi must match two unquoted terms in the same gallery title.',
    );
    public_search_compound_assert(
        in_array(
            15,
            public_search_compound_test_galleries('petrovi piper'),
            true,
        ),
        'Unquoted AND must not enforce title word order.',
    );
    public_search_compound_assert(
        in_array(
            15,
            public_search_compound_test_galleries('PIPER, PETROVI'),
            true,
        ),
        'Mixed-case comma-separated AND must preserve title matching.',
    );
    public_search_compound_assert(
        in_array(
            15,
            public_search_compound_test_galleries('"piper petrovi"'),
            true,
        ),
        'Quoted original word order must match the contiguous phrase.',
    );
    public_search_compound_assert(
        !in_array(
            15,
            public_search_compound_test_galleries('"petrovi piper"'),
            true,
        ),
        'Quoted reversed phrase must not match the original title.',
    );

    $primary = public_search_compound_test_galleries('A380 LKPR');
    foreach ([1, 3, 4, 12, 13] as $id) {
        public_search_compound_assert(
            in_array($id, $primary, true),
            'Gallery cross-field AND missing gallery ' . $id,
        );
    }
    foreach ([2, 5, 6, 7, 8, 9, 10] as $id) {
        public_search_compound_assert(
            !in_array($id, $primary, true),
            'Primary phase returned a false positive ' . $id,
        );
    }
    public_search_compound_assert(
        public_search_compound_test_galleries('"A380 LKPR"') === [4],
        'Exact phrase must retain ordered contiguous substring semantics.',
    );
    public_search_compound_assert(
        in_array(
            2,
            public_search_compound_test_galleries('A380 OR LKPR'),
            true,
        ),
        'Explicit OR must include the alternative matching only A380.',
    );
    public_search_compound_assert(
        in_array(
            14,
            public_search_compound_test_galleries('A380 LKPR OR B737'),
            true,
        ),
        'OR groups must include the single-term alternative.',
    );
    public_search_compound_assert(
        in_array(
            5,
            public_search_compound_test_galleries('A380 LKPR', 'descriptive'),
            true,
        ),
        'Description+title matches must be available in the descriptive phase.',
    );
    public_search_compound_assert(
        in_array(
            6,
            public_search_compound_test_galleries('A380 LKPR', 'descriptive'),
            true,
        ),
        'Description+title terms must match in either order.',
    );
    public_search_compound_assert(
        in_array(
            9,
            public_search_compound_test_galleries(
                'A380 LKPR',
                'descriptive',
                true,
            ),
            true,
        ),
        'Translated gallery titles must be eligible in the descriptive phase.',
    );
    public_search_compound_assert(
        !in_array(
            9,
            public_search_compound_test_galleries(
                'A380 LKPR',
                'descriptive',
                false,
            ),
            true,
        ),
        'Translation fields must be gated by schema readiness.',
    );
    public_search_compound_assert(
        in_array(
            10,
            public_search_compound_test_galleries('A380 LKPR', 'deep', true),
            true,
        ),
        'Translated description+title must match in the deep phase.',
    );
    public_search_compound_assert(
        !in_array(
            10,
            public_search_compound_test_galleries('A380 LKPR', 'deep', false),
            true,
        ),
        'Deep translations must remain capability-gated.',
    );
    public_search_compound_assert(
        in_array(
            5,
            public_search_compound_test_galleries(
                'A380 "LKPR arrival"',
                'descriptive',
            ),
            true,
        ),
        'A phrase plus term must match attributes of the same gallery.',
    );
    $scoped = public_search_compound_test_galleries(
        'A380 LKPR',
        'primary',
        false,
        ['folder_path' => 'Flights'],
    );
    public_search_compound_assert(
        !in_array(11, $scoped, true),
        'Gallery context restriction must exclude unrelated branches.',
    );

    $media = public_search_compound_test_images('A380 LKPR', 'media');
    foreach ([1, 3, 8] as $id) {
        public_search_compound_assert(
            in_array($id, $media, true),
            'Media AND across filenames/tags missing photo ' . $id,
        );
    }
    foreach ([2, 4, 5, 6, 7, 9] as $id) {
        public_search_compound_assert(
            !in_array($id, $media, true),
            'Media must exclude deep/private/partial photo ' . $id,
        );
    }
    $deepImages = public_search_compound_test_images(
        'A380 LKPR',
        'deep',
        true,
        true,
    );
    foreach ([4, 7, 9] as $id) {
        public_search_compound_assert(
            in_array($id, $deepImages, true),
            'Deep photo field combination missing ' . $id,
        );
    }
    public_search_compound_assert(
        !in_array(5, $deepImages, true),
        'Private photos must not become eligible.',
    );
    public_search_compound_assert(
        !in_array(6, $deepImages, true),
        'Photos inside private galleries must not become eligible.',
    );
    public_search_compound_assert(
        !in_array(
            9,
            public_search_compound_test_images(
                'A380 LKPR',
                'deep',
                false,
                false,
            ),
            true,
        ),
        'Disabled AI metadata must not participate in search.',
    );

    echo "public_search_compound_test: ok\n";
}
