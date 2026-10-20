<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: scripts/admin_import_contracts.php
 * Module Type: Repository Contract Support
 * Purpose: Check actual Admin module URL identity and cache invalidation relationships.
 * Responsibilities: Recognize literal imports, compare shared consumers and reject stale payload/importer chains.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Tools\AdminImports;

require_once __DIR__ . '/source_contracts/javascript.php';
require_once __DIR__ . '/source_contracts/changes.php';

/**
 * Identify active shared Admin consumers and their existing import invalidation owners.
 *
 * These are consumer relationships, not a second revision registry. Revision values
 * remain exclusively in the actual production imports. The entrypoint is invalidated
 * by the existing asset_dependency_revision() owner in the layout view.
 *
 * @return array<string,list<string>> Target source paths mapped to required importing source paths.
 */
function admin_import_groups(): array
{
    $prefix = 'public/assets/gallery-modules/';
    $groups = [
        'admin-mutation-completion.js' => [
            'admin-side-panel.js',
            'admin-route-navdata.js',
            'admin-cache-cleanup.js',
            'admin-cache-lifecycle.js',
            'admin-cooperative-galleries.js',
            'admin-gallery-features.js',
            'admin-gallery-quick-access.js',
            'admin-navdata-update.js',
            'admin-pdf-documents.js',
            'admin-settings-workspace.js',
        ],
        'admin-browser-upload.js' => [
            'admin-side-panel.js',
            'admin-icloud-source.js',
            'admin-pdf-documents.js',
        ],
        'admin-smart-galleries.js' => ['admin-side-panel.js'],
        'admin-side-panel.js' => ['admin-operations.js'],
        'admin-pdf-documents.js' => ['admin-browser-upload.js'],
        'admin-cache-lifecycle.js' => ['admin-cache-cleanup.js'],
        'admin-cache-cleanup.js' => ['admin-dashboard-workspace.js'],
        'admin-gallery-features.js' => ['admin-dashboard-workspace.js'],
        'admin-gallery-quick-access.js' => ['admin-dashboard-workspace.js'],
        'admin-navdata-update.js' => ['admin-operations.js'],
        'admin-icloud-source.js' => ['admin-side-panel.js'],
    ];
    $result = [];
    foreach ($groups as $target => $consumers) {
        $result[$prefix . $target] = array_map(
            static fn(string $consumer): string => $prefix . $consumer,
            $consumers,
        );
    }
    foreach ([
        'admin-smart-galleries.js',
        'admin-operations.js',
        'admin-pdf-documents.js',
        'admin-route-navdata.js',
        'admin-settings-workspace.js',
        'admin-cooperative-galleries.js',
        'admin-dashboard-workspace.js',
    ] as $target) {
        $result[$prefix . $target][] = 'public/assets/gallery.js';
    }
    return $result;
}

/**
 * Read literal static imports/re-exports and literal dynamic imports without matching comments or string contents.
 *
 * Supported forms are import bindings from a quoted URL, bare quoted imports,
 * export bindings/star from a quoted URL, and import(quoted URL). Escaped URL
 * literals, import attributes, computed imports and malformed/ambiguous forms
 * fail explicitly in this bounded owner instead of silently escaping coverage.
 *
 * @param string $source Complete JavaScript source text.
 * @return list<string> Actual module URL specifiers in source order.
 */
