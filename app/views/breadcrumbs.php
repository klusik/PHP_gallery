<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/views/breadcrumbs.php
 * Module Type: View
 *
 * Purpose:
 *   Render accessible breadcrumb navigation from prepared presentation data.
 *
 * Responsibilities:
 *   - Render semantic navigation and ordered-list markup
 *   - Escape labels, accessible names, and preserved URLs
 *   - Keep visual separators in CSS rather than generated markup
 *
 * Author:
 *   Rudolf Klusal
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 */

declare(strict_types=1);

namespace Gallery\Views;

use function Gallery\Core\e;

/**
 * Render breadcrumb navigation using a controller-prepared view model.
 *
 * @param array{items?:list<array{label:string,url:?string,current:bool}>,aria_label?:string,style_class?:string} $viewModel Prepared breadcrumb items, accessible label, and registry style class.
 * @return void
 */
function view_render_breadcrumbs(array $viewModel): void
{
    $ariaLabel = (string) ($viewModel['aria_label'] ?? '');
    $styleClass = (string) ($viewModel['style_class'] ?? '');
    echo '<nav class="breadcrumbs ' . e($styleClass) . '" aria-label="' . e($ariaLabel) . '">';
    echo '<ol class="breadcrumbs__list">';

    foreach ((array) ($viewModel['items'] ?? []) as $item) {
        if (!is_array($item)) {
            continue;
        }

        $label = (string) ($item['label'] ?? '');
        if ($label === '') {
            continue;
        }

        $url = isset($item['url']) ? (string) $item['url'] : null;
        $current = !empty($item['current']);
        echo '<li class="breadcrumbs__item">';
        if ($current) {
            echo '<span class="breadcrumbs__current" aria-current="page">' . e($label) . '</span>';
        } elseif ($url !== null) {
            echo '<a class="breadcrumbs__link" href="' . e($url) . '">' . e($label) . '</a>';
        } else {
            echo '<span class="breadcrumbs__label">' . e($label) . '</span>';
        }
        echo '</li>';
    }

    echo '</ol>';
    echo '</nav>';
}

/**
 * Render a native radio-card selector with CSS previews for breadcrumb styles.
 *
 * @param array{field_name:string,label:string,current:string,options:list<array{value:string,label:string,preview:array{style_class?:string,items?:list<array{label:string,current?:bool}>}}>} $viewModel Prepared field identity, accessible legend, selected value, and presentation-only previews.
 * @return void Outputs the selector markup without looking up style policy or translating labels.
 */
function view_render_breadcrumb_style_picker(array $viewModel): void
{
    $fieldName = (string) ($viewModel['field_name'] ?? '');
    $label = (string) ($viewModel['label'] ?? '');
    $options = array_values(array_filter(
        (array) ($viewModel['options'] ?? []),
        static fn (mixed $option): bool => is_array($option)
            && is_string($option['value'] ?? null)
            && is_string($option['label'] ?? null)
            && is_array($option['preview'] ?? null)
    ));
    $selectedValue = (string) ($viewModel['current'] ?? '');
    $selectedIndex = 0;
    foreach ($options as $index => $option) {
        if ($option['value'] === $selectedValue) {
            $selectedIndex = $index;
            break;
        }
    }

    echo '<fieldset class="breadcrumb-style-picker">';
    echo '<legend class="breadcrumb-style-picker__label">' . e($label) . '</legend>';
    echo '<div class="breadcrumb-style-picker__grid">';
    foreach ($options as $index => $option) {
        $value = $option['value'];
        $optionLabel = $option['label'];
        $preview = $option['preview'];
        $styleClass = (string) ($preview['style_class'] ?? '');
        $items = (array) ($preview['items'] ?? []);

        echo '<label class="breadcrumb-style-picker__option">';
        echo '<input class="breadcrumb-style-picker__input" type="radio" name="' . e($fieldName)
            . '" value="' . e($value) . '"' . ($index === $selectedIndex ? ' checked' : '') . '>';
        echo '<span class="breadcrumb-style-picker__card">';
        echo '<span class="breadcrumb-style-picker__name">' . e($optionLabel) . '</span>';
        echo '<span class="breadcrumb-style-picker__preview" aria-hidden="true">';
        echo '<span class="breadcrumbs ' . e($styleClass) . '">';
        echo '<span class="breadcrumbs__list">';
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $itemLabel = (string) ($item['label'] ?? '');
            if ($itemLabel === '') {
                continue;
            }
            $isCurrent = !empty($item['current']);
            echo '<span class="breadcrumbs__item">';
            echo '<span class="' . ($isCurrent ? 'breadcrumbs__current' : 'breadcrumbs__link') . '">'
                . e($itemLabel) . '</span>';
            echo '</span>';
        }
        echo '</span></span></span></span></label>';
    }
    echo '</div></fieldset>';
}
