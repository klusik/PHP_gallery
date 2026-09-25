<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/gallery_creation_preferences.php
 * Module Type: Service
 *
 * Purpose:
 *   Validate and manage per-administrator gallery creation defaults.
 *
 * Responsibilities:
 *   - Apply optional preference storage readiness policy
 *   - Validate and normalize selected default values
 *
 * Author:
 *   Rudolf Klusal
 * Contact:
 *   https://github.com/klusik
 * License:
 *   MIT License (see LICENSE file in repository)
 * Last Updated:
 *   2026-09-25
 */

declare(strict_types=1);

namespace Gallery\Services;

use RuntimeException;
use function Gallery\Core\now_sql;
use function Gallery\Models\gallery_creation_preferences_model_find;
use function Gallery\Models\gallery_creation_preferences_model_save;

/**
 * Return whether preference storage was verified by the shared inspector.
 *
 * @return bool True only for confirmed available storage.
 */
function gallery_creation_preferences_available(): bool
{
    return schema_inspection_is_available(schema_inspection_feature('gallery_creation_preferences', [
        schema_inspection_table('user_gallery_creation_preferences'),
        schema_inspection_column('user_gallery_creation_preferences', 'user_id'),
        schema_inspection_column('user_gallery_creation_preferences', 'simbrief_pilot_id'),
        schema_inspection_column('user_gallery_creation_preferences', 'simbrief_pilot_name'),
        schema_inspection_column('user_gallery_creation_preferences', 'content_language'),
        schema_inspection_column('user_gallery_creation_preferences', 'created_at'),
        schema_inspection_column('user_gallery_creation_preferences', 'updated_at'),
    ]));
}

/**
 * Return safe defaults, even before the optional migration has run.
 *
 * @param int $userId Authenticated administrator ID.
 * @return array{simbrief_pilot_id:string,simbrief_pilot_name:string,content_language:string} Supported defaults.
 */
function gallery_creation_preferences_for_user(int $userId): array
{
    $empty = ['simbrief_pilot_id' => '', 'simbrief_pilot_name' => '', 'content_language' => ''];
    if ($userId < 1 || !gallery_creation_preferences_available()) {
        return $empty;
    }
    $row = gallery_creation_preferences_model_find($userId);
    if (!is_array($row)) {
        return $empty;
    }
    $language = (string) ($row['content_language'] ?? '');
    if ($language !== '' && !in_array($language, content_supported_languages(), true)) {
        $language = '';
    }
    return [
        'simbrief_pilot_id' => (string) ($row['simbrief_pilot_id'] ?? ''),
        'simbrief_pilot_name' => (string) ($row['simbrief_pilot_name'] ?? ''),
        'content_language' => $language,
    ];
}

/**
 * Validate selected defaults before any gallery mutation.
 *
 * @param array<string,mixed> $input Normalized creation input.
 * @return void Refuse unsupported or oversized selected values.
 */
function gallery_creation_preferences_validate(array $input): void
{
    foreach (['simbrief_pilot_id' => 32, 'simbrief_pilot_name' => 80] as $field => $limit) {
        if (empty($input['remember_' . $field])) {
            continue;
        }
        $value = trim((string) ($input[$field] ?? ''));
        $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : preg_match_all('/./us', $value);
        if (!is_int($length) || $length > $limit) {
            throw new RuntimeException('A remembered SimBrief identifier is too long.');
        }
        $pattern = $field === 'simbrief_pilot_id' ? '/\A[A-Za-z0-9_-]*\z/D' : '/\A[\p{L}\p{N} ._-]*\z/uD';
        if (preg_match($pattern, $value) !== 1) {
            throw new RuntimeException('A remembered SimBrief identifier contains unsupported characters.');
        }
    }
    if (!empty($input['remember_content_language'])) {
        $language = (string) ($input['content_language'] ?? '');
        if ($language !== '' && !in_array($language, content_supported_languages(), true)) {
            throw new RuntimeException('Select a supported description language.');
        }
    }
}

/**
 * Update only the defaults explicitly selected by the administrator.
 *
 * @param int $userId Authenticated administrator ID.
 * @param array<string,mixed> $input Normalized creation input.
 * @return void Persist the selected values.
 */
function gallery_creation_preferences_remember(int $userId, array $input): void
{
    if ($userId < 1 || !gallery_creation_preferences_available()) {
        throw new RuntimeException('Gallery creation preferences are unavailable. Run pending migrations.');
    }
    gallery_creation_preferences_validate($input);
    $values = gallery_creation_preferences_for_user($userId);
    foreach (['simbrief_pilot_id', 'simbrief_pilot_name'] as $field) {
        if (!empty($input['remember_' . $field])) {
            $values[$field] = trim((string) ($input[$field] ?? ''));
        }
    }
    if (!empty($input['remember_content_language'])) {
        $language = (string) ($input['content_language'] ?? '');
        $values['content_language'] = $language;
    }
    gallery_creation_preferences_model_save($userId, $values, now_sql());
}
