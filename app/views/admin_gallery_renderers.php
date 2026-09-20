<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/views/admin_gallery_renderers.php
 * Module Type: View Module
 *
 * Purpose:
 *   Renders reusable gallery select options, datalists, and searchable picker markup.
 *
 * Responsibilities:
 *   - Render controller-prepared select option rows
 *   - Render tag suggestion datalist markup
 *   - Render the searchable gallery destination picker from a prepared view model
 *   - Keep reusable gallery-form HTML out of controller compatibility helpers
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
 *   - This module does not perform gallery lookup, request parsing, or persistence.
 *
 * Last Updated:
 *   2026-09-13
 */

declare(strict_types=1);

namespace Gallery\Views;

use function Gallery\Core\e;

/**
 * Render a list of controller-prepared select options.
 *
 * @param array<int,array{value:int|string,label:string,selected?:bool}> $rows Prepared option rows.
 * @return string Rendered option markup.
 */
function view_render_admin_select_options(array $rows): string
{
    $html = '';
    foreach ($rows as $row) {
        $html .= '<option value="' . e((string) ($row['value'] ?? '')) . '"' . (!empty($row['selected']) ? ' selected' : '') . '>' . e((string) ($row['label'] ?? '')) . '</option>';
    }
    return $html;
}

/**
 * Render the shared tag-suggestion datalist.
 *
 * @param array<int,string> $names Controller-prepared tag names.
 */
function view_render_tag_datalist(array $names): void
{
    echo '<datalist id="tag-suggestions">';
    foreach ($names as $name) {
        echo '<option value="' . e($name) . '"></option>';
    }
    echo '</datalist>';
}

/**
 * Render the weighted-tag advice data attribute.
 *
 * @param array<int|string,mixed> $payload Controller-prepared suggestion payload.
 * @return string Escaped attribute or an empty string.
 */
function view_render_weighted_tag_suggestions_attribute(array $payload): string
{
    if ($payload === []) {
        return '';
    }
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return $json === false ? '' : ' data-tag-weighted-suggestions="' . e($json) . '"';
}

/**
 * Render a bounded destination picker and a real no-JavaScript ID fallback.
 *
 * @param array<string,mixed> $viewModel Prepared selection, rows, labels and URLs.
 * @return string Escaped picker markup.
 */
