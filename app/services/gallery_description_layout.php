<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/gallery_description_layout.php
 * Module Type: Service
 *
 * Purpose:
 *   Resolves public gallery-card description layout settings and safe description rendering.
 *
 * Responsibilities:
 *   - Keep the global Theme default separate from optional per-gallery overrides
 *   - Normalize layout values before they reach templates or CSS classes
 *   - Render a small, safe Markdown subset for public gallery descriptions
 *   - Produce compact public-card excerpts while keeping full descriptions available on gallery pages
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
 *   - Keep comments and docstrings intact when modifying this file.
 *   - Prefer small, readable changes over broad rewrites.
 *
 * Last Updated:
 *   2026-05-11
 */

declare(strict_types=1);

namespace Gallery\Services;

use function Gallery\Core\e;
use function Gallery\Views\view_gallery_description_markdown_excerpt;
use function Gallery\Views\view_gallery_description_markdown_html;
use function Gallery\Views\view_gallery_description_utf8_excerpt;

/**
 * Return the public gallery-card description layouts supported by the renderer.
 *
 * @return array Structured result data for the caller.
 */
function gallery_description_layout_options(): array
{
    return ['vertical', 'horizontal'];
}

/**
 * Normalize a submitted or stored description-layout value.
 *
 * @param mixed $value Value to process.
 * @param string $fallback Fallback value.
 * @return string Text result for the caller.
 */
function gallery_description_layout_normalize(mixed $value, string $fallback = 'vertical'): string
{
    // $layout stores the lowercase database or form value before validation.
    $layout = strtolower(trim((string) $value));
    if (in_array($layout, gallery_description_layout_options(), true)) {
        return $layout;
    }
    return in_array($fallback, gallery_description_layout_options(), true) ? $fallback : 'vertical';
}

/**
 * Return true when per-gallery description-layout overrides can be stored.
 *
 * @return bool True when the condition matches.
 */
function gallery_description_layout_schema_ready(): bool
{
    return db_column_exists('galleries', 'description_layout');
}

/**
 * Return the global Theme fallback used by gallery cards without an override.
 *
 * @return string Text result for the caller.
 */
function theme_gallery_description_layout(): string
{
    return gallery_description_layout_normalize(app_setting('theme_gallery_description_layout', 'vertical'), 'vertical');
}

/**
 * Normalize a per-gallery override for database storage.
 *
 * A null return value means the gallery inherits the global Theme setting.
 *
 * @param mixed $value Value to process.
 * @return ?string Text result for the caller.
 */
function gallery_description_layout_storage_value(mixed $value): ?string
{
    // $layout stores the raw form value used by the Admin editor.
    $layout = strtolower(trim((string) $value));
    if ($layout === '' || $layout === 'inherit') {
        return null;
    }
    return in_array($layout, gallery_description_layout_options(), true) ? $layout : null;
}

/**
 * Return the description layout that should be used for one gallery card.
 *
 * @param array $gallery Gallery row or gallery data.
 * @return string Text result for the caller.
 */
function gallery_effective_description_layout(array $gallery): string
{
    if (gallery_description_layout_schema_ready()) {
        // $storedLayout stores the optional per-gallery override.
        $storedLayout = gallery_description_layout_storage_value($gallery['description_layout'] ?? null);
        if ($storedLayout !== null) {
            return $storedLayout;
        }
    }
    return theme_gallery_description_layout();
}

/**
 * Return a translated label for one layout value.
 *
 * @param string $layout Layout value.
 * @return string Text result for the caller.
 */
function gallery_description_layout_label(string $layout): string
{
    return match (gallery_description_layout_normalize($layout)) {
        'horizontal' => t('gallery.description_layout.horizontal', 'Horizontal system'),
        default => t('gallery.description_layout.vertical', 'Vertical system'),
    };
}

/**
 * Return a readable summary of the current source for Admin forms.
 *
 * @param array $gallery Gallery row or gallery data.
 * @return string Text result for the caller.
 */
function gallery_description_layout_source_label(array $gallery): string
{
    if (gallery_description_layout_schema_ready() && gallery_description_layout_storage_value($gallery['description_layout'] ?? null) !== null) {
        return t('admin.gallery_editor.description_layout_source_gallery', 'gallery override');
    }
    return t('admin.gallery_editor.description_layout_source_theme', 'Theme default');
}

