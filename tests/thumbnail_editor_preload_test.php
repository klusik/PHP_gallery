<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/thumbnail_editor_preload_test.php
 * Module Type: Regression Test
 * Purpose: Prove exact existing-thumbnail preload never falls back to larger resources or generation.
 * Responsibilities: Exercise the real read-only resolver and preserve ordinary preview fallback.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);
namespace Gallery\Services {
    /** Admit the synthetic source identity.
     * @param array{id:int} $image Fixture image.
     * @return bool True for the owned source.
     */
    function thumbnail_legacy_identity_owned(array $image): bool
    {
        return true;
    }

    /** Return supported fixture derivative sizes.
     * @return list<int> Small table and large preview sizes.
     */
    function thumbnail_sizes(): array
    {
        return [300, 900];
    }

    /** Return supported fixture derivative formats.
     * @return list<string> Supported formats.
     */
    function thumbnail_policy_requested_formats(): array
    {
        return ['jpg', 'webp'];
    }

    /** Resolve a test-owned derivative and record exact candidate probes.
     * @param array{id:int} $image Fixture identity.
     * @param array{folder_path:string} $gallery Fixture ownership.
     * @param int $size Requested derivative size in pixels.
     * @param string $format Requested derivative format.
     * @return string Confined fixture path.
     */
    function thumbnail_abs_path(
        array $image,
        array $gallery,
        int $size,
        string $format,
    ): string {
        $GLOBALS['preload_probes'][] = [$size, $format];
        return $GLOBALS['preload_fixture'] . '/' . $size . '.' . $format;
    }
}

namespace Gallery\Services {
    /** Keep ordinary visual-preview request semantics in the fixture.
     * @param array<string,string> $query Synthetic request parameters.
     * @return bool True for ordinary preview compatibility coverage.
     */
    function public_visual_preview_is_active(array $query): bool
    {
        return true;
    }
}

namespace {
    require_once dirname(__DIR__) . '/app/services/thumbnail_generation.php';
    require_once dirname(__DIR__) . '/app/controllers/public_media.php';
    if (
        !function_exists('imagecreatetruecolor')
        || !function_exists('imagejpeg')
    ) {
        echo
            "SKIP: GD JPEG encoding is unavailable for exact derivative fixtures.\n";
        exit(0);
    }
    $base = dirname(__DIR__) . '/cache';
    if (!is_dir($base)) {
        mkdir($base, 0777, true);
    }
    $fixture = $base . '/editor-preload-' . bin2hex(random_bytes(8));
    mkdir($fixture);
    $GLOBALS['preload_fixture'] = $fixture;
    $GLOBALS['preload_probes'] = [];
    $image = ['id' => 1];
    $gallery = ['folder_path' => 'fixture'];
    /** Require one exact-resource postcondition.
     * @param bool $condition Observed behavior.
     * @param string $message Failure diagnosis.
     * @return void Throw for an unsafe resource decision.
     */
    function editor_thumbnail_assert(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new \RuntimeException($message);
        }
    }

    try {
        file_put_contents($fixture . '/900.jpg', 'synthetic larger derivative');
        file_put_contents($fixture . '/300.webp', 'synthetic alternate format');
        $_GET = ['existing_only' => '1'];
        $result = \Gallery\Controllers\cms_resolve_thumbnail_response_file(
            $image,
            $gallery,
            300,
            'jpg',
        );
        editor_thumbnail_assert(
            $result === null && $GLOBALS['preload_probes'] === [[300, 'jpg']],
            'Strict preload cannot inspect or select larger/alternate resources.',
        );
        $tiny = imagecreatetruecolor(1, 1);
        imagejpeg($tiny, $fixture . '/300.jpg');
        imagedestroy($tiny);
        $GLOBALS['preload_probes'] = [];
        $result = \Gallery\Controllers\cms_resolve_thumbnail_response_file(
            $image,
            $gallery,
            300,
            'jpg',
        );
        editor_thumbnail_assert(
            $result !== null
                && $result['path'] === $fixture . '/300.jpg'
                && $GLOBALS['preload_probes'] === [[300, 'jpg']],
            'Strict preload returns only the requested existing derivative.',
        );
        unlink($fixture . '/300.jpg');
        unlink($fixture . '/300.webp');
        $_GET = [];
        $result = \Gallery\Controllers\cms_resolve_thumbnail_response_file(
            $image,
            $gallery,
            300,
            'jpg',
        );
        editor_thumbnail_assert(
            $result !== null && $result['path'] === $fixture . '/900.jpg',
            'Ordinary visual-preview nearest-existing fallback remains compatible.',
        );
        $source = file_get_contents(
            dirname(__DIR__) . '/app/services/thumbnail_sources.php',
        );
        $start = strpos($source, 'function thumbnail_editor_preload_url');
        $urlOwner = substr(
            $source,
            $start,
            strpos($source, 'function thumbnail_editor_preview_url', $start)
                - $start,
        );
        editor_thumbnail_assert(
            str_contains($urlOwner, "'size' => 300")
                && str_contains($urlOwner, "'existing_only' => 1")
                && !str_contains($urlOwner, 'image_public_media_url')
                && !str_contains($urlOwner, 'thumbnail_url('),
            'Preload URL never enters original-media fallback or filesystem enumeration.',
        );
    } finally {
        foreach (['300.jpg', '300.webp', '900.jpg'] as $file) {
            if (is_file($fixture . '/' . $file)) {
                unlink($fixture . '/' . $file);
            }
        }
        rmdir($fixture);
    }
    echo
        "PASS: exact read-only thumbnail preload and ordinary preview compatibility.\n";
}
