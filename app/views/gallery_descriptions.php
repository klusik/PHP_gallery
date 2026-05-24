<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/views/gallery_descriptions.php
 * Module Type: View Module
 *
 * Purpose:
 *   Presents gallery descriptions as safe public Markdown excerpts and HTML.
 *
 * Responsibilities:
 *   - Keep Markdown rendering out of the description-layout model
 *   - Preserve the existing small Markdown subset
 *   - Return safe HTML fragments for public gallery templates
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
 *   2026-05-24
 */

declare(strict_types=1);

function view_gallery_description_utf8_excerpt(string $text, int $limit): string
{
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

function view_gallery_description_markdown_excerpt(string $markdown, int $limit = 360): string
{
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
    $excerpt = view_gallery_description_utf8_excerpt($normalized, max(1, $limit - 6));
    $excerpt = preg_replace('/[ \t]+\S*$/u', '', $excerpt) ?: $excerpt;
    return rtrim($excerpt, " \t\n\r\0\x0B.,;:") . ' (...)';
}

function view_gallery_description_markdown_html(string $markdown): string
{
    $normalized = trim(str_replace(["\r\n", "\r"], "\n", $markdown));
    if ($normalized === '') {
        return '';
    }
    $paragraphs = preg_split('/\n{2,}/u', $normalized) ?: [$normalized];
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
            $href = html_entity_decode((string) $matches[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            return '<a href="' . e($href) . '" rel="noopener noreferrer" target="_blank">' . $matches[1] . '</a>';
        }, $escaped) ?? $escaped;
        $html[] = '<p>' . str_replace("\n", '<br>', $escaped) . '</p>';
    }
    return implode('', $html);
}
