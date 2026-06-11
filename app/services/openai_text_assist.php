<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/openai_text_assist.php
 * Module Type: Service
 *
 * Purpose:
 *   Provides optional user-scoped OpenAI text-assistance helpers.
 *
 * Responsibilities:
 *   - Store and read per-user OpenAI text-assistance settings
 *   - Keep OpenAI API credential handling isolated from controllers and views
 *   - Build focused prompts for gallery description and text cleanup tasks
 *   - Execute bounded OpenAI Responses API requests only when explicitly enabled
 *   - Return short draft text for human review without mutating gallery content
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
 *   2026-05-29
 */

declare(strict_types=1);

const OPENAI_TEXT_ASSIST_ENDPOINT = 'https://api.openai.com/v1/responses';
const OPENAI_TEXT_ASSIST_TIMEOUT_SECONDS = 35;
const OPENAI_TEXT_ASSIST_DEFAULT_MODEL = 'gpt-5.4-mini';
const OPENAI_TEXT_ASSIST_MAX_CONTEXT_CHARS = 9000;
const OPENAI_TEXT_ASSIST_VISUAL_GALLERY_LIMIT = 3;

/**
 * Translation wrapper for isolated service tests.
 *
 * @param string $key Lookup key.
 * @param string $fallback Fallback value.
 * @param array $parameters Parameters value.
 * @return string Text result for the caller.
 */
function openai_text_assist_t(string $key, string $fallback, array $parameters = []): string
{
    if (function_exists('t')) {
        return t($key, $fallback, $parameters);
    }

    foreach ($parameters as $name => $value) {
        $fallback = str_replace('{' . $name . '}', (string) $value, $fallback);
    }
    return $fallback;
}

/**
 * Return whether the user-level OpenAI settings table is ready.
 *
 * @return bool True when the condition matches.
 */
function openai_text_assist_schema_ready(): bool
{
    if (!function_exists('db_table_exists') || !function_exists('db_column_exists')) {
        return false;
    }
    if (!db_table_exists('user_openai_text_settings')) {
        return false;
    }

    $requiredColumns = [
        'user_id',
        'enabled',
        'api_key_cipher',
        'api_key_hint',
        'model',
        'created_at',
        'updated_at',
    ];
    foreach ($requiredColumns as $column) {
        if (!db_column_exists('user_openai_text_settings', $column)) {
            return false;
        }
    }
    return true;
}

/**
 * Return the safe default settings row shape.
 *
 * @return array<string,mixed> Structured result data for the caller.
 */
function openai_text_assist_default_settings(): array
{
    return [
        'user_id' => 0,
        'enabled' => 0,
        'api_key_cipher' => '',
        'api_key_hint' => '',
        'model' => OPENAI_TEXT_ASSIST_DEFAULT_MODEL,
        'allow_image_input' => 0,
        'created_at' => null,
        'updated_at' => null,
    ];
}

/**
 * Return one user's OpenAI text-assistance settings.
 *
 * @param int $userId User id identifier.
 * @return array<string,mixed> Structured result data for the caller.
 */
function openai_text_assist_user_settings(int $userId): array
{
    $settings = openai_text_assist_default_settings();
    $settings['user_id'] = $userId;
    if ($userId <= 0 || !openai_text_assist_schema_ready()) {
        return $settings;
    }

    $stmt = db()->prepare('SELECT * FROM user_openai_text_settings WHERE user_id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    if (!$row) {
        return $settings;
    }

    return array_merge($settings, $row);
}

/**
 * Return whether the optional image-input preference column exists.
 *
 * @return bool True when the condition matches.
 */
function openai_text_assist_image_input_column_ready(): bool
{
    return function_exists('db_column_exists') && db_column_exists('user_openai_text_settings', 'allow_image_input');
}

/**
 * Return whether the user allowed small image thumbnails to be sent to OpenAI.
 *
 * @param int $userId User id identifier.
 * @return bool True when the condition matches.
 */
function openai_text_assist_image_input_allowed(int $userId): bool
{
    if ($userId <= 0 || !openai_text_assist_available($userId) || !openai_text_assist_image_input_column_ready()) {
        return false;
    }

    $settings = openai_text_assist_user_settings($userId);
    return (int) ($settings['allow_image_input'] ?? 0) === 1;
}

/**
 * Return the curated model choices exposed in the profile UI.
 *
 * @return array<string,array{label:string,description:string,badge:string}> Structured result data for the caller.
 */
function openai_text_assist_model_catalog(): array
{
    return [
        'gpt-5.4-mini' => [
            'label' => 'GPT-5.4 mini',
            'description' => openai_text_assist_t('admin.openai.model_desc_gpt_54_mini', 'Recommended default. Good quality for gallery descriptions and rewrites with lower latency and lower cost.'),
            'badge' => openai_text_assist_t('admin.openai.model_badge_recommended', 'Recommended'),
        ],
        'gpt-5.4-nano' => [
            'label' => 'GPT-5.4 nano',
            'description' => openai_text_assist_t('admin.openai.model_desc_gpt_54_nano', 'Lowest-cost option for short spelling fixes and simple cleanup. Use mini if descriptions become too plain.'),
            'badge' => openai_text_assist_t('admin.openai.model_badge_lowest_cost', 'Lowest cost'),
        ],
        'gpt-5.4' => [
            'label' => 'GPT-5.4',
            'description' => openai_text_assist_t('admin.openai.model_desc_gpt_54', 'Stronger wording and reasoning for larger parent-gallery summaries, with higher cost than mini.'),
            'badge' => openai_text_assist_t('admin.openai.model_badge_stronger', 'Stronger'),
        ],
        'gpt-5.5' => [
            'label' => 'GPT-5.5',
            'description' => openai_text_assist_t('admin.openai.model_desc_gpt_55', 'Flagship quality for complex text work. Usually more than this gallery helper needs.'),
            'badge' => openai_text_assist_t('admin.openai.model_badge_flagship', 'Flagship'),
        ],
    ];
}

