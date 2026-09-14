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
 *   2026-09-14
 */

declare(strict_types=1);

namespace Gallery\Views;

use function Gallery\Core\asset_url;
use function Gallery\Core\e;

/**
 * Handle view gallery description utf8 excerpt.
 *
 * Used by server-rendered view helpers.
 *
 * @param string $text Text value.
 * @param int $limit Maximum number of items.
 * @return string Text result for the caller.
 */
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

/**
 * Handle view gallery description markdown excerpt.
 *
 * Used by server-rendered view helpers.
 *
 * @param string $markdown Markdown value.
 * @param int $limit Maximum number of items.
 * @return string Text result for the caller.
 */
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

/**
 * Normalize a gallery-description link target to an allowed public URL.
 *
 * Gallery descriptions accept explicit HTTP(S) URLs and convenient www.
 * addresses. Other schemes are intentionally rejected so description markup
 * cannot create javascript:, data:, or similar executable links.
 *
 * @param string $url Raw URL text from description markup.
 * @return ?string Normalized HTTP(S) URL, or null when the target is unsafe.
 */
function view_gallery_description_link_url(string $url): ?string
{
    $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if ($url === '') {
        return null;
    }
    if (preg_match('/^www\./iu', $url) === 1) {
        $url = 'https://' . $url;
    }
    if (preg_match('/^https?:\/\/[^\s<>]+$/iu', $url) !== 1) {
        return null;
    }
    $parts = parse_url($url);
    if (!is_array($parts) || trim((string) ($parts['host'] ?? '')) === '') {
        return null;
    }
    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    if ($scheme !== 'http' && $scheme !== 'https') {
        return null;
    }
    return $url;
}

/**
 * Return whether a hostname is exactly a known domain or one of its subdomains.
 *
 * @param string $host Normalized lowercase hostname.
 * @param string $domain Canonical lowercase domain to test.
 * @return bool True when the hostname belongs to the domain.
 */
function view_gallery_description_link_host_matches(string $host, string $domain): bool
{
    return $host === $domain || str_ends_with($host, '.' . $domain);
}

/**
 * Resolve a known public website hostname to a local brand icon symbol.
 *
 * Matching is hostname-based rather than substring-based so an unrelated host
 * such as notyoutube.com cannot receive the YouTube icon accidentally.
 *
 * @param string $url Normalized HTTP(S) URL.
 * @return ?string Local SVG symbol id, or null for an unrecognized website.
 */
function view_gallery_description_link_icon_id(string $url): ?string
{
    $normalized = view_gallery_description_link_url($url);
    if ($normalized === null) {
        return null;
    }
    $parts = parse_url($normalized);
    if (!is_array($parts)) {
        return null;
    }
    $host = strtolower(rtrim(trim((string) ($parts['host'] ?? '')), '.'));
    if ($host === '') {
        return null;
    }

    static $icons = [
        'youtube' => ['youtube.com', 'youtu.be', 'youtube-nocookie.com'],
        'facebook' => ['facebook.com', 'fb.com', 'fb.me', 'fb.watch'],
        'twitter-x' => ['x.com', 'twitter.com', 't.co'],
        'instagram' => ['instagram.com', 'instagr.am'],
        'wikipedia' => ['wikipedia.org', 'wikimedia.org', 'wikidata.org'],
        'linkedin' => ['linkedin.com', 'lnkd.in'],
        'github' => ['github.com', 'githubusercontent.com'],
        'reddit' => ['reddit.com', 'redd.it'],
        'tiktok' => ['tiktok.com'],
        'discord' => ['discord.com', 'discord.gg'],
        'twitch' => ['twitch.tv'],
        'vimeo' => ['vimeo.com'],
    ];
    foreach ($icons as $iconId => $domains) {
        foreach ($domains as $domain) {
            if (view_gallery_description_link_host_matches($host, $domain)) {
                return $iconId;
            }
        }
    }
    return null;
}

/**
 * Render the optional local icon placed before a known external-link label.
 *
 * @param string $url Normalized HTTP(S) URL.
 * @return string Safe inline SVG markup, or an empty string for unknown hosts.
 */
function view_gallery_description_link_icon_html(string $url, array $linkModels = []): string
{
    $iconId = view_gallery_description_link_icon_id($url);
    if ($iconId !== null) {
        $spriteUrl = asset_url('assets/link-icons/brands.svg');
        return '<svg class="gallery-description-link-icon" aria-hidden="true" focusable="false" viewBox="0 0 16 16"><use href="' . e($spriteUrl . '#' . $iconId) . '"></use></svg>';
    }

    $cachedUrl = (string) (($linkModels[$url]['cached_url'] ?? '') ?: '');
    if ($cachedUrl === '') {
        return '';
    }
    return '<img class="gallery-description-link-icon gallery-description-link-favicon" src="' . e($cachedUrl) . '" alt="" aria-hidden="true" decoding="async">';
}

/**
 * Render one safe external gallery-description anchor.
 *
 * @param string $url Raw URL text from description markup.
 * @param string $label Already escaped/rendered link label.
 * @param string $fallback Original escaped markup returned for unsafe URLs.
 * @return string Safe anchor HTML or the unchanged fallback markup.
 */
function view_gallery_description_link_html(string $url, string $label, string $fallback, array $linkModels = []): string
{
    $href = view_gallery_description_link_url($url);
    if ($href === null) {
        return $fallback;
    }
    $iconHtml = view_gallery_description_link_icon_html($href, $linkModels);
    return '<a class="gallery-description-external-link" href="' . e($href) . '" rel="noopener noreferrer" target="_blank">' . $iconHtml . $label . '</a>';
}

/**
 * Handle view gallery description markdown html.
 *
 * Used by server-rendered view helpers.
 *
 * @param string $markdown Markdown value.
 * @return string Text result for the caller.
 */
function view_gallery_description_markdown_html(string $markdown, array $linkModels = []): string
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
        $escaped = preg_replace_callback('/\[(link|url)=([^\]\n]{1,2048})\]([^\n]*?)\[\/\1\]/iu', static function (array $matches) use ($linkModels): string {
            return view_gallery_description_link_html((string) $matches[2], (string) $matches[3], (string) $matches[0], $linkModels);
        }, $escaped) ?? $escaped;
        $escaped = preg_replace_callback('/\[(link|url)\]([^\[\]\n]{1,2048})\[\/\1\]/iu', static function (array $matches) use ($linkModels): string {
            $label = trim((string) $matches[2]);
            return view_gallery_description_link_html($label, $label, (string) $matches[0], $linkModels);
        }, $escaped) ?? $escaped;
        $escaped = preg_replace_callback('/\[([^\]\n]{1,160})\]\((https?:\/\/[^\s<>")]+)\)/iu', static function (array $matches) use ($linkModels): string {
            return view_gallery_description_link_html((string) $matches[2], (string) $matches[1], (string) $matches[0], $linkModels);
        }, $escaped) ?? $escaped;
        $html[] = '<p>' . str_replace("\n", '<br>', $escaped) . '</p>';
    }
    return implode('', $html);
}
