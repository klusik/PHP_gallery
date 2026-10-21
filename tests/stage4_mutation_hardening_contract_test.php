<?php

/**
 * Project: PHP Gallery
 * Module Type: Regression Test
 * Purpose: Protect hardened persistent mutation boundaries.
 * Responsibilities:
 *   - Check shared completion contracts and in-place workflow guarantees.
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/stage4_mutation_hardening_contract_test.php
 *
 * Author:
 *   Rudolf Klusal
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 *
 * Notes:
 *   - Keep comments and docstrings intact when modifying this file.
 */
declare(strict_types=1);

require_once __DIR__ . '/support/module_source.php';
require_once dirname(__DIR__) . '/scripts/admin_import_contracts.php';

/**
 * Focused Stage 4 source-boundary contracts for mutation synchronization hardening.
 *
 * Runtime behavior for operation generations, stale-cache rejection, aborts, and
 * postcondition evaluation is covered by admin_mutation_stage4_hardening_test.mjs.
 * These assertions protect the server-render metadata and workflow wiring that the
 * browser coordinator depends on but cannot discover from an isolated DOM fixture.
 */

$root = dirname(__DIR__);

/**
 * Read one repository source file or fail with a concise diagnostic.
 * @param string $relative Repository-relative source module path.
 * @return string Complete module source including owned part files.
 */
function stage4_source(string $relative): string
{
    global $root;
    // module_source() also covers controllers that are split into part files.
    $source = module_source($root . '/' . $relative);
    if (!is_string($source) || $source === '') {
        throw new RuntimeException('Could not read ' . $relative);
    }
    return $source;
}

/**
 * Throw a concise exception when one Stage 4 boundary is absent.
 * @param bool $condition Observed contract result.
 * @param string $message Safe assertion label describing the protected boundary.
 * @return void Returns normally for a satisfied contract, otherwise throws.
 */
function stage4_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$helper = stage4_source('app/helpers_mutation.php');
foreach ([
    'gallery_membership',
    'image_order',
    'image_updated_at',
    'gallery_image_revision',
    'smart_gallery_presence',
] as $postconditionType) {
    stage4_expect(
        str_contains($helper, "'" . $postconditionType . "'"),
        'Canonical mutation helper is missing Stage 4 postcondition type '
            . $postconditionType
            . '.',
    );
}
stage4_expect(
    str_contains($helper, "'image_tags'")
        && str_contains($helper, "'tag_ids'")
        && str_contains($helper, "'tag_names'"),
    'Targeted photo-tag mutation must expose its authoritative direct-tag postcondition.',
);
$inlineImageController = stage4_source(
    'app/controllers/admin_public_inline.php',
);
$quickTagStart = strpos(
    $inlineImageController,
    'function admin_public_update_image_quick_tag',
);
$quickTagEnd = $quickTagStart === false
    ? false
    : strpos($inlineImageController, 'function cms_admin_public_update_image', $quickTagStart);
$quickTagAction = $quickTagStart !== false && $quickTagEnd !== false
    ? substr($inlineImageController, $quickTagStart, $quickTagEnd - $quickTagStart)
    : '';
stage4_expect(
    $quickTagAction !== ''
        && str_contains($quickTagAction, "'image.tag'")
        && str_contains($quickTagAction, "'image_tags'")
        && str_contains($quickTagAction, "'source_gallery_id'")
        && str_contains($quickTagAction, 'mutate_image_tag(')
        && !str_contains($quickTagAction, 'sync_entity_tags(')
        && !str_contains($quickTagAction, 'image_editor_update_fields('),
    'Photo quick-tag must use one targeted physical-image mutation and authoritative tag snapshot.',
);
$quickTagRoute = strpos(
    $inlineImageController,
    'if (in_array($action, [\'quick_tag_add\', \'quick_tag_remove\'], true))',
);
$adminGate = strpos($inlineImageController, 'require_admin();');
$csrfGate = strpos($inlineImageController, 'verify_csrf();', $adminGate ?: 0);
stage4_expect(
    $quickTagRoute !== false
        && $adminGate !== false
        && $csrfGate !== false
        && $quickTagRoute > $csrfGate,
    'Photo quick-tag dispatch must remain behind the canonical Admin and CSRF gates.',
);
stage4_expect(
    str_contains($helper, "'postcondition' => " . '$postcondition'),
    'Gallery/tag contexts must serialize explicit null postconditions instead of omitting the field.',
);
stage4_expect(
    str_contains($helper, 'admin_mutation_gallery_context_count'),
    'Physical gallery membership verification must use an authoritative full-context count.',
);
stage4_expect(
    str_contains($helper, 'admin_mutation_gallery_membership_postcondition'),
    'Physical gallery workflows must share one pagination-safe membership postcondition builder.',
);

