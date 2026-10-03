<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/updater_metadata_budget_test.php
 * Module Type: Regression Test
 * Purpose: Count real discovery-service GitHub calls using confined transport fixtures.
 * Responsibilities: Verify branch fallback, passive notes, pending-history reuse and local reconciliation.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);
namespace Gallery\Core {
    const CMS_GITHUB_REPOSITORY = 'fixture/gallery';
    const CMS_UPDATE_BRANCHES = ['main', 'master'];
    /** Return the installed fixture release. @return string Installed release. */
    function cms_current_version(): string { return '1.0'; }
    /** Escape rendered notes. @param string $value Fixture text. @return string HTML-safe text. */
    function e(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
}
namespace Gallery\Services {
    /** Return a confined cache root. @return string Temporary fixture directory. */
    function application_update_project_root(): string { return $GLOBALS['budget_root']; }
    /** Supply no rate backoff. @return array{active:bool} Policy fixture. */
    function cms_github_api_wait_state(): array { return ['active' => false]; }
    /** Return safe diagnostics. @param \Throwable $error Fixture transport failure. @return array{message:string,reference:string} Safe failure. */
    function application_update_safe_error(\Throwable $error): array { return ['message' => 'Fixture failure', 'reference' => 'fixture']; }
    /** Return passive discovery. @param int $ttl Cache lifetime. @param bool $refresh Whether HTTP is allowed. @return array<string,mixed> Cached release metadata. */
    function cached_application_update_check(int $ttl, bool $refresh): array { return $GLOBALS['budget_status']; }
    /** Confine the production GitHub gateway to fixture responses.
     * @param string $url Trusted API URL. @param int $timeout Request budget. @param array<int,string> $headers Transport headers. @param bool $conditional Cache validation.
     * @return array{body:string} Synthetic content response.
     */
    function cms_github_api_get(string $url, int $timeout, array $headers, bool $conditional): array
    {
        $GLOBALS['budget_calls'][] = $url;
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $branch = (string) $query['ref'];
        if ($branch === 'main' && $GLOBALS['budget_main_missing']) throw new \RuntimeException('Missing fixture branch');
        $version = $GLOBALS['budget_remote_version'];
        return ['body' => str_contains($url, 'PATCH_NOTES.md') ? "## Version $version\nFresh pending notes\n## Version 1.0\nRemote stale installed notes\n" : "<?php const CMS_VERSION = '$version';"];
    }
}
namespace {
    require_once __DIR__ . '/../app/services/updates_remote.php';
    require_once __DIR__ . '/../app/services/updates_patch_notes.php';
    // The real wait predicate delegates to the gateway policy fixture.
    require_once __DIR__ . '/../app/services/updates_status.php';

    /** Require a transport-budget behavior. @param bool $value Expected behavior. @param string $message Failure description. @return void Throws on failure. */
    function metadata_budget_assert(bool $value, string $message): void { if (!$value) throw new \RuntimeException($message); }
    $GLOBALS['budget_root'] = sys_get_temp_dir() . '/gallery-metadata-' . bin2hex(random_bytes(6));
    mkdir($GLOBALS['budget_root']);
    file_put_contents($GLOBALS['budget_root'] . '/PATCH_NOTES.md', "## Version 1.0\nInstalled package notes\n");
    $GLOBALS['budget_calls'] = [];
    $GLOBALS['budget_remote_version'] = '1.0';
    $GLOBALS['budget_main_missing'] = false;
    $GLOBALS['budget_status'] = ['branch' => 'main', 'latest_version' => '2.0', 'update_available' => true];
    try {
        $status = \Gallery\Services\check_application_update(true);
        metadata_budget_assert(count($GLOBALS['budget_calls']) === 1 && $status['branch'] === 'main', 'A valid preferred marker must spend one API request and skip master.');
        $notes = \Gallery\Services\application_patch_notes_viewer_data('main', 0);
        metadata_budget_assert(count($GLOBALS['budget_calls']) === 1 && str_contains($notes['versions']['1.0']['html'], 'Installed package notes'), 'A passive page without remote notes cache must stay offline.');
        $GLOBALS['budget_remote_version'] = '2.0';
        $GLOBALS['budget_calls'] = [];
        \Gallery\Services\check_application_update(true);
        metadata_budget_assert(count($GLOBALS['budget_calls']) === 2, 'A newly discovered release may fetch bootstrap and missing release history once each.');
        $notes = \Gallery\Services\application_patch_notes_viewer_data('main');
        metadata_budget_assert(count($GLOBALS['budget_calls']) === 2 && isset($notes['versions']['2.0']) && str_contains($notes['versions']['1.0']['html'], 'Installed package notes'), 'Viewer must reuse pending history while preferring installed package notes.');
        $GLOBALS['budget_calls'] = [];
        \Gallery\Services\check_application_update(true);
        metadata_budget_assert(count($GLOBALS['budget_calls']) === 1, 'Repeated discovery must reuse already cached pending notes.');
        $GLOBALS['budget_calls'] = [];
        $GLOBALS['budget_main_missing'] = true;
        $status = \Gallery\Services\check_application_update(true);
        metadata_budget_assert($status['branch'] === 'master' && count($GLOBALS['budget_calls']) === 4, 'A genuinely missing preferred branch must retain bootstrap/notes fallback and pending history on master.');
        $GLOBALS['budget_calls'] = [];
        $installed = \Gallery\Services\application_update_installed_status('2.0', 'main');
        metadata_budget_assert($installed['current_version'] === '2.0' && !$installed['update_available'] && $GLOBALS['budget_calls'] === [], 'Finalization must clear former availability without remote discovery.');
        $rollback = \Gallery\Services\application_update_installed_status('1.0', 'main');
        metadata_budget_assert($rollback['update_available'] && $rollback['latest_version'] === '2.0', 'Rollback must preserve a known newer stable release.');
    } finally {
        foreach (glob($GLOBALS['budget_root'] . '/cache/patch-notes/*.json') ?: [] as $path) unlink($path);
        if (is_dir($GLOBALS['budget_root'] . '/cache/patch-notes')) rmdir($GLOBALS['budget_root'] . '/cache/patch-notes');
        if (is_dir($GLOBALS['budget_root'] . '/cache')) rmdir($GLOBALS['budget_root'] . '/cache');
        unlink($GLOBALS['budget_root'] . '/PATCH_NOTES.md');
        rmdir($GLOBALS['budget_root']);
    }
    echo "Updater metadata budget: PASS\n";
}
