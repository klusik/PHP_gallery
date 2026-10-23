<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/gallery_publication_test.php
 * Module Type: Regression Test
 * Purpose: Exercise UTC validation, indexed admission and real guarded SQL.
 * Responsibilities: Keep schema/clock fixtures isolated from application data.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Core {
    /**
     * Return the isolated in-memory database owned by this test.
     * @return \PDO Disposable SQLite connection, never installation credentials.
     */
    function db(): \PDO
    {
        return $GLOBALS['publication_test_database'];
    }
}

namespace Gallery\Services {
    /**
     * Supply a controlled observation without querying installation metadata.
     * @param string $table Requested table identity.
     * @param string $column Requested column identity.
     * @return array{state:string} Explicit fixture schema state.
     */
    function schema_inspection_column(string $table, string $column): array
    {
        return ['state' => $GLOBALS['publication_test_schema']];
    }

    /**
     * Supply a controlled due-index observation.
     * @param string $table Requested table identity.
     * @param string $index Requested index identity.
     * @return array{state:string} Explicit fixture schema state.
     */
    function schema_inspection_index(string $table, string $index): array
    {
        return ['state' => $GLOBALS['publication_test_index']];
    }

    /**
     * Inspect the successful fixture state.
     * @param array{state:string} $result Controlled observation.
     * @return bool True for available schema only.
     */
    function schema_inspection_is_available(array $result): bool
    {
        return $result['state'] === 'available';
    }

    /**
     * Inspect conclusive absence in the fixture.
     * @param array{state:string} $result Controlled observation.
     * @return bool True for confirmed missing schema only.
     */
    function schema_inspection_is_missing(array $result): bool
    {
        return $result['state'] === 'missing';
    }

    /**
     * Inspect failed observation in the fixture.
     * @param array{state:string} $result Controlled observation.
     * @return bool True for unknown schema only.
     */
    function schema_inspection_is_unknown(array $result): bool
    {
        return $result['state'] === 'unknown';
    }

    /**
     * Keep validation failures independent of translation storage.
     * @param string $key Stable message key.
     * @return string The bounded key, with no database lookup.
     */
    function t(string $key): string
    {
        return $key;
    }

    /**
     * Capture the deferred work boundary; HTTP coverage exercises its real owner.
     * @param list<array{id:int|string,scheduled_publish_at:string}> $due Due identities.
     * @param string $nowUtc Visitor comparison time.
     * @return void Store only the number of admitted rows.
     */
    function gallery_editor_publish_due(array $due, string $nowUtc): void
    {
        $GLOBALS['publication_test_admitted'] = count($due);
    }
}

namespace {
    require_once dirname(__DIR__) . '/app/models/galleries.php';
    require_once dirname(__DIR__) . '/app/services/gallery_access.php';

    use function Gallery\Models\gallery_publication_model_consume;
    use function Gallery\Models\gallery_publication_model_due;
    use function Gallery\Services\gallery_publication_request;
    use function Gallery\Services\gallery_publication_schema_state;
    use function Gallery\Services\gallery_publication_manual_storage_available;
    use function Gallery\Services\gallery_publication_validate;
    use const Gallery\Services\GALLERY_PUBLICATION_BATCH_SIZE;

