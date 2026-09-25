<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/gallery_creation_preferences_test.php
 * Module Type: Regression Test
 * Purpose: Verify per-admin gallery creation defaults and selected-field updates.
 * Responsibilities: Exercise isolated defaults, partial updates, and schema refusal.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

declare(strict_types=1);

namespace Gallery\Models {
    /** Read one administrator's fixture row. @param int $userId Administrator ID. @return ?array<string,mixed> Fixture row. */
    function gallery_creation_preferences_model_find(int $userId): ?array
    {
        return $GLOBALS['gallery_creation_test_rows'][$userId] ?? null;
    }

    /** Save one administrator's fixture row. @param int $userId Administrator ID. @param array<string,string> $values Saved fields. @param string $now SQL timestamp. @return void Store one fixture row. */
    function gallery_creation_preferences_model_save(int $userId, array $values, string $now): void
    {
        $GLOBALS['gallery_creation_test_rows'][$userId] = $values;
    }
}

namespace Gallery\Core {
    /** Escape one fixture value for rendered HTML. @param string $value Raw value. @return string HTML-safe value. */
    function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** Build a deterministic fixture route. @param string $route Route name. @param array<string,mixed> $params Query fields. @return string Fixture URL. */
    function url_for(string $route, array $params = []): string
    {
        return '/?' . http_build_query(['route' => $route] + $params);
    }

    /** Provide one hidden fixture CSRF field. @return string Form field HTML. */
    function csrf_field(): string
    {
        return '<input type="hidden" name="csrf_token" value="fixture">';
    }

    /** Return a fixed fixture timestamp. @return string Fixed fixture timestamp. */
    function now_sql(): string
    {
        return '2026-09-25 12:00:00';
    }
}

namespace Gallery\Services {
    /** Resolve fixture labels from English fallback text. @param string $key Translation key. @param string $fallback Fallback text. @param array<string,mixed> $params Text placeholders. @return string Fixture label. */
    function t(string $key, string $fallback = '', array $params = []): string
    {
        foreach ($params as $name => $value) {
            $fallback = str_replace('{' . $name . '}', (string) $value, $fallback);
        }
        return $fallback;
    }

    /** Describe a fixture table requirement. @param string $table Table name. @return array<string,string> Fixture requirement. */
    function schema_inspection_table(string $table): array
    {
        return ['table' => $table];
    }

    /** Describe a fixture column requirement. @param string $table Table name. @param string $column Column name. @return array<string,string> Fixture requirement. */
    function schema_inspection_column(string $table, string $column): array
    {
        return ['table' => $table, 'column' => $column];
    }

    /** Return the current fixture schema state. @param string $name Feature name. @param array<int,array<string,string>> $requirements Requirements. @return array<string,string> Fixture state. */
    function schema_inspection_feature(string $name, array $requirements): array
    {
        return ['state' => $GLOBALS['gallery_creation_test_schema']];
    }

    /** Resolve fixture schema availability. @param array<string,string> $status Fixture state. @return bool Whether storage is available. */
    function schema_inspection_is_available(array $status): bool
    {
        return ($status['state'] ?? '') === 'available';
    }

    /** Return supported fixture languages. @return list<string> Supported source languages. */
    function content_supported_languages(): array
    {
        return ['en', 'cs'];
    }
}

namespace {
    require_once dirname(__DIR__) . '/app/services/gallery_creation_preferences.php';

