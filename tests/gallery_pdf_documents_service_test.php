<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/gallery_pdf_documents_service_test.php
 * Module Type: Regression Test
 * Purpose: Exercise gallery PDF storage and automatic usage reconciliation.
 * Responsibilities:
 *   - Verify exact-byte attachment and gallery-scoped resolution
 *   - Verify replacement and deletion require explicit confirmation
 *   - Verify backwards-compatible visibility defaults and index-state preservation
 *   - Verify durable bounded media-count kickoff, continuation, retry and completion
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

declare(strict_types=1);

namespace Gallery\Core {
    /**
     * Check whether one canonical filesystem path is beneath another.
     * @param string $root Canonical parent path.
     * @param string $candidate Canonical candidate path.
     * @return bool True when the candidate is a strict descendant of the root.
     */
    function path_inside(string $root, string $candidate): bool
    {
        $prefix = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        return str_starts_with($candidate, $prefix);
    }
}

namespace Gallery\Services {
    /**
     * Resolve one gallery-relative fixture path beneath the test galleries root.
     * @param string $relative Gallery-relative folder path.
     * @return string Absolute fixture path.
     */
    function gallery_abs_path(string $relative): string
    {
        return (
            $GLOBALS['gallery_pdf_documents_test_root']
                . DIRECTORY_SEPARATOR
                . $relative
        );
    }

    /**
     * Return the isolated test galleries directory.
     * @return string Absolute galleries root.
     */
    function galleries_root(): string
    {
        return $GLOBALS['gallery_pdf_documents_test_root'];
    }

    /**
     * Persist fixture-owned application settings without using installation DB.
     * @param string $key Canonical app-setting key.
     * @param string $value Serialized fixture value.
     * @return void Stores state in the isolated test process.
     */
    function set_app_setting(string $key, string $value): void
    {
        $GLOBALS['gallery_pdf_documents_settings'][$key] = $value;
    }

    /**
     * Supply an empty authoritative playlist for each isolated physical gallery.
     * @param array{id:int|string,folder_path:string} $gallery Physical fixture row.
     * @return array{tracks:list<array{id:string,sha256:string,hidden:bool,asset_id?:string}>,albums:list<array{id:string,album_id:int,hidden:bool}>} Known empty playlist state.
     */
    function gallery_music_state(array $gallery): array
    {
        return ['tracks' => [], 'albums' => []];
    }

    /**
     * Convert the fixture's empty playlist state to no projection rows.
     * @param array{tracks:list<array{id:string,sha256:string,hidden:bool,asset_id?:string}>,albums:list<array{id:string,album_id:int,hidden:bool}>} $state Validated fixture playlist.
     * @return list<array{source_id:string,sha256:?string,asset_id:?string,album_id:?int,hidden:bool}> Empty authoritative music snapshot.
     */
    function gallery_music_attachment_usage_items(array $state): array
    {
        return [];
    }

    /**
     * Acquire one local music lock for the bounded usage fixture.
     * @param array{id:int,folder_path:string} $gallery Physical fixture row.
     * @param bool $nonBlocking Whether the production caller requires no-wait acquisition.
     * @return resource|false Isolated lock handle, or false on acquisition failure.
     */
    function gallery_music_acquire_lock(array $gallery, bool $nonBlocking = false): mixed
    {
        if (!empty($GLOBALS['gallery_pdf_documents_usage_music_lock_busy'])) {
            return false;
        }
        $path = gallery_abs_path($gallery['folder_path'])
            . DIRECTORY_SEPARATOR . '.music-fixture.lock';
        $handle = fopen($path, 'c');
        if ($handle === false || !flock($handle, LOCK_EX | LOCK_NB)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            return false;
        }
        return $handle;
    }

