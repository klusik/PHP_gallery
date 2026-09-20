<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/gallery_workflow_ci_trigger_policy_test.php
 * Module Type: Regression Test
 * Purpose: Exercise the actual CI bootstrap with inert database and environment adapters.
 * Responsibilities:
 *   - Refuse account changes before explicit disposable-service ownership checks
 *   - Require a schema-local migration grant without trigger or server-global authority
 *   - Keep root credentials and privileged bootstrap access out of the audit child
 * Author: Rudolf Klusal
 * Contact: https://github.com/klusik
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace GalleryWorkflowCiPolicyFixture {
    /**
     * Read only the in-memory case environment, never the machine environment.
     *
     * @param ?string $name Optional environment variable name.
     * @return array<string,string>|string|false Complete fixture environment, selected value or confirmed absence.
     */
    function getenv(?string $name = null): array|string|false
    {
        $environment = $GLOBALS['gallery_workflow_ci_policy_state']['environment'];
        return $name === null ? $environment : ($environment[$name] ?? false);
    }

    /**
     * Set or remove only an in-memory fixture environment entry.
     *
     * @param string $assignment Variable assignment, or name to remove.
     * @return bool Always true for this explicit fixture adapter.
     */
    function putenv(string $assignment): bool
    {
        $parts = explode('=', $assignment, 2);
        if (count($parts) === 1) {
            unset($GLOBALS['gallery_workflow_ci_policy_state']['environment'][$parts[0]]);
        } else {
            $GLOBALS['gallery_workflow_ci_policy_state']['environment'][$parts[0]] = $parts[1];
        }
        return true;
    }

    /**
     * Suppress the readiness retry delay in the inert connection fixture.
     *
     * @param int $microseconds Original bootstrap retry interval.
     * @return void
     */
    function usleep(int $microseconds): void
    {
        \GalleryWorkflow\check($microseconds === 500000, 'Unexpected CI retry interval.');
    }
}

namespace {
    require_once __DIR__ . '/support/gallery_workflow_safety.php';

    use function GalleryWorkflow\check;

    /** In-memory query result; no PDO statement is prepared against a server. */
    final class GalleryWorkflowCiPolicyStatement extends PDOStatement
    {
        /**
         * Retain one configured ownership result.
         *
         * @param string|false $value Service hostname or simulated unavailable observation.
         * @return void
         */
        public function __construct(private string|false $value)
        {
        }

        /**
         * Return the configured scalar ownership result.
         *
         * @param int $column Requested scalar column.
         * @return string|false Configured hostname; no server query is issued.
         */
        public function fetchColumn(int $column = 0): string|false
        {
            return $this->value;
        }
    }

    /** Inert root-bootstrap adapter; constructing it never opens a connection. */
    final class GalleryWorkflowCiPolicyConnection extends PDO
    {
        /**
         * Record a validated loopback CI connection attempt without connecting.
         *
         * @param string $dsn Bootstrap-generated loopback DSN.
         * @param ?string $username Expected CI bootstrap principal.
         * @param ?string $password In-memory fixture bootstrap credential.
         * @param array<int,int>|null $options PDO attribute/value map, normally exception error mode.
         * @return void
         */
        public function __construct(string $dsn, ?string $username = null, ?string $password = null, ?array $options = null)
        {
            $state = &$GLOBALS['gallery_workflow_ci_policy_state'];
            $state['connections']++;
            check($dsn === 'mysql:host=127.0.0.1;port=13316' && $username === 'root'
                && $password === 'fixture-root-only', 'Bootstrap connection escaped fixture expectations.');
        }

        /**
         * Observe only the disposable service identity through local fixture data.
         *
         * @param string $query Actual bootstrap identity statement.
         * @param ?int $fetchMode Optional requested PDO mode.
         * @param int|string|array<array-key,mixed> ...$fetchModeArgs Optional PDO fetch configuration; unused by the identity query.
         * @source-contract-opaque-param fetchModeArgs Unused PDO-compatible variadic values are neither inspected nor forwarded by this identity fixture.
         * @return PDOStatement|false In-memory result; never queries a server.
         */
        public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
        {
            $state = &$GLOBALS['gallery_workflow_ci_policy_state'];
            $state['queries'][] = $query;
            check($query === 'SELECT @@hostname', 'CI bootstrap inspected server-global policy or unexpected metadata.');
            return new GalleryWorkflowCiPolicyStatement($state['hostname']);
        }

        /**
         * Record only restricted account creation and the exact schema-local grant.
         *
         * @param string $statement Actual bootstrap statement.
         * @return int|false Zero affected rows for successful in-memory operations.
         */
        public function exec(string $statement): int|false
        {
            $state = &$GLOBALS['gallery_workflow_ci_policy_state'];
            if (str_starts_with($statement, "CREATE USER 'gallery_workflow_runner'@'%' IDENTIFIED BY ")) {
                $state['account_writes']++;
                return 0;
            }
            $grant = 'GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, DROP, INDEX, REFERENCES ON '
                . chr(96) . 'gallery\\_workflow\\_%' . chr(96) . ".* TO 'gallery_workflow_runner'@'%'";
            check($statement === $grant, 'Bootstrap widened the runner grant or changed unexpected server state.');
            $state['account_writes']++;
            return 0;
        }

