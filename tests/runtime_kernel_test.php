<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/runtime_kernel_test.php
 * Module Type: Regression Test
 * Purpose: Protect the small OO runtime kernel's autoload, route, module and dispatch contracts.
 * Responsibilities: Verify strict loading, cycle/path refusals, route fallback and request isolation.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

declare(strict_types=1);

namespace Gallery\Controllers {
    /**
     * Record a procedural handler invocation for runtime-kernel compatibility coverage.
     *
     * @return void Stores one call marker in the test process.
     */
    function runtime_kernel_test_handler(): void
    {
        $GLOBALS['runtime_kernel_test_handler_calls'] =
            (int) ($GLOBALS['runtime_kernel_test_handler_calls'] ?? 0) + 1;
    }

    /**
     * Record fallback-handler invocation for unknown-route coverage.
     *
     * @return void Stores one fallback marker in the test process.
     */
    function runtime_kernel_test_fallback(): void
    {
        $GLOBALS['runtime_kernel_test_fallback_calls'] =
            (int) ($GLOBALS['runtime_kernel_test_fallback_calls'] ?? 0) + 1;
    }
}

namespace {
    use Gallery\Core\Kernel;
    use Gallery\Core\ModuleLoader;
    use Gallery\Core\Request;
    use Gallery\Core\RouteDefinition;
    use Gallery\Core\RouteRegistry;
    use Gallery\Core\Router;

    $root = dirname(__DIR__);
    require_once $root . '/app/runtime/autoload.php';

