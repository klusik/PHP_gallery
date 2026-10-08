<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/patch_notes_ai_evidence_test.php
 * Module Type: Regression Test
 * Purpose: Exercise release evidence bounds, child deadlines and large real Git diffs.
 * Responsibilities:
 *   - Reproduce oversized stdout and stderr without pipe deadlock
 *   - Keep immutable metadata complete and preserve the entire text diff
 *   - Execute the actual prompt CLI against an owned disposable Git repository
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/scripts/audit_lib.php';
require_once dirname(__DIR__) . '/.github/scripts/patch_notes_ai.php';

use function PhpGallery\Audit\resolve_executable;
use function PhpGallery\Audit\run_process;

$check = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$validNotes = "## Version 0.124.1\n\nTest tooling release.\n\n### Highlights\n\n- Hardened test completion.\n\n### Technical Details\n\n- Added lifecycle checks.\n\n### User Impact\n\n- Maintainers received clearer diagnostics; public behavior did not change.\n";
$check(patch_notes_ai_validate_response('0.124.1', str_replace("\n", "\r\n", $validNotes)) === $validNotes,
    'A tooling-only release must retain all three sections and truthfully describe maintainer impact.');
foreach (patch_notes_ai_required_sections() as $heading) {
    foreach (['', 'Inline mention of ' . $heading, '#' . $heading, $heading . ' renamed'] as $replacement) {
        try {
            patch_notes_ai_validate_response('0.124.1', str_replace($heading, $replacement, $validNotes));
        } catch (RuntimeException $exception) {
            $check($exception->getMessage() === 'Generated release notes are missing required section: ' . $heading,
                'A malformed required heading must report its exact missing section.');
            continue;
        }
        throw new RuntimeException('Missing, inline, wrong-level or renamed main headings must never pass validation.');
    }
}
$refused = static function (array $command, int $limit, string $message, int $deadline = 30) use ($check): void {
    try {
        patch_notes_ai_git($command, $limit, $deadline);
    } catch (RuntimeException $exception) {
        $check($exception->getMessage() === $message, 'Unexpected evidence refusal: ' . $exception->getMessage());
        return;
    }
    throw new RuntimeException('Git evidence command did not refuse the fixture.');
};

// Literal arguments and stdout exactly at the limit retain their complete bytes.
$literal = 'spaces, $(literal), "quotes" and žluťoučký';
$check(patch_notes_ai_git([PHP_BINARY, '-r', 'echo $argv[1];', $literal], strlen($literal)) === $literal,
    'Shell-free evidence arguments and exact-limit stdout must stay unchanged.');

// Each stream is larger than an OS pipe buffer; the original reader blocked here.
$refused([PHP_BINARY, '-r', 'echo str_repeat("x", 2000000);'], 300000,
    'Release evidence exceeds the configured AI context bound.');
$refused([PHP_BINARY, '-r', 'fwrite(STDERR, str_repeat("s", 1000000)); echo str_repeat("x", 2000000);'], 3000000,
    'Git evidence diagnostics exceeded the configured bound.');
$refused([PHP_BINARY, '-r', 'fwrite(STDERR, "private repository diagnostic"); exit(7);'], 300000,
    'Git evidence command failed without exposing raw repository diagnostics.');

$started = hrtime(true);
$refused([PHP_BINARY, '-r', 'sleep(10);'], 300000,
    'Git evidence command exceeded its 1-second deadline.', 1);
$check((hrtime(true) - $started) / 1e9 < 5,
    'A timed-out child must be stopped and reaped instead of waiting for its original sleep.');

$complete = patch_notes_ai_git([
    PHP_BINARY, '-r', 'fwrite(STDERR, str_repeat("s", 8192)); echo str_repeat("žluťoučký řádek\n", 100000);',
], null);
$check($complete === str_repeat("žluťoučký řádek\n", 100000),
    'Multi-megabyte text must remain byte-for-byte complete, including UTF-8 and the final line.');
$check(patch_notes_ai_git([PHP_BINARY, '-r', 'echo "small diff\n";'], null) === "small diff\n",
    'Small diffs must remain complete.');

$git = resolve_executable('PHP_GALLERY_GIT', ['git']);
$check($git !== null, 'Git is required for real release evidence regression coverage.');
$repository = dirname(__DIR__);
$fixture = sys_get_temp_dir() . '/php-gallery-release-evidence-' . bin2hex(random_bytes(8));
$write = static function (string $path, string $contents) use ($check): void {
    $directory = dirname($path);
    if (!is_dir($directory)) {
        $check(mkdir($directory, 0775, true), 'Unable to create owned evidence fixture directory.');
    }
    $check(file_put_contents($path, $contents) !== false, 'Unable to write owned evidence fixture.');
};
$gitRun = static function (array $arguments) use ($git, $fixture, $check): void {
    $result = run_process(array_merge([$git], $arguments), $fixture, 10);
    $check(!$result['timed_out'] && $result['exit_code'] === 0, 'Owned Git fixture command failed.');
};