$galleryPage =
    stage4_source('app/controllers/public_gallery_page.php')
    . "\n"
    . stage4_source('app/views/public_gallery_pages.php');
foreach ([
    'data-admin-mutation-canonical-url',
    'data-public-context-gallery-id',
    'data-public-subgallery-total-count',
    'data-public-subgallery-revision',
    'data-public-gallery-total-pages',
    'data-public-image-total-count',
    'data-public-image-revision',
    'data-public-image-total-pages',
    'data-public-image-updated-at',
    'data-public-smart-gallery-count',
] as $metadata) {
    stage4_expect(
        str_contains($galleryPage, $metadata),
        'Gallery render is missing Stage 4 ownership/state metadata '
            . $metadata
            . '.',
    );
}

$galleryHome =
    stage4_source('app/controllers/public_gallery_home.php')
    . "\n"
    . stage4_source('app/views/public_gallery_pages.php');
foreach ([
    'data-public-root-gallery-count',
    'data-public-root-gallery-revision',
    'data-public-gallery-page',
    'data-public-gallery-total-pages',
    'data-public-root-smart-gallery-count',
    'data-admin-mutation-canonical-url',
] as $metadata) {
    stage4_expect(
        str_contains($galleryHome, $metadata),
        'Root gallery index is missing Stage 4 ownership/state metadata '
            . $metadata
            . '.',
    );
}

$tags =
    stage4_source('app/controllers/public_tags.php')
    . "\n"
    . stage4_source('app/views/public_tags.php');
stage4_expect(
    str_contains($tags, 'data-admin-mutation-canonical-url'),
    'Tag landing pages must expose their canonical refresh owner URL.',
);

$cards =
    stage4_source('app/controllers/public_gallery_cards.php')
    . "\n"
    . stage4_source('app/views/public_gallery_cards.php');
stage4_expect(
    str_contains($cards, 'data-smart-gallery-placement'),
    'Smart Gallery cards must expose their rendered placement.',
);
stage4_expect(
    str_contains($cards, 'data-smart-gallery-placement-order'),
    'Smart Gallery cards must expose their rendered placement order.',
);

$reorder = stage4_source('app/controllers/admin_images_reorder.php');
stage4_expect(
    str_contains($reorder, "admin_mutation_postcondition('image_order'"),
    'Image reorder must verify the persisted order, not merely refresh the gallery.',
);

$editActions = stage4_source(
    'app/controllers/admin_galleries_edit_actions.php',
);
stage4_expect(
    str_contains($editActions, 'gallery_lightbox_state_summary')
        && str_contains($editActions, "'revision' => " . '$imageStateRevision'),
    'Image edit workflows must expose gallery-wide state for pagination-safe verification.',
);
stage4_expect(
    str_contains(
        $editActions,
        'admin_mutation_gallery_membership_postcondition',
    ),
    'Reparent/move workflows must verify source and destination membership with full counts.',
);

$metadataOrganizer = stage4_source(
    'app/controllers/admin_galleries_edit_metadata.php',
);
stage4_expect(
    str_contains(
        $metadataOrganizer,
        "admin_mutation_postcondition('gallery_image_count'",
    ),
    'Metadata Organizer source/destination contexts must verify authoritative image counts.',
);

$renamer = stage4_source('app/controllers/admin_galleries_edit_page.php');
stage4_expect(
    str_contains(
        $renamer,
        "admin_mutation_postcondition('gallery_image_revision'",
    ),
    'Media Renamer must verify a gallery-wide image revision so off-page renames remain observable.',
);

$smartGalleries = stage4_source('app/controllers/smart_galleries.php');
stage4_expect(
    str_contains($smartGalleries, 'smart_gallery_admin_context_postcondition'),
    'Smart Gallery mutations must share an authoritative placement/presence postcondition builder.',
);
stage4_expect(
    str_contains(
        $smartGalleries,
        "admin_mutation_postcondition('smart_gallery_presence'",
    ),
    'Smart Gallery mutations must verify placement/presence state.',
);

$thumbnails = stage4_source('app/controllers/admin_thumbnails.php');
stage4_expect(
    preg_match(
        '/admin_mutation_public_gallery_context\([^;]*?,\s*null\s*,?\s*\)/s',
        $thumbnails,
    ) === 1,
    'Thumbnail repair is the intentional no-immediate-postcondition exception and must declare null explicitly.',
);

