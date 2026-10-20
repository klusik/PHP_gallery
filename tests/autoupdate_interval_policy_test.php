<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/autoupdate_interval_policy_test.php
 * Module Type: Regression Test
 *
 * Purpose:
 *   Exercise the persisted stable-update check cadence, due boundaries, request guards,
 *   cross-process discovery lock, and GitHub secondary rate-limit backoff without HTTP.
 *
 * Responsibilities:
 *   - Prove the 300-second default and strict 5/15/30/60-minute settings contract
 *   - Verify deterministic elapsed-time boundaries and live changes of preference
 *   - Preserve active-job priority, method safety, and single-owner discovery locking
 *   - Confirm bounded secondary GitHub API backoff without real network requests
 *
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 *
 * Notes:
 *   - All state and transport dependencies are isolated in in-memory fixtures.
 *   - Keep comments and docstrings intact when modifying this file.
 */

declare(strict_types=1);

namespace Gallery\Services {
    /** Return an isolated saved preference. @param string $key Setting key. @param string $default Fallback. @return string Saved value. */
    function app_setting(string $key, string $default = ''): string
    {
        return (string) (
            $GLOBALS['autoupdate_fixture_settings'][$key] ?? $default
        );
    }

    /** Store an isolated preference. @param string $key Setting key. @param string $value Saved value. @return void */
    function set_app_setting(string $key, string $value): void
    {
        $GLOBALS['autoupdate_fixture_settings'][$key] = $value;
    }

    /** Delete isolated preferences. @param array<int,string> $keys Keys to erase. @return void */
    function delete_app_settings(array $keys): void
    {
        foreach ($keys as $key) {
            unset($GLOBALS['autoupdate_fixture_settings'][$key]);
        }
    }

    /** Provide a disposable lock directory. @return string Confined temporary root. */
    function application_update_project_root(): string
    {
        return $GLOBALS['autoupdate_fixture_root'];
    }

    /** Return an optional fake active update job. @return array<string,mixed>|null Fake job. */
    function application_update_active_job(): ?array
    {
        return $GLOBALS['autoupdate_fixture_active_job'];
    }

    /** Observe an optional live continuation lease. @param string $jobId Active identifier. @return bool Whether this fixture grants continuation ownership to a server chain. */
    function application_update_background_chain_pending(string $jobId): bool
    {
        return (bool) ($GLOBALS['autoupdate_fixture_chain_pending'] ?? false);
    }

    /** Observe bounded job continuation. @param float $budgetSeconds Time budget. @return array<string,mixed> Fake job state. */
    function application_update_continue_background_job(float $budgetSeconds): array
    {
        $GLOBALS['autoupdate_fixture_continue_budgets'][] = $budgetSeconds;
        return [];
    }

    /** Observe an eligible automatic stable discovery. @param bool $force Force flag. @param int|null $checkedAt Reservation time. @return array<string,mixed> Fake status. */
    function application_autoupdate_run_installing_check(
        bool $force = false,
        ?int $checkedAt = null,
    ): array {
        $GLOBALS['autoupdate_fixture_install_checks'][] = [$force, $checkedAt];
        set_app_setting(
            'application_autoupdate_last_checked_at',
            (string) ($checkedAt ?? time()),
        );
        return [];
    }

    /** Observe a metadata-only beta dry check. @param bool $force Force flag. @param int|null $checkedAt Reservation time. @return array<string,mixed> Fake status. */
    function application_autoupdate_dry_run(
        bool $force = false,
        ?int $checkedAt = null,
    ): array {
        $GLOBALS['autoupdate_fixture_dry_checks'][] = [$force, $checkedAt];
        set_app_setting(
            'application_autoupdate_last_checked_at',
            (string) ($checkedAt ?? time()),
        );
        return [];
    }
}

namespace {
    require_once __DIR__ . '/../app/services/updates_request.php';
    require_once __DIR__ . '/../app/services/updates_status.php';
    require_once __DIR__ . '/../app/services/github.php';