try {
    foreach (['.github/scripts/patch_notes_ai.php', 'scripts/cli_guard.php', 'scripts/release_lib.php', 'PATCH_NOTES_TEMPLATE.md'] as $path) {
        $contents = file_get_contents($repository . '/' . $path);
        $check(is_string($contents), 'Unable to read actual release evidence source.');
        $write($fixture . '/' . $path, $contents);
    }
    $write($fixture . '/PATCH_NOTES.md', "# Patch notes\n\n## Version 0.122.1\n\n### Highlights\n- Previous completed release.\n");
    $write($fixture . '/public/assets/large.js', "// Previous source.\n");
    $write($fixture . '/zz-later-feature.txt', "Previous feature.\n");
    $gitRun(['init', '--quiet', '--template=']);
    $gitRun(['config', 'user.name', 'Release evidence fixture']);
    $gitRun(['config', 'user.email', 'fixture@example.invalid']);
    $gitRun(['config', 'core.hooksPath', $fixture . '/empty-hooks']);
    $gitRun(['add', '.']);
    $gitRun(['commit', '--quiet', '--no-gpg-sign', '-m', 'Previous stable fixture']);
    $gitRun(['tag', 'v_0.122.1']);

    // A multi-megabyte early path must not hide later paths or commit evidence.
    $write($fixture . '/public/assets/large.js', str_repeat("const evidence = 'žluťoučký release change';\n", 50000));
    $write($fixture . '/zz-later-feature.txt', "Later feature supported by commit metadata.\n");
    $write($fixture . '/docs/generated.pdf', "\0EXCLUDED_PDF_CONTENT\n");
    $write($fixture . '/app/core-manifest.json', "EXCLUDED_MANIFEST_CONTENT\n");
    $gitRun(['add', '.']);
    $gitRun(['commit', '--quiet', '--no-gpg-sign', '-m', 'Add large source and later feature', '-m', 'Refs #123']);

    $promptPath = $fixture . '/prompt.txt';
    $result = run_process([
        PHP_BINARY, $fixture . '/.github/scripts/patch_notes_ai.php', 'prompt',
        '0.123', 'v_0.122.1', 'klusik/PHP_gallery', $promptPath,
    ], $fixture, 10);
    $check(!$result['timed_out'] && $result['exit_code'] === 0,
        'The actual prompt CLI must complete a multi-megabyte Git diff: ' . $result['stderr']);
    $prompt = file_get_contents($promptPath);
    $check(is_string($prompt), 'The prompt CLI must write its complete evidence.');
    $contract = substr($prompt, 0, strpos($prompt, 'SECURITY / EVIDENCE RULE:'));
    foreach (patch_notes_ai_required_sections() as $heading) {
        $check(str_contains($contract, '"' . $heading . '"'), 'Prompt and validator must share every required heading.');
    }
    $check(str_contains($contract, 'Main sections are mandatory even for test-only, documentation-only or tooling-only releases')
        && str_contains($contract, 'omit only its optional #### subsection')
        && str_contains($contract, 'state that explicitly; do not invent a product improvement'),
        'The output contract must distinguish required sections from optional subsections without fabricating user impact.');
    $check(str_contains($prompt, 'Refs #123') && str_contains($prompt, 'zz-later-feature.txt')
        && str_contains($prompt, '+Later feature supported by commit metadata.'),
        'The complete diff must include later file content as well as full commit metadata and changed paths.');
    $check(!str_contains($prompt, 'EXCLUDED_PDF_CONTENT') && !str_contains($prompt, 'EXCLUDED_MANIFEST_CONTENT'),
        'Generated artifacts must remain excluded from text diff evidence.');
    $diffStart = strpos($prompt, "<<<DIFF\n");
    $diffEnd = strpos($prompt, "\nDIFF\n", $diffStart === false ? 0 : $diffStart);
    $check($diffStart !== false && $diffEnd !== false && $diffEnd - $diffStart - strlen("<<<DIFF\n") > 2000000
        && preg_match('//u', $prompt) === 1,
        'The real CLI prompt must retain the entire multi-megabyte text diff and preserve UTF-8.');
    $check(str_contains($result['stdout'], 'Prepared complete AI release-note context:'),
        'The workflow log must report complete evidence size without exposing raw diff content.');

    // Metadata is never silently shortened to make an immutable comparison succeed.
    $messagePath = $fixture . '/commit-message.txt';
    $write($messagePath, "Large immutable commit metadata\n\n" . str_repeat('metadata ', 12000));
    $gitRun(['commit', '--quiet', '--allow-empty', '--no-gpg-sign', '-F', $messagePath]);
    unlink($promptPath);
    $result = run_process([
        PHP_BINARY, $fixture . '/.github/scripts/patch_notes_ai.php', 'prompt',
        '0.123', 'v_0.122.1', 'klusik/PHP_gallery', $promptPath,
    ], $fixture, 10);
    $check(!$result['timed_out'] && $result['exit_code'] !== 0 && !is_file($promptPath)
        && str_contains($result['stderr'], 'Release evidence exceeds the configured AI context bound.'),
        'Oversized immutable metadata must fail promptly without creating a partial prompt.');
} finally {
    if (is_dir($fixture)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($fixture, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $path) {
            if ($path->isDir()) {
                rmdir($path->getPathname());
            } else {
                unlink($path->getPathname());
            }
        }
        rmdir($fixture);
    }
}

echo "PASS release evidence bounds, deadlines and real large Git diff\n";