    /**
     * Assert one runtime-kernel test condition.
     *
     * @param bool $condition Condition expected to hold.
     * @param string $message Failure description.
     * @return void Throws when the assertion fails.
     */
    function runtime_kernel_assert(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    /**
     * Return an isolated fixture root with an app directory.
     *
     * @return string Absolute temporary project root.
     */
    function runtime_kernel_temp_root(): string
    {
        $base =
            rtrim(sys_get_temp_dir(), '/\\')
            . DIRECTORY_SEPARATOR
            . 'php-gallery-runtime-kernel-'
            . bin2hex(random_bytes(8));
        if (
            !mkdir($base, 0777, true)
            || !mkdir($base . DIRECTORY_SEPARATOR . 'app')
        ) {
            throw new RuntimeException(
                'Could not create runtime-kernel fixture root.',
            );
        }
        return $base;
    }

    /**
     * Write one PHP fixture beneath the isolated project root.
     *
     * @param string $root Isolated temporary project root.
     * @param string $relativePath Root-relative fixture path.
     * @param string $source PHP source to write.
     * @return void Writes the fixture or throws.
     */
    function runtime_kernel_write_fixture(
        string $root,
        string $relativePath,
        string $source,
    ): void {
        $path =
            $root
            . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $directory = dirname($path);
        if (
            !is_dir($directory)
            && !mkdir($directory, 0777, true)
            && !is_dir($directory)
        ) {
            throw new RuntimeException(
                'Could not create runtime-kernel fixture directory.',
            );
        }
        if (file_put_contents($path, $source) === false) {
            throw new RuntimeException(
                'Could not write runtime-kernel fixture.',
            );
        }
    }

    /**
     * Remove the isolated temporary project tree after fixture-based checks.
     *
     * @param string $root Temporary project root created by this test.
     * @return void Removes only the supplied temporary root.
     */
    function runtime_kernel_remove_tree(string $root): void
    {
        if (!is_dir($root)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $root,
                FilesystemIterator::SKIP_DOTS,
            ),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            if ($entry->isDir() && !$entry->isLink()) {
                rmdir($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }
        rmdir($root);
    }

    /**
     * Assert that an operation throws the expected exception class.
     *
     * @param callable():mixed $operation Operation expected to fail.
     * @param class-string<Throwable> $exceptionClass Expected exception class.
     * @param string $message Failure description.
     * @return void Throws when the operation does not fail as expected.
     */
    function runtime_kernel_expect_exception(
        callable $operation,
        string $exceptionClass,
        string $message,
    ): void {
        try {
            $operation();
        } catch (Throwable $exception) {
            runtime_kernel_assert(
                $exception instanceof $exceptionClass,
                $message . ' (wrong exception: ' . get_class($exception) . ')',
            );
            return;
        }
        throw new RuntimeException($message . ' (no exception was thrown)');
    }

    runtime_kernel_assert(
        class_exists(Request::class),
        'Runtime autoloader did not load a class from Gallery\\Core.',
    );

    $foreignAutoloadCalled = false;
    $foreignAutoloader = static function (string $class) use (
        &$foreignAutoloadCalled,
    ): void {
        $foreignAutoloadCalled = true;
    };
    spl_autoload_register($foreignAutoloader);
    try {
        runtime_kernel_assert(
            !class_exists('Example\\ForeignRuntimeKernelProbe', true),
            'Unexpected foreign class resolved.',
        );
        runtime_kernel_assert(
            $foreignAutoloadCalled,
            'The Gallery autoloader should ignore a foreign namespace and allow later autoloaders to run.',
        );
    } finally {
        spl_autoload_unregister($foreignAutoloader);
    }

    runtime_kernel_assert(
        !class_exists('Gallery\\Core\\RuntimeKernelMissingProbe', true),
        'A missing runtime class unexpectedly resolved.',
    );
    runtime_kernel_assert(
        !class_exists('Gallery\\Core\\..\\OutsideRuntimeKernelProbe', true),
        'Runtime autoloader accepted a traversal class name.',
    );

    $validDefinition = new RouteDefinition(
        'gallery',
        'Gallery\\Controllers\\not_loaded_yet',
        ['public-gallery'],
    );
    runtime_kernel_assert(
        !is_callable($validDefinition->handler),
        'RouteDefinition unexpectedly requires a handler to be loaded during registration.',
    );
    runtime_kernel_expect_exception(
        static fn(): RouteDefinition => new RouteDefinition(
            'Bad-Name',
            'Gallery\\Controllers\\handler',
            ['public-gallery'],
        ),
        InvalidArgumentException::class,
        'Invalid route syntax should be rejected.',
    );
    runtime_kernel_expect_exception(
        static fn(): RouteDefinition => new RouteDefinition(
            'gallery',
            'Example\\Controllers\\handler',
            ['public-gallery'],
        ),
        InvalidArgumentException::class,
        'Foreign handler namespace should be rejected.',
    );
    runtime_kernel_expect_exception(
        static fn(): RouteDefinition => new RouteDefinition(
            'gallery',
            'Gallery\\Controllers\\handler',
            ['public-gallery', 'public-gallery'],
        ),
        InvalidArgumentException::class,
        'Duplicate route modules should be rejected.',
    );
    runtime_kernel_expect_exception(
        static fn(): RouteDefinition => new RouteDefinition(
            'gallery',
            'Gallery\\Controllers\\handler',
            ['../controllers'],
        ),
        InvalidArgumentException::class,
        'Invalid route module identifiers should be rejected.',
    );

    $fallback = new RouteDefinition(
        'not_found',
        'Gallery\\Controllers\\runtime_kernel_test_fallback',
        ['kernel-fixture'],
    );
    $known = new RouteDefinition(
        'gallery',
        'Gallery\\Controllers\\runtime_kernel_test_handler',
        ['kernel-fixture'],
    );
    $registry = new RouteRegistry([$known], $fallback);
    runtime_kernel_assert(
        $registry->find('gallery') === $known,
        'RouteRegistry did not retain the known definition.',
    );
    runtime_kernel_assert(
        $registry->find('absent') === null,
        'RouteRegistry find should return null for an unknown route.',
    );
    runtime_kernel_assert(
        $registry->resolve('absent') === $fallback,
        'Unknown routes should resolve to the configured fallback.',
    );
    runtime_kernel_expect_exception(
        static fn(): RouteRegistry => new RouteRegistry(
            [$known, $known],
            $fallback,
        ),
        InvalidArgumentException::class,
        'Duplicate route names should be rejected.',
    );

    $tempRoot = runtime_kernel_temp_root();
    $globalKey = 'runtime_kernel_fixture_' . bin2hex(random_bytes(8));
    $GLOBALS[$globalKey] = [];
    try {
        runtime_kernel_write_fixture(
            $tempRoot,
            'app/base.php',
            '<?php $GLOBALS['
                . var_export($globalKey, true)
                . "][] = 'base';\n",
        );
        runtime_kernel_write_fixture(
            $tempRoot,
            'app/feature.php',
            '<?php $GLOBALS['
                . var_export($globalKey, true)
                . "][] = 'feature';\n",
        );
        runtime_kernel_write_fixture(
            $tempRoot,
            'app/partial.php',
            '<?php $GLOBALS['
                . var_export($globalKey, true)
                . "][] = 'partial';\n",
        );
        runtime_kernel_write_fixture(
            $tempRoot,
            'escape.php',
            '<?php $GLOBALS['
                . var_export($globalKey, true)
                . "][] = 'escape';\n",
        );

        $definitions = [
            'base' => ['depends' => [], 'files' => ['app/base.php']],
            'feature' => [
                'depends' => ['base'],
                'files' => ['app/feature.php'],
            ],
            'empty' => ['depends' => [], 'files' => []],
        ];
        $loader = new ModuleLoader($tempRoot, $definitions);
        $loader->load('feature');
        $loader->load('base');
        $loader->load('feature');
        runtime_kernel_assert(
            $GLOBALS[$globalKey] === ['base', 'feature'],
            'Module dependencies should load in order and only once per loader.',
        );
        runtime_kernel_assert(
            $loader->loadedModules() === ['base', 'feature'],
            'Loaded module history should reflect dependency-first order without duplicates.',
        );

        runtime_kernel_write_fixture(
            $tempRoot,
            'app/schema-core.php',
            '<?php $GLOBALS['
                . var_export($globalKey, true)
                . "][] = 'schema-core';\n",
        );
        runtime_kernel_write_fixture(
            $tempRoot,
            'app/models/schema-model.php',
            '<?php $GLOBALS['
                . var_export($globalKey, true)
                . "][] = 'schema-model';\n",
        );
        runtime_kernel_write_fixture(
            $tempRoot,
            'app/services/schema-service.php',
            '<?php $GLOBALS['
                . var_export($globalKey, true)
                . "][] = 'schema-service';\n",
        );
        $composedDefinitions = [
            'schema-core' => [
                'depends' => [],
                'files' => ['app/schema-core.php'],
            ],
            'schema-model' => [
                'depends' => ['schema-core'],
                'files' => ['app/models/schema-model.php'],
            ],
            'schema-service' => [
                'depends' => ['schema-model'],
                'files' => ['app/services/schema-service.php'],
            ],
        ];
        $composedOrder = [
            'app/schema-core.php',
            'app/models/schema-model.php',
            'app/services/schema-service.php',
        ];
        $composedLoader = new ModuleLoader(
            $tempRoot,
            $composedDefinitions,
            $composedOrder,
        );
        $composedLoader->load('schema-service');
        runtime_kernel_assert(
            array_slice($GLOBALS[$globalKey], -3) === [
                'schema-core',
                'schema-model',
                'schema-service',
            ],
            'Composed modules must include the selected union in canonical global order.',
        );
        runtime_kernel_assert(
            $composedLoader->loadedModules() === [
                'schema-core',
                'schema-model',
                'schema-service',
            ],
            'Composed loader must preserve dependency-first logical module history.',
        );
        $GLOBALS[$globalKey] = ['base', 'feature'];

        runtime_kernel_write_fixture(
            $tempRoot,
            'app/schema-independent-a.php',
            '<?php $GLOBALS['
                . var_export($globalKey, true)
                . "][] = 'independent-a';\n",
        );
        runtime_kernel_write_fixture(
            $tempRoot,
            'app/schema-independent-b.php',
            '<?php $GLOBALS['
                . var_export($globalKey, true)
                . "][] = 'independent-b';\n",
        );
        runtime_kernel_write_fixture(
            $tempRoot,
            'app/schema-independent-root.php',
            '<?php $GLOBALS['
                . var_export($globalKey, true)
                . "][] = 'independent-root';\n",
        );
        $independentDefinitions = [
            'branch-a' => [
                'depends' => [],
                'files' => ['app/schema-independent-a.php'],
            ],
            'branch-b' => [
                'depends' => [],
                'files' => ['app/schema-independent-b.php'],
            ],
            'branch-root' => [
                'depends' => ['branch-b', 'branch-a'],
                'files' => ['app/schema-independent-root.php'],
            ],
        ];
        $independentLoader = new ModuleLoader(
            $tempRoot,
            $independentDefinitions,
            [
                'app/schema-independent-a.php',
                'app/schema-independent-b.php',
                'app/schema-independent-root.php',
            ],
        );
        $independentLoader->load('branch-root');
        runtime_kernel_assert(
            array_slice($GLOBALS[$globalKey], -3) === [
                'independent-a',
                'independent-b',
                'independent-root',
            ],
            'Composed independent files must follow canonical global order.',
        );
        runtime_kernel_assert(
            $independentLoader->loadedModules() === [
                'branch-b',
                'branch-a',
                'branch-root',
            ],
            'Completed module history must retain deterministic dependency-first DFS order.',
        );
        $GLOBALS[$globalKey] = ['base', 'feature'];

        runtime_kernel_write_fixture(
            $tempRoot,
            'app/schema-throwing-root.php',
            '<?php throw new RuntimeException("fixture include failure");'
                . "\n",
        );
        $throwingLoader = new ModuleLoader(
            $tempRoot,
            [
                'throwing-base' => [
                    'depends' => [],
                    'files' => ['app/base.php'],
                ],
                'throwing-root' => [
                    'depends' => ['throwing-base'],
                    'files' => ['app/schema-throwing-root.php'],
                ],
            ],
            ['app/base.php', 'app/schema-throwing-root.php'],
        );
        runtime_kernel_expect_exception(
            static fn(): mixed => $throwingLoader->load('throwing-root'),
            RuntimeException::class,
            'A throwing composed include should propagate its failure.',
        );
        runtime_kernel_assert(
            $throwingLoader->loadedModules() === ['throwing-base'],
            'A failed root include must preserve completed dependencies without claiming the incomplete root.',
        );

        $beforeInvalidPlan = $GLOBALS[$globalKey];
        runtime_kernel_expect_exception(
            static fn(): ModuleLoader => new ModuleLoader(
                $tempRoot,
                $composedDefinitions,
                [
                    'app/schema-core.php',
                    'app/schema-core.php',
                    'app/services/schema-service.php',
                ],
            ),
            RuntimeException::class,
            'Composed loader must reject duplicate or incomplete global order before include.',
        );
        runtime_kernel_assert(
            $GLOBALS[$globalKey] === $beforeInvalidPlan,
            'Invalid global order included a feature file.',
        );
        runtime_kernel_expect_exception(
            static fn(): ModuleLoader => new ModuleLoader(
                $tempRoot,
                [
                    'reachable' => [
                        'depends' => [],
                        'files' => ['app/schema-core.php'],
                    ],
                    'unreachable' => ['depends' => ['absent'], 'files' => []],
                ],
                ['app/schema-core.php'],
            ),
            RuntimeException::class,
            'Composed loader must validate unselected graph nodes before any include.',
        );
        runtime_kernel_assert(
            $GLOBALS[$globalKey] === $beforeInvalidPlan,
            'Invalid unselected graph included a feature file.',
        );
        $lazyDefinitions = [
            'reachable' => [
                'depends' => [],
                'files' => ['app/schema-core.php'],
            ],
            'unreachable' => ['depends' => [], 'files' => ['app/missing.php']],
        ];
        $lazyLoader = new ModuleLoader(
            $tempRoot,
            $lazyDefinitions,
            ['app/missing.php', 'app/schema-core.php'],
        );
        $lazyLoader->load('reachable');
        runtime_kernel_assert(
            $GLOBALS[$globalKey] === $beforeInvalidPlan,
            'An unreachable missing path altered include state.',
        );
        runtime_kernel_expect_exception(
            static fn(): mixed => $lazyLoader->load('unreachable'),
            RuntimeException::class,
            'A selected missing path must fail before the closure begins including files.',
        );
        runtime_kernel_assert(
            $GLOBALS[$globalKey] === $beforeInvalidPlan,
            'Selected missing path included another module first.',
        );

        $secondLoader = new ModuleLoader($tempRoot, $definitions);
        runtime_kernel_assert(
            $secondLoader->loadedModules() === [],
            'A new ModuleLoader instance inherited another loader’s state.',
        );
        $secondLoader->load('base');
        runtime_kernel_assert(
            $secondLoader->loadedModules() === ['base'],
            'A second loader should maintain its own logical loaded-module state.',
        );

        runtime_kernel_expect_exception(
            static fn(): mixed => $loader->load('unknown'),
            RuntimeException::class,
            'Unknown modules should fail.',
        );
        runtime_kernel_assert(
            $GLOBALS[$globalKey] === ['base', 'feature'],
            'Unknown-module validation changed the include-time side effects.',
        );

        $cycleLoader = new ModuleLoader($tempRoot, [
            'cycle-a' => [
                'depends' => ['cycle-b'],
                'files' => ['app/partial.php'],
            ],
            'cycle-b' => ['depends' => ['cycle-a'], 'files' => []],
        ]);
        runtime_kernel_expect_exception(
            static fn(): mixed => $cycleLoader->load('cycle-a'),
            RuntimeException::class,
            'Circular module dependencies should fail.',
        );
        runtime_kernel_assert(
            $GLOBALS[$globalKey] === ['base', 'feature'],
            'Cycle validation included a file before rejecting the graph.',
        );

        $missingLoader = new ModuleLoader($tempRoot, [
            'partial' => ['depends' => [], 'files' => ['app/partial.php']],
            'missing' => [
                'depends' => ['partial'],
                'files' => ['app/missing.php'],
            ],
        ]);
        runtime_kernel_expect_exception(
            static fn(): mixed => $missingLoader->load('missing'),
            RuntimeException::class,
            'Missing entrypoints should fail.',
        );
        runtime_kernel_assert(
            $GLOBALS[$globalKey] === ['base', 'feature'],
            'Missing-file validation included a valid dependency first.',
        );

        $escapedLoader = new ModuleLoader($tempRoot, [
            'partial' => ['depends' => [], 'files' => ['app/partial.php']],
            'escaped' => [
                'depends' => ['partial'],
                'files' => ['app/../escape.php'],
            ],
        ]);
        runtime_kernel_expect_exception(
            static fn(): mixed => $escapedLoader->load('escaped'),
            RuntimeException::class,
            'Escaped entrypoints should fail.',
        );
        runtime_kernel_assert(
            $GLOBALS[$globalKey] === ['base', 'feature'],
            'Path validation included a dependency before rejecting an escaped path.',
        );

        // Shared paths must execute once while include-local variables retain
        // the historical scope across adjacent canonical includes.
        runtime_kernel_write_fixture($tempRoot, 'app/loader-shared.php',
            '<?php $loaderScopeSentinel = "shared"; $GLOBALS['
                . var_export($globalKey, true) . '][] = "loader-shared";');
        runtime_kernel_write_fixture($tempRoot, 'app/loader-tail.php',
            '<?php $GLOBALS[' . var_export($globalKey, true)
                . '][] = $loaderScopeSentinel ?? "scope-lost";');
        $sharedDefinitions = [
            'shared-first' => ['depends' => [], 'files' => ['app/loader-shared.php']],
            'shared-second' => [
                'depends' => ['shared-first'],
                'files' => ['app/loader-shared.php', 'app/loader-tail.php'],
            ],
            'shared-empty' => ['depends' => ['shared-second'], 'files' => []],
        ];
        $sharedOrder = ['app/loader-shared.php', 'app/loader-tail.php'];
        $sharedLoader = new ModuleLoader($tempRoot, $sharedDefinitions, $sharedOrder);
        $sharedLoader->load('shared-empty');
        $sharedLoader->load('shared-second');
        runtime_kernel_assert(
            array_slice($GLOBALS[$globalKey], -2) === ['loader-shared', 'shared']
                && $sharedLoader->loadedModules() === [
                    'shared-first', 'shared-second', 'shared-empty',
                ],
            'Shared paths, empty modules, repeat loads and include scope must remain stable.',
        );
        require_once $root . '/app/diagnostics/admin_test_run_early.php';
        $GLOBALS['gallery_admin_test_run_early'] = [
            'enabled' => true,
            'adopted' => false,
            'request_time_unix' => microtime(true),
            'phases' => [],
            'open_phases' => [],
            'marks' => [],
        ];
        try {
            $sharedLoader->load('shared-empty');
            runtime_kernel_assert(
                array_column($GLOBALS['gallery_admin_test_run_early']['phases'], 'name')
                    === ['runtime_loader.plan'],
                'A fully loaded closure must record preflight without another canonical execute pass.',
            );
            $traceFailureLoader = new ModuleLoader($tempRoot, [
                'trace-missing' => ['depends' => [], 'files' => ['app/trace-missing.php']],
            ], ['app/trace-missing.php']);
            runtime_kernel_expect_exception(
                static fn(): mixed => $traceFailureLoader->load('trace-missing'),
                RuntimeException::class,
                'Traced preflight failures must propagate unchanged.',
            );
            $tracePhases = $GLOBALS['gallery_admin_test_run_early']['phases'];
            $traceFailure = $tracePhases[array_key_last($tracePhases)];
            runtime_kernel_assert(
                $traceFailure['name'] === 'runtime_loader.plan'
                    && $traceFailure['completed'] === false
                    && $traceFailure['included_files_delta'] === 0
                    && $traceFailure['error_or_warning']['type'] === RuntimeException::class,
                'Failed planning must retain failure diagnostics without including selected code.',
            );
        } finally {
            unset($GLOBALS['gallery_admin_test_run_early']);
        }

        // A path verified by an earlier load must not become a reusable
        // validation receipt for an unloaded module after a filesystem change.
        runtime_kernel_write_fixture($tempRoot, 'app/loader-replaced.php', '<?php');
        $replacementDefinitions = [
            'replacement-first' => ['depends' => [], 'files' => ['app/loader-replaced.php']],
            'replacement-second' => ['depends' => [], 'files' => ['app/loader-replaced.php']],
        ];
        foreach ([null, ['app/loader-replaced.php']] as $replacementOrder) {
            $replacementLoader = new ModuleLoader($tempRoot, $replacementDefinitions, $replacementOrder);
            $replacementLoader->load('replacement-first');
            unlink($tempRoot . '/app/loader-replaced.php');
            clearstatcache(true, $tempRoot . '/app/loader-replaced.php');
            runtime_kernel_expect_exception(
                static fn(): mixed => $replacementLoader->load('replacement-second'),
                RuntimeException::class,
                'Later loads must reject a removed shared file in both loader modes.',
            );
            runtime_kernel_assert(
                $replacementLoader->loadedModules() === ['replacement-first'],
                'A failed later path check must not claim the unloaded module.',
            );
            runtime_kernel_write_fixture($tempRoot, 'app/loader-replaced.php', '<?php');
        }

        // Constructor validation covers the whole graph, including unselected
        // cycles and malformed paths; no optimization may defer these refusals.
        foreach ([
            [
                'cycle-a' => ['depends' => ['cycle-b'], 'files' => []],
                'cycle-b' => ['depends' => ['cycle-a'], 'files' => []],
            ],
            ['invalid' => ['depends' => [], 'files' => ['app/../escape.php']]],
        ] as $invalidDefinitions) {
            runtime_kernel_expect_exception(
                static fn(): ModuleLoader => new ModuleLoader($tempRoot, $invalidDefinitions, []),
                RuntimeException::class,
                'Whole-plan cycle and path validation must run before includes.',
            );
        }
        runtime_kernel_expect_exception(
            static fn(): ModuleLoader => new ModuleLoader(
                $tempRoot, $sharedDefinitions, array_reverse($sharedOrder),
            ),
            RuntimeException::class,
            'A complete but noncanonical plan must be rejected.',
        );

        // Canonical execution can finish the second DFS branch before the
        // first one. A failure in that gap must expose no completed prefix.
        runtime_kernel_write_fixture($tempRoot, 'app/loader-a-ready.php', '<?php');
        runtime_kernel_write_fixture($tempRoot, 'app/loader-m-failure.php',
            '<?php throw new RuntimeException("ordered gap failure");');
        runtime_kernel_write_fixture($tempRoot, 'app/loader-z-pending.php', '<?php');
        $gapLoader = new ModuleLoader($tempRoot, [
            'gap-first' => ['depends' => [], 'files' => ['app/loader-z-pending.php']],
            'gap-second' => ['depends' => [], 'files' => ['app/loader-a-ready.php']],
            'gap-root' => [
                'depends' => ['gap-first', 'gap-second'],
                'files' => ['app/loader-m-failure.php'],
            ],
        ], [
            'app/loader-a-ready.php', 'app/loader-m-failure.php',
            'app/loader-z-pending.php',
        ]);
        runtime_kernel_expect_exception(
            static fn(): mixed => $gapLoader->load('gap-root'),
            RuntimeException::class,
            'The canonical-order gap failure must propagate.',
        );
        runtime_kernel_assert($gapLoader->loadedModules() === [],
            'A completed later branch must not jump over an incomplete DFS prefix.');

        // Linux hosted owners exercise symlink confinement. Windows may deny
        // link creation without Developer Mode; lexical escape checks still run.
        if (@symlink($tempRoot . '/escape.php', $tempRoot . '/app/loader-link.php')) {
            $linkLoader = new ModuleLoader($tempRoot, [
                'link-base' => ['depends' => [], 'files' => ['app/base.php']],
                'link-root' => [
                    'depends' => ['link-base'],
                    'files' => ['app/loader-link.php'],
                ],
            ], ['app/base.php', 'app/loader-link.php']);
            $beforeLinkFailure = $GLOBALS[$globalKey];
            runtime_kernel_expect_exception(
                static fn(): mixed => $linkLoader->load('link-root'),
                RuntimeException::class,
                'A symlink outside app must fail before any selected include.',
            );
            runtime_kernel_assert($GLOBALS[$globalKey] === $beforeLinkFailure,
                'Escaped symlink validation must preserve include-time state.');
        }

        $kernelLoader = new ModuleLoader($tempRoot, [
            'kernel-fixture' => ['depends' => [], 'files' => []],
        ]);
        $kernel = new Kernel(
            new Router(new RouteRegistry([$known], $fallback)),
            $kernelLoader,
        );
        $request = Request::fromRoute([
            'page' => 'gallery',
            'params' => ['gallery_page' => 2],
        ]);
        runtime_kernel_assert(
            $request->routeData() === [
                'page' => 'gallery',
                'params' => ['gallery_page' => 2],
            ],
            'Request should preserve the legacy route shape.',
        );
        runtime_kernel_assert(
            $kernel->resolve($request) === $known,
            'Kernel should resolve the known procedural route.',
        );
        $kernel->dispatch($known);
        runtime_kernel_assert(
            ($GLOBALS['runtime_kernel_test_handler_calls'] ?? 0) === 1,
            'Kernel did not dispatch the existing procedural handler.',
        );

        $missingHandler = new RouteDefinition(
            'missing_handler',
            'Gallery\\Controllers\\runtime_kernel_missing_handler',
            ['kernel-fixture'],
        );
        runtime_kernel_expect_exception(
            static fn(): mixed => $kernel->dispatch($missingHandler),
            RuntimeException::class,
            'Missing procedural handlers should fail explicitly after their route modules load.',
        );

        $unknownRequest = new Request('unknown_page');
        $fallbackRoute = $kernel->resolve($unknownRequest);
        runtime_kernel_assert(
            $fallbackRoute === $fallback,
            'Kernel should resolve unknown routes to the fallback definition.',
        );
        $kernel->dispatch($fallbackRoute);
        runtime_kernel_assert(
            ($GLOBALS['runtime_kernel_test_fallback_calls'] ?? 0) === 1,
            'Kernel did not dispatch the unknown-route fallback.',
        );
    } finally {
        unset($GLOBALS[$globalKey]);
        unset(
            $GLOBALS['runtime_kernel_test_handler_calls'],
            $GLOBALS['runtime_kernel_test_fallback_calls'],
        );
        runtime_kernel_remove_tree($tempRoot);
    }

    fwrite(STDOUT, "Runtime kernel contracts passed.\n");
}
