<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/custom_css.php
 * Module Type: Service
 *
 * Purpose:
 *   Provides reusable application logic for gallery data, media, settings, or maintenance workflows.
 *
 * Responsibilities:
 *   - Keep domain logic reusable outside controllers
 *   - Protect existing behavior with small focused functions
 *   - Return predictable values for callers
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
 *   - Prefer small, readable changes over broad rewrites.
 *
 * Last Updated:
 *   2026-05-04
 */

declare(strict_types=1);

namespace Gallery\Services;

use function Gallery\Core\asset_url;

/** A refused activation whose file rollback needs administrator attention. */
class CustomCssRecoveryException extends \RuntimeException
{
    /**
     * Distinguish an actual previous-file recovery copy from a failed first-install cleanup.
     * @param bool $hasRecoveryCopy Whether verified prior stylesheet bytes remain backed up.
     * @param \Throwable $previous Persistence error that caused the refused activation.
     * @return void Initializes the bounded recovery status and original failure.
     */
    public function __construct(public readonly bool $hasRecoveryCopy, \Throwable $previous)
    {
        parent::__construct('The custom stylesheet rollback could not be completed.', 0, $previous);
    }
}

/**
 * Resolve the active custom CSS file path.
 *
 * This module lives in app/services/, so project-root paths must use
 * dirname(__DIR__, 2). Keeping this calculation local prevents the path
 * regression that previously happened when theme-adjacent helpers were moved
 * out of app/services.php.
 *
 * @return string Text result for the caller.
 */
function custom_css_path(): string
{
    return dirname(__DIR__, 2) . '/public/assets/custom.css';
}

/**
 * Return the folder containing selectable custom CSS skins.
 *
 * Preset files stay outside public/assets/ on purpose. The admin page copies
 * a selected preset into the active public stylesheet instead of serving the
 * preset directory directly.
 *
 * @return string Text result for the caller.
 */
function custom_css_preset_dir(): string
{
    return dirname(__DIR__, 2) . '/custom_css';
}

/**
 * Return selectable custom CSS files from the preset folder.
 *
 * The returned array keeps the filename as the stable UI key and the absolute
 * file path as the copy source. This preserves the existing admin behavior
 * while moving the path handling into a dedicated service.
 *
 * @return array Structured result data for the caller.
 */
function custom_css_presets(): array
{
    // $dir stores an intermediate value used by the surrounding gallery workflow.
    $dir = custom_css_preset_dir();
    if (!is_dir($dir)) {
        return [];
    }

    // $files stores an intermediate value used by the surrounding gallery workflow.
    $files = glob($dir . '/*.css') ?: [];
    sort($files, SORT_NATURAL | SORT_FLAG_CASE);

    // $presets stores an intermediate value used by the surrounding gallery workflow.
    $presets = [];
    foreach ($files as $file) {
        $presets[basename($file)] = $file;
    }
    return $presets;
}

/**
 * Resolve one preset filename to a path inside the custom CSS preset folder.
 *
 * Only plain filenames ending in .css are accepted. This prevents directory
 * traversal while keeping the existing admin form contract unchanged.
 *
 * @param string $filename Filename value.
 * @return ?string Text result for the caller.
 */
function custom_css_preset_path(string $filename): ?string
{
    if ($filename === '' || basename($filename) !== $filename || !str_ends_with(strtolower($filename), '.css')) {
        return null;
    }

    // $presets stores an intermediate value used by the surrounding gallery workflow.
    $presets = custom_css_presets();
    return $presets[$filename] ?? null;
}

/**
 * Return the custom CSS URL only when a custom file exists.
 *
 * The actual file is still public/assets/custom.css. This helper only decides
 * whether the optional stylesheet should be advertised to the browser.
 *
 * @return ?string Text result for the caller.
 */
function custom_css_url(): ?string
{
    return is_file(custom_css_path()) ? asset_url('assets/custom.css') : null;
}

/**
 * Describe the installed stylesheet without inferring its contents from a preset marker.
 * @return array{active:bool,preset:string,bytes:int,modified:int,url:string} Bounded asset metadata for controllers.
 */
