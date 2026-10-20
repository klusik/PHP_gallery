<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/production_package_test.php
 * Module Type: Packaging Regression Test
 * Purpose: Exercise the real production builder against a small source package without Git metadata.
 * Responsibilities: Prove exact bytes, ZIP reproducibility, metadata, refusal paths, cleanup, and native link safety.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/scripts/package_production.php';
require_once dirname(__DIR__) . '/scripts/audit_lib.php';

/**
 * Fail a compact packaging assertion with its concrete contract.
 *
 * @param bool $condition Required invariant.
 * @param string $message Failure detail for the audit report.
 * @return void Throws when the invariant is false.
 */
function production_package_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * Require the expected refusal rather than allowing an unrelated error to satisfy a negative case.
 *
 * @param callable():void $operation Real builder or verifier invocation expected to fail.
 * @param string $expectedMessage Required exception message substring.
 * @return void Throws if the operation succeeds or fails for a different reason.
 */
function production_package_refuses(
    callable $operation,
    string $expectedMessage,
): void {
    try {
        $operation();
    } catch (RuntimeException $exception) {
        production_package_assert(
            str_contains($exception->getMessage(), $expectedMessage),
            'Wrong packaging refusal; expected "'
                . $expectedMessage
                . '", received: '
                . $exception->getMessage(),
        );
        return;
    }
    throw new RuntimeException('Builder accepted a negative case: '
        . $expectedMessage);
}

/**
 * Create one tiny fixture file and its explicitly needed directories.
 *
 * @param string $root Isolated source root.
 * @param string $path Relative fixture file.
 * @param string $bytes Exact fixture bytes, including binary/non-normalized text.
 * @return void Writes the file or fails immediately.
 */
function production_package_write(
    string $root,
    string $path,
    string $bytes,
): void {
    $absolute = $root . '/' . $path;
    if (!is_dir(dirname($absolute))) {
        production_package_assert(
            mkdir(dirname($absolute), 0755, true),
            'Could not create package fixture directory.',
        );
    }
    production_package_assert(
        file_put_contents($absolute, $bytes) === strlen($bytes),
        'Could not write package fixture.',
    );
}

/**
 * Run a bounded real CLI command through the central process owner.
 *
 * @param list<string> $command Executable followed by arguments.
 * @param string $directory Child working directory.
 * @return array{exit_code:int,stdout:string,stderr:string} Complete captured child evidence.
 */
function production_package_run(array $command, string $directory): array
{
    $result = \PhpGallery\Audit\run_process($command, $directory, 20);
    production_package_assert(
        empty($result['blocked']) && !$result['timed_out'],
        'Packaging fixture child was blocked or timed out: '
            . implode(' ', $command),
    );
    return [
        'exit_code' => $result['exit_code'],
        'stdout' => $result['stdout'],
        'stderr' => $result['stderr'],
    ];
}

/**
 * Create and positively verify a mandatory Windows junction through a native executable.
 *
 * The central process owner quotes each literal argument. EncodedCommand keeps
 * PowerShell syntax and Unicode paths inside one encoded argument rather than
 * passing a cmd.exe builtin through those executable-argument quoting rules.
 * External junction publication invalidates both PHP stat and realpath caches
 * before PHP inspects a path that may previously have named a regular directory.
 *
 * @param string $link New test-owned junction path, including spaces or Unicode.
 * @param string $target Existing test-owned directory to reference.
 * @param string $directory Working directory for the bounded native child.
 * @return void Throws with complete child diagnostics if creation or native junction verification fails.
 */