/**
 * Normalize an OpenAI model id saved by the admin.
 *
 * @param string $model Model value.
 * @return string Text result for the caller.
 */
function openai_text_assist_normalize_model(string $model): string
{
    $model = trim($model);
    if ($model === '' || $model === 'gpt-4.1-mini') {
        return OPENAI_TEXT_ASSIST_DEFAULT_MODEL;
    }
    if (array_key_exists($model, openai_text_assist_model_catalog())) {
        return $model;
    }
    return OPENAI_TEXT_ASSIST_DEFAULT_MODEL;
}

/**
 * Return true when a raw OpenAI API key looks usable enough to save.
 *
 * @param string $apiKey Api key value.
 * @return bool True when the condition matches.
 */
function openai_text_assist_api_key_format_valid(string $apiKey): bool
{
    $apiKey = trim($apiKey);
    if (strlen($apiKey) < 20 || strlen($apiKey) > 240) {
        return false;
    }
    return preg_match('/^[A-Za-z0-9_\-\.]+$/', $apiKey) === 1;
}

/**
 * Return a privacy-safe key hint for profile UI and logs.
 *
 * @param string $apiKey Api key value.
 * @return string Text result for the caller.
 */
function openai_text_assist_key_hint(string $apiKey): string
{
    $apiKey = trim($apiKey);
    if ($apiKey === '') {
        return '';
    }
    $suffix = strlen($apiKey) >= 4 ? substr($apiKey, -4) : $apiKey;
    return '****' . $suffix;
}

/**
 * Encrypt one OpenAI API key for database storage.
 *
 * @param string $secret Secret value.
 * @return string Text result for the caller.
 */
function openai_text_assist_encrypt_secret(string $secret): string
{
    if ($secret === '') {
        return '';
    }
    if (!function_exists('openssl_encrypt')) {
        return '';
    }

    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($secret, 'aes-256-gcm', openai_text_assist_secret_key(), OPENSSL_RAW_DATA, $iv, $tag, 'openai-text-assist');
    if ($cipher === false || $tag === '') {
        return '';
    }

    return 'v1:' . base64_encode(json_encode([
        'iv' => base64_encode($iv),
        'tag' => base64_encode($tag),
        'cipher' => base64_encode($cipher),
    ], JSON_UNESCAPED_SLASHES));
}

/**
 * Decrypt one OpenAI API key previously saved by openai_text_assist_encrypt_secret().
 *
 * @param string $encoded Encoded value.
 * @return string Text result for the caller.
 */
function openai_text_assist_decrypt_secret(string $encoded): string
{
    if ($encoded === '' || !str_starts_with($encoded, 'v1:') || !function_exists('openssl_decrypt')) {
        return '';
    }

    $json = base64_decode(substr($encoded, 3), true);
    if ($json === false) {
        return '';
    }
    $payload = json_decode($json, true);
    if (!is_array($payload)) {
        return '';
    }

    $iv = base64_decode((string) ($payload['iv'] ?? ''), true);
    $tag = base64_decode((string) ($payload['tag'] ?? ''), true);
    $cipher = base64_decode((string) ($payload['cipher'] ?? ''), true);
    if ($iv === false || $tag === false || $cipher === false) {
        return '';
    }

    $plain = openssl_decrypt($cipher, 'aes-256-gcm', openai_text_assist_secret_key(), OPENSSL_RAW_DATA, $iv, $tag, 'openai-text-assist');
    return $plain === false ? '' : $plain;
}

/**
 * Return the binary encryption key used for user-scoped OpenAI secrets.
 *
 * @return string Text result for the caller.
 */
function openai_text_assist_secret_key(): string
{
    $config = function_exists('cms_config') ? cms_config() : [];
    $keyMaterial = implode('|', [
        (string) ($config['visitor_vote_secret'] ?? ''),
        (string) ($config['setup_key'] ?? ''),
        (string) ($config['admin_session_name'] ?? ''),
        (string) ($config['base_url'] ?? ''),
        is_array($config['database'] ?? null) ? (string) ($config['database']['password'] ?? '') : '',
    ]);
    return hash('sha256', $keyMaterial !== '||||' ? $keyMaterial : __FILE__, true);
}

/**
 * Save one user's OpenAI text-assistance settings.
 *
 * @param int $userId User id identifier.
 * @param array<string,mixed> $input Raw profile form input.
 * @return array{ok:bool,errors:array<int,string>,enabled:bool,api_key_hint:string,model:string} Structured result data for the caller.
 */
