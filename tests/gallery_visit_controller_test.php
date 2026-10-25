<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/gallery_visit_controller_test.php
 * Module Type: Controller Regression
 * Purpose: Verify canonical installation history keys and Admin exclusion.
 * Responsibilities: Exercise the real payload controller and mount resolver
 *   with a bounded synthetic projection, without installation data or sessions.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Core {
    /** @var array{id:int}|null Explicit synthetic authenticated identity. */
    $visitControllerUser = null;
    /** @var string Captured production footer script for the current request. */
    $visitControllerScript = '';

    /**
     * Supply the fixture's actual identity independently of preview flags.
     * @return array{id:int}|null Synthetic Admin or anonymous identity.
     */
    function current_user(): ?array
    {
        return $GLOBALS['visitControllerUser'];
    }

    /**
     * Capture the production controller's serialized browser payload.
     * @param string $script Prepared executable footer script.
     * @return void Retains the exact script for payload assertions.
     */
    function append_cms_footer_script(string $script): void
    {
        $GLOBALS['visitControllerScript'] .= $script;
    }

    /**
     * Resolve a fixture asset without configuration or a database.
     * @param string $path Relative production browser-module path.
     * @return string Root-relative asset URL used only in captured markup.
     */
    function asset_url(string $path): string
    {
        return '/public/' . $path;
    }
}

namespace Gallery\Services {
    /**
     * Supply an empty successful projection without discovering domain data.
     * @param string $opened Unused current detail identity.
     * @param list<string> $observed Unused listed gallery identities.
     * @return array{nodes:array{},ready:bool} Known empty public evidence.
     */
    function gallery_visit_projection(
        string $opened = '',
        array $observed = [],
    ): array {
        return ['nodes' => [], 'ready' => true];
    }

    /**
     * Preserve the translation key without loading a catalog.
     * @param string $key Requested visitor translation key.
     * @return string Stable key sufficient for payload inspection.
     */
    function t(string $key): string
    {
        return $key;
    }
}

namespace {
    require_once dirname(__DIR__) . '/app/helpers_request.php';
    require_once dirname(__DIR__) . '/app/controllers/public_gallery_cards.php';

    // These are structural deployment variants, not operational size policies.
    foreach (['', '/Galerie', '/other-installation'] as $mount) {
        $expectedMount = DIRECTORY_SEPARATOR === '\\' ? strtolower($mount) : $mount;
        foreach (['/index.php', '/public/index.php'] as $frontController) {
            $_SERVER['SCRIPT_NAME'] = $mount . $frontController;
            $visitControllerScript = '';
            \Gallery\Controllers\public_gallery_visit_progress();
            if (!preg_match(
                '/setupGalleryVisitProgress\((\{.*\})\)\)/s',
                $visitControllerScript,
                $matches,
            )) {
                throw new RuntimeException('Production payload was not emitted.');
            }
            $payload = json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR);
            $expected = 'php-gallery.visit-progress.v1:' . ($expectedMount ?: '/');
            if ($payload['storageKey'] !== $expected) {
                throw new RuntimeException('History key differs across front controllers.');
            }
        }
    }

    $visitControllerUser = ['id' => 1];
    foreach (['', 'anonymous', 'visual'] as $preview) {
        $_GET['preview'] = $preview;
        $visitControllerScript = '';
        \Gallery\Controllers\public_gallery_visit_progress();
        if ($visitControllerScript !== '') {
            throw new RuntimeException('Admin identity received visitor history UI.');
        }
    }
    echo "PASS canonical visitor history mounts and Admin identity exclusion\n";
}