    /**
     * Release one fixture music lock.
     * @param resource $handle Lock handle returned by the fixture adapter.
     * @return void Unlocks and closes the handle.
     */
    function gallery_music_release_lock(mixed $handle): void
    {
        if (is_resource($handle)) {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * Return an empty OFP manifest for fixture galleries.
     * @param array{id:int|string,folder_path:string} $gallery Physical fixture row.
     * @return array{} No registered OFP source exists in the fixture.
     */
    function simbrief_ofp_gallery_manifest(array $gallery): array
    {
        return [];
    }

    /**
     * Supply the isolated Maintenance Center cache directory.
     * @return string Absolute fixture-owned control directory.
     */
    function site_maintenance_cache_dir(): string
    {
        $directory = $GLOBALS['gallery_pdf_documents_test_root']
            . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR
            . 'site-maintenance';
        if (!is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        return $directory;
    }

    /**
     * Report no live updater job in the isolated attachment fixture.
     * @return null No updater owns the fixture.
     */
    function application_update_active_job(): ?array
    {
        return null;
    }
}

namespace Gallery\Models {
    /**
     * Read an isolated app-setting value without opening a database connection.
     * @param string $key Canonical setting key.
     * @return string|false Stored fixture value or missing setting.
     */
    function app_settings_model_get(string $key): string|false
    {
        return $GLOBALS['gallery_pdf_documents_settings'][$key] ?? false;
    }

    /**
     * Supply the sorted source-gallery page requested by the real bounded worker.
     * @param int $afterId Exclusive cursor.
     * @param int $limit Maximum candidates including one lookahead row.
     * @return list<array{id:int|string,title:string}> Stable fixture page.
     */
    function gallery_model_source_picker_page(int $afterId, int $limit): array
    {
        $GLOBALS['gallery_pdf_documents_usage_picker_calls'][] = [
            'after_id' => $afterId,
            'limit' => $limit,
        ];
        $rows = [];
        foreach ($GLOBALS['gallery_pdf_documents_usage_galleries'] as $id => $gallery) {
            if ($id > $afterId) {
                $rows[] = ['id' => $id, 'title' => 'Fixture gallery'];
            }
        }
        return array_slice($rows, 0, $limit);
    }

    /**
     * Find one physical fixture gallery by id.
     * @param int $galleryId Gallery identifier.
     * @return array{id:int,folder_path:string}|null Existing fixture row or absence.
     */
    function gallery_model_find_by_id(int $galleryId): ?array
    {
        if (in_array(
            $galleryId,
            $GLOBALS['gallery_pdf_documents_usage_missing_ids'],
            true,
        )) {
            return null;
        }
        return $GLOBALS['gallery_pdf_documents_usage_galleries'][$galleryId]
            ?? null;
    }

    /**
     * Mark a fixture snapshot dirty and return its generation token.
     * @param int $galleryId Physical gallery identifier.
     * @param string $kind Attachment kind.
     * @return string Fresh fixture generation token.
     */
    function gallery_model_attachment_usage_mark_dirty(int $galleryId, string $kind): string
    {
        $GLOBALS['gallery_pdf_documents_usage_dirty_calls'][] = [
            'gallery_id' => $galleryId,
            'kind' => $kind,
        ];
        return str_pad(
            dechex($galleryId),
            8,
            '0',
            STR_PAD_LEFT,
        ) . str_repeat($kind === 'pdf' ? 'a' : 'b', 24);
    }

    /**
     * Accept one complete fixture snapshot under its latest dirty generation.
     * @param int $galleryId Physical gallery identifier.
     * @param string $kind Attachment kind.
     * @param list<array{source_id:string,sha256:?string,asset_id:?string,album_id:?int,hidden:bool}> $items Authoritative fixture rows.
     * @param string $revision Dirty generation token.
     * @return bool True because this isolated adapter does not model concurrent writers.
     */
    function gallery_model_attachment_usage_replace(
        int $galleryId,
        string $kind,
        array $items,
        string $revision,
    ): bool {
        return true;
    }

    /**
     * Report no conflicting Maintenance Center mutation.
     * @param bool $requireKnown Whether unknown ownership must throw.
     * @return null No active owner in the isolated fixture.
     */
    function maintenance_center_model_mutation_owner(bool $requireKnown = false): ?array
    {
        return null;
    }
}

namespace {
    /**
     * Keep the service fixture's bounded batch one candidate beyond the
     * production worker page so its lookahead proves a continuation.
     * Type: int. Units: galleries. Scope: this isolated service test.
     * Consumers: the two-page bounded reconciliation scenario.
     * Rationale: one extra candidate distinguishes continuation from terminal.
     */
    const GALLERY_PDF_USAGE_FIXTURE_GALLERY_COUNT = 26;

    /**
     * Restrict disposable gallery folders to the fixture-owned temporary tree.
     * Type: int. Units: POSIX permissions. Scope: this isolated service test.
     * Consumers: temporary gallery directories and the private state lock parent.
     * Rationale: no fixture data is exposed to other local users.
     */
    const GALLERY_PDF_USAGE_FIXTURE_DIRECTORY_MODE = 0700;

    require_once __DIR__
        . '/support/gallery_attachment_usage_legacy_schema_fixture.php';
    require_once dirname(__DIR__) . '/app/services/gallery_pdf_documents.php';
    require_once dirname(__DIR__) . '/app/services/gallery_attachment_usage.php';
    require_once dirname(__DIR__) . '/app/release_file_policy.php';

    /**
     * Stop the service test with a clear failure when an expectation is false.
     * @param bool $condition Expected condition.
     * @param string $message Failure description.
     * @return void
     */
    function gallery_pdf_documents_test_assert(
        bool $condition,
        string $message,
    ): void {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    $root =
        sys_get_temp_dir()
        . DIRECTORY_SEPARATOR
        . 'gallery-pdf-documents-'
        . bin2hex(random_bytes(8));
    // Type: string. Units: absolute path. Scope: this disposable fixture.
    // Consumers: production safe-target guard for the worker state lock.
    // Rationale: bind all maintenance lock paths to this exact temporary root.
    define('CACHE_LIFECYCLE_TEST_ROOT', $root);
    $galleryDirectory = $root . DIRECTORY_SEPARATOR . 'gallery';
    if (!mkdir(
        $galleryDirectory,
        GALLERY_PDF_USAGE_FIXTURE_DIRECTORY_MODE,
        true,
    )) {
        throw new RuntimeException(
            'Could not create the isolated PDF documents test directory.',
        );
    }
    $GLOBALS['gallery_pdf_documents_test_root'] = $root;
    $GLOBALS['gallery_pdf_documents_settings'] = [];
    $GLOBALS['gallery_pdf_documents_usage_galleries'] = [];
    $GLOBALS['gallery_pdf_documents_usage_picker_calls'] = [];
    $GLOBALS['gallery_pdf_documents_usage_dirty_calls'] = [];
    $GLOBALS['gallery_pdf_documents_usage_missing_ids'] = [];
    $GLOBALS['gallery_pdf_documents_usage_music_lock_busy'] = false;
    $source = $root . DIRECTORY_SEPARATOR . 'first.pdf';
    $replacement = $root . DIRECTORY_SEPARATOR . 'replacement.pdf';
    $firstBytes = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n";
    $replacementBytes = "%PDF-1.7\n2 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n";
    file_put_contents($source, $firstBytes);
    file_put_contents($replacement, $replacementBytes);
    $gallery = ['id' => 7, 'folder_path' => 'gallery'];

    try {
        gallery_pdf_documents_test_assert(
            \Gallery\Services\presentation_gallery_attachment_usage_schema_status()['state']
                === 'missing',
            'The explicit legacy fixture adapter must confirm optional usage schema absence.',
        );
        $first = \Gallery\Services\gallery_pdf_documents_attach(
            $gallery,
            $source,
            'scan.pdf',
            'Original scan',
            'manually_supplied',
        );
        gallery_pdf_documents_test_assert(
            $first['size'] === strlen($firstBytes)
                && $first['sha256'] === hash('sha256', $firstBytes),
            'Attachment metadata must describe exact original bytes.',
        );
        gallery_pdf_documents_test_assert(
            $first['hidden'] === false
                && !\Gallery\Services\gallery_pdf_documents_hidden(
                    $gallery,
                    $first['id'],
                ),
            'New documents must default to visible in metadata and visibility reads.',
        );
        $resolved = \Gallery\Services\gallery_pdf_documents_read(
            $gallery,
            $first['id'],
        );
        gallery_pdf_documents_test_assert(
            is_array($resolved)
                && file_get_contents($resolved['path']) === $firstBytes
                && $resolved['metadata']['id'] === $first['id'],
            'Read must resolve the exact gallery-scoped original PDF.',
        );
        gallery_pdf_documents_test_assert(
            count(\Gallery\Services\gallery_pdf_documents_list($gallery)) === 1,
            'List must return the attached general document.',
        );

        $indexPath =
            $galleryDirectory
            . DIRECTORY_SEPARATOR
            . '.gallery-pdf-documents'
            . DIRECTORY_SEPARATOR
            . 'index.json';
        $legacyIndex = json_decode(
            (string) file_get_contents($indexPath),
            true,
        );
        unset(
            $legacyIndex['documents'][$first['id']]['hidden'],
            $legacyIndex['ofp_hidden'],
        );
        file_put_contents($indexPath, json_encode($legacyIndex));
        $legacyRead = \Gallery\Services\gallery_pdf_documents_read(
            $gallery,
            $first['id'],
        );
        gallery_pdf_documents_test_assert(
            is_array($legacyRead)
                && $legacyRead['metadata']['hidden'] === false
                && !\Gallery\Services\gallery_pdf_documents_hidden(
                    $gallery,
                    $first['id'],
                )
                && !\Gallery\Services\gallery_pdf_documents_hidden(
                    $gallery,
                    'ofp',
                ),
            'Records written before visibility metadata existed must remain visible by default.',
        );

        gallery_pdf_documents_test_assert(
            \Gallery\Services\gallery_pdf_documents_set_hidden(
                $gallery,
                $first['id'],
                true,
            )
                && \Gallery\Services\gallery_pdf_documents_hidden(
                    $gallery,
                    $first['id'],
                ),
            'Manual PDF visibility must be hideable through the persisted index.',
        );
        gallery_pdf_documents_test_assert(
            \Gallery\Services\gallery_pdf_documents_set_hidden(
                $gallery,
                $first['id'],
                false,
            )
                && !\Gallery\Services\gallery_pdf_documents_hidden(
                    $gallery,
                    $first['id'],
                ),
            'Manual PDF visibility must be restorable through the persisted index.',
        );
        gallery_pdf_documents_test_assert(
            \Gallery\Services\gallery_pdf_documents_set_ofp_hidden(
                $gallery,
                true,
            )
                && \Gallery\Services\gallery_pdf_documents_hidden(
                    $gallery,
                    'ofp',
                ),
            'Legacy OFP visibility must be stored in the gallery PDF index.',
        );
        $additional = \Gallery\Services\gallery_pdf_documents_attach(
            $gallery,
            $source,
            'additional.pdf',
            'Additional scan',
            'manually_supplied',
        );
        gallery_pdf_documents_test_assert(
            \Gallery\Services\gallery_pdf_documents_hidden($gallery, 'ofp'),
            'Attaching a manual PDF must preserve the independent OFP visibility flag.',
        );
        gallery_pdf_documents_test_assert(
            \Gallery\Services\gallery_pdf_documents_delete(
                $gallery,
                $additional['id'],
                true,
            )
                && \Gallery\Services\gallery_pdf_documents_hidden(
                    $gallery,
                    'ofp',
                ),
            'Deleting a manual PDF must preserve the independent OFP visibility flag.',
        );

        $replacementConfirmationRequired = false;
        try {
            \Gallery\Services\gallery_pdf_documents_attach(
                $gallery,
                $replacement,
                'updated.pdf',
                'Updated scan',
                'manually_supplied',
                $first['id'],
            );
        } catch (RuntimeException $exception) {
            $replacementConfirmationRequired = str_contains(
                $exception->getMessage(),
                'confirmation',
            );
        }
        gallery_pdf_documents_test_assert(
            $replacementConfirmationRequired,
            'Replacement must be refused until explicit confirmation is supplied.',
        );
        $updated = \Gallery\Services\gallery_pdf_documents_attach(
            $gallery,
            $replacement,
            'updated.pdf',
            'Updated scan',
            'manually_supplied',
            $first['id'],
            true,
        );
        gallery_pdf_documents_test_assert(
            $updated['id'] === $first['id']
                && $updated['sha256'] === hash('sha256', $replacementBytes)
                && $updated['hidden'] === false
                && \Gallery\Services\gallery_pdf_documents_hidden(
                    $gallery,
                    'ofp',
                ),
            'Replacement must preserve the stable id and manual visibility, and retain the separate OFP flag.',
        );
        $resolvedUpdated = \Gallery\Services\gallery_pdf_documents_read(
            $gallery,
            $first['id'],
        );
        gallery_pdf_documents_test_assert(
            is_array($resolvedUpdated)
                && file_get_contents($resolvedUpdated['path'])
                    === $replacementBytes,
            'Replacement must preserve the uploaded original bytes.',
        );
        gallery_pdf_documents_test_assert(
            \Gallery\Services\gallery_pdf_documents_set_hidden(
                $gallery,
                $first['id'],
                true,
            ),
            'The replacement record must remain eligible for visibility changes.',
        );
        $hiddenReplacement = \Gallery\Services\gallery_pdf_documents_attach(
            $gallery,
            $source,
            'hidden-replacement.pdf',
            'Hidden scan',
            'manually_supplied',
            $first['id'],
            true,
        );
        gallery_pdf_documents_test_assert(
            $hiddenReplacement['hidden'] === true
                && \Gallery\Services\gallery_pdf_documents_hidden(
                    $gallery,
                    $first['id'],
                )
                && \Gallery\Services\gallery_pdf_documents_hidden(
                    $gallery,
                    'ofp',
                ),
            'Replacing a hidden PDF must preserve its hidden state and the independent OFP flag.',
        );
        $resolvedHiddenReplacement = \Gallery\Services\gallery_pdf_documents_read(
            $gallery,
            $first['id'],
        );
        gallery_pdf_documents_test_assert(
            is_array($resolvedHiddenReplacement)
                && file_get_contents($resolvedHiddenReplacement['path'])
                    === $firstBytes,
            'Replacing a hidden PDF must preserve the newly uploaded original bytes.',
        );

        $deleteConfirmationRequired = false;
        try {
            \Gallery\Services\gallery_pdf_documents_delete(
                $gallery,
                $first['id'],
            );
        } catch (RuntimeException $exception) {
            $deleteConfirmationRequired = str_contains(
                $exception->getMessage(),
                'confirmation',
            );
        }
        gallery_pdf_documents_test_assert(
            $deleteConfirmationRequired,
            'Deletion must be refused until explicit confirmation is supplied.',
        );
        gallery_pdf_documents_test_assert(
            \Gallery\Services\gallery_pdf_documents_delete(
                $gallery,
                $first['id'],
                true,
            ),
            'Confirmed deletion must remove the document.',
        );
        gallery_pdf_documents_test_assert(
            \Gallery\Services\gallery_pdf_documents_read($gallery, $first['id'])
                === null
                && \Gallery\Services\gallery_pdf_documents_list($gallery) === []
                && \Gallery\Services\gallery_pdf_documents_hidden(
                    $gallery,
                    'ofp',
                ),
            'Deleting a manual PDF must remove it while preserving the OFP visibility flag.',
        );

        // Report all optional objects present so the real bounded worker can
        // run through fixture model adapters without a SQL connection.
        \Gallery\Services\schema_inspection_set_query_executor_for_tests(
            static fn(string $objectType, string $table, string $object): bool => true,
        );
        // Background identities start at 100, separate from the original PDF
        // fixture gallery; the extra gallery forces a genuine second batch.
        for (
            $offset = 0;
            $offset < GALLERY_PDF_USAGE_FIXTURE_GALLERY_COUNT;
            $offset++
        ) {
            // Start at 100 so the 25-row page ends at 124 and leaves 125.
            $galleryId = 100 + $offset;
            $folder = 'usage-gallery-' . $galleryId;
            mkdir(
                $root . DIRECTORY_SEPARATOR . $folder,
                GALLERY_PDF_USAGE_FIXTURE_DIRECTORY_MODE,
            );
            $GLOBALS['gallery_pdf_documents_usage_galleries'][$galleryId] = [
                'id' => $galleryId,
                'folder_path' => $folder,
            ];
        }
        $initialDispatches = [];
        gallery_pdf_documents_test_assert(
            \Gallery\Services\gallery_attachment_usage_background_due(),
            'An absent durable receipt must admit the initial automatic scan.',
        );
        $initialQueue = \Gallery\Services\gallery_attachment_usage_background_start(
            static function (string $ticket) use (&$initialDispatches): bool {
                $initialDispatches[] = $ticket;
                $state = json_decode(
                    (string) \Gallery\Models\app_settings_model_get(
                        \Gallery\Services\GALLERY_ATTACHMENT_USAGE_BACKGROUND_STATE_SETTING,
                    ),
                    true,
                );
                return is_array($state)
                    && $state['status'] === 'queued'
                    && hash_equals($state['ticket'], $ticket);
            },
        );
        gallery_pdf_documents_test_assert(
            $initialQueue['ok']
                && $initialQueue['queued']
                && !$initialQueue['completed']
                && count($initialDispatches) === 1
                && preg_match('/^[a-f0-9]{64}$/D', $initialDispatches[0]) === 1,
            'Initial admission must persist one opaque ticket before dispatch.',
        );
        $duplicateDispatches = 0;
        $duplicateQueue = \Gallery\Services\gallery_attachment_usage_background_start(
            static function (string $ticket) use (&$duplicateDispatches): bool {
                $duplicateDispatches++;
                return true;
            },
        );
        gallery_pdf_documents_test_assert(
            !$duplicateQueue['queued']
                && $duplicateQueue['reason'] === 'already_queued'
                && $duplicateDispatches === 0
                && !\Gallery\Services\gallery_attachment_usage_background_due(),
            'A live durable lease must suppress another kickoff and dispatch.',
        );
        $firstWorkerDispatches = [];
        $firstWorker = \Gallery\Services\gallery_attachment_usage_background_run(
            $initialDispatches[0],
            static function (string $ticket) use (&$firstWorkerDispatches): bool {
                $firstWorkerDispatches[] = $ticket;
                $state = json_decode(
                    (string) \Gallery\Models\app_settings_model_get(
                        \Gallery\Services\GALLERY_ATTACHMENT_USAGE_BACKGROUND_STATE_SETTING,
                    ),
                    true,
                );
                return is_array($state)
                    && $state['status'] === 'queued'
                    && hash_equals($state['ticket'], $ticket);
            },
        );
        $firstState = json_decode(
            (string) \Gallery\Models\app_settings_model_get(
                \Gallery\Services\GALLERY_ATTACHMENT_USAGE_BACKGROUND_STATE_SETTING,
            ),
            true,
        );
        gallery_pdf_documents_test_assert(
            $firstWorker['ok']
                && $firstWorker['queued']
                && !$firstWorker['completed']
                && $firstWorker['scanned'] === 25
                && count($firstWorkerDispatches) === 1
                && $firstState['status'] === 'queued'
                // IDs 100 through 124 are exactly one worker's bounded page.
                && $firstState['cursor_gallery_id'] === 124
                && $GLOBALS['gallery_pdf_documents_usage_picker_calls'] === [
                    ['after_id' => 0, 'limit' => 26],
                ],
            'One worker request must process at most 25 galleries and persist its continuation before dispatch.',
        );
        $staleWorker = \Gallery\Services\gallery_attachment_usage_background_run(
            $initialDispatches[0],
            static fn(string $ticket): bool => false,
        );
        gallery_pdf_documents_test_assert(
            !$staleWorker['ok']
                && $staleWorker['reason'] === 'stale_ticket'
                && count($GLOBALS['gallery_pdf_documents_usage_picker_calls']) === 1,
            'A superseded ticket must not repeat an already checkpointed slice.',
        );
        $secondWorker = \Gallery\Services\gallery_attachment_usage_background_run(
            $firstWorkerDispatches[0],
            static fn(string $ticket): bool => false,
        );
        $completedState = json_decode(
            (string) \Gallery\Models\app_settings_model_get(
                \Gallery\Services\GALLERY_ATTACHMENT_USAGE_BACKGROUND_STATE_SETTING,
            ),
            true,
        );
        gallery_pdf_documents_test_assert(
            $secondWorker['ok']
                && $secondWorker['done']
                && $secondWorker['completed']
                && $secondWorker['scanned'] === 26
                && $secondWorker['ready'] === 26
                && $completedState['status'] === 'complete'
                && $completedState['completed_at'] > 0
                // Each clean gallery marks its PDF and music projections dirty.
                && count($GLOBALS['gallery_pdf_documents_usage_dirty_calls']) === 52
                && $GLOBALS['gallery_pdf_documents_usage_picker_calls'] === [
                    ['after_id' => 0, 'limit' => 26],
                    ['after_id' => 124, 'limit' => 26],
                ],
            'The resumed worker must process the remaining gallery once and complete only after the clean terminal slice.',
        );
        $repeatDispatches = 0;
        $repeatQueue = \Gallery\Services\gallery_attachment_usage_background_start(
            static function (string $ticket) use (&$repeatDispatches): bool {
                $repeatDispatches++;
                return true;
            },
        );
        gallery_pdf_documents_test_assert(
            !\Gallery\Services\gallery_attachment_usage_background_due()
                && $repeatQueue['completed']
                && !$repeatQueue['queued']
                && $repeatDispatches === 0,
            'A completed installation must not enqueue another automatic scan.',
        );
        $GLOBALS['gallery_pdf_documents_settings'][
            \Gallery\Services\GALLERY_ATTACHMENT_USAGE_BACKGROUND_STATE_SETTING
        ] = '';
        $GLOBALS['gallery_pdf_documents_usage_galleries'] = [
            100 => ['id' => 100, 'folder_path' => 'usage-gallery-100'],
        ];
        $GLOBALS['gallery_pdf_documents_usage_music_lock_busy'] = true;
        $stalledTickets = [];
        $stalledQueue = \Gallery\Services\gallery_attachment_usage_background_start(
            static function (string $ticket) use (&$stalledTickets): bool {
                $stalledTickets[] = $ticket;
                return true;
            },
        );
        $continuationCalls = 0;
        $stalledWorker = \Gallery\Services\gallery_attachment_usage_background_run(
            $stalledTickets[0] ?? '',
            static function (string $ticket) use (&$continuationCalls): bool {
                $continuationCalls++;
                return true;
            },
        );
        $stalledState = json_decode(
            (string) \Gallery\Models\app_settings_model_get(
                \Gallery\Services\GALLERY_ATTACHMENT_USAGE_BACKGROUND_STATE_SETTING,
            ),
            true,
        );
        gallery_pdf_documents_test_assert(
            $stalledQueue['queued']
                && $stalledWorker['ok']
                && !$stalledWorker['queued']
                && $stalledWorker['reason'] === 'source_busy'
                && $stalledState['status'] === 'retry'
                && $stalledState['cursor_gallery_id'] === 0
                && $stalledState['scanned'] === 0
                && $stalledState['next_attempt_at'] > time()
                && $continuationCalls === 0,
            'A source-lock deferral with no cursor progress must back off instead of chaining empty worker requests.',
        );
        $GLOBALS['gallery_pdf_documents_usage_music_lock_busy'] = false;
        $GLOBALS['gallery_pdf_documents_settings'][
            \Gallery\Services\GALLERY_ATTACHMENT_USAGE_BACKGROUND_STATE_SETTING
        ] = '';
        $GLOBALS['gallery_pdf_documents_usage_galleries'] = [
            200 => ['id' => 200, 'folder_path' => 'usage-gallery-100'],
        ];
        $GLOBALS['gallery_pdf_documents_usage_missing_ids'] = [200];
        $failedTickets = [];
        $failedQueue = \Gallery\Services\gallery_attachment_usage_background_start(
            static function (string $ticket) use (&$failedTickets): bool {
                $failedTickets[] = $ticket;
                return true;
            },
        );
        gallery_pdf_documents_test_assert(
            $failedQueue['queued'] && count($failedTickets) === 1,
            'A terminal-failure scenario must begin from one accepted worker ticket.',
        );
        $failedWorker = \Gallery\Services\gallery_attachment_usage_background_run(
            $failedTickets[0] ?? '',
            static fn(string $ticket): bool => true,
        );
        $failedState = json_decode(
            (string) \Gallery\Models\app_settings_model_get(
                \Gallery\Services\GALLERY_ATTACHMENT_USAGE_BACKGROUND_STATE_SETTING,
            ),
            true,
        );
        gallery_pdf_documents_test_assert(
            $failedWorker['ok']
                && !$failedWorker['completed']
                && $failedState['status'] === 'retry'
                && $failedState['completed_at'] === 0
                && $failedState['next_attempt_at'] > time()
                && !\Gallery\Services\gallery_attachment_usage_background_due(),
            'A failed terminal pass must schedule retry and never publish completion.',
        );
        $GLOBALS['gallery_pdf_documents_settings'][
            \Gallery\Services\GALLERY_ATTACHMENT_USAGE_BACKGROUND_STATE_SETTING
        ] = '';
        $lostDispatch = \Gallery\Services\gallery_attachment_usage_background_start(
            static fn(string $ticket): bool => false,
        );
        $lostState = json_decode(
            (string) \Gallery\Models\app_settings_model_get(
                \Gallery\Services\GALLERY_ATTACHMENT_USAGE_BACKGROUND_STATE_SETTING,
            ),
            true,
        );
        gallery_pdf_documents_test_assert(
            !$lostDispatch['ok']
                && $lostDispatch['reason'] === 'dispatch_failed'
                && $lostState['status'] === 'retry'
                && $lostState['ticket'] === ''
                && $lostState['completed_at'] === 0
                && $lostState['next_attempt_at'] > time(),
            'A lost initial handoff must retire its ticket into retry state without recording completion.',
        );

        \Gallery\Services\schema_inspection_set_query_executor_for_tests(
            static function (
                string $objectType,
                string $table,
                string $object,
            ): bool {
                throw new PDOException(
                    'Fixture metadata inspection is unavailable.',
                );
            },
        );
        $unknownSchemaRejected = false;
        try {
            \Gallery\Services\gallery_pdf_documents_attach(
                $gallery,
                $source,
                'unknown-schema.pdf',
                'Unknown schema case',
                'manually_supplied',
            );
        } catch (\Gallery\Services\PresentationSchemaUnavailableException $exception) {
            $unknownSchemaRejected = $exception->state() === 'unknown';
        }
        gallery_pdf_documents_test_assert(
            $unknownSchemaRejected
                && \Gallery\Services\gallery_pdf_documents_list($gallery) === []
                && file_get_contents($source) === $firstBytes,
            'Unknown schema must refuse before installing a PDF or altering the uploaded source.',
        );
    } finally {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $root,
                FilesystemIterator::SKIP_DOTS,
            ),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            $entry->isDir() && !$entry->isLink()
                ? rmdir($entry->getPathname())
                : unlink($entry->getPathname());
        }
        rmdir($root);
    }

    echo "PASS gallery PDF documents service lifecycle\n";
}
