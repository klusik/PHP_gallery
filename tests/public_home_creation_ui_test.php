<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/public_home_creation_ui_test.php
 * Module Type: Regression Test
 * Purpose: Verify root creation authorization, catalog onboarding and refreshable home markup.
 * Responsibilities:
 *   - Verify authorization and bounded persisted-catalog onboarding decisions.
 *   - Preserve delegated creation links and refreshable empty home markup.
 * Author: Rudolf Klusal
 * Notes: Exercise real controllers, catalog service, models and views against isolated dependencies.
 */
declare(strict_types=1);

namespace Gallery\Tests\HomeCreation {
    /** Isolated persisted catalog and request dependencies for the home creation contract. */
    final class Fixture
    {
        /** @var bool Whether the current request belongs to an administrator. */
        public static bool $admin = true;
        /** @var bool Whether anonymous preview suppresses administrator controls. */
        public static bool $preview = false;
        /** @var bool Effective inline administration capability. */
        public static bool $inline = true;
        /** @var string Optional Smart Gallery table observation. */
        public static string $schema = 'available';
        /** @var string Table whose read must fail, or an empty string. */
        public static string $failTable = '';
        /** @var list<array{id:int,parent_id:int|null,visibility:string}> Persisted physical rows, including invisible descendants. */
        public static array $physical = [];
        /** @var list<array{id:int,visibility:string,enabled:int}> Persisted Smart definitions, including disabled rows. */
        public static array $smart = [];
        /** @var list<string> Executed catalog statements. */
        public static array $queries = [];
        /** @var int Number of optional table inspections. */
        public static int $inspections = 0;
        /** @var list<array{level:string,event:string,message:string,context:array{capability:string}}> Bounded diagnostic events. */
        public static array $logs = [];

        /**
         * Reset a request to an authorized administrator with a confirmed empty catalog.
         * @return void Clear fixture state.
         */
        public static function reset(): void
        {
            self::$admin = self::$inline = true;
            self::$preview = false;
            self::$schema = 'available';
            self::$failTable = '';
            self::$physical = self::$smart = self::$queries = self::$logs = [];
            self::$inspections = 0;
        }
    }

    /** A minimal model database double enforcing bounded, unconditional catalog reads. */
    final class CatalogDatabase
    {
        /**
         * Execute only the actual model existence probes; reject filtered or unbounded regressions.
         * @param string $sql Model-owned statement.
         * @return CatalogStatement Existence result for the requested persisted table.
         */
        public function query(string $sql): CatalogStatement
        {
            Fixture::$queries[] = $sql;
            $table = match ($sql) {
                'SELECT 1 FROM galleries LIMIT 1' => 'galleries',
                'SELECT 1 FROM smart_galleries LIMIT 1' => 'smart_galleries',
                default => throw new \RuntimeException('Unexpected catalog statement: ' . $sql),
            };
            if (Fixture::$failTable === $table) {
                throw new \RuntimeException('Private database exception must not escape into diagnostics.');
            }
            return new CatalogStatement(($table === 'galleries' ? Fixture::$physical : Fixture::$smart) !== []);
        }
    }

    /** Existence result compatible with the models' fetchColumn contract. */
    final class CatalogStatement
    {
        /**
         * Initialize the result of one persisted catalog existence probe.
         * @param bool $exists Whether the persisted table contains a row.
         * @return void Store the existence result.
         */
        public function __construct(private bool $exists) {}

        /**
         * Return the model-compatible scalar existence result.
         * @return int|false Return a constant projection or the empty-result sentinel.
         */
        public function fetchColumn(): int|false
        {
            return $this->exists ? 1 : false;
        }
    }

