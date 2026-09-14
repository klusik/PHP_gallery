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
 * Render the searchable gallery destination picker.
 *
 * @param array<string,mixed> $viewModel Controller-prepared picker state.
 * @return string Complete picker markup.
 */
function view_render_gallery_search_picker(array $viewModel): string
{
    $fieldName = (string) ($viewModel['field_name'] ?? '');
    $pickerId = (string) ($viewModel['picker_id'] ?? 'gallery-picker');
    $listId = (string) ($viewModel['list_id'] ?? ($pickerId . '-list'));
    $hiddenValue = (string) ($viewModel['hidden_value'] ?? '');
    $inputValue = (string) ($viewModel['input_value'] ?? '');
    $prefillValue = (string) ($viewModel['prefill_value'] ?? '');
    $placeholder = (string) ($viewModel['placeholder'] ?? '');
    $hiddenAttributes = is_array($viewModel['hidden_attributes'] ?? null) ? $viewModel['hidden_attributes'] : [];
    $rows = is_array($viewModel['rows'] ?? null) ? $viewModel['rows'] : [];

    $html = '<div class="gallery-search-picker" data-gallery-search-picker data-search-delay="200">';
    $html .= '<input type="hidden"' . ($fieldName !== '' ? ' name="' . e($fieldName) . '"' : '') . ' value="' . e($hiddenValue) . '" data-gallery-search-picker-value';
    foreach ($hiddenAttributes as $name => $value) {
        if (!is_string($name) || preg_match('/^data-[a-z0-9_-]+$/i', $name) !== 1) {
            continue;
        }
        $html .= ' ' . $name . '="' . e((string) $value) . '"';
    }
    $html .= '>';
    $html .= '<div class="gallery-search-picker-field">';
    $html .= '<input id="' . e($pickerId) . '" type="text" value="' . e($inputValue) . '" placeholder="' . e($placeholder) . '" autocomplete="off" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="' . e($listId) . '" data-gallery-search-picker-input data-prefill-value="' . e($prefillValue) . '">';
    $html .= '<button type="button" class="gallery-search-picker-clear" data-gallery-search-picker-clear aria-label="' . e((string) ($viewModel['clear_label'] ?? '')) . '">×</button>';
    $html .= '</div>';
    $html .= '<div id="' . e($listId) . '" class="gallery-search-picker-menu" role="listbox" data-gallery-search-picker-menu hidden>';
    $html .= '<p class="gallery-search-picker-empty" data-gallery-search-picker-empty hidden>' . e((string) ($viewModel['empty_label'] ?? '')) . '</p>';
    foreach ($rows as $index => $row) {
        if (!is_array($row)) {
            continue;
        }
        $optionId = $pickerId . '-option-' . $index;
        $html .= '<button id="' . e($optionId) . '" type="button" class="gallery-search-picker-option" role="option" data-gallery-search-picker-option data-gallery-id="' . (int) ($row['id'] ?? 0) . '" data-gallery-label="' . e((string) ($row['label'] ?? '')) . '" data-gallery-title="' . e((string) ($row['title'] ?? '')) . '" data-gallery-path="' . e((string) ($row['path'] ?? '')) . '" data-gallery-search="' . e((string) ($row['search'] ?? '')) . '" style="--gallery-picker-depth: ' . min(max(0, (int) ($row['depth'] ?? 0)), 8) . ';">';
        $html .= '<span class="gallery-search-picker-option-title">' . e((string) ($row['title'] ?? '')) . '</span>';
        $html .= '<span class="gallery-search-picker-option-path">' . e((string) ($row['path_label'] ?? '')) . '</span>';
        $html .= '</button>';
    }
    $html .= '</div>';
    $html .= '<small class="gallery-search-picker-help" data-gallery-search-picker-help>' . e((string) ($viewModel['help_label'] ?? '')) . '</small>';
    $html .= '</div>';
    return $html;
}