function custom_css_state(): array
{
    $path = custom_css_path();
    clearstatcache(true, $path);
    $active = is_file($path);
    return [
        'active' => $active,
        'preset' => (string) app_setting('custom_css_preset', ''),
        'bytes' => $active ? max(0, (int) @filesize($path)) : 0,
        'modified' => $active ? max(0, (int) @filemtime($path)) : 0,
        'url' => $active ? (string) custom_css_url() : '',
    ];
}

/**
 * Verify the stylesheet's persisted selection owner before changing an installed asset.
 * @param string $operation Stable replacement or reset operation identifier.
 * @return void Refuses missing or unknown setting storage before any file mutation.
 */
function custom_css_assert_storage_ready(string $operation): void
{
    mutation_schema_assert_available(
        mutation_schema_table_columns_status('custom_stylesheet', 'app_settings', ['setting_key', 'setting_value', 'updated_at']),
        $operation
    );
}

/**
 * Allocate a temporary asset next to the active stylesheet for same-filesystem activation.
 * @return string Owned staging path; throws when a writable staging file cannot be allocated.
 */
function custom_css_temporary_path(): string
{
    $directory = dirname(custom_css_path());
    $path = @tempnam($directory, '.custom-css-');
    if ($path === false || realpath(dirname($path)) !== realpath($directory)) {
        if (is_string($path) && is_file($path)) {
            @unlink($path);
        }
        throw new \RuntimeException('The custom stylesheet could not be staged.');
    }
    return $path;
}

/**
 * Copy and verify every byte before a staged file can replace user stylesheet data.
 * @param string $source Complete stylesheet or prior installed file.
 * @param string $destination Service-owned staging or recovery file.
 * @return bool Whether the copy and both digest observations succeeded.
 */
function custom_css_copy_verified(string $source, string $destination): bool
{
    if (!@copy($source, $destination)) {
        return false;
    }
    $sourceHash = @hash_file('sha256', $source);
    $copiedHash = @hash_file('sha256', $destination);
    return is_string($sourceHash) && is_string($copiedHash) && hash_equals($sourceHash, $copiedHash);
}

/**
 * Activate a complete staged stylesheet and retain the prior file on a failed commit.
 * @param string $staged Service-owned staging path beside the active stylesheet.
 * @param string $preset Selection marker to record only after successful activation.
 * @return void Replaces only the stylesheet and its marker; appearance preferences are preserved.
 */
function custom_css_install_staged(string $staged, string $preset): void
{
    $target = custom_css_path();
    $backup = '';
    $activated = false;
    $retainBackup = false;
    try {
        $permissions = 0644;
        if (is_file($target)) {
            $existingPermissions = @fileperms($target);
            if ($existingPermissions === false) {
                throw new \RuntimeException('The existing custom stylesheet permissions could not be verified.');
            }
            $permissions = $existingPermissions & 0777;
            $backup = custom_css_temporary_path();
            if (!custom_css_copy_verified($target, $backup) || !@chmod($backup, $permissions)) {
                throw new \RuntimeException('The existing custom stylesheet could not be backed up.');
            }
        }
        // Staging files start private; preserve the installed asset's readability on activation and rollback.
        if (!@chmod($staged, $permissions)) {
            throw new \RuntimeException('The custom stylesheet permissions could not be preserved.');
        }
        if (!@rename($staged, $target)) {
            throw new \RuntimeException('The custom stylesheet could not be installed.');
        }
        $activated = true;
        set_app_setting('custom_css_preset', $preset);
    } catch (\Throwable $exception) {
        if ($activated) {
            $restored = $backup !== '' ? @rename($backup, $target) : @unlink($target);
            if (!$restored) {
                $retainBackup = $backup !== '';
                throw new CustomCssRecoveryException($backup !== '', $exception);
            }
        }
        throw $exception;
    } finally {
        if (is_file($staged)) {
            @unlink($staged);
        }
        if (!$retainBackup && $backup !== '' && is_file($backup)) {
            @unlink($backup);
        }
        clearstatcache(true, $target);
    }
}

/**
 * Remove the active user stylesheet after the controller's Admin/CSRF checks.
 * @return void Clears the selected preset only after the file is absent.
 * @throws \RuntimeException When a present stylesheet cannot be removed.
 */
