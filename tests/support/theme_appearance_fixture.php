<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/support/theme_appearance_fixture.php
 * Module Type: Test Fixture
 * Purpose: Render production Theme forms from disposable prepared settings without bootstrap.
 * Responsibilities: Share safe presentation fixtures between PHP and browser regression tests.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);
namespace Gallery\Core {
    /** Escape untrusted fixture labels.
     * @param string $value Prepared presentation text.
     * @return string Escaped HTML.
     */
    function e(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
    /** Produce deterministic fixture route destinations.
     * @param string $route Route identifier.
     * @param array<string,mixed> $params Query values.
     * @return string Loopback-only relative URL.
     */
    function url_for(string $route, array $params = []): string { return '/index.php?' . http_build_query(['page'=>$route]+$params); }
    /** Resolve only repository-owned fixture assets.
     * @param string $path Relative public asset path.
     * @return string First-party disposable asset URL.
     */
    function asset_url(string $path): string { return '/public/'.ltrim($path,'/'); }
    /** Supply disposable form authority.
     * @return string Hidden fixture CSRF input.
     */
    function csrf_field(): string { return '<input type="hidden" name="csrf_token" value="theme-fixture">'; }
    /** Refuse accidental persistence or SQL in an isolated rendering fixture.
     * @return never No live database connection is available.
     */
    function db(): never { throw new \RuntimeException('Theme rendering must not access database storage.'); }
}
namespace Gallery\Services {
    /** Resolve labels and placeholders without loading dictionaries or configuration.
     * @param string $key Translation identifier.
     * @param string|array<string,string|int> $fallback English fallback or interpolation arguments.
     * @param array<string,string|int> $parameters Placeholder values.
     * @return string Safe prepared label.
     */
    function t(string $key, string|array $fallback = '', array $parameters = []): string { if (!is_string($fallback)) return $key; foreach ($parameters as $name=>$value) $fallback=str_replace('{'.$name.'}',(string)$value,$fallback); return $fallback; }
    /** Read only disposable settings for pure controller preparation.
     * @param string $key Setting identifier.
     * @param string|null $default Canonical fallback.
     * @return string|null Prepared fixture value.
     */
    function app_setting(string $key, ?string $default = null): ?string { return $GLOBALS['theme_fixture_values'][$key] ?? $default; }
    /** Make accidental settings writes fail before any storage access.
     * @param string $key Setting identifier.
     * @param string $value Proposed value.
     * @return never This fixture has no mutation transport.
     */
    function set_app_setting(string $key, string $value): never { throw new \RuntimeException('Theme rendering must not write settings.'); }
    /** Supply an escaped-site-name regression value.
     * @return string Disposable branding text.
     */
    function site_name(): string { return 'Theme <safe> & identity'; }
}
namespace {
    $GLOBALS['theme_fixture_values']=['theme_gallery_description_layout'=>'horizontal'];
    require_once dirname(__DIR__,2).'/app/helpers_admin_rendering.php';
    require_once dirname(__DIR__,2).'/app/views/admin_chrome.php';
    require_once dirname(__DIR__,2).'/app/views/admin_ui.php';
    require_once dirname(__DIR__,2).'/app/services/breadcrumbs.php';
    require_once dirname(__DIR__,2).'/app/views/breadcrumbs.php';
    require_once dirname(__DIR__,2).'/app/views/admin_theme.php';
    require_once dirname(__DIR__,2).'/app/views/admin_gallery_renderers.php';
    require_once dirname(__DIR__,2).'/app/views/layout.php';
    require_once dirname(__DIR__,2).'/app/controllers/shared_layout.php';
    require_once dirname(__DIR__,2).'/app/views/admin_language_settings.php';
    require_once dirname(__DIR__,2).'/app/services/theme.php';
    require_once dirname(__DIR__,2).'/app/services/pagination.php';
    require_once dirname(__DIR__,2).'/app/services/gallery_description_layout.php';
    require_once dirname(__DIR__,2).'/app/controllers/admin_theme_appearance.php';
    /** Prepare all Appearance inputs without consulting installation state.
     * @param array{theme?:array<string,string|int|bool>,advanced?:array{definitions?:array<string,array{default:string,min:int,max:int,unit:string}>,values?:array<string,string>},theme_background_url?:string,gps_maps_feature_enabled?:bool,gps_pin_enabled?:bool,gps_pin_background_enabled?:bool,gps_pin_size?:int,gps_pin_background_size?:int,page_width_mode?:string,custom_page_width?:int,tag_page_grid_settings?:array{columns:int,rows:int,items_per_page:int},tag_page_description_layout?:string,theme_gallery_description_layout?:string,description_layouts?:list<array{value:string,label:string}>,hero_tag_visible_limit?:int,hero_tag_display_all?:bool,hero_tag_scrollbar_enabled?:bool,hero_tag_scrollbar_rows?:int,hero_tag_sort_mode?:string,gallery_info_motion_ms?:int,admin_side_panel_motion_ms?:int,site_name?:string,preview?:array<string,string|int|bool>,admin_tags_url?:string,active_subtab?:string,max_columns?:int,max_rows?:int} $overrides Scenario-specific prepared values.
     * @return array{theme?:array<string,string|int|bool>,advanced?:array{definitions?:array<string,array{default:string,min:int,max:int,unit:string}>,values?:array<string,string>},theme_background_url?:string,gps_maps_feature_enabled?:bool,gps_pin_enabled?:bool,gps_pin_background_enabled?:bool,gps_pin_size?:int,gps_pin_background_size?:int,page_width_mode?:string,custom_page_width?:int,tag_page_grid_settings?:array{columns:int,rows:int,items_per_page:int},tag_page_description_layout?:string,theme_gallery_description_layout?:string,description_layouts?:list<array{value:string,label:string}>,hero_tag_visible_limit?:int,hero_tag_display_all?:bool,hero_tag_scrollbar_enabled?:bool,hero_tag_scrollbar_rows?:int,hero_tag_sort_mode?:string,gallery_info_motion_ms?:int,admin_side_panel_motion_ms?:int,site_name?:string,preview?:array<string,string|int|bool>,admin_tags_url?:string,active_subtab?:string,max_columns?:int,max_rows?:int} Complete Appearance presentation model with canonical advanced controls.
     */
    function theme_fixture_model(array $overrides = []): array {
        return array_replace(['theme'=>['accent'=>'#123456','accent_dark'=>'#234567','paper'=>'#eeeeee','panel'=>'#ffffff','gallery_panel'=>'#fafafa','header_text'=>'#102030','hero_text'=>'#304050','radius'=>12,'font'=>'sans','background_opacity'=>100,'page_width'=>'default','page_width_custom'=>1440,'gps_pin_enabled'=>'1','gps_pin_background_enabled'=>'1','gps_pin_size'=>24,'gps_pin_background_size'=>8],
            'advanced'=>['definitions'=>\Gallery\Services\theme_advanced_appearance_definitions(),'values'=>\Gallery\Services\theme_advanced_appearance_settings()],
            'site_name'=>\Gallery\Services\site_name(),'theme_gallery_description_layout'=>'horizontal','preview'=>['gallery_description_layout'=>'horizontal'],'theme_background_url'=>'','gps_maps_feature_enabled'=>true,'gps_pin_enabled'=>true,'gps_pin_background_enabled'=>true,'gps_pin_size'=>24,'gps_pin_background_size'=>8,
            'page_width_mode'=>'default','custom_page_width'=>1440,'tag_page_grid_settings'=>['columns'=>3,'rows'=>4,'items_per_page'=>12],'tag_page_description_layout'=>'vertical','description_layouts'=>[['value'=>'vertical','label'=>'Vertical'],['value'=>'horizontal','label'=>'Horizontal']],
            'hero_tag_visible_limit'=>20,'hero_tag_display_all'=>false,'hero_tag_scrollbar_enabled'=>true,'hero_tag_scrollbar_rows'=>5,'hero_tag_sort_mode'=>'usage','admin_tags_url'=>'/fixture-tags','active_subtab'=>'admin-theme-appearance-subtab-colors','max_columns'=>12,'max_rows'=>50],$overrides);
    }
    /** Render the real Appearance tab into a disposable fragment.
     * @param array<string,mixed> $overrides Prepared scenario values.
     * @return string Actual production HTML.
     */
    function theme_fixture_appearance(array $overrides = []): string { ob_start(); \Gallery\Views\view_render_admin_theme_appearance_tab(theme_fixture_model($overrides)); return (string)ob_get_clean(); }
    /** Prepare stored Media assets and controls without reading installation files.
     * @param array<string,mixed> $overrides Scenario-specific prepared Media values.
     * @return array<string,mixed> Complete disposable Media presentation model.
     */
    function theme_fixture_media_model(array $overrides = []): array {
        $asset='data:image/svg+xml,'.rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" width="640" height="120"><rect width="640" height="120" fill="#9ab"/></svg>');
        return array_replace(['banner'=>['label'=>'Banner <safe>','description'=>'Shared header banner','asset_url'=>$asset,'current_alt'=>'Current banner','remove_label'=>'Remove banner'],
            'separator'=>['label'=>'Separator','description'=>'Shared header separator','asset_url'=>$asset,'current_alt'=>'Current separator','remove_label'=>'Remove separator','width'=>960,'height'=>48,'stretch'=>true],
            'favicon_url'=>$asset.'#favicon','favicon_version'=>'fixture','background'=>['asset_url'=>$asset,'original_url'=>$asset.'#original','optimized_active'=>true,'has_background'=>true,'optimized_max_side'=>1920,'optimized_size_label'=>'1920px longest side','opacity'=>65,'source'=>'existing'],
            'labels'=>['accepted_formats'=>'JPG, PNG, GIF, WebP; 8 MB maximum','background_optimized_size_template'=>'{size}px longest side','remove_favicon'=>'Remove favicon']],$overrides);
    }
    /** Prepare real Layout controls and real searchable picker markup without storage access.
     * @param array<string,mixed> $overrides Scenario-specific Layout presentation values.
     * @return array<string,mixed> Complete disposable Layout view model.
     */
    function theme_fixture_layout_model(array $overrides = []): array {
        $shortcuts=[];
        foreach (['gallery','home',''] as $index=>$type) {
            $id='theme-favorite-gallery-'.($index+1); $selected=$index===0?'42':'';
            $shortcuts[]=['slot_label'=>'Shortcut '.($index+1),'selected_type'=>$type,'picker_html'=>\Gallery\Views\view_render_gallery_search_picker(['field_name'=>'theme_favorite_gallery_ids[]','picker_id'=>$id,'list_id'=>$id.'-list','hidden_value'=>$selected,'input_value'=>$selected!==''?'Gallery <safe> / fixture':'','search_url'=>'/fixture-gallery-search','placeholder'=>'Search gallery by name or path','clear_label'=>'Clear gallery','loading_label'=>'Loading','error_label'=>'Search unavailable','empty_label'=>'No matches','page_size'=>20,'next_after_id'=>0,'more_label'=>'More','help_label'=>'Choose one gallery','lookup_url'=>'#lookup','lookup_label'=>'Look up gallery ID','fallback_label'=>'Gallery ID','rows'=>[['id'=>42,'title'=>'Gallery <safe>','label'=>'Gallery <safe> / fixture','path_label'=>'/fixture'],['id'=>84,'title'=>'Second gallery','label'=>'Second gallery / fixture-two','path_label'=>'/fixture-two']]])];
        }
        $breadcrumbStyle = \Gallery\Services\theme_breadcrumb_style();
        $breadcrumbStylePicker = [
            'field_name'=>'theme_breadcrumb_style',
            'label'=>\Gallery\Services\t('admin.theme.layout.breadcrumb_style_label','Default breadcrumb style'),
            'current'=>$breadcrumbStyle,
            'options'=>\Gallery\Services\breadcrumb_style_picker_options(),
        ];
        return array_replace(['favorite_shortcuts'=>$shortcuts,'home_token'=>'home','gallery_count_badge_enabled'=>true,'thumbnail_modes'=>[['value'=>'progressive','label'=>'Progressive','selected'=>true],['value'=>'responsive','label'=>'Responsive','selected'=>false]],
            'breadcrumb_style_picker'=>$breadcrumbStylePicker,
            'pagination'=>['enabled'=>true,'columns'=>3,'rows'=>4,'items_per_page'=>12],'home_grid'=>['columns'=>4,'rows'=>5],'lightbox_modes_enabled'=>true,'lightbox_options'=>[['value'=>'single','label'=>'Single image','selected'=>true],['value'=>'picture_strip','label'=>'Picture strip','selected'=>false],['value'=>'3d_carousel','label'=>'3D carousel','selected'=>false]],'max_columns'=>12,'max_rows'=>50,'labels'=>['reset_gallery_grids_confirm'=>'Reset all custom gallery grids?','reset_all_gallery_grids'=>'Reset all custom gallery grids','show_count_badge'=>'Show picture count']],$overrides);
    }
    /** Load canonical pure defaults once without conflicting with rendering translation stubs.
     * @return array{defaults:array<string,mixed>,bounds:array<string,array{0:int,1:int}>} Production selector design configuration.
     */
    function theme_fixture_language_defaults(): array {
        static $prepared=null;
        if ($prepared===null) {
            $pipes=[];
            $process=proc_open([PHP_BINARY,__DIR__.'/language_design_defaults_fixture.php'],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
            if (!is_resource($process)) throw new RuntimeException('Cannot prepare canonical language defaults.');
            $json=stream_get_contents($pipes[1]); $error=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
            if (proc_close($process)!==0 || $error!=='') throw new RuntimeException('Canonical language fixture preparation failed.');
            $prepared=json_decode($json,true,512,JSON_THROW_ON_ERROR);
        }
        return $prepared;
    }
    /** Prepare real language controls and diagnostic content without consulting installation state.
     * @param array<string,mixed> $overrides Scenario-specific Language values.
     * @return array<string,mixed> Complete disposable Language presentation model.
     */
    function theme_fixture_language_model(array $overrides = []): array {
        $canonical=theme_fixture_language_defaults(); $packs=[]; $presentations=[];
        foreach (['en'=>'English','cs'=>'Czech <safe>','de'=>'German','sv'=>'Swedish'] as $code=>$name) {
            $packs[]=['code'=>$code,'name'=>$name,'format_label'=>'JSON','string_count'=>99,'coverage'=>['translated_count'=>99,'default_count'=>100],'status_label'=>'Missing 1','edit_url'=>\Gallery\Core\url_for('admin_theme',['edit_language'=>$code]).'#admin-theme-tab-language'];
            $flag=['en'=>'gb','cs'=>'cz','de'=>'de','sv'=>'se'][$code];
            $presentations[$code]=['name'=>$name,'flag_asset'=>'assets/flags/'.$flag.'.svg'];
        }
        return array_replace(['language_packs'=>$packs,'admin_language'=>'cs','public_language'=>'de','default_language'=>'en','language_edit_code'=>'cs','active_subtab'=>'admin-theme-language-subtab-settings','editor_base_url'=>\Gallery\Core\url_for('admin_theme'),'export_url'=>\Gallery\Core\url_for('admin_theme',['download_language_pack'=>'cs']),
            'language_pack_json'=>"{\n  \"gallery.fixture\": \"Safe <text> {count}\"\n}",'language_editor_errors'=>[],
            'language_coverage'=>['translated_count'=>99,'default_count'=>100,'missing_count'=>1,'extra_count'=>1,'missing_keys'=>['gallery.missing<safe>'],'extra_keys'=>['legacy.extra<safe>']],
            'missing_translations'=>[['key'=>'gallery.diagnostic<safe>','active_language'=>'cs','fallback_used'=>true,'last_seen'=>'2026-10-02 12:00']],
            'selector_state'=>['id_prefix'=>'admin-theme-public-language-selector','marker_name'=>'public_language_selector_settings_present','enabled'=>true,'languages'=>['en','de'],'supported_languages'=>array_keys($presentations),'presentations'=>$presentations,'design_defaults'=>$canonical['defaults'],'design'=>$canonical['defaults'],'design_bounds'=>$canonical['bounds'],'public_language'=>'de']],$overrides);
    }
    /** Prepare Custom CSS state and preset choices without reading user files.
     * @param array{labels?:array<string,string>,presets?:list<array{filename:string,label:string,selected?:bool}>,current_css?:array{active?:bool,status_label?:string,preset_label?:string,size_label?:string,modified_label?:string,public_url?:string},errors?:list<string>,overrides?:array{state?:array{text:string,revision:string,url:string},draft?:array{text:string,revision:string}|null,notice?:array{ok:bool,message:string}|null,ready?:bool,active?:bool,background?:array{available:bool,ready?:bool,revision:string,source:string,url:string}}} $overrides Scenario-specific Custom CSS presentation values, including a safe image readiness snapshot; preview_url stays absent so chooser behavior does not depend on preview launch.
     * @return array{labels?:array<string,string>,presets?:list<array{filename:string,label:string,selected?:bool}>,current_css?:array{active?:bool,status_label?:string,preset_label?:string,size_label?:string,modified_label?:string,public_url?:string},errors?:list<string>,overrides?:array{state?:array{text:string,revision:string,url:string},draft?:array{text:string,revision:string}|null,notice?:array{ok:bool,message:string}|null,ready?:bool,active?:bool,background?:array{available:bool,ready?:bool,revision:string,source:string,url:string}}} Complete disposable Custom CSS view model with independent editor state and an intentionally absent visual preview URL.
     */
    function theme_fixture_custom_css_model(array $overrides = []): array {
        return array_replace(['current_css'=>['active'=>true,'status_label'=>'Custom stylesheet active','preset_label'=>'Uploaded <safe> stylesheet','size_label'=>'1.2 KB','modified_label'=>'2026-10-02 12:00','public_url'=>'/fixture-custom.css'],
            'overrides'=>['state'=>['text'=>'/* saved */ .gallery-card { border-radius: 8px; }','revision'=>hash('sha256','/* saved */ .gallery-card { border-radius: 8px; }'),'url'=>'/fixture-overrides.css'],'ready'=>true,'background'=>['available'=>false,'ready'=>true,'revision'=>str_repeat('0',64),'source'=>'none','url'=>'']],
            'presets'=>[['filename'=>'night.css','label'=>'Night <safe>','selected'=>true],['filename'=>'paper.css','label'=>'Paper','selected'=>false]],
            'labels'=>['keep_current'=>'Keep current custom CSS','skin_hint'=>'Choose a preset only when you intend to replace the active stylesheet.','file_hint'=>'Upload CSS to replace the active stylesheet when you save.']],$overrides);
    }
    /** Render the actual shared form and untouched neighboring tab renderers.
     * @param array<string,mixed> $mediaOverrides Prepared Media scenario values.
     * @param array<string,mixed> $languageOverrides Prepared Language selection values.
     * @return string Complete production Theme form for browser input assertions.
     */
    function theme_fixture_page(array $mediaOverrides = [], array $languageOverrides = []): string {
        $fragments=['appearance'=>theme_fixture_appearance()];
        foreach (['layout','media','language','custom_css'] as $kind) {
            $model=['theme'=>theme_fixture_model()['theme']];
            if ($kind==='media') $model=theme_fixture_media_model($mediaOverrides);
            if ($kind==='layout') $model=theme_fixture_layout_model();
            if ($kind==='language') $model=theme_fixture_language_model($languageOverrides);
            if ($kind==='custom_css') $model=theme_fixture_custom_css_model();
            ob_start(); $renderer='Gallery\\Views\\view_render_admin_theme_'.$kind.'_tab'; $renderer($model); $fragments[$kind]=(string)ob_get_clean();
        }
        $tabs=[]; foreach (['appearance','media','layout','language','custom-css'] as $kind) $tabs[]=['id'=>'admin-theme-tab-'.$kind,'label'=>$kind];
        ob_start(); \Gallery\Views\view_render_admin_theme_page(['tabs'=>$tabs,'csrf_html'=>\Gallery\Core\csrf_field(),'settings_url'=>'/fixture-settings','tab_fragments'=>$fragments]); return (string)ob_get_clean();
    }
    if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
        foreach (\Gallery\Controllers\shared_layout_admin_stylesheet_files() as $stylesheet) echo '<link rel="stylesheet" href="/public/'.\Gallery\Core\e($stylesheet).'">';
        echo theme_fixture_page([], in_array('--language-editor', $argv ?? [], true) ? ['active_subtab'=>'admin-theme-language-subtab-editor'] : []);
    }
}
