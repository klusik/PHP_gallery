<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/cache_lifecycle_test.php
 * Module Type: Regression Test
 * Purpose: Verify cache retention and reclamation on disposable producer fixtures.
 * Responsibilities: Protect leases, pins, stale approval, changed proof, seven-day ZIPs and contextual rollback.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);
namespace Gallery\Services {
    /**
     * Enable the isolated fixture's canonical automatic cache capability.
     * @param string $key Capability identity selected by the lifecycle owner.
     * @return bool True only for the fixture's automatic cache capability.
     */
    function feature_capability_effective_enabled(string $key): bool { return $key === 'automatic_cache_cleanup'; }
    /**
     * Supply the fixture's existing beta recovery preference.
     * @param string $key Setting identity.
     * @param string|null $default Fallback preference.
     * @return string|null Empty beta reference or fallback; no database access occurs.
     */
    function app_setting(string $key, ?string $default = null): ?string
    {
        return $key === 'application_update_beta_backup_path' ? '' : $default;
    }
}
namespace {
    /**
     * Enforce one observed fixture invariant.
     * @param bool $condition Expected safety result.
     * @param string $message Failure explanation.
     * @return void Throws on failure and continues on success.
     */
    function lifecycle_assert(bool $condition, string $message): void
    {
        if (!$condition) throw new \RuntimeException($message);
    }
    /**
     * Publish producer-shaped fixture JSON below the disposable cache.
     * @param string $relative Cache-relative fixture file.
     * @param array<string,mixed> $record Native audit/ZIP/job fields validated by their policy owner.
     * @return void Creates fixture parents and writes complete JSON.
     */
    function lifecycle_json(string $relative, array $record): void
    {
        $path = CACHE_LIFECYCLE_TEST_ROOT . '/cache/' . $relative;
        if (!is_dir(dirname($path))) mkdir(dirname($path),0775,true);
        file_put_contents($path,json_encode($record,JSON_THROW_ON_ERROR));
    }
    $root = sys_get_temp_dir() . '/gallery-cache-lifecycle-' . bin2hex(random_bytes(8));
    // Confine lifecycle retention and deletion to this fixture's temporary root.
    // Type: string. Units: absolute directory path. Scope: this disposable test.
    // Consumers: lifecycle path validation. Rationale: real cache payloads must remain unreachable from regression cleanup.
    define('CACHE_LIFECYCLE_TEST_ROOT',$root);
    // Advance producer age without waiting for the seven-day ZIP boundary.
    // Type: int. Units: Unix seconds. Scope: this fixture's retention clock.
    // Consumers: lifecycle eligibility. Rationale: eight days plus one hour exercise expired and deliberately fresh proof deterministically.
    define('CACHE_LIFECYCLE_TEST_NOW',time()+8*86400+3600);
    mkdir($root . '/cache',0775,true);
    require_once __DIR__ . '/../app/services/cache_lifecycle.php';
    $held = null;
    try {
        lifecycle_assert(\Gallery\Services\cache_lifecycle_status()['phase']==='idle' && !is_dir($root.'/cache/cache-lifecycle'),'Status wrote runtime state.');
        foreach ([123,124,125] as $run) {
            $id = '20261001-000000-' . $run;
            lifecycle_json('test-audit/'.$id.'/report.json',['schema_version'=>1,'status'=>'PASS','started_at'=>'2026-10-01T00:00:00Z',
                'duration_seconds'=>1,'source'=>['head'=>str_repeat('a',40)],'report_files'=>['run_directory'=>'cache/test-audit/'.$id,
                    'json'=>'cache/test-audit/latest.json','markdown'=>'cache/test-audit/latest.md']]);
            file_put_contents($root.'/cache/test-audit/'.$id.'/report.md', "# PHP Gallery Audit\n\n- Result: **PASS**\n\n"
                . "## Report files\n\n- JSON: `cache/test-audit/latest.json`\n- Run directory: `cache/test-audit/".$id."`\n");
            file_put_contents($root.'/cache/test-audit/'.$id.'/output.log',str_repeat('x',4096));
        }
        $held = \Gallery\Services\cache_lifecycle_producer_lease('test-audit/20261001-000000-124');
        lifecycle_assert(is_resource($held),'Shared producer lease failed.');
        lifecycle_json('test-audit/20261001-000000-126/owner.json',['schema_version'=>1,'producer'=>'php_gallery_audit',
            'run_id'=>'20261001-000000-126','run_directory'=>'cache/test-audit/20261001-000000-126','started_at_unix'=>time()]);
        lifecycle_json('test-audit/20261001-000000-128/report.json',['schema_version'=>1,'status'=>'FAIL','profile'=>'full',
            'started_at'=>'2026-10-01T00:00:00Z','duration_seconds'=>1,'environment'=>[],
            'tasks'=>[['id'=>'php-regression','status'=>'PASS']], 'report_files'=>['run_directory'=>'cache/test-audit/20261001-000000-128',
                'json'=>'cache/test-audit/latest.json','markdown'=>'cache/test-audit/latest.md']]);
        file_put_contents($root.'/cache/test-audit/20261001-000000-128/report.md', "# PHP Gallery Audit\n\n- Result: **FAIL**\n\n"
            . "## Report files\n\n- JSON: `cache/test-audit/latest.json`\n- Run directory: `cache/test-audit/20261001-000000-128`\n");
        foreach ([129,130,131] as $run) {
            $relative = 'test-audit/20261001-000000-' . $run;
            lifecycle_json($relative.'/report.json',['schema_version'=>1,'status'=>'PASS','started_at'=>'2026-10-01T00:00:00Z',
                'duration_seconds'=>1,'source'=>[], 'report_files'=>['run_directory'=>'cache/'.$relative,
                    'json'=>$run === 131 ? 'cache/'.$relative.'/custom.json' : 'cache/test-audit/latest.json',
                    'markdown'=>'cache/test-audit/latest.md']]);
            if ($run !== 129) file_put_contents($root.'/cache/'.$relative.'/report.md', $run === 130
                ? "# PHP Gallery Audit\n\n- Result: **PASS**\n"
                : "# PHP Gallery Audit\n\n- Result: **PASS**\n\n## Report files\n\n- JSON: `cache/".$relative."/custom.json`\n- Run directory: `cache/".$relative."`\n");
            lifecycle_assert(!\Gallery\Services\cache_lifecycle_native_audit_finished($relative),
                'Partial or still-in-subtree audit publication qualified for deletion.');
        }
        lifecycle_json('codex-ci-unknown/unknown.json',['unknown'=>'preserve']);
        lifecycle_json('theme-background/original.json',['original'=>'preserve']);
        foreach (['map-browser-profile-Ab12Cd','panel-lifecycle-browser-Zx98Yw','release-0111-source','release-0.108-pdf-review-final'] as $workspace) {
            lifecycle_json($workspace.'/cache-owner.json',['schema_version'=>1,'producer'=>'php_gallery_browser_fixture',
                'directory'=>$workspace,'created_at'=>CACHE_LIFECYCLE_TEST_NOW-86400]);
            file_put_contents($root.'/cache/'.$workspace.'/payload.log','disposable');
            // Fresh activity must not renew a workspace's fixed lifetime.
            touch($root.'/cache/'.$workspace.'/payload.log',CACHE_LIFECYCLE_TEST_NOW);
        }
        lifecycle_json('map-browser-profile-Fr3sh1/cache-owner.json',['schema_version'=>1,'producer'=>'php_gallery_browser_fixture',
            'directory'=>'map-browser-profile-Fr3sh1','created_at'=>CACHE_LIFECYCLE_TEST_NOW-86399]);
        lifecycle_json('release-0112-source/content.json',['legacy'=>'filesystem age anchor']);
        \Gallery\Services\cache_lifecycle_workspace_observe('release-0112-source');
        $legacyAge = file_get_contents($root.'/cache/'.\Gallery\Services\cache_lifecycle_workspace_age_path('release-0112-source'));
        touch($root.'/cache/release-0112-source',CACHE_LIFECYCLE_TEST_NOW);
        \Gallery\Services\cache_lifecycle_workspace_observe('release-0112-source');
        lifecycle_assert(file_get_contents($root.'/cache/'.\Gallery\Services\cache_lifecycle_workspace_age_path('release-0112-source'))===$legacyAge,'Legacy activity renewed fixed expiry.');
        foreach (['release-preparation','release-qualification','map-browser-profile-not-a-random-suffix','release-custom-original'] as $workspace) {
            lifecycle_json($workspace.'/content.json',['persistent'=>'preserve']);
            lifecycle_assert(!\Gallery\Services\cache_lifecycle_disposable_workspace($workspace),'Unregistered workspace was admitted.');
        }
        lifecycle_json('map-browser-profile-P1nned/cache-owner.json',['schema_version'=>1,'producer'=>'php_gallery_browser_fixture',
            'directory'=>'map-browser-profile-P1nned','created_at'=>time()]);
        file_put_contents($root.'/cache/map-browser-profile-P1nned/.keep','preserve');
        $ciReport = ['schema_version'=>1,'status'=>'PASS','started_at'=>'2026-10-01T00:00:00Z','duration_seconds'=>1,'source'=>[],
            'report_files'=>['run_directory'=>'cache/test-audit/20261001-000000-127']];
        lifecycle_json('codex-ci-pinned/latest.json',$ciReport);
        lifecycle_json('cache-cleaning-ci-nested/basic-source-evidence/latest.json',$ciReport);
        file_put_contents($root.'/cache/codex-ci-pinned/.keep','incident evidence');
        lifecycle_json('github-api/'.sha1('https://api.github.com/example').'.json',
            ['url'=>'https://api.github.com/example','cached_at'=>time(),'status'=>200,'headers'=>[],'body'=>'{}']);
        $last = '20261001000000-aaaaaaaaaaaa';
        $old = '20260901000000-bbbbbbbbbbbb';
        $failed = '20260801000000-cccccccccccc';
        lifecycle_json('updates/last-job.json',['job_id'=>$last]);
        foreach ([$last=>'completed',$old=>'completed',$failed=>'failed'] as $id=>$status) {
            lifecycle_json('updates/jobs/'.$id.'/job.json',['id'=>$id,'status'=>$status,'parameters'=>[]]);
            file_put_contents($root.'/cache/updates/jobs/'.$id.'/worker.lock','');
            file_put_contents($root.'/cache/updates/jobs/'.$id.'/package.zip',str_repeat('z',128));
        }
        $zipSuffix = '/'.str_repeat('d',64).'/'.str_repeat('e',64);
        foreach ([1=>time(),2=>CACHE_LIFECYCLE_TEST_NOW-6*86400] as $resource=>$created) {
            $path = 'zips/legacy-artifacts/gallery/'.$resource.$zipSuffix;
            lifecycle_json($path.'/metadata.json',['format_version'=>1,'resource_type'=>'gallery','resource_id'=>$resource,
                'resource_revision'=>str_repeat('d',64),'build_key'=>str_repeat('e',64),'created_at'=>$created,'archive_size'=>128,'expected_file_count'=>1]);
            file_put_contents($root.'/cache/'.$path.'/archive.zip',str_repeat('z',128));
            file_put_contents($root.'/cache/'.$path.'/artifact.lock','');
        }
        $state = \Gallery\Services\cache_lifecycle_inventory_start();
        $id = $state['id'];
        lifecycle_assert(!\Gallery\Services\cache_lifecycle_inventory_slice('wrong-id')['ok'],'Stale inventory accepted.');
        file_put_contents($root.'/cache/cache-lifecycle/work/plan.jsonl',"uncommitted crash tail\n",FILE_APPEND);
        for ($step=0;$step<200 && $state['phase']==='inventory';$step++) $state = \Gallery\Services\cache_lifecycle_inventory_slice($id);
        lifecycle_assert($state['phase']==='preview' && $state['candidates']===13,'Eligibility did not preserve protected owners or recognize fixed-age workspaces and historical/nested CI proof.');
        lifecycle_assert(is_dir($root.'/cache/test-audit/20261001-000000-123'),'Inventory deleted payload.');
        lifecycle_assert(\Gallery\Services\cache_lifecycle_scheduled_slice(true)['reason']==='manual_cache_preview_pending','Cron approved a manual preview.');
        lifecycle_assert(!\Gallery\Services\cache_lifecycle_cleanup_start('wrong-id')['ok'],'Stale approval accepted.');
        $reportPath = $root.'/cache/test-audit/20261001-000000-125/report.json';
        $changed = json_decode(file_get_contents($reportPath),true);
        $changed['duration_seconds'] = 2;
        file_put_contents($reportPath,json_encode($changed,JSON_THROW_ON_ERROR));
        $state = \Gallery\Services\cache_lifecycle_cleanup_start($id);
        for ($step=0;$step<200 && $state['phase']==='cleanup';$step++) $state = \Gallery\Services\cache_lifecycle_cleanup_slice($id);
        lifecycle_assert($state['phase']==='complete' && $state['bytes_removed']>=4096 && $state['skipped']===2,'Reclamation ignored lease/changed proof or failed to finish.');
        lifecycle_assert(!is_dir($root.'/cache/updates/jobs/'.$old),'Older terminal job was not reclaimed.');
        foreach (['test-audit/20261001-000000-124','test-audit/20261001-000000-125','codex-ci-pinned','codex-ci-unknown','theme-background','updates/jobs/'.$last,'updates/jobs/'.$failed] as $path) {
            lifecycle_assert(is_dir($root.'/cache/'.$path),'Protected owner was deleted: '.$path);
        }
        lifecycle_assert(!is_file($root.'/cache/zips/legacy-artifacts/gallery/1'.$zipSuffix.'/archive.zip')
            && is_file($root.'/cache/zips/legacy-artifacts/gallery/2'.$zipSuffix.'/archive.zip'),'Seven-day ZIP retention failed.');
        foreach ([126,129,130,131] as $run) lifecycle_assert(is_dir($root.'/cache/test-audit/20261001-000000-'.$run),
            'Unfinished/partial audit without final publication was deleted.');
        lifecycle_assert(!is_dir($root.'/cache/cache-cleaning-ci-nested/basic-source-evidence'),'Expired native downloaded artifact survived.');
        foreach (['map-browser-profile-Ab12Cd','panel-lifecycle-browser-Zx98Yw','release-0111-source','release-0.108-pdf-review-final','release-0112-source'] as $workspace) {
            lifecycle_assert(!is_dir($root.'/cache/'.$workspace),'Expired disposable workspace survived: '.$workspace);
            lifecycle_assert(!is_file($root.'/cache/'.\Gallery\Services\cache_lifecycle_workspace_age_path($workspace)),'Reclaimed workspace left an age record.');
        }
        foreach (['map-browser-profile-Fr3sh1','map-browser-profile-P1nned','release-preparation','release-qualification','map-browser-profile-not-a-random-suffix','release-custom-original'] as $workspace) {
            lifecycle_assert(is_dir($root.'/cache/'.$workspace),'Fresh, pinned or unregistered workspace was deleted: '.$workspace);
        }
        echo "Cache lifecycle retention, preview, leases, pins, rollback and journal recovery: PASS\n";
    } finally {
        if (is_resource($held)) { flock($held,LOCK_UN); fclose($held); }
        // This exact random disposable root is the only fixture deletion target.
        for ($step=0;$step<200 && is_dir($root);$step++) \Gallery\Services\application_update_delete_tree_slice($root,5000,0.5);
    }
}
