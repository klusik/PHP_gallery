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
        $GLOBALS['runtime_kernel_test_handler_calls'] = (int) ($GLOBALS['runtime_kernel_test_handler_calls'] ?? 0) + 1;
    }

    /**
     * Record fallback-handler invocation for unknown-route coverage.
     *
     * @return void Stores one fallback marker in the test process.
     */
    function runtime_kernel_test_fallback(): void
    {
        $GLOBALS['runtime_kernel_test_fallback_calls'] = (int) ($GLOBALS['runtime_kernel_test_fallback_calls'] ?? 0) + 1;
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
        $base = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'php-gallery-runtime-kernel-' . bin2hex(random_bytes(8));
        if (!mkdir($base, 0777, true) || !mkdir($base . DIRECTORY_SEPARATOR . 'app')) {
            throw new RuntimeException('Could not create runtime-kernel fixture root.');
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
    function runtime_kernel_write_fixture(string $root, string $relativePath, string $source): void
    {
        $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException('Could not create runtime-kernel fixture directory.');
        }
        if (file_put_contents($path, $source) === false) {
            throw new RuntimeException('Could not write runtime-kernel fixture.');
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
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
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
    function runtime_kernel_expect_exception(callable $operation, string $exceptionClass, string $message): void
    {
        try {
            $operation();
        } catch (Throwable $exception) {
            runtime_kernel_assert($exception instanceof $exceptionClass, $message . ' (wrong exception: ' . get_class($exception) . ')');
            return;
        }
        throw new RuntimeException($message . ' (no exception was thrown)');
    }

    runtime_kernel_assert(class_exists(Request::class), 'Runtime autoloader did not load a class from Gallery\\Core.');

    $foreignAutoloadCalled = false;
    $foreignAutoloader = static function (string $class) use (&$foreignAutoloadCalled): void {
        $foreignAutoloadCalled = true;
    };
    spl_autoload_register($foreignAutoloader);
    try {
        runtime_kernel_assert(!class_exists('Example\\ForeignRuntimeKernelProbe', true), 'Unexpected foreign class resolved.');
        runtime_kernel_assert($foreignAutoloadCalled, 'The Gallery autoloader should ignore a foreign namespace and allow later autoloaders to run.');
    } finally {
        spl_autoload_unregister($foreignAutoloader);
    }

    runtime_kernel_assert(!class_exists('Gallery\\Core\\RuntimeKernelMissingProbe', true), 'A missing runtime class unexpectedly resolved.');
    runtime_kernel_assert(!class_exists('Gallery\\Core\\..\\OutsideRuntimeKernelProbe', true), 'Runtime autoloader accepted a traversal class name.');

    $validDefinition = new RouteDefinition('gallery', 'Gallery\\Controllers\\not_loaded_yet', ['public-gallery']);
    runtime_kernel_assert(!is_callable($validDefinition->handler), 'RouteDefinition unexpectedly requires a handler to be loaded during registration.');
    runtime_kernel_expect_exception(
        static fn (): RouteDefinition => new RouteDefinition('Bad-Name', 'Gallery\\Controllers\\handler', ['public-gallery']),
        InvalidArgumentException::class,
        'Invalid route syntax should be rejected.'
    );
    runtime_kernel_expect_exception(
        static fn (): RouteDefinition => new RouteDefinition('gallery', 'Example\\Controllers\\handler', ['public-gallery']),
        InvalidArgumentException::class,
        'Foreign handler namespace should be rejected.'
    );
    runtime_kernel_expect_exception(
        static fn (): RouteDefinition => new RouteDefinition('gallery', 'Gallery\\Controllers\\handler', ['public-gallery', 'public-gallery']),
        InvalidArgumentException::class,
        'Duplicate route modules should be rejected.'
    );
    runtime_kernel_expect_exception(
        static fn (): RouteDefinition => new RouteDefinition('gallery', 'Gallery\\Controllers\\handler', ['../controllers']),
        InvalidArgumentException::class,
        'Invalid route module identifiers should be rejected.'
    );

    $fallback = new RouteDefinition('not_found', 'Gallery\\Controllers\\runtime_kernel_test_fallback', ['kernel-fixture']);
    $known = new RouteDefinition('gallery', 'Gallery\\Controllers\\runtime_kernel_test_handler', ['kernel-fixture']);
    $registry = new RouteRegistry([$known], $fallback);
    runtime_kernel_assert($registry->find('gallery') === $known, 'RouteRegistry did not retain the known definition.');
    runtime_kernel_assert($registry->find('absent') === null, 'RouteRegistry find should return null for an unknown route.');
    runtime_kernel_assert($registry->resolve('absent') === $fallback, 'Unknown routes should resolve to the configured fallback.');
    runtime_kernel_expect_exception(
        static fn (): RouteRegistry => new RouteRegistry([$known, $known], $fallback),
        InvalidArgumentException::class,
        'Duplicate route names should be rejected.'
    );

    $tempRoot = runtime_kernel_temp_root();
    $globalKey = 'runtime_kernel_fixture_' . bin2hex(random_bytes(8));
    $GLOBALS[$globalKey] = [];
    try {
        runtime_kernel_write_fixture($tempRoot, 'app/base.php', '<?php $GLOBALS[' . var_export($globalKey, true) . "][] = 'base';\n");
        runtime_kernel_write_fixture($tempRoot, 'app/feature.php', '<?php $GLOBALS[' . var_export($globalKey, true) . "][] = 'feature';\n");
        runtime_kernel_write_fixture($tempRoot, 'app/partial.php', '<?php $GLOBALS[' . var_export($globalKey, true) . "][] = 'partial';\n");
        runtime_kernel_write_fixture($tempRoot, 'escape.php', '<?php $GLOBALS[' . var_export($globalKey, true) . "][] = 'escape';\n");

        $definitions = [
            'base' => ['depends' => [], 'files' => ['app/base.php']],
            'feature' => ['depends' => ['base'], 'files' => ['app/feature.php']],
            'empty' => ['depends' => [], 'files' => []],
        ];
        $loader = new ModuleLoader($tempRoot, $definitions);
        $loader->load('feature');
        $loader->load('base');
        $loader->load('feature');
        runtime_kernel_assert($GLOBALS[$globalKey] === ['base', 'feature'], 'Module dependencies should load in order and only once per loader.');
        runtime_kernel_assert($loader->loadedModules() === ['base', 'feature'], 'Loaded module history should reflect dependency-first order without duplicates.');

        $secondLoader = new ModuleLoader($tempRoot, $definitions);
        runtime_kernel_assert($secondLoader->loadedModules() === [], 'A new ModuleLoader instance inherited another loader’s state.');
        $secondLoader->load('base');
        runtime_kernel_assert($secondLoader->loadedModules() === ['base'], 'A second loader should maintain its own logical loaded-module state.');

        runtime_kernel_expect_exception(
            static fn (): mixed => $loader->load('unknown'),
            RuntimeException::class,
            'Unknown modules should fail.'
        );
        runtime_kernel_assert($GLOBALS[$globalKey] === ['base', 'feature'], 'Unknown-module validation changed the include-time side effects.');

        $cycleLoader = new ModuleLoader($tempRoot, [
            'cycle-a' => ['depends' => ['cycle-b'], 'files' => ['app/partial.php']],
            'cycle-b' => ['depends' => ['cycle-a'], 'files' => []],
        ]);
        runtime_kernel_expect_exception(
            static fn (): mixed => $cycleLoader->load('cycle-a'),
            RuntimeException::class,
            'Circular module dependencies should fail.'
        );
        runtime_kernel_assert($GLOBALS[$globalKey] === ['base', 'feature'], 'Cycle validation included a file before rejecting the graph.');

        $missingLoader = new ModuleLoader($tempRoot, [
            'partial' => ['depends' => [], 'files' => ['app/partial.php']],
            'missing' => ['depends' => ['partial'], 'files' => ['app/missing.php']],
        ]);
        runtime_kernel_expect_exception(
            static fn (): mixed => $missingLoader->load('missing'),
            RuntimeException::class,
            'Missing entrypoints should fail.'
        );
        runtime_kernel_assert($GLOBALS[$globalKey] === ['base', 'feature'], 'Missing-file validation included a valid dependency first.');

        $escapedLoader = new ModuleLoader($tempRoot, [
            'partial' => ['depends' => [], 'files' => ['app/partial.php']],
            'escaped' => ['depends' => ['partial'], 'files' => ['app/../escape.php']],
        ]);
        runtime_kernel_expect_exception(
            static fn (): mixed => $escapedLoader->load('escaped'),
            RuntimeException::class,
            'Escaped entrypoints should fail.'
        );
        runtime_kernel_assert($GLOBALS[$globalKey] === ['base', 'feature'], 'Path validation included a dependency before rejecting an escaped path.');

        $kernelLoader = new ModuleLoader($tempRoot, ['kernel-fixture' => ['depends' => [], 'files' => []]]);
        $kernel = new Kernel(new Router(new RouteRegistry([$known], $fallback)), $kernelLoader);
        $request = Request::fromRoute(['page' => 'gallery', 'params' => ['gallery_page' => 2]]);
        runtime_kernel_assert($request->routeData() === ['page' => 'gallery', 'params' => ['gallery_page' => 2]], 'Request should preserve the legacy route shape.');
        runtime_kernel_assert($kernel->resolve($request) === $known, 'Kernel should resolve the known procedural route.');
        $kernel->dispatch($known);
        runtime_kernel_assert(($GLOBALS['runtime_kernel_test_handler_calls'] ?? 0) === 1, 'Kernel did not dispatch the existing procedural handler.');

        $missingHandler = new RouteDefinition('missing_handler', 'Gallery\\Controllers\\runtime_kernel_missing_handler', ['kernel-fixture']);
        runtime_kernel_expect_exception(
            static fn (): mixed => $kernel->dispatch($missingHandler),
            RuntimeException::class,
            'Missing procedural handlers should fail explicitly after their route modules load.'
        );

        $unknownRequest = new Request('unknown_page');
        $fallbackRoute = $kernel->resolve($unknownRequest);
        runtime_kernel_assert($fallbackRoute === $fallback, 'Kernel should resolve unknown routes to the fallback definition.');
        $kernel->dispatch($fallbackRoute);
        runtime_kernel_assert(($GLOBALS['runtime_kernel_test_fallback_calls'] ?? 0) === 1, 'Kernel did not dispatch the unknown-route fallback.');
    } finally {
        unset($GLOBALS[$globalKey]);
        unset($GLOBALS['runtime_kernel_test_handler_calls'], $GLOBALS['runtime_kernel_test_fallback_calls']);
        runtime_kernel_remove_tree($tempRoot);
    }

    fwrite(STDOUT, "Runtime kernel contracts passed.\n");
}
