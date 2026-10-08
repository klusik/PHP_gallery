<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/patch_notes_ai_chunks_test.php
 * Module Type: Regression Test
 * Purpose: Verify complete Git diff coverage across bounded Copilot evidence stages.
 * Responsibilities:
 *   - Cover UTF-8 boundaries, oversized source lines and every original diff byte
 *   - Refuse missing/tampered/oversized intermediate model evidence
 *   - Exercise direct synthesis and recursively reduced synthesis without Copilot
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/.github/scripts/patch_notes_ai_chunks.php';

$check = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$refused = static function (callable $action, string $contains) use ($check): void {
    try {
        $action();
    } catch (RuntimeException $exception) {
        $check(str_contains($exception->getMessage(), $contains),
            'Unexpected bounded-evidence refusal: ' . $exception->getMessage());
        return;
    }
    throw new RuntimeException('The requested incomplete evidence was accepted.');
};

$fixture = sys_get_temp_dir() . '/php-gallery-copilot-diff-' . bin2hex(random_bytes(8));
$check(mkdir($fixture, 0700), 'Could not create the isolated bounded-evidence fixture.');
$promptPath = $fixture . '/complete.prompt';
$createPrompt = static function (string $diff) use ($promptPath, $check): void {
    $prompt = "You are generating canonical release notes.\n"
        . "EVIDENCE - COMMIT METADATA:\n<<<COMMITS\nCOMMIT 123\nCOMMITS\n\n"
        . "EVIDENCE - TEXT DIFF:\n<<<DIFF\n"
        . $diff
        . "\nDIFF\n\nNow return only the final Markdown section for Version 0.123.\n";
    $check(file_put_contents($promptPath, $prompt) === strlen($prompt),
        'Complete evidence prompt fixture could not be written.');
};
$makeDigest = static function (string $dir, array $part, int $index, int $payload = 0) use ($check): void {
    $path = $dir . '/' . substr($part['prompt'], 0, -7) . '.md';
    $digest = '- Evidence slice ' . $index . ': verified feature behavior. ' . str_repeat('q', $payload) . "\n";
    $check(file_put_contents($path, $digest) === strlen($digest), 'Unable to write mocked Copilot digest.');
};

try {
    // A very long, multibyte source line cannot be dropped to satisfy a context budget.
    $longLine = '+' . str_repeat('žluťoučký', 28000) . "\n";
    $diff = "diff --git a/early.php b/early.php\n"
        . str_repeat("+Changed behavior and regression coverage.\n", 30000)
        . $longLine
        . str_repeat("+Factual later change.\n", 10000)
        . "diff --git a/zz-last.txt b/zz-last.txt\n+Final release evidence.\n";
    $createPrompt($diff);
    $dir = $fixture . '/first';
    patch_notes_chunks_prepare($promptPath, $dir);
    $state = patch_notes_chunks_load($dir);
    $manifest = $state['manifest'];
    $parts = patch_notes_chunks_split($diff);
    $check(count($parts) === count($manifest['levels'][0]['parts']) && count($parts) > 10,
        'Every diff slice must be registered in the provenance manifest.');
    $recreated = implode('', array_column($parts, 'text'));
    $check($recreated === $diff && hash('sha256', $recreated) === $manifest['diff_sha256'],
        'No byte of the large UTF-8 diff, including the final file, may be omitted or modified.');
    foreach ($manifest['levels'][0]['parts'] as $index => $part) {
        $body = file_get_contents($dir . '/' . $part['prompt']);
        $check(is_string($body) && str_contains($body, $parts[$index]['text'])
            && strlen($parts[$index]['text']) <= PATCH_NOTES_AI_DIFF_SLICE_BYTES,
            'A bounded prompt did not retain its complete original diff interval.');
        $makeDigest($dir, $part, $index + 1);
    }
    patch_notes_chunks_advance($dir);
    $final = file_get_contents($dir . '/final.prompt');
    $check(is_string($final) && strlen($final) <= PATCH_NOTES_AI_FINAL_PROMPT_BYTES
        && str_contains($final, 'Evidence slice 1:')
        && str_contains($final, 'Evidence slice ' . count($parts) . ':')
        && !str_contains($final, $longLine),
        'Final request must be bounded, cover first/last digests and exclude raw megabyte diff.');

    $old = file_get_contents($promptPath);
    file_put_contents($promptPath, $old . "altered");
    $refused(static fn(): array => patch_notes_chunks_load($dir), 'changed after splitting');
    file_put_contents($promptPath, $old);
    $firstPath = $dir . '/' . $manifest['levels'][0]['parts'][0]['prompt'];
    $originalPart = file_get_contents($firstPath);
    file_put_contents($firstPath, $originalPart . "tampered");
    $refused(static fn(): array => patch_notes_chunks_load($dir), 'missing or modified');
    file_put_contents($firstPath, $originalPart);
    $summaryPath = $dir . '/' . substr($manifest['levels'][0]['parts'][0]['prompt'], 0, -7) . '.md';
    file_put_contents($summaryPath, str_repeat('x', PATCH_NOTES_AI_DIGEST_BYTES + 1));
    $refused(static fn(): string => patch_notes_chunks_summary($summaryPath), 'exceeds its 6500-byte bound');
    $refused(static fn(): array => patch_notes_chunks_load($dir), 'already accepted Copilot digest');

    // More summaries than one bounded final prompt can hold require a complete
    // second Copilot reduction level, not a truncated subset of the first level.
    $largeDiff = str_repeat("+feature evidence repeated to exceed per-request bound\n", 95000);
    $createPrompt($largeDiff);
    $largeDir = $fixture . '/hierarchy';
    patch_notes_chunks_prepare($promptPath, $largeDir);
    $state = patch_notes_chunks_load($largeDir);
    $largeParts = $state['manifest']['levels'][0]['parts'];
    $check(count($largeParts) > 30, 'Large fixture must create enough bounded requests.');
    foreach ($largeParts as $index => $part) {
        $makeDigest($largeDir, $part, $index + 1, 4700);
    }
    patch_notes_chunks_advance($largeDir);
    $levelOne = patch_notes_chunks_load($largeDir)['manifest'];
    $check($levelOne['current_level'] === 1
        && count($levelOne['levels'][1]['parts']) < count($largeParts)
        && !is_file($largeDir . '/final.prompt'),
        'Oversized accumulated digests must be recursively reduced.');
    foreach ($levelOne['levels'][1]['parts'] as $index => $part) {
        $makeDigest($largeDir, $part, $index + 1);
    }
    patch_notes_chunks_advance($largeDir);
    $final = file_get_contents($largeDir . '/final.prompt');
    $check(is_string($final) && strlen($final) <= PATCH_NOTES_AI_FINAL_PROMPT_BYTES
        && str_contains($final, 'Evidence slice ' . count($levelOne['levels'][1]['parts']) . ':'),
        'Recursive reductions must finish with a complete bounded final request.');

    // No missing or malformed response is silently interpreted as "no changes".
    unlink($largeDir . '/' . substr($levelOne['levels'][1]['parts'][0]['prompt'], 0, -7) . '.md');
    $refused(static fn(): array => patch_notes_chunks_load($largeDir), 'already accepted Copilot digest');
} finally {
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

echo "PASS complete UTF-8 diff coverage, bounded digests and recursive Copilot reduction\n";