$coordinator = stage4_source(
    'public/assets/gallery-modules/admin-mutation-completion.js',
);
foreach ([
    'beginAdminMutationOperation',
    'adminMutationOperationContextIsCurrent',
    'adminMutationPanelGuard',
    'mutationActiveRefreshByContext',
    'new AbortController()',
    "lastStatus = 'stale-response'",
    'adminMutationDiagnosticRecord',
    'safeAdminMutationDiagnosticPath',
    "lastStatus = 'refreshed-unverified'",
    'data-admin-mutation-canonical-url',
] as $contract) {
    stage4_expect(
        str_contains($coordinator, $contract),
        'Coordinator is missing Stage 4 hardening contract: ' . $contract,
    );
}

$sidePanel = stage4_source('public/assets/gallery-modules/admin-side-panel.js');
stage4_expect(
    str_contains($sidePanel, 'completionGuard?.signal || undefined'),
    'Side-panel refresh fetches must participate in coordinator aborts.',
);
stage4_expect(
    str_contains($sidePanel, 'completionGuard.isCurrent()'),
    'Side-panel replacements must reject superseded operation generations.',
);
stage4_expect(
    \Gallery\Tools\AdminImports\admin_import_repository_failures($root) === [],
    'Active Admin module consumers must share valid target identities and invalidate every changed payload/importer chain.',
);

// Exercise the actual evaluator with inert source records. Historical revision
// spelling and harmless quote/spacing choices carry no expected value here.
$importGroups = [
    'fixture/coordinator.js' => ['fixture/first.js', 'fixture/second.js'],
    'fixture/first.js' => ['fixture/entry.js'],
];
$importBase = [
    'fixture/coordinator.js' => 'export const value = 1;',
    'fixture/first.js' => "import {value} from './coordinator.js?v=one';",
    'fixture/second.js' => 'import { value } from "./coordinator.js?v=one";',
    'fixture/entry.js' => "export {value} from './first.js?v=one';",
];
$evaluateImports = static fn(
    array $sources,
    ?array $base = null,
): array => \Gallery\Tools\AdminImports\admin_import_contract_failures(
    $sources,
    $importGroups,
    $base ?? $importBase,
);
stage4_expect(
    $evaluateImports($importBase) === [],
    'Existing valid consumer relationships must pass.',
);
$coordinated = $importBase;
$coordinated['fixture/coordinator.js'] = 'export const value = 2;';
$coordinated['fixture/first.js'] = 'import /* supported formatting */ { value } from "./coordinator.js?v=two";';
$coordinated['fixture/second.js'] = "import {value} from './coordinator.js?v=two';";
$coordinated['fixture/entry.js'] = "export {value} from './first.js?v=two';";
stage4_expect(
    $evaluateImports($coordinated) === [],
    'A coordinated revision bump and supported equivalent formatting must pass.',
);
$negativeImports = [];
$negativeImports['different module URL identities'] = array_replace($importBase, [
    'fixture/second.js' => "import {value} from './coordinator.js?v=other';",
]);
$negativeImports['missing, empty or duplicate cache revision'] = array_replace($importBase, [
    'fixture/first.js' => "import {value} from './coordinator.js?v=';",
]);
$negativeImports['wrong resolved module target'] = array_replace($importBase, [
    'fixture/first.js' => "import {value} from '../wrong/coordinator.js?v=one';",
]);
$negativeImports['missing required module target'] = array_diff_key($importBase, [
    'fixture/coordinator.js' => true,
]);
$negativeImports['missing or duplicate required import'] = array_replace($importBase, [
    'fixture/first.js' =>
        $importBase['fixture/first.js'] . $importBase['fixture/first.js'],
]);
$negativeImports['changed payload has unchanged cache identity'] = array_replace($importBase, [
    'fixture/coordinator.js' => 'export const value = 2;',
]);
$negativeImports['upstream chain'] = array_replace($coordinated, [
    'fixture/entry.js' => $importBase['fixture/entry.js'],
]);
$negativeImports['unsupported dynamic import syntax'] = array_replace($importBase, [
    'fixture/first.js' => "const url = './coordinator.js?v=one'; import(url);",
]);
$negativeImports['unsupported import attributes'] = array_replace($importBase, [
    'fixture/first.js' => "import {value} from './coordinator.js?v=one' with {type: 'json'};",
]);
foreach ($negativeImports as $reason => $sources) {
    $failures = $evaluateImports($sources);
    stage4_expect(
        $failures !== [] && str_contains(implode(' ', $failures), $reason),
        'Import evaluator must reject the named regression: ' . $reason,
    );
}
stage4_expect(
    \Gallery\Tools\AdminImports\admin_import_contract_failures(
        $importBase,
        $importGroups,
        null,
    ) !== [],
    'An unavailable immutable import comparison must never pass.',
);

echo "Stage 4 mutation hardening contracts passed.\n";
