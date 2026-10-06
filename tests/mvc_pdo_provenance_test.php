<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/mvc_pdo_provenance_test.php
 * Module Type: Architecture Regression Test
 * Purpose: Distinguish bounded PDO evidence from ordinary similarly named methods.
 * Responsibilities: Exercise independent positive and negative scope, alias and SQL cases.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/scripts/check_mvc_boundaries.php';

/**
 * Count one scanner rule without relying on other evidence in the same fixture.
 *
 * @param array<int,array<string,mixed>> $findings Scanner findings for one source.
 * @param string $rule Exact rule identity to count.
 * @return int Number of findings carrying that rule.
 */
function mvc_pdo_rule_count(array $findings, string $rule): int
{
    return count(array_filter($findings, static fn(array $finding): bool => ($finding['rule'] ?? '') === $rule));
}

// Each independent source has exactly one prepare/query/transaction call under test.
// No fixture executes a connection or SQL; the architecture scanner only reads tokens.
$fixtures = [
    'typed parameter alias' => [1, <<<'PHP'
namespace Gallery\Services;
function run(\PDO $connection, string $sql): void {
    $arbitraryRunner = $connection;
    $arbitraryRunner->prepare($sql);
}
PHP],
    'imported PDO alias' => [1, <<<'PHP'
namespace Gallery\Services;
use PDO as Connection;
function run(Connection $connection, string $sql): void {
    $connection->prepare($sql);
}
PHP],
    'direct constructor' => [1, <<<'PHP'
namespace Gallery\Services;
function run(string $sql): void {
    $connection = new \PDO('sqlite::memory:');
    $connection->prepare($sql);
}
PHP],
    'canonical db assignment' => [1, <<<'PHP'
namespace Gallery\Services;
use function Gallery\Core\db;
function run(string $sql): void {
    $connection = db();
    $connection->prepare($sql);
}
PHP],
    'canonical db direct result' => [1, <<<'PHP'
namespace Gallery\Services;
function run(string $sql): void {
    \Gallery\Core\db()->prepare($sql);
}
PHP],
    'canonical db imported alias' => [1, <<<'PHP'
namespace Gallery\Services;
use function Gallery\Core\db as connection;
function run(string $sql): void {
    connection()->prepare($sql);
}
PHP],
    'literal SQL on unknown receiver' => [1, <<<'PHP'
namespace Gallery\Services;
function run(object $connection): void {
    $connection->prepare('SELECT id FROM galleries WHERE id = ?');
}
PHP],
    'typed property' => [1, <<<'PHP'
namespace Gallery\Services;
final class Repository {
    private \PDO $connection;
    public function run(string $sql): void {
        $this->connection->prepare($sql);
    }
}
PHP],
    'promoted typed property' => [1, <<<'PHP'
namespace Gallery\Services;
final class Repository {
    public function __construct(private \PDO $connection) {}
    public function run(string $sql): void {
        $this->connection->prepare($sql);
    }
}
PHP],
    'case insensitive PDO method' => [1, <<<'PHP'
namespace Gallery\Services;
function run(\PDO $connection, string $sql): void {
    $connection->PREPARE($sql);
}
PHP],
    'top level typed construction' => [1, <<<'PHP'
namespace Gallery\Services;
$connection = new \PDO('sqlite::memory:');
$connection->prepare($dynamicSql);
PHP],
    'typed arrow receiver' => [1, <<<'PHP'
namespace Gallery\Services;
$prepare = fn(\PDO $connection, string $sql): mixed => $connection->prepare($sql);
PHP],
    'ordinary route prepare' => [0, <<<'PHP'
namespace Gallery\Services;
final class Kernel {
    public function run(object $route): callable {
        return $this->prepare($route);
    }
    private function prepare(object $route): callable {
        return $route->handler;
    }
}
PHP],
    'comment-only SQL' => [0, <<<'PHP'
namespace Gallery\Services;
function run(object $router, object $route): void {
    $router->prepare($route /* SELECT id FROM galleries */);
}
PHP],
    'same variable in separate functions' => [0, <<<'PHP'
namespace Gallery\Services;
function first(\PDO $connection): void {}
function second(object $connection, object $route): void {
    $connection->prepare($route);
}
PHP],
    'later assignment does not taint earlier call' => [0, <<<'PHP'
namespace Gallery\Services;
function run(object $connection, object $route): void {
    $connection->prepare($route);
    $connection = new \PDO('sqlite::memory:');
}
PHP],
    'reassignment removes old evidence' => [0, <<<'PHP'
namespace Gallery\Services;
function run(\PDO $connection, object $router, object $route): void {
    $connection = $router;
    $connection->prepare($route);
}
PHP],
    'nested closure local does not taint outer variable' => [0, <<<'PHP'
namespace Gallery\Services;
function run(object $connection, object $route): void {
    $worker = function (): void {
        $connection = new \PDO('sqlite::memory:');
    };
    $connection->prepare($route);
}
PHP],
    'nested named function local does not taint outer variable' => [0, <<<'PHP'
namespace Gallery\Services;
function run(object $connection, object $route): void {
    function worker(): void {
        $connection = new \PDO('sqlite::memory:');
    }
    $connection->prepare($route);
}
PHP],
    'nested arrow assignment does not taint outer variable' => [0, <<<'PHP'
namespace Gallery\Services;
function run(object $connection, object $route): void {
    $worker = fn(): object => ($connection = new \PDO('sqlite::memory:'));
    $connection->prepare($route);
}
PHP],
    'arrow parameter shadows outer PDO' => [0, <<<'PHP'
namespace Gallery\Services;
function run(\PDO $connection, object $route): void {
    $worker = fn(object $connection): mixed => $connection->prepare($route);
}
PHP],
    'arrow captures outer PDO' => [1, <<<'PHP'
namespace Gallery\Services;
function run(\PDO $connection, string $sql): void {
    $worker = fn(): mixed => $connection->prepare($sql);
}
PHP],
    'foreign PDO import' => [0, <<<'PHP'
namespace Gallery\Services;
use Vendor\PDO;
function run(PDO $connection, object $route): void {
    $connection->prepare($route);
}
PHP],
    'foreign PDO constructor' => [0, <<<'PHP'
namespace Gallery\Services;
function run(object $route): void {
    $connection = new \Vendor\PDO();
    $connection->prepare($route);
}
PHP],
    'foreign db helper' => [0, <<<'PHP'
namespace Gallery\Services;
function run(object $route): void {
    \Vendor\db()->prepare($route);
}
PHP],
    'same property name in another class' => [0, <<<'PHP'
namespace Gallery\Services;
final class Repository { private \PDO $connection; }
final class Kernel {
    private object $connection;
    public function run(object $route): void {
        $this->connection->prepare($route);
    }
}
PHP],
];

