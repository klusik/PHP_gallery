<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/gallery_music_service_test.php
 * Module Type: Regression Test
 * Purpose: Exercise gallery music persistence with isolated real audio bytes.
 * Responsibilities: Verify validation, revisions, ordering, deletion and confinement.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Core {
    /**
     * Escape fixture view strings using the production HTML encoding boundary.
     * @param string $value Plain translated text or prepared URL.
     * @return string HTML-safe UTF-8 text.
     */
    function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Check canonical fixture containment without production configuration.
     * @param string $root Trusted fixture root.
     * @param string $path Candidate canonical path.
     * @return bool Whether the path is strictly beneath the root.
     */
    function path_inside(string $root, string $path): bool
    {
        return str_starts_with($path, $root . DIRECTORY_SEPARATOR);
    }
}
namespace Gallery\Services {
    /**
     * Resolve the fixture gallery path.
     * @param string $relative Fixture-relative gallery folder.
     * @return string Absolute fixture gallery path.
     */
    function gallery_abs_path(string $relative): string
    {
        return galleries_root() . '/' . $relative;
    }
    /**
     * Return the private temporary galleries root.
     * @return string Absolute root owned by this fixture.
     */
    function galleries_root(): string
    {
        return $GLOBALS['music_fixture_root'];
    }
    /**
     * Preserve translation identities in service refusal assertions.
     * @param string $key Translation key.
     * @return string The original translation key.
     */
    function t(string $key): string
    {
        return $key;
    }
}
namespace {
    require_once dirname(__DIR__) . '/app/services/gallery_music.php';

    /**
     * Assert one observed service behavior.
     * @param bool $condition Whether the required behavior was observed.
     * @param string $message Safe failure explanation.
     * @return void Throw when the assertion fails.
     */
    function music_assert(bool $condition, string $message): void
    {
        if (!$condition) throw new RuntimeException($message);
    }

