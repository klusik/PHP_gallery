<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/gallery_editor_creation_defaults_save_test.php
 * Module Type: Regression Test
 * Purpose: Verify editor saves selected personal gallery defaults after a successful gallery mutation.
 * Responsibilities:
 *   - Refuse unavailable defaults before changing the gallery.
 *   - Persist selected defaults only after the gallery save succeeds.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

declare(strict_types=1);

namespace Gallery\Core {
    /** Return the authenticated fixture administrator. @return array<string,int> User identity. */
    function current_user(): array
    {
        return ['id' => 11];
    }

    /** Store a fixture flash notice. @param string $key Flash key. @param string $message Notice text. @return void Record the notice. */
    function flash_message(string $key, string $message): void
    {
        $GLOBALS['defaults_save_order'][] = 'flash';
    }

    /** Build a fixture route. @param string $route Route name. @param array<string,mixed> $params Query values. @return string Fixture URL. */
    function url_for(string $route, array $params = []): string
    {
        return '/?' . http_build_query(['route' => $route] + $params);
    }

    /** End a direct-page fixture response. @param string $url Destination. @return never Throw the fixture sentinel. */
    function redirect_to(string $url): never
    {
        $GLOBALS['defaults_save_order'][] = 'redirect';
        throw new \RuntimeException('redirect:' . $url);
    }
}

namespace Gallery\Services {
    /** Report fixture preference-storage availability. @return bool Configured fixture state. */
    function gallery_creation_preferences_available(): bool
    {
        $GLOBALS['defaults_save_order'][] = 'available';
        return $GLOBALS['defaults_save_available'];
    }

    /** Validate one selected default before the gallery mutation. @param array<string,mixed> $input Submitted fields. @return void Record validation. */
    function gallery_creation_preferences_validate(array $input): void
    {
        $GLOBALS['defaults_save_order'][] = 'validate';
    }

    /** Save selected defaults only after the gallery mutation. @param int $userId Administrator ID. @param array<string,mixed> $input Submitted fields. @return void Record persistence. */
    function gallery_creation_preferences_remember(int $userId, array $input): void
    {
        if ($userId !== 11) {
            throw new \RuntimeException('Wrong preference owner.');
        }
        $GLOBALS['defaults_save_order'][] = 'remember';
    }

    /** Resolve fixture copy. @param string $key Translation key. @param string $fallback Fallback. @param array<string,mixed> $params Replacements. @return string Fixture text. */
    function t(string $key, string $fallback = '', array $params = []): string
    {
        return $fallback;
    }
}

namespace Gallery\Controllers {
    /** Save or refuse the fixture gallery mutation. @param array<string,mixed> $gallery Gallery row. @param array<string,mixed> $input Submitted fields. @param array<string,mixed> $files Upload descriptors. @param string $returnTab Active tab. @param bool $completeForm Complete form marker. @return array<string,mixed> Fixture save result. */
    function admin_save_gallery_from_input(array $gallery, array $input, array $files, string $returnTab, bool $completeForm = true): array
    {
        $GLOBALS['defaults_save_order'][] = 'save';
        if ($GLOBALS['defaults_save_failure']) {
            throw new \RuntimeException('Gallery save refused.');
        }
        return ['gallery' => $gallery, 'notice' => 'Saved.'];
    }

    /** Keep the fixture on the direct-page branch. @return bool False for this fixture. */
    function admin_wants_json(): bool
    {
        return false;
    }

    /** Build a deterministic editor fallback. @param int $galleryId Gallery ID. @param string $tab Selected tab. @return string Fixture editor URL. */
    function admin_edit_gallery_tab_url(int $galleryId, string $tab): string
    {
        return '/edit?id=' . $galleryId . '&tab=' . $tab;
    }
}

namespace {
    require_once dirname(__DIR__) . '/app/controllers/admin_galleries_edit_page/post_actions.php';

    /** Require one observed fixture postcondition. @param bool $condition Observed state. @param string $message Failure explanation. @return void Throw on failure. */
    function defaults_save_assert(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new \RuntimeException($message);
        }
    }

    /** Run one direct-page editor save through its redirect sentinel. @param array<string,mixed> $post Submitted fields. @param bool $available Preference storage state. @param bool $failGallerySave Whether gallery persistence fails. @return list<string> Observed operation order. */
    function defaults_save_run(array $post, bool $available, bool $failGallerySave): array
    {
        $GLOBALS['defaults_save_order'] = [];
        $GLOBALS['defaults_save_available'] = $available;
        $GLOBALS['defaults_save_failure'] = $failGallerySave;
        $_POST = $post;
        $_FILES = [];
        $_SESSION = [];
        try {
            \Gallery\Controllers\admin_edit_gallery_handle_save(['id' => 7], 'admin-edit-identity');
            throw new \RuntimeException('Editor did not complete the fixture response.');
        } catch (\RuntimeException $exception) {
            if (!str_starts_with($exception->getMessage(), 'redirect:/edit?id=7')) {
                throw $exception;
            }
        }
        return $GLOBALS['defaults_save_order'];
    }

    defaults_save_assert(defaults_save_run([
        'remember_simbrief_pilot_id' => '1',
        'simbrief_pilot_id' => 'pilot-11',
    ], true, false) === ['available', 'validate', 'save', 'remember', 'flash', 'redirect'],
        'Editor did not validate before save and remember defaults after save.');

    defaults_save_assert(defaults_save_run([
        'remember_simbrief_pilot_id' => '1',
        'simbrief_pilot_id' => 'pilot-11',
    ], false, false) === ['available', 'flash', 'redirect'],
        'Unavailable default storage must refuse before the gallery mutation.');

    defaults_save_assert(defaults_save_run([
        'remember_simbrief_pilot_id' => '1',
        'simbrief_pilot_id' => 'pilot-11',
    ], true, true) === ['available', 'validate', 'save', 'flash', 'redirect'],
        'Failed gallery save must not update personal defaults.');

    fwrite(STDOUT, "Gallery editor creation defaults save: PASS\n");
}