$problems = [];
foreach ($fixtures as $label => [$expected, $body]) {
    $source = "<?php\n" . $body;
    $strict = \PhpGallery\MvcBoundary\scan_source($source, 'app/services/provenance_fixture.php');
    $actual = mvc_pdo_rule_count($strict, 'services.pdo_method');
    $core = \PhpGallery\MvcBoundary\scan_source($source, 'app/helpers_provenance_fixture.php');
    $coreCount = mvc_pdo_rule_count($core, 'core.pdo_method');
    $record = \PhpGallery\MvcBoundary\scan_architecture_source($source, 'app/services/provenance_fixture.php');
    $inventoryCount = (int) ($record['signals']['pdo_method']['count'] ?? 0);
    if ($actual !== $expected || $coreCount !== $expected || $inventoryCount !== $expected) {
        $problems[] = $label . ': expected ' . $expected . ', strict ' . $actual . ', core ' . $coreCount . ', inventory ' . $inventoryCount;
    }
    if (str_starts_with($label, 'foreign ') && (mvc_pdo_rule_count($core, 'core.pdo_construction') > 0
        || mvc_pdo_rule_count($core, 'core.direct_db') > 0)) {
        $problems[] = $label . ': foreign names must not create Core PDO construction or database findings';
    }
}
if ($problems !== []) {
    fwrite(STDERR, implode("\n", $problems) . "\n");
    exit(1);
}
echo 'MVC PDO provenance fixtures passed (' . count($fixtures) . " independent cases).\n";