    /**
     * Require one expected preference behavior.
     *
     * @param bool $condition Expected condition.
     * @param string $message Failure explanation.
     * @return void Throw when the condition fails.
     */
    function creation_pref_assert(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new \RuntimeException($message);
        }
    }

    $GLOBALS['gallery_creation_test_rows'] = [];
    $GLOBALS['gallery_creation_test_schema'] = 'available';
    \Gallery\Services\gallery_creation_preferences_remember(11, [
        'simbrief_pilot_id' => 'pilot-11',
        'simbrief_pilot_name' => 'First Pilot',
        'content_language' => 'cs',
        'remember_simbrief_pilot_id' => 1,
        'remember_content_language' => 1,
    ]);
    creation_pref_assert(\Gallery\Services\gallery_creation_preferences_for_user(11) === [
        'simbrief_pilot_id' => 'pilot-11',
        'simbrief_pilot_name' => '',
        'content_language' => 'cs',
    ], 'Selected defaults were not saved independently.');
    creation_pref_assert(\Gallery\Services\gallery_creation_preferences_for_user(12)['simbrief_pilot_id'] === '',
        'Another administrator inherited personal defaults.');

    \Gallery\Services\gallery_creation_preferences_remember(11, [
        'simbrief_pilot_id' => 'one-off',
        'simbrief_pilot_name' => 'Second Pilot',
        'content_language' => '',
        'remember_simbrief_pilot_name' => 1,
        'remember_content_language' => 1,
    ]);
    $saved = \Gallery\Services\gallery_creation_preferences_for_user(11);
    creation_pref_assert($saved['simbrief_pilot_id'] === 'pilot-11' && $saved['simbrief_pilot_name'] === 'Second Pilot'
        && $saved['content_language'] === '', 'An unselected field changed or an explicit language clear failed.');

    try {
        \Gallery\Services\gallery_creation_preferences_validate(['content_language' => 'xx', 'remember_content_language' => 1]);
        throw new \RuntimeException('Unsupported language was accepted.');
    } catch (\RuntimeException $exception) {
        creation_pref_assert($exception->getMessage() === 'Select a supported description language.',
            'Unexpected validation outcome for unsupported language.');
    }
    $GLOBALS['gallery_creation_test_schema'] = 'unknown';
    creation_pref_assert(\Gallery\Services\gallery_creation_preferences_for_user(11)['simbrief_pilot_id'] === '',
        'Unknown preference storage exposed stale defaults.');

    require_once dirname(__DIR__) . '/app/views/admin_gallery_forms.php';
    ob_start();
    \Gallery\Views\view_render_admin_new_gallery_side_panel(7, null, '', [
        'operation_key' => 'fixture-key',
        'title_completion' => ['url' => '/title-suggestions'],
    ]);
    $createHtml = (string) ob_get_clean();
    preg_match_all('/<input\b[^>]*\bname="([^"]+)"/', $createHtml, $matches);
    $fieldNames = $matches[1];
    sort($fieldNames);
    creation_pref_assert($fieldNames === ['csrf_token', 'operation_key', 'panel', 'parent_id', 'title']
        && !str_contains($createHtml, '<textarea') && !str_contains($createHtml, '<select'),
        'The panel create step must ask only for a gallery name.');
    creation_pref_assert(str_contains($createHtml, 'data-gallery-title-completion-url="/title-suggestions"')
        && str_contains($createHtml, 'name="parent_id" value="7"'),
        'The name-only step lost title completion or its hidden parent.');

    $editorModel = [
        'creation_preferences' => ['simbrief_pilot_id' => 'pilot-11', 'simbrief_pilot_name' => 'Second Pilot', 'content_language' => 'cs'],
        'creation_preferences_available' => true,
        'localization' => ['enabled' => true, 'schema_ready' => true, 'languages' => ['en', 'cs'], 'presentation' => [], 'translations' => []],
    ];
    ob_start();
    \Gallery\Views\view_render_content_localization_fields('gallery', ['id' => 7, 'content_language' => ''], $editorModel);
    $languageHtml = (string) ob_get_clean();
    creation_pref_assert(str_contains($languageHtml, '<option value="cs" data-flag-src="" selected>')
        && str_contains($languageHtml, 'name="remember_content_language"')
        && str_contains($languageHtml, 'Pre-filled from your saved defaults.'),
        'The editor did not show and identify the pre-filled source language.');

    ob_start();
    \Gallery\Views\view_render_admin_simbrief_description_tool(7, $editorModel);
    $simbriefHtml = (string) ob_get_clean();
    creation_pref_assert(str_contains($simbriefHtml, 'name="simbrief_identifier" value="pilot-11"')
        && str_contains($simbriefHtml, 'name="remember_simbrief_identifier"')
        && substr_count($simbriefHtml, 'Pre-filled from your saved defaults.') === 1,
        'The editor did not expose saved SimBrief defaults and their save controls.');
    echo "gallery creation preferences: PASS\n";
}