    /**
     * Refuse an incorrect regression result with a safe fixture-authored message.
     * @param bool $condition Required invariant.
     * @param string $message Non-secret assertion description.
     * @return void Throw on failure, otherwise continue.
     */
    function publicationCheck(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    $GLOBALS['publication_test_schema'] = 'available';
    $GLOBALS['publication_test_index'] = 'available';
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $GLOBALS['publication_test_database'] = $pdo;
    $pdo->exec(
        'CREATE TABLE galleries (id INTEGER PRIMARY KEY, visibility TEXT,'
        . ' scheduled_publish_at TEXT, edit_revision INTEGER, updated_at TEXT,'
        . ' access_listing TEXT, access_mode TEXT, nsfw_enabled INTEGER)',
    );
    $pdo->exec('CREATE INDEX idx_galleries_scheduled_publish_at ON galleries (scheduled_publish_at)');
    $now = new DateTimeImmutable('2030-03-30 12:00:00', new DateTimeZone('UTC'));
    publicationCheck(
        gallery_publication_validate('2030-03-31T02:30', null, $now)
            === '2030-03-31 02:30:00',
        'UTC input must survive a European DST gap without local conversion.',
    );
    publicationCheck(
        gallery_publication_validate('2030-10-27T02:30', null, $now)
            === '2030-10-27 02:30:00',
        'UTC input must remain unambiguous during a European DST overlap.',
    );
    foreach (['2030-02-30T12:00', '2030-03-30T12:00', '2029-01-01T00:00', 'bad'] as $invalid) {
        try {
            gallery_publication_validate($invalid, null, $now);
            throw new LogicException('Invalid or nonfuture input was accepted.');
        } catch (RuntimeException $exception) {
            publicationCheck(
                $exception->getMessage() === 'publication.invalid',
                'Unexpected validation refusal.',
            );
        }
    }
    publicationCheck(
        gallery_publication_validate('2029-01-01T00:00', '2029-01-01 00:00:00', $now)
            === '2029-01-01 00:00:00'
            && gallery_publication_validate('', null, $now) === null,
        'An unchanged pending deadline and explicit cancellation must remain valid.',
    );
    $insert = $pdo->prepare(
        "INSERT INTO galleries VALUES (?, 'private', ?, 1, 'before', 'unlisted', 'password', 1)",
    );
    $insert->execute([1, '2030-03-30 12:00:00']);
    publicationCheck(
        gallery_publication_model_due('2030-03-30 11:59:59', GALLERY_PUBLICATION_BATCH_SIZE) === [],
        'The indexed comparison must not select a gallery one second early.',
    );
    publicationCheck(
        count(gallery_publication_model_due('2030-03-30 12:00:00', GALLERY_PUBLICATION_BATCH_SIZE)) === 1,
        'The exact inclusive boundary must be selected.',
    );
    $pdo->exec("UPDATE galleries SET scheduled_publish_at = '2030-04-01 00:00:00', edit_revision = 2");
    publicationCheck(
        !gallery_publication_model_consume(1, '2030-03-30 12:00:00', '1', '2030-04-02 00:00:00', 'after'),
        'A stale in-flight runner must not override a reschedule.',
    );
    $pdo->exec('UPDATE galleries SET scheduled_publish_at = NULL, edit_revision = 3');
    publicationCheck(
        !gallery_publication_model_consume(1, '2030-04-01 00:00:00', '2', '2030-04-02 00:00:00', 'after'),
        'A stale in-flight runner must not override cancellation.',
    );
    $pdo->exec("UPDATE galleries SET scheduled_publish_at = '2030-04-01 00:00:00', edit_revision = 4");
    publicationCheck(
        gallery_publication_model_consume(1, '2030-04-01 00:00:00', '4', '2030-04-01 00:00:00', 'after')
            && !gallery_publication_model_consume(1, '2030-04-01 00:00:00', '4', '2030-04-01 00:00:00', 'again'),
        'One atomic transition must consume the deadline exactly once.',
    );
    $row = $pdo->query('SELECT * FROM galleries')->fetch(PDO::FETCH_ASSOC);
    publicationCheck(
        $row['visibility'] === 'public' && $row['scheduled_publish_at'] === null
            && (int) $row['edit_revision'] === 5 && $row['updated_at'] === 'after'
            && $row['access_listing'] === 'listed' && $row['access_mode'] === 'password'
            && (int) $row['nsfw_enabled'] === 1,
        'Publication must restore listing, preserve protection and advance revision/timestamp.',
    );
    $loads = [];
    $load = static function (string $module) use (&$loads): void {
        $loads[] = $module;
    };
    gallery_publication_request('GET', $load);
    publicationCheck($loads === [], 'An empty pending list must not load heavy owners.');
    // One more row than the actual policy bound tests admission, not elapsed time.
    for ($id = 2; $id <= GALLERY_PUBLICATION_BATCH_SIZE + 2; $id++) {
        $insert->execute([$id, '2000-01-01 00:00:00']);
    }
    $nextPage = gallery_publication_model_due(
        '2030-04-01 00:00:00',
        GALLERY_PUBLICATION_BATCH_SIZE,
        '2000-01-01 00:00:00',
        GALLERY_PUBLICATION_BATCH_SIZE + 1,
    );
    publicationCheck(
        count($nextPage) === 1
            && (int) $nextPage[0]['id'] === GALLERY_PUBLICATION_BATCH_SIZE + 2,
        'The indexed keyset must advance past a full blocked batch.',
    );
    gallery_publication_request('POST', $load);
    publicationCheck($loads === [], 'POST must not run visitor publication.');
    gallery_publication_request('HEAD', $load);
    publicationCheck(
        $loads === ['site-maintenance-work']
            && $GLOBALS['publication_test_admitted'] === GALLERY_PUBLICATION_BATCH_SIZE,
        'HEAD must admit only the bounded due batch.',
    );
    foreach (['missing', 'unknown'] as $state) {
        $GLOBALS['publication_test_index'] = $state;
        $loads = [];
        publicationCheck(gallery_publication_schema_state() === $state, 'Index state was discarded.');
        gallery_publication_request('GET', $load);
        publicationCheck($loads === [], 'Unverified index must not admit publication.');
        try {
            gallery_publication_validate('', null, $now);
            throw new LogicException('Unverified storage authorized a mutation.');
        } catch (RuntimeException $exception) {
            publicationCheck($exception->getMessage() === 'publication.unavailable', 'Wrong schema refusal.');
        }
    }
    $GLOBALS['publication_test_index'] = 'available';
    $GLOBALS['publication_test_schema'] = 'missing';
    publicationCheck(
        !gallery_publication_manual_storage_available(),
        'A confirmed old schema must retain manual-publication compatibility.',
    );
    $GLOBALS['publication_test_schema'] = 'unknown';
    try {
        gallery_publication_manual_storage_available();
        throw new LogicException('Unknown manual-publication storage was accepted.');
    } catch (RuntimeException $exception) {
        publicationCheck(
            $exception->getMessage() === 'publication.unavailable',
            'Manual publication must refuse unknown storage before side effects.',
        );
    }
    echo "PASS gallery publication UTC validation, schema, exact boundary, bounded admission and guarded SQL\n";
}