function admin_import_specifiers(string $source): array
{
    $tokens = array_values(array_filter(
        \PhpGallery\SourceContracts\javascript_tokens($source),
        static fn(array $token): bool => !in_array(
            $token['id'],
            [T_COMMENT, T_DOC_COMMENT],
            true,
        ),
    ));
    $imports = [];
    foreach ($tokens as $index => $token) {
        if (
            $token['id'] !== T_STRING
            || !in_array($token['text'], ['import', 'export'], true)
            || in_array($tokens[$index - 1]['text'] ?? '', ['.', '?.'], true)
        ) {
            continue;
        }
        $next = $tokens[$index + 1] ?? null;
        if (($next['text'] ?? '') === '.') {
            continue; // import.meta is module metadata, not an import edge.
        }
        $literal = null;
        $literalIndex = null;
        if ($token['text'] === 'import' && ($next['text'] ?? '') === '(') {
            $literal = $tokens[$index + 2] ?? null;
            $literalIndex = $index + 2;
            if (
                ($literal['id'] ?? null) !== T_CONSTANT_ENCAPSED_STRING
                || ($tokens[$index + 3]['text'] ?? '') !== ')'
            ) {
                throw new \RuntimeException(
                    'unsupported dynamic import syntax',
                );
            }
        } elseif (
            $token['text'] === 'import'
            && ($next['id'] ?? null) === T_CONSTANT_ENCAPSED_STRING
        ) {
            $literal = $next;
            $literalIndex = $index + 1;
        } elseif (
            $token['text'] === 'import'
            || in_array($next['text'] ?? '', ['{', '*'], true)
        ) {
            for ($cursor = $index + 1; isset($tokens[$cursor]); $cursor++) {
                if ($tokens[$cursor]['text'] === ';') {
                    break;
                }
                if ($tokens[$cursor]['text'] === 'from') {
                    $literal = $tokens[$cursor + 1] ?? null;
                    $literalIndex = $cursor + 1;
                    break;
                }
            }
            if ($literal === null) {
                // A local export list has no module URL and is not a re-export.
                if ($token['text'] === 'export') {
                    continue;
                }
                throw new \RuntimeException('missing literal import target');
            }
        } else {
            continue; // Exported declarations do not import a dependency.
        }
        $text = $literal['text'] ?? '';
        if (
            $literalIndex !== null
            && in_array(
                $tokens[$literalIndex + 1]['text'] ?? '',
                ['with', 'assert'],
                true,
            )
        ) {
            throw new \RuntimeException('unsupported import attributes');
        }
        if (
            ($literal['id'] ?? null) !== T_CONSTANT_ENCAPSED_STRING
            || strlen($text) < 3
            || !in_array($text[0], ["'", '"'], true)
            || substr($text, -1) !== $text[0]
            || str_contains($text, '\\')
        ) {
            throw new \RuntimeException(
                'unsupported or nonliteral import target',
            );
        }
        $imports[] = substr($text, 1, -1);
    }
    return $imports;
}

/**
 * Resolve one covered relative module URL while retaining its exact query and fragment identity.
 *
 * @param string $importer Repository-relative importing source path.
 * @param string $specifier Literal relative URL from that source.
 * @return array{path:string,identity:string,revision:string} Normalized target path, full module identity and nonempty explicit revision.
 */
function admin_import_resolve(string $importer, string $specifier): array
{
    if (
        !str_starts_with($specifier, './')
        && !str_starts_with($specifier, '../')
    ) {
        throw new \RuntimeException(
            'covered import must use a relative module URL',
        );
    }
    $url = parse_url($specifier);
    if (
        !is_array($url)
        || !isset($url['path'])
        || isset($url['scheme'], $url['host'])
        || preg_match('/^[A-Za-z0-9_.\/-]+$/D', $url['path']) !== 1
    ) {
        throw new \RuntimeException('unsupported relative module URL');
    }
    $parts = explode('/', dirname($importer));
    foreach (explode('/', $url['path']) as $part) {
        if ($part === '..') {
            if (array_pop($parts) === null) {
                throw new \RuntimeException(
                    'module URL escapes the source root',
                );
            }
        } elseif ($part !== '.') {
            $parts[] = $part;
        }
    }
    $query = (string) ($url['query'] ?? '');
    $revisionFields = array_values(array_filter(
        explode('&', $query),
        static fn(string $field): bool => str_starts_with($field, 'v='),
    ));
    if (
        count($revisionFields) !== 1
        || trim(rawurldecode(substr($revisionFields[0], 2))) === ''
    ) {
        throw new \RuntimeException(
            'missing, empty or duplicate cache revision',
        );
    }
    $path = implode('/', $parts);
    return [
        'path' => $path,
        'identity' =>
            $path
                . '?'
                . $query
                . (isset($url['fragment']) ? '#' . $url['fragment'] : ''),
        'revision' => substr($revisionFields[0], 2),
    ];
}

