<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/site_url_reversible_test.php
 * Module Type: Test Script
 *
 * Purpose:
 *   Verifies reversible website URL writes against an isolated config.php fixture.
 *
 * Responsibilities:
 *   - Preserve exact source bytes when restoring a legacy empty base_url
 *   - Preserve unrelated UTF-8 and filesystem-path configuration
 *   - Refuse compensation after an unrelated concurrent config change
 *
 * Author:
 *   Rudolf Klusal
 *
 * Contact:
 *   https://github.com/klusik
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 */

declare(strict_types=1);

namespace Gallery\Tests\SiteUrlReversible {
    use RuntimeException;

    /**
     * Assert one reversible URL behavior.
     *
     * @param bool $condition Evaluated condition.
     * @param string $message Failure message.
     * @return void
     */
    function assert_true(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }
}

namespace Gallery\Core {
    /**
     * Return the isolated site URL config fixture path.
     *
     * @return string Isolated site URL config fixture path.
     */
    function cms_config_path(): string
    {
        return (string) $GLOBALS['site_url_fixture_path'];
    }
}

namespace Gallery\Services {
    require_once __DIR__ . '/../app/services/site_url.php';
}

namespace Gallery\Tests\SiteUrlReversible {
    use RuntimeException;
    use function Gallery\Services\site_url_current_value;
    use function Gallery\Services\site_url_save_reversible;

    $directory = __DIR__ . '/../cache/test-site-url-reversible-' . getmypid();
    if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
        throw new RuntimeException('Could not create isolated site URL fixture directory.');
    }
    $path = $directory . '/config.php';
    $GLOBALS['site_url_fixture_path'] = $path;
    $original = "<?php\nreturn [\n    'base_url' => '',\n    'galleries_root' => 'D:\\\\Fotky\\\\Žluťoučký',\n    'label' => 'Příliš žluťoučký kůň',\n];\n";
    if (file_put_contents($path, $original) !== strlen($original)) {
        throw new RuntimeException('Could not write isolated site URL fixture.');
    }

    try {
        assert_true(site_url_current_value() === '', 'Legacy empty base_url was not read directly.');
        $restore = site_url_save_reversible('https://example.test/gallery/');
        $changed = (string) file_get_contents($path);
        assert_true(str_contains($changed, "'base_url' => 'https://example.test/gallery'"), 'Normalized website URL was not saved.');
        assert_true(str_contains($changed, 'Žluťoučký') && str_contains($changed, 'Příliš žluťoučký kůň'), 'Unrelated UTF-8 or path configuration changed.');
        $restore();
        assert_true(file_get_contents($path) === $original, 'Compensation did not restore exact original source bytes.');

        $restore = site_url_save_reversible('https://example.test/second');
        $concurrent = (string) file_get_contents($path) . "// concurrent admin edit\n";
        file_put_contents($path, $concurrent);
        try {
            $restore();
            throw new RuntimeException('Compensation overwrote a concurrent config change.');
        } catch (RuntimeException $exception) {
            assert_true(str_contains($exception->getMessage(), 'changed after'), 'Concurrent edit produced an unexpected refusal.');
        }
        assert_true(file_get_contents($path) === $concurrent, 'Concurrent config source was modified by refused compensation.');
    } finally {
        if (is_file($path)) {
            unlink($path);
        }
        if (is_file($path . '.lock')) {
            unlink($path . '.lock');
        }
        if (is_dir($directory)) {
            rmdir($directory);
        }
    }

    echo "site_url_reversible_test: PASS\n";
}