    /** Assert one strict updater regression expectation. @param bool $ok Assertion. @param string $label Explanation. @return void */
    function autoupdate_interval_assert(bool $ok, string $label): void
    {
        if (!$ok) {
            throw new \RuntimeException($label);
        }
    }

    $GLOBALS['autoupdate_fixture_settings'] = [];
    $GLOBALS['autoupdate_fixture_active_job'] = null;
    $GLOBALS['autoupdate_fixture_install_checks'] = [];
    $GLOBALS['autoupdate_fixture_dry_checks'] = [];
    $GLOBALS['autoupdate_fixture_continue_budgets'] = [];
    $GLOBALS['autoupdate_fixture_root'] =
        sys_get_temp_dir()
        . '/gallery-update-interval-'
        . bin2hex(random_bytes(6));
    mkdir($GLOBALS['autoupdate_fixture_root'], 0700, true);
    mkdir($GLOBALS['autoupdate_fixture_root'] . '/cache', 0700, true);

    try {
        $getInterval = 'Gallery\Services\application_autoupdate_interval_seconds';
        $saveInterval = 'Gallery\Services\set_application_autoupdate_interval_seconds';
        $due = 'Gallery\Services\application_autoupdate_discovery_due';

        autoupdate_interval_assert(
            $getInterval() === 300,
            'Fresh and upgraded installations without a key must use 300 seconds.',
        );
        \Gallery\Services\set_app_setting(
            'application_autoupdate_enabled',
            '0',
        );
        foreach ([300, 900, 1800, 3600] as $seconds) {
            $saveInterval((string) $seconds);
            autoupdate_interval_assert(
                $getInterval() === $seconds,
                'An explicit valid interval must round-trip.',
            );
            autoupdate_interval_assert(
                \Gallery\Services\app_setting('application_autoupdate_enabled')
                    === '0',
                'Changing the interval must not enable updates.',
            );
            autoupdate_interval_assert(
                !$due(100000 + $seconds - 1, 100000),
                'One second before the due boundary must skip.',
            );
            autoupdate_interval_assert(
                $due(100000 + $seconds, 100000),
                'The exact due boundary must permit one check.',
            );
            autoupdate_interval_assert(
                !$due(100000 + $seconds, 100000 + $seconds),
                'A repeated check at the same instant must skip.',
            );
        }

        $saveInterval('900');
        foreach ([
            '',
            '-300',
            '0',
            '300.0',
            ' 300',
            '00300',
            '301',
            '3601',
            '1e3',
            '900 ',
            '900' . PHP_EOL,
        ] as $invalid) {
            $rejected = false;
            try {
                $saveInterval($invalid);
            } catch (\InvalidArgumentException $exception) {
                $rejected = true;
            }
            autoupdate_interval_assert(
                $rejected,
                'Invalid or coerced interval was accepted: '
                    . json_encode($invalid),
            );
            autoupdate_interval_assert(
                $getInterval() === 900,
                'A rejected interval must preserve the earlier valid setting.',
            );
        }
        \Gallery\Services\set_app_setting(
            'application_autoupdate_interval_seconds',
            'garbage',
        );
        autoupdate_interval_assert(
            $getInterval() === 300,
            'A corrupt saved interval must use the default.',
        );
        \Gallery\Services\delete_app_settings([
            'application_autoupdate_interval_seconds',
        ]);
        autoupdate_interval_assert(
            $getInterval() === 300,
            'A missing saved interval must use the default.',
        );

        $reference = 100000;
        $saveInterval('3600');
        autoupdate_interval_assert(
            !$due($reference + 600, $reference),
            'Explicit one-hour interval must hold after 10 minutes.',
        );
        $saveInterval('300');
        autoupdate_interval_assert(
            $due($reference + 600, $reference),
            'Switching from 60 to 5 minutes must immediately recognize due work.',
        );
        $saveInterval('3600');
        autoupdate_interval_assert(
            !$due($reference + 600, $reference),
            'Switching from 5 to 60 minutes must immediately defer work.',
        );

        \Gallery\Services\set_app_setting(
            'application_autoupdate_last_checked_at',
            '100',
        );
        \Gallery\Services\set_app_setting(
            'application_update_check_cached_at',
            '200',
        );
        autoupdate_interval_assert(
            \Gallery\Services\application_update_discovery_last_attempt_at()
                === 200,
            'Manual discovery timestamp must coalesce with automatic discovery.',
        );
        \Gallery\Services\set_app_setting(
            'application_autoupdate_last_checked_at',
            '300',
        );
        autoupdate_interval_assert(
            \Gallery\Services\application_update_discovery_last_attempt_at()
                === 300,
            'Newer automatic discovery timestamp must take precedence.',
        );

        $saveInterval('300');
        \Gallery\Services\set_app_setting(
            'application_autoupdate_enabled',
            '1',
        );
        \Gallery\Services\set_app_setting(
            'application_autoupdate_last_checked_at',
            (string) (time() - 100),
        );
        \Gallery\Services\set_app_setting(
            'application_update_check_cached_at',
            '0',
        );
        $loaded = [];
        $loader = static function (string $module) use (&$loaded): void {
            $loaded[] = $module;
        };
        \Gallery\Services\application_autoupdate_maybe_run(
            3600,
            'GET',
            $loader,
        );
        autoupdate_interval_assert(
            $GLOBALS['autoupdate_fixture_install_checks'] === []
                && $loaded === [],
            'Not-due requests must not load heavy modules or contact GitHub.',
        );
        \Gallery\Services\set_app_setting(
            'application_autoupdate_last_checked_at',
            (string) (time() - 400),
        );
        \Gallery\Services\application_autoupdate_maybe_run(
            3600,
            'POST',
            $loader,
        );
        autoupdate_interval_assert(
            $GLOBALS['autoupdate_fixture_install_checks'] === [],
            'POST must not discover a release.',
        );
        \Gallery\Services\application_autoupdate_maybe_run(
            3600,
            'HEAD',
            $loader,
        );
        autoupdate_interval_assert(
            count($GLOBALS['autoupdate_fixture_install_checks']) === 1
                && $loaded === ['updater-work'],
            'Due HEAD must use saved 300 seconds despite a legacy 3600-second caller argument.',
        );
        \Gallery\Services\application_autoupdate_maybe_run(
            3600,
            'GET',
            $loader,
        );
        autoupdate_interval_assert(
            count($GLOBALS['autoupdate_fixture_install_checks']) === 1,
            'Repeated same-window requests must not rediscover.',
        );
        \Gallery\Services\set_app_setting(
            'application_autoupdate_enabled',
            '0',
        );
        \Gallery\Services\set_app_setting(
            'application_autoupdate_last_checked_at',
            (string) (time() - 400),
        );
        \Gallery\Services\application_autoupdate_maybe_run(
            3600,
            'GET',
            $loader,
        );
        autoupdate_interval_assert(
            count($GLOBALS['autoupdate_fixture_install_checks']) === 1,
            'Disabled automatic updates must not discover.',
        );
        $GLOBALS['autoupdate_fixture_active_job'] = ['id' => 'existing'];
        \Gallery\Services\application_autoupdate_maybe_run(
            3600,
            'POST',
            $loader,
        );
        autoupdate_interval_assert(
            $GLOBALS['autoupdate_fixture_continue_budgets'] === [3.0],
            'Active background jobs must continue before HTTP method/checkbox guards.',
        );
        $GLOBALS['autoupdate_fixture_active_job']['trigger'] = 'background';
        $GLOBALS['autoupdate_fixture_chain_pending'] = true;
        \Gallery\Services\application_autoupdate_maybe_run(
            3600,
            'GET',
            $loader,
        );
        autoupdate_interval_assert(
            $GLOBALS['autoupdate_fixture_continue_budgets'] === [3.0],
            'An existing server-chain lease must keep ordinary visitor requests out of the heavy worker.',
        );
        $GLOBALS['autoupdate_fixture_chain_pending'] = false;
        $GLOBALS['autoupdate_fixture_active_job'] = null;
        \Gallery\Services\set_app_setting(
            'application_autoupdate_enabled',
            '1',
        );
        \Gallery\Services\set_app_setting('application_update_channel', 'beta');
        \Gallery\Services\set_app_setting(
            'application_update_beta_commit',
            'fixture',
        );
        \Gallery\Services\application_autoupdate_maybe_run(
            3600,
            'GET',
            $loader,
        );
        autoupdate_interval_assert(
            count($GLOBALS['autoupdate_fixture_dry_checks']) === 1
                && count($GLOBALS['autoupdate_fixture_install_checks']) === 1,
            'A due beta request must dry check, not start stable installation.',
        );

        $lock = \Gallery\Services\application_update_discovery_try_lock();
        autoupdate_interval_assert(
            is_resource($lock),
            'The common discovery lock must be acquirable.',
        );
        autoupdate_interval_assert(
            \Gallery\Services\application_update_discovery_try_lock() === null,
            'A second contender must not acquire the discovery lock.',
        );
        \Gallery\Services\application_update_discovery_unlock($lock);
        $lock = \Gallery\Services\application_update_discovery_try_lock();
        autoupdate_interval_assert(
            is_resource($lock),
            'An unlocked discovery slot must become available again.',
        );
        \Gallery\Services\application_update_discovery_unlock($lock);

        $url = 'https://api.github.com/repos/fixture/gallery/contents/app/bootstrap.php';
        \Gallery\Services\cms_github_api_record_response($url, 403, []);
        $pause1 = (int) \Gallery\Services\app_setting(
            'application_update_github_retry_after_until',
            '0',
        );
        autoupdate_interval_assert(
            $pause1 >= (time() + 55)
                && \Gallery\Services\cms_github_api_wait_state()['active'],
            '403 without headers must impose a nonzero delay.',
        );
        \Gallery\Services\cms_github_api_record_response($url, 429, []);
        $pause2 = (int) \Gallery\Services\app_setting(
            'application_update_github_retry_after_until',
            '0',
        );
        autoupdate_interval_assert(
            $pause2 >= (time() + 115) && $pause2 > $pause1,
            'Repeated secondary failures must increase backoff.',
        );
        \Gallery\Services\cms_github_api_record_response($url, 429, [
            'retry-after' => '600',
        ]);
        $pause3 = (int) \Gallery\Services\app_setting(
            'application_update_github_retry_after_until',
            '0',
        );
        autoupdate_interval_assert(
            $pause3 >= (time() + 595),
            'Explicit Retry-After must take precedence.',
        );
        \Gallery\Services\cms_github_api_record_response($url, 403, [
            'x-ratelimit-remaining' => '0',
            'x-ratelimit-reset' => (string) (time() + 1200),
        ]);
        autoupdate_interval_assert(
            \Gallery\Services\cms_github_api_wait_state()['active'],
            'Exhausted primary quota must preserve reset-based waiting.',
        );
        \Gallery\Services\cms_github_api_record_response($url, 200, [
            'x-ratelimit-remaining' => '42',
        ]);
        autoupdate_interval_assert(
            !\Gallery\Services\cms_github_api_wait_state()['active'],
            'A successful API response must clear stale backoff gates.',
        );
        autoupdate_interval_assert(
            \Gallery\Services\app_setting(
                'application_update_github_secondary_failures',
                '',
            ) === '',
            'Success must reset consecutive secondary failures.',
        );
    } finally {
        @unlink(
            $GLOBALS['autoupdate_fixture_root']
                . '/cache/update-discovery.lock',
        );
        @rmdir($GLOBALS['autoupdate_fixture_root'] . '/cache');
        @rmdir($GLOBALS['autoupdate_fixture_root']);
    }

    echo
        "Automatic-update interval, concurrency, and API-backoff tests passed.\n";
}
