<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/app_settings_atomic_activation_model_test.php
 * Module Type: Regression Test
 * Purpose: Verify settings activation callbacks run inside the model-owned transaction.
 * Responsibilities: Exercise commit, callback, upsert, and foreign-transaction failures with a disconnected PDO double.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Core {
    /** Return the disconnected settings connection owned by this test.
     * @return \PDO Recording PDO double used by the production model.
     */
    function db(): \PDO
    {
        return $GLOBALS['atomic_settings_connection'];
    }

    /** Supply a deterministic timestamp for the real application-settings service.
     * @return string Stable fixture SQL timestamp.
     */
    function now_sql(): string
    {
        return '2030-01-01 00:00:00';
    }
}

namespace {
    use PDO;
    use PDOStatement;
    use RuntimeException;

    /** Record only the setting transaction operations used by the production model. */
    final class AtomicSettingsRecordingPdo extends PDO
    {
        /** @var array<string,string> Current transactional setting values. */
        public array $values = [];
        /** @var list<string> Ordered transaction operations. */
        public array $events = [];
        /** @var bool Whether this connection currently owns a transaction. */
        public bool $active = false;
        /** @var array<string,string>|null Snapshot restored by rollback. */
        public ?array $before = null;
        /** @var string|null One failure point injected into a disposable operation. */
        public ?string $failure = null;

        /** Construct a recording PDO without opening a database connection.
         * @return void Initializes only in-memory test state.
         */
        public function __construct() {}

        /** Report whether the model already owns a transaction.
         * @return bool Current fixture transaction state.
         */
        public function inTransaction(): bool { return $this->active; }

        /** Prepare the one setting upsert accepted by the model.
         * @param string $query SQL selected by the production persistence owner.
         * @param array<int,mixed> $options PDO options passed by the model.
         * @return PDOStatement|false Fixture statement or false for unrelated SQL.
         */
        public function prepare(string $query, array $options = []): PDOStatement|false
        {
            $this->events[] = 'prepare';
            if (!str_starts_with($query, 'INSERT INTO app_settings')) {
                return false;
            }
            return new AtomicSettingsRecordingStatement($this);
        }

        /** Snapshot settings before the model runs its upserts and callback.
         * @return bool Whether the test transaction started.
         */
        public function beginTransaction(): bool
        {
            $this->events[] = 'begin';
            if ($this->failure === 'begin') {
                return false;
            }
            $this->before = $this->values;
            $this->active = true;
            return true;
        }

        /** Commit every upsert after successful file activation.
         * @return bool Whether the fixture transaction committed.
         */
        public function commit(): bool
        {
            $this->events[] = 'commit';
            if ($this->failure === 'commit') {
                return false;
            }
            $this->before = null;
            $this->active = false;
            return true;
        }

        /** Restore every prior value after an activation or persistence failure.
         * @return bool Whether rollback completed.
         */
        public function rollBack(): bool
        {
            $this->events[] = 'rollback';
            $this->values = $this->before ?? [];
            $this->before = null;
            $this->active = false;
            return $this->failure !== 'rollback';
        }
    }

    /** Apply the actual model's prepared setting values to the recording connection. */
    final class AtomicSettingsRecordingStatement extends PDOStatement
    {
        /** Keep the prepared operation attached to its recording connection.
         * @param AtomicSettingsRecordingPdo $connection Transaction owner receiving setting rows.
         * @return void Stores the fixture connection only.
         */
        public function __construct(private AtomicSettingsRecordingPdo $connection) {}

        /** Execute one application-setting upsert selected by the production model.
         * @param array<int,mixed>|null $params Positional key, value, and timestamp from the model.
         * @return bool Whether the fixture accepted the row.
         */
        public function execute(?array $params = null): bool
        {
            if ($this->connection->failure === 'execute') {
                throw new RuntimeException('Injected setting upsert failure.');
            }
            if (!is_array($params) || count($params) !== 3 || !is_string($params[0]) || !is_string($params[1])) {
                throw new RuntimeException('The model supplied an invalid setting row.');
            }
            $this->connection->events[] = 'upsert:' . $params[0];
            $this->connection->values[$params[0]] = $params[1];
            return true;
        }
    }