function openai_text_assist_save_user_settings(int $userId, array $input): array
{
    $errors = [];
    if ($userId <= 0) {
        $errors[] = openai_text_assist_t('admin.openai.error_user_missing', 'The current user account could not be resolved.');
    }
    if (!openai_text_assist_schema_ready()) {
        $errors[] = openai_text_assist_t('admin.openai.error_migration_required', 'OpenAI profile settings are not available until database migrations are applied.');
    }

    $existing = openai_text_assist_user_settings($userId);
    $enabled = !empty($input['openai_text_enabled']);
    $clearKey = !empty($input['openai_text_clear_key']);
    $submittedKey = trim((string) ($input['openai_text_api_key'] ?? ''));
    $model = openai_text_assist_normalize_model((string) ($input['openai_text_model'] ?? OPENAI_TEXT_ASSIST_DEFAULT_MODEL));
    $allowImageInput = !empty($input['openai_text_allow_image_input']) && openai_text_assist_image_input_column_ready();
    $cipher = (string) ($existing['api_key_cipher'] ?? '');
    $hint = (string) ($existing['api_key_hint'] ?? '');

    if ($clearKey) {
        $cipher = '';
        $hint = '';
    }

    if ($submittedKey !== '') {
        if (!openai_text_assist_api_key_format_valid($submittedKey)) {
            $errors[] = openai_text_assist_t('admin.openai.error_api_key_format', 'Enter a valid OpenAI API key without spaces.');
        } else {
            $cipher = openai_text_assist_encrypt_secret($submittedKey);
            if ($cipher === '') {
                $errors[] = openai_text_assist_t('admin.openai.error_encryption_unavailable', 'This server cannot encrypt the API key. Enable the OpenSSL PHP extension before saving OpenAI settings.');
            } else {
                $hint = openai_text_assist_key_hint($submittedKey);
            }
        }
    }

    if ($enabled && $cipher === '') {
        $errors[] = openai_text_assist_t('admin.openai.error_key_required_when_enabled', 'OpenAI text assistance needs a saved API key before it can be enabled.');
    }

    if ($errors !== []) {
        return [
            'ok' => false,
            'errors' => $errors,
            'enabled' => $enabled,
            'api_key_hint' => $hint,
            'model' => $model,
            'allow_image_input' => $allowImageInput ? 1 : 0,
        ];
    }

    $now = function_exists('now_sql') ? now_sql() : date('Y-m-d H:i:s');
    if (openai_text_assist_image_input_column_ready()) {
        $stmt = db()->prepare('INSERT INTO user_openai_text_settings (user_id, enabled, api_key_cipher, api_key_hint, model, allow_image_input, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), api_key_cipher = VALUES(api_key_cipher), api_key_hint = VALUES(api_key_hint), model = VALUES(model), allow_image_input = VALUES(allow_image_input), updated_at = VALUES(updated_at)');
        $stmt->execute([
            $userId,
            $enabled ? 1 : 0,
            $cipher === '' ? null : $cipher,
            $hint === '' ? null : $hint,
            $model,
            $allowImageInput ? 1 : 0,
            $now,
            $now,
        ]);
    } else {
        $stmt = db()->prepare('INSERT INTO user_openai_text_settings (user_id, enabled, api_key_cipher, api_key_hint, model, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), api_key_cipher = VALUES(api_key_cipher), api_key_hint = VALUES(api_key_hint), model = VALUES(model), updated_at = VALUES(updated_at)');
        $stmt->execute([
            $userId,
            $enabled ? 1 : 0,
            $cipher === '' ? null : $cipher,
            $hint === '' ? null : $hint,
            $model,
            $now,
            $now,
        ]);
    }

    return [
        'ok' => true,
        'errors' => [],
        'enabled' => $enabled,
        'api_key_hint' => $hint,
        'model' => $model,
        'allow_image_input' => $allowImageInput ? 1 : 0,
    ];
}

/**
 * Return true when a user can call OpenAI text assistance.
 *
 * @param int $userId User id identifier.
 * @return bool True when the condition matches.
 */
function openai_text_assist_available(int $userId): bool
{
    if (function_exists('feature_flag_enabled') && !feature_flag_enabled('openai_text_assist')) {
        return false;
    }
    if ($userId <= 0 || !openai_text_assist_schema_ready()) {
        return false;
    }
    $settings = openai_text_assist_user_settings($userId);
    if ((int) ($settings['enabled'] ?? 0) !== 1) {
        return false;
    }
    return openai_text_assist_decrypt_secret((string) ($settings['api_key_cipher'] ?? '')) !== '';
}

/**
 * Return a compact gallery context for prompt construction.
 *
 * @param int $galleryId Gallery identifier.
 * @return array<string,mixed> Structured result data for the caller.
 */
