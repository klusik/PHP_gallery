<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/support/module_source.php
 * Module Type: Test Support
 *
 * Purpose:
 *   Reads a service module as a single source text even when the module is
 *   split into part files under a sibling directory.
 *
 * Responsibilities:
 *   - Return the module entry file concatenated with every part file it loads
 *   - Keep part ordering deterministic so before/after offset checks stay stable
 *   - Behave identically for modules that were never split
 *
 * Author:
 *   Rudolf Klusal
 *
 * Contact:
 *   https://github.com/klusik
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 *
 * Notes:
 *   - Source contracts describe module behavior, not the file it happens to live in.
 *   - Parts are appended in require_once order so ordering assertions remain meaningful.
 *
 * Last Updated:
 *   2026-09-06
 */

declare(strict_types=1);

/**
 * Return the full source text of a module, including its split part files.
 *
 * Part files are appended in the order the module entry file requires them, so
 * assertions about one token appearing before another keep their meaning.
 *
 * @param string $path Absolute path to the module entry file.
 * @return string Concatenated module source.
 */
function module_source(string $path): string
{
    $entry = @file_get_contents($path);
    if (!is_string($entry)) {
        return '';
    }

    $partDirectory = dirname($path) . DIRECTORY_SEPARATOR . basename($path, '.php');
    if (!is_dir($partDirectory)) {
        return $entry;
    }

    $source = $entry;
    $matches = [];
    preg_match_all("#require_once __DIR__ \. '/([^']+)';#", $entry, $matches);
    foreach ($matches[1] as $relative) {
        $partPath = dirname($path) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        if (!str_starts_with($partPath, $partDirectory . DIRECTORY_SEPARATOR)) {
            continue;
        }
        $part = @file_get_contents($partPath);
        if (is_string($part)) {
            $source .= "\n" . $part;
        }
    }

    return $source;
}