function production_package_junction(
    string $link,
    string $target,
    string $directory,
): void {
    $linkLiteral =
        "'"
        . str_replace("'", "''", str_replace('/', DIRECTORY_SEPARATOR, $link))
        . "'";
    $targetLiteral =
        "'"
        . str_replace("'", "''", str_replace('/', DIRECTORY_SEPARATOR, $target))
        . "'";
    $script =
        '$ErrorActionPreference="Stop"; try { '
        . '$link='
        . $linkLiteral
        . '; $target='
        . $targetLiteral
        . '; '
        . 'if (-not [IO.Directory]::Exists($target)) { throw "The junction fixture target is missing." }; '
        . 'New-Item -ItemType Junction -Path $link -Target $target -ErrorAction Stop | Out-Null; '
        . 'if ((Get-Item -LiteralPath $link -Force).LinkType -ne "Junction" '
        . '-or ([IO.File]::GetAttributes($link) -band [IO.FileAttributes]::ReparsePoint) -eq 0) { '
        . 'throw "The fixture did not create a native junction reparse point." }; exit 0 '
        . '} catch { [Console]::Error.WriteLine($_.Exception.ToString()); exit 1 }';
    $nativeShell =
        (string) getenv('SystemRoot')
        . '\\System32\\WindowsPowerShell\\v1.0\\powershell.exe';
    $encoded = base64_encode(mb_convert_encoding($script, 'UTF-16LE', 'UTF-8'));
    $result = production_package_run([
        $nativeShell,
        '-NoLogo',
        '-NoProfile',
        '-NonInteractive',
        '-EncodedCommand',
        $encoded,
    ], $directory);
    clearstatcache(true);
    production_package_assert(
        $result['exit_code'] === 0 && is_dir($link),
        'BLOCKED: mandatory Windows junction fixture failed: '
            . $result['stderr']
            . $result['stdout'],
    );
}

$started = microtime(true);
production_package_assert(
    class_exists(ZipArchive::class),
    'BLOCKED: production packaging smoke requires PHP ZipArchive.',
);
$fixture =
    (string) realpath(sys_get_temp_dir())
    . DIRECTORY_SEPARATOR
    . 'gallery package žluťoučký '
    . bin2hex(random_bytes(5));
