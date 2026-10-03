<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/support/gallery_creation_render_fixture.php
 * Module Type: Test Fixture
 * Purpose: Render actual create-gallery presentation without installation state.
 * Responsibilities: Supply safe prepared full-page and name-only panel models for isolated tests.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);
namespace Gallery\Core {
    /** Supply disposable request authority for prepared editor tools.
     * @return string Fixture-only CSRF value.
     */
    function csrf_token(): string { return 'theme-fixture'; }
    /** Supply the presentation-only logged-in administrator identity.
     * @return array{id:int,username:string} Disposable administrator row.
     */
    function current_user(): array { return ['id'=>11,'username'=>'Fixture admin']; }
    /** Leave the document wrapper to the isolated browser fixture instead of application bootstrap.
     * @param string $title Prepared page title.
     * @return void The real page-owned renderer supplies all tested markup.
     */
    function render_header(string $title): void {}
    /** Leave document closure to the isolated browser fixture.
     * @return void No installation footer or scripts are executed.
     */
    function render_footer(): void {}
}
namespace Gallery\Controllers {
    /** Prepare canonical visibility choices without controller or storage access.
     * @param string $selected Current submitted visibility.
     * @return string Safe native option markup.
     */
    function visibility_options(string $selected): string { $html=''; foreach(['public'=>'Public','unpublished'=>'Unpublished','private'=>'Private'] as $value=>$label) $html.='<option value="'.$value.'"'.($selected===$value?' selected':'').'>'.$label.'</option>'; return $html; }
}
namespace {
    require_once __DIR__.'/theme_appearance_fixture.php';
    require_once dirname(__DIR__,2).'/app/views/admin_gallery_forms.php';
    require_once dirname(__DIR__,2).'/app/views/admin_gallery_discovery.php';
    /** Prepare full create-gallery controls and the actual parent picker without storage.
     * @param array<string,mixed> $overrides Scenario-specific prepared values.
     * @return array<string,mixed> Complete creation form presentation model.
     */
    function creation_fixture_model(array $overrides=[]): array {
        $picker=\Gallery\Views\view_render_gallery_search_picker(['field_name'=>'parent_id','picker_id'=>'creation-parent','list_id'=>'creation-parent-list','hidden_value'=>'7','input_value'=>'Parent <safe> / parent',
            'allow_root'=>true,'root_label'=>'No parent','search_url'=>'/fixture-parent-search','placeholder'=>'Search parent gallery','clear_label'=>'No parent','loading_label'=>'Loading','error_label'=>'Search unavailable','empty_label'=>'No matches','page_size'=>20,'next_after_id'=>0,'more_label'=>'More','help_label'=>'Choose a parent or no parent','lookup_url'=>'#lookup','lookup_label'=>'Look up gallery ID','fallback_label'=>'Parent ID',
            'rows'=>[['id'=>7,'title'=>'Parent <safe>','label'=>'Parent <safe> / parent','path_label'=>'/parent'],['id'=>8,'title'=>'Another parent','label'=>'Another parent / second','path_label'=>'/second']]]);
        return array_replace(['operation_key'=>str_repeat('a',64),'submitted'=>[],'title_completion'=>['url'=>'/fixture-title-completion','candidates'=>[['title'=>'Gallery fixture completion','parent_id'=>7]]],
            'simbrief_enabled'=>true,'creation_preferences_available'=>true,'creation_preferences'=>['simbrief_pilot_id'=>'12345','simbrief_pilot_name'=>'','content_language'=>'cs'],
            'localization'=>['enabled'=>true,'schema_ready'=>true,'default_source_language'=>'cs','languages'=>['en','cs','de','sv'],'presentation'=>[
                'en'=>['name'=>'English'],'cs'=>['name'=>'Czech <safe>'],'de'=>['name'=>'German'],'sv'=>['name'=>'Swedish'],
            ]],
            'parent_picker_html'=>'<div class="admin-side-panel-field"><span>Parent gallery</span>'.$picker.'</div>',
            'date'=>['schema_ready'=>true,'range_schema_ready'=>true,'start_value'=>'','end_value'=>''],
            'count_badge'=>['schema_ready'=>true,'options'=>[['value'=>'inherit','label'=>'Inherit'],['value'=>'show','label'=>'Show'],['value'=>'hide','label'=>'Hide']]],
            'tag_suggestions_attribute'=>' data-tag-suggestions="[&quot;flight&quot;,&quot;landscape&quot;]"','tag_datalist_html'=>'<datalist id="tag-suggestions"><option value="flight"><option value="landscape"></datalist>',
            'visibility_summary'=>'Unpublished','parent_summary'=>'Parent <safe>'],$overrides);
    }
    /** Render actual full-page creation controls and page-owned shell.
     * @param array<string,mixed> $overrides Scenario-specific form values.
     * @param string $error Safe prepared validation message.
     * @return string Production-owned create-page markup.
     */
    function creation_fixture_page(array $overrides=[],string $error=''): string {
        ob_start(); \Gallery\Views\view_render_admin_new_gallery_fields(7,false,'create',creation_fixture_model($overrides)); $fields=(string)ob_get_clean();
        ob_start(); \Gallery\Views\view_render_admin_new_gallery_page(['title'=>'Create gallery','dashboard_url'=>'/fixture-dashboard','dashboard_label'=>'Dashboard','galleries_url'=>'/fixture-home','galleries_label'=>'Open galleries','error_notice'=>$error,'action_url'=>'/fixture-full-create','csrf_html'=>\Gallery\Core\csrf_field(),'fields_html'=>$fields,'submit_label'=>'Create gallery']); return (string)ob_get_clean();
    }
    /** Render the name-only right-panel form from actual production helpers.
     * @param int $parentId Prepared parent scope.
     * @return string Production-owned dynamic panel markup.
     */
    function creation_fixture_panel(int $parentId=7): string { ob_start(); \Gallery\Views\view_render_admin_new_gallery_side_panel($parentId,$parentId>0?['id'=>$parentId,'title'=>'Parent <safe>']:null,'',creation_fixture_model()); return (string)ob_get_clean(); }
    /** Prepare submitted retry values independently of database or session state.
     * @return array<string,mixed> Complete retry scenario values.
     */
    function creation_fixture_retry(): array { return ['submitted'=>['title'=>'Retry <safe>','description'=>'Keep <text> & description','tags'=>'flight, landscape','content_language'=>'en','remember_content_language'=>'1','simbrief_pilot_name'=>'Retry pilot','remember_simbrief_pilot_name'=>'1','simbrief_draft_ref'=>'fixture-draft','folder_name'=>'retry-folder','visibility'=>'private','voting_enabled'=>'1','show_filenames'=>'1','count_badge_visibility'=>'hide'],'date'=>['schema_ready'=>true,'range_schema_ready'=>true,'start_value'=>'2026-10-01','end_value'=>'2026-10-03']]; }
    if(realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? ''))===__FILE__) {
        foreach(\Gallery\Views\view_admin_stylesheet_files() as $stylesheet) echo '<link rel="stylesheet" href="/public/'.\Gallery\Core\e($stylesheet).'">';
        echo '<div id="full-create">'.creation_fixture_page().'</div><template id="production-create-panel">'.creation_fixture_panel().'</template><template id="production-create-root-panel">'.creation_fixture_panel(0).'</template><template id="production-create-retry">'.creation_fixture_page(creation_fixture_retry(),'Validation <safe> failed').'</template>';
    }
}
