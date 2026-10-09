<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/custom_css_visual_preview_inspection_test.php
 * Module Type: Regression Test
 * Purpose: Prove visual-preview stylesheet inspection refuses unsafe and oversized inputs without changing them.
 * Responsibilities:
 *   - Exercise the actual Custom CSS inspection service inside an owned temporary module tree.
 *   - Make filesystem refusal branches deterministic without changing repository assets or permissions.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Services {
    /**
     * Report the fixture stylesheet as a symbolic link only in the selected decision scenario.
     * @param string $filename Path queried by the copied service.
     * @return bool Native symbolic-link result or the injected fixture result.
     */
    function is_link(string $filename): bool
    {
        if ($filename === ($GLOBALS['visual_css_inspection_path'] ?? null)
            && ($GLOBALS['visual_css_inspection_mode'] ?? '') === 'symlink') {
            return true;
        }
        return \is_link($filename);
    }

    /**
     * Report the fixture stylesheet as present for the non-regular-file decision scenario.
     * @param string $filename Path queried by the copied service.
     * @return bool Native existence result or the injected fixture result.
     */
    function file_exists(string $filename): bool
    {
        if ($filename === ($GLOBALS['visual_css_inspection_path'] ?? null)
            && ($GLOBALS['visual_css_inspection_mode'] ?? '') === 'non_regular') {
            return true;
        }
        return \file_exists($filename);
    }

    /**
     * Report the fixture stylesheet as non-regular only in the selected decision scenario.
     * @param string $filename Path queried by the copied service.
     * @return bool Native file result or the injected fixture result.
     */
    function is_file(string $filename): bool
    {
        if ($filename === ($GLOBALS['visual_css_inspection_path'] ?? null)
            && ($GLOBALS['visual_css_inspection_mode'] ?? '') === 'non_regular') {
            return false;
        }
        return \is_file($filename);
    }

    /**
     * Deny reading the fixture stylesheet only in the selected decision scenario.
     * @param string $filename Path queried by the copied service.
     * @param bool $use_include_path Whether PHP should search its include path.
     * @param resource|null $context Optional PHP stream context resource; PHP has no native resource parameter type.
     * @param int $offset Byte offset requested by the service.
     * @param int|null $length Maximum number of bytes requested, or null for an unbounded read.
     * @return string|false Native file contents or the injected unreadable result.
     */
    function file_get_contents(
        string $filename,
        bool $use_include_path = false,
        mixed $context = null,
        int $offset = 0,
        ?int $length = null
    ): string|false {
        if ($filename === ($GLOBALS['visual_css_inspection_path'] ?? null)
            && ($GLOBALS['visual_css_inspection_mode'] ?? '') === 'unreadable') {
            return false;
        }
        return \file_get_contents($filename, $use_include_path, $context, $offset, $length);
    }
}