$source = $fixture . DIRECTORY_SEPARATOR . 'source package';
production_package_assert(
    mkdir($source, 0755, true),
    'Could not create isolated package fixture.',
);
$junctions = [];
try {
    $payloads = [
        '.htaccess' => "Options -Indexes\n",
        'app/.htaccess' => "Require all denied\n",
        'app/bootstrap.php' => "<?php\nconst CMS_VERSION = '1.0.0';\n",
        'app/core-manifest.json' => "{}\n",
        'app/example.php' => "\xEF\xBB\xBF<?php\r\n// Preserve these exact checkout bytes.\r\n",
        'app/production-files.json' => "{}\n",
        'deploy/.htaccess' => "Require all denied\n",
        'docs/manual.pdf' => "%PDF-fixture\x00\xFF\r\n",
        'galleries/.htaccess' => "Options -Indexes\n",
        'index.php' => "<?php require __DIR__ . '/app/bootstrap.php';\n",
        'public/assets/app.js' => "'use strict';\r\n",
        'public/assets/žluťoučký soubor.css' => "body { color: red; }\n",
        'scripts/check.php' => "#!/usr/bin/env php\n<?php echo 'fixture';\n",
        'winapp/uploader/media.py' => "# Companion bytes\n",
        'winapp/uploader/resources/DejaVuSans.ttf' => "font-fixture\x00\xFF\r\n",
        'winapp/uploader/resources/LICENSE_DEJAVU' => "Font redistribution license fixture\n",
    ];
    $production = array_values(array_filter(
        array_keys($payloads),
        static fn(string $path): bool => !str_starts_with($path, 'winapp/'),
    ));
    sort($production, SORT_STRING);
    $updater = array_values(array_filter(
        $production,
        '\\Gallery\\Core\\release_file_policy_is_updater_path',
    ));
    $payloads['app/production-files.json'] =
        json_encode(
            [
                'schema_version' => 1,
                'production_files' => $production,
                'companion_files' => ['winapp/uploader/media.py'],
                'companion_resource_files' => [
                    'winapp/uploader/resources/DejaVuSans.ttf',
                    'winapp/uploader/resources/LICENSE_DEJAVU',
                ],
                'updater_files' => $updater,
                'source_review_files' => [],
            ],
            JSON_PRETTY_PRINT
                | JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_THROW_ON_ERROR,
        ) . "\n";
    foreach ($payloads as $path => $bytes) {
        production_package_write($source, $path, $bytes);
    }
    // Use the actual manifest producer, with no Git metadata or source-fixture checker substitutes.
    $generated = production_package_run([
        PHP_BINARY,
        dirname(__DIR__) . '/scripts/generate_manifest.php',
        '--root=' . $source,
    ], $source);
    production_package_assert(
        $generated['exit_code'] === 0,
        'Fixture manifest producer failed: ' . $generated['stderr'],
    );
    $payloads['app/core-manifest.json'] = (string) file_get_contents($source
        . '/app/core-manifest.json');
    foreach (array_keys($payloads) as $index => $path) {
        production_package_assert(
            touch($source . '/' . $path, 1700000000 + $index),
            'Could not set fixture file time.',
        );
    }
    production_package_assert(
        chmod($source . '/scripts/check.php', 0751),
        'Could not set fixture executable mode.',
    );
    foreach ([
        'config.php',
        'galleries/private/photo.jpg',
        'cache/private.bin',
        'data/private.json',
        'logs/private.log',
        '.agent-local/private.txt',
        '.codex/private.txt',
        '.vscode/settings.json',
        'app/unlisted.php',
        'winapp/settings.json',
        'winapp/dist/private.exe',
    ] as $private) {
        production_package_write($source, $private, "private fixture\n");
    }
    $folder = $fixture . '/production folder';
    $archive = $fixture . '/production archive.zip';
    $command = [
        PHP_BINARY,
        dirname(__DIR__) . '/scripts/package_production.php',
        '--root=' . $source,
        '--directory=' . $folder,
        '--zip=' . $archive,
        '--timestamp=1700000020',
    ];
    $nativeTimezone = getenv('TZ');
    try {
        production_package_assert(
            putenv('TZ=UTC0'),
            'Could not prepare the UTC archive child.',
        );
        $child = production_package_run($command, $source);
    } finally {
        putenv($nativeTimezone === false ? 'TZ' : 'TZ=' . $nativeTimezone);
    }
    production_package_assert(
        $child['exit_code'] === 0,
        'Real builder CLI failed: ' . $child['stderr'] . $child['stdout'],
    );
    $report = json_decode($child['stdout'], true, 512, JSON_THROW_ON_ERROR);
    production_package_assert(
        $report['file_count'] === count($payloads)
            && $report['archive_sha256'] === hash_file('sha256', $archive),
        'Builder report does not identify the complete archive.',
    );
    $expectedPaths = array_keys($payloads);
    sort($expectedPaths, SORT_STRING);
    production_package_assert(
        \Gallery\Core\release_file_policy_enumerate_files($folder)
            === $expectedPaths,
        'Real builder published extra or missing directory files.',
    );
    $zip = new ZipArchive();
    production_package_assert(
        $zip->open($archive) === true,
        'Could not inspect real builder archive.',
    );
    try {
        production_package_assert(
            $zip->numFiles === count($expectedPaths),
            'Real archive contains extra or missing entries.',
        );
        foreach ($expectedPaths as $index => $path) {
            production_package_assert(
                $zip->getNameIndex($index) === $path,
                'Real archive paths are not exactly sorted inventory entries.',
            );
            production_package_assert(
                hash_file('sha256', $folder . '/' . $path) === hash(
                    'sha256',
                    $payloads[$path],
                )
                    && hash(
                        'sha256',
                        (string) $zip->getFromIndex($index),
                    ) === hash('sha256', $payloads[$path]),
                'Directory or ZIP bytes differ from original complete source bytes: '
                    . $path,
            );
            production_package_assert(
                filemtime($folder . '/' . $path) === filemtime(
                    $source . '/' . $path,
                ),
                'Package did not preserve source modification time: ' . $path,
            );
        }
    } finally {
        $zip->close();
    }
    if (DIRECTORY_SEPARATOR !== '\\') {
        production_package_assert(
            (fileperms($folder . '/scripts/check.php') & 0777) === 0751,
            'Source executable permissions were lost.',
        );
    }
    $again = \Gallery\Packaging\build(
        $source,
        null,
        $fixture . '/second.zip',
        1700000020,
    );
    production_package_assert(
        $again['archive_sha256'] === $report['archive_sha256'],
        'Identical inputs produced a different ZIP checksum.',
    );
    try {
        production_package_assert(
            putenv('TZ=EST5EDT'),
            'Could not prepare a non-UTC archive child.',
        );
        $alternate = production_package_run([
            PHP_BINARY,
            dirname(__DIR__) . '/scripts/package_production.php',
            '--root=' . $source,
            '--zip=' . $fixture . '/different native timezone.zip',
            '--timestamp=1700000020',
        ], $source);
    } finally {
        putenv($nativeTimezone === false ? 'TZ' : 'TZ=' . $nativeTimezone);
    }
    production_package_assert(
        $alternate['exit_code'] === 0,
        'Non-UTC archive child failed: ' . $alternate['stderr'],
    );
    production_package_assert(
        json_decode(
            $alternate['stdout'],
            true,
            512,
            JSON_THROW_ON_ERROR,
        )['archive_sha256'] === $report['archive_sha256'],
        'Native process timezone changed the deterministic archive bytes.',
    );
    $evidence = \Gallery\Packaging\source_evidence($source)['files'];
    try {
        production_package_assert(
            putenv('TZ=EST5EDT'),
            'Could not prepare standalone verifier timezone regression.',
        );
        \Gallery\Packaging\verify_zip(
            $fixture . '/second.zip',
            $evidence,
            1700000020,
        );
        production_package_assert(
            getenv('TZ') === 'EST5EDT',
            'Standalone ZIP verification leaked its native UTC timezone.',
        );
    } finally {
        putenv($nativeTimezone === false ? 'TZ' : 'TZ=' . $nativeTimezone);
    }
    \Gallery\Packaging\verify_directory($folder, $evidence);
    $directoryOnly = \Gallery\Packaging\build(
        $source,
        $fixture . '/directory only',
        null,
        1700000020,
    );
    production_package_assert(
        $directoryOnly['zip'] === null
            && $directoryOnly['archive_sha256'] === null
            && $directoryOnly['file_count'] === count($payloads),
        'Directory-only builder contract changed.',
    );
    $originalArchive = hash_file('sha256', $archive);
    production_package_refuses(static function () use (
        $source,
        $archive,
    ): void {
        \Gallery\Packaging\build($source, null, $archive, 1700000020);
    }, 'already exists');
    production_package_refuses(static function () use ($source, $folder): void {
        \Gallery\Packaging\build($source, $folder);
    }, 'already exists');
    production_package_assert(
        hash_file('sha256', $archive) === $originalArchive,
        'No-clobber refusal changed an existing artifact.',
    );
    foreach ([
        $source . '/inside.zip',
        $fixture,
        $fixture . '/../escape.zip',
        $fixture . '/missing-parent/output.zip',
    ] as $unsafe) {
        production_package_refuses(static function () use (
            $source,
            $unsafe,
        ): void {
            \Gallery\Packaging\build($source, null, $unsafe, 1700000020);
        }, match ($unsafe) {
            $source . '/inside.zip', $fixture => 'outside',
            $fixture . '/../escape.zip' => 'unsafe',
            default => 'missing',
        });
    }
    production_package_refuses(static function () use (
        $source,
        $fixture,
    ): void {
        \Gallery\Packaging\build(
            $source,
            $fixture . '/same',
            $fixture . '/same',
            1700000020,
        );
    }, 'distinct');
    $inventoryPath = $source . '/app/production-files.json';
    $manifestPath = $source . '/app/core-manifest.json';
    $inventory = json_decode(
        $payloads['app/production-files.json'],
        true,
        512,
        JSON_THROW_ON_ERROR,
    );
    $missingStructure = $inventory;
    foreach (['production_files', 'updater_files'] as $list) {
        $missingStructure[$list] = array_values(array_diff($missingStructure[$list], [
            'index.php',
        ]));
    }
    file_put_contents($inventoryPath, json_encode(
        $missingStructure,
        JSON_THROW_ON_ERROR,
    ));
    $generated = production_package_run([
        PHP_BINARY,
        dirname(__DIR__) . '/scripts/generate_manifest.php',
        '--root=' . $source,
    ], $source);
    production_package_assert(
        $generated['exit_code'] === 0,
        'Could not generate the intentionally incomplete structure manifest.',
    );
    production_package_refuses(static function () use (
        $source,
        $fixture,
    ): void {
        \Gallery\Packaging\build(
            $source,
            null,
            $fixture . '/refused.zip',
            1700000020,
        );
    }, 'required structural file: index.php');
    file_put_contents($inventoryPath, $payloads['app/production-files.json']);
    file_put_contents($manifestPath, $payloads['app/core-manifest.json']);
    foreach ([
        'config.php',
        'galleries/private/photo.jpg',
        'app/../config.php',
        'app/CON.txt',
        'public/assets/custom-overrides.css',
    ] as $invalid) {
        $changed = $inventory;
        $changed['production_files'][] = $invalid;
        sort($changed['production_files'], SORT_STRING);
        file_put_contents($inventoryPath, json_encode(
            $changed,
            JSON_THROW_ON_ERROR,
        ));
        production_package_refuses(
            static function () use ($source, $fixture): void {
                \Gallery\Packaging\build(
                    $source,
                    null,
                    $fixture . '/refused.zip',
                    1700000020,
                );
            },
            in_array($invalid, ['app/../config.php', 'app/CON.txt'], true)
                ? 'unsafe'
                : 'private or unapproved',
        );
    }
    file_put_contents($inventoryPath, $payloads['app/production-files.json']);
    foreach ([
        '{invalid',
        '{"files":{}}',
        str_replace(
            'normalized-text-sha256',
            'sha256',
            $payloads['app/core-manifest.json'],
        ),
        str_replace('1.0.0', '9.0.0', $payloads['app/core-manifest.json']),
    ] as $invalidManifest) {
        file_put_contents($manifestPath, $invalidManifest);
        production_package_refuses(static function () use (
            $source,
            $fixture,
        ): void {
            \Gallery\Packaging\build(
                $source,
                null,
                $fixture . '/refused.zip',
                1700000020,
            );
        }, 'manifest');
    }
    file_put_contents($manifestPath, $payloads['app/core-manifest.json']);
    file_put_contents($source . '/app/example.php', 'changed core bytes');
    production_package_refuses(static function () use (
        $source,
        $fixture,
    ): void {
        \Gallery\Packaging\build(
            $source,
            null,
            $fixture . '/refused.zip',
            1700000020,
        );
    }, 'hash is stale');
    file_put_contents(
        $source . '/app/example.php',
        $payloads['app/example.php'],
    );
    unlink($source . '/docs/manual.pdf');
    production_package_refuses(static function () use (
        $source,
        $fixture,
    ): void {
        \Gallery\Packaging\build(
            $source,
            null,
            $fixture . '/refused.zip',
            1700000020,
        );
    }, 'missing or unreadable');
    file_put_contents(
        $source . '/docs/manual.pdf',
        $payloads['docs/manual.pdf'],
    );

    // Two output reservations prove that a later failure releases earlier ownership without publishing anything.
    file_put_contents($fixture . '/reserved.zip.package.lock', 'other builder');
    production_package_refuses(static function () use (
        $source,
        $fixture,
    ): void {
        \Gallery\Packaging\build(
            $source,
            $fixture . '/never-published',
            $fixture . '/reserved.zip',
            1700000020,
        );
    }, 'already reserved');
    production_package_assert(
        !file_exists($fixture . '/never-published')
            && !file_exists($fixture . '/never-published.package.lock')
            && file_get_contents($fixture . '/reserved.zip.package.lock')
                === 'other builder',
        'Failure cleanup removed another reservation or leaked an output.',
    );
    unlink($fixture . '/reserved.zip.package.lock');

    // These checks use the same production verifiers and prove membership alone cannot accept corrupt PDF bytes.
    file_put_contents($folder . '/docs/manual.pdf', str_repeat(
        'C',
        strlen($payloads['docs/manual.pdf']),
    ));
    touch($folder . '/docs/manual.pdf', $evidence['docs/manual.pdf']['mtime']);
    production_package_refuses(static function () use (
        $folder,
        $evidence,
    ): void {
        \Gallery\Packaging\verify_directory($folder, $evidence);
    }, 'content or metadata mismatch');
    file_put_contents($folder . '/extra.txt', 'extra');
    production_package_refuses(static function () use (
        $folder,
        $evidence,
    ): void {
        \Gallery\Packaging\verify_directory($folder, $evidence);
    }, 'membership');
    try {
        production_package_assert(
            putenv('TZ=UTC0'),
            'Could not prepare corrupt ZIP metadata in UTC.',
        );
        $zip = new ZipArchive();
        production_package_assert(
            $zip->open($archive) === true
                && $zip->addFromString('docs/manual.pdf', str_repeat(
                    'C',
                    strlen($payloads['docs/manual.pdf']),
                ))
                && $zip->setMtimeName('docs/manual.pdf', 1700000020)
                && $zip->setExternalAttributesName(
                    'docs/manual.pdf',
                    ZipArchive::OPSYS_UNIX,
                    $evidence['docs/manual.pdf']['mode'] << 16,
                )
                && $zip->close(),
            'Could not prepare corrupt ZIP regression.',
        );
    } finally {
        putenv($nativeTimezone === false ? 'TZ' : 'TZ=' . $nativeTimezone);
    }
    production_package_refuses(static function () use (
        $archive,
        $evidence,
    ): void {
        \Gallery\Packaging\verify_zip($archive, $evidence, 1700000020);
    }, 'member content mismatch');

    if (DIRECTORY_SEPARATOR === '\\') {
        // Junction creation requires no developer-mode or symbolic-link privilege on Windows runners.
        $linked = $source . '/linked app';
        production_package_junction($linked, $source . '/app', $fixture);
        $junctions[] = $linked;
        production_package_refuses(static function () use ($linked): void {
            \Gallery\Packaging\assert_native_paths([$linked]);
        }, 'Package path');
        production_package_refuses(static function () use (
            $source,
            $linked,
        ): void {
            \Gallery\Packaging\build(
                $source,
                null,
                $linked . '/forbidden.zip',
                1700000020,
            );
        }, 'Package path');
        production_package_assert(
            !file_exists($linked . '/forbidden.zip'),
            'Destination-junction refusal published an output through its target.',
        );
        $renamed = $source . '/public-real';
        production_package_assert(
            rename($source . '/public', $renamed),
            'Could not prepare Windows source-junction replacement.',
        );
        production_package_junction($source . '/public', $renamed, $fixture);
        $junctions[] = $source . '/public';
        production_package_assert(
            is_dir($renamed . '/assets')
                && is_file($renamed . '/assets/app.js')
                && is_dir($source . '/public/assets')
                && is_file($source . '/public/assets/app.js')
                && file_get_contents($source . '/public/assets/app.js')
                    === $payloads['public/assets/app.js'],
            'Windows source junction must expose its existing target directory and unchanged payload before the safety refusal.',
        );
        production_package_refuses(static function () use (
            $source,
            $fixture,
        ): void {
            \Gallery\Packaging\build(
                $source,
                null,
                $fixture . '/source-junction.zip',
                1700000020,
            );
        }, 'production file path');
        production_package_assert(
            !file_exists($fixture . '/source-junction.zip'),
            'Source-junction refusal published an output.',
        );
    } else {
        production_package_assert(
            symlink($source . '/app', $fixture . '/linked-parent'),
            'Could not prepare mandatory POSIX link fixture.',
        );
        production_package_refuses(static function () use (
            $source,
            $fixture,
        ): void {
            \Gallery\Packaging\build(
                $source,
                null,
                $fixture . '/linked-parent/forbidden.zip',
                1700000020,
            );
        }, 'Package path');
        unlink($source . '/app/example.php');
        production_package_assert(
            symlink(
                $source . '/app/bootstrap.php',
                $source . '/app/example.php',
            ),
            'Could not create mandatory source link fixture.',
        );
        production_package_refuses(static function () use (
            $source,
            $fixture,
        ): void {
            \Gallery\Packaging\build(
                $source,
                null,
                $fixture . '/source-link.zip',
                1700000020,
            );
        }, 'symbolic link');
    }
    production_package_assert(
        (glob($fixture . '/.gallery-package-*') ?: []) === []
            && (glob($fixture . '/*.package.lock') ?: []) === []
            && !file_exists($fixture . '/refused.zip'),
        'Failed production builds leaked a staging workspace, reservation, or published artifact.',
    );
    printf(
        "Production packaging smoke passed: %d payload files; %.3f seconds; copy %.4fs; ZIP %.4fs; validation %.4fs.\n",
        count($payloads),
        microtime(true) - $started,
        $report['timings']['copy_seconds'],
        $report['timings']['zip_seconds'],
        $report['timings']['verification_seconds'],
    );
} finally {
    foreach (array_reverse($junctions) as $junction) {
        // Remove only the verified junction itself, never its destination tree.
        rmdir($junction);
    }
    \Gallery\Packaging\remove_tree($fixture);
}