    /**
     * Fail a focused regression assertion.
     * @param bool $condition Expected behavior.
     * @param string $message Failure explanation.
     * @return void Throw for a contract regression.
     */
    function check(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new \RuntimeException($message);
        }
    }

    /**
     * Render a prepared home page through the real presentation layer.
     * @param array{placement:string,title:string,url:string,panel_url:string}|null $action Prepared creation link.
     * @param bool $onboarding Controller-prepared first-gallery decision.
     * @param bool $search Whether the optional search control is enabled.
     * @return string Captured complete home markup.
     */
    function render(?array $action, bool $onboarding, bool $search = true): string
    {
        ob_start();
        \Gallery\Views\view_render_public_gallery_home([
            'site_name' => 'Fixture gallery',
            'canonical_url' => '/',
            'gallery_count' => 0,
            'creation_action' => $action,
            'show_first_gallery' => $onboarding,
            'search_bar' => ['enabled' => $search, 'search_url' => '/search', 'aria_label' => 'Search'],
        ]);
        return (string) ob_get_clean();
    }
}

namespace Gallery\Core {
    /**
     * Resolve the isolated request identity.
     * @return array{id:int}|null Return an isolated administrator identity or anonymous request.
     */
    function current_user(): ?array { return \Gallery\Tests\HomeCreation\Fixture::$admin ? ['id' => 1] : null; }
    /**
     * Resolve whether the fixture request is an anonymous preview.
     * @return bool Return the anonymous preview state.
     */
    function admin_anonymous_preview_active(): bool { return \Gallery\Tests\HomeCreation\Fixture::$preview; }
    /**
     * Supply the isolated persisted catalog dependency.
     * @return \Gallery\Tests\HomeCreation\CatalogDatabase Return the model database double.
     */
    function db(): \Gallery\Tests\HomeCreation\CatalogDatabase { return new \Gallery\Tests\HomeCreation\CatalogDatabase(); }
    /**
     * Escape presentation strings through the fixture HTML boundary.
     * @param string $text Presentation text.
     * @return string Escaped HTML text.
     */
    function e(string $text): string { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
    /**
     * Open the isolated page shell for the real home view.
     * @param string $title Page title.
     * @return void Emit the isolated page opening.
     */
    function render_header(string $title): void { echo '<main>'; }
    /**
     * Close the isolated page shell.
     * @return void Emit the isolated page closing.
     */
    function render_footer(): void { echo '</main>'; }
    /**
     * Construct deterministic route URLs from semantic parameters.
     * @param string $route Route name.
     * @param array<string,int> $query Semantic route parameters.
     * @return string Deterministic fallback route.
     */
    function url_for(string $route, array $query = []): string { return '/index.php?' . http_build_query(['page' => $route] + $query); }
}

namespace Gallery\Services {
    /**
     * Resolve effective fixture capability policy with Smart Galleries deliberately off.
     * @param string $key Capability key.
     * @return bool Effective fixture policy.
     */
    function feature_capability_effective_enabled(string $key): bool { return $key === 'inline_administration' && \Gallery\Tests\HomeCreation\Fixture::$inline; }
    /**
     * Observe optional catalog storage and track the inspection budget.
     * @param string $table Optional table identifier.
     * @return array{state:string} Three-state inspection observation.
     */
    function schema_inspection_table(string $table): array
    {
        \Gallery\Tests\HomeCreation\Fixture::$inspections++;
        return ['state' => \Gallery\Tests\HomeCreation\Fixture::$schema];
    }
    /**
     * Identify a confirmed absent optional table.
     * @param array{state:string} $result Schema observation.
     * @return bool Whether absence was confirmed.
     */
    function schema_inspection_is_missing(array $result): bool { return $result['state'] === 'missing'; }
    /**
     * Identify verified available optional storage.
     * @param array{state:string} $result Schema observation.
     * @return bool Whether storage was verified.
     */
    function schema_inspection_is_available(array $result): bool { return $result['state'] === 'available'; }
    /**
     * Capture bounded onboarding diagnostics for disclosure assertions.
     * @param string $level Severity.
     * @param string $event Stable event identifier.
     * @param string $message Safe diagnostic.
     * @param array{capability:string} $context Bounded identifiers.
     * @return void Capture the diagnostic.
     */
    function admin_log_event(string $level, string $event, string $message, array $context): void
    {
        \Gallery\Tests\HomeCreation\Fixture::$logs[] = compact('level', 'event', 'message', 'context');
    }
    /**
     * Resolve presentation labels using deterministic English fallback text.
     * @param string $key Translation key.
     * @param string $fallback English fallback.
     * @param array<string,int|string> $parameters Replacements.
     * @return string Resolved fixture label.
     */
    function t(string $key, string $fallback, array $parameters = []): string
    {
        foreach ($parameters as $name => $value) {
            $fallback = str_replace('{' . $name . '}', (string) $value, $fallback);
        }
        return $fallback;
    }
}

namespace Gallery\Tests\HomeCreation {
    require_once __DIR__ . '/../app/models/galleries.php';
    require_once __DIR__ . '/../app/models/smart_galleries.php';
    require_once __DIR__ . '/../app/services/gallery_lookup.php';
    require_once __DIR__ . '/../app/controllers/public_gallery_cards.php';
    require_once __DIR__ . '/../app/controllers/public_gallery_home.php';
    require_once __DIR__ . '/../app/views/public_gallery_cards.php';
    require_once __DIR__ . '/../app/views/public_search.php';
    require_once __DIR__ . '/../app/views/public_content_widgets.php';
    require_once __DIR__ . '/../app/views/public_gallery_pages.php';