/**
 * Evaluate shared consumer identity and every covered payload/importer invalidation against inert source records.
 *
 * @param array<string,string> $sources Complete current module bytes keyed by repository-relative path.
 * @param array<string,list<string>> $groups Required target-to-consumer relationships.
 * @param array<string,string>|null $base Complete immutable-base records for existing paths; null means unavailable comparison evidence.
 * @return list<string> Actionable path/category failures; an empty list means all relationships and invalidation checks passed.
 */
function admin_import_contract_failures(
    array $sources,
    array $groups,
    ?array $base,
): array {
    if ($base === null) {
        return [
            'BLOCKED Admin import invalidation requires an available immutable comparison base.',
        ];
    }
    $failures = [];
    $parsed = [];
    $oldParsed = [];
    foreach ($groups as $target => $consumers) {
        $sharedIdentity = null;
        if (!isset($sources[$target])) {
            $failures[] = $target . ': missing required module target';
            continue;
        }
        foreach ($consumers as $consumer) {
            try {
                if (!isset($sources[$consumer])) {
                    throw new \RuntimeException(
                        'missing required importing source',
                    );
                }
                $parsed[$consumer] ??= admin_import_specifiers(
                    $sources[$consumer],
                );
                $matching = array_values(array_filter($parsed[$consumer], static function (string $specifier) use (
                    $target,
                ): bool {
                    return (
                        basename((string) parse_url(
                            $specifier,
                            PHP_URL_PATH,
                        )) === basename($target)
                    );
                }));
                if (count($matching) !== 1) {
                    throw new \RuntimeException(
                        'missing or duplicate required import',
                    );
                }
                $current = admin_import_resolve($consumer, $matching[0]);
                if ($current['path'] !== $target) {
                    throw new \RuntimeException('wrong resolved module target');
                }
                if (
                    $sharedIdentity !== null
                    && $sharedIdentity !== $current['identity']
                ) {
                    throw new \RuntimeException(
                        'shared consumers resolve different module URL identities',
                    );
                }
                $sharedIdentity = $current['identity'];
                if (
                    isset($base[$target], $base[$consumer])
                    && hash('sha256', $base[$target]) !== hash(
                        'sha256',
                        $sources[$target],
                    )
                ) {
                    $oldParsed[$consumer] ??= admin_import_specifiers(
                        $base[$consumer],
                    );
                    foreach ($oldParsed[$consumer] as $oldSpecifier) {
                        if (
                            basename((string) parse_url(
                                $oldSpecifier,
                                PHP_URL_PATH,
                            )) !== basename($target)
                        ) {
                            continue;
                        }
                        // Legacy missing-revision edges are repaired by adding v;
                        // only unchanged actual URL identity can retain stale bytes.
                        $oldIdentity = dirname($consumer) . '/' . $oldSpecifier;
                        try {
                            $oldIdentity = admin_import_resolve(
                                $consumer,
                                $oldSpecifier,
                            )['identity'];
                        } catch (\RuntimeException) {
                            // A newly valid revision is an explicit invalidation of this old edge.
                        }
                        if ($oldIdentity === $current['identity']) {
                            throw new \RuntimeException(
                                'changed payload has unchanged cache identity; advance the owning import and its upstream chain',
                            );
                        }
                    }
                }
            } catch (\RuntimeException $exception) {
                $failures[] =
                    $consumer
                    . ' -> '
                    . $target
                    . ': '
                    . $exception->getMessage();
            }
        }
    }
    return $failures;
}

