<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/site_url.php
 * Module Type: Service
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

namespace Gallery\Services;

use InvalidArgumentException;
use RuntimeException;
use function Gallery\Core\cms_config_path;

/**
 * Validate an explicit public installation URL without HTTP or persistence.
 * @param string|array<array-key,mixed>|null $value Submitted URL; arrays and missing values are rejected.
 * @return string Validated absolute URL without a trailing slash.
 */
function site_url_normalize(mixed $value): string
{
    if (!is_string($value)) {
        throw new InvalidArgumentException('Enter an absolute HTTP or HTTPS website URL.');
    }
    $value = rtrim(trim($value), '/');
    $parts = parse_url($value);
    if ($value === '' || strlen($value) > 2048 || preg_match('/[\s\\\\\x00-\x1f\x7f]/', $value)
        || filter_var($value, FILTER_VALIDATE_URL) === false || !is_array($parts)
        || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
        || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
        || isset($parts['query']) || isset($parts['fragment'])) {
        throw new InvalidArgumentException('Enter an absolute HTTP or HTTPS website URL without credentials, query parameters, or a fragment.');
    }
    return $value;
}

/**
 * Read the current literal base_url directly from config.php without request caching.
 *
 * This bounded read supports optimistic conflict checks for the Setup Wizard.
 * It returns only the URL scalar and never exposes the remaining configuration.
 *
 * @return string Current configured URL, including the supported legacy empty value.
 */
function site_url_current_value(): string
{
    $path = cms_config_path();
    if (!is_file($path)) {
        return '';
    }
    $config = require $path;
    if (!is_array($config) || !is_string($config['base_url'] ?? null)) {
        throw new RuntimeException('Could not read the current website URL from config.php.');
    }
    return rtrim(trim($config['base_url']), '/');
}

/**
 * Replace only the top-level literal base_url, preserving comments and other settings.
 * @param string $source Existing PHP configuration source.
 * @param string $url Explicit public installation address.
 * @return string Updated source with all unrelated bytes preserved.
 */
function site_url_config_source(string $source, string $url): string
{
    $url = site_url_normalize($url);
    $tokens = token_get_all($source, TOKEN_PARSE);
    $offset = 0;
    $depth = 0;
    $returned = false;
    $matches = [];
    foreach ($tokens as $index => $token) {
        $text = is_array($token) ? $token[1] : $token;
        if (is_array($token) && $token[0] === T_RETURN && $depth === 0) {
            $returned = true;
        }
        if ($returned && $depth === 1 && is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING
            && in_array($text, ["'base_url'", '"base_url"'], true)) {
            $next = $index + 1;
            $valueOffset = $offset + strlen($text);
            while (isset($tokens[$next]) && is_array($tokens[$next])
                && in_array($tokens[$next][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $valueOffset += strlen($tokens[$next++][1]);
            }
            if (isset($tokens[$next]) && is_array($tokens[$next]) && $tokens[$next][0] === T_DOUBLE_ARROW) {
                $valueOffset += strlen($tokens[$next++][1]);
                while (isset($tokens[$next]) && is_array($tokens[$next])
                    && in_array($tokens[$next][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    $valueOffset += strlen($tokens[$next++][1]);
                }
                $literal = $tokens[$next] ?? null;
                if (!is_array($literal) || $literal[0] !== T_CONSTANT_ENCAPSED_STRING) {
                    throw new RuntimeException('The base_url in config.php must be a literal string. Edit this custom configuration manually.');
                }
                $end = $next + 1;
                while (isset($tokens[$end]) && is_array($tokens[$end])
                    && in_array($tokens[$end][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    $end++;
                }
                if (!in_array($tokens[$end] ?? null, [',', ']', ')'], true)) {
                    throw new RuntimeException('Computed base_url configuration cannot be changed safely.');
                }
                $matches[] = [$valueOffset, strlen($literal[1])];
            }
        }
        if (in_array($text, ['[', '(', '{'], true)) {
            $depth++;
        } elseif (in_array($text, [']', ')', '}'], true)) {
            $depth--;
        }
        $offset += strlen($text);
    }
    if (count($matches) !== 1) {
        throw new RuntimeException('Could not identify a single base_url in config.php. Edit this custom configuration manually.');
    }
    return substr_replace($source, var_export($url, true), $matches[0][0], $matches[0][1]);
}

/**
 * Save only the website address in config.php; no database setting is accessed.
 * @param string $url Explicit public installation address.
 * @return void
 */
function site_url_save(string $url): void
{
    site_url_save_reversible($url);
}

/**
 * Save the website address and return an in-memory compensation callback.
 *
 * The callback restores the exact prior config.php source, including a valid
 * installation whose previous base_url was an empty literal. It refuses to
 * overwrite any later concurrent config change. Source bytes stay inside this
 * service and are never returned to a controller, session, response, or log.
 *
 * @param string $url Explicit public installation address.
 * @return callable():void Compensation callback.
 */
function site_url_save_reversible(string $url): callable
{
    $url = site_url_normalize($url);
    $change = site_url_mutate_config_source(
        /**
         * Replace the configured website URL in the locked source.
         *
         * @param string $source Current config.php source.
         * @return string Updated config.php source.
         */
        static fn (string $source): string => site_url_config_source($source, $url)
    );
    /**
     * Restore the exact captured source unless a later writer changed it.
     *
     * @return void
     */
    $restore = static function () use ($change): void {
        site_url_mutate_config_source(
            /**
             * Validate the post-write source before returning its predecessor.
             *
             * @param string $source Current locked config.php source.
             * @return string Exact source captured before the URL update.
             */
            static function (string $source) use ($change): string {
                if (!hash_equals(hash('sha256', $change['after']), hash('sha256', $source))) {
                    throw new RuntimeException('config.php changed after the website URL update; automatic restoration was refused.');
                }
                return $change['before'];
            }
        );
    };
    return $restore;
}

/**
 * Apply one locked, atomic config.php source mutation.
 *
 * @param callable(string):string $mutator Builds replacement source from the locked current source.
 * @return array{before:string,after:string} In-memory source pair for bounded compensation.
 */
function site_url_mutate_config_source(callable $mutator): array
{
    $path = cms_config_path();
    if (!is_file($path) || !is_writable($path) || !is_writable(dirname($path))) {
        throw new RuntimeException('config.php or its folder is not writable. Change its permissions or edit base_url manually.');
    }
    $lock = @fopen($path . '.lock', 'c');
    if ($lock === false) {
        throw new RuntimeException('Could not lock config.php for writing.');
    }
    $temporary = false;
    try {
        if (!flock($lock, LOCK_EX)) {
            throw new RuntimeException('Could not lock config.php for writing.');
        }
        $source = file_get_contents($path);
        if (!is_string($source)) {
            throw new RuntimeException('Could not read config.php.');
        }
        $updated = $mutator($source);
        if (!is_string($updated)) {
            throw new RuntimeException('Could not safely prepare config.php.');
        }
        if ($updated !== $source) {
            $temporary = tempnam(dirname($path), '.config-');
            if ($temporary === false || !chmod($temporary, fileperms($path) & 0777)
                || file_put_contents($temporary, $updated) !== strlen($updated)
                || file_get_contents($path) !== $source || !rename($temporary, $path)) {
                throw new RuntimeException('Could not safely save config.php. The original configuration was preserved.');
            }
            $temporary = false;
            if (function_exists('opcache_invalidate')) {
                opcache_invalidate($path, true);
            }
        }
        return ['before' => $source, 'after' => $updated];
    } finally {
        if (is_string($temporary) && is_file($temporary)) {
            unlink($temporary);
        }
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