    Fixture::reset();
    $empty = \Gallery\Controllers\public_home_admin_creation_view_model(0);
    check($empty['show_first_gallery'] && $empty['creation_action'] !== null, 'Confirmed empty catalog must offer first-gallery creation.');
    check(count(Fixture::$queries) === 2 && Fixture::$inspections === 1, 'Empty catalog verification must use exactly two bounded reads and one schema observation.');
    $root = $empty['creation_action'];
    check($root['url'] === '/index.php?page=admin_new_gallery' && $root['panel_url'] === '/index.php?page=admin_new_gallery&panel=1', 'Root creation must omit a parent ID in both route forms.');

    foreach ([['id' => 4, 'parent_id' => 3, 'visibility' => 'public'], ['id' => 5, 'parent_id' => null, 'visibility' => 'private']] as $physicalRow) {
        Fixture::reset();
        Fixture::$physical = [$physicalRow];
        check(!\Gallery\Controllers\public_home_admin_creation_view_model(0)['show_first_gallery'], 'Persisted nested or private physical galleries must suppress onboarding.');
        check(count(Fixture::$queries) === 1 && Fixture::$inspections === 0, 'A physical row must short-circuit optional storage inspection.');
    }
    foreach ([['id' => 7, 'visibility' => 'private', 'enabled' => 1], ['id' => 8, 'visibility' => 'public', 'enabled' => 0]] as $smartRow) {
        Fixture::reset();
        Fixture::$smart = [$smartRow];
        check(!\Gallery\Controllers\public_home_admin_creation_view_model(0)['show_first_gallery'], 'Private or disabled Smart definitions must suppress onboarding even when Smart capability is off.');
        check(count(Fixture::$queries) === 2, 'Smart catalog existence must be verified regardless of feature policy.');
    }

    Fixture::reset();
    Fixture::$schema = 'missing';
    check(\Gallery\Controllers\public_home_admin_creation_view_model(0)['show_first_gallery'], 'Confirmed absent optional Smart storage permits empty-catalog onboarding.');
    check(count(Fixture::$queries) === 1, 'Missing optional storage must not be queried.');

    foreach (['unknown', 'physical_failure', 'smart_failure'] as $failure) {
        Fixture::reset();
        Fixture::$schema = $failure === 'unknown' ? 'unknown' : 'available';
        Fixture::$failTable = match ($failure) { 'physical_failure' => 'galleries', 'smart_failure' => 'smart_galleries', default => '' };
        $result = \Gallery\Controllers\public_home_admin_creation_view_model(0);
        check(!$result['show_first_gallery'] && $result['creation_action'] !== null, 'Unknown or failed catalog reads must hide onboarding while retaining authorized creation.');
        check(count(Fixture::$logs) === 1 && Fixture::$logs[0]['event'] === 'gallery.onboarding.catalog_unavailable', 'Catalog uncertainty must emit one bounded diagnostic.');
        check(Fixture::$logs[0]['context'] === ['capability' => 'gallery_catalog'] && !str_contains(json_encode(Fixture::$logs, JSON_THROW_ON_ERROR), 'Private database exception'), 'Catalog diagnostics must not expose database errors.');
    }

