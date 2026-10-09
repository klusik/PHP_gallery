<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/services/custom_css/visual_background_save.php
 * Module Type: Service Module Part
 * Purpose: Coordinate explicit CSS override saves with reviewed global Theme background image changes.
 * Responsibilities: Lock both owners, stage recovery data, activate files inside the settings transaction and reverse failures.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

declare(strict_types=1);

namespace Gallery\Services;

/**
 * Normalize the backward-compatible upload descriptor or one explicit Theme-image operation.
 *
 * @param array{operation?:string,target?:string,file?:array{name?:string,tmp_name?:string,error?:int,size?:int},expected_revision?:string} $change Legacy upload or discriminated Theme-image change submitted by the protected editor.
 * @return array{operation:'keep',target:'theme'}|array{operation:'replace',target:'theme',file:array{name?:string,tmp_name?:string,error?:int,size?:int},expected_revision:string}|array{operation:'remove',target:'theme',expected_revision:string} Strict normalized change with no ambiguous file/removal combination.
 * @throws \InvalidArgumentException When the operation, target, descriptor or opaque revision is invalid.
 */
function custom_css_background_change_normalize(array $change): array
{
    if (!array_key_exists('operation', $change)) {
        $keys = array_keys($change);
        sort($keys);
        if ($keys !== ['expected_revision', 'file'] || !is_array($change['file'] ?? null)) {
            throw new \InvalidArgumentException('Choose one explicit global Theme background operation.');
        }
        $change = [
            'operation' => 'replace',
            'target' => 'theme',
            'file' => $change['file'],
            'expected_revision' => $change['expected_revision'] ?? null,
        ];
    }

    $operation = $change['operation'] ?? null;
    $target = $change['target'] ?? null;
    $keys = array_keys($change);
    sort($keys);
    if ($target !== 'theme') {
        throw new \InvalidArgumentException('Visual background changes can target only the global Theme image.');
    }
    if ($operation === 'keep' && $keys === ['operation', 'target']) {
        return ['operation' => 'keep', 'target' => 'theme'];
    }
    if ($operation === 'replace' && $keys === ['expected_revision', 'file', 'operation', 'target']
        && is_array($change['file'] ?? null)) {
        $revision = $change['expected_revision'] ?? null;
        if (!is_string($revision) || preg_match('/^[a-f0-9]{64}$/D', $revision) !== 1) {
            throw new \InvalidArgumentException('Reload the Theme background before saving this image.');
        }
        return [
            'operation' => 'replace', 'target' => 'theme', 'file' => $change['file'],
            'expected_revision' => $revision,
        ];
    }
    if ($operation === 'remove' && $keys === ['expected_revision', 'operation', 'target']) {
        $revision = $change['expected_revision'] ?? null;
        if (!is_string($revision) || preg_match('/^[a-f0-9]{64}$/D', $revision) !== 1) {
            throw new \InvalidArgumentException('Reload the Theme background before removing this image.');
        }
        return ['operation' => 'remove', 'target' => 'theme', 'expected_revision' => $revision];
    }
    throw new \InvalidArgumentException('The global Theme background operation is incomplete or ambiguous.');
}

/**
 * Preserve the original upload-specific service entry point for existing callers.
 *
 * @param string $text Explicit validated CSS draft to install.
 * @param string $expectedRevision SHA-256 revision observed by the administrator in the CSS editor.
 * @param array{file:array{name?:string,tmp_name?:string,error?:int,size?:int},expected_revision:string} $attachment Legacy reviewed upload and opaque background revision.
 * @return array{text:string,revision:string,url:string} Installed CSS snapshot after the combined save.
 * @throws \InvalidArgumentException When attachment fields or image contents are invalid.
 * @throws CustomCssOverrideConflictException When either submitted revision is stale.
 * @throws CustomCssRecoveryException When CSS activation or settings rollback cannot be recovered.
 * @throws \RuntimeException When lock acquisition, staging, activation or persistence fails.
 */
function custom_css_overrides_save_with_background_attachment(string $text, string $expectedRevision, array $attachment): array
{
    return custom_css_overrides_save_with_background_change($text, $expectedRevision, [
        'operation' => 'replace',
        'target' => 'theme',
        'file' => $attachment['file'] ?? [],
        'expected_revision' => $attachment['expected_revision'] ?? null,
    ]);
}

