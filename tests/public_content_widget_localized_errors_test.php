<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/public_content_widget_localized_errors_test.php
 * Module Type: Localization Regression Test
 * Purpose: Cover all widget validation error messages in EN/CS/DE/SV.
 * Responsibilities:
 *   - Keep the real controller translation boundary independent of application login/config.
 *   - Reject untranslated field, safe-link, revision and mutation errors.
 *   - Ensure rejected AJAX preview and ordinary form paths share the same safe message owner.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Services {
    /**
     * Read the selected maintained language map without installing application state.
     *
     * @param string $key Existing catalog key.
     * @param mixed $default Service-supplied English fallback or null.
     * @param array<string,mixed> $parameters Optional unused replacement parameters.
     * @return string Selected real catalog string or its explicit English fallback.
     */
    function t(string $key, mixed $default = null, array $parameters = []): string
    {
        $value = $GLOBALS['widget_error_test_catalog'][$key] ?? $default;
        return is_string($value) ? $value : $key;
    }
}

namespace {
    require_once dirname(__DIR__) . '/app/controllers/admin_public_widgets.php';

    use Gallery\Services\PublicWidgetInvalidField;
    use function Gallery\Controllers\admin_public_widget_error_message;

    $root = dirname(__DIR__);
    $controllers = (string) file_get_contents($root . '/app/controllers/admin_public_widgets.php');
    $service = (string) file_get_contents($root . '/app/services/public_content_widgets.php');
    $sources = [$controllers, $service];
    $messages = [];
    foreach ($sources as $source) {
        preg_match_all('/new\s+PublicWidgetInvalidField\(\s*[^,\r\n]+,\s*\'([^\']+)\'\s*\)/', $source, $found);
        foreach ($found[1] as $message) {
            $messages[$message] = true;
        }
    }
    if (count($messages) !== 25) {
        throw new \RuntimeException('Expected all 25 currently owned widget validation messages to be catalogued.');
    }

    $baselineKeys = null;
    foreach (['en', 'cs', 'de', 'sv'] as $language) {
        $path = $root . '/app/lang/' . $language . '.json';
        $catalog = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($catalog)) {
            throw new \RuntimeException('Invalid maintained widget translation catalog.');
        }
        $keys = array_keys($catalog);
        if ($baselineKeys === null) {
            $baselineKeys = $keys;
        } elseif ($keys !== $baselineKeys) {
            throw new \RuntimeException('Maintained translation key insertion order differs for ' . $language . '.');
        }
        $GLOBALS['widget_error_test_catalog'] = $catalog;
        $generic = (string) ($catalog['admin.widgets.validation.generic'] ?? '');
        if ($generic === '') {
            throw new \RuntimeException('Missing localized generic fallback for ' . $language . '.');
        }
        foreach (array_keys($messages) as $message) {
            $translated = admin_public_widget_error_message(new PublicWidgetInvalidField('content_md', $message));
            if ($translated === '' || $translated === $generic || !in_array($translated, $catalog, true)) {
                throw new \RuntimeException('Unlocalized widget validation for ' . $language . ': ' . $message);
            }
            if ($language !== 'en' && $translated === $message) {
                throw new \RuntimeException('English widget validation leaked into ' . $language . ' Admin UI.');
            }
        }
        $unknown = admin_public_widget_error_message(
            new PublicWidgetInvalidField('widget_id', 'Internal details must not be exposed.')
        );
        if ($unknown !== $generic) {
            throw new \RuntimeException('Unknown domain message bypassed the safe translated fallback.');
        }
    }
    unset($GLOBALS['widget_error_test_catalog']);
    if (substr_count($controllers, 'admin_public_widget_error_message($failure)') !== 2) {
        throw new \RuntimeException('Both Admin POST/JSON and GET editor errors must be translated.');
    }
    echo "public_content_widget_localized_errors_test: PASS\n";
}