function openai_text_assist_gallery_context(int $galleryId): array
{
    $gallery = function_exists('find_gallery') ? find_gallery($galleryId) : null;
    if (!$gallery) {
        throw new RuntimeException(openai_text_assist_t('admin.openai.error_gallery_missing', 'The gallery could not be found. Reload the editor and try again.'));
    }

    $children = function_exists('child_galleries') ? child_galleries($galleryId, false) : [];
    $images = function_exists('gallery_images') ? gallery_images($galleryId, false) : [];
    $tags = function_exists('tag_names_for_entity') ? (string) tag_names_for_entity('gallery', $galleryId) : '';
    $branchCount = function_exists('gallery_branch_image_count') ? gallery_branch_image_count($galleryId, false) : count($images);

    $childSummaries = [];
    foreach (array_slice($children, 0, 12) as $child) {
        $childSummaries[] = [
            'title' => openai_text_assist_text_limit((string) ($child['title'] ?? ''), 140),
            'description' => openai_text_assist_text_limit((string) ($child['description'] ?? ''), 360),
            'image_count' => (int) ($child['image_count'] ?? 0),
        ];
    }

    $imageSummaries = [];
    foreach (array_slice($images, 0, 24) as $image) {
        $imageSummaries[] = [
            'filename' => openai_text_assist_text_limit((string) ($image['filename'] ?? $image['relative_path'] ?? ''), 160),
            'title' => openai_text_assist_text_limit((string) ($image['title'] ?? ''), 160),
            'description' => openai_text_assist_text_limit((string) ($image['description'] ?? ''), 260),
        ];
    }

    return [
        'gallery' => [
            'id' => (int) ($gallery['id'] ?? 0),
            'title' => openai_text_assist_text_limit((string) ($gallery['title'] ?? ''), 180),
            'description' => openai_text_assist_text_limit((string) ($gallery['description'] ?? ''), 1200),
            'folder_path' => openai_text_assist_text_limit((string) ($gallery['folder_path'] ?? ''), 280),
            'tags' => openai_text_assist_text_limit($tags, 600),
            'direct_image_count' => count($images),
            'branch_image_count' => $branchCount,
            'direct_subgallery_count' => count($children),
        ],
        'subgalleries' => $childSummaries,
        'images' => $imageSummaries,
    ];
}

/**
 * Return a compact image context for prompt construction.
 *
 * @param int $imageId Image identifier.
 * @return array<string,mixed> Structured result data for the caller.
 */
function openai_text_assist_image_context(int $imageId): array
{
    $image = function_exists('find_image') ? find_image($imageId) : null;
    if (!$image) {
        throw new RuntimeException(openai_text_assist_t('admin.openai.error_image_missing', 'The photo could not be found. Reload the editor and try again.'));
    }

    $gallery = function_exists('find_gallery') ? find_gallery((int) ($image['gallery_id'] ?? 0)) : null;
    $imageTags = function_exists('tag_names_for_entity') ? (string) tag_names_for_entity('image', $imageId) : '';
    $galleryTags = $gallery && function_exists('tag_names_for_entity') ? (string) tag_names_for_entity('gallery', (int) ($gallery['id'] ?? 0)) : '';

    $metadataSummary = [];
    if (function_exists('ai_image_analysis_latest_metadata_for_image')) {
        $metadata = ai_image_analysis_latest_metadata_for_image($imageId);
        if (is_array($metadata)) {
            $metadataSummary = [
                'searchable_text' => openai_text_assist_text_limit((string) ($metadata['searchable_text'] ?? ''), 900),
                'dominant_colors' => openai_text_assist_text_limit((string) ($metadata['dominant_colors'] ?? ''), 260),
                'generated_by' => openai_text_assist_text_limit(trim((string) ($metadata['model_name'] ?? '') . ' ' . (string) ($metadata['model_version'] ?? '')), 160),
            ];
        }
    }

    return [
        'image' => [
            'id' => (int) ($image['id'] ?? 0),
            'filename' => openai_text_assist_text_limit((string) ($image['filename'] ?? $image['relative_path'] ?? ''), 180),
            'title' => openai_text_assist_text_limit((string) ($image['title'] ?? ''), 180),
            'description' => openai_text_assist_text_limit((string) ($image['description'] ?? ''), 1200),
            'tags' => openai_text_assist_text_limit($imageTags, 600),
            'taken_at' => openai_text_assist_text_limit((string) ($image['exif_taken_at'] ?? ''), 80),
            'camera' => openai_text_assist_text_limit(trim((string) ($image['exif_camera_make'] ?? '') . ' ' . (string) ($image['exif_camera_model'] ?? '')), 140),
            'lens' => openai_text_assist_text_limit((string) ($image['exif_lens_model'] ?? ''), 140),
            'gps' => openai_text_assist_text_limit(trim((string) ($image['gps_lat'] ?? '') . ', ' . (string) ($image['gps_lng'] ?? ''), ' ,'), 90),
        ],
        'gallery' => [
            'id' => (int) ($gallery['id'] ?? 0),
            'title' => openai_text_assist_text_limit((string) ($gallery['title'] ?? ''), 180),
            'description' => openai_text_assist_text_limit((string) ($gallery['description'] ?? ''), 900),
            'folder_path' => openai_text_assist_text_limit((string) ($gallery['folder_path'] ?? ''), 280),
            'tags' => openai_text_assist_text_limit($galleryTags, 600),
        ],
        'internal_ai_metadata' => $metadataSummary,
    ];
}

/**
 * Truncate input text to a safe prompt size without breaking multibyte strings when mbstring exists.
 *
 * @param string $value Value to process.
 * @param int $limit Maximum number of items.
 * @return string Text result for the caller.
 */
