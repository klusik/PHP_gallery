<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/custom_css_preservation_test.php
 * Module Type: Regression Test
 * Purpose: Preserve installed Custom CSS and global Theme image state through editor replacement, removal and failed transactions.
 * Responsibilities: Inject filesystem and settings-commit failures into actual services within an owned temporary root.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);
namespace Gallery\Core {
    /** Produce a deterministic asset URL without application configuration.
     * @param string $path Public relative asset path.
     * @return string Fixture asset URL.
     */
    function asset_url(string $path): string { return '/fixture/'.$path; }
    /** Prepare a route URL used by the real Theme background service.
     * @param string $route Canonical fixture route name.
     * @param array<string,string> $params Route parameters.
     * @return string Disposable same-origin route URL.
     */
    function url_for(string $route, array $params = []): string { return '/fixture/index.php?page='.$route.($params === [] ? '' : '&'.http_build_query($params)); }
}
namespace Gallery\Services {
    /** Read only the fixture's in-memory setting map.
     * @param string $key Requested setting.
     * @param string $default Missing-value fallback.
     * @return string Disposable stored value.
     */
    function app_setting(string $key,string $default=''): string { return $GLOBALS['css_settings'][$key] ?? $default; }
    /** Apply semantic settings and a reversible asset callback as one disposable transaction.
     * @param array<string,string> $settings Related settings proposed by the service.
     * @param callable():void $activate File activation executed before commit.
     * @return void Restores settings after ordinary failure but retains injected uncertain-commit values to model an unconfirmed rollback.
     */
    function set_app_settings_atomically(array $settings, callable $activate): void {
        $before=$GLOBALS['css_settings'];
        foreach($settings as $key=>$value) $GLOBALS['css_settings'][$key]=$value;
        try {
            $activate();
            if($GLOBALS['css_failure']==='atomic_commit') throw new \RuntimeException('Injected settings commit failure.');
            if(in_array($GLOBALS['css_failure'], ['atomic_rollback_uncertain', 'atomic_rollback_uncertain_css_restore'], true)) throw new \Gallery\Models\AppSettingsRollbackException('Injected unconfirmed settings rollback.');
        } catch(\Throwable $exception) {
            if(!str_starts_with($GLOBALS['css_failure'], 'atomic_rollback_uncertain')) $GLOBALS['css_settings']=$before;
            throw $exception;
        }
    }
    /** Keep the disposable request cache empty after a simulated successful transaction.
     * @return void Does not access application state.
     */
    function app_settings_reset_request_cache(): void {}
    /** Inject a failed marker write before changing the fixture setting map.
     * @param string $key Setting identifier.
     * @param string $value Proposed stored value.
     * @return void Records successful fixture-only writes.
     */
    function set_app_setting(string $key,string $value): void { if($GLOBALS['css_fail_setting']) { $GLOBALS['css_fail_setting']=false; throw new \RuntimeException('Fixture marker write failed.'); } $GLOBALS['css_settings'][$key]=$value; }
    /** Return a bounded disposable schema observation and validate the actual storage owner.
     * @param string $capability Stable capability identifier.
     * @param string $table Required table.
     * @param list<string> $columns Required settings columns.
     * @return array{state:string} Prepared schema observation.
     */
    function mutation_schema_table_columns_status(string $capability,string $table,array $columns): array { if($capability!=='custom_stylesheet' || $table!=='app_settings' || $columns!==['setting_key','setting_value','updated_at']) throw new \RuntimeException('Incorrect Custom CSS storage owner.'); return ['state'=>$GLOBALS['css_schema']]; }
    /** Refuse unavailable fixture storage before any recorded filesystem operation.
     * @param array{state:string} $status Prepared schema observation.
     * @param string $operation Stable mutation identifier.
     * @return void Allows only verified disposable storage.
     */
    function mutation_schema_assert_available(array $status,string $operation): void { $GLOBALS['css_events'][]='preflight:'.$operation; if($status['state']!=='available') throw new \RuntimeException('Fixture storage unavailable.'); }
    /** Record staged allocation and optionally fail it without touching installation assets.
     * @param string $directory Owned temporary destination.
     * @param string $prefix Staging filename prefix.
     * @return string|false Owned path or injected failure.
     */
    function tempnam(string $directory,string $prefix): string|false { $GLOBALS['css_events'][]='allocate'; return $GLOBALS['css_failure']==='allocate' ? false : \tempnam($directory,$prefix); }
    /** Inject source-copy or backup-copy failure into the actual staging pipeline.
     * @param string $source Owned fixture source.
     * @param string $target Owned fixture target.
     * @return bool Whether the complete fixture copy succeeded.
     */
    function copy(string $source,string $target): bool { $GLOBALS['css_events'][]='copy'; if($GLOBALS['css_failure']==='stage_copy' && str_contains($source,'/custom_css/')) return false; if($GLOBALS['css_failure']==='backup_copy' && $source===custom_css_path()) return false; return \copy($source,$target); }
    /** Inject one activation failure while leaving rollback renames operational.
     * @param string $source Owned staged path.
     * @param string $target Owned active path.
     * @return bool Whether the fixture rename succeeded.
     */
    function rename(string $source,string $target): bool
    {
        $GLOBALS['css_events'][] = 'rename';
        if ($GLOBALS['css_failure'] === 'rename_once') {
            $GLOBALS['css_failure'] = '';
            return false;
        }
        if ($GLOBALS['css_failure'] === 'background_activate' && str_contains($target, 'background-original-')) return false;
        if ($GLOBALS['css_failure'] === 'css_activate' && str_ends_with($target, 'custom-overrides.css')) return false;
        if ($GLOBALS['css_failure'] === 'atomic_rollback_uncertain_css_restore' && str_ends_with($target, 'custom-overrides.css')) {
            $GLOBALS['css_restore_target_renames'] = ($GLOBALS['css_restore_target_renames'] ?? 0) + 1;
            if ($GLOBALS['css_restore_target_renames'] > 1) return false;
        }
        if ($GLOBALS['css_failure'] === 'restore_only' && count(array_filter($GLOBALS['css_events'],
            /** Count recorded fixture renames.
             * @param string $event Recorded operation.
             * @return bool Whether the operation is a rename.
             */
            static fn (string $event): bool => $event === 'rename')) > 1) return false;
        return \rename($source, $target);
    }
    /** Inject failed digest observation without fabricating an apparently verified copy.
     * @param string $algorithm Requested hash algorithm.
     * @param string $filename Owned fixture path.
     * @param bool $binary Whether to return raw digest bytes.
     * @return string|false Actual digest or injected inspection failure.
     */
    function hash_file(string $algorithm,string $filename,bool $binary=false): string|false { return $GLOBALS['css_failure']==='hash_failure' ? false : \hash_file($algorithm,$filename,$binary); }
    /** Record permissions requested by the actual staging pipeline and inject bounded failures.
     * @param string $filename Owned staged or recovery asset.
     * @param int $permissions Requested filesystem mode.
     * @return bool Whether native permission application succeeded.
     */
    function chmod(string $filename,int $permissions): bool {
        $GLOBALS['css_modes'][]=['path'=>$filename,'mode'=>$permissions];
        $count=count($GLOBALS['css_modes']);
        if(($GLOBALS['css_failure']==='chmod_first' && $count===1) || ($GLOBALS['css_failure']==='chmod_second' && $count===2)) return false;
        return \chmod($filename,$permissions);
    }
    /** Refuse only active-file cleanup to exercise a failed first-install rollback.
     * @param string $filename Owned disposable path to remove.
     * @return bool Whether the fixture removal succeeded.
     */
    function unlink(string $filename): bool { return $GLOBALS['css_failure']==='unlink_active' && $filename===custom_css_path() ? false : \unlink($filename); }
    /** Recognize only explicitly allocated disposable uploads.
     * @param string $path Proposed uploaded path.
     * @return bool Whether the test owns this simulated upload.
     */
    function is_uploaded_file(string $path): bool { return $path===$GLOBALS['css_upload'] || $path===($GLOBALS['css_background_upload']??''); }
    /** Move a disposable upload or inject its transport failure.
     * @param string $source Owned simulated upload.
     * @param string $target Owned staging path.
     * @return bool Whether the fixture move succeeded.
     */
    function move_uploaded_file(string $source,string $target): bool { $GLOBALS['css_events'][]='upload'; return $GLOBALS['css_failure']==='upload_move' ? false : \rename($source,$target); }
    /** Match only raster MIME values admitted by the actual Theme upload policy.
     * @param string $mime Content-derived raster MIME value.
     * @return string|null Normalized extension or null for unsupported content.
     */
    function gallery_branding_mime_extension(string $mime): ?string { return ['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp'][$mime]??null; }
}
namespace Gallery\Models {
    /** Identify the fixture's unconfirmed settings transaction outcome. */
    final class AppSettingsRollbackException extends \RuntimeException {}

