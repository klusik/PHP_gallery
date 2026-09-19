<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/controllers/admin_gallery_form_models.php
 * Module Type: Controller
 *
 * Purpose:
 *   Prepares reusable gallery/image editor view models at the Controller boundary.
 *
 * Responsibilities:
 *   - Resolve multilingual editor state before form Views render it
 *   - Resolve gallery date, EXIF suggestion, and count-badge presentation data
 *   - Resolve OpenAI text-assist availability and language configuration
 *   - Keep reusable admin form Views independent from Service-layer calls
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
 *   - This module prepares data only and must not emit form markup.
 *
 * Last Updated:
 *   2026-09-14
 */

declare(strict_types=1);

namespace Gallery\Controllers;

use function Gallery\Core\current_user;
use function Gallery\Core\url_for;
use function Gallery\Services\content_localization_enabled;
use function Gallery\Services\content_localization_schema_ready;
use function Gallery\Services\content_supported_languages;
use function Gallery\Services\content_translation_rows;
use function Gallery\Services\feature_capability_effective_enabled;
use function Gallery\Services\gallery_count_badge_override_label;
use function Gallery\Services\gallery_count_badge_override_values;
use function Gallery\Services\gallery_count_badge_schema_ready;
use function Gallery\Services\gallery_date_exif_suggestion_for_gallery;
use function Gallery\Services\gallery_date_exif_suggestions_schema_ready;
use function Gallery\Services\gallery_date_input_value;
use function Gallery\Services\gallery_date_range_schema_ready;
use function Gallery\Services\gallery_date_range_storage_label;
use function Gallery\Services\gallery_date_schema_ready;
use function Gallery\Services\openai_text_assist_available;
use function Gallery\Services\openai_text_assist_default_language;
use function Gallery\Services\openai_text_assist_image_input_allowed;
use function Gallery\Services\openai_text_assist_language_catalog;
use function Gallery\Services\translation_language_presentation;

/**
 * Resolve the current administrator id without exposing authentication logic to Views.
 */
function admin_gallery_form_current_user_id(): int
{
    $user = current_user();
    return is_array($user) ? (int) ($user['id'] ?? 0) : 0;
}

/**
 * Prepare all reusable gallery/image editor presentation data.
 *
 * @param string $entityType Gallery or image architecture identifier.
 * @param array<string,mixed> $entity Entity row being edited.
 * @param ?int $userId Optional administrator id. Null resolves the current administrator.
 * @return array<string,mixed>
 */
function admin_gallery_form_view_model(string $entityType, array $entity = [], ?int $userId = null): array
{
    $entityType = $entityType === 'image' ? 'image' : 'gallery';
    $resolvedUserId = $userId ?? admin_gallery_form_current_user_id();
    $localizationEnabled = content_localization_enabled();
    $localizationSchemaReady = $localizationEnabled && content_localization_schema_ready($entityType);
    $languages = $localizationSchemaReady ? content_supported_languages() : [];
    $translations = [];
    $entityId = (int) ($entity['id'] ?? 0);
    if ($localizationSchemaReady && $entityId > 0) {
        $translationRows = content_translation_rows($entityType, [$entityId]);
        $translations = is_array($translationRows[$entityId] ?? null) ? $translationRows[$entityId] : [];
    }

    $openAiAvailable = $resolvedUserId > 0 && openai_text_assist_available($resolvedUserId);
    $dateModel = [
        'schema_ready' => false,
        'range_schema_ready' => false,
        'start_value' => '',
        'end_value' => '',
        'exif_suggestion_enabled' => false,
        'exif_suggestion' => null,
        'exif_suggestion_label' => '',
    ];
    if ($entityType === 'gallery') {
        $dateSchemaReady = gallery_date_schema_ready();
        $rangeSchemaReady = $dateSchemaReady && gallery_date_range_schema_ready();
        $galleryId = (int) ($entity['id'] ?? 0);
        $exifSuggestionEnabled = $galleryId > 0
            && feature_capability_effective_enabled('exif_gallery_date_suggestions')
            && gallery_date_exif_suggestions_schema_ready();
        $suggestion = $exifSuggestionEnabled ? gallery_date_exif_suggestion_for_gallery($galleryId) : null;
        $dateModel = [
            'schema_ready' => $dateSchemaReady,
            'range_schema_ready' => $rangeSchemaReady,
            'start_value' => $dateSchemaReady ? gallery_date_input_value($entity['gallery_date'] ?? null) : '',
            'end_value' => $rangeSchemaReady ? gallery_date_input_value($entity['gallery_date_end'] ?? null) : '',
            'exif_suggestion_enabled' => $exifSuggestionEnabled,
            'exif_suggestion' => is_array($suggestion) ? $suggestion : null,
            'exif_suggestion_label' => is_array($suggestion)
                ? gallery_date_range_storage_label($suggestion['suggested_start'] ?? null, $suggestion['suggested_end'] ?? null)
                : '',
        ];
    }

    $countBadgeOptions = [];
    $countBadgeSchemaReady = $entityType === 'gallery' && gallery_count_badge_schema_ready();
    if ($countBadgeSchemaReady) {
        foreach (gallery_count_badge_override_values() as $value) {
            $countBadgeOptions[] = [
                'value' => $value,
                'label' => gallery_count_badge_override_label($value),
            ];
        }
    }

    return [
        'localization' => [
            'enabled' => $localizationEnabled,
            'schema_ready' => $localizationSchemaReady,
            'languages' => $languages,
            'presentation' => $localizationSchemaReady ? translation_language_presentation() : [],
            'translations' => $translations,
        ],
        'openai' => [
            'available' => $openAiAvailable,
            'allow_image_input' => $openAiAvailable && openai_text_assist_image_input_allowed($resolvedUserId),
            'language_catalog' => $openAiAvailable ? openai_text_assist_language_catalog() : [],
            'default_language' => $openAiAvailable ? openai_text_assist_default_language() : 'en',
        ],
        'date' => $dateModel,
        'count_badge' => [
            'schema_ready' => $countBadgeSchemaReady,
            'options' => $countBadgeOptions,
        ],
        'title_completion' => [
            'candidates' => [],
            'url' => $entityType === 'gallery' && $entityId <= 0 ? url_for('admin_gallery_title_completion') : '',
        ],
    ];
}
