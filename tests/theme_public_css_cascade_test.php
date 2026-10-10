<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/theme_public_css_cascade_test.php
 * Module Type: Regression Test
 * Purpose: Verify the actual shared document head loads manual CSS last and only on public pages.
 * Responsibilities: Cover anonymous, authenticated, preview and Admin asset contexts without installation access.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);
namespace Gallery\Core {
    /**
     * Supply a deterministic Theme version without configuration or database access.
     * @param array<string,string|int|bool> $theme Prepared scalar Theme preferences.
     * @return string Stable digest for the fixture's generated CSS URL.
     */
    function theme_cache_key(array $theme): string { return hash('sha256', json_encode($theme, JSON_THROW_ON_ERROR)); }
    /** Supply the disposable mount used by the prepared CSS URL fixture.
     * @return string Fixture mount path.
     */
    function request_script_base_path(): string { return '/sub'; }
    /** Build one internal path from the fixture's actual application mount.
     * @param string $path Application path/query suffix, empty for the mounted home directory.
     * @return string Root-relative fixture URL under /sub.
     */
    function request_mount_url(string $path = ''): string { return request_script_base_path() . '/' . ltrim($path, '/'); }
    /** Model direct HTTP for the prepared CSS URL fixture.
     * @return bool The fixture is not served over TLS.
     */
    function request_is_https(): bool { return false; }
    /** Add preview markers to a known local fixture URL.
     * @param string $url Same-origin fixture URL.
     * @param bool $anonymous Whether anonymous audience is selected.
     * @return string Fixture URL carrying preview state.
     */
    function public_visual_preview_url(string $url, bool $anonymous = false): string {
        $parts = parse_url($url);
        $query = [];
        parse_str((string) ($parts['query'] ?? ''), $query);
        $query['preview'] = 'visual';
        if ($anonymous) $query['view_as'] = 'anonymous';
        return (string) ($parts['path'] ?? '') . '?' . http_build_query($query);
    }
}
namespace {
    require __DIR__ . '/support/theme_appearance_fixture.php';
    /**
     * Require an observable rendered asset invariant.
     * @param bool $condition Required predicate.
     * @param string $message Failure context.
     * @return void Throws on regression.
     */
    function cascade_require(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
    /**
     * Capture actual shared header styles for one disposable visitor context.
     * @param string $bodyClass Canonical public or Admin presentation context.
     * @param array{id:int,username:string}|null $user Disposable authenticated identity or anonymous visitor.
     * @param bool $anonymousPreview Whether the public renderer uses anonymous preview assets.
     * @param string $overrideUrl Prepared content-versioned URL, or empty when no override text exists.
     * @return list<string> Stylesheet URLs in their actual document order.
     */
    function cascade_styles(string $bodyClass, ?array $user, bool $anonymousPreview, string $overrideUrl): array {
        $stylesheetUrls = \Gallery\Controllers\shared_layout_stylesheet_urls(
            $bodyClass,
            $user,
            $anonymousPreview,
            false,
            [],
            '/public/assets/custom.css',
            17
        );
        ob_start();
        try {
            \Gallery\Views\view_render_header('CSS isolation', [
                'body_class'=>$bodyClass, 'user'=>$user, 'anonymous_preview'=>$anonymousPreview,
                'custom_css_url'=>'/public/assets/custom.css', 'custom_css_version'=>17,
                'custom_css_overrides_url'=>$overrideUrl,
                'stylesheet_urls'=>$stylesheetUrls,
                'head_extras'=>'<link rel="stylesheet" href="/fixture-extra.css">',
            ]);
            $html = (string) ob_get_contents();
        } finally { ob_end_clean(); }
        $document = new DOMDocument();
        @$document->loadHTML($html);
        $urls = [];
        foreach ((new DOMXPath($document))->query('//head/link[@rel="stylesheet"]') as $link) $urls[] = $link->getAttribute('href');
        return $urls;
    }
    $overrideUrl = '/public/assets/custom-overrides.css?v=' . hash('sha256', '.manual { color:red; }');
    foreach ([[null,false], [['id'=>1,'username'=>'fixture'],false], [['id'=>1,'username'=>'fixture'],true]] as [$user,$preview]) {
        $urls = cascade_styles('public-page', $user, $preview, $overrideUrl);
        cascade_require(end($urls) === $overrideUrl && count(array_keys($urls, $overrideUrl, true)) === 1, 'every public context must load exactly one digest-versioned override as its final stylesheet');
        $installed = array_search('/public/assets/custom.css?v=17', $urls, true);
        $generated = $mobile = false;
        foreach ($urls as $index=>$url) {
            if (str_contains($url, 'page=theme_css')) $generated = $index;
            if (str_contains($url, 'assets/styles/mobile-gallery.css')) $mobile = $index;
        }
        cascade_require(is_int($installed) && is_int($generated) && is_int($mobile) && $installed < $generated && $generated < $mobile && $mobile < array_search('/fixture-extra.css', $urls, true), 'installed CSS, generated Theme, mobile and trusted extras must precede manual overrides');
    }
    $_SERVER['SCRIPT_NAME'] = '/sub/index.php';
    $_SERVER['HTTP_HOST'] = 'gallery.example';
    $previewStyles = \Gallery\Controllers\shared_layout_stylesheet_urls('public-page', ['id'=>1,'username'=>'fixture'], false, true, [], '/configured/assets/custom.css', 17);
    $anonymousPreviewStyles = \Gallery\Controllers\shared_layout_stylesheet_urls('public-page', ['id'=>1,'username'=>'fixture'], true, true, [], '/configured/assets/custom.css', 17);
    $ordinaryStyles = \Gallery\Controllers\shared_layout_stylesheet_urls('public-page', ['id'=>1,'username'=>'fixture'], false, false, [], '/configured/assets/custom.css', 17);
    cascade_require($previewStyles !== [] && !array_filter($previewStyles, static fn(string $url): bool => !str_contains($url, 'preview=visual') || str_contains($url, 'view_as=anonymous')), 'signed-in preview CSS URLs must carry only the visual marker');
    cascade_require($anonymousPreviewStyles !== [] && !array_filter($anonymousPreviewStyles, static fn(string $url): bool => !str_contains($url, 'preview=visual') || !str_contains($url, 'view_as=anonymous')), 'anonymous preview CSS URLs must carry both protected context markers');
    cascade_require(!array_filter($ordinaryStyles, static fn(string $url): bool => str_contains($url, 'preview=visual') || str_contains($url, 'view_as=anonymous')), 'ordinary public CSS URLs must remain unmarked');
    cascade_require(!in_array($overrideUrl, cascade_styles('admin-page', null, false, $overrideUrl), true), 'Admin head must refuse the override layer even when a model supplies its URL');
    cascade_require(!array_filter(cascade_styles('public-page', null, false, ''), static fn(string $url): bool => str_contains($url, 'custom-overrides.css')), 'empty overrides must omit their public link');
    echo "PASS public manual CSS cascade and Admin isolation\n";
}
