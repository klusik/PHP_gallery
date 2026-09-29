<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/admin_setup_wizard_model_test.php
 * Module Type: Test Script
 *
 * Purpose:
 *   Verifies the real Setup Wizard model transaction state machine with a fake PDO.
 *
 * Responsibilities:
 *   - Check stable row-lock ordering and successful commit
 *   - Check begin, operation, commit, and rollback failure behavior
 *   - Check compensation sequencing without a live database
 *
 * Author:
 *   Rudolf Klusal
 *
 * Contact:
 *   https://github.com/klusik
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 */

declare(strict_types=1);

namespace Gallery\Tests\AdminSetupWizardModel {
    use RuntimeException;

    /**
     * Records the row-lock keys executed by the real model.
     */
    final class FakeStatement
    {
        /** @var list<string> Bound setting keys recorded by the statement fixture. */
        public array $executed = [];

        /**
         * Record the setting keys bound to the row-lock statement.
         *
         * @param list<string> $values Bound lock keys.
         * @return bool Always successful.
         */
        public function execute(array $values): bool
        {
            $this->executed = $values;
            return true;
        }

        /**
         * Return the empty locked-row fixture.
         *
         * @return array<int,mixed> Empty locked-row fixture.
         */
        public function fetchAll(): array
        {
            return [];
        }
    }

    /**
     * Models PDO transaction outcomes without opening a database.
     */
    final class FakePdo
    {
        /** @var bool Configured result returned when a transaction begins. */
        public bool $beginResult = true;

        /** @var bool Configured result returned when a transaction commits. */
        public bool $commitResult = true;

        /** @var bool Configured result returned when a transaction rolls back. */
        public bool $rollbackResult = true;

        /** @var bool Whether the fake currently has an active transaction. */
        public bool $transaction = false;

        /** @var int Number of calls made to beginTransaction(). */
        public int $beginCalls = 0;

        /** @var int Number of calls made to commit(). */
        public int $commitCalls = 0;

        /** @var int Number of calls made to rollBack(). */
        public int $rollbackCalls = 0;

        /** @var string Last row-lock query prepared by the model. */
        public string $preparedSql = '';

        /** @var FakeStatement Current statement fixture returned by prepare(). */
        public FakeStatement $statement;

        /**
         * Initialize the reusable statement fixture.
         *
         * @return void
         */
        public function __construct()
        {
            $this->statement = new FakeStatement();
        }

        /**
         * Report whether the fake transaction is active.
         *
         * @return bool Whether a transaction is active.
         */
        public function inTransaction(): bool
        {
            return $this->transaction;
        }

        /**
         * Start a fake transaction using the configured outcome.
         *
         * @return bool Configured begin result.
         */
        public function beginTransaction(): bool
        {
            $this->beginCalls++;
            $this->transaction = $this->beginResult;
            return $this->beginResult;
        }

        /**
         * Commit a fake transaction using the configured outcome.
         *
         * @return bool Configured commit result.
         */
        public function commit(): bool
        {
            $this->commitCalls++;
            if ($this->commitResult) {
                $this->transaction = false;
            }
            return $this->commitResult;
        }

        /**
         * Roll back a fake transaction using the configured outcome.
         *
         * @return bool Configured rollback result.
         */
        public function rollBack(): bool
        {
            $this->rollbackCalls++;
            if ($this->rollbackResult) {
                $this->transaction = false;
            }
            return $this->rollbackResult;
        }

        /**
         * Record SQL prepared by the real model and return a statement fake.
         *
         * @param string $sql Prepared SQL.
         * @return FakeStatement Statement fixture.
         */
        public function prepare(string $sql): FakeStatement
        {
            $this->preparedSql = $sql;
            $this->statement = new FakeStatement();
            return $this->statement;
        }
    }

    /**
     * Assert one model transaction behavior.
     *
     * @param bool $condition Evaluated condition.
     * @param string $message Failure message.
     * @return void
     */
    function assert_true(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    /**
     * Select the fake returned by the database bootstrap stub.
     *
     * @param FakePdo $pdo Active fake.
     * @return void
     */
    function use_pdo(FakePdo $pdo): void
    {
        $GLOBALS['wizard_model_pdo'] = $pdo;
    }
}

namespace Gallery\Core {
    /**
     * Return the active model transaction fake.
     *
     * @return FakePdo Active model transaction fake.
     */
    function db(): object
    {
        return $GLOBALS['wizard_model_pdo'];
    }
}

namespace Gallery\Models {
    require_once __DIR__ . '/../app/models/admin_setup_wizard.php';
}

namespace Gallery\Tests\AdminSetupWizardModel {
    use RuntimeException;
    use function Gallery\Models\admin_setup_wizard_model_transaction;

