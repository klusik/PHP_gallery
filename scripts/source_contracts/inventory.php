<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: scripts/source_contracts/inventory.php
 * Module Type: Source Contract Discovery
 * Purpose:
 *   Discover source without opening installation data or generated trees.
 * Responsibilities:
 *   - Share exclusions and native header parsing between source contracts.
 * Author:
 *   Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace PhpGallery\SourceContracts;

/**
 * Register third-party vector provenance using the repository's existing licenses.
 * New first-party SVGs outside these exact paths still require native headers.
 * @return array<string,array{origin:string,license:string}> Asset path to upstream and local license owner.
 */
function source_provenance_exclusions(): array
{
    $assets = [
        'public/assets/link-icons/brands.svg' => [
            'origin' => 'Bootstrap Icons (twbs/icons); public/assets/link-icons/README.md',
            'license' => 'public/assets/link-icons/LICENSE.bootstrap-icons.md',
        ],
    ];
    foreach (['cz', 'de', 'gb', 'se'] as $country) {
        $assets['public/assets/flags/' . $country . '.svg'] = [
            'origin' => 'lipis/flag-icons v7.2.3; pinned origin documented in README.md',
            'license' => 'public/assets/flags/LICENSE.flag-icons.md',
        ];
    }
    return $assets;
}

/**
 * Discover source, retaining exclusion and format accounting without following links.
 * Excluded trees are pruned before recursion. Other formats are counted by path only.
 * @param string $root Repository or disposable fixture root.
 * @return array{files:array<string,string>,other_formats:array<string,int>,excluded:array<string,string>,provenance:array<string,array{origin:string,license:string}>}
 */
function inventory(string $root): array
{
    $root = rtrim(str_replace('\\', '/', realpath($root) ?: $root), '/');
    $excludedDirectories = source_excluded_directories();
    $extensions = ['php', 'js', 'mjs', 'cjs', 'css', 'py', 'pyw', 'sh', 'bat', 'cmd', 'ps1', 'psm1', 'sql', 'yml', 'yaml', 'html', 'htm', 'svg', 'tex', 'htaccess'];
    $excluded = [];
    $thirdParty = source_provenance_exclusions();
    $provenance = [];
    /**
     * Prune private/generated paths before reading source or descending.
     * @param \SplFileInfo $item Candidate filesystem entry.
     * @return bool Whether discovery may visit the entry.
     */
    $filter = static function (\SplFileInfo $item) use ($root, $excludedDirectories, $thirdParty, &$excluded, &$provenance): bool {
        $relative = substr(str_replace('\\', '/', $item->getPathname()), strlen($root) + 1);
        if ($item->isLink()) {
            $excluded[$relative] = 'symlink; not followed';
            return false;
        }
        if ($item->isDir() && in_array($item->getFilename(), $excludedDirectories, true)) {
            $excluded[$relative . '/'] = 'private, generated, third-party, or agent-owned tree; pruned';
            return false;
        }
        if ($relative === 'config.php' || $relative === 'public/assets/custom.css' || str_starts_with($item->getFilename(), '.env')) {
            $excluded[$relative] = 'installation-owned configuration; never opened';
            return false;
        }
        if (isset($thirdParty[$relative])) {
            $provenance[$relative] = $thirdParty[$relative];
            $excluded[$relative] = 'third-party vector; preserve upstream attribution in ' . $thirdParty[$relative]['license'];
            return false;
        }
        return true;
    };
    $iterator = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
        new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), $filter
    ));
    $files = [];
    $other = [];
    foreach ($iterator as $item) {
        if (!$item->isFile()) {
            continue;
        }
        $path = str_replace('\\', '/', $item->getPathname());
        $extension = strtolower($item->getExtension());
        if (in_array($extension, $extensions, true)) {
            $files[substr($path, strlen($root) + 1)] = $extension;
        } else {
            $key = $extension !== '' ? $extension : '(no extension)';
            $other[$key] = ($other[$key] ?? 0) + 1;
        }
    }
    ksort($files);
    ksort($other);
    ksort($excluded);
    ksort($provenance);
    return ['files' => $files, 'other_formats' => $other, 'excluded' => $excluded, 'provenance' => $provenance];
}

/**
 * Own private/generated directory exclusions for both disk and immutable HEAD paths.
 * @return list<string> Exact directory basenames whose contents must never be scanned.
 */
function source_excluded_directories(): array
{
    return [
        '.git', '.claude', '.codex', '.agent', '.agent-local', '.idea', '.vscode',
        'cache', 'data', 'galleries', 'logs', 'tmp', 'deploy', 'node_modules', 'vendor',
        '__pycache__', '.pytest_cache', '.venv', 'venv', 'http_monitor_logs',
    ];
}