    $root = sys_get_temp_dir() . '/gallery-music-' . bin2hex(random_bytes(8));
    $GLOBALS['music_fixture_root'] = $root;
    mkdir($root . '/one', 0700, true);
    mkdir($root . '/two', 0700);
    $gallery = ['id' => 1, 'folder_path' => 'one'];
    // Minimal PCM WAV: mono, 8-bit, 8000 Hz, eight silent samples. These are
    // format boundary vectors rather than operational timing/size policies.
    $samples = str_repeat("\x80", 8);
    $wav = 'RIFF' . pack('V', 36 + strlen($samples)) . 'WAVEfmt '
        . pack('VvvVVvv', 16, 1, 1, 8000, 8000, 1, 8)
        . 'data' . pack('V', strlen($samples)) . $samples;
    $source = $root . '/source.wav';
    file_put_contents($source, $wav);
    try {
        $initial = \Gallery\Services\gallery_music_playlist($gallery);
        music_assert(!$initial['enabled'] && $initial['tracks'] === [], 'New gallery must have music off.');
        $uploaded = \Gallery\Services\gallery_music_mutate(
            $gallery, $initial['revision'], 'upload', '', $source, 'first.wav'
        );
        $first = \Gallery\Services\gallery_music_playlist($gallery);
        $id = $first['tracks'][0]['id'];
        music_assert($uploaded === [
            'revision' => $first['revision'], 'track_id' => $id,
        ], 'Upload must return its exact committed identity and revision.');
        music_assert(!$first['tracks'][0]['downloadable'],
            'Every new original must initially forbid downloads.');
        $read = \Gallery\Services\gallery_music_track($gallery, $id);
        music_assert($read !== null && file_get_contents($read['path']) === $wav, 'Upload must retain exact audio bytes.');
        file_put_contents($read['path'], str_repeat('x', strlen($wav)));
        music_assert(\Gallery\Services\gallery_music_track($gallery, $id) === null,
            'Same-size tampering must not bypass the stored original digest.');
        file_put_contents($read['path'], $wav);
        music_assert(\Gallery\Services\gallery_music_track(['id' => 2, 'folder_path' => 'two'], $id) === null,
            'Track identity must not cross galleries.');
        music_assert(\Gallery\Services\gallery_music_track($gallery, '../source') === null, 'Traversal must be refused.');
        try {
            \Gallery\Services\gallery_music_mutate($gallery, $initial['revision'], 'settings', '', '', '', true);
            throw new LogicException('Stale writer was accepted.');
        } catch (RuntimeException $error) {
            music_assert($error->getMessage() === 'music.conflict', 'Stale revision must have a specific refusal.');
        }
        \Gallery\Services\gallery_music_mutate($gallery, $first['revision'], 'upload', '', $source, 'second.wav');
        $second = \Gallery\Services\gallery_music_playlist($gallery);
        $secondId = $second['tracks'][1]['id'];
        $download = \Gallery\Services\gallery_music_mutate(
            $gallery, $second['revision'], 'download', $id, '', '', false, true
        );
        $perTrack = \Gallery\Services\gallery_music_playlist($gallery);
        music_assert($download['revision'] === $perTrack['revision']
            && $download['track_id'] === null
            && array_column($perTrack['tracks'], 'downloadable') === [true, false],
            'Per-track permission must preserve every other track.');
        \Gallery\Services\gallery_music_mutate(
            $gallery, $perTrack['revision'], 'download_all', '', '', '', false, true
        );
        $allAllowed = \Gallery\Services\gallery_music_playlist($gallery);
        music_assert(array_column($allAllowed['tracks'], 'downloadable')
            === [true, true], 'Bulk permission must update all current tracks.');
        $later = \Gallery\Services\gallery_music_mutate(
            $gallery, $allAllowed['revision'], 'upload', '', $source, 'later.wav'
        );
        $withLater = \Gallery\Services\gallery_music_playlist($gallery);
        music_assert(array_column($withLater['tracks'], 'downloadable')
            === [true, true, false],
            'A later upload must not inherit a previous bulk permission.');
        $allAllowed = \Gallery\Services\gallery_music_mutate(
            $gallery, $later['revision'], 'delete', $later['track_id']
        );
        \Gallery\Services\gallery_music_mutate(
            $gallery, $allAllowed['revision'], 'download_all'
        );
        $second = \Gallery\Services\gallery_music_playlist($gallery);
        music_assert(array_column($second['tracks'], 'downloadable')
            === [false, false], 'Bulk revocation must remove every permission.');
        // The pre-permission v1 shape remains readable with a denied default.
        $legacy = $second;
        foreach ($legacy['tracks'] as &$track) unset($track['downloadable']);
        unset($track);
        \Gallery\Models\gallery_music_model_write(
            \Gallery\Services\gallery_music_directory($gallery), $legacy
        );
        music_assert(\Gallery\Services\gallery_music_playlist($gallery) === $second,
            'Legacy v1 records must never inherit download permission.');
        require_once dirname(__DIR__) . '/app/views/gallery_music.php';
        $viewTrack = $perTrack['tracks'][0] + [
            'url' => '/index.php?page=gallery_music&id=1&track=' . $id,
            'download_url' => '/index.php?download=1',
        ];
        $viewModel = [
            'gallery_id' => 1, 'editor' => false, 'action_url' => '/index.php',
            'csrf_html' => '', 'revision' => $second['revision'],
            'enabled' => false, 'error' => '', 'tracks' => [$viewTrack],
        ];
        ob_start();
        \Gallery\Views\view_render_gallery_music($viewModel);
        $publicHtml = (string) ob_get_clean();
        music_assert(str_contains($publicHtml, ' download title=')
            && str_contains($publicHtml, 'data-music-duration')
            && !str_contains($publicHtml, 'type="checkbox"'),
            'Public table must show allowed download/duration with no checkbox.');
        $viewModel['tracks'][0]['download_url'] = '';
        $viewModel['tracks'][0]['downloadable'] = false;
        ob_start();
        \Gallery\Views\view_render_gallery_music($viewModel);
        $lockedHtml = (string) ob_get_clean();
        music_assert(!str_contains($lockedHtml, ' download title='),
            'Locked tracks must not expose a public download control.');
        $viewModel['editor'] = true;
        ob_start();
        \Gallery\Views\view_render_gallery_music($viewModel);
        $adminHtml = (string) ob_get_clean();
        music_assert(str_contains($adminHtml, 'value="download_all"')
            && str_contains($adminHtml, 'value="download"')
            && str_contains($adminHtml, '<svg'),
            'Admin table must expose compact per-track and bulk permission icons.');
        \Gallery\Services\gallery_music_mutate($gallery, $second['revision'], 'previous', $secondId);
        $ordered = \Gallery\Services\gallery_music_playlist($gallery);
        music_assert(array_column($ordered['tracks'], 'id') === [$secondId, $id], 'Reorder must persist an exact permutation.');
        \Gallery\Services\gallery_music_mutate($gallery, $ordered['revision'], 'settings', '', '', '', true);
        $enabled = \Gallery\Services\gallery_music_playlist($gallery);
        music_assert($enabled['enabled'] && $enabled['tracks'] === $ordered['tracks'], 'Preference must preserve the playlist.');
        \Gallery\Services\gallery_music_mutate($gallery, $enabled['revision'], 'delete', $id);
        $deleted = \Gallery\Services\gallery_music_playlist($gallery);
        music_assert(array_column($deleted['tracks'], 'id') === [$secondId]
            && !file_exists($read['path']), 'Deletion must remove only the selected original.');
        // Fill the metadata count boundary without allocating 100 audio files;
        // upload admission must refuse before it installs any new original.
        $full = $deleted;
        $full['tracks'] = [];
        for ($position = 0; $position < \Gallery\Services\GALLERY_MUSIC_MAX_TRACKS; $position++) {
            $full['tracks'][] = array_replace($deleted['tracks'][0], [
                'id' => sprintf('%032x', $position + 1),
            ]);
        }
        $directory = \Gallery\Services\gallery_music_directory($gallery);
        \Gallery\Models\gallery_music_model_write($directory, $full);
        try {
            \Gallery\Services\gallery_music_mutate($gallery, $full['revision'], 'upload', '', $source, 'too-many.wav');
            throw new LogicException('Track-count quota was bypassed.');
        } catch (RuntimeException $error) {
            music_assert($error->getMessage() === 'music.quota', 'Count quota must refuse before publication.');
        }
        music_assert(\Gallery\Services\gallery_music_playlist($gallery) === $full,
            'Count refusal must preserve the complete saved playlist.');
        // Fill the aggregate byte boundary with metadata-only original sizes;
        // admission checks totals before touching any retained payload.
        $bytesFull = $deleted;
        $bytesFull['tracks'] = [];
        while (array_sum(array_column($bytesFull['tracks'], 'size')) < \Gallery\Services\GALLERY_MUSIC_TOTAL_BYTES) {
            // Preserve the model's canonical row shape; only quota-vector
            // identity and size differ from the real uploaded original.
            $bytesFull['tracks'][] = array_replace($deleted['tracks'][0], [
                'id' => sprintf('%032x', count($bytesFull['tracks']) + 1),
                'size' => \Gallery\Services\GALLERY_MUSIC_MAX_BYTES,
            ]);
        }
        \Gallery\Models\gallery_music_model_write($directory, $bytesFull);
        try {
            \Gallery\Services\gallery_music_mutate($gallery, $bytesFull['revision'], 'upload', '', $source, 'over-total.wav');
            throw new LogicException('Aggregate byte quota was bypassed.');
        } catch (RuntimeException $error) {
            music_assert($error->getMessage() === 'music.quota', 'Aggregate quota must refuse before publication.');
        }
        music_assert(\Gallery\Services\gallery_music_playlist($gallery) === $bytesFull,
            'Byte quota refusal must preserve the complete saved playlist.');
        \Gallery\Models\gallery_music_model_write($directory, $deleted);
        $oversize = $root . '/oversize.wav';
        $sparse = fopen($oversize, 'wb');
        // One byte beyond the production size boundary is the rejection vector.
        ftruncate($sparse, \Gallery\Services\GALLERY_MUSIC_MAX_BYTES + 1);
        fclose($sparse);
        try {
            \Gallery\Services\gallery_music_validate_source($oversize, 'oversize.wav');
            throw new LogicException('Per-file size quota was bypassed.');
        } catch (RuntimeException $error) {
            music_assert($error->getMessage() === 'music.invalid_file', 'Oversize source must be refused.');
        }
        file_put_contents($source, '<?php echo "not audio";');
        try {
            \Gallery\Services\gallery_music_validate_source($source, 'fake.mp3');
            throw new LogicException('Renamed executable was accepted.');
        } catch (RuntimeException $error) {
            music_assert($error->getMessage() === 'music.invalid_file', 'Content detection must refuse renamed files.');
        }
        $index = $root . '/one/.gallery-music/index.json';
        file_put_contents($index, '{broken');
        try {
            \Gallery\Services\gallery_music_mutate($gallery, $deleted['revision'], 'settings');
            throw new LogicException('Damaged index was silently replaced.');
        } catch (RuntimeException) {
            music_assert(file_get_contents($index) === '{broken', 'Invalid storage must remain intact.');
        }
        if (function_exists('symlink') && @symlink($root . '/one/.gallery-music', $root . '/two/.gallery-music')) {
            try {
                \Gallery\Services\gallery_music_playlist(['id' => 2, 'folder_path' => 'two']);
                throw new LogicException('Symlink storage was accepted.');
            } catch (RuntimeException) {
                music_assert(is_link($root . '/two/.gallery-music'), 'Refusal must preserve the symlink.');
            }
            unlink($root . '/two/.gallery-music');
        }
        // Inclusive and suffix boundaries, open ends, and overflow/multiple
        // range refusals exercise the actual stream selection policy.
        music_assert(\Gallery\Services\gallery_music_byte_range('bytes=2-', 5)
            === ['start' => 2, 'end' => 4, 'partial' => true], 'Open-ended range must end at the last original byte.');
        music_assert(\Gallery\Services\gallery_music_byte_range('bytes=-99', 5)
            === ['start' => 0, 'end' => 4, 'partial' => true], 'Large suffix must clamp to the whole original.');
        foreach (['bytes=1-0', 'bytes=-0', 'bytes=5-', 'bytes=0-1,3-4', 'bytes=999999999999999999999999-'] as $range) {
            music_assert(\Gallery\Services\gallery_music_byte_range($range, 5) === null,
                'Malformed or unsatisfiable range must be refused: ' . $range);
        }
        echo "PASS: gallery music exact bytes, revisions, ordering, validation and confinement\n";
    } finally {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,
            FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) {
            if ($entry->isDir() && !$entry->isLink()) rmdir($entry->getPathname());
            else unlink($entry->getPathname());
        }
        rmdir($root);
    }
}