/**
 * Trim a UTF-8 string without requiring mbstring on shared hosting.
 *
 * @param string $text Text value.
 * @param int $limit Maximum number of items.
 * @return string Text result for the caller.
 */
function gallery_description_utf8_excerpt(string $text, int $limit): string
{
    if (function_exists('Gallery\\Views\\view_gallery_description_utf8_excerpt')) {
        return view_gallery_description_utf8_excerpt($text, $limit);
    }
    if ($limit <= 0) {
        return '';
    }
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        if (mb_strlen($text, 'UTF-8') <= $limit) {
            return $text;
        }
        return (string) mb_substr($text, 0, $limit, 'UTF-8');
    }
    if (strlen($text) <= $limit) {
        return $text;
    }
    return substr($text, 0, $limit);
}

/**
 * Produce the shortened Markdown text used by public gallery cards.
 *
 * @param string $markdown Markdown value.
 * @param int $limit Maximum number of items.
 * @return string Text result for the caller.
 */
function gallery_description_markdown_excerpt(string $markdown, int $limit = 360): string
{
    if (function_exists('Gallery\\Views\\view_gallery_description_markdown_excerpt')) {
        return view_gallery_description_markdown_excerpt($markdown, $limit);
    }
    // $normalized stores the description with predictable line endings so user-entered newlines survive in cards.
    $normalized = trim(str_replace(["\r\n", "\r"], "\n", $markdown));
    if ($normalized === '') {
        return '';
    }
    if (function_exists('mb_strlen') && mb_strlen($normalized, 'UTF-8') <= $limit) {
        return $normalized;
    }
    if (!function_exists('mb_strlen') && strlen($normalized) <= $limit) {
        return $normalized;
    }
    // $excerpt stores a safe-length text preview before a visible continuation marker is appended.
    $excerpt = gallery_description_utf8_excerpt($normalized, max(1, $limit - 6));
    $excerpt = preg_replace('/[ \t]+\S*$/u', '', $excerpt) ?: $excerpt;
    return rtrim($excerpt, " \t\n\r\0\x0B.,;:") . ' (...)';
}

/**
 * Render a safe public-description subset of Markdown.
 *
 * Supported inline syntax: **bold**, __bold__, *italic*, _italic_, `code`, and http or https links.
 *
 * @param string $markdown Markdown value.
 * @return string Text result for the caller.
 */
function gallery_description_markdown_html(string $markdown): string
{
    if (function_exists('Gallery\\Views\\view_gallery_description_markdown_html')) {
        return view_gallery_description_markdown_html($markdown);
    }
    // $normalized stores line endings in one form before paragraph handling.
    $normalized = trim(str_replace(["\r\n", "\r"], "\n", $markdown));
    if ($normalized === '') {
        return '';
    }
    // $paragraphs stores prose separated by blank lines. Each paragraph is escaped before inline formatting is restored.
    $paragraphs = preg_split('/\n{2,}/u', $normalized) ?: [$normalized];
    // $html stores escaped and lightly formatted paragraph fragments.
    $html = [];
    foreach ($paragraphs as $paragraph) {
        $paragraph = trim((string) $paragraph);
        if ($paragraph === '') {
            continue;
        }
        $escaped = e($paragraph);
        $escaped = preg_replace('/`([^`\n]+)`/u', '<code>$1</code>', $escaped) ?? $escaped;
        $escaped = preg_replace('/\*\*([^*\n]+)\*\*/u', '<strong>$1</strong>', $escaped) ?? $escaped;
        $escaped = preg_replace('/__([^_\n]+)__/u', '<strong>$1</strong>', $escaped) ?? $escaped;
        $escaped = preg_replace('/(?<!\*)\*([^*\n]+)\*(?!\*)/u', '<em>$1</em>', $escaped) ?? $escaped;
        $escaped = preg_replace('/(?<!_)_([^_\n]+)_(?!_)/u', '<em>$1</em>', $escaped) ?? $escaped;
        $escaped = preg_replace_callback('/\[([^\]\n]{1,160})\]\((https?:\/\/[^\s<>")]+)\)/iu', static function (array $matches): string {
            // $href stores the decoded URL so it can be escaped specifically for the href attribute.
            $href = html_entity_decode((string) $matches[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            return '<a href="' . e($href) . '" rel="noopener noreferrer" target="_blank">' . $matches[1] . '</a>';
        }, $escaped) ?? $escaped;
        $html[] = '<p>' . str_replace("\n", '<br>', $escaped) . '</p>';
    }
    return implode('', $html);
}