function openai_text_assist_text_limit(string $value, int $limit): string
{
    $value = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', ' ', $value) ?? '');
    if ($limit <= 0) {
        return '';
    }
    $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    if ($length <= $limit) {
        return $value;
    }
    return (function_exists('mb_substr') ? mb_substr($value, 0, $limit, 'UTF-8') : substr($value, 0, $limit)) . '…';
}

/**
 * Normalize an OpenAI text-assistance task id.
 *
 * @param string $task Task value.
 * @return string Text result for the caller.
 */
function openai_text_assist_normalize_task(string $task): string
{
    $task = strtolower(trim($task));
    $allowed = ['gallery_description', 'gallery_summary', 'image_description', 'image_visual_description', 'gallery_visual_description', 'cleanup_text', 'expand_text'];
    return in_array($task, $allowed, true) ? $task : 'gallery_description';
}

/**
 * Return whether a task needs thumbnail image input in addition to text context.
 *
 * @param string $task Task value.
 * @return bool True when the condition matches.
 */
function openai_text_assist_task_uses_images(string $task): bool
{
    $task = openai_text_assist_normalize_task($task);
    return in_array($task, ['image_visual_description', 'gallery_visual_description'], true);
}

/**
 * Return the small language catalog exposed beside OpenAI generation actions.
 *
 * @return array<string,array{label:string,flag:string,instruction:string}> Structured result data for the caller.
 */
function openai_text_assist_language_catalog(): array
{
    return [
        'auto' => [
            'label' => openai_text_assist_t('admin.openai.language_auto', 'Auto'),
            'flag' => '🌐',
            'instruction' => 'Choose the output language from the supplied title, existing text, tags, and gallery context.',
        ],
        'cs' => [
            'label' => openai_text_assist_t('admin.openai.language_cs', 'Czech'),
            'flag' => '🇨🇿',
            'instruction' => 'Write the final output in Czech.',
        ],
        'en' => [
            'label' => openai_text_assist_t('admin.openai.language_en', 'English'),
            'flag' => '🇬🇧',
            'instruction' => 'Write the final output in English.',
        ],
    ];
}

/**
 * Normalize a requested OpenAI output language to a supported catalog key.
 *
 * @param string $language Language value.
 * @return string Text result for the caller.
 */
function openai_text_assist_normalize_language(string $language): string
{
    $language = strtolower(trim($language));
    if ($language === '') {
        return 'auto';
    }

    $language = str_replace('_', '-', $language);
    $primary = explode('-', $language)[0] ?? '';
    return array_key_exists($primary, openai_text_assist_language_catalog()) ? $primary : 'auto';
}

/**
 * Return the best default OpenAI output language for the current UI.
 *
 * @return string Text result for the caller.
 */
function openai_text_assist_default_language(): string
{
    $active = function_exists('translation_active_language') ? translation_active_language() : '';
    $normalized = openai_text_assist_normalize_language($active);
    return $normalized === 'auto' ? 'en' : $normalized;
}

/**
 * Return one instruction line for the requested OpenAI output language.
 *
 * @param string $language Language value.
 * @return string Text result for the caller.
 */
function openai_text_assist_language_instruction(string $language): string
{
    $normalized = openai_text_assist_normalize_language($language);
    $catalog = openai_text_assist_language_catalog();
    return (string) ($catalog[$normalized]['instruction'] ?? $catalog['auto']['instruction']);
}

/**
 * Build a concise, task-specific prompt payload.
 *
 * @param string $task Task value.
 * @param array<string,mixed> $context Gallery and submitted editor context.
 * @param string $existingText Existing text value.
 * @param string $language Language value.
 * @return array{instructions:string,input:string,max_output_tokens:int} Structured result data for the caller.
 */
