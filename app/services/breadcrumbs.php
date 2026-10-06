<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/breadcrumbs.php
 * Module Type: Service
 *
 * Purpose:
 *   Resolve breadcrumb presentation styles and prepare breadcrumb view models.
 *
 * Responsibilities:
 *   - Own the stable breadcrumb style registry and style inheritance rules
 *   - Read theme and physical-gallery style preferences
 *   - Prepare escaped-at-render-time labels and preserved URLs for the view
 *
 * Author:
 *   Rudolf Klusal
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 */

declare(strict_types=1);

namespace Gallery\Services;

/**
 * Stable site-wide fallback breadcrumb style.
 *
 * @var string
 * Units: Unitless stable style identifier.
 * Scope: Theme and physical-gallery breadcrumb resolution.
 * Consumers: Theme settings, gallery settings, and breadcrumb view-model preparation.
 * Rationale: Guarantees a supported presentation when persisted values are missing or obsolete.
 */
const BREADCRUMB_STYLE_DEFAULT = 'chevron';

/**
 * Stable sentinel for a physical gallery that inherits the Theme style.
 *
 * @var string
 * Units: Unitless stable setting value.
 * Scope: Physical-gallery breadcrumb override only; it is not a rendered style ID.
 * Consumers: Gallery settings validation, persistence, sidecars, and trash restore.
 * Rationale: Preserves Theme inheritance without storing a duplicate effective style.
 */
const BREADCRUMB_STYLE_INHERIT = 'inherit';

/**
 * Return the supported breadcrumb style metadata keyed by stable style ID.
 *
 * @return array<string,array{label:string,class:string}> Style labels and presentation classes.
 */
function breadcrumb_style_registry(): array
{
    return [
        'minimal' => [
            'label' => t('breadcrumbs.style.minimal', 'Minimal'),
            'class' => 'breadcrumbs--minimal',
        ],
        'chevron' => [
            'label' => t('breadcrumbs.style.chevron', 'Chevron'),
            'class' => 'breadcrumbs--chevron',
        ],
        'pills' => [
            'label' => t('breadcrumbs.style.pills', 'Pills'),
            'class' => 'breadcrumbs--pills',
        ],
        'surface' => [
            'label' => t('breadcrumbs.style.surface', 'Surface'),
            'class' => 'breadcrumbs--surface',
        ],
        'ribbon' => [
            'label' => t('breadcrumbs.style.ribbon', 'Ribbon'),
            'class' => 'breadcrumbs--ribbon',
        ],
        'nodes' => [
            'label' => t('breadcrumbs.style.nodes', 'Connected nodes'),
            'class' => 'breadcrumbs--nodes',
        ],
        'tabs' => [
            'label' => t('breadcrumbs.style.tabs', 'Tabs'),
            'class' => 'breadcrumbs--tabs',
        ],
        'tiles' => [
            'label' => t('breadcrumbs.style.tiles', 'Tiles'),
            'class' => 'breadcrumbs--tiles',
        ],
        'gradient' => [
            'label' => t('breadcrumbs.style.gradient', 'Gradient'),
            'class' => 'breadcrumbs--gradient',
        ],
    ];
}

/**
 * Prepare visual style choices with localized example paths and Theme inheritance.
 *
 * @param bool $allowInherit Whether to include the gallery inheritance choice.
 * @param string $inheritedStyle Effective Theme style shown by the inheritance example.
 * @return list<array{value:string,label:string,preview:array{items:list<array{label:string,url:?string,current:bool}>,aria_label:string,style_class:string}}> Prepared choices rendered by the shared style picker.
 */
function breadcrumb_style_picker_options(bool $allowInherit = false, string $inheritedStyle = BREADCRUMB_STYLE_DEFAULT): array
{
    $registry = breadcrumb_style_registry();
    $items = [
        ['label' => t('breadcrumbs.preview.home', 'Home')],
        ['label' => t('breadcrumbs.preview.gallery', 'Travel')],
        ['label' => t('breadcrumbs.preview.current', 'Photos'), 'current' => true],
    ];
    $options = [];
    if ($allowInherit) {
        $effectiveStyle = breadcrumb_style_normalize($inheritedStyle);
        $options[] = [
            'value' => BREADCRUMB_STYLE_INHERIT,
            'label' => t('admin.gallery_editor.breadcrumb_style_inherit', 'Inherit from Theme') . ' — ' . $registry[$effectiveStyle]['label'],
            'preview' => breadcrumb_view_model($items, $effectiveStyle, ''),
        ];
    }
    foreach ($registry as $styleId => $definition) {
        $options[] = [
            'value' => $styleId,
            'label' => $definition['label'],
            'preview' => breadcrumb_view_model($items, $styleId, ''),
        ];
    }
    return $options;
}

/**
 * Normalize a stored breadcrumb style to a supported stable ID.
 *
 * @param scalar|array<array-key,mixed>|object|resource|null $value Stored or submitted style identifier; unsupported values normalize safely.
 * @param bool $allowInherit Whether the gallery inheritance sentinel is accepted.
 * @return string Supported style ID, or inherit when explicitly allowed.
 */
function breadcrumb_style_normalize(mixed $value, bool $allowInherit = false): string
{
    if (!is_string($value) && !is_int($value)) {
        return $allowInherit ? BREADCRUMB_STYLE_INHERIT : BREADCRUMB_STYLE_DEFAULT;
    }

    $style = strtolower(trim((string) $value));
    if ($allowInherit && $style === BREADCRUMB_STYLE_INHERIT) {
        return BREADCRUMB_STYLE_INHERIT;
    }

    if (array_key_exists($style, breadcrumb_style_registry())) {
        return $style;
    }

    return $allowInherit ? BREADCRUMB_STYLE_INHERIT : BREADCRUMB_STYLE_DEFAULT;
}

