<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/translation_catalog_consistency_test.php
 * Module Type: Regression Test
 *
 * Purpose:
 *   Keeps the four maintained selectable catalogs and dormant future-language
 *   skeletons aligned with the application translation model.
 *
 * Responsibilities:
 *   - Require English, Czech, German, and Swedish JSON catalogs to stay complete
 *   - Require every literal translation call to have an English catalog entry
 *   - Require placeholder names to match across all maintained translations
 *   - Allow dormant future languages to translate only safe subsets of English keys
 *   - Verify that English remains the configured and code-level fallback language
 *   - Verify that only the four maintained languages are selectable
 *   - Verify that Admin and public selectors filter detected packs by support policy
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
 *   - The test intentionally checks literal translation keys only. Dynamic keys
 *     cannot be proven complete by static scanning.
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$enPath = $root . '/app/lang/en.json';
$maintainedLanguages = [
    'en' => 'English',
    'cs' => 'Čeština',
    'de' => 'Deutsch',
    'sv' => 'Svenska',
];
$dormantLanguages = [
    'no' => 'Norsk',
    'is' => 'Íslenska',
    'da' => 'Dansk',
    'fr' => 'Français',
    'it' => 'Italiano',
    'es' => 'Español',
];

/**
 * Fail this regression test with one useful message.
 */
function translation_catalog_test_fail(string $message): never
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

/**
 * Load one JSON translation catalog as a flat string dictionary.
 *
 * @return array<string,string>
 */
function translation_catalog_test_load(string $path): array
{
    $json = @file_get_contents($path);
    if (!is_string($json)) {
        translation_catalog_test_fail('Could not read translation catalog: ' . $path);
    }
    try {
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable $exception) {
        translation_catalog_test_fail('Invalid translation JSON in ' . $path . ': ' . $exception->getMessage());
    }
    if (!is_array($decoded)) {
        translation_catalog_test_fail('Translation catalog is not a JSON object: ' . $path);
    }
    $result = [];
    foreach ($decoded as $key => $value) {
        if (!is_string($key) || !is_string($value)) {
            translation_catalog_test_fail('Translation catalog must contain only string keys and values: ' . $path);
        }
        $result[$key] = $value;
    }
    return $result;
}

/**
 * Return placeholder names used in one translation value.
 *
 * @return list<string>
 */
function translation_catalog_test_placeholders(string $value): array
{
    preg_match_all('/\{([A-Za-z0-9_]+)\}/', $value, $matches);
    $names = $matches[1] ?? [];
    sort($names, SORT_STRING);
    return array_values($names);
}

$en = translation_catalog_test_load($enPath);

foreach ($maintainedLanguages as $languageCode => $languageName) {
    $catalogPath = $root . '/app/lang/' . $languageCode . '.json';
    $catalog = translation_catalog_test_load($catalogPath);
    if (($catalog['_language_name'] ?? '') !== $languageName) {
        translation_catalog_test_fail(
            'Maintained language pack ' . $languageCode . ' must declare native name "' . $languageName . '".'
        );
    }

    $missing = array_values(array_diff(array_keys($en), array_keys($catalog)));
    sort($missing, SORT_STRING);
    if ($missing !== []) {
        translation_catalog_test_fail(
            'Maintained language pack ' . $languageCode . ' is missing English keys: ' . implode(', ', $missing)
        );
    }

    $extra = array_values(array_diff(array_keys($catalog), array_keys($en)));
    sort($extra, SORT_STRING);
    if ($extra !== []) {
        translation_catalog_test_fail(
            'Maintained language pack ' . $languageCode . ' contains keys absent from English: ' . implode(', ', $extra)
        );
    }

    foreach ($en as $key => $englishValue) {
        $englishPlaceholders = translation_catalog_test_placeholders($englishValue);
        $translatedPlaceholders = translation_catalog_test_placeholders($catalog[$key]);
        if ($englishPlaceholders !== $translatedPlaceholders) {
            translation_catalog_test_fail(
                'Placeholder mismatch for ' . $key . ' in ' . $languageCode . ': EN {'
                . implode(', ', $englishPlaceholders) . '} vs translation {'
                . implode(', ', $translatedPlaceholders) . '}'
            );
        }
    }
}