    foreach (['anonymous', 'preview', 'capability_off'] as $request) {
        Fixture::reset();
        Fixture::$admin = $request !== 'anonymous';
        Fixture::$preview = $request === 'preview';
        Fixture::$inline = $request !== 'capability_off';
        $result = \Gallery\Controllers\public_home_admin_creation_view_model(0);
        check($result === ['creation_action' => null, 'show_first_gallery' => false], 'Unauthorized request must not receive creation or onboarding controls.');
        check(Fixture::$queries === [] && Fixture::$inspections === 0, 'Unauthorized request must not inspect the catalog.');
        $html = render($result['creation_action'], $result['show_first_gallery']);
        check(!str_contains($html, 'data-gallery-side-panel-link') && !str_contains($html, 'data-public-home-first-gallery'), 'Unauthorized home markup must omit creation affordances.');
    }

    Fixture::reset();
    $populated = \Gallery\Controllers\public_home_admin_creation_view_model(1);
    check(!$populated['show_first_gallery'] && $populated['creation_action'] !== null && Fixture::$queries === [] && Fixture::$inspections === 0, 'Visible galleries must suppress onboarding without extra catalog reads.');
    $child = \Gallery\Controllers\public_gallery_admin_creation_view_model(['id' => 42, 'title' => 'Parent'], 'card');
    check($child !== null && str_contains($child['url'], 'parent_id=42') && str_contains($child['panel_url'], 'parent_id=42') && str_contains($child['panel_url'], 'panel=1'), 'Existing child creation must retain its parent in fallback and panel URLs.');

    $html = render($root, true);
    check(substr_count($html, 'data-admin-side-panel-workflow="create"') === 2 && str_contains($html, 'Add your first gallery'), 'Empty administrator home must render a compact create action and a labeled onboarding action.');
    check(strpos($html, 'data-gallery-side-panel-link') < strpos($html, 'data-public-home-search '), 'Root plus action must precede public search.');
    check(!str_contains($html, 'public-admin-edit') && !str_contains($html, 'public-admin-delete') && !str_contains($html, 'parent_id'), 'Root toolbar must not offer edit/delete or a parent context.');
    foreach (['data-gallery-side-panel-link', 'data-admin-side-panel-workflow="create"', 'data-gallery-side-panel-url="/index.php?page=admin_new_gallery&amp;panel=1"', 'href="/index.php?page=admin_new_gallery"'] as $attribute) {
        check(substr_count($html, $attribute) === 2, 'Both root controls must preserve delegated creation and direct-page fallback: ' . $attribute);
    }
    $document = new \DOMDocument();
    $document->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
    $xpath = new \DOMXPath($document);
    check($xpath->query('//div[contains(concat(" ", normalize-space(@class), " "), " gallery-list-frame ")]//*[@data-public-gallery-index]')->length === 1, 'Empty home verification marker must be nested inside the persistent replacement frame.');
    check($xpath->query('//*[@data-public-home-first-gallery]')->length === 1, 'Onboarding must belong to the refreshable empty home.');
    $standalone = render($root, false, false);
    check(str_contains($standalone, 'public-home-toolbar') && substr_count($standalone, 'data-admin-side-panel-workflow="create"') === 1 && !str_contains($standalone, 'data-public-home-search'), 'Search disabled must retain a standalone compact creation action.');
    check(!str_contains(render($root, false), 'data-public-home-first-gallery'), 'Prepared onboarding false must suppress the CTA.');
    $anonymous = render(null, true, false);
    check(!str_contains($anonymous, 'data-public-home-first-gallery') && str_contains($anonymous, 'gallery-list-frame') && str_contains($anonymous, 'data-public-gallery-index'), 'Absent action must suppress onboarding while retaining the empty refresh frame and marker.');

    ob_start();
    \Gallery\Views\view_render_public_gallery_admin_add_child_link($child);
    $childHtml = (string) ob_get_clean();
    check(str_contains($childHtml, 'parent_id=42') && str_contains($childHtml, 'data-admin-side-panel-workflow="create"') && str_contains($childHtml, 'public-admin-add-gallery-button-card'), 'Shared child link must preserve placement and delegated workflow.');
    fwrite(STDOUT, "Public home creation UI tests passed.\n");
}
