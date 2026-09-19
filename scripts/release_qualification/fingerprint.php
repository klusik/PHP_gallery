<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: scripts/release_qualification/fingerprint.php
 * Module Type: Release Qualification Content Identity
 *
 * Purpose:
 *   Hashes the actual release inputs without trusting timestamps or the manifest.
 *
 * Responsibilities:
 *   - Discover source changes, additions, deletions and rebuilt PDF bytes
 *
 * Author:
 *   Rudolf Klusal
 * Contact:
 *   https://github.com/klusik
 * License:
 *   MIT License (see LICENSE file in repository)
 * Notes:
 *   - Keep comments and docstrings intact when modifying this file.
 * Last Updated:
 *   2026-09-20
 */

declare(strict_types=1);

namespace PhpGallery\ReleaseQualification;

use RuntimeException;
use function PhpGallery\Release\detect_cms_version;
use function PhpGallery\Release\valid_version;

/** Encode stable, readable local evidence JSON. */
function encode(array $value): string
{
    return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
}

/** Exclude installation-specific state and compiler outputs, never release source. */
function excluded_path(string $path): bool
{
    if (in_array($path, ['config.php', 'app/bootstrap/config.php', 'public/assets/custom.css'], true)) {
        return true;
    }
    return preg_match('~^(?:cache|data|galleries|logs)/|^winapp/http_monitor_logs/~', $path) === 1
        || preg_match('~(^|/)(?:\.git|\.codex|\.agent|\.agent-local|\.claude|node_modules|__pycache__)(/|$)~', $path) === 1
        || preg_match('~(?:\.(?:log|tmp|aux|idx|ilg|ind|toc|out|fls|fdb_latexmk|pyc|pyo)|\.synctex\.gz)$~i', $path) === 1
        || in_array(basename($path), ['Thumbs.db', '.DS_Store', 'error_log'], true);
}

/** Reject resolved paths outside this checkout, including Windows junction targets. */
function assert_local_path(string $root, string $path): void
{
    $resolvedRoot = realpath($root);
    $resolvedPath = realpath($path);
    if ($resolvedRoot === false) {
        throw new RuntimeException('Qualification root does not exist.');
    }
    if ($resolvedPath === false) {
        return;
    }
    $base = str_replace('\\', '/', $resolvedRoot) . '/';
    $target = str_replace('\\', '/', $resolvedPath) . '/';
    if (PHP_OS_FAMILY === 'Windows') {
        $base = strtolower($base);
        $target = strtolower($target);
    }
    if (!str_starts_with($target, $base)) {
        throw new RuntimeException('Qualification path escapes the repository.');
    }
}

/** Read every byte of one regular input; linked inputs are not qualified. */
function content_hash(string $path): string
{
    if (is_link($path) || !is_file($path)) {
        throw new RuntimeException('Qualification input is missing or linked: ' . $path);
    }
    $hash = hash_file('sha256', $path);
    if ($hash === false) {
        throw new RuntimeException('Unable to hash qualification input: ' . $path);
    }
    return $hash;
}

/**
 * Discover a conservative release surface, independent of Git and manifest freshness.
 * Raw bytes and sorted relative names make additions/deletions and same-size edits visible.
 */
function snapshot(string $root, string $version): array
{
    if (!valid_version($version) || detect_cms_version($root) !== $version) {
        throw new RuntimeException('Target version must match CMS_VERSION in app/bootstrap.php.');
    }
    $files = [];
    $rootFiles = [
        '.htaccess', '.gitignore', '.gitattributes', 'index.php', 'install.php', 'reset.php',
        'setup-gallery.php', 'config.example.php', 'deploy.bat', 'README.md', 'PATCH_NOTES.md',
        'PATCH_NOTES_TEMPLATE.md', 'ARCHITECTURE.md', 'DATABASE.md', 'TESTING.md', 'CODEMAP.md',
        'AGENTS.md', 'RELEASE.md', 'LICENSE', 'release-metadata.json',
    ];
    foreach ($rootFiles as $relative) {
        if (file_exists($root . '/' . $relative) || is_link($root . '/' . $relative)) {
            assert_local_path($root, $root . '/' . $relative);
            $files[$relative] = content_hash($root . '/' . $relative);
        }
    }
    foreach (['.github', 'app', 'database', 'public', 'scripts', 'docs', 'tests', 'winapp'] as $directory) {
        $absolute = $root . '/' . $directory;
        if (is_link($absolute)) {
            throw new RuntimeException('Qualification input directory is linked: ' . $directory);
        }
        if (!is_dir($absolute)) {
            continue;
        }
        assert_local_path($root, $absolute);
        $iterator = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($absolute, \FilesystemIterator::SKIP_DOTS),
            static function (\SplFileInfo $file) use ($root): bool {
                $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
                if (excluded_path($relative)) {
                    return false;
                }
                if ($file->isLink()) {
                    throw new RuntimeException('Qualification input is linked: ' . $relative);
                }
                assert_local_path($root, $file->getPathname());
                return true;
            }
        ));
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
                $files[$relative] = content_hash($file->getPathname());
            }
        }
    }
    foreach (['app/bootstrap.php', 'app/core-manifest.json', 'release-metadata.json',
        'docs/PHP_Gallery_Manual.tex', 'docs/PHP_Gallery_Manual.pdf'] as $required) {
        if (!isset($files[$required])) {
            throw new RuntimeException('Required qualification input is missing: ' . $required);
        }
    }
    ksort($files, SORT_STRING);
    $identity = ['scope_version' => SCOPE_VERSION, 'version' => $version, 'files' => $files];
    return $identity + ['fingerprint' => hash('sha256', encode($identity))];
}

/** Fail before writing evidence when the operator reviewed another snapshot. */
function assert_fingerprint(array $snapshot, string $expected): void
{
    if (!preg_match('/^[a-f0-9]{64}$/D', $expected) || !hash_equals($snapshot['fingerprint'], $expected)) {
        throw new RuntimeException('Reviewed fingerprint is stale or invalid. Run init/check and review the current artifacts.');
    }
}
