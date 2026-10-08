<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/theme_advanced_appearance_test.php
 * Module Type: Regression Test
 * Purpose: Preserve normalized public appearance defaults, bounded persistence and reset isolation.
 * Responsibilities: Exercise the real Theme service with disposable in-memory settings only.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);
namespace Gallery\Services {
    /**
     * Read only the disposable appearance map.
     * @param string $key Stable persisted preference.
     * @param string|null $default Missing preference fallback.
     * @return string|null Stored fixture value or the supplied fallback.
     */
    function app_setting(string $key, ?string $default = null): ?string { return $GLOBALS['advanced_settings'][$key] ?? $default; }
    /**
     * Record a normalized preference in disposable memory.
     * @param string $key Stable persisted preference.
     * @param string $value Canonical scalar value.
     * @return void Updates only the fixture map.
     */
    function set_app_setting(string $key, string $value): void { $GLOBALS['advanced_settings'][$key] = $value; }
    /**
     * Remove exactly the service-selected disposable preference keys.
     * @param list<string> $keys Stable preference identifiers.
     * @return void Deletes only the selected in-memory entries.
     */
    function delete_app_settings(array $keys): void { foreach ($keys as $key) unset($GLOBALS['advanced_settings'][$key]); }
}
namespace {
    require dirname(__DIR__) . '/app/services/theme.php';
    /**
     * Require an observable Theme invariant.
     * @param bool $condition Required result.
     * @param string $message Failure context.
     * @return void Throws on regression.
     */
    function advanced_require(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
    $GLOBALS['advanced_settings'] = [];
    $defaults = \Gallery\Services\theme_advanced_appearance_settings();
    advanced_require(\Gallery\Services\theme_advanced_appearance_css($defaults) === '', 'upgrade defaults must leave the original CSS appearance exactly intact');
    foreach (\Gallery\Services\theme_advanced_appearance_definitions() as $key => $definition) {
        advanced_require(\Gallery\Services\theme_advanced_appearance_value($key, 'invalid') === $definition['default'], 'corrupt saved value must recover: ' . $key);
    }
    \Gallery\Services\theme_advanced_appearance_save(['gallery_grid_gap'=>'999','gallery_card_padding'=>'-8','card_shadow'=>'none','public_type_scale'=>'120','header_transparent'=>'1']);
    $saved = \Gallery\Services\theme_advanced_appearance_settings();
    advanced_require($saved['gallery_grid_gap'] === '64' && $saved['gallery_card_padding'] === '0' && $saved['public_type_scale'] === '120', 'numeric inputs must be bounded through one service');
    $css = \Gallery\Services\theme_advanced_appearance_css($saved);
    advanced_require(str_contains($css, '.public-page .site-header{background:transparent;') && str_contains($css, '.site-header::before') && str_contains($css, 'backdrop-filter:none') && str_contains($css, 'box-shadow:none'), 'transparent header must remove every container backdrop');
    advanced_require(!str_contains($css, '.admin-page') && !str_contains($css, 'opacity:0') && !str_contains($css, '.hero{') && !str_contains($css, '.lightbox'), 'public controls must preserve Admin, header content, hero backdrop and viewers');
    \Gallery\Services\theme_advanced_appearance_reset('header_transparent');
    $reset = \Gallery\Services\theme_advanced_appearance_settings();
    advanced_require($reset['header_transparent'] === '0' && $reset['gallery_grid_gap'] === '64' && $reset['card_shadow'] === 'none', 'reset must restore exactly one control');
    advanced_require(!str_contains(\Gallery\Services\theme_advanced_appearance_css($reset), '.site-header'), 'reset must return to the existing header CSS');
    $before = $GLOBALS['advanced_settings'];
    try { \Gallery\Services\theme_advanced_appearance_save(['gallery_card_padding'=>'20','unknown'=>'1']); throw new RuntimeException('unknown key accepted'); } catch (InvalidArgumentException) {}
    advanced_require($GLOBALS['advanced_settings'] === $before, 'invalid batch must not partly save appearance');
    echo "PASS advanced Theme appearance\n";
}
