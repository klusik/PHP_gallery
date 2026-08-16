<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/openai_text_assist_model_test.php
 * Module Type: Test Script
 *
 * Purpose:
 *   Verifies OpenAI text-assistance helpers without requiring a live API request
 *   or a database connection.
 *
 * Responsibilities:
 *   - Cover model id normalization and API key shape validation
 *   - Cover prompt construction for gallery description and parent summary tasks
 *   - Cover Responses API text extraction from direct and nested payloads
 *   - Cover local secret encryption round trips when OpenSSL is available
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
 *   2026-05-29
 */

declare(strict_types=1);

use function Gallery\Services\openai_text_assist_api_key_format_valid;
use function Gallery\Services\openai_text_assist_decrypt_secret;
use function Gallery\Services\openai_text_assist_default_settings;
use function Gallery\Services\openai_text_assist_encrypt_secret;
use function Gallery\Services\openai_text_assist_extract_output_text;
use function Gallery\Services\openai_text_assist_key_hint;
use function Gallery\Services\openai_text_assist_language_instruction;
use function Gallery\Services\openai_text_assist_model_catalog;
use function Gallery\Services\openai_text_assist_normalize_language;
use function Gallery\Services\openai_text_assist_normalize_model;
use function Gallery\Services\openai_text_assist_normalize_task;
use function Gallery\Services\openai_text_assist_payload_input;
use function Gallery\Services\openai_text_assist_prompt;
use function Gallery\Services\openai_text_assist_task_uses_images;

if (!function_exists('cms_config')) {
        /**
     * Return deterministic config material for isolated encryption tests.
     *
     * @return array<string,mixed> Structured result data for the caller.
     */
    function cms_config(): array
    {
        return [
            'visitor_vote_secret' => 'test-vote-secret',
            'setup_key' => 'test-setup-key',
            'admin_session_name' => 'php-gallery-test-session',
            'base_url' => 'https://example.test',
            'database' => [
                'password' => 'test-database-password',
            ],
        ];
    }
}

require_once __DIR__ . '/../app/services/openai_text_assist.php';

/**
 * Throw when an OpenAI text-assistance model expectation fails.
 *
 * @param mixed $expected Expected value.
 * @param mixed $actual Actual value.
 * @param string $label Human-readable assertion label.
 */