    $pdo = new FakePdo();
    use_pdo($pdo);
    /**
     * Return a stable value from successful transaction work.
     *
     * @return string Successful operation value.
     */
    $successfulOperation = static fn (): string => 'done';
    $result = admin_setup_wizard_model_transaction(
        ['zeta', 'alpha', 'zeta', ''],
        $successfulOperation
    );
    assert_true($result === 'done', 'Successful transaction lost its result.');
    assert_true($pdo->beginCalls === 1 && $pdo->commitCalls === 1 && $pdo->rollbackCalls === 0, 'Successful transaction lifecycle is incorrect.');
    assert_true($pdo->statement->executed === ['alpha', 'zeta'], 'Lock keys were not unique and sorted.');
    assert_true(str_contains($pdo->preparedSql, 'FOR UPDATE'), 'Model did not issue a row lock.');

    $pdo = new FakePdo();
    $pdo->beginResult = false;
    use_pdo($pdo);
    $operationCalled = false;
    $compensationCalled = false;
    /**
     * Record work that must stay unreachable after begin failure.
     *
     * @return void
     */
    $beginOperation = static function () use (&$operationCalled): void {
        $operationCalled = true;
    };
    /**
     * Record compensation that must stay unreachable after begin failure.
     *
     * @return void
     */
    $beginCompensate = static function () use (&$compensationCalled): void {
        $compensationCalled = true;
    };
    try {
        admin_setup_wizard_model_transaction([], $beginOperation, $beginCompensate);
        throw new RuntimeException('Failed begin was accepted.');
    } catch (RuntimeException $exception) {
        assert_true(str_contains($exception->getMessage(), 'could not start'), 'Unexpected begin failure.');
    }
    assert_true(!$operationCalled && !$compensationCalled, 'Failed begin executed work or compensation.');

    $pdo = new FakePdo();
    use_pdo($pdo);
    $compensationCalled = false;
    /**
     * Fail transaction work after begin succeeds.
     *
     * @return void
     */
    $failingOperation = static function (): void {
        throw new RuntimeException('operation failed');
    };
    /**
     * Record compensation after transaction work fails.
     *
     * @return void
     */
    $operationCompensate = static function () use (&$compensationCalled): void {
        $compensationCalled = true;
    };
    try {
        admin_setup_wizard_model_transaction([], $failingOperation, $operationCompensate);
        throw new RuntimeException('Operation failure was accepted.');
    } catch (RuntimeException $exception) {
        assert_true($exception->getMessage() === 'operation failed', 'Operation failure identity was not retained.');
    }
    assert_true($pdo->rollbackCalls === 1 && $compensationCalled, 'Operation failure did not roll back and compensate.');

    $pdo = new FakePdo();
    $pdo->commitResult = false;
    use_pdo($pdo);
    $configChanged = false;
    $restored = false;
    /**
     * Record a configuration change before commit failure.
     *
     * @return void
     */
    $commitOperation = static function () use (&$configChanged): void {
        $configChanged = true;
    };
    /**
     * Record compensation only after configuration changed.
     *
     * @return void
     */
    $commitCompensate = static function () use (&$configChanged, &$restored): void {
        if ($configChanged) {
            $restored = true;
        }
    };
    try {
        admin_setup_wizard_model_transaction([], $commitOperation, $commitCompensate);
        throw new RuntimeException('Failed commit was accepted.');
    } catch (RuntimeException $exception) {
        assert_true(str_contains($exception->getMessage(), 'could not commit'), 'Unexpected commit failure.');
    }
    assert_true($pdo->rollbackCalls === 1 && $restored, 'Failed commit did not roll back and compensate changed config.');

    $pdo = new FakePdo();
    $pdo->rollbackResult = false;
    use_pdo($pdo);
    $compensationCalled = false;
    /**
     * Fail transaction work before the configured rollback failure.
     *
     * @return void
     */
    $rollbackOperation = static function (): void {
        throw new RuntimeException('operation failed');
    };
    /**
     * Record compensation even when database rollback fails.
     *
     * @return void
     */
    $rollbackCompensate = static function () use (&$compensationCalled): void {
        $compensationCalled = true;
    };
    try {
        admin_setup_wizard_model_transaction([], $rollbackOperation, $rollbackCompensate);
        throw new RuntimeException('Failed rollback was accepted.');
    } catch (RuntimeException $exception) {
        assert_true(str_contains($exception->getMessage(), 'could not roll back'), 'Rollback failure was not bounded.');
    }
    assert_true($compensationCalled, 'Rollback failure skipped file compensation.');

    echo "admin_setup_wizard_model_test: PASS\n";
}
