<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/audit_lint_selection_test.php
 * Module Type: Regression Test
 * Purpose: Verify complete-file immutable-delta syntax selection and evaluator-triggered whole-tree fallback.
 * Responsibilities:
 *   - Cover multi-commit and squashed histories, additions, deletions and renames
 *   - Refuse unavailable baselines and preserve unchanged files when evaluator inputs change
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/scripts/audit_lib.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$git = \PhpGallery\Audit\resolve_executable('PHP_GALLERY_GIT', ['git']);
if ($git === null) {
    fwrite(STDERR, "BLOCKED immutable lint selection fixture requires Git.\n");
    exit(2);
}
$fixture = sys_get_temp_dir() . '/gallery-lint-selection-' . bin2hex(random_bytes(8));
$assert(mkdir($fixture, 0700), 'Unable to create the owned Git fixture.');
$run = static function (array $arguments) use ($git, $fixture, $assert): string {
    $result = \PhpGallery\Audit\run_process(array_merge([$git], $arguments), $fixture, 15);
    $assert($result['exit_code'] === 0 && !$result['timed_out'],
        'Git fixture command failed: ' . implode(' ', $arguments) . '\n' . $result['stderr']);
    return trim($result['stdout']);
};
$write = static function (string $path, string $content) use ($fixture, $assert): void {
    $parent = dirname($fixture . '/' . $path);
    if (!is_dir($parent)) {
        $assert(mkdir($parent, 0700, true), 'Unable to create a fixture source directory.');
    }
    $assert(file_put_contents($fixture . '/' . $path, $content) === strlen($content), 'Incomplete fixture write: ' . $path);
};
try {
    $run(['init', '--initial-branch=fixture']);
    $run(['config', 'user.name', 'Lint Fixture']);
    $run(['config', 'user.email', 'fixture@example.invalid']);
    foreach (['unchanged.php', 'deleted.php', 'old-name.php', 'unchanged.js'] as $path) {
        $write($path, str_ends_with($path, '.php') ? '<?php echo "complete-file";' : 'const unchanged = 1;');
    }
    $run(['add', '--all']);
    $run(['commit', '-m', 'Immutable baseline']);
    $baseline = $run(['rev-parse', 'HEAD']);
    for ($index = 1; $index <= 17; $index++) {
        $write('sources/Příliš žluťoučký ' . $index . '.php', '<?php echo ' . $index . ';');
    }
    for ($index = 1; $index <= 8; $index++) {
        $write('assets/script ' . $index . '.js', 'const value = ' . $index . ';');
    }
    for ($index = 1; $index <= 4; $index++) {
        $write('assets/module ' . $index . '.mjs', 'export const value = ' . $index . ';');
    }
    $assert(unlink($fixture . '/deleted.php'), 'Unable to remove the deleted source fixture.');
    $assert(rename($fixture . '/old-name.php', $fixture . '/sources/renamed.php'), 'Unable to rename the PHP fixture.');
    $run(['add', '--all']);
    $run(['commit', '-m', 'First source batch']);
    $write('sources/Příliš žluťoučký 1.php', '<?php echo "second commit complete bytes";');
    $run(['add', '--all']);
    $run(['commit', '-m', 'Second source batch']);

    $select = static fn(array $extensions, bool $changed = true, ?string $base = null): array =>
        \PhpGallery\Audit\select_lint_targets($fixture, $extensions, $changed, $base ?? $baseline, true);
    $php = $select(['php']);
    $js = $select(['js', 'mjs']);
    $assert(!$php['blocked'] && !$php['fallback'] && count($php['files']) === 18,
        'Two source commits must select 18 complete existing PHP files, including the rename destination.');
    $assert(count($js['files']) === 12 && !$js['fallback'], 'JS and MJS must select all 12 changed files.');
    $assert(!in_array($fixture . '/unchanged.php', $php['files'], true)
        && !in_array($fixture . '/deleted.php', $php['files'], true)
        && !in_array($fixture . '/old-name.php', $php['files'], true), 'Deleted and unchanged source paths must not enter delta lint.');
    $assert(file_get_contents($php['files'][0]) !== false, 'Lint selection must return complete readable source paths.');
    $run(['reset', '--soft', $baseline]);
    $run(['commit', '-m', 'Squashed candidate']);
    $assert($select(['php'])['files'] === $php['files'] && $select(['js', 'mjs'])['files'] === $js['files'],
        'Squashing the same source tree must preserve the immutable-baseline file selection.');
    foreach ([null, str_repeat('0', 40), 'HEAD^'] as $invalidBase) {
        $invalid = \PhpGallery\Audit\select_lint_targets($fixture, ['php'], true, $invalidBase, true);
        $assert($invalid['blocked'] && $invalid['files'] === [], 'Missing, unresolved and symbolic BASIC baselines must BLOCK.');
    }
    $full = $select(['php'], false);
    $assert(count($full['files']) === 19 && in_array($fixture . '/unchanged.php', $full['files'], true),
        'Whole-tree syntax must retain every current unchanged PHP source.');
    $write('scripts/audit_registry.php', '<?php return [];');
    $expanded = $select(['php']);
    $expandedJs = $select(['js', 'mjs']);
    $assert($expanded['fallback'] && !$expanded['blocked'] && count($expanded['files']) === 20
        && in_array($fixture . '/unchanged.php', $expanded['files'], true)
        && count($expandedJs['files']) === 13 && $expandedJs['fallback'],
        'Changed evaluator inputs must execute full PHP and JS/MJS lint, including unchanged sources.');
    $assert(unlink($fixture . '/scripts/audit_registry.php'), 'Unable to remove the checker fixture.');
    $write('package.json', '{"type":"module"}');
    $assert($select(['js', 'mjs'])['fallback'], 'Parser configuration changes must expand syntax selection.');
} finally {
    $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($entries as $entry) {
        if ($entry->isDir() && !$entry->isLink()) {
            rmdir($entry->getPathname());
        } else {
            chmod($entry->getPathname(), 0666);
            unlink($entry->getPathname());
        }
    }
    rmdir($fixture);
}
echo "PASS immutable BASIC complete-file selection, counts, multi-commit/squash history and full evaluator fallback\n";
