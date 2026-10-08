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
            $grant = 'GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, CREATE TEMPORARY TABLES, ALTER, DROP, INDEX, REFERENCES ON '
                . chr(96) . 'gallery\\_workflow\\_%' . chr(96) . ".* TO 'gallery_workflow_runner'@'%'";
            check($statement === $grant, 'Bootstrap changed the schema-local runner grant or added unapproved privileges.');
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
        'PHP_GALLERY_BROWSER' => 'disabled', 'GALLERY_WORKFLOW_BROWSER' => 'disabled',
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
                && $state['environment']['GALLERY_WORKFLOW_REQUIRED'] === '1'
                && $state['environment']['PHP_GALLERY_BROWSER'] === 'disabled'
                && $state['environment']['GALLERY_WORKFLOW_BROWSER'] === 'disabled',
                'CI child retained root authority, lost explicit browser disablement or optionalized database/HTTP coverage.');
        }
    }
    $workflow = str_replace("\r\n", "\n", (string) file_get_contents(dirname(__DIR__) . '/.github/workflows/gallery-workflows.yml'));
    $workflowHeader = strstr($workflow, "\njobs:", true);
    check(is_string($workflowHeader) && !str_contains($workflowHeader, 'PHP_GALLERY_BROWSER: disabled')
        && !str_contains($workflowHeader, 'GALLERY_WORKFLOW_BROWSER: disabled'),
        'Workflow-global browser disablement would invalidate required Chromium coverage.');
    check(str_contains($workflowHeader, 'workflow_call:')
        && str_contains($workflowHeader, 'audit_profile:')
        && str_contains($workflowHeader, 'checkout_ref:')
        && str_contains($workflowHeader, 'source_base:')
        && str_contains($workflowHeader, 'default: full'),
        'The central CI workflow must remain reusable with full as its safe default profile and explicit release SHA/base inputs.');
    $jobBlocks = [];
    preg_match_all('/^  ([a-z][a-z0-9-]+):\s*\n(.*?)(?=^  [a-z][a-z0-9-]+:\s*\n|\z)/ms', $workflow, $jobMatches, PREG_SET_ORDER);
    foreach ($jobMatches as $jobMatch) {
        $jobBlocks[$jobMatch[1]] = $jobMatch[2];
    }
    foreach (['real-database-browser', 'runtime-compatibility'] as $jobName) {
        $job = $jobBlocks[$jobName] ?? '';
        check(preg_match('/^    env:\s*\n(?:(?:      #[^\n]*|      [^\n]+)\n)*?      PHP_GALLERY_BROWSER: disabled\s*\n      GALLERY_WORKFLOW_BROWSER: disabled/m', $job) === 1,
            'Database/runtime jobs must explicitly disable redundant Chromium: ' . $jobName);
        check(!str_contains($job, '--suite=browser-map'), 'Browser fixtures must not repeat across a runtime matrix.');
    }
    $browserJob = $jobBlocks['browser-tests'] ?? '';
    check(str_contains($browserJob, "PHP_GALLERY_BROWSER_REQUIRED: '1'")
        && !str_contains($browserJob, 'PHP_GALLERY_BROWSER: disabled')
        && !str_contains($browserJob, 'matrix:')
        && str_contains($browserJob, "php-version: '8.3'")
        && str_contains($browserJob, "node-version: '22'"),
        'Exactly one stable browser job must require Chromium independently of the runtime matrix.');
    check(str_contains($browserJob, 'command -v "$candidate"')
        && str_contains($browserJob, '"$browser_path" --version')
        && str_contains($browserJob, 'PHP_GALLERY_BROWSER=%s')
        && str_contains($browserJob, 'exit 1')
        && !str_contains($browserJob, 'continue-on-error:')
        && !str_contains($browserJob, '--no-report')
        && substr_count($workflow, 'php scripts/audit.php --suite=browser-map') === 1,
        'Required Chromium discovery must fail on absence and execute the central browser registry once.');
    check(str_contains($workflow, 'php scripts/gallery_workflow_ci.php')
        && str_contains($workflow, 'php scripts/audit.php --profile=full')
        && !str_contains($browserJob, '--no-sandbox')
        && !str_contains($browserJob, 'npm install'),
        'Browser CI must preserve full database/source coverage, sandboxing and the existing dependency-free fixtures.');

    $releaseAuditJob = $jobBlocks['release-audit'] ?? '';
    check(str_contains($releaseAuditJob, "inputs.audit_profile == 'release'")
        && str_contains($releaseAuditJob, 'php scripts/audit.php --profile=release')
        && str_contains($releaseAuditJob, 'PHP_GALLERY_BROWSER: disabled')
        && str_contains($releaseAuditJob, 'GALLERY_WORKFLOW_BROWSER: disabled')
        && str_contains($releaseAuditJob, 'authoritative-release-audit')
        && str_contains($releaseAuditJob, 'retention-days: 30'),
        'Reusable release qualification must own exactly one explicit release-profile audit and retain its evidence.');

    $releaseWorkflow = str_replace("\r\n", "\n", (string) file_get_contents(dirname(__DIR__) . '/.github/workflows/release-qualification.yml'));
    check(str_contains($releaseWorkflow, "      - 'release/v_*'")
        && str_contains($releaseWorkflow, 'workflow_dispatch:')
        && str_contains($releaseWorkflow, 'name: Prepare release candidate')
        && str_contains($releaseWorkflow, 'contents: write')
        && str_contains($releaseWorkflow, 'prepare_release_candidate.php')
        && str_contains($releaseWorkflow, 'patch_notes_ai.php prompt')
        && str_contains($releaseWorkflow, 'patch_notes_ai.php apply')
        && str_contains($releaseWorkflow, 'COPILOT_GITHUB_TOKEN')
        && str_contains($releaseWorkflow, 'gpt-6-luna')
        && str_contains($releaseWorkflow, 'GITHUB_COPILOT_PROMPT_MODE_EXTENSIONS')
        && str_contains($releaseWorkflow, '--no-ask-user')
        && str_contains($releaseWorkflow, 'actions/cache/restore@v6.1.0')
        && str_contains($releaseWorkflow, 'actions/cache/save@v6.1.0')
        && str_contains($releaseWorkflow, '.github/texlive-lock.json')
        && str_contains($releaseWorkflow, '.github/texlive-packages.txt')
        && str_contains($releaseWorkflow, 'provision_tinytex.sh install')
        && str_contains($releaseWorkflow, 'timeout --kill-after=15s 300')
        && !str_contains($releaseWorkflow, 'sudo apt-get install')
        && !str_contains($releaseWorkflow, 'texlive-latex-base')
        && !str_contains($releaseWorkflow, '--allow-all')
        && !str_contains($releaseWorkflow, '--allow-tool')
        && str_contains($releaseWorkflow, 'Release branch changed during preparation; refusing stale write-back.')
        && str_contains($releaseWorkflow, 'uses: ./.github/workflows/gallery-workflows.yml')
        && str_contains($releaseWorkflow, 'audit_profile: release')
        && str_contains($releaseWorkflow, 'checkout_ref:')
        && str_contains($releaseWorkflow, 'source_base:')
        && str_contains($releaseWorkflow, 'name: Release qualification gate')
        && !str_contains($releaseWorkflow, 'refs/heads/main')
        && !str_contains($releaseWorkflow, 'gh release create')
        && !str_contains($releaseWorkflow, 'git tag '),
        'Release workflow must write back only deterministic preparation to its release branch, preserve the editorial gate, reuse central CI and avoid main/tag/publication actions.');

    $preflightPosition = strpos($releaseWorkflow, 'php scripts/audit.php --profile=release-preflight');
    $preparationPosition = strpos($releaseWorkflow, 'name: Apply deterministic release preparation');
    $buildPosition = strpos($releaseWorkflow, 'name: Build all maintained manuals');
    $savePosition = strpos($releaseWorkflow, 'name: Save TinyTeX cache');
    check($preflightPosition !== false && $preparationPosition !== false && $preflightPosition < $preparationPosition
        && str_contains($releaseWorkflow, 'PHP_GALLERY_SOURCE_BASE: ${{ steps.release.outputs.source_base }}'),
        'Cheap blocking source checks must run through the central audit before preparation, AI and TeX.');
    check($buildPosition !== false && $savePosition !== false && $buildPosition < $savePosition
        && str_contains($releaseWorkflow, "steps.manuals.outcome == 'success'")
        && str_contains($releaseWorkflow, "steps.tinytex-cache.outputs.cache-hit != 'true'")
        && str_contains($releaseWorkflow, 'steps.tinytex-cache.outputs.cache-primary-key')
        && str_contains($releaseWorkflow, "hashFiles('.github/texlive-lock.json', '.github/texlive-packages.txt', '.github/scripts/provision_tinytex.sh')")
        && !str_contains($releaseWorkflow, 'restore-keys:')
        && str_contains($releaseWorkflow, 'SOURCE_DATE_EPOCH')
        && str_contains($releaseWorkflow, 'FORCE_SOURCE_DATE=1')
        && str_contains($releaseWorkflow, 'echo "SOURCE_DATE_EPOCH=${SOURCE_DATE_EPOCH}" >> "${GITHUB_ENV}"')
        && substr_count($releaseWorkflow, 'if ! php scripts/generate_manifest.php --check; then') === 2
        && str_contains($releaseWorkflow, 'PHP_Gallery_Manual PHP_Gallery_Manual_CZ PHP_Gallery_Manual_DE PHP_Gallery_Manual_SV'),
        'TinyTeX must use an exact input-bound cache, save only after all manuals succeed and retain reproducible PDF builds.');
    check(str_contains($releaseWorkflow, "git rev-parse 'HEAD^{tree}'")
        && str_contains($releaseWorkflow, 'Prepared tree already exists at current release head')
        && str_contains($releaseWorkflow, 'Release branch changed after preparation; this qualification is stale.'),
        'Reruns may reuse only identical prepared trees and the final gate must refuse a changed branch head.');

    $texLock = json_decode((string) file_get_contents(dirname(__DIR__) . '/.github/texlive-lock.json'), true, 512, JSON_THROW_ON_ERROR);
    $texHelper = (string) file_get_contents(dirname(__DIR__) . '/.github/scripts/provision_tinytex.sh');
    check(($texLock['schema_version'] ?? null) === 1 && ($texLock['texlive_year'] ?? null) === 2025
        && ($texLock['infra_revision'] ?? null) === 76780
        && ($texLock['bundle_version'] ?? null) === '2026.02'
        && str_ends_with((string) ($texLock['repository'] ?? ''), '/2025/tlnet-final')
        && preg_match('/^[a-f0-9]{64}$/D', (string) ($texLock['bundle_sha256'] ?? '')) === 1
        && preg_match('/^[a-f0-9]{64}$/D', (string) ($texLock['repository_metadata_sha256'] ?? '')) === 1,
        'Manual toolchain lock must identify compatible bundle/infra and immutable final repository checksums.');
    check(str_contains($texHelper, 'sha256sum --check --strict')
        && str_contains($texHelper, '--connect-timeout 20 --max-time 180')
        && str_contains($texHelper, '--retry-max-time 240')
        && str_contains($texHelper, '--repository "${repository}" update --all')
        && str_contains($texHelper, '--repository "${repository}" install')
        && str_contains($texHelper, '.php-gallery-provenance')
        && str_contains($texHelper, 'Locked tlmgr revision mismatch.')
        && !str_contains($texHelper, 'update --self')
        && !str_contains($texHelper, 'tlnet.yihui.org')
        && !str_contains($texHelper, 'install-bin-unix.sh'),
        'TinyTeX must verify locked downloads, bound network work and refuse live/self-update fallbacks.');
    $texPackages = str_replace("\r\n", "\n", (string) file_get_contents(dirname(__DIR__) . '/.github/texlive-packages.txt'));
    foreach (['english', 'czech', 'german', 'swedish'] as $language) {
        check(str_contains($texPackages, 'babel-' . $language . "\n")
            && str_contains($texPackages, 'hyphen-' . $language . "\n"),
            'Every manual language must explicitly own its Babel and hyphenation packages.');
    }
    check(str_contains($texHelper, 'english.ldf czech.ldf ngerman.ldf swedish.ldf'),
        'Cache activation must verify resources for all four manual languages.');
    $verifyStart = strpos($texHelper, '    verify)');
    $verifyEnd = strpos($texHelper, '    *)', $verifyStart === false ? 0 : $verifyStart);
    check($verifyStart !== false && $verifyEnd !== false
        && !str_contains(substr($texHelper, $verifyStart, $verifyEnd - $verifyStart), 'curl ')
        && !str_contains(substr($texHelper, $verifyStart, $verifyEnd - $verifyStart), 'tlmgr" install'),
        'A TinyTeX cache hit must verify local provenance and resources without provisioning.');

    $patchNotesAi = (string) file_get_contents(dirname(__DIR__) . '/.github/scripts/patch_notes_ai.php');
    check(str_contains($releaseWorkflow, 'name: Upload rejected AI release-note response')
        && str_contains($releaseWorkflow, "failure() && steps.notes-before.outputs.complete == 'false'")
        && str_contains($releaseWorkflow, 'name: release-notes-rejected-response')
        && str_contains($releaseWorkflow, 'path: ${{ runner.temp }}/release-notes-response.md'),
        'Rejected AI prose must remain available as a failure-only artifact without uploading prompts or authentication diagnostics.');
    check(preg_match('/name: Build complete release-note evidence prompt\n(?:(?!      - name:).)*timeout-minutes: 2/s', $releaseWorkflow) === 1,
        'Local release evidence collection must have its own two-minute workflow deadline.');
    check(str_contains($patchNotesAi, 'EVIDENCE - TEXT DIFF:')
        && str_contains($patchNotesAi, 'untrusted quoted repository data')
        && str_contains($patchNotesAi, "':(exclude)docs/*.pdf'")
        && str_contains($patchNotesAi, "':(exclude)winapp/dist/**'")
        && str_contains($patchNotesAi, 'Generated release notes contain more than one version section.')
        && str_contains($patchNotesAi, 'Completed release notes already exist; preserving maintainer-authored content.')
        && !str_contains($patchNotesAi, 'COPILOT_GITHUB_TOKEN'),
        'AI patch-note boundary must provide bounded evidence, reject unsafe output and remain independent of authentication secrets.');

    $runner = (string) file_get_contents(dirname(__DIR__) . '/scripts/gallery_workflow_run.php');
    foreach (['gallery_workflow_run.php', 'gallery_workflow_mysql.php'] as $launcherName) {
        $launcher = (string) file_get_contents(dirname(__DIR__) . '/scripts/' . $launcherName);
        $quickBranch = strpos($launcher, "if ((\$argv[1] ?? '') === '--quick')");
        $supportLoad = strpos($launcher, "require_once __DIR__ . '/../tests/support/gallery_workflow_fixture.php'");
        check($quickBranch !== false && $supportLoad !== false && $quickBranch < $supportLoad
            && str_contains(substr($launcher, $quickBranch, $supportLoad - $quickBranch), "'--profile=quick'")
            && str_contains(substr($launcher, $quickBranch, $supportLoad - $quickBranch), "require __DIR__ . '/audit.php'"),
            'Quick workflow convenience must delegate before disposable provisioning.');
    }
    $selectionStart = strpos($runner, '$requiredTests =');
    $selectionEnd = strpos($runner, 'foreach ($requiredTests as $test)', $selectionStart);
    check($selectionStart !== false && $selectionEnd !== false, 'Mandatory central workflow evidence selector missing.');
    $selectionBody = substr($runner, $selectionStart, $selectionEnd - $selectionStart);
    $selection = eval('namespace GalleryWorkflowCiPolicyFixture; return static function (string $profile): array {' . $selectionBody . 'return $requiredTests;};');
    check($selection instanceof Closure, 'Actual central evidence selector did not compile.');
    $GLOBALS['gallery_workflow_ci_policy_state']['environment']['GALLERY_WORKFLOW_BROWSER'] = 'disabled';
    $requiredDatabaseTests = ['gallery_workflow_integration_test.php', 'gallery_image_move_crash_test.php', 'viewer_phase07_mysql_concurrency_test.php'];
    check($selection('full') === ['database_engine_contract_test.php', ...$requiredDatabaseTests],
        'Explicit browser disablement changed database/HTTP or race PASS requirements.');
    check($selection('quick') === ['database_engine_contract_test.php'],
        'Quick workflow qualification must retain its database-engine contract without requiring full-only races.');
    $GLOBALS['gallery_workflow_ci_policy_state']['environment']['GALLERY_WORKFLOW_BROWSER'] = '';
    check($selection('full') === ['database_engine_contract_test.php', ...$requiredDatabaseTests, 'gallery_workflow_browser_test.php'],
        'Local defaults no longer require browser PASS in the workflow runner.');
    echo 'PASS CI schema-local migration account and browser-only opt-out: ' . count($cases) . " inert ownership and authority cases; no database or CI execution\n";
}
