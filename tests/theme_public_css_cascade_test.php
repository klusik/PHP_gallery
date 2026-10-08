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
        ob_start();
        try {
            \Gallery\Views\view_render_header('CSS isolation', [
                'body_class'=>$bodyClass, 'user'=>$user, 'anonymous_preview'=>$anonymousPreview,
                'custom_css_url'=>'/public/assets/custom.css', 'custom_css_version'=>17,
                'custom_css_overrides_url'=>$overrideUrl,
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
    cascade_require(!in_array($overrideUrl, cascade_styles('admin-page', null, false, $overrideUrl), true), 'Admin head must refuse the override layer even when a model supplies its URL');
    cascade_require(!array_filter(cascade_styles('public-page', null, false, ''), static fn(string $url): bool => str_contains($url, 'custom-overrides.css')), 'empty overrides must omit their public link');
    echo "PASS public manual CSS cascade and Admin isolation\n";
}