function custom_css_reset(): void
{
    custom_css_assert_storage_ready('custom_stylesheet.reset');
    $path = custom_css_path();
    $backup = '';
    if (is_file($path)) {
        $backup = custom_css_temporary_path();
        if (!@rename($path, $backup)) {
            @unlink($backup);
            throw new \RuntimeException('The custom stylesheet could not be removed.');
        }
    }
    try {
        set_app_setting('custom_css_preset', '');
    } catch (\Throwable $exception) {
        if ($backup !== '' && !@rename($backup, $path)) {
            throw new CustomCssRecoveryException(true, $exception);
        }
        throw $exception;
    }
    if ($backup !== '' && is_file($backup)) {
        @unlink($backup);
    }
    clearstatcache(true, $path);
}

/**
 * Apply one changed, catalogued stylesheet preset without resetting unchanged settings.
 * @param string $preset Plain preset filename supplied by the authenticated controller.
 * @return bool True only after a changed preset was copied and recorded.
 */
function custom_css_apply_preset(string $preset): bool
{
    $source = custom_css_preset_path($preset);
    if ($source === null || ($preset === (string) app_setting('custom_css_preset', '') && is_file(custom_css_path()))) {
        return false;
    }
    custom_css_assert_storage_ready('custom_stylesheet.replace');
    $staged = custom_css_temporary_path();
    if (!custom_css_copy_verified($source, $staged)) {
        @unlink($staged);
        throw new \RuntimeException('The selected stylesheet could not be staged.');
    }
    custom_css_install_staged($staged, $preset);
    return true;
}

/**
 * Install an explicitly supplied CSS upload; no request globals are read here.
 * @param array{name?:string,tmp_name?:string,error?:int} $file Controller-selected upload descriptor.
 * @return bool True after the uploaded stylesheet and its selection marker are stored.
 */