function openai_text_assist_prompt(string $task, array $context, string $existingText, string $language = 'auto'): array
{
    $task = openai_text_assist_normalize_task($task);
    $language = openai_text_assist_normalize_language($language);
    $existingText = openai_text_assist_text_limit($existingText, 2500);

    $baseInstructions = implode("\n", [
        'You are helping edit descriptions for a private PHP photo gallery CMS.',
        'Return only the proposed description text. Do not add explanations, headings about the task, or surrounding quotes.',
        'Use a natural, polished style suitable for a public gallery page.',
        'Keep the result compatible with basic Markdown: bold, italic, inline code, links, and paragraph breaks only.',
        'Do not invent specific places, dates, people, aircraft, or events that are not supported by the supplied context.',
        openai_text_assist_language_instruction($language),
    ]);

    $taskInstructions = [
        'gallery_description' => 'Write a concise leaf-gallery description in one or two short paragraphs. Use the title, tags, photo names, and existing text as context.',
        'gallery_summary' => 'Write a broader parent-gallery summary. Emphasize themes and structure across subgalleries rather than duplicating every child gallery detail.',
        'image_description' => 'Write a concise public photo description in one short paragraph. Use the image title, filename, tags, EXIF/GPS hints, gallery context, and existing text as evidence.',
        'image_visual_description' => 'Write a concise public photo description in one short paragraph. Use the provided thumbnail as the primary evidence for visible content, and use text metadata only as supporting context.',
        'gallery_visual_description' => 'Write a concise gallery description in one or two short paragraphs based on the provided gallery thumbnails. Describe the overall theme and visible content without pretending to have seen more photos than were supplied.',
        'cleanup_text' => 'Clean up spelling, grammar, punctuation, and readability. Preserve meaning and keep roughly the same length.',
        'expand_text' => 'Rewrite the existing text into a richer but still compact gallery description. Preserve meaning and avoid unsupported claims.',
    ];

    $promptContext = $context;
    if (isset($promptContext['visual_references'])) {
        unset($promptContext['visual_references']);
    }

    $input = [
        'task' => $task,
        'task_instruction' => $taskInstructions[$task],
        'existing_text' => $existingText,
        'output_language' => $language,
        'context' => $promptContext,
    ];
    $encoded = json_encode($input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if (!is_string($encoded)) {
        $encoded = '{}';
    }

    return [
        'instructions' => $baseInstructions . "\n" . $taskInstructions[$task],
        'input' => openai_text_assist_text_limit($encoded, OPENAI_TEXT_ASSIST_MAX_CONTEXT_CHARS),
        'max_output_tokens' => $task === 'cleanup_text' ? 900 : 1200,
    ];
}


/**
 * Return one compact thumbnail reference for image-input prompts.
 *
 * @param array $image Image row or image data.
 * @return array{image_id:int,label:string,detail:string,data_url:string,size:int,format:string,mime_type:string} Structured result data for the caller.
 */
function openai_text_assist_thumbnail_reference_for_image(array $image): array
{
    $gallery = function_exists('find_gallery') ? find_gallery((int) ($image['gallery_id'] ?? 0)) : null;
    if (!$gallery) {
        throw new RuntimeException(openai_text_assist_t('admin.openai.error_gallery_missing', 'The gallery could not be found. Reload the editor and try again.'));
    }

    foreach (thumbnail_sizes() as $size) {
        foreach (['webp', 'jpg'] as $format) {
            try {
                $path = thumbnail_abs_path($image, $gallery, (int) $size, $format);
            } catch (RuntimeException) {
                continue;
            }
            if (!is_file($path) || !is_readable($path)) {
                continue;
            }

            $binary = file_get_contents($path);
            if (!is_string($binary) || $binary === '') {
                continue;
            }

            $mimeType = $format === 'webp' ? 'image/webp' : 'image/jpeg';
            $label = trim((string) ($image['title'] ?? ''));
            if ($label === '') {
                $label = (string) ($image['filename'] ?? 'Photo');
            }

            return [
                'image_id' => (int) ($image['id'] ?? 0),
                'label' => openai_text_assist_text_limit($label, 160),
                'detail' => 'low',
                'data_url' => 'data:' . $mimeType . ';base64,' . base64_encode($binary),
                'size' => (int) $size,
                'format' => $format,
                'mime_type' => $mimeType,
            ];
        }
    }

    throw new RuntimeException(openai_text_assist_t('admin.openai.error_thumbnail_missing', 'No generated thumbnail was found for this photo. Create thumbnails first, then try again.'));
}

/**
 * Return one thumbnail reference for an existing image id.
 *
 * @param int $imageId Image identifier.
 * @return array{image_id:int,label:string,detail:string,data_url:string,size:int,format:string,mime_type:string} Structured result data for the caller.
 */
function openai_text_assist_thumbnail_reference_for_image_id(int $imageId): array
{
    $image = function_exists('find_image') ? find_image($imageId) : null;
    if (!$image) {
        throw new RuntimeException(openai_text_assist_t('admin.openai.error_image_missing', 'The photo could not be found. Reload the editor and try again.'));
    }

    return openai_text_assist_thumbnail_reference_for_image($image);
}

/**
 * Return direct photo candidates for a confirmed gallery bulk-description run.
 *
 * @param int $galleryId Gallery identifier.
 * @return array<int,array{id:int,filename:string,title:string,has_description:bool}> Structured result data for the caller.
 */
function openai_text_assist_gallery_bulk_image_candidates(int $galleryId): array
{
    $gallery = function_exists('find_gallery') ? find_gallery($galleryId) : null;
    if (!$gallery) {
        throw new RuntimeException(openai_text_assist_t('admin.openai.error_gallery_missing', 'The gallery could not be found. Reload the editor and try again.'));
    }

    $images = function_exists('gallery_images') ? gallery_images($galleryId, false) : [];
    $candidates = [];
    foreach ($images as $image) {
        $imageId = (int) ($image['id'] ?? 0);
        if ($imageId <= 0) {
            continue;
        }
        $candidates[] = [
            'id' => $imageId,
            'filename' => openai_text_assist_text_limit((string) ($image['filename'] ?? $image['relative_path'] ?? ''), 180),
            'title' => openai_text_assist_text_limit((string) ($image['title'] ?? ''), 180),
            'has_description' => trim((string) ($image['description'] ?? '')) !== '',
        ];
    }

    return $candidates;
}

/**
 * Persist one generated image description after a confirmed bulk OpenAI action.
 *
 * @param int $imageId Image identifier.
 * @param string $description Description value.
 * @return array<string,mixed> Structured result data for the caller.
 */
function openai_text_assist_save_image_description(int $imageId, string $description): array
{
    $image = function_exists('find_image') ? find_image($imageId) : null;
    if (!$image) {
        throw new RuntimeException(openai_text_assist_t('admin.openai.error_image_missing', 'The photo could not be found. Reload the editor and try again.'));
    }

    $description = openai_text_assist_text_limit($description, 8000);
    $stmt = db()->prepare('UPDATE images SET description = ?, updated_at = ? WHERE id = ?');
    $stmt->execute([$description, function_exists('now_sql') ? now_sql() : date('Y-m-d H:i:s'), $imageId]);

    $stmt = db()->prepare('SELECT * FROM images WHERE id = ? LIMIT 1');
    $stmt->execute([$imageId]);
    $updated = $stmt->fetch();
    return is_array($updated) ? $updated : array_merge($image, ['description' => $description]);
}

/**
 * Return up to a few thumbnail references sampled from a gallery branch.
 *
 * @param int $galleryId Gallery identifier.
 * @param int $limit Maximum number of items.
 * @return array<int,array{image_id:int,label:string,detail:string,data_url:string,size:int,format:string,mime_type:string}> Structured result data for the caller.
 */
function openai_text_assist_gallery_thumbnail_references(int $galleryId, int $limit = OPENAI_TEXT_ASSIST_VISUAL_GALLERY_LIMIT): array
{
    $limit = max(1, min(12, $limit));
    $queue = [$galleryId];
    $visited = [];
    $references = [];

    while ($queue !== [] && count($references) < $limit) {
        $currentGalleryId = (int) array_shift($queue);
        if ($currentGalleryId <= 0 || isset($visited[$currentGalleryId])) {
            continue;
        }
        $visited[$currentGalleryId] = true;

        $images = function_exists('gallery_images') ? gallery_images($currentGalleryId, false) : [];
        foreach ($images as $image) {
            try {
                $references[] = openai_text_assist_thumbnail_reference_for_image($image);
            } catch (Throwable) {
                continue;
            }
            if (count($references) >= $limit) {
                break;
            }
        }

        if (count($references) >= $limit) {
            break;
        }

        $children = function_exists('child_galleries') ? child_galleries($currentGalleryId, false) : [];
        foreach ($children as $child) {
            $childId = (int) ($child['id'] ?? 0);
            if ($childId > 0 && !isset($visited[$childId])) {
                $queue[] = $childId;
            }
        }
    }

    if ($references === []) {
        throw new RuntimeException(openai_text_assist_t('admin.openai.error_gallery_thumbnail_missing', 'No generated thumbnails were found in this gallery. Create thumbnails first, then try again.'));
    }

    return $references;
}

/**
 * Build one Responses API input payload, with optional thumbnail image input.
 *
 * @param array<string,mixed> $prompt Prompt instructions and text context.
 * @param array<int,array<string,mixed>> $visualReferences Thumbnail references.
 * @return string|array<int,array<string,mixed>> Structured result data for the caller.
 */
function openai_text_assist_payload_input(array $prompt, array $visualReferences)
{
    if ($visualReferences === []) {
        return (string) ($prompt['input'] ?? '');
    }

    $content = [[
        'type' => 'input_text',
        'text' => (string) ($prompt['input'] ?? ''),
    ]];

    foreach ($visualReferences as $reference) {
        $label = trim((string) ($reference['label'] ?? 'Thumbnail'));
        if ($label !== '') {
            $content[] = [
                'type' => 'input_text',
                'text' => 'Thumbnail sample: ' . $label,
            ];
        }
        $content[] = [
            'type' => 'input_image',
            'image_url' => (string) ($reference['data_url'] ?? ''),
            'detail' => (string) ($reference['detail'] ?? 'low'),
        ];
    }

    return [[
        'role' => 'user',
        'content' => $content,
    ]];
}

/**
 * Generate one OpenAI text suggestion for a user and gallery.
 *
 * @param int $userId User id identifier.
 * @param string $task Task value.
 * @param array<string,mixed> $context Prompt context.
 * @param string $existingText Existing text value.
 * @param string $language Language value.
 * @return array{text:string,model:string} Structured result data for the caller.
 */
function openai_text_assist_generate(int $userId, string $task, array $context, string $existingText, string $language = 'auto'): array
{
    if (!openai_text_assist_available($userId)) {
        throw new RuntimeException(openai_text_assist_t('admin.openai.error_not_enabled', 'OpenAI text assistance is not enabled for this account.'));
    }

    $settings = openai_text_assist_user_settings($userId);
    $apiKey = openai_text_assist_decrypt_secret((string) ($settings['api_key_cipher'] ?? ''));
    if ($apiKey === '') {
        throw new RuntimeException(openai_text_assist_t('admin.openai.error_key_unreadable', 'The saved OpenAI API key could not be read. Save the key again from your profile.'));
    }

    $model = openai_text_assist_normalize_model((string) ($settings['model'] ?? OPENAI_TEXT_ASSIST_DEFAULT_MODEL));
    if (openai_text_assist_task_uses_images($task) && !openai_text_assist_image_input_allowed($userId)) {
        throw new RuntimeException(openai_text_assist_t('admin.openai.error_image_input_disabled', 'Sending thumbnails to OpenAI is disabled for this account. Enable it in your profile first.'));
    }

    $prompt = openai_text_assist_prompt($task, $context, $existingText, $language);
    $visualReferences = [];
    if (openai_text_assist_task_uses_images($task) && isset($context['visual_references']) && is_array($context['visual_references'])) {
        $visualReferences = array_values(array_filter($context['visual_references'], static fn ($item): bool => is_array($item) && !empty($item['data_url'])));
    }
    if (openai_text_assist_task_uses_images($task) && $visualReferences === []) {
        throw new RuntimeException(openai_text_assist_t('admin.openai.error_thumbnail_missing', 'No generated thumbnail was found for this photo. Create thumbnails first, then try again.'));
    }

    $payload = [
        'model' => $model,
        'instructions' => $prompt['instructions'],
        'input' => openai_text_assist_payload_input($prompt, $visualReferences),
        'max_output_tokens' => (int) ($prompt['max_output_tokens'] ?? 1200),
    ];

    $response = openai_text_assist_post_json(OPENAI_TEXT_ASSIST_ENDPOINT, $payload, $apiKey, OPENAI_TEXT_ASSIST_TIMEOUT_SECONDS);
    $text = openai_text_assist_extract_output_text($response);
    if ($text === '') {
        throw new RuntimeException(openai_text_assist_t('admin.openai.error_empty_response', 'OpenAI returned an empty text suggestion. Try again or use the manual description field.'));
    }

    return [
        'text' => openai_text_assist_text_limit($text, 8000),
        'model' => $model,
        'visual_reference_count' => count($visualReferences),
    ];
}

/**
 * POST a JSON payload to OpenAI and return the decoded response.
 *
 * @param string $url URL used by this workflow.
 * @param array<string,mixed> $payload Request body.
 * @param string $apiKey Api key value.
 * @param int $timeoutSeconds Timeout seconds value.
 * @return array<string,mixed> Structured result data for the caller.
 */
function openai_text_assist_post_json(string $url, array $payload, string $apiKey, int $timeoutSeconds): array
{
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($body)) {
        throw new RuntimeException(openai_text_assist_t('admin.openai.error_encode_failed', 'Could not prepare the OpenAI request.'));
    }

    $headers = [
        'Authorization: Bearer ' . $apiKey,
        'Content-Type: application/json',
        'Accept: application/json',
    ];

    if (function_exists('curl_init')) {
        $handle = curl_init($url);
        if ($handle === false) {
            throw new RuntimeException(openai_text_assist_t('admin.openai.error_http_client', 'Could not initialize the OpenAI HTTP client.'));
        }
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => min($timeoutSeconds, 15),
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_USERAGENT => 'PHP-Gallery-CMS/' . (function_exists('cms_current_version') ? cms_current_version() : 'unknown'),
        ]);
        $responseBody = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);
        return openai_text_assist_decode_http_response((string) $responseBody, $status, $error);
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'timeout' => $timeoutSeconds,
            'header' => implode("\r\n", $headers) . "\r\n",
            'content' => $body,
            'ignore_errors' => true,
        ],
    ]);
    $responseBody = @file_get_contents($url, false, $context);
    $status = 0;
    foreach (($http_response_header ?? []) as $line) {
        if (preg_match('/^HTTP\/\S+\s+(\d+)/', $line, $match)) {
            $status = (int) $match[1];
        }
    }
    return openai_text_assist_decode_http_response($responseBody === false ? '' : (string) $responseBody, $status, $responseBody === false ? 'HTTP request failed.' : '');
}