/**
 * Extract leading native comments after a shebang or PHP opening tag.
 * Attribution in strings or later declarations does not satisfy the header rule.
 * @param string $source Complete source text.
 * @param string $extension Lowercase language extension.
 * @param int|null $endOffset Receives the byte offset after the native header/preamble.
 * @return string Leading comments; Python module docstrings are also accepted.
 */
function leading_header(string $source, string $extension, ?int &$endOffset = null): string
{
    $originalLength = strlen($source);
    $source = preg_replace('/^\xEF\xBB\xBF/', '', $source) ?? $source;
    $source = preg_replace('/^#![^\r\n]*\R/', '', $source) ?? $source;
    if ($extension === 'php') {
        $source = preg_replace('/^\s*<\?php\s*/', '', $source) ?? $source;
        $source = preg_replace('/^\s*declare\s*\(\s*strict_types\s*=\s*1\s*\)\s*;\s*/', '', $source) ?? $source;
    } elseif (in_array($extension, ['bat', 'cmd'], true)) {
        $source = preg_replace('/^\s*@?echo\s+off\s*(?:\R|$)/i', '', $source) ?? $source;
    } elseif (in_array($extension, ['html', 'htm', 'svg'], true)) {
        $source = preg_replace('/^\s*<\?xml[\s\S]*?\?>\s*/i', '', $source) ?? $source;
        $source = preg_replace('/^\s*<!doctype[^>]*>\s*/i', '', $source) ?? $source;
    }
    $source = ltrim($source);
    $patterns = match ($extension) {
        'php', 'js', 'mjs', 'cjs', 'css', 'sql' => ['~^/\*[\s\S]*?\*/~', '~^(?:(?://|--)[^\r\n]*(?:\R|$)|\s*\R)+~'],
        'ps1', 'psm1' => ['~^<#[\s\S]*?#>~', '~^(?:\#[^\r\n]*(?:\R|$)|\s*\R)+~'],
        'py', 'pyw' => ['~^(?:\#[^\r\n]*(?:\R|$)|\s*\R)+~', '~^(?:[ru])?("""|\'\'\')[\s\S]*?\1~i'],
        'sh', 'yml', 'yaml', 'htaccess' => ['~^(?:\#[^\r\n]*(?:\R|$)|\s*\R)+~'],
        'tex' => ['~^(?:%[^\r\n]*(?:\R|$)|\s*\R)+~'],
        'html', 'htm', 'svg', 'md' => ['~^<!--[\s\S]*?-->~'],
        'bat', 'cmd' => ['~^(?:(?:@?rem\b|::)[^\r\n]*(?:\R|$)|\s*\R)+~i'],
        default => [],
    };
    $header = '';
    while ($source !== '') {
        $matched = false;
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $source, $match) === 1 && $match[0] !== '') {
                $header .= "\n" . $match[0];
                $source = ltrim(substr($source, strlen($match[0])));
                $matched = true;
                break;
            }
        }
        if (!$matched) {
            break;
        }
    }
    $endOffset = $originalLength - strlen($source);
    return $header;
}

/**
 * Validate identity and substantive template fields in the actual file header.
 * @param string $source Source to inspect without executing it.
 * @param string $path Repository-relative source identity.
 * @return list<string> Stable missing/mismatched header rule identifiers.
 */
function header_issues(string $source, string $path): array
{
    $header = leading_header($source, strtolower(pathinfo($path, PATHINFO_EXTENSION)));
    $header = str_replace(['<!--', '-->'], '', $header);
    $header = preg_replace('/^\s*(?:\/\*+|\*\/|\*|\/\/|#|%|--|@?rem\b|::)\s?/mi', '', $header) ?? $header;
    $issues = [];
    // These two shipped assets have an enforced byte-identity compatibility
    // contract. The shared header must truthfully name both roles, not omit File.
    $fileIdentity = match ($path) {
        'public/assets/usage.js', 'public/assets/telemetry.js' => 'public/assets/usage.js (canonical); public/assets/telemetry.js (compatibility copy)',
        default => $path,
    };
    foreach (['Project' => 'PHP Gallery', 'Repository' => 'https://github.com/klusik/PHP_gallery', 'File' => $fileIdentity, 'Author' => 'Rudolf Klusal'] as $field => $expected) {
        if (preg_match('/(?:^|\R)\s*' . preg_quote($field, '/') . ':\s*' . preg_quote($expected, '/') . '\s*(?:\R|$)/', $header) !== 1) {
            $issues[] = 'header.' . strtolower($field);
        }
    }
    foreach (['Module Type', 'Purpose', 'Responsibilities'] as $field) {
        if (preg_match('/(?:^|\R)\s*' . preg_quote($field, '/') . ':\s*([^\r\n]+)/', $header, $match) !== 1
            || preg_match('/^(?:Project|Repository|File|Module Type|Purpose|Responsibilities|Author|Contact|License|Notes):/i', trim($match[1])) === 1) {
            $issues[] = 'header.' . strtolower(str_replace(' ', '_', $field));
        }
    }
    return $issues;
}