function custom_css_store_uploaded(array $file): bool
{
    $temporary = (string) ($file['tmp_name'] ?? '');
    if ((int) ($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK
        || $temporary === '' || !is_uploaded_file($temporary)
        || !str_ends_with(strtolower((string) ($file['name'] ?? '')), '.css')) {
        return false;
    }
    custom_css_assert_storage_ready('custom_stylesheet.replace');
    $staged = custom_css_temporary_path();
    if (!move_uploaded_file($temporary, $staged)) {
        @unlink($staged);
        throw new \RuntimeException('The uploaded stylesheet could not be staged.');
    }
    custom_css_install_staged($staged, 'uploaded');
    return true;
}

/**
 * Apply an explicit stylesheet choice with upload precedence and a non-destructive default.
 * @param string $preset Empty means keep the installed file; otherwise a catalogued preset filename.
 * @param array<string,mixed>|null $file Optional controller-selected upload descriptor.
 * @return bool Whether an explicitly requested replacement was installed.
 */
function custom_css_save_selection(string $preset, ?array $file = null): bool
{
    $uploadRequested = $file !== null && (
        (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE
        || (string) ($file['tmp_name'] ?? '') !== ''
        || (string) ($file['name'] ?? '') !== ''
    );
    if ($uploadRequested) {
        if ((int) ($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK || !custom_css_store_uploaded($file)) {
            throw new \RuntimeException('Choose a successfully uploaded .css file. The current stylesheet was kept.');
        }
        return true;
    }
    if ($preset !== '' && custom_css_preset_path($preset) === null) {
        throw new \RuntimeException('The selected stylesheet is unavailable. The current stylesheet was kept.');
    }
    return $preset !== '' && custom_css_apply_preset($preset);
}

/** A stale override writer that must reload the saved revision before retrying. */
class CustomCssOverrideConflictException extends \RuntimeException
{
}

/**
 * Bound manually edited CSS before reading, staging or retaining a failed draft.
 * Type: int. Units: UTF-8 bytes. Scope: one installation-owned override stylesheet.
 * Consumers: override validation and bounded editor reads.
 * Rationale: 256 KiB accommodates a substantial stylesheet while bounding session and request memory.
 */
const CUSTOM_CSS_OVERRIDE_MAX_BYTES = 262144;

/**
 * Resolve the independent installation-owned manual override asset.
 * @return string Fixed absolute path beside the installed preset/upload asset.
 */
function custom_css_overrides_path(): string
{
    return dirname(__DIR__, 2) . '/public/assets/custom-overrides.css';
}

/**
 * Reject malformed transport data while allowing ordinary CSS and browser-recoverable syntax errors.
 * @param string $text Complete administrator-submitted UTF-8 stylesheet.
 * @return void Refuses excessive bytes, invalid UTF-8 and binary control characters before filesystem writes.
 */
function custom_css_overrides_validate(string $text): void
{
    if (strlen($text) > CUSTOM_CSS_OVERRIDE_MAX_BYTES || preg_match('//u', $text) !== 1
        || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $text) === 1) {
        throw new \InvalidArgumentException('Enter valid UTF-8 CSS within the editor size limit.');
    }
}

/**
 * Read one complete override snapshot for the protected editor or public stylesheet URL.
 * @return array{text:string,revision:string,url:string} Text and SHA-256 precondition; empty text has no public stylesheet URL.
 */
function custom_css_overrides_state(): array
{
    $path = custom_css_overrides_path();
    clearstatcache(true, $path);
    $text = '';
    if (is_link($path) || (file_exists($path) && !is_file($path))) {
        throw new \RuntimeException('The override asset is not a regular file.');
    }
    if (is_file($path)) {
        $text = @file_get_contents($path, false, null, 0, CUSTOM_CSS_OVERRIDE_MAX_BYTES + 1);
        if (!is_string($text)) {
            throw new \RuntimeException('The override asset could not be read.');
        }
        custom_css_overrides_validate($text);
    }
    $revision = hash('sha256', $text);
    return ['text' => $text, 'revision' => $revision, 'url' => $text === '' ? '' : asset_url('assets/custom-overrides.css') . '?v=' . $revision];
}

/**
 * Atomically replace manual overrides under a serialized optimistic-concurrency precondition.
 * @param string $text Explicit validated editor submission; empty means a dedicated clear or explicit empty save.
 * @param string $expectedRevision SHA-256 snapshot the administrator actually edited.
 * @return array{text:string,revision:string,url:string} Installed snapshot; validation, conflict or write errors leave the previous file intact.
 */
function custom_css_overrides_save(string $text, string $expectedRevision): array
{
    custom_css_overrides_validate($text);
    if (preg_match('/^[a-f0-9]{64}$/D', $expectedRevision) !== 1) {
        throw new \InvalidArgumentException('Reload the saved override revision before saving.');
    }
    $revision = hash('sha256', $text);
    $nextState = ['text' => $text, 'revision' => $revision, 'url' => $text === '' ? '' : asset_url('assets/custom-overrides.css') . '?v=' . $revision];
    $target = custom_css_overrides_path();
    $directory = dirname($target);
    $lockPath = $directory . '/.custom-overrides.lock';
    clearstatcache();
    if (!is_dir($directory) || !is_writable($directory) || is_link($lockPath)) {
        throw new \RuntimeException('The override asset directory is unavailable or unwritable.');
    }
    $lock = @fopen($lockPath, 'c');
    if ($lock === false) {
        throw new \RuntimeException('The override writer lock could not be opened.');
    }
    $staged = '';
    try {
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            throw new \RuntimeException('The override writer lock could not be acquired.');
        }
        $current = custom_css_overrides_state();
        if (!hash_equals($current['revision'], $expectedRevision)) {
            throw new CustomCssOverrideConflictException('The saved overrides changed in another editor.');
        }
        if ($current['text'] === $text) {
            return $current;
        }
        $staged = custom_css_temporary_path();
        if (@file_put_contents($staged, $text) !== strlen($text)
            || @hash_file('sha256', $staged) !== hash('sha256', $text)
            || !@chmod($staged, 0644)) {
            throw new \RuntimeException('The override asset could not be staged and verified.');
        }
        // Activation is the final fallible operation: no DB marker or post-replacement write can require rollback.
        if (!@rename($staged, $target)) {
            throw new \RuntimeException('The override asset could not be replaced.');
        }
        return $nextState;
    } finally {
        if ($staged !== '' && is_file($staged)) {
            @unlink($staged);
        }
        flock($lock, LOCK_UN);
        fclose($lock);
        clearstatcache(true, $target);
    }
}
