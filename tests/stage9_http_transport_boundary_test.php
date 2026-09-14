<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/stage9_http_transport_boundary_test.php
 * Module Type: Regression Test
 *
 * Purpose:
 *   Protects the Stage 9 HTTP transport boundary after policy/services return
 *   decisions or transport intents instead of touching the PHP response API.
 *
 * Responsibilities:
 *   - Keep header/status/cookie emission out of every service module
 *   - Keep feature, SEO, translation, updater, and Admin Test Run policy free of request globals
 *   - Verify Core/bootstrap/controller transport adapters remain available
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
 */

declare(strict_types=1);

$root = dirname(__DIR__);

/** Return the next significant token index after one token. */
function stage9_transport_next_token_index(array $tokens, int $index): ?int
{
    for ($cursor = $index + 1, $count = count($tokens); $cursor < $count; $cursor++) {
        $token = $tokens[$cursor];
        if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        return $cursor;
    }
    return null;
}

/** Return whether one T_STRING token is a direct function call. */
function stage9_transport_is_function_call(array $tokens, int $index): bool
{
    $next = stage9_transport_next_token_index($tokens, $index);
    if ($next === null || $tokens[$next] !== '(') {
        return false;
    }
    for ($cursor = $index - 1; $cursor >= 0; $cursor--) {
        $token = $tokens[$cursor];
        if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        return !(is_array($token) && in_array($token[0], [T_FUNCTION, T_NEW], true));
    }
    return true;
}

$violations = [];
$responseFunctions = ['header', 'http_response_code', 'setcookie', 'setrawcookie'];
$serviceIterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root . '/app/services', FilesystemIterator::SKIP_DOTS)
);
foreach ($serviceIterator as $fileInfo) {
    if (!$fileInfo instanceof SplFileInfo || !$fileInfo->isFile() || strtolower($fileInfo->getExtension()) !== 'php') {
        continue;
    }
    $path = $fileInfo->getPathname();
    $relativePath = str_replace('\\', '/', substr($path, strlen($root) + 1));
    $source = file_get_contents($path);
    if ($source === false) {
        $violations[] = 'Unable to read service: ' . $relativePath;
        continue;
    }
    $tokens = token_get_all($source);
    foreach ($tokens as $index => $token) {
        if (!is_array($token) || $token[0] !== T_STRING) {
            continue;
        }
        $name = strtolower($token[1]);
        if (in_array($name, $responseFunctions, true) && stage9_transport_is_function_call($tokens, $index)) {
            $violations[] = $relativePath . ':' . $token[2] . ' direct HTTP response call ' . $token[1] . '()';
        }
    }
}

$transportCleanServices = [
    'app/services/feature_flags/routes.php',
    'app/services/seo_request_guard.php',
    'app/services/translations.php',
    'app/services/updates_install.php',
    'app/services/admin_test_runs/context.php',
    'app/services/admin_test_runs/lifecycle.php',
];
$requestVariables = ['$_GET', '$_POST', '$_SERVER', '$_COOKIE', '$_REQUEST', '$_FILES'];
foreach ($transportCleanServices as $relativePath) {
    $path = $root . '/' . $relativePath;
    if (!is_file($path)) {
        $violations[] = 'Stage 9 service is unavailable: ' . $relativePath;
        continue;
    }
    $source = file_get_contents($path);
    if ($source === false) {
        $violations[] = 'Unable to read Stage 9 service: ' . $relativePath;
        continue;
    }
    foreach (token_get_all($source) as $token) {
        if (is_array($token) && $token[0] === T_VARIABLE && in_array($token[1], $requestVariables, true)) {
            $violations[] = $relativePath . ':' . $token[2] . ' direct request variable ' . $token[1];
        }
    }
}

$coreRequestSource = (string) file_get_contents($root . '/app/helpers_request.php');
$requestBootstrapSource = (string) file_get_contents($root . '/app/bootstrap/request.php');
$dispatchSource = (string) file_get_contents($root . '/app/bootstrap/dispatch.php');
$maintenanceSource = (string) file_get_contents($root . '/app/bootstrap/maintenance.php');
if (!str_contains($coreRequestSource, 'function apply_cookie_intents(')
    || !str_contains($coreRequestSource, 'function apply_response_header_intents(')) {
    $violations[] = 'Core cookie/header intent adapters are unavailable';
}
if (!str_contains($requestBootstrapSource, 'cms_apply_seo_request_guard_decision(')
    || !str_contains($requestBootstrapSource, 'translation_bootstrap_request(')
    || !str_contains($requestBootstrapSource, 'apply_cookie_intents(')) {
    $violations[] = 'Request bootstrap does not own SEO/language transport application';
}
if (!str_contains($dispatchSource, 'feature_flag_disabled_route_decision(')
    || !str_contains($dispatchSource, 'cms_apply_feature_disabled_route_response(')) {
    $violations[] = 'Dispatch does not own feature-disabled HTTP response application';
}
if (!str_contains($maintenanceSource, 'application_autoupdate_maybe_run(3600, request_method())')) {
    $violations[] = 'Maintenance bootstrap does not pass request method explicitly to updater policy';
}

if ($violations !== []) {
    throw new RuntimeException("Stage 9 HTTP transport boundary violations:\n  - " . implode("\n  - ", $violations));
}

fwrite(STDOUT, "Stage 9 HTTP transport boundary checks passed.\n");
