<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/support/gallery_picker_legacy_view.php
 * Module Type: Test Fixture
 * Purpose: Preserve the pre-search picker renderer for before/after measurement.
 * Responsibilities:
 *   - Render only disposable benchmark data without becoming an application fallback.
 * Frozen pre-priority-10 picker renderer for synthetic before/after measurement.
 * Author: Rudolf Klusal
 * Source: app/views/admin_gallery_renderers.php before bounded destination search.
 * Never loaded by the application; uses the same disposable fixture as the new renderer.
 */
declare(strict_types=1);
namespace Gallery\Tests\PickerBaseline;
use function Gallery\Core\e;

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
