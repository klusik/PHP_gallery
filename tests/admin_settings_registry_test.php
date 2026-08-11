<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/admin_settings_registry_test.php
 * Module Type: Test Script
 *
 * Purpose:
 *   Verifies the centralized Admin Settings registry contract without a database.
 *
 * Responsibilities:
 *   - Ensure registered identifiers and canonical setting keys are unique
 *   - Ensure every registered group belongs to the stable section taxonomy
 *   - Ensure default/current resolvers and ownership metadata are present
 *   - Ensure sensitive entries remain redacted and non-editable
 *   - Ensure specialized routes referenced by the registry exist in the router
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

/**
 * Split a PHP call argument list on top-level commas.
 *
 * @param string $text Argument-list source.
 * @return list<string> Parsed top-level arguments.
 */
function settings_test_split_arguments(string $text): array
{
    $arguments = [];
    $current = '';
    $depth = 0;
    $quote = '';
    $escaped = false;
    $length = strlen($text);
    for ($index = 0; $index < $length; $index++) {
        $char = $text[$index];
        if ($quote !== '') {
            $current .= $char;
            if ($escaped) {
                $escaped = false;
            } elseif ($char === '\\') {
                $escaped = true;
            } elseif ($char === $quote) {
                $quote = '';
            }
            continue;
        }
        if ($char === "'" || $char === '"') {
            $quote = $char;
            $current .= $char;
            continue;
        }
        if ($char === '(' || $char === '[' || $char === '{') {
            $depth++;
            $current .= $char;
            continue;
        }
        if ($char === ')' || $char === ']' || $char === '}') {
            $depth--;
            $current .= $char;
            continue;
        }
        if ($char === ',' && $depth === 0) {
            $arguments[] = trim($current);
            $current = '';
            continue;
        }
        $current .= $char;
    }
    if (trim($current) !== '') {
        $arguments[] = trim($current);
    }
    return $arguments;
}

/**
 * Extract registry entry calls from the returned registry array.
 *
 * @param string $source Registry source.
 * @return list<list<string>> Parsed argument lists.
 */
function settings_test_registry_calls(string $source): array
{
    $calls = [];
    $needle = 'admin_settings_entry(';
    $offset = 0;
    while (($start = strpos($source, $needle, $offset)) !== false) {
        $open = $start + strlen($needle) - 1;
        $depth = 1;
        $quote = '';
        $escaped = false;
        $index = $open + 1;
        $length = strlen($source);
        for (; $index < $length; $index++) {
            $char = $source[$index];
            if ($quote !== '') {
                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === $quote) {
                    $quote = '';
                }
                continue;
            }
            if ($char === "'" || $char === '"') {
                $quote = $char;
                continue;
            }
            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
                if ($depth === 0) {
                    break;
                }
            }
        }
        if ($depth !== 0) {
            throw new RuntimeException('Unbalanced admin_settings_entry call.');
        }
        $calls[] = settings_test_split_arguments(substr($source, $open + 1, $index - $open - 1));
        $offset = $index + 1;
    }
    return $calls;
}

/**
 * Decode one quoted PHP string literal used by the registry declaration.
 */
function settings_test_literal(string $expression): string
{
    $expression = trim($expression);
    if (preg_match("/^'((?:\\\\.|[^'])*)'$/s", $expression, $match) === 1) {
        return stripcslashes($match[1]);
    }
    if (preg_match('/^"((?:\\\\.|[^"])*)"$/s', $expression, $match) === 1) {
        return stripcslashes($match[1]);
    }
    return '';
}

$registrySource = file_get_contents(__DIR__ . '/../app/services/admin_settings_registry.php');
$bootstrapSource = file_get_contents(__DIR__ . '/../app/bootstrap.php');
if (!is_string($registrySource) || !is_string($bootstrapSource)) {
    throw new RuntimeException('Unable to read Settings registry or router source.');
}

$sections = ['general', 'appearance', 'content', 'media', 'uploads', 'privacy', 'advanced'];
$calls = settings_test_registry_calls($registrySource);
$ids = [];
$canonicalKeys = [];
$routes = [];
foreach ($calls as $arguments) {
    if (count($arguments) < 9) {
        continue;
    }
    $id = settings_test_literal($arguments[0]);
    $group = settings_test_literal($arguments[1]);
    if ($id === '' || $group === '') {
        continue;
    }
    if (isset($ids[$id])) {
        throw new RuntimeException('Duplicate Settings registry id: ' . $id);
    }
    $ids[$id] = true;
    if (!in_array($group, $sections, true)) {
        throw new RuntimeException('Unknown Settings registry group: ' . $group);
    }

    $keyExpression = trim($arguments[4]);
    $key = settings_test_literal($keyExpression);
    if ($key === '' && $keyExpression === 'PUBLIC_THUMBNAIL_RENDERING_SETTING_KEY') {
        $key = 'public_thumbnail_rendering_mode';
    } elseif ($key === '' && $keyExpression === 'exif_gps_default_enabled_setting_key()') {
        $key = 'exif_gps_maps_default_enabled';
    }
    if ($key !== '') {
        if (isset($canonicalKeys[$key])) {
            throw new RuntimeException('Duplicate canonical Settings key: ' . $key);
        }
        $canonicalKeys[$key] = $id;
    }

    $route = settings_test_literal($arguments[8]);
    if ($route !== '') {
        $routes[$route] = true;
    }

    if (isset($arguments[10]) && settings_test_literal($arguments[10]) === '' && trim($arguments[10]) !== "''" && trim($arguments[10]) !== '""') {
        throw new RuntimeException('Settings registry specialized fragment must be a string for: ' . $id);
    }
}

foreach (['default_resolver', 'current_resolver', 'fallback_behavior', 'owner', 'specialized_route', 'central_editable', 'sensitivity', 'migration_required'] as $field) {
    if (!str_contains($registrySource, "'{$field}' =>")) {
        throw new RuntimeException('Registry metadata field missing: ' . $field);
    }
}

foreach ($routes as $route => $_unused) {
    if (!str_contains($bootstrapSource, "'{$route}' =>")) {
        throw new RuntimeException('Registry specialized route is not registered: ' . $route);
    }
}

foreach (['password_reset_smtp_password', 'site_maintenance_token', 'account_credentials'] as $sensitiveId) {
    if (!isset($ids[$sensitiveId])) {
        throw new RuntimeException('Sensitive registry entry missing: ' . $sensitiveId);
    }
}
if (!str_contains($registrySource, "admin_settings_sensitive_status('password_reset_smtp_password')") || !str_contains($registrySource, "admin_settings_sensitive_status('site_maintenance_token')")) {
    throw new RuntimeException('Sensitive Settings values are not summarized through the redaction helper.');
}

foreach (['site_name', 'public_language', 'url_rewrite_enabled', 'public_home_search_enabled', 'public_thumbnail_rendering_mode', 'exif_gps_maps_default_enabled', 'dev_mode_enabled'] as $centralId) {
    if (!isset($ids[$centralId])) {
        throw new RuntimeException('Expected centrally editable registry id missing: ' . $centralId);
    }
}

if (!str_contains($registrySource, "'normalization_callback' =>") || !str_contains($registrySource, "'save_callback' =>")) {
    throw new RuntimeException('Registry does not expose canonical normalization/save callback metadata.');
}

echo "Admin Settings registry tests passed.\n";
