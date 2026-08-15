<?php

/** Verify multilingual content normalization, fallback, batching, and cache behavior. */

declare(strict_types=1);

namespace Gallery\Core {
    /** Return the maintained-language fixture used by this isolated test. */
    function cms_config(): array
    {
        return ['language' => ['default' => 'en', 'available' => ['en', 'cs', 'de', 'sv']]];
    }
}

namespace Gallery\Services {
    require_once dirname(__DIR__) . '/app/services/translations.php';

    /**
     * Return an available column-inspection fixture.
     *
     * @param string $table Table name.
     * @param string $column Column name.
     * @return array<string,string> Available result.
     */
    function schema_inspection_column(string $table, string $column): array { return ['state' => 'available']; }
    /**
     * Return an available table-inspection fixture.
     *
     * @param string $table Table name.
     * @return array<string,string> Available result.
     */
    function schema_inspection_table(string $table): array { return ['state' => 'available']; }
    /**
     * Aggregate available inspection fixtures for the isolated service load.
     *
     * @param string $feature Feature identifier.
     * @param array<int,array<string,mixed>> $requirements Requirement fixtures.
     * @return array<string,mixed> Available feature result.
     */
    function schema_inspection_feature(string $feature, array $requirements): array { return ['state' => 'available', 'feature' => $feature, 'requirements' => $requirements]; }
    /**
     * Return whether the isolated inspection fixture is available.
     *
     * @param array<string,mixed> $result Inspection result.
     * @return bool True for available state.
     */
    function schema_inspection_is_available(array $result): bool { return ($result['state'] ?? '') === 'available'; }

    require_once dirname(__DIR__) . '/app/services/content_localization.php';

    /**
     * Assert strict equality for one content-localization behavior.
     *
     * @param mixed $expected Expected value.
     * @param mixed $actual Actual value.
     * @param string $label Assertion label.
     */
    function content_test_same(mixed $expected, mixed $actual, string $label): void
    {
        if ($expected !== $actual) {
            throw new \RuntimeException($label . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
        }
    }

    content_test_same(['en', 'cs', 'de', 'sv'], content_supported_languages(), 'Maintained language order');
    content_test_same(null, content_language_normalize('CS-cz'), 'Regional locale rejected in favor of architecture code');
    content_test_same(null, content_language_normalize('fr'), 'Dormant language rejected');
    content_test_same(null, content_language_normalize(''), 'Blank source remains unspecified');
    content_test_same('de_DE', content_language_og_locale('de'), 'German Open Graph locale');
    content_test_same('en_GB', content_language_og_locale('invalid'), 'Invalid Open Graph locale fallback');

    $base = ['id' => 7, 'title' => 'Praha', 'description' => 'Český text', 'content_language' => 'cs'];
    $translated = content_localized_fields($base, 'de', ['de' => ['title' => 'Prag', 'description' => 'Deutscher Text']]);
    content_test_same('Prag', $translated['title'], 'Translated title selected');
    content_test_same('Deutscher Text', $translated['description'], 'Translated description selected');
    content_test_same('translation', $translated['title_source'], 'Title provenance');

    $partial = content_localized_fields($base, 'de', ['de' => ['title' => 'Prag', 'description' => '']]);
    content_test_same('Prag', $partial['title'], 'Partial title selected');
    content_test_same('Český text', $partial['description'], 'Partial description falls back independently');
    $photoCaption = content_localized_fields($base, 'de', ['de' => ['title' => 'Prag', 'description' => '']], false);
    content_test_same('Prag', $photoCaption['title'], 'Photo translated title selected');
    content_test_same('', $photoCaption['description'], 'Photo caption does not mix source description into translated variant');
    $source = content_localized_fields($base, 'cs', ['cs' => ['title' => 'Wrong duplicate', 'description' => 'Wrong']]);
    content_test_same('Praha', $source['title'], 'Source language ignores duplicate translation');

    $calls = 0;
    content_localization_set_loader_for_tests(static function (string $type, array $ids, ?string $language) use (&$calls): array {
        $calls++;
        content_test_same('gallery', $type, 'Batch entity type');
        content_test_same([1, 2], $ids, 'Batch identifiers');
        content_test_same('sv', $language, 'Batch language');
        return [
            1 => ['sv' => ['title' => 'Ett', 'description' => 'Beskrivning']],
            2 => ['sv' => ['title' => '', 'description' => 'Andra']],
        ];
    });
    $rows = [
        ['id' => 2, 'title' => 'Two', 'description' => 'Second', 'content_language' => 'en'],
        ['id' => 1, 'title' => 'One', 'description' => 'First', 'content_language' => 'en'],
    ];
    $localized = content_localize_entities('gallery', $rows, 'sv');
    content_test_same('Andra', $localized[0]['description'], 'Batch description overlay');
    content_test_same('Two', $localized[0]['title'], 'Blank batch title fallback');
    content_test_same('Ett', $localized[1]['title'], 'Batch title overlay');
    content_localize_entities('gallery', $rows, 'sv');
    content_test_same(1, $calls, 'Batch result cached per request');
    content_localization_reset_request_cache();
    content_localize_entities('gallery', $rows, 'sv');
    content_test_same(2, $calls, 'Cache reset reloads rows');

    try {
        content_localization_storage('tag');
        throw new \RuntimeException('Unsupported entity type was accepted.');
    } catch (\InvalidArgumentException) {
    }

    echo "Content localization model tests passed.\n";
}