function assert_openai_text_assist_same(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($label . ' expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

/**
 * Throw when a string does not contain required text.
 *
 * @param string $needle Required text.
 * @param string $haystack String being checked.
 * @param string $label Human-readable assertion label.
 */
function assert_openai_text_assist_contains(string $needle, string $haystack, string $label): void
{
    if (!str_contains($haystack, $needle)) {
        throw new RuntimeException($label . ' missing ' . var_export($needle, true) . ' in ' . var_export($haystack, true));
    }
}

assert_openai_text_assist_same('gpt-5.4-mini', openai_text_assist_normalize_model(''), 'empty model fallback');
assert_openai_text_assist_same('gpt-5.4-mini', openai_text_assist_normalize_model(' gpt-4.1-mini '), 'legacy model fallback');
assert_openai_text_assist_same('gpt-5.5', openai_text_assist_normalize_model(' gpt-5.5 '), 'curated model trim');
assert_openai_text_assist_same('gpt-5.4-mini', openai_text_assist_normalize_model('gpt-4.1-mini<script>'), 'model unsafe character removal');
assert_openai_text_assist_same(true, array_key_exists('gpt-5.4-mini', openai_text_assist_model_catalog()), 'model catalog default');
assert_openai_text_assist_same(true, openai_text_assist_api_key_format_valid('sk-proj-' . str_repeat('A', 48)), 'valid API key format');
assert_openai_text_assist_same(false, openai_text_assist_api_key_format_valid('short-key'), 'short API key rejection');
assert_openai_text_assist_same(false, openai_text_assist_api_key_format_valid('sk with spaces'), 'space API key rejection');
assert_openai_text_assist_same('****1234', openai_text_assist_key_hint('sk-test-1234'), 'key hint suffix');

$defaultSettings = openai_text_assist_default_settings();
assert_openai_text_assist_same(0, $defaultSettings['allow_image_input'], 'image input default off');
assert_openai_text_assist_same('image_visual_description', openai_text_assist_normalize_task(' image_visual_description '), 'visual image task normalization');
assert_openai_text_assist_same(true, openai_text_assist_task_uses_images('gallery_visual_description'), 'gallery visual task uses images');
assert_openai_text_assist_same(false, openai_text_assist_task_uses_images('cleanup_text'), 'cleanup task does not use images');
assert_openai_text_assist_same('cs', openai_text_assist_normalize_language('cs-CZ'), 'Czech language normalization');
assert_openai_text_assist_same('en', openai_text_assist_normalize_language('en_US'), 'English language normalization');
assert_openai_text_assist_same('de', openai_text_assist_normalize_language('de'), 'German language normalization');
assert_openai_text_assist_same('sv', openai_text_assist_normalize_language('sv-SE'), 'Swedish language normalization');
assert_openai_text_assist_same('auto', openai_text_assist_normalize_language('fr'), 'unsupported language fallback');
assert_openai_text_assist_contains('Write the final output in Czech', openai_text_assist_language_instruction('cs'), 'Czech language instruction');

$context = [
    'gallery' => [
        'id' => 42,
        'title' => 'Letní výlet',
        'description' => 'Krátký ruční popis.',
        'folder_path' => 'travel/summer-trip',
        'tags' => 'cestování, léto, rodina',
        'direct_image_count' => 4,
        'branch_image_count' => 12,
        'direct_subgallery_count' => 2,
    ],
    'subgalleries' => [
        [
            'title' => 'Hory',
            'description' => 'Výšlap a výhledy.',
            'image_count' => 6,
        ],
        [
            'title' => 'Město',
            'description' => 'Večerní procházka.',
            'image_count' => 6,
        ],
    ],
    'images' => [
        [
            'filename' => 'IMG_0001.jpg',
            'title' => 'Západ slunce',
            'description' => 'Teplé světlo nad krajinou.',
        ],
    ],
];

$descriptionPrompt = openai_text_assist_prompt('gallery_description', $context, 'Existing text', 'cs');
assert_openai_text_assist_contains('leaf-gallery description', $descriptionPrompt['instructions'], 'leaf-gallery instruction');
assert_openai_text_assist_contains('Letní výlet', $descriptionPrompt['input'], 'gallery title in prompt input');
assert_openai_text_assist_contains('Write the final output in Czech', $descriptionPrompt['instructions'], 'selected language instruction in prompt');
assert_openai_text_assist_contains('"output_language": "cs"', $descriptionPrompt['input'], 'selected language in prompt input');

$summaryPrompt = openai_text_assist_prompt('gallery_summary', $context, 'Existing text');
assert_openai_text_assist_contains('parent-gallery summary', $summaryPrompt['instructions'], 'parent-gallery instruction');
assert_openai_text_assist_contains('Hory', $summaryPrompt['input'], 'subgallery title in prompt input');

$cleanupPrompt = openai_text_assist_prompt('cleanup_text', $context, 'bad txt');
assert_openai_text_assist_contains('Translate the existing text faithfully', openai_text_assist_prompt('translate_text', $context, 'Ahoj', 'de')['instructions'], 'Translation draft instruction');
assert_openai_text_assist_same(900, $cleanupPrompt['max_output_tokens'], 'cleanup token bound');

$visualPrompt = openai_text_assist_prompt('image_visual_description', $context + [
    'visual_references' => [
        ['label' => 'Sample thumbnail', 'data_url' => 'data:image/jpeg;base64,AAAA'],
    ],
], '');
assert_openai_text_assist_contains('provided thumbnail', $visualPrompt['instructions'], 'visual photo instruction');
assert_openai_text_assist_same(false, str_contains($visualPrompt['input'], 'data:image/jpeg;base64'), 'visual prompt omits raw data URLs');

$payloadInput = openai_text_assist_payload_input($visualPrompt, [
    ['label' => 'Sample thumbnail', 'data_url' => 'data:image/jpeg;base64,AAAA', 'detail' => 'low'],
]);
assert_openai_text_assist_same(true, is_array($payloadInput), 'visual payload input uses structured array');
assert_openai_text_assist_same('input_image', $payloadInput[0]['content'][2]['type'], 'visual payload includes image content');

assert_openai_text_assist_same('Direct output', openai_text_assist_extract_output_text(['output_text' => 'Direct output']), 'direct Responses API output');
assert_openai_text_assist_same('Part one' . "\n\n" . 'Part two', openai_text_assist_extract_output_text([
    'output' => [
        [
            'content' => [
                ['text' => 'Part one'],
                ['text' => 'Part two'],
            ],
        ],
    ],
]), 'nested Responses API output');

if (function_exists('openssl_encrypt') && function_exists('openssl_decrypt')) {
    $secret = 'sk-proj-' . str_repeat('B', 48);
    $cipher = openai_text_assist_encrypt_secret($secret);
    assert_openai_text_assist_same(true, str_starts_with($cipher, 'v1:'), 'encrypted secret version prefix');
    assert_openai_text_assist_same($secret, openai_text_assist_decrypt_secret($cipher), 'encrypted secret round trip');
}

echo "OpenAI text-assistance model tests passed.\n";