/**
 * Decode one HTTP response from OpenAI.
 *
 * @param string $responseBody Response body value.
 * @param int $status Status value.
 * @param string $transportError Transport error value.
 * @return array<string,mixed> Structured result data for the caller.
 */
function openai_text_assist_decode_http_response(string $responseBody, int $status, string $transportError): array
{
    if ($responseBody === '' || $status >= 400 || $status === 0) {
        $decodedError = json_decode($responseBody, true);
        $message = '';
        if (is_array($decodedError)) {
            $message = (string) ($decodedError['error']['message'] ?? $decodedError['message'] ?? '');
        }
        if ($message === '') {
            $message = $transportError !== '' ? $transportError : 'HTTP status ' . $status;
        }
        throw new RuntimeException(openai_text_assist_t('admin.openai.error_api_failed', 'OpenAI request failed: {message}', ['message' => $message]));
    }

    $decoded = json_decode($responseBody, true);
    if (!is_array($decoded)) {
        throw new RuntimeException(openai_text_assist_t('admin.openai.error_invalid_response', 'OpenAI returned an unreadable response.'));
    }
    return $decoded;
}

/**
 * Extract plain text from a Responses API payload.
 *
 * @param array<string,mixed> $response Decoded OpenAI response.
 * @return string Text result for the caller.
 */
function openai_text_assist_extract_output_text(array $response): string
{
    $direct = trim((string) ($response['output_text'] ?? ''));
    if ($direct !== '') {
        return $direct;
    }

    $parts = [];
    foreach (($response['output'] ?? []) as $item) {
        if (!is_array($item)) {
            continue;
        }
        foreach (($item['content'] ?? []) as $content) {
            if (!is_array($content)) {
                continue;
            }
            $text = trim((string) ($content['text'] ?? ''));
            if ($text !== '') {
                $parts[] = $text;
            }
        }
    }
    return trim(implode("\n\n", $parts));
}