    /** Require a transaction behavior that protects the file activation boundary.
     * @param bool $condition Expected observable invariant.
     * @param string $message Safe regression context.
     * @return void Throws when model-owned transaction semantics regress.
     */
    function atomic_settings_assert(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    require dirname(__DIR__) . '/app/models/app_settings.php';
    require dirname(__DIR__) . '/app/services/app_settings.php';
    $connection = new AtomicSettingsRecordingPdo();
    $connection->values = ['theme_background_path' => 'old.png', 'custom_css_revision' => 'old-css'];
    $GLOBALS['atomic_settings_connection'] = $connection;
    $newSettings = ['theme_background_path' => 'new.png', 'theme_background_source' => 'upload'];
    $callbackObservedRows = false;
    \Gallery\Models\app_settings_model_set_many_with_activation($newSettings, '2030-01-01 00:00:00', static function () use ($connection, &$callbackObservedRows): void {
        $callbackObservedRows = $connection->active
            && $connection->values['theme_background_path'] === 'new.png'
            && $connection->values['theme_background_source'] === 'upload';
        $connection->events[] = 'activate';
    });
    atomic_settings_assert($callbackObservedRows && !$connection->active && $connection->values['theme_background_path'] === 'new.png', 'file activation did not observe staged settings inside the owning transaction');
    atomic_settings_assert(array_slice($connection->events, -2) === ['activate', 'commit'], 'settings commit did not follow reversible file activation');

    foreach (['callback', 'execute', 'commit'] as $failure) {
        $connection->values = ['theme_background_path' => 'old.png', 'custom_css_revision' => 'old-css'];
        $connection->events = [];
        $connection->failure = $failure;
        $refused = false;
        try {
            \Gallery\Models\app_settings_model_set_many_with_activation($newSettings, '2030-01-01 00:00:00', static function () use ($connection, $failure): void {
                $connection->events[] = 'activate';
                if ($failure === 'callback') {
                    throw new RuntimeException('Injected reversible file activation failure.');
                }
            });
        } catch (RuntimeException) {
            $refused = true;
        }
        $connection->failure = null;
        atomic_settings_assert($refused && !$connection->active && $connection->values === ['theme_background_path' => 'old.png', 'custom_css_revision' => 'old-css'], $failure . ' failure did not roll back every setting value');
        atomic_settings_assert(end($connection->events) === 'rollback', $failure . ' failure did not close the transaction through rollback');
        atomic_settings_assert(($failure !== 'callback' || !in_array('commit', $connection->events, true)), $failure . ' failure unexpectedly committed');
    }

    $connection->values = ['theme_background_path' => 'old.png'];
    $connection->events = [];
    $connection->failure = 'rollback';
    $rollbackFailureReported = false;
    try {
        \Gallery\Models\app_settings_model_set_many_with_activation($newSettings, '2030-01-01 00:00:00', static function (): void { throw new RuntimeException('Injected activation failure before rollback.'); });
    } catch (\Gallery\Models\AppSettingsRollbackException) {
        $rollbackFailureReported = true;
    }
    $connection->failure = null;
    atomic_settings_assert($rollbackFailureReported && !$connection->active && $connection->values === ['theme_background_path' => 'old.png'] && end($connection->events) === 'rollback', 'rollback boundary failure was hidden or retained partial settings');

    $connection->values = ['theme_background_path' => 'old.png'];
    $connection->events = [];
    $connection->failure = 'begin';
    $beginRefused = false;
    try {
        \Gallery\Models\app_settings_model_set_many_with_activation($newSettings, '2030-01-01 00:00:00', static function (): void { throw new RuntimeException('Activation must not run when begin fails.'); });
    } catch (RuntimeException) {
        $beginRefused = true;
    }
    $connection->failure = null;
    atomic_settings_assert($beginRefused && !$connection->active && $connection->values === ['theme_background_path' => 'old.png'] && $connection->events === ['begin'], 'failed transaction start changed rows or entered file activation');

    $connection->active = true;
    $connection->events = [];
    $callbackCalled = false;
    $foreignTransactionRefused = false;
    try {
        \Gallery\Models\app_settings_model_set_many_with_activation($newSettings, '2030-01-01 00:00:00', static function () use (&$callbackCalled): void { $callbackCalled = true; });
    } catch (InvalidArgumentException) {
        $foreignTransactionRefused = true;
    }
    atomic_settings_assert($foreignTransactionRefused && !$callbackCalled && $connection->events === [] && $connection->active, 'model did not refuse foreign transaction ownership before writes or activation');

    $connection->active = false;
    $connection->values = ['theme_background_path' => 'old.png'];
    $connection->events = [];
    $GLOBALS['cms_app_settings_cache'] = ['theme_background_path'=>'old.png'];
    $GLOBALS['cms_app_settings_cache_loaded'] = true;
    $cacheRefusal = false;
    try {
        \Gallery\Services\set_app_settings_atomically($newSettings, static function (): void { throw new RuntimeException('Injected file activation failure.'); });
    } catch (RuntimeException) {
        $cacheRefusal = true;
    }
    atomic_settings_assert($cacheRefusal && $connection->values === ['theme_background_path'=>'old.png'] && $GLOBALS['cms_app_settings_cache'] === ['theme_background_path'=>'old.png'] && $GLOBALS['cms_app_settings_cache_loaded'] === true, 'failed activation changed persisted settings or invalidated the request cache');
    \Gallery\Services\set_app_settings_atomically($newSettings, static function (): void {});
    atomic_settings_assert($GLOBALS['cms_app_settings_cache'] === [] && $GLOBALS['cms_app_settings_cache_loaded'] === false, 'successful transaction did not invalidate request-local setting reads');

    echo "PASS application settings atomic activation model\n";
}