    /** Return one value from the disposable raw settings snapshot.
     * @param string $key Requested application setting.
     * @return string|false Stored fixture string or false when absent.
     */
    function app_settings_model_get(string $key): string|false { return array_key_exists($key,$GLOBALS['css_settings']) ? (string)$GLOBALS['css_settings'][$key] : false; }
}
namespace {
    /** Require an observable preservation invariant.
     * @param bool $condition Required state.
     * @param string $message Failure context.
     * @return void Throws on regression.
     */
    function css_preserve_require(bool $condition,string $message): void { if(!$condition) throw new RuntimeException($message); }
    /** Restore a known installed fixture and clear all injection state.
     * @return void Initializes only disposable files and in-memory settings.
     */
    function css_preserve_baseline(): void {
        $GLOBALS['css_settings']=['custom_css_preset'=>'old.css','theme_accent'=>'#123456','theme_page_width'=>'1440','theme_background_path'=>'','theme_background_original_path'=>'','theme_background_optimized_path'=>'','theme_background_source'=>'existing','theme_background_optimized_max_side'=>'1920'];
        $GLOBALS['css_fail_setting']=false; $GLOBALS['css_schema']='available'; $GLOBALS['css_failure']=''; $GLOBALS['css_events']=[]; $GLOBALS['css_modes']=[];
        file_put_contents(\Gallery\Services\custom_css_path(),'/* retained local edits */ .owned{color:#123456}');
        \chmod(\Gallery\Services\custom_css_path(),0640); clearstatcache(true,\Gallery\Services\custom_css_path());
        file_put_contents($GLOBALS['css_upload'],'/* uploaded CSS */ .uploaded{display:block}');
    }
    /** List disposable staging files without assuming platform-specific tempnam filename spelling.
     * @return list<string> Owned temporary asset paths excluding the active stylesheet.
     */
    function css_preserve_staged_paths(): array {
        $paths=[]; $directory=dirname(\Gallery\Services\custom_css_path());
        foreach(new DirectoryIterator($directory) as $entry) if($entry->isFile() && $entry->getFilename()!=='custom.css') $paths[]=$entry->getPathname();
        return $paths;
    }
    /** Hash all installed Theme image assets owned by the disposable service fixture.
     * @return array<string,string> Filename-to-SHA-256 map, excluding the shared writer lock.
     */
    function css_preserve_background_hashes(): array {
        $paths=[]; $directory=$GLOBALS['css_root'].'/cache/theme-background';
        foreach(new DirectoryIterator($directory) as $entry) if(!$entry->isDot() && $entry->getFilename()!=='.theme-background.lock') {
            if(!$entry->isFile() || $entry->isLink()) throw new RuntimeException('Unexpected fixture background storage entry.');
            $paths[$entry->getFilename()]=hash_file('sha256',$entry->getPathname());
        }
        ksort($paths,SORT_STRING); return $paths;
    }
    /** Confirm a failed action leaves the old bytes, marker and unrelated preferences intact.
     * @param callable():mixed $action Actual service invocation with a configured failure.
     * @param string $label Failure scenario description.
     * @return void Checks rollback and propagates unexpected successes.
     */
    function css_preserve_failure(callable $action,string $label): void {
        $bytes=file_get_contents(\Gallery\Services\custom_css_path()); $settings=$GLOBALS['css_settings']; $mode=fileperms(\Gallery\Services\custom_css_path()) & 0777; $failed=false;
        try { $action(); } catch(RuntimeException) { $failed=true; }
        clearstatcache(true,\Gallery\Services\custom_css_path());
        css_preserve_require($failed && file_get_contents(\Gallery\Services\custom_css_path())===$bytes && $GLOBALS['css_settings']===$settings,$label.' changed existing CSS or settings');
        css_preserve_require((fileperms(\Gallery\Services\custom_css_path()) & 0777)===$mode,$label.' changed installed stylesheet permissions');
        css_preserve_require(css_preserve_staged_paths()===[],$label.' leaked a disposable staging file');
    }
    $root=sys_get_temp_dir().'/gallery-custom-css-'.bin2hex(random_bytes(10)); $GLOBALS['css_root']=$root;
    $directories=['','/app','/app/services','/app/services/custom_css','/app/services/gallery_backgrounds','/public','/public/assets','/custom_css','/cache'];
    foreach($directories as $directory) mkdir($root.$directory);
    \copy(dirname(__DIR__).'/app/services/custom_css.php',$root.'/app/services/custom_css.php');
    \copy(dirname(__DIR__).'/app/services/custom_css/visual_background_save.php',$root.'/app/services/custom_css/visual_background_save.php');
    \copy(dirname(__DIR__).'/app/services/gallery_backgrounds.php',$root.'/app/services/gallery_backgrounds.php');
    \copy(dirname(__DIR__).'/app/services/gallery_backgrounds/visual_css_save.php',$root.'/app/services/gallery_backgrounds/visual_css_save.php');
    require $root.'/app/services/custom_css.php';
    require $root.'/app/services/gallery_backgrounds.php';
    require dirname(__DIR__).'/app/services/updates.php';
    require_once dirname(__DIR__).'/app/release_file_policy.php';
    $GLOBALS['css_upload']=$root.'/upload.css';
    try {
        file_put_contents($root.'/custom_css/new.css','/* preset CSS */ .preset{display:grid}');
        file_put_contents($root.'/custom_css/old.css','/* original preset */');
        css_preserve_baseline(); $before=file_get_contents(\Gallery\Services\custom_css_path());
        css_preserve_require(!\Gallery\Services\custom_css_save_selection('') && !\Gallery\Services\custom_css_apply_preset('old.css'),'Keep current or same-marker save was not a no-op');
        css_preserve_require(file_get_contents(\Gallery\Services\custom_css_path())===$before && $GLOBALS['css_events']===[],'no-op allocated files or replaced local edits');
        css_preserve_require(!\Gallery\Services\custom_css_apply_preset('../new.css') && !\Gallery\Services\custom_css_store_uploaded(['name'=>'fake.css','tmp_name'=>$root.'/custom_css/new.css']),'legacy invalid wrappers changed behavior');
        css_preserve_failure(/** Invoke strict selection with a traversal filename. @return bool Actual service result. */ fn(): bool=>\Gallery\Services\custom_css_save_selection('../new.css'),'strict traversal rejection');
        foreach(['stage_copy','backup_copy','rename_once','allocate','hash_failure','chmod_first','chmod_second'] as $failure) { css_preserve_baseline(); $GLOBALS['css_failure']=$failure; css_preserve_failure(/** Exercise actual preset staging with configured failure. @return bool Actual service result. */ fn(): bool=>\Gallery\Services\custom_css_save_selection('new.css'),$failure); }
        css_preserve_baseline(); $GLOBALS['css_fail_setting']=true; css_preserve_failure(/** Exercise activation followed by a failed marker write. @return bool Actual service result. */ fn(): bool=>\Gallery\Services\custom_css_save_selection('new.css'),'replacement marker rollback');
        foreach(['missing','unknown'] as $schema) { css_preserve_baseline(); $GLOBALS['css_schema']=$schema; css_preserve_failure(/** Refuse replacement using unverified fixture storage. @return bool Actual service result. */ fn(): bool=>\Gallery\Services\custom_css_save_selection('new.css'),'schema '.$schema); css_preserve_require(count($GLOBALS['css_events'])===1 && str_starts_with($GLOBALS['css_events'][0],'preflight:'),'schema refusal mutated files before preflight'); $GLOBALS['css_events']=[]; css_preserve_failure(/** Refuse reset before changing an installed stylesheet under unverified storage. @return void Actual service refusal. */ function(): void { \Gallery\Services\custom_css_reset(); },'reset schema '.$schema); css_preserve_require($GLOBALS['css_events']===['preflight:custom_stylesheet.reset'],'reset schema refusal performed filesystem work'); }
        foreach([['name'=>'bad.txt','tmp_name'=>$GLOBALS['css_upload'],'error'=>UPLOAD_ERR_OK],['name'=>'good.css','tmp_name'=>$GLOBALS['css_upload'],'error'=>UPLOAD_ERR_PARTIAL]] as $upload) { css_preserve_baseline(); css_preserve_failure(/** Reject a malformed explicit upload before considering a preset. @return bool Actual service result. */ fn(): bool=>\Gallery\Services\custom_css_save_selection('new.css',$upload),'invalid upload precedence'); }
        css_preserve_baseline(); $GLOBALS['css_failure']='upload_move'; css_preserve_failure(/** Inject a failed staged upload move. @return bool Actual service result. */ fn(): bool=>\Gallery\Services\custom_css_save_selection('new.css',['name'=>'valid.css','tmp_name'=>$GLOBALS['css_upload'],'error'=>UPLOAD_ERR_OK]),'upload move failure');
        css_preserve_baseline(); $oldMode=fileperms(\Gallery\Services\custom_css_path()) & 0777; css_preserve_require(\Gallery\Services\custom_css_save_selection('new.css',['name'=>'valid.CSS','tmp_name'=>$GLOBALS['css_upload'],'error'=>UPLOAD_ERR_OK]),'valid upload did not take precedence');
        clearstatcache(true,\Gallery\Services\custom_css_path()); css_preserve_require((fileperms(\Gallery\Services\custom_css_path()) & 0777)===$oldMode && count($GLOBALS['css_modes'])===2,'successful replacement failed to preserve active and backup file permissions');
        foreach($GLOBALS['css_modes'] as $permission) css_preserve_require($permission['mode']===$oldMode,'staging or recovery copy uses different permissions from the installed stylesheet');
        css_preserve_require(str_contains(file_get_contents(\Gallery\Services\custom_css_path()),'uploaded CSS') && $GLOBALS['css_settings']['custom_css_preset']==='uploaded' && $GLOBALS['css_settings']['theme_accent']==='#123456','upload replaced unrelated appearance preferences or lost precedence');
        $state=\Gallery\Services\custom_css_state(); css_preserve_require($state['active'] && $state['preset']==='uploaded' && $state['bytes']===filesize(\Gallery\Services\custom_css_path()) && $state['modified']>0 && $state['url']==='/fixture/assets/custom.css','current state metadata does not describe the installed file');
        css_preserve_baseline(); $GLOBALS['css_fail_setting']=true; css_preserve_failure(/** Exercise reset marker failure with actual file rollback. @return void Service resets the disposable stylesheet. */ function(): void { \Gallery\Services\custom_css_reset(); },'reset marker rollback');
        css_preserve_baseline(); $GLOBALS['css_failure']='rename_once'; css_preserve_failure(/** Exercise failed staged removal while retaining the old stylesheet. @return void Service attempts a disposable reset. */ function(): void { \Gallery\Services\custom_css_reset(); },'reset removal failure');
        css_preserve_baseline(); $original=file_get_contents(\Gallery\Services\custom_css_path()); $GLOBALS['css_failure']='restore_only'; $GLOBALS['css_fail_setting']=true; $recovery=false;
        try { \Gallery\Services\custom_css_save_selection('new.css'); } catch(\Gallery\Services\CustomCssRecoveryException $exception) { $recovery=$exception->hasRecoveryCopy; }
        $backups=css_preserve_staged_paths();
        css_preserve_require($recovery && count($backups)===1 && file_get_contents($backups[0])===$original && $GLOBALS['css_settings']['custom_css_preset']==='old.css','failed rollback lost the original recovery copy or safe failure type');
        unlink($backups[0]);
        css_preserve_baseline(); unlink(\Gallery\Services\custom_css_path()); $GLOBALS['css_settings']['custom_css_preset']=''; $GLOBALS['css_failure']='unlink_active'; $GLOBALS['css_fail_setting']=true; $incomplete=false;
        try { \Gallery\Services\custom_css_save_selection('new.css'); } catch(\Gallery\Services\CustomCssRecoveryException $exception) { $incomplete=!$exception->hasRecoveryCopy; }
        css_preserve_require($incomplete && is_file(\Gallery\Services\custom_css_path()) && str_contains(file_get_contents(\Gallery\Services\custom_css_path()),'preset CSS') && css_preserve_staged_paths()===[] && $GLOBALS['css_settings']['custom_css_preset']==='','failed first-install cleanup invented a prior recovery copy or hid the remaining active file');
        css_preserve_baseline(); unlink(\Gallery\Services\custom_css_path()); css_preserve_require(\Gallery\Services\custom_css_apply_preset('old.css') && file_get_contents(\Gallery\Services\custom_css_path())==='/* original preset */','same-marker selection failed to repair a missing active file');
        clearstatcache(true,\Gallery\Services\custom_css_path()); css_preserve_require(count($GLOBALS['css_modes'])===1 && $GLOBALS['css_modes'][0]['mode']===0644,'first installation did not explicitly request publicly readable permissions');
        if(PHP_OS_FAMILY!=='Windows') css_preserve_require((fileperms(\Gallery\Services\custom_css_path()) & 0777)===0644,'first installation did not retain native POSIX permissions');
        css_preserve_baseline(); css_preserve_require(\Gallery\Services\custom_css_save_selection('new.css',['name'=>'','tmp_name'=>'','error'=>UPLOAD_ERR_NO_FILE]) && str_contains(file_get_contents(\Gallery\Services\custom_css_path()),'preset CSS'),'native empty upload failed to fall back to explicit preset selection');
        css_preserve_baseline(); $resetExpectedSettings=$GLOBALS['css_settings']; \Gallery\Services\custom_css_reset(); $resetExpectedSettings['custom_css_preset']=''; css_preserve_require(!is_file(\Gallery\Services\custom_css_path()) && $GLOBALS['css_settings']===$resetExpectedSettings,'successful reset changed another owner');
        $state=\Gallery\Services\custom_css_state(); css_preserve_require(!$state['active'] && $state['bytes']===0 && $state['modified']===0 && $state['url']==='','absent CSS state advertises an asset');
        foreach(['public/assets/custom.css','custom_css/new.css','custom_css/nested/local.css'] as $path) css_preserve_require(\Gallery\Services\application_update_path_is_protected($path),'updater admits local CSS '.$path);
        $manifest=json_decode(file_get_contents(dirname(__DIR__).'/app/core-manifest.json'),true,512,JSON_THROW_ON_ERROR);
        foreach(array_keys($manifest['files']) as $path) css_preserve_require($path!=='public/assets/custom.css' && !str_starts_with($path,'custom_css/'),'local CSS became updater managed');
        $controller=file_get_contents(dirname(__DIR__).'/app/controllers/admin_theme_actions.php');
        css_preserve_require(substr_count($controller,'custom_css_save_selection(')===1 && !str_contains($controller,'elseif ($customCssChanged)'),'normal Theme save reintroduced implicit appearance reset or multiple CSS commits');
        $productionPaths=\Gallery\Core\release_file_policy_paths(dirname(__DIR__),'production');
        $updaterPaths=\Gallery\Core\release_file_policy_paths(dirname(__DIR__),'updater');
        foreach(['public/assets/custom.css','custom_css/new.css','custom_css/nested/local.css'] as $path) {
            css_preserve_require(!in_array($path,$productionPaths,true) && !in_array($path,$updaterPaths,true),'canonical package policy admitted active or dynamically created local CSS '.$path);
        }
        foreach(['custom_css/css_template.css','custom_css/custom.css','custom_css/modern.css'] as $path) {
            css_preserve_require(in_array($path,$productionPaths,true),'canonical production inventory lost an intentional shipped CSS preset '.$path);
        }
        $powershell=file_get_contents(dirname(__DIR__).'/scripts/deploy.ps1'); $shell=file_get_contents(dirname(__DIR__).'/scripts/deploy.sh');
        css_preserve_require(str_contains($powershell,'scripts/release_files.php') && str_contains($powershell,"'list',") && str_contains($powershell,"'verify',"),
            'PowerShell deployment bypassed the canonical inventory list or staged-tree verifier');
        css_preserve_require(str_contains($shell,'release_files.php" list') && str_contains($shell,'release_files.php" verify'),
            'Shell deployment bypassed the canonical inventory list or staged-tree verifier');
        // Exercise the independent editor against only the copied service and disposable assets.
        css_preserve_baseline();
        $initial = \Gallery\Services\custom_css_overrides_state();
        css_preserve_require($initial['text'] === '' && $initial['url'] === '' && $initial['revision'] === hash('sha256', ''), 'missing override has a deterministic empty revision');
        $installed = file_get_contents(\Gallery\Services\custom_css_path());
        $settingsBefore = $GLOBALS['css_settings'];
        $css = "/* Žluťoučký </textarea><script> */\n.gallery-card { border-radius: 3px !important; }\n@media (max-width:700px) { :root { --custom: 2px; } }";
        $saved = \Gallery\Services\custom_css_overrides_save($css, $initial['revision']);
        css_preserve_require($saved['text'] === $css && \Gallery\Services\custom_css_overrides_state() === $saved && str_ends_with($saved['url'], $saved['revision']), 'CSS, UTF-8, selectors, media queries and HTML-looking strings survive a reload with digest versioning');
        css_preserve_require(file_get_contents(\Gallery\Services\custom_css_path()) === $installed && $GLOBALS['css_settings'] === $settingsBefore, 'editor save touched installed CSS or Theme settings');
        $conflict = false;
        try { \Gallery\Services\custom_css_overrides_save('.stale { color:red; }', $initial['revision']); } catch (\Gallery\Services\CustomCssOverrideConflictException) { $conflict = true; }
        css_preserve_require($conflict && \Gallery\Services\custom_css_overrides_state() === $saved, 'stale editor overwrote the newer snapshot');
        foreach ([str_repeat('x', \Gallery\Services\CUSTOM_CSS_OVERRIDE_MAX_BYTES + 1), "\xFF", "a\0b"] as $invalid) {
            $refused = false;
            try { \Gallery\Services\custom_css_overrides_save($invalid, $saved['revision']); } catch (InvalidArgumentException) { $refused = true; }
            css_preserve_require($refused && \Gallery\Services\custom_css_overrides_state() === $saved, 'invalid transport changed installed overrides');
        }
        foreach (['allocate', 'hash_failure', 'chmod_first', 'rename_once'] as $failure) {
            $GLOBALS['css_failure'] = $failure; $GLOBALS['css_modes'] = [];
            $refused = false;
            try { \Gallery\Services\custom_css_overrides_save('.changed { padding:2px; }', $saved['revision']); } catch (RuntimeException) { $refused = true; }
            $GLOBALS['css_failure'] = '';
            css_preserve_require($refused && \Gallery\Services\custom_css_overrides_state() === $saved, 'override ' . $failure . ' failed to preserve the previous file');
        }
        $lock = fopen($root . '/public/assets/.custom-overrides.lock', 'c'); flock($lock, LOCK_EX);
        $refused = false;
        try { \Gallery\Services\custom_css_overrides_save('.busy{}', $saved['revision']); } catch (RuntimeException) { $refused = true; }
        flock($lock, LOCK_UN); fclose($lock);
        css_preserve_require($refused && \Gallery\Services\custom_css_overrides_state() === $saved, 'parallel writer was not refused without changing saved bytes');
        \rename($root . '/public/assets', $root . '/public/held-assets');
        $refused = false;
        try { \Gallery\Services\custom_css_overrides_save('.missing{}', $saved['revision']); } catch (RuntimeException) { $refused = true; }
        \rename($root . '/public/held-assets', $root . '/public/assets');
        css_preserve_require($refused && \Gallery\Services\custom_css_overrides_state() === $saved, 'missing asset directory changed the previous file');
        \Gallery\Services\custom_css_apply_preset('new.css');
        css_preserve_require(\Gallery\Services\custom_css_overrides_state() === $saved, 'preset replacement erased overrides');
        \Gallery\Services\custom_css_save_selection('', ['name'=>'valid.css','tmp_name'=>$GLOBALS['css_upload'],'error'=>UPLOAD_ERR_OK]);
        css_preserve_require(\Gallery\Services\custom_css_overrides_state() === $saved, 'upload replacement erased overrides');
        \Gallery\Services\custom_css_reset();
        css_preserve_require(\Gallery\Services\custom_css_overrides_state() === $saved, 'installed stylesheet reset erased overrides');
        css_preserve_baseline();
        $beforeComposite = \Gallery\Services\custom_css_overrides_state();
        $GLOBALS['css_background_upload'] = $root . '/background-first.gif';
        file_put_contents($GLOBALS['css_background_upload'], base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7', true));
        $firstComposite = \Gallery\Services\custom_css_overrides_save(
            '.visual { color: #123456; }',
            $beforeComposite['revision'],
            ['file'=>['name'=>'background.gif','tmp_name'=>$GLOBALS['css_background_upload'],'error'=>UPLOAD_ERR_OK,'size'=>filesize($GLOBALS['css_background_upload'])],'expected_revision'=>\Gallery\Services\theme_background_revision()]
        );
        $installedBackgroundSettings = $GLOBALS['css_settings'];
        $installedBackgroundPath = $root . '/' . $installedBackgroundSettings['theme_background_original_path'];
        css_preserve_require($firstComposite['text'] === '.visual { color: #123456; }' && is_file($installedBackgroundPath), 'explicit composite save did not activate its CSS and owned background');
        $currentBackgroundRevision = \Gallery\Services\theme_background_revision();
        $GLOBALS['css_settings']['theme_background_opacity'] = '48';
        $opacityChangedRevision = \Gallery\Services\theme_background_revision();
        unset($GLOBALS['css_settings']['theme_background_opacity']);
        css_preserve_require($opacityChangedRevision !== $currentBackgroundRevision, 'background revision did not protect the opacity setting that affects the reviewed result');
        $priorCompositeCssMode = fileperms(\Gallery\Services\custom_css_overrides_path()) & 0777;
        $staleBackgroundRefused = false;
        $GLOBALS['css_background_upload'] = $root . '/background-stale.gif';
        file_put_contents($GLOBALS['css_background_upload'], base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7', true));
        try {
            \Gallery\Services\custom_css_overrides_save('.stale { color: red; }', $firstComposite['revision'], ['file'=>['name'=>'background.gif','tmp_name'=>$GLOBALS['css_background_upload'],'error'=>UPLOAD_ERR_OK,'size'=>filesize($GLOBALS['css_background_upload'])],'expected_revision'=>str_repeat('0',64)]);
        } catch (\Gallery\Services\CustomCssOverrideConflictException) { $staleBackgroundRefused = true; }
        css_preserve_require($staleBackgroundRefused && \Gallery\Services\custom_css_overrides_state() === $firstComposite && $GLOBALS['css_settings'] === $installedBackgroundSettings && is_file($GLOBALS['css_background_upload']), 'stale background revision changed saved CSS or settings before consuming the pending upload');
        foreach (['background_activate','css_activate','atomic_commit'] as $failure) {
            $previousFiles = [];
            foreach (glob($root . '/cache/theme-background/background*.*') ?: [] as $path) if (is_file($path)) $previousFiles[basename($path)] = hash_file('sha256', $path);
            $GLOBALS['css_background_upload'] = $root . '/background-' . $failure . '.gif';
            file_put_contents($GLOBALS['css_background_upload'], base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7', true));
            $GLOBALS['css_failure'] = $failure;
            $refused = false;
            try {
                \Gallery\Services\custom_css_overrides_save('.failed { color: red; }', $firstComposite['revision'], ['file'=>['name'=>'background.gif','tmp_name'=>$GLOBALS['css_background_upload'],'error'=>UPLOAD_ERR_OK,'size'=>filesize($GLOBALS['css_background_upload'])],'expected_revision'=>$currentBackgroundRevision]);
            } catch (RuntimeException) { $refused = true; }
            $GLOBALS['css_failure'] = '';
            $currentFiles = [];
            foreach (glob($root . '/cache/theme-background/background*.*') ?: [] as $path) if (is_file($path)) $currentFiles[basename($path)] = hash_file('sha256', $path);
            $stagingFiles = array_merge(glob($root . '/cache/theme-background/.pending-*') ?: [], glob($root . '/public/assets/.custom-css-*') ?: []);
            css_preserve_require($refused && \Gallery\Services\custom_css_overrides_state() === $firstComposite && (fileperms(\Gallery\Services\custom_css_overrides_path()) & 0777) === $priorCompositeCssMode && $GLOBALS['css_settings'] === $installedBackgroundSettings && $currentFiles === $previousFiles && $stagingFiles === [], $failure . ' failure did not restore prior CSS bytes/mode, Theme settings/assets or remove staging files');
        }
        $previousFiles = [];
        foreach (glob($root . '/cache/theme-background/background*.*') ?: [] as $path) if (is_file($path)) $previousFiles[basename($path)] = hash_file('sha256', $path);
        $GLOBALS['css_background_upload'] = $root . '/background-rollback-uncertain.gif';
        file_put_contents($GLOBALS['css_background_upload'], base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7', true));
        $GLOBALS['css_failure'] = 'atomic_rollback_uncertain';
        $uncertainFailure = false;
        try {
            \Gallery\Services\custom_css_overrides_save('.uncertain { color: red; }', $firstComposite['revision'], ['file'=>['name'=>'background.gif','tmp_name'=>$GLOBALS['css_background_upload'],'error'=>UPLOAD_ERR_OK,'size'=>filesize($GLOBALS['css_background_upload'])],'expected_revision'=>$currentBackgroundRevision]);
        } catch (\Gallery\Services\CustomCssRecoveryException $exception) {
            $uncertainFailure = $exception->backgroundStateUncertain;
        }
        $GLOBALS['css_failure'] = '';
        $uncertainFiles = [];
        foreach (glob($root . '/cache/theme-background/background*.*') ?: [] as $path) if (is_file($path)) $uncertainFiles[basename($path)] = hash_file('sha256', $path);
        $uncertainBackgroundPath = $root . '/' . $GLOBALS['css_settings']['theme_background_original_path'];
        $stagingFiles = array_merge(glob($root . '/cache/theme-background/.pending-*') ?: [], glob($root . '/public/assets/.custom-css-*') ?: []);
        css_preserve_require($uncertainFailure && \Gallery\Services\custom_css_overrides_state() === $firstComposite
            && $GLOBALS['css_settings'] !== $installedBackgroundSettings && is_file($uncertainBackgroundPath)
            && array_diff_key($previousFiles, $uncertainFiles) === [] && count($uncertainFiles) > count($previousFiles)
            && $stagingFiles === [], 'unconfirmed database rollback removed a possibly referenced immutable background or changed saved CSS');
        $previousFiles = $uncertainFiles;
        $GLOBALS['css_background_upload'] = $root . '/background-double-failure.gif';
        file_put_contents($GLOBALS['css_background_upload'], base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7', true));
        $GLOBALS['css_failure'] = 'atomic_rollback_uncertain_css_restore';
        $GLOBALS['css_restore_target_renames'] = 0;
        $doubleFailure = false;
        try {
            \Gallery\Services\custom_css_overrides_save('.double-failure { color: red; }', \Gallery\Services\custom_css_overrides_state()['revision'], ['file'=>['name'=>'background.gif','tmp_name'=>$GLOBALS['css_background_upload'],'error'=>UPLOAD_ERR_OK,'size'=>filesize($GLOBALS['css_background_upload'])],'expected_revision'=>\Gallery\Services\theme_background_revision()]);
        } catch (\Gallery\Services\CustomCssRecoveryException $exception) {
            $doubleFailure = $exception->backgroundStateUncertain && $exception->hasRecoveryCopy;
        }
        $GLOBALS['css_failure'] = '';
        $doubleFailureFiles = [];
        foreach (glob($root . '/cache/theme-background/background*.*') ?: [] as $path) if (is_file($path)) $doubleFailureFiles[basename($path)] = hash_file('sha256', $path);
        $recoveryCopies = glob($root . '/public/assets/.custom-css-*') ?: [];
        css_preserve_require($doubleFailure && array_diff_key($previousFiles, $doubleFailureFiles) === []
            && count($doubleFailureFiles) > count($previousFiles) && count($recoveryCopies) === 1
            && file_get_contents($recoveryCopies[0]) === $firstComposite['text'],
            'combined CSS restore and settings rollback failure did not retain both background candidates and the prior CSS recovery copy');
        if (!\rename($recoveryCopies[0], \Gallery\Services\custom_css_overrides_path())) {
            throw new RuntimeException('The disposable prior CSS recovery copy could not be restored for fixture cleanup.');
        }
        $GLOBALS['css_settings'] = $installedBackgroundSettings;
        $saved = $firstComposite;
        $beforeRemoveCss = \Gallery\Services\custom_css_overrides_state();
        $beforeRemoveSettings = $GLOBALS['css_settings'];
        $beforeRemoveBackgrounds = css_preserve_background_hashes();
        $beforeRemovePublicAssets = css_preserve_staged_paths();
        $beforeRemoveMode = fileperms(\Gallery\Services\custom_css_overrides_path()) & 0777;
        $removeRevision = \Gallery\Services\theme_background_revision();
        $GLOBALS['css_failure'] = 'atomic_commit';
        $removeCommitFailure = false;
        try {
            \Gallery\Services\custom_css_overrides_save(
                '.remove-failure { color: red; }',
                $beforeRemoveCss['revision'],
                ['operation'=>'remove','target'=>'theme','expected_revision'=>$removeRevision]
            );
        } catch (RuntimeException) { $removeCommitFailure = true; }
        $GLOBALS['css_failure'] = '';
        clearstatcache(true, \Gallery\Services\custom_css_overrides_path());
        css_preserve_require($removeCommitFailure
            && \Gallery\Services\custom_css_overrides_state() === $beforeRemoveCss
            && $GLOBALS['css_settings'] === $beforeRemoveSettings
            && css_preserve_background_hashes() === $beforeRemoveBackgrounds
            && (fileperms(\Gallery\Services\custom_css_overrides_path()) & 0777) === $beforeRemoveMode
            && css_preserve_staged_paths() === $beforeRemovePublicAssets,
            'settings commit failure after staged CSS activation did not restore exact CSS, Theme settings and background assets');
        $removed = \Gallery\Services\custom_css_overrides_save(
            '.theme-image-removed { color: #123456; }',
            $beforeRemoveCss['revision'],
            ['operation'=>'remove','target'=>'theme','expected_revision'=>$removeRevision]
        );
        css_preserve_require($removed['text'] === '.theme-image-removed { color: #123456; }'
            && \Gallery\Services\custom_css_overrides_state() === $removed
            && $GLOBALS['css_settings']['theme_background_path'] === ''
            && $GLOBALS['css_settings']['theme_background_original_path'] === ''
            && $GLOBALS['css_settings']['theme_background_optimized_path'] === ''
            && $GLOBALS['css_settings']['theme_background_source'] === 'existing'
            && $GLOBALS['css_settings']['theme_accent'] === '#123456'
            && css_preserve_background_hashes() === [],
            'global Theme image removal did not clear only owned image settings/assets while preserving fallback and unrelated appearance');
        $removedSettings = $GLOBALS['css_settings'];
        $removedRevision = \Gallery\Services\theme_background_revision();
        $idempotentRemove = \Gallery\Services\custom_css_overrides_save(
            $removed['text'],
            $removed['revision'],
            ['operation'=>'remove','target'=>'theme','expected_revision'=>$removedRevision]
        );
        css_preserve_require($idempotentRemove === $removed && $GLOBALS['css_settings'] === $removedSettings
            && css_preserve_background_hashes() === [],
            'repeating removal with current revisions was not idempotent');
        $unknownOperationRefused = false;
        try {
            \Gallery\Services\custom_css_overrides_save($removed['text'], $removed['revision'], [
                'operation'=>'remove','target'=>'gallery','expected_revision'=>$removedRevision,
            ]);
        } catch (InvalidArgumentException) { $unknownOperationRefused = true; }
        css_preserve_require($unknownOperationRefused && $GLOBALS['css_settings'] === $removedSettings,
            'a non-Theme removal target was accepted or changed settings');
        $saved = $removed;
        foreach (['public/assets/custom-overrides.css', 'public/assets/.custom-overrides.lock'] as $owned) {
            css_preserve_require(\Gallery\Core\release_file_policy_is_protected_path($owned) && !\Gallery\Core\release_file_policy_is_updater_path($owned) && !\Gallery\Core\release_file_policy_is_integrity_path($owned) && !\Gallery\Core\release_file_policy_is_production_path($owned), 'installation-owned override was claimed by release/update policy: ' . $owned);
        }
        $cleared = \Gallery\Services\custom_css_overrides_save('', $saved['revision']);
        css_preserve_require($cleared === $initial && $GLOBALS['css_settings']['theme_accent'] === '#123456', 'explicit clear did not restore only the empty override layer');
        echo "PASS Custom CSS preservation and independent overrides\n";
    } finally {
        foreach(['/public/assets','/custom_css','/app/services/custom_css','/app/services/gallery_backgrounds','/app/services','/cache/theme-background','/cache',''] as $directory) foreach(glob($root.$directory.'/*') ?: [] as $path) if(is_file($path)) unlink($path);
        if (is_file($root . '/cache/theme-background/.theme-background.lock')) unlink($root . '/cache/theme-background/.theme-background.lock');
        foreach(new DirectoryIterator($root.'/public/assets') as $entry) if($entry->isFile()) unlink($entry->getPathname());
        if(is_dir($root.'/cache/theme-background')) rmdir($root.'/cache/theme-background');
        foreach(array_reverse($directories) as $directory) rmdir($root.$directory);
    }
}