/**
 * Save one CSS draft and a reviewed global Theme image replacement or removal together.
 *
 * @param string $text Explicit validated CSS draft to install.
 * @param string $expectedRevision SHA-256 revision observed by the administrator in the CSS editor.
 * @param array{operation:'replace',target:'theme',file:array{name?:string,tmp_name?:string,error?:int,size?:int},expected_revision:string}|array{operation:'remove',target:'theme',expected_revision:string} $backgroundChange Explicit global Theme-image replacement or removal and its opaque Theme revision.
 * @return array{text:string,revision:string,url:string} Installed CSS snapshot; global image settings change in the same model-owned transaction.
 * @throws \InvalidArgumentException When CSS, image operation, target or uploaded image is invalid.
 * @throws CustomCssOverrideConflictException When either submitted revision is stale.
 * @throws CustomCssRecoveryException When CSS activation cannot be restored or database rollback is uncertain.
 * @throws \RuntimeException When a configured image cannot be verified or lock, staging, activation or persistence fails.
 */
function custom_css_overrides_save_with_background_change(string $text, string $expectedRevision, array $backgroundChange): array
{
    custom_css_overrides_validate($text);
    $backgroundChange = custom_css_background_change_normalize($backgroundChange);
    $operation = $backgroundChange['operation'];
    if ($operation === 'keep') {
        throw new \InvalidArgumentException('Keep does not require a composite background mutation.');
    }
    $backgroundRevision = $backgroundChange['expected_revision'];
    $upload = $operation === 'replace' ? $backgroundChange['file'] : null;

    $target = custom_css_overrides_path();
    $directory = dirname($target);
    $lockPath = $directory . '/.custom-overrides.lock';
    if (!is_dir($directory) || !is_writable($directory) || is_link($lockPath)) {
        throw new \RuntimeException('The override asset directory is unavailable or unwritable.');
    }
    $cssLock = @fopen($lockPath, 'c');
    if ($cssLock === false) {
        throw new \RuntimeException('The override writer lock could not be opened.');
    }

    $stagedCss = '';
    $recoveryCopy = '';
    $keepRecoveryCopy = false;
    $stagedBackground = null;
    $backgroundActivated = false;
    $preserveBackgroundForRecovery = false;
    $cssActivated = false;
    $committed = false;
    $result = ['text' => '', 'revision' => '', 'url' => ''];
    try {
        if (!flock($cssLock, LOCK_EX | LOCK_NB)) {
            throw new \RuntimeException('The override writer lock could not be acquired.');
        }
        theme_background_with_writer_lock(static function () use (
            &$result,
            $text,
            $expectedRevision,
            $backgroundRevision,
            $operation,
            $upload,
            $target,
            &$stagedCss,
            &$recoveryCopy,
            &$keepRecoveryCopy,
            &$stagedBackground,
            &$backgroundActivated,
            &$preserveBackgroundForRecovery,
            &$cssActivated,
            &$committed
        ): void {
            $currentCss = custom_css_overrides_state();
            if (!hash_equals($currentCss['revision'], $expectedRevision)) {
                throw new CustomCssOverrideConflictException('The saved overrides changed in another editor.');
            }
            if (!hash_equals(theme_background_revision(), $backgroundRevision)) {
                throw new CustomCssOverrideConflictException('The Theme background changed in another editor.');
            }

            $previousBackgroundPaths = [];
            if ($operation === 'replace') {
                $stagedBackground = theme_background_stage_pending_upload(
                    $upload,
                    theme_background_optimized_max_side_value(
                        \Gallery\Models\app_settings_model_get('theme_background_optimized_max_side') ?: '1920'
                    )
                );
            } else {
                $previousBackgroundPaths = theme_background_verified_current_paths();
            }
            $cssChanged = $currentCss['text'] !== $text;
            if ($operation === 'remove' && $previousBackgroundPaths === [] && !$cssChanged) {
                $result = $currentCss;
                return;
            }
            $revision = hash('sha256', $text);
            $nextState = [
                'text' => $text,
                'revision' => $revision,
                'url' => $text === '' ? '' : \Gallery\Core\asset_url('assets/custom-overrides.css') . '?v=' . $revision,
            ];

            if ($cssChanged) {
                $stagedCss = custom_css_temporary_path();
                if (@file_put_contents($stagedCss, $text) !== strlen($text)
                    || @hash_file('sha256', $stagedCss) !== $revision
                    || !@chmod($stagedCss, 0644)) {
                    throw new \RuntimeException('The override asset could not be staged and verified.');
                }
                if (is_file($target)) {
                    $recoveryCopy = custom_css_temporary_path();
                    $oldMode = fileperms($target);
                    if ($oldMode === false || !custom_css_copy_verified($target, $recoveryCopy)
                        || !@chmod($recoveryCopy, $oldMode & 0777)) {
                        throw new \RuntimeException('The prior override could not be protected for rollback.');
                    }
                }
            }

            if ($operation === 'replace' && $stagedBackground !== null) {
                $maxSide = theme_background_optimized_max_side_value(
                    \Gallery\Models\app_settings_model_get('theme_background_optimized_max_side') ?: '1920'
                );
                $settings = [
                    'theme_background_path' => 'cache/theme-background/' . basename($stagedBackground['original_final']),
                    'theme_background_original_path' => 'cache/theme-background/' . basename($stagedBackground['original_final']),
                    'theme_background_optimized_path' => $stagedBackground['optimized_final'] !== null
                        ? 'cache/theme-background/' . basename($stagedBackground['optimized_final'])
                        : '',
                    'theme_background_optimized_max_side' => (string) $maxSide,
                ];
            } else {
                $settings = [
                    'theme_background_path' => '',
                    'theme_background_original_path' => '',
                    'theme_background_optimized_path' => '',
                ];
            }

            try {
                set_app_settings_atomically($settings, static function () use (
                    $stagedBackground,
                    $stagedCss,
                    $target,
                    $cssChanged,
                    &$backgroundActivated,
                    &$cssActivated
                ): void {
                    if ($stagedBackground !== null) {
                        theme_background_activate_staged_upload($stagedBackground);
                        $backgroundActivated = true;
                    }
                    if ($cssChanged) {
                        if (!@rename($stagedCss, $target)) {
                            throw new \RuntimeException('The override asset could not be activated.');
                        }
                        $cssActivated = true;
                    }
                });
                $committed = true;
            } catch (\Throwable $exception) {
                $backgroundStateUncertain = $exception instanceof \Gallery\Models\AppSettingsRollbackException;
                if ($backgroundStateUncertain && $backgroundActivated) {
                    // Do this before CSS restoration can throw so final cleanup cannot remove a possibly referenced asset.
                    $preserveBackgroundForRecovery = true;
                }
                if ($cssActivated) {
                    if ($recoveryCopy !== '') {
                        if (!@rename($recoveryCopy, $target)) {
                            $keepRecoveryCopy = is_file($recoveryCopy);
                            throw new CustomCssRecoveryException($keepRecoveryCopy, $exception, $backgroundStateUncertain);
                        }
                        $recoveryCopy = '';
                    } elseif (is_file($target) && !@unlink($target)) {
                        throw new CustomCssRecoveryException(false, $exception, $backgroundStateUncertain);
                    }
                }
                if ($backgroundActivated && $stagedBackground !== null) {
                    if ($backgroundStateUncertain) {
                        // Keep both immutable candidates because the database may still reference the new one.
                        $preserveBackgroundForRecovery = true;
                    } else {
                        theme_background_discard_staged_upload($stagedBackground, true);
                        $backgroundActivated = false;
                    }
                }
                if ($exception instanceof CustomCssOverrideConflictException
                    || $exception instanceof CustomCssRecoveryException
                    || $exception instanceof \InvalidArgumentException) {
                    throw $exception;
                }
                if ($backgroundStateUncertain) {
                    throw new CustomCssRecoveryException(false, $exception, true);
                }
                throw new \RuntimeException('The CSS and Theme background were not saved; the previous assets were restored.', 0, $exception);
            }

            if ($operation === 'replace' && $stagedBackground !== null) {
                theme_background_cleanup_replaced_assets([
                    $settings['theme_background_original_path'],
                    $settings['theme_background_optimized_path'],
                ]);
            } elseif ($operation === 'remove') {
                theme_background_cleanup_replaced_assets([]);
            }
            $result = $nextState;
        });
        return $result;
    } finally {
        if ($stagedCss !== '' && is_file($stagedCss)) {
            @unlink($stagedCss);
        }
        if ($recoveryCopy !== '' && is_file($recoveryCopy) && !$keepRecoveryCopy) {
            @unlink($recoveryCopy);
        }
        if ($stagedBackground !== null) {
            theme_background_discard_staged_upload(
                $stagedBackground,
                $backgroundActivated && !$committed && !$preserveBackgroundForRecovery
            );
        }
        if (is_resource($cssLock)) {
            flock($cssLock, LOCK_UN);
            fclose($cssLock);
        }
        clearstatcache(true, $target);
    }
}