/**
 * Resolve gallery and theme style preferences using the documented fallback order.
 *
 * @param scalar|array<array-key,mixed>|object|resource|null $galleryStyle Gallery override, including the inherit sentinel.
 * @param scalar|array<array-key,mixed>|object|resource|null $themeStyle Theme style preference.
 * @return string Resolved supported breadcrumb style ID.
 */
function breadcrumb_style_resolve(mixed $galleryStyle = null, mixed $themeStyle = null): string
{
    $normalizedGalleryStyle = breadcrumb_style_normalize($galleryStyle, true);
    if ($normalizedGalleryStyle !== BREADCRUMB_STYLE_INHERIT) {
        return $normalizedGalleryStyle;
    }

    $normalizedThemeStyle = breadcrumb_style_normalize($themeStyle);
    return $normalizedThemeStyle;
}

/**
 * Read and normalize the site-wide breadcrumb style preference.
 *
 * @return string Supported theme breadcrumb style ID.
 */
function theme_breadcrumb_style(): string
{
    return breadcrumb_style_normalize(app_setting('theme_breadcrumb_style', BREADCRUMB_STYLE_DEFAULT));
}

/**
 * Read and normalize one physical gallery's breadcrumb style override.
 *
 * @param int $galleryId Physical gallery identifier.
 * @return string Supported style ID or inherit.
 */
function gallery_breadcrumb_style_override(int $galleryId): string
{
    if ($galleryId < 1) {
        return BREADCRUMB_STYLE_INHERIT;
    }

    return breadcrumb_style_normalize(
        app_setting('gallery_breadcrumb_style.' . $galleryId, BREADCRUMB_STYLE_INHERIT),
        true
    );
}

/**
 * Resolve the breadcrumb style for a physical gallery or the site theme.
 *
 * Smart Gallery callers resolve their separate presentation document instead,
 * so their IDs never address physical gallery settings.
 *
 * @param array<string,mixed>|null $gallery Physical gallery row keyed by column name, or null for the site root.
 * @return string Resolved supported breadcrumb style ID.
 */
function gallery_breadcrumb_style(?array $gallery = null): string
{
    $themeStyle = theme_breadcrumb_style();
    if ($gallery === null) {
        return $themeStyle;
    }

    $galleryId = (int) ($gallery['id'] ?? 0);
    if ($galleryId < 1) {
        return $themeStyle;
    }

    return breadcrumb_style_resolve(gallery_breadcrumb_style_override($galleryId), $themeStyle);
}

/**
 * Validate one submitted physical gallery breadcrumb style.
 *
 * @param scalar|array<array-key,mixed>|object|resource|null $value Submitted candidate style ID or inherit sentinel; non-string input is rejected.
 * @return string Validated style ID, or inherit.
 * @throws \InvalidArgumentException When the submitted value is unsupported.
 */
function gallery_breadcrumb_style_validate(mixed $value): string
{
    if (!is_string($value)) {
        throw new \InvalidArgumentException('The submitted breadcrumb style is not supported.');
    }

    $styleId = trim($value);
    $allowedStyles = array_keys(breadcrumb_style_registry());
    $allowedStyles[] = BREADCRUMB_STYLE_INHERIT;
    if (!in_array($styleId, $allowedStyles, true)) {
        throw new \InvalidArgumentException('The submitted breadcrumb style is not supported.');
    }

    return breadcrumb_style_normalize($styleId, true);
}

/**
 * Save one physical gallery's validated breadcrumb style override.
 *
 * @param int $galleryId Physical gallery identifier owning the override.
 * @param scalar|array<array-key,mixed>|object|resource|null $value Submitted candidate style ID or inherit sentinel; validation rejects non-strings.
 * @return string Normalized style ID persisted for the gallery.
 */
function gallery_breadcrumb_style_save(int $galleryId, mixed $value): string
{
    if ($galleryId < 1) {
        throw new \InvalidArgumentException('A valid gallery is required to save its breadcrumb style.');
    }

    $normalizedStyle = gallery_breadcrumb_style_validate($value);
    set_app_setting('gallery_breadcrumb_style.' . $galleryId, $normalizedStyle);
    return $normalizedStyle;
}

/**
 * Prepare a normalized breadcrumb view model without rebuilding supplied URLs.
 *
 * @param array<array-key,mixed> $items Ordered candidate entries; usable entries have a scalar label, optional scalar URL, and optional current flag.
 * @param string $style Supported style ID to apply.
 * @param string $label Accessible navigation label.
 * @return array{items:list<array{label:string,url:?string,current:bool}>,aria_label:string,style_class:string} Presentation-ready breadcrumb state.
 */
function breadcrumb_view_model(array $items, string $style, string $label): array
{
    $registry = breadcrumb_style_registry();
    $styleId = breadcrumb_style_normalize($style);
    $normalizedItems = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }

        $itemLabel = (string) ($item['label'] ?? '');
        if ($itemLabel === '') {
            continue;
        }

        $url = array_key_exists('url', $item) && $item['url'] !== null
            ? (string) $item['url']
            : null;
        $normalizedItems[] = [
            'label' => $itemLabel,
            'url' => $url,
            'current' => !empty($item['current']),
        ];
    }

    return [
        'items' => $normalizedItems,
        'aria_label' => $label,
        'style_class' => $registry[$styleId]['class'],
    ];
}