function view_render_gallery_search_picker(array $viewModel): string
{
    $fieldName = (string) ($viewModel['field_name'] ?? '');
    $pickerId = (string) ($viewModel['picker_id'] ?? 'gallery-picker');
    $listId = (string) ($viewModel['list_id'] ?? ($pickerId . '-list'));
    $hiddenValue = (string) ($viewModel['hidden_value'] ?? '');
    $inputValue = (string) ($viewModel['input_value'] ?? '');
    $html = '<div class="gallery-search-picker" data-gallery-search-picker data-search-url="' . e((string) ($viewModel['search_url'] ?? '')) . '"';
    foreach (['loading_label', 'error_label', 'empty_label', 'page_size', 'next_after_id'] as $key) {
        $html .= ' data-' . str_replace('_', '-', $key) . '="' . e((string) ($viewModel[$key] ?? '')) . '"';
    }
    $html .= '>';
    // JavaScript enables this field. The noscript ID input is its real fallback.
    $html .= '<input type="hidden" disabled' . ($fieldName !== '' ? ' name="' . e($fieldName) . '"' : '') . ' value="' . e($hiddenValue) . '" data-gallery-search-picker-value';
    foreach ((array) ($viewModel['hidden_attributes'] ?? []) as $name => $value) {
        if (is_string($name) && preg_match('/^data-[a-z0-9_-]+$/i', $name) === 1) {
            $html .= ' ' . $name . '="' . e((string) $value) . '"';
        }
    }
    $html .= '><div class="gallery-search-picker-field">';
    $html .= '<input id="' . e($pickerId) . '" type="text" value="' . e($inputValue) . '" placeholder="' . e((string) ($viewModel['placeholder'] ?? '')) . '" autocomplete="off" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="' . e($listId) . '" data-gallery-search-picker-input>';
    $html .= '<button type="button" class="gallery-search-picker-clear" data-gallery-search-picker-clear aria-label="' . e((string) ($viewModel['clear_label'] ?? '')) . '">&#215;</button></div>';
    $html .= '<div id="' . e($listId) . '" class="gallery-search-picker-menu" role="listbox" data-gallery-search-picker-menu hidden>';
    if (!empty($viewModel['allow_root'])) {
        $html .= '<button id="' . e($pickerId) . '-root" type="button" class="gallery-search-picker-option" role="option" aria-selected="false" data-gallery-search-picker-root data-gallery-search-picker-option data-gallery-id="0" data-gallery-label="' . e((string) $viewModel['root_label']) . '">' . e((string) $viewModel['root_label']) . '</button>';
    }
    foreach ((array) ($viewModel['rows'] ?? []) as $index => $row) {
        $html .= '<button id="' . e($pickerId) . '-option-' . (int) $index . '" type="button" class="gallery-search-picker-option" role="option" aria-selected="false" data-gallery-search-picker-option data-gallery-id="' . (int) $row['id'] . '" data-gallery-label="' . e((string) $row['label']) . '">';
        $html .= '<span class="gallery-search-picker-option-title">' . e((string) $row['title']) . '</span>';
        $html .= '<span class="gallery-search-picker-option-path">' . e((string) $row['path_label']) . ' (#' . (int) $row['id'] . ')</span></button>';
    }
    $html .= '</div><p role="status" aria-live="polite" class="gallery-search-picker-empty" data-gallery-search-picker-status hidden></p>';
    $html .= '<button type="button" data-gallery-search-picker-more hidden>' . e((string) ($viewModel['more_label'] ?? '')) . '</button>';
    $html .= '<small class="gallery-search-picker-help" data-gallery-search-picker-help>' . e((string) ($viewModel['help_label'] ?? '')) . '</small>';
    $html .= '<a href="' . e((string) ($viewModel['lookup_url'] ?? '')) . '" target="_blank" rel="noopener">' . e((string) ($viewModel['lookup_label'] ?? '')) . '</a>';
    if ($fieldName !== '') {
        $html .= '<noscript><p>' . e((string) ($viewModel['fallback_label'] ?? '')) . '</p><input type="number" step="1" min="' . (!empty($viewModel['allow_root']) ? '0' : '1') . '" name="' . e($fieldName) . '" value="' . e($hiddenValue) . '" aria-label="' . e((string) ($viewModel['fallback_label'] ?? '')) . '">';
        $html .= '<p>' . e($inputValue) . '</p></noscript>';
    }
    return $html . '</div>';
}

/**
 * Render the standalone authenticated directory lookup used without JavaScript.
 *
 * The administrator copies an ID back into the still-open unsaved form.
 *
 * @param array<string,mixed> $viewModel Prepared results, navigation and labels.
 * @return string Escaped HTML document with an ordinary GET search form.
 */
function view_render_gallery_picker_directory(array $viewModel): string
{
    $labels = $viewModel['labels'];
    $html = '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . e($labels['title']) . '</title></head><body><main>';
    $html .= '<h1>' . e($labels['title']) . '</h1><p>' . e($labels['help']) . '</p>';
    $html .= '<form method="get" action="' . e($viewModel['action_url']) . '">';
    foreach ($viewModel['parameters'] as $key => $value) {
        $html .= '<input type="hidden" name="' . e((string) $key) . '" value="' . e((string) $value) . '">';
    }
    $html .= '<label>' . e($labels['query']) . ' <input name="q" maxlength="' . (int) $viewModel['query_max_characters'] . '" value="' . e($viewModel['query']) . '"></label><button type="submit">' . e($labels['search']) . '</button></form>';
    if (!$viewModel['ok']) {
        $html .= '<p role="alert">' . e($labels['error']) . '</p>';
    }
    if ($viewModel['allow_root']) {
        $html .= '<p>' . e($labels['root']) . ': <strong>0</strong></p>';
    }
    $html .= '<table><thead><tr><th>' . e($labels['id']) . '</th><th>' . e($labels['gallery']) . '</th><th>' . e($labels['path']) . '</th></tr></thead><tbody>';
    foreach ($viewModel['rows'] as $row) {
        $html .= '<tr><td><strong>' . (int) $row['id'] . '</strong></td><td>' . e($row['title']) . '</td><td>/' . e($row['path']) . '</td></tr>';
    }
    $html .= '</tbody></table>';
    if ($viewModel['ok'] && $viewModel['rows'] === []) {
        $html .= '<p>' . e($labels['empty']) . '</p>';
    }
    if ($viewModel['next_url'] !== '') {
        $html .= '<a rel="next" href="' . e($viewModel['next_url']) . '">' . e($labels['more']) . '</a>';
    }
    return $html . '</main></body></html>';
}