/**
 * Read the selected real source group and immutable Git base without executing module code.
 *
 * @param string $root Absolute repository root.
 * @return list<string> Concrete relationship failures or an explicit BLOCKED comparison diagnostic.
 */
function admin_import_repository_failures(string $root): array
{
    $groups = admin_import_groups();
    $paths = array_keys($groups);
    foreach ($groups as $consumers) {
        $paths = array_merge($paths, $consumers);
    }
    $requested = trim((string) getenv('PHP_GALLERY_SOURCE_BASE'));
    if (preg_match('/^[a-f0-9]{40}$/D', $requested) !== 1) {
        return admin_import_contract_failures([], $groups, null);
    }
    $resolved = \PhpGallery\SourceContracts\source_git_read(
        ['rev-parse', '--verify', $requested . '^{commit}'],
        $root,
    );
    if ($resolved['status'] !== 0 || trim($resolved['stdout']) !== $requested) {
        return admin_import_contract_failures([], $groups, null);
    }
    $ancestor = \PhpGallery\SourceContracts\source_git_read([
        'merge-base',
        '--is-ancestor',
        $requested,
        'HEAD',
    ], $root);
    if ($ancestor['status'] !== 0) {
        return [
            'BLOCKED Admin import comparison base is not an ancestor of the selected candidate.',
        ];
    }
    $sources = [];
    $paths = array_values(array_unique($paths));
    $base = admin_import_base_sources($root, $requested, $paths);
    if ($base === null) {
        return ['BLOCKED Admin import base source records are unavailable.'];
    }
    foreach ($paths as $path) {
        $bytes = @file_get_contents($root . '/' . $path);
        if (is_string($bytes)) {
            $sources[$path] = $bytes;
        }
    }
    return admin_import_contract_failures($sources, $groups, $base);
}

/**
 * Read the complete comparison records through one local Git object process.
 *
 * @param string $root Absolute repository root.
 * @param string $base Verified immutable commit SHA.
 * @param list<string> $paths Explicit reviewed repository-relative JavaScript source paths.
 * @return array<string,string>|null Complete exact existing blob bytes keyed by path, omitting authenticated missing paths for newly introduced modules; null means unavailable, truncated or oversized evidence.
 */
function admin_import_base_sources(
    string $root,
    string $base,
    array $paths,
): ?array {
    $nullDevice = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
    $process = @proc_open(
        ['git', '--no-optional-locks', '-C', $root, 'cat-file', '--batch'],
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['file', $nullDevice, 'w'],
        ],
        $pipes,
        $root,
    );
    if (!is_resource($process)) {
        return null;
    }
    $records = [];
    $complete = true;
    foreach ($paths as $path) {
        if (fwrite($pipes[0], $base . ':' . $path . "\n") === false) {
            $complete = false;
            break;
        }
        fflush($pipes[0]);
        $header = fgets($pipes[1]);
        if ($header === $base . ':' . $path . " missing\n") {
            continue;
        }
        if (
            !is_string($header)
            || preg_match('/^[a-f0-9]{40} blob ([0-9]+)\n$/D', $header, $match)
                !== 1
            || (int) $match[1] > 1048576
        ) {
            $complete = false;
            break;
        }
        $remaining = (int) $match[1];
        $bytes = '';
        while ($remaining > 0) {
            $chunk = fread($pipes[1], $remaining);
            if (!is_string($chunk) || $chunk === '') {
                $complete = false;
                break 2;
            }
            $bytes .= $chunk;
            $remaining -= strlen($chunk);
        }
        if (fread($pipes[1], 1) !== "\n") {
            $complete = false;
            break;
        }
        $records[$path] = $bytes;
    }
    fclose($pipes[0]);
    fclose($pipes[1]);
    $status = proc_close($process);
    return $complete && $status === 0 ? $records : null;
}