        /**
         * Quote only the generated hexadecimal fixture password.
         *
         * @param string $string Generated runner password.
         * @param int $type PDO string parameter type.
         * @return string|false Locally quoted hexadecimal value.
         */
        public function quote(string $string, int $type = PDO::PARAM_STR): string|false
        {
            check(preg_match('/^[a-f0-9]{48}$/D', $string) === 1, 'Unexpected CI generated credential format.');
            return "'" . $string . "'";
        }
    }

    $source = (string) file_get_contents(dirname(__DIR__) . '/scripts/gallery_workflow_ci.php');
    $start = strpos($source, "    check(getenv('GITHUB_ACTIONS')");
    $end = strpos($source, "    \$argv = [__FILE__, '--audit'];");
    check($start !== false && $end !== false && $end > $start, 'CI bootstrap source boundary is missing.');
    check(str_contains($source, "putenv('GALLERY_WORKFLOW_CI_ROOT_PASSWORD');")
        && str_contains($source, 'BLOCKED gallery workflow disposable CI provisioning')
        && !str_contains($source, 'getMessage()'), 'CI bootstrap leaked its root credential or raw diagnostics.');
    check(!str_contains($source, '@@GLOBAL') && !str_contains($source, 'SET GLOBAL')
        && preg_match('/GRANT[^\r\n]*(?:ALL PRIVILEGES|SUPER|TRIGGER)/i', $source) !== 1,
        'CI bootstrap retained a server-global or trigger privilege assumption.');
    $body = substr($source, $start, $end - $start);
    $bootstrap = eval('namespace GalleryWorkflowCiPolicyFixture;'
        . ' use \GalleryWorkflowCiPolicyConnection as PDO; use \Throwable;'
        . ' use function \GalleryWorkflow\check; use function \GalleryWorkflow\databaseOptions;'
        . ' return static function (): void {' . $body . '};');
    check($bootstrap instanceof Closure, 'Actual CI bootstrap did not compile with fixture adapters.');

    $environment = [
        'GITHUB_ACTIONS' => 'true', 'GALLERY_WORKFLOW_ENABLE' => 'disposable-only',
        'GALLERY_WORKFLOW_DB_HOST' => '127.0.0.1', 'GALLERY_WORKFLOW_DB_PORT' => '13316',
        'GALLERY_WORKFLOW_DB_USER' => 'gallery_workflow_runner',
        'GALLERY_WORKFLOW_CI_ROOT_PASSWORD' => 'fixture-root-only',
    ];
    $cases = [
        'restricted-account' => ['pass' => true],
        'wrong-service' => ['hostname' => 'not-the-ci-service'],
        'outside-ci' => ['environment' => ['GITHUB_ACTIONS' => 'false'], 'connections' => 0, 'queries' => 0],
        'no-opt-in' => ['environment' => ['GALLERY_WORKFLOW_ENABLE' => ''], 'connections' => 0, 'queries' => 0],
        'host-alias' => ['environment' => ['GALLERY_WORKFLOW_DB_HOST' => 'localhost'], 'connections' => 0, 'queries' => 0],
        'default-port' => ['environment' => ['GALLERY_WORKFLOW_DB_PORT' => '3306'], 'connections' => 0, 'queries' => 0],
        'root-as-runner' => ['environment' => ['GALLERY_WORKFLOW_DB_USER' => 'root'], 'connections' => 0, 'queries' => 0],
        'custom-dsn' => ['environment' => ['GALLERY_WORKFLOW_DSN' => 'fixture-forbidden'], 'connections' => 0, 'queries' => 0],
        'missing-bootstrap-credential' => ['environment' => ['GALLERY_WORKFLOW_CI_ROOT_PASSWORD' => ''], 'connections' => 0, 'queries' => 0],
    ];
    foreach ($cases as $label => $case) {
        $GLOBALS['gallery_workflow_ci_policy_state'] = array_replace([
            'hostname' => 'gallery-workflow-ci',
        ], $case, [
            'environment' => array_replace($environment, $case['environment'] ?? []),
            'connections' => 0, 'queries' => [], 'account_writes' => 0,
        ]);
        $passed = false;
        try {
            $bootstrap();
            $passed = true;
        } catch (Throwable) {
            // The real entry point emits one fixed BLOCKED message. Fixture
            // errors and generated credentials are never written to test output.
        }
        $state = $GLOBALS['gallery_workflow_ci_policy_state'];
        check($passed === ($case['pass'] ?? false), 'CI policy outcome mismatch: ' . $label);
        check($state['connections'] === ($case['connections'] ?? 1), 'CI connection guard failed: ' . $label);
        check(count($state['queries']) === ($case['queries'] ?? 1), 'CI identity query order changed: ' . $label);
        check($state['account_writes'] === ($passed ? 2 : 0), 'Account changed before disposable-service ownership proof: ' . $label);
        if ($passed) {
            check(!isset($state['environment']['GALLERY_WORKFLOW_CI_ROOT_PASSWORD'])
                && $state['environment']['GALLERY_WORKFLOW_REQUIRED'] === '1', 'CI child retained root authority or optionalized its audit.');
        }
    }
    echo 'PASS CI schema-local migration account: ' . count($cases) . " inert ownership and authority cases; no database or CI execution\n";
}
