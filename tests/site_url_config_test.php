<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/site_url_config_test.php
 * Module Type: Regression Test
 *
 * Purpose:
 *   Supports direct configuration persistence for the public website address.
 *
 * Responsibilities:
 *   - Validate installation URLs and preserve unrelated configuration
 *   - Refuse unsafe configuration rewrites without database persistence
 *
 * Author:
 *   Rudolf Klusal
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 */

declare(strict_types=1);

namespace Gallery\Core {
    /** Return the disposable fixture configuration path. @return string Fixture file path. */
    function cms_config_path(): string { return $GLOBALS['site_url_test_path']; }
}
namespace {
    require __DIR__ . '/../app/services/site_url.php';
    use function Gallery\Services\site_url_normalize;
    use function Gallery\Services\site_url_config_source;
    use function Gallery\Services\site_url_save;

    foreach (['https://example.com/', 'https://example.org/gallery///', 'http://localhost:8000'] as $url) {
        if (site_url_normalize($url) !== rtrim($url, '/')) {
            throw new RuntimeException('URL normalization failed.');
        }
    }
    foreach (['', null, [], '/subdom/galerie', 'javascript:alert(1)', 'https://user:pass@example.org', 'https://example.org?q=1', 'https://example.org#x', "https://example.org/\npath", 'https://example.org\\path'] as $url) {
        try {
            site_url_normalize($url);
            throw new RuntimeException('Invalid URL was accepted.');
        } catch (InvalidArgumentException $expected) {
        }
    }
    $source = "<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/site_url_config_test.php
 * Module Type: Regression Test
 *
 * Purpose:
 *   Supports direct configuration persistence for the public website address.
 *
 * Responsibilities:
 *   - Validate installation URLs and preserve unrelated configuration
 *   - Refuse unsafe configuration rewrites without database persistence
 *
 * Author:
 *   Rudolf Klusal
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 */
\n// Keep local configuration.\nreturn [\n 'nested' => ['base_url' => 'keep'],\n 'base_url' /* comment */ => 'https://old.test/subdom/galerie',\n 'secret' => 'unchanged',\n];\n";
    $expected = str_replace("'https://old.test/subdom/galerie'", "'https://example.com'", $source);
    if (site_url_config_source($source, 'https://example.com/') !== $expected) {
        throw new RuntimeException('Unrelated configuration changed.');
    }
    foreach ([
        "<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/site_url_config_test.php
 * Module Type: Regression Test
 *
 * Purpose:
 *   Supports direct configuration persistence for the public website address.
 *
 * Responsibilities:
 *   - Validate installation URLs and preserve unrelated configuration
 *   - Refuse unsafe configuration rewrites without database persistence
 *
 * Author:
 *   Rudolf Klusal
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 */
 return ['base_url' => getenv('URL')];",
        "<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/site_url_config_test.php
 * Module Type: Regression Test
 *
 * Purpose:
 *   Supports direct configuration persistence for the public website address.
 *
 * Responsibilities:
 *   - Validate installation URLs and preserve unrelated configuration
 *   - Refuse unsafe configuration rewrites without database persistence
 *
 * Author:
 *   Rudolf Klusal
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 */
 return ['base_url' => 'https://old.test' . '/path'];",
        "<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/site_url_config_test.php
 * Module Type: Regression Test
 *
 * Purpose:
 *   Supports direct configuration persistence for the public website address.
 *
 * Responsibilities:
 *   - Validate installation URLs and preserve unrelated configuration
 *   - Refuse unsafe configuration rewrites without database persistence
 *
 * Author:
 *   Rudolf Klusal
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 */
 return ['base_url' => '', 'base_url' => ''];",
        "<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/site_url_config_test.php
 * Module Type: Regression Test
 *
 * Purpose:
 *   Supports direct configuration persistence for the public website address.
 *
 * Responsibilities:
 *   - Validate installation URLs and preserve unrelated configuration
 *   - Refuse unsafe configuration rewrites without database persistence
 *
 * Author:
 *   Rudolf Klusal
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 */
 return ['nested' => ['base_url' => '']];",
    ] as $unsafe) {
        try {
            site_url_config_source($unsafe, 'https://example.org');
            throw new LogicException('Unsafe configuration accepted.');
        } catch (RuntimeException $expectedError) {
        }
    }
    $directory = sys_get_temp_dir() . '/gallery-site-url-' . bin2hex(random_bytes(6));
    mkdir($directory);
    $GLOBALS['site_url_test_path'] = $directory . '/config.php';
    try {
        file_put_contents($GLOBALS['site_url_test_path'], $source);
        site_url_save('https://example.com');
        if (file_get_contents($GLOBALS['site_url_test_path']) !== $expected) {
            throw new RuntimeException('Persistence changed unrelated content.');
        }
        $loaded = require $GLOBALS['site_url_test_path'];
        if ($loaded['base_url'] !== 'https://example.com' || $loaded['secret'] !== 'unchanged') {
            throw new RuntimeException('Saved configuration cannot be loaded.');
        }
        file_put_contents($GLOBALS['site_url_test_path'], "<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/site_url_config_test.php
 * Module Type: Regression Test
 *
 * Purpose:
 *   Supports direct configuration persistence for the public website address.
 *
 * Responsibilities:
 *   - Validate installation URLs and preserve unrelated configuration
 *   - Refuse unsafe configuration rewrites without database persistence
 *
 * Author:
 *   Rudolf Klusal
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 */
 return ['base_url' => getenv('URL')];");
        $before = file_get_contents($GLOBALS['site_url_test_path']);
        try {
            site_url_save('https://example.org');
            throw new LogicException('Computed configuration accepted.');
        } catch (RuntimeException $expectedError) {
        }
        if (file_get_contents($GLOBALS['site_url_test_path']) !== $before) {
            throw new RuntimeException('Refused write changed configuration.');
        }
    } finally {
        foreach (glob($directory . '/*') as $file) {
            unlink($file);
        }
        rmdir($directory);
    }
    echo "Site URL config persistence: PASS\n";
}
