<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/content_request_language_test.php
 * Module Type: Regression Test
 * Purpose: Verify one effective language for interface and authored metadata.
 * Responsibilities:
 *   - Exercise request, session, and persisted browser preferences without a database
 *   - Preserve source authority, missing-field fallback, and virtual gallery identity
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

declare(strict_types=1);

namespace Gallery\Core {
    /**
     * Return the four maintained language configuration for this fixture.
     * @return array<string,mixed> Isolated application configuration.
     */
    function cms_config(): array
    {
        return ['language' => ['default' => 'en', 'available' => ['en', 'cs', 'de', 'sv']]];
    }

    /**
     * Omit authenticated diagnostics from the isolated language fixture.
     * @return ?array No authenticated account.
     */
    function current_user(): ?array
    {
        return null;
    }
}

namespace Gallery\Services {
    /**
     * Read the isolated language and optional capability settings.
     * @param string $key Setting identifier.
     * @param string|bool|null $default Value used for an absent preference.
     * @return string|bool|null Stored fixture preference or the supplied default.
     */
    function app_setting(string $key, mixed $default = null): mixed
    {
        return $GLOBALS['content_request_settings'][$key] ?? $default;
    }

    /**
     * Resolve the isolated authored-content capability.
     * @param string $key Canonical capability identifier.
     * @return bool True except when the fixture disables authored translations.
     */
    function feature_capability_effective_enabled(string $key): bool
    {
        return $key !== 'multilingual_content' || ($GLOBALS['content_request_enabled'] ?? true);
    }
}

namespace {
    require_once dirname(__DIR__) . '/app/services/translations.php';
    require_once dirname(__DIR__) . '/app/services/content_localization.php';

    use function Gallery\Services\content_localize_entities;
    use function Gallery\Services\content_localize_entity;
    use function Gallery\Services\content_localization_set_loader_for_tests;
    use function Gallery\Services\t;
    use function Gallery\Services\translation_active_language;
    use function Gallery\Services\translation_bootstrap_request;
    use function Gallery\Services\translation_load_language;

    /**
     * Compare one interface/content language invariant.
     * @param scalar|null|array<array-key,mixed> $expected Required value.
     * @param scalar|null|array<array-key,mixed> $actual Observed value.
     * @param string $label Behavior being verified.
     * @return void Throws on a mismatch.
     */
    function content_request_same(mixed $expected, mixed $actual, string $label): void
    {
        if ($expected !== $actual) {
            throw new RuntimeException($label . ': ' . var_export($actual, true));
        }
    }

    $gallery = ['id' => 7, 'title' => 'Source gallery', 'description' => 'Source description', 'content_language' => 'en'];
    $photo = ['id' => 14, 'title' => 'Source photo', 'description' => 'Source caption', 'content_language' => 'en'];
    $loadedIds = [];
    content_localization_set_loader_for_tests(static function (string $type, array $ids, ?string $language) use (&$loadedIds): array {
        $loadedIds[] = $ids;
        $rows = [];
        foreach ($ids as $id) {
            foreach (['cs', 'de', 'sv'] as $code) {
                $rows[$id][$code] = ['title' => $code . ' ' . $type, 'description' => $code . ' description'];
            }
        }
        return $rows;
    });

    $cases = [
        ['home', [], [], 'en'],
        ['home', [], ['query' => ['lang' => 'de']], 'de'],
        ['gallery', [], ['public_cookie' => 'sv'], 'sv'],
        ['gallery', ['cms_public_language_override' => 'cs'], ['public_cookie' => 'de'], 'cs'],
        ['gallery', ['cms_public_language_override' => 'cs'], ['query' => ['lang' => 'sv']], 'sv'],
        ['admin', [], ['admin_cookie' => 'de', 'public_cookie' => 'sv'], 'de'],
        ['admin_edit_gallery', ['cms_admin_language' => 'cs'], ['admin_cookie' => 'de'], 'cs'],
        ['admin_edit_image', [], ['legacy_cookie' => 'sv'], 'sv'],
        ['gallery', [], ['query' => ['lang' => 'invalid']], 'en'],
        ['gallery', [], ['admin_cookie' => 'de'], 'en'],
    ];
    foreach ($cases as [$route, $session, $context, $language]) {
        $_SESSION = $session;
        translation_bootstrap_request($route, $context);
        content_request_same($language, translation_active_language(), $route . ' effective language');
        content_request_same(translation_load_language($language)['public.galleries'], t('public.galleries'), $route . ' UI language');
        $resolved = content_localize_entity('gallery', $gallery);
        $resolvedPhoto = content_localize_entity('image', $photo);
        content_request_same($language === 'en' ? 'Source gallery' : $language . ' gallery', $resolved['title'], $route . ' gallery title');
        content_request_same($language === 'en' ? 'Source description' : $language . ' description', $resolved['description'], $route . ' gallery description');
        content_request_same($language === 'en' ? 'Source photo' : $language . ' image', $resolvedPhoto['title'], $route . ' image title');
        content_request_same($language === 'en' ? 'Source caption' : $language . ' description', $resolvedPhoto['description'], $route . ' image caption');
    }

    $_SESSION = [];
    translation_bootstrap_request('home', ['query' => ['lang' => 'de']]);
    $german = content_localize_entity('gallery', $gallery);
    $again = content_localize_entity('gallery', $german);
    content_request_same($german, $again, 'Repeated localization preserves source provenance');
    translation_bootstrap_request('home', ['query' => ['lang' => 'default'], 'public_cookie' => 'de']);
    content_request_same('Source gallery', content_localize_entity('gallery', $german)['title'], 'Reset restores source from a translated row');
    content_request_same('Source description', content_localize_entity('gallery', $german)['description'], 'Reset restores the source description');
    content_request_same('sv gallery', content_localize_entity('gallery', $gallery, 'sv')['title'], 'Explicit language override remains supported');

    $virtual = ['id' => 7, '__smart_gallery' => true, 'title' => 'Virtual title', 'description' => 'Virtual description'];
    $loadedIds = [];
    content_localization_set_loader_for_tests(static function (string $type, array $ids, ?string $language) use (&$loadedIds): array {
        $loadedIds[] = $ids;
        return [7 => ['de' => ['title' => 'de gallery', 'description' => '']]];
    });
    translation_bootstrap_request('home', ['query' => ['lang' => 'de']]);
    [$resolved, $resolvedVirtual] = content_localize_entities('gallery', [$gallery, $virtual]);
    content_request_same($virtual, $resolvedVirtual, 'Virtual and physical gallery IDs remain separate');
    content_request_same([[7]], $loadedIds, 'Virtual gallery IDs never enter the translation query');
    content_request_same('Source description', $resolved['description'], 'Blank gallery description falls back independently');
    $missing = ['id' => 99] + $gallery;
    content_request_same('Source gallery', content_localize_entity('gallery', $missing)['title'], 'Missing translation preserves source title');
    $GLOBALS['content_request_enabled'] = false;
    content_request_same('Source gallery', content_localize_entity('gallery', $resolved)['title'], 'Capability OFF restores source text');
    content_request_same(translation_load_language('de')['public.galleries'], t('public.galleries'), 'Capability OFF preserves UI language');

    echo "Content request language tests passed.\n";
}