foreach ($dormantLanguages as $languageCode => $languageName) {
    $dormantPath = $root . '/app/lang/' . $languageCode . '.json';
    $dormant = translation_catalog_test_load($dormantPath);
    if (($dormant['_language_name'] ?? '') !== $languageName) {
        translation_catalog_test_fail(
            'Dormant language pack ' . $languageCode . ' must declare native name "' . $languageName . '".'
        );
    }

    foreach ($dormant as $key => $translatedValue) {
        if (str_starts_with($key, '_')) {
            continue;
        }
        if (!array_key_exists($key, $en)) {
            translation_catalog_test_fail(
                'Dormant language pack ' . $languageCode . ' contains a key absent from English: ' . $key
            );
        }
        $englishPlaceholders = translation_catalog_test_placeholders($en[$key]);
        $translatedPlaceholders = translation_catalog_test_placeholders($translatedValue);
        if ($englishPlaceholders !== $translatedPlaceholders) {
            translation_catalog_test_fail(
                'Placeholder mismatch for ' . $key . ' in dormant pack ' . $languageCode . ': EN {'
                . implode(', ', $englishPlaceholders) . '} vs translation {'
                . implode(', ', $translatedPlaceholders) . '}'
            );
        }
    }
}

$translationCallPattern = '/(?:\\b(?:t|i18n|admin_log_english_t|gallery_migration_t))\\(\\s*([\'\"])([^\'\"]+)\\1\\s*,/s';
$missingEnglish = [];
$scanRoots = [$root . '/app', $root . '/public'];
foreach ($scanRoots as $scanRoot) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($scanRoot, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $fileInfo) {
        if (!$fileInfo->isFile()) {
            continue;
        }
        $extension = strtolower($fileInfo->getExtension());
        if ($extension !== 'php' && $extension !== 'js') {
            continue;
        }
        $source = @file_get_contents($fileInfo->getPathname());
        if (!is_string($source)) {
            translation_catalog_test_fail('Could not read source file during translation scan: ' . $fileInfo->getPathname());
        }
        preg_match_all($translationCallPattern, $source, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $key = (string) ($match[2] ?? '');
            if ($key !== '' && !array_key_exists($key, $en)) {
                $relative = ltrim(str_replace($root, '', $fileInfo->getPathname()), DIRECTORY_SEPARATOR);
                $missingEnglish[$key][] = $relative;
            }
        }
    }
}

if ($missingEnglish !== []) {
    ksort($missingEnglish, SORT_STRING);
    $details = [];
    foreach ($missingEnglish as $key => $files) {
        $details[] = $key . ' [' . implode(', ', array_values(array_unique($files))) . ']';
    }
    translation_catalog_test_fail('English catalog is missing literal translation keys: ' . implode('; ', $details));
}

$translationsSource = @file_get_contents($root . '/app/services/translations.php');
if (
    !is_string($translationsSource)
    || !str_contains($translationsSource, "const CMS_SELECTABLE_LANGUAGES = ['en', 'cs', 'de', 'sv'];")
    || !str_contains($translationsSource, "return 'en';")
) {
    translation_catalog_test_fail('translations.php no longer enforces the four selectable languages with English fallback.');
}

$configExample = @file_get_contents($root . '/config.example.php');
if (!is_string($configExample) || !preg_match("/'language'\\s*=>\\s*\\[[\\s\\S]*?'default'\\s*=>\\s*'en'/", $configExample)) {
    translation_catalog_test_fail('config.example.php no longer configures English as the default language.');
}
if (!preg_match("/'available'\\s*=>\\s*\\[([^\\]]*)\\]/s", $configExample, $availableMatch)) {
    translation_catalog_test_fail('config.example.php has no readable selectable language list.');
}
preg_match_all("/'([a-z]{2}(?:-[a-z]{2})?)'/", (string) ($availableMatch[1] ?? ''), $availableCodesMatch);
$configuredLanguages = array_values($availableCodesMatch[1] ?? []);
if ($configuredLanguages !== array_keys($maintainedLanguages)) {
    translation_catalog_test_fail(
        'config.example.php selectable languages must be exactly: ' . implode(', ', array_keys($maintainedLanguages))
    );
}
foreach (array_keys($dormantLanguages) as $languageCode) {
    if (in_array($languageCode, $configuredLanguages, true)) {
        translation_catalog_test_fail('Dormant language unexpectedly selectable in config.example.php: ' . $languageCode);
    }
}

$adminThemeSource = @file_get_contents($root . '/app/controllers/admin_theme_language.php');
if (
    !is_string($adminThemeSource)
    || !str_contains($adminThemeSource, '<select name="cms_language">')
    || !str_contains($adminThemeSource, '<select name="public_language">')
    || !str_contains($adminThemeSource, 'translation_supported_languages()')
    || !str_contains($adminThemeSource, 'translation_detected_language_packs()')
    || substr_count($adminThemeSource, 'foreach ($languagePacks as $languagePack)') < 2
) {
    translation_catalog_test_fail('Admin Theme no longer filters detected packs into supported Admin/public selectors.');
}

fwrite(
    STDOUT,
    'Translation catalogs are aligned: ' . count($en)
    . " keys across four maintained selectable languages, " . count($dormantLanguages)
    . " dormant future packs validated, placeholders matched, English fallback preserved.\n"
);