namespace {
    /**
     * Require a disposable visual-preview inspection invariant.
     * @param bool $condition Required service or fixture state.
     * @param string $message Failure context shown by the regression runner.
     * @return void Throws when the invariant is not satisfied.
     */
    function visual_css_inspection_require(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    $fixtureRoot = sys_get_temp_dir() . '/gallery-css-inspection-' . bin2hex(random_bytes(10));
    $fixtureMarker = $fixtureRoot . '/.owned-by-visual-css-inspection-test';
    $fixtureDirectories = [
        $fixtureRoot,
        $fixtureRoot . '/app',
        $fixtureRoot . '/app/services',
        $fixtureRoot . '/app/services/custom_css',
        $fixtureRoot . '/public',
        $fixtureRoot . '/public/assets',
    ];
    $fixtureFiles = [
        $fixtureMarker,
        $fixtureRoot . '/app/services/custom_css.php',
        $fixtureRoot . '/app/services/custom_css/visual_background_save.php',
        $fixtureRoot . '/public/assets/custom.css',
    ];

    try {
        visual_css_inspection_require(mkdir($fixtureRoot), 'Could not create the owned inspection fixture root.');
        visual_css_inspection_require(
            file_put_contents($fixtureMarker, 'custom-css-visual-inspection-v1') === strlen('custom-css-visual-inspection-v1'),
            'Could not mark the owned inspection fixture.'
        );
        foreach (array_slice($fixtureDirectories, 1) as $directory) {
            visual_css_inspection_require(mkdir($directory), 'Could not create the owned inspection fixture directory.');
        }
        visual_css_inspection_require(
            copy(dirname(__DIR__) . '/app/services/custom_css.php', $fixtureRoot . '/app/services/custom_css.php'),
            'Could not copy the Custom CSS service into its isolated module tree.'
        );
        visual_css_inspection_require(
            copy(
                dirname(__DIR__) . '/app/services/custom_css/visual_background_save.php',
                $fixtureRoot . '/app/services/custom_css/visual_background_save.php'
            ),
            'Could not copy the Custom CSS service part into its isolated module tree.'
        );

        require $fixtureRoot . '/app/services/custom_css.php';
        $installedPath = \Gallery\Services\custom_css_path();
        $GLOBALS['visual_css_inspection_path'] = $installedPath;
        $GLOBALS['visual_css_inspection_mode'] = '';
        visual_css_inspection_require($installedPath === $fixtureRoot . '/public/assets/custom.css', 'The copied service escaped its fixture root.');

        $baseline = '/* unchanged inspection fixture */ body { color: #123456; }';
        visual_css_inspection_require(file_put_contents($installedPath, $baseline) === strlen($baseline), 'Could not write the installed stylesheet fixture.');
        $before = file_get_contents($installedPath);
        foreach ([
            'symlink' => 'inspection_unavailable',
            'non_regular' => 'inspection_unavailable',
            'unreadable' => 'inspection_unavailable',
        ] as $mode => $reason) {
            $GLOBALS['visual_css_inspection_mode'] = $mode;
            $decision = \Gallery\Services\custom_css_visual_preview_inspection_decision();
            visual_css_inspection_require(
                $decision === ['blocked' => true, 'reason' => $reason],
                'Filesystem refusal mode did not return its stable inspection decision: ' . $mode
            );
            visual_css_inspection_require(file_get_contents($installedPath) === $before, 'Inspection refusal changed the installed CSS bytes: ' . $mode);
        }

        $GLOBALS['visual_css_inspection_mode'] = '';
        $limit = \Gallery\Services\CUSTOM_CSS_VISUAL_PREVIEW_SCAN_MAX_BYTES;
        $boundaryCss = '/*' . str_repeat('x', $limit - 4) . '*/';
        visual_css_inspection_require(strlen($boundaryCss) === $limit, 'The exact scan-boundary fixture has the wrong byte length.');
        visual_css_inspection_require(file_put_contents($installedPath, $boundaryCss) === $limit, 'Could not write the exact-boundary stylesheet fixture.');
        $boundaryDecision = \Gallery\Services\custom_css_visual_preview_inspection_decision();
        visual_css_inspection_require(
            $boundaryDecision === ['blocked' => false, 'reason' => 'allowed'],
            'A stylesheet exactly at the 8 MiB inspection bound must remain inspectable.'
        );
        visual_css_inspection_require(file_get_contents($installedPath) === $boundaryCss, 'Exact-boundary inspection changed installed CSS bytes.');

        $oversizedCss = $boundaryCss . 'x';
        visual_css_inspection_require(file_put_contents($installedPath, $oversizedCss) === $limit + 1, 'Could not write the over-bound stylesheet fixture.');
        $oversizedDecision = \Gallery\Services\custom_css_visual_preview_inspection_decision();
        visual_css_inspection_require(
            $oversizedDecision === ['blocked' => true, 'reason' => 'inspection_limit'],
            'A stylesheet above 8 MiB must return the stable inspection-limit decision.'
        );
        visual_css_inspection_require(file_get_contents($installedPath) === $oversizedCss, 'Over-bound inspection changed installed CSS bytes.');
    } finally {
        if (is_file($fixtureMarker) && file_get_contents($fixtureMarker) === 'custom-css-visual-inspection-v1') {
            foreach ($fixtureFiles as $file) {
                if (is_file($file) || is_link($file)) {
                    unlink($file);
                }
            }
            foreach (array_reverse($fixtureDirectories) as $directory) {
                if (is_dir($directory) && !is_link($directory)) {
                    rmdir($directory);
                }
            }
        }
    }

    fwrite(STDOUT, "Custom CSS visual-preview inspection contract passed.\n");
}
