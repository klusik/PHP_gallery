<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/smart_galleries.php
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
/**
 * Central Smart Gallery rule, persistence, compilation, and query service.
 *
 * Persisted rules are data, never SQL. This module is the only boundary that
 * maps allowlisted fields and operators to parameterized database predicates.
 */

declare(strict_types=1);

namespace Gallery\Services;

use InvalidArgumentException;
use function Gallery\Core\db;
use function Gallery\Core\now_sql;
use function Gallery\Core\slugify;

const SMART_GALLERY_RULE_VERSION = 1;
const SMART_GALLERY_MAX_DEPTH = 5;
const SMART_GALLERY_MAX_CONDITIONS = 50;
const SMART_GALLERY_PRESENTATION_VERSION = 1;
const SMART_GALLERY_QUERY_MAX_PAGE_SIZE = 200;
const SMART_GALLERY_LIGHTBOX_MAX_WINDOW = 80;
const SMART_GALLERY_ATTACHMENT_MAX_PER_PARENT = 100;
const SMART_GALLERY_GRAPH_MAX_DEPTH = 64;
const SMART_GALLERY_GRAPH_MAX_EXPANDED_NODES = 4096;
const SMART_GALLERY_GRAPH_MAX_EXPANDED_SMART_NODES = 1024;
const SMART_GALLERY_GRAPH_MAX_EDGES = 20000;
const SMART_GALLERY_GRAPH_MAX_SOURCE_ROWS = 50000;
const SMART_GALLERY_CARD_SUMMARY_BATCH_SIZE = 20;

/** Return the canonical inherited preference values for Smart Galleries before runtime capability suppression. */
function smart_gallery_presentation_defaults(): array
{
    $pagination = pagination_global_settings(['listing' => 'smart_gallery']);

    return [
        'grid_columns' => (int) $pagination['columns'],
        'grid_rows' => (int) $pagination['rows'],
        'pagination_enabled' => !empty($pagination['enabled']),
        'thumbnail_min_size' => null,
        'thumbnail_max_size' => null,
        'thumbnail_rendering_mode' => public_thumbnail_rendering_mode(),
        'card_layout' => theme_gallery_description_layout(),
        'metadata_visible' => true,
        'lightbox_enabled' => true,
        'lightbox_browsing_mode' => theme_lightbox_browsing_mode(),
        'slideshow_enabled' => true,
        'download_enabled' => true,
        'voting_enabled' => true,
        'source' => 'theme',
    ];
}

/** Return current site-wide capability masters that may suppress Smart Gallery preferences at runtime. */
function smart_gallery_presentation_master_status(): array
{
    $capabilityEnabled = static function (string $capability): bool {
        if (function_exists('Gallery\\Services\\feature_capability_effective_enabled')) {
            return feature_capability_effective_enabled($capability);
        }
        return !function_exists('Gallery\\Services\\feature_flag_enabled') || feature_flag_enabled($capability);
    };

    return [
        'lightbox' => $capabilityEnabled('lightbox_modes'),
        'downloads' => $capabilityEnabled('downloads'),
        'voting' => $capabilityEnabled('image_voting'),
    ];
}

/** Normalize a stored or submitted Smart Gallery presentation document. */
function smart_gallery_normalize_presentation(mixed $value): array
{
    if (is_string($value)) {
        if (trim($value) === '') return [];
        try {
            $value = json_decode($value, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }
    }
    if (!is_array($value)) return [];
    if (isset($value['version']) && (int) $value['version'] !== SMART_GALLERY_PRESENTATION_VERSION) return [];

    $normalized = [];
    if (array_key_exists('grid_columns', $value) && $value['grid_columns'] !== null && $value['grid_columns'] !== '') {
        $columns = filter_var($value['grid_columns'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => CMS_PAGINATION_MAX_COLUMNS]]);
        if ($columns !== false) $normalized['grid_columns'] = (int) $columns;
    }
    if (array_key_exists('grid_rows', $value) && $value['grid_rows'] !== null && $value['grid_rows'] !== '') {
        $rows = filter_var($value['grid_rows'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => CMS_PAGINATION_MAX_ROWS]]);
        if ($rows !== false) $normalized['grid_rows'] = (int) $rows;
    }
    foreach (['pagination_enabled', 'metadata_visible', 'lightbox_enabled', 'slideshow_enabled', 'download_enabled', 'voting_enabled'] as $booleanKey) {
        if (array_key_exists($booleanKey, $value) && $value[$booleanKey] !== null && $value[$booleanKey] !== 'inherit') {
            $normalized[$booleanKey] = filter_var($value[$booleanKey], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
            if ($normalized[$booleanKey] === null) unset($normalized[$booleanKey]);
        }
    }
    foreach (['thumbnail_min_size', 'thumbnail_max_size'] as $boundKey) {
        if (array_key_exists($boundKey, $value) && $value[$boundKey] !== null && $value[$boundKey] !== '' && (int) $value[$boundKey] !== 0) {
            $bound = thumbnail_bound_post_value($value[$boundKey]);
            if ($bound !== null) $normalized[$boundKey] = $bound;
        }
    }
    if (isset($normalized['thumbnail_min_size'], $normalized['thumbnail_max_size']) && $normalized['thumbnail_min_size'] > $normalized['thumbnail_max_size']) {
        [$normalized['thumbnail_min_size'], $normalized['thumbnail_max_size']] = [$normalized['thumbnail_max_size'], $normalized['thumbnail_min_size']];
    }
    if (array_key_exists('thumbnail_rendering_mode', $value) && !in_array($value['thumbnail_rendering_mode'], [null, '', 'inherit'], true)) {
        $mode = is_string($value['thumbnail_rendering_mode']) ? trim($value['thumbnail_rendering_mode']) : '';
        if (in_array($mode, public_thumbnail_rendering_modes(), true)) $normalized['thumbnail_rendering_mode'] = $mode;
    }
    if (array_key_exists('card_layout', $value) && !in_array($value['card_layout'], [null, '', 'inherit'], true)) {
        $layout = gallery_description_layout_storage_value($value['card_layout']);
        if ($layout !== null) $normalized['card_layout'] = $layout;
    }
    if (array_key_exists('lightbox_browsing_mode', $value) && !in_array($value['lightbox_browsing_mode'], [null, '', 'inherit'], true)) {
        $mode = gallery_lightbox_browsing_mode_storage_value($value['lightbox_browsing_mode']);
        if ($mode !== null) $normalized['lightbox_browsing_mode'] = $mode;
    }
    return $normalized;
}

/** Return Smart Gallery presentation preferences without applying runtime capability masters. */
function smart_gallery_presentation_preferences(array $gallery): array
{
    $defaults = smart_gallery_presentation_defaults();
    $presentationValue = array_key_exists('presentation', $gallery) ? $gallery['presentation'] : ($gallery['presentation_json'] ?? []);
    $overrides = smart_gallery_normalize_presentation($presentationValue);
    $preferences = array_merge($defaults, $overrides);
    $preferences['grid_columns'] = pagination_dimension_value($preferences['grid_columns'], (int) $defaults['grid_columns'], CMS_PAGINATION_MAX_COLUMNS);
    $preferences['grid_rows'] = pagination_dimension_value($preferences['grid_rows'], (int) $defaults['grid_rows'], CMS_PAGINATION_MAX_ROWS);
    $preferences['items_per_page'] = (int) $preferences['grid_columns'] * (int) $preferences['grid_rows'];
    $preferences['grid_columns_enabled'] = true;
    $preferences['grid_source'] = $overrides === [] ? 'theme' : 'smart_gallery';
    $preferences['source'] = $preferences['grid_source'];
    return $preferences;
}

/** Return effective Smart Gallery presentation values after explicit override precedence and runtime capability policy. */
function smart_gallery_effective_presentation(array $gallery): array
{
    $effective = smart_gallery_presentation_preferences($gallery);
    $masters = smart_gallery_presentation_master_status();

    // Global capability masters are applied after local Smart Gallery overrides.
    // Stored local preferences remain untouched and become effective again when the master is re-enabled.
    $effective['lightbox_enabled'] = !empty($effective['lightbox_enabled']) && !empty($masters['lightbox']);
    $effective['slideshow_enabled'] = !empty($effective['slideshow_enabled']) && $effective['lightbox_enabled'];
    $effective['download_enabled'] = !empty($effective['download_enabled']) && !empty($masters['downloads']);
    $effective['voting_enabled'] = !empty($effective['voting_enabled']) && !empty($masters['voting']);

    return $effective;
}

/** Encode normalized Smart Gallery presentation overrides for persistence. */
function smart_gallery_presentation_json(mixed $value): string
{
    $normalized = smart_gallery_normalize_presentation($value);
    return json_encode(['version' => SMART_GALLERY_PRESENTATION_VERSION] + $normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}

/** Return thumbnail candidates after intersecting Smart Gallery guardrails with physical gallery guardrails. */
function smart_gallery_thumbnail_sizes(array $presentation, array $image, array $sourceGallery, array $requestedSizes): array
{
    $sizes = thumbnail_bound_filter_sizes($requestedSizes, $image, $sourceGallery);
    $minSize = thumbnail_bound_post_value($presentation['thumbnail_min_size'] ?? null);
    $maxSize = thumbnail_bound_post_value($presentation['thumbnail_max_size'] ?? null);
    if ($minSize === null && $maxSize === null) return $sizes;
    $filtered = array_values(array_filter($sizes, static function (int $size) use ($minSize, $maxSize): bool {
        return ($minSize === null || $size >= $minSize) && ($maxSize === null || $size <= $maxSize);
    }));
    if ($filtered !== []) return $filtered;
    // Contradictory Smart Gallery and source-gallery bounds fall back to the source gallery's canonical safe candidates.
    return $sizes;
}

/** Inspect the Smart Gallery schema required by backward-compatible read paths. */
function smart_gallery_schema_status(): array
{
    return mutation_schema_tables_status('mutation.smart_galleries', [
        'smart_galleries' => ['id', 'slug', 'rules_json', 'rule_version', 'enabled', 'visibility', 'placement_mode', 'parent_gallery_id', 'sort_mode', 'sort_direction'],
        'smart_gallery_placements' => ['smart_gallery_id', 'gallery_id', 'created_at'],
        'images' => ['id', 'gallery_id', 'editorial_rating'],
    ]);
}

/** Inspect the complete schema required before a current-version Smart Gallery write. */
function smart_gallery_mutation_schema_status(): array
{
    return mutation_schema_tables_status('mutation.smart_galleries.write', [
        'smart_galleries' => ['id', 'slug', 'rules_json', 'rule_version', 'enabled', 'visibility', 'placement_mode', 'parent_gallery_id', 'sort_mode', 'sort_direction', 'presentation_json'],
        'smart_gallery_placements' => ['smart_gallery_id', 'gallery_id', 'created_at'],
        'images' => ['id', 'gallery_id', 'editorial_rating'],
    ]);
}

/** Return true only when Smart Gallery storage is conclusively available for reads. */
function smart_gallery_schema_ready(): bool
{
    return schema_inspection_is_available(smart_gallery_schema_status());
}

/** Inspect the attachment metadata required for top/bottom placement and ordering writes. */
function smart_gallery_attachment_schema_status(): array
{
    return mutation_schema_tables_status('mutation.smart_gallery_attachments', [
        'smart_gallery_placements' => ['smart_gallery_id', 'gallery_id', 'placement', 'placement_order', 'created_at'],
    ]);
}

/** Return true when attachment placement and ordering metadata can be read safely. */
function smart_gallery_attachment_schema_ready(): bool
{
    return schema_inspection_is_available(smart_gallery_attachment_schema_status());
}

/** Refuse attachment writes before any mutation when placement metadata is unavailable. */
function smart_gallery_assert_attachment_mutation_ready(string $operation): void
{
    mutation_schema_assert_available(
        smart_gallery_attachment_schema_status(),
        $operation,
        'Smart Gallery attachment placement requires the pending database migration.',
        'Smart Gallery attachment storage could not be verified safely.'
    );
}

/** Refuse a Smart Gallery write before any mutation when schema is inconclusive. */
function smart_gallery_assert_mutation_ready(string $operation): void
{
    mutation_schema_assert_available(
        smart_gallery_mutation_schema_status(),
        $operation,
        'Smart Galleries require the pending database migration.',
        'Smart Gallery storage could not be verified safely.'
    );
}

/** Return a new, valid Smart Gallery rule document. */
function smart_gallery_empty_rules(): array
{
    return ['version' => SMART_GALLERY_RULE_VERSION, 'root' => ['type' => 'group', 'operator' => 'AND', 'children' => []]];
}

/** Return request-local Smart Gallery graph/cache storage. */
function &smart_gallery_graph_request_cache(): array
{
    static $cache = [];
    return $cache;
}

/** Clear Smart Gallery graph/cache data after a relationship mutation. */
function smart_gallery_graph_cache_clear(): void
{
    $cache = &smart_gallery_graph_request_cache();
    $cache = [];
}

/** Normalize one attachment placement to the supported structured values. */
function smart_gallery_attachment_placement_value(mixed $value): string
{
    return $value === 'top' ? 'top' : 'bottom';
}

/** Normalize one bounded attachment order value. */
function smart_gallery_attachment_order_value(mixed $value): int
{
    if (!is_numeric($value)) return 0;
    return max(-100000, min(100000, (int) $value));
}

/** Normalize legacy id lists or structured attachment form rows. */
function smart_gallery_normalize_attachment_inputs(array $input): array
{
    $normalized = [];
    foreach ($input as $key => $value) {
        if (is_array($value)) {
            $id = (int) ($value['smart_gallery_id'] ?? $key);
            $explicitNormalizedRow = array_key_exists('smart_gallery_id', $value) && array_key_exists('placement', $value) && array_key_exists('placement_order', $value);
            if ($id <= 0 || (!$explicitNormalizedRow && empty($value['enabled']))) continue;
            $normalized[$id] = [
                'smart_gallery_id' => $id,
                'placement' => smart_gallery_attachment_placement_value($value['placement'] ?? 'bottom'),
                'placement_order' => smart_gallery_attachment_order_value($value['placement_order'] ?? 0),
            ];
            continue;
        }
        $id = (int) $value;
        if ($id <= 0) continue;
        $normalized[$id] = [
            'smart_gallery_id' => $id,
            'placement' => 'bottom',
            'placement_order' => 0,
        ];
    }
    if (count($normalized) > SMART_GALLERY_ATTACHMENT_MAX_PER_PARENT) {
        throw new InvalidArgumentException('A physical gallery may contain at most ' . SMART_GALLERY_ATTACHMENT_MAX_PER_PARENT . ' Smart Gallery attachments.');
    }
    return array_values($normalized);
}

/** Collect physical-gallery rule references with exact/descendant inclusion semantics. */
function smart_gallery_rule_gallery_reference_specs(array $rules): array
{
    $validated = smart_gallery_validate_rules($rules);
    $references = [];
    smart_gallery_rule_gallery_reference_specs_node($validated['root'], true, $references);
    $references = array_values($references);
    usort($references, static function (array $left, array $right): int {
        $idCompare = ((int) $left['gallery_id']) <=> ((int) $right['gallery_id']);
        return $idCompare !== 0 ? $idCompare : strcmp((string) $left['mode'], (string) $right['mode']);
    });
    return $references;
}

/** Return only the stable physical-gallery IDs referenced by positive inclusion branches. */
function smart_gallery_rule_gallery_references(array $rules): array
{
    $ids = array_map(static fn (array $reference): int => (int) $reference['gallery_id'], smart_gallery_rule_gallery_reference_specs($rules));
    $ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
    sort($ids, SORT_NUMERIC);
    return $ids;
}

/** Walk one validated rule node while tracking NOT polarity and exact/under semantics. */
function smart_gallery_rule_gallery_reference_specs_node(array $node, bool $positive, array &$references): void
{
    if (($node['type'] ?? '') === 'group') {
        $childPositive = ($node['operator'] ?? '') === 'NOT' ? !$positive : $positive;
        foreach ((array) ($node['children'] ?? []) as $child) {
            if (is_array($child)) smart_gallery_rule_gallery_reference_specs_node($child, $childPositive, $references);
        }
        return;
    }
    if (($node['field'] ?? '') !== 'gallery') return;
    $operator = (string) ($node['operator'] ?? '');
    $mode = null;
    if ($positive && $operator === 'equals') $mode = 'exact';
    if ($positive && $operator === 'under') $mode = 'under';
    if (!$positive && $operator === 'not_equals') $mode = 'exact';
    if (!$positive && $operator === 'not_under') $mode = 'under';
    $galleryId = (int) ($node['value'] ?? 0);
    if ($mode === null || $galleryId <= 0) return;
    $references[$mode . ':' . $galleryId] = ['gallery_id' => $galleryId, 'mode' => $mode];
}

/** Expand one physical-gallery branch with bounded descendant traversal. */
function smart_gallery_graph_gallery_branch_ids(int $galleryId, array $childrenByParent, bool $includeDescendants): array
{
    if ($galleryId <= 0) return [];
    if (!$includeDescendants) return [$galleryId];
    $queue = [$galleryId];
    $queueIndex = 0;
    $seen = [];
    $result = [];
    while (isset($queue[$queueIndex])) {
        $currentId = (int) $queue[$queueIndex++];
        if ($currentId <= 0 || isset($seen[$currentId])) continue;
        $seen[$currentId] = true;
        $result[] = $currentId;
        if (count($result) > SMART_GALLERY_GRAPH_MAX_EXPANDED_NODES) {
            throw new InvalidArgumentException('A gallery-descendant branch is too large to validate Smart Gallery relationships safely.');
        }
        foreach ((array) ($childrenByParent[$currentId] ?? []) as $childId) {
            $childId = (int) $childId;
            if ($childId > 0 && !isset($seen[$childId])) $queue[] = $childId;
        }
    }
    return $result;
}

/** Add one deduplicated graph edge while enforcing a hard edge ceiling. */
function smart_gallery_graph_add_edge(array &$adjacency, string $from, string $to, int &$edgeCount): void
{
    if ($from === '' || $to === '') return;
    if (!isset($adjacency[$from])) $adjacency[$from] = [];
    if (isset($adjacency[$from][$to])) return;
    if (++$edgeCount > SMART_GALLERY_GRAPH_MAX_EDGES) {
        throw new InvalidArgumentException('Smart Gallery relationship graph is too large to validate safely.');
    }
    $adjacency[$from][$to] = true;
}

/**
 * Find a bounded path in the mixed physical-gallery/Smart-Gallery graph.
 *
 * @return array{found:bool,path:array<int,string>,truncated:bool,visited:int,smart_nodes:int}
 */
function smart_gallery_graph_find_path(array $adjacency, string $start, string $target, int $maxDepth = SMART_GALLERY_GRAPH_MAX_DEPTH, int $maxNodes = SMART_GALLERY_GRAPH_MAX_EXPANDED_NODES, int $maxSmartNodes = SMART_GALLERY_GRAPH_MAX_EXPANDED_SMART_NODES): array
{
    if ($start === '' || $target === '') return ['found' => false, 'path' => [], 'truncated' => false, 'visited' => 0, 'smart_nodes' => 0];
    $queue = [[$start, 0]];
    $queueIndex = 0;
    $seen = [];
    $previous = [];
    $visited = 0;
    $smartNodes = 0;
    while (isset($queue[$queueIndex])) {
        [$node, $depth] = $queue[$queueIndex++];
        if (isset($seen[$node])) continue;
        $seen[$node] = true;
        $visited++;
        if (str_starts_with($node, 's:')) $smartNodes++;
        if ($visited > $maxNodes || $smartNodes > $maxSmartNodes || $depth > $maxDepth) {
            return ['found' => false, 'path' => [], 'truncated' => true, 'visited' => $visited, 'smart_nodes' => $smartNodes];
        }
        if ($node === $target) {
            $path = [$node];
            while (isset($previous[$node])) {
                $node = $previous[$node];
                $path[] = $node;
            }
            return ['found' => true, 'path' => array_reverse($path), 'truncated' => false, 'visited' => $visited, 'smart_nodes' => $smartNodes];
        }
        foreach (array_keys((array) ($adjacency[$node] ?? [])) as $next) {
            if (isset($seen[$next]) || isset($previous[$next]) || $next === $start) continue;
            $previous[$next] = $node;
            $queue[] = [$next, $depth + 1];
        }
    }
    return ['found' => false, 'path' => [], 'truncated' => false, 'visited' => $visited, 'smart_nodes' => $smartNodes];
}

/** Build the canonical mixed relationship graph with optional proposed mutations. */
function smart_gallery_graph_snapshot(?array $definitionOverride = null, ?array $placementOverride = null, ?array $parentOverride = null): array
{
    $cache = &smart_gallery_graph_request_cache();
    if ($definitionOverride === null && $placementOverride === null && $parentOverride === null && isset($cache['base_graph'])) {
        return $cache['base_graph'];
    }
    if (!smart_gallery_schema_ready()) {
        return ['adjacency' => [], 'placements' => [], 'smart_errors' => [], 'edge_count' => 0];
    }

    $adjacency = [];
    $placements = [];
    $smartErrors = [];
    $edgeCount = 0;
    $galleryRows = \Gallery\Models\smart_gallery_model_graph_gallery_rows(SMART_GALLERY_GRAPH_MAX_SOURCE_ROWS);
    if (count($galleryRows) > SMART_GALLERY_GRAPH_MAX_SOURCE_ROWS) {
        throw new InvalidArgumentException('Gallery hierarchy is too large to validate Smart Gallery relationships safely.');
    }
    $parentOverrides = [];
    if ($parentOverride !== null) {
        if (isset($parentOverride['parents']) && is_array($parentOverride['parents'])) {
            foreach ($parentOverride['parents'] as $overrideGalleryId => $overrideParentId) {
                $overrideGalleryId = (int) $overrideGalleryId;
                if ($overrideGalleryId > 0) $parentOverrides[$overrideGalleryId] = max(0, (int) $overrideParentId);
            }
        } else {
            $overrideGalleryId = (int) ($parentOverride['gallery_id'] ?? 0);
            if ($overrideGalleryId > 0) $parentOverrides[$overrideGalleryId] = max(0, (int) ($parentOverride['parent_id'] ?? 0));
        }
    }
    $childrenByParent = [];
    foreach ($galleryRows as $row) {
        $galleryId = (int) ($row['id'] ?? 0);
        if ($galleryId <= 0) continue;
        $parentId = array_key_exists($galleryId, $parentOverrides)
            ? $parentOverrides[$galleryId]
            : (isset($row['parent_id']) ? (int) $row['parent_id'] : 0);
        if ($parentId > 0) $childrenByParent[$parentId][] = $galleryId;
    }

    $definitionRows = \Gallery\Models\smart_gallery_model_graph_definition_rows(SMART_GALLERY_GRAPH_MAX_SOURCE_ROWS);
    if (count($definitionRows) > SMART_GALLERY_GRAPH_MAX_SOURCE_ROWS) {
        throw new InvalidArgumentException('There are too many Smart Gallery definitions to validate relationships safely.');
    }
    foreach ($definitionRows as $row) {
        $smartId = (int) ($row['id'] ?? 0);
        if ($smartId <= 0) continue;
        try {
            $rules = $definitionOverride !== null && $smartId === (int) ($definitionOverride['id'] ?? 0)
                ? (array) ($definitionOverride['rules'] ?? [])
                : smart_gallery_rules_from_json((string) ($row['rules_json'] ?? ''));
            foreach (smart_gallery_rule_gallery_reference_specs($rules) as $reference) {
                $galleryId = (int) $reference['gallery_id'];
                $includeDescendants = ($reference['mode'] ?? 'exact') === 'under';
                foreach (smart_gallery_graph_gallery_branch_ids($galleryId, $childrenByParent, $includeDescendants) as $resolvedGalleryId) {
                    smart_gallery_graph_add_edge($adjacency, 's:' . $smartId, 'g:' . $resolvedGalleryId, $edgeCount);
                }
            }
        } catch (InvalidArgumentException) {
            $smartErrors[$smartId] = 'malformed_rules';
        }
    }

    $placementRows = \Gallery\Models\smart_gallery_model_graph_placement_rows(
        SMART_GALLERY_GRAPH_MAX_SOURCE_ROWS,
        smart_gallery_attachment_schema_ready()
    );
    if (count($placementRows) > SMART_GALLERY_GRAPH_MAX_SOURCE_ROWS) {
        throw new InvalidArgumentException('There are too many Smart Gallery placements to validate relationships safely.');
    }
    $overrideGalleryId = $placementOverride !== null ? (int) ($placementOverride['gallery_id'] ?? 0) : 0;
    foreach ($placementRows as $row) {
        $galleryId = (int) ($row['gallery_id'] ?? 0);
        if ($overrideGalleryId > 0 && $galleryId === $overrideGalleryId) continue;
        $smartId = (int) ($row['smart_gallery_id'] ?? 0);
        if ($galleryId <= 0 || $smartId <= 0) continue;
        $normalized = [
            'smart_gallery_id' => $smartId,
            'gallery_id' => $galleryId,
            'placement' => smart_gallery_attachment_placement_value($row['placement'] ?? 'bottom'),
            'placement_order' => smart_gallery_attachment_order_value($row['placement_order'] ?? 0),
        ];
        $placements[] = $normalized;
        smart_gallery_graph_add_edge($adjacency, 'g:' . $galleryId, 's:' . $smartId, $edgeCount);
    }
    if ($placementOverride !== null && $overrideGalleryId > 0) {
        foreach (smart_gallery_normalize_attachment_inputs((array) ($placementOverride['attachments'] ?? [])) as $attachment) {
            $smartId = (int) $attachment['smart_gallery_id'];
            $normalized = $attachment + ['gallery_id' => $overrideGalleryId];
            $normalized['gallery_id'] = $overrideGalleryId;
            $placements[] = $normalized;
            smart_gallery_graph_add_edge($adjacency, 'g:' . $overrideGalleryId, 's:' . $smartId, $edgeCount);
        }
    }
    $snapshot = ['adjacency' => $adjacency, 'placements' => $placements, 'smart_errors' => $smartErrors, 'edge_count' => $edgeCount];
    if ($definitionOverride === null && $placementOverride === null && $parentOverride === null) $cache['base_graph'] = $snapshot;
    return $snapshot;
}

/** Build a fail-closed relationship snapshot for read-only Admin/public rendering. */
function smart_gallery_graph_read_snapshot(): array
{
    try {
        return smart_gallery_graph_snapshot();
    } catch (InvalidArgumentException) {
        return ['adjacency' => [], 'placements' => [], 'smart_errors' => [], 'edge_count' => 0, 'graph_error' => 'graph_limit'];
    }
}

/** Return a bounded diagnostic for one concrete physical-gallery attachment edge. */
function smart_gallery_relationship_diagnostic(int $smartGalleryId, int $galleryId, ?array $snapshot = null): array
{
    if ($smartGalleryId <= 0 || $galleryId <= 0) return ['valid' => false, 'code' => 'malformed_relationship', 'path' => []];
    $snapshot = $snapshot ?? smart_gallery_graph_read_snapshot();
    if (isset($snapshot['graph_error'])) return ['valid' => false, 'code' => (string) $snapshot['graph_error'], 'path' => []];
    if (isset($snapshot['smart_errors'][$smartGalleryId])) return ['valid' => false, 'code' => (string) $snapshot['smart_errors'][$smartGalleryId], 'path' => []];
    $path = smart_gallery_graph_find_path((array) ($snapshot['adjacency'] ?? []), 's:' . $smartGalleryId, 'g:' . $galleryId);
    if (!empty($path['truncated'])) return ['valid' => false, 'code' => 'graph_limit', 'path' => []];
    if (!empty($path['found'])) return ['valid' => false, 'code' => 'cycle', 'path' => (array) $path['path']];
    return ['valid' => true, 'code' => 'ok', 'path' => []];
}

/** Log a bounded relationship diagnostic using IDs and stable codes only. */
function smart_gallery_log_relationship_diagnostic(int $smartGalleryId, int $galleryId, string $code): void
{
    static $logged = [];
    if (count($logged) >= 10) return;
    $key = $smartGalleryId . ':' . $galleryId . ':' . $code;
    if (isset($logged[$key])) return;
    $logged[$key] = true;
    if (!function_exists(__NAMESPACE__ . '\\admin_log_event')) return;
    try {
        admin_log_event('warning', 'smart_gallery.relationship_invalid', 'Smart Gallery relationship was skipped because it is invalid.', [
            'smart_gallery_id' => $smartGalleryId,
            'gallery_id' => $galleryId,
            'reason' => preg_match('/^[a-z_]{1,32}$/D', $code) === 1 ? $code : 'invalid',
        ], ['category' => 'gallery', 'severity' => 'warning', 'subject_type' => 'smart_gallery', 'subject_id' => $smartGalleryId]);
    } catch (\Throwable) {
        // Relationship safety must not depend on diagnostic persistence being available.
    }
}

/** Validate a proposed Smart Gallery rule update against its existing attachments. */
function smart_gallery_validate_definition_graph(int $smartGalleryId, array $rules): void
{
    if ($smartGalleryId <= 0) return;
    $snapshot = smart_gallery_graph_snapshot(['id' => $smartGalleryId, 'rules' => $rules]);
    foreach ((array) ($snapshot['placements'] ?? []) as $placement) {
        if ((int) ($placement['smart_gallery_id'] ?? 0) !== $smartGalleryId) continue;
        $diagnostic = smart_gallery_relationship_diagnostic($smartGalleryId, (int) $placement['gallery_id'], $snapshot);
        if (!$diagnostic['valid']) {
            throw new InvalidArgumentException(t('smart_gallery.relationship_cycle_rule_error', 'This Smart Gallery rule would make the gallery contain or attach back to itself. Detach it from the affected physical gallery or change the gallery rule first.'));
        }
    }
}

/** Return true only when a proposed relationship introduces a new invalid edge. */
function smart_gallery_relationship_change_is_newly_invalid(bool $wasAttached, array $before, array $after): bool
{
    if (!empty($after['valid'])) return false;
    return !$wasAttached || !empty($before['valid']);
}

/** Validate and normalize all proposed Smart Gallery attachments beneath one physical gallery. */
function smart_gallery_validate_children_assignment(int $galleryId, array $input, ?int $proposedParentId = null, bool $applyParentOverride = false): array
{
    smart_gallery_assert_attachment_mutation_ready('smart_gallery.assign_children');
    if ($galleryId <= 0) throw new InvalidArgumentException('Select a valid parent gallery.');
    $attachments = smart_gallery_normalize_attachment_inputs($input);
    if ($attachments !== []) {
        $ids = array_column($attachments, 'smart_gallery_id');
        $validIds = \Gallery\Models\smart_gallery_model_existing_ids(array_map('intval', $ids));
        sort($validIds, SORT_NUMERIC);
        $expectedIds = array_values(array_unique(array_map('intval', $ids)));
        sort($expectedIds, SORT_NUMERIC);
        if ($validIds !== $expectedIds) throw new InvalidArgumentException('One or more selected Smart Galleries no longer exist.');
    }
    $baseSnapshot = smart_gallery_graph_snapshot();
    $currentlyAttached = [];
    foreach ((array) ($baseSnapshot['placements'] ?? []) as $placement) {
        if ((int) ($placement['gallery_id'] ?? 0) === $galleryId) {
            $currentlyAttached[(int) ($placement['smart_gallery_id'] ?? 0)] = true;
        }
    }
    $parentOverride = $applyParentOverride ? ['gallery_id' => $galleryId, 'parent_id' => $proposedParentId ?? 0] : null;
    $snapshot = smart_gallery_graph_snapshot(null, ['gallery_id' => $galleryId, 'attachments' => $attachments], $parentOverride);
    foreach ($attachments as $attachment) {
        $smartId = (int) $attachment['smart_gallery_id'];
        $diagnostic = smart_gallery_relationship_diagnostic($smartId, $galleryId, $snapshot);
        $wasAttached = isset($currentlyAttached[$smartId]);
        $before = $wasAttached
            ? smart_gallery_relationship_diagnostic($smartId, $galleryId, $baseSnapshot)
            : ['valid' => true, 'code' => 'not_attached', 'path' => []];
        if (smart_gallery_relationship_change_is_newly_invalid($wasAttached, $before, $diagnostic)) {
            throw new InvalidArgumentException(t('smart_gallery.relationship_cycle_attachment_error', 'A Smart Gallery cannot contain or attach back to itself, directly or indirectly. Change the gallery rule or attachment.'));
        }
    }
    return $attachments;
}

/** Validate a complete proposed physical-gallery parent map without blocking unrelated legacy cycles. */
function smart_gallery_validate_gallery_parent_map(array $parentByGalleryId): void
{
    if (!smart_gallery_schema_ready() || $parentByGalleryId === []) return;
    $normalizedParents = [];
    foreach ($parentByGalleryId as $galleryId => $parentId) {
        $galleryId = (int) $galleryId;
        if ($galleryId <= 0) continue;
        $normalizedParents[$galleryId] = max(0, (int) $parentId);
    }
    if ($normalizedParents === []) return;
    $base = smart_gallery_graph_snapshot();
    $proposed = smart_gallery_graph_snapshot(null, null, ['parents' => $normalizedParents]);
    foreach ((array) ($proposed['placements'] ?? []) as $placement) {
        $smartId = (int) ($placement['smart_gallery_id'] ?? 0);
        $parentGalleryId = (int) ($placement['gallery_id'] ?? 0);
        if ($smartId <= 0 || $parentGalleryId <= 0) continue;
        $before = smart_gallery_relationship_diagnostic($smartId, $parentGalleryId, $base);
        $after = smart_gallery_relationship_diagnostic($smartId, $parentGalleryId, $proposed);
        if ($before['valid'] && !$after['valid']) {
            throw new InvalidArgumentException(t('smart_gallery.relationship_cycle_move_error', 'Moving this gallery would make a Smart Gallery contain or attach back to itself. Change or detach the affected Smart Gallery first.'));
        }
    }
}

/** Validate one physical gallery parent change without blocking unrelated legacy cycles. */
function smart_gallery_validate_gallery_parent_change(int $galleryId, ?int $parentId): void
{
    if ($galleryId <= 0) return;
    smart_gallery_validate_gallery_parent_map([$galleryId => $parentId ?? 0]);
}

/** Refuse flat result evaluation only when this Smart Gallery's persisted rule document is malformed. */
function smart_gallery_assert_runtime_safe(array $gallery): void
{
    if (!smart_gallery_schema_ready()) return;
    // Result membership is a flat image query and never expands physical placements.
    // Validate only this rule document here so an unrelated oversized/cyclic attachment
    // graph cannot take down an otherwise valid direct/root Smart Gallery result view.
    smart_gallery_rules_from_json((string) ($gallery['rules_json'] ?? ''));
}

/** Return the public rule-builder field catalog and supported operators. */
function smart_gallery_rule_catalog(): array
{
    $text = ['equals', 'not_equals', 'contains', 'not_contains', 'starts_with', 'ends_with', 'exists', 'missing', 'is_empty', 'not_empty'];
    $number = ['equals', 'not_equals', 'gt', 'gte', 'lt', 'lte', 'between', 'exists', 'missing'];
    return [
        'gallery' => ['kind' => 'reference', 'operators' => ['equals', 'not_equals', 'under', 'not_under']],
        'tag' => ['kind' => 'reference', 'operators' => ['has_tag', 'not_has_tag', 'has_any_tags', 'has_all_tags', 'untagged']],
        'capture_date' => ['kind' => 'date', 'operators' => ['before', 'after', 'between', 'exact', 'year', 'month', 'exists', 'missing']],
        'camera_make' => ['kind' => 'text', 'operators' => $text],
        'camera_model' => ['kind' => 'text', 'operators' => $text],
        'lens' => ['kind' => 'text', 'operators' => $text],
        'iso' => ['kind' => 'number', 'operators' => $number],
        'aperture' => ['kind' => 'text', 'operators' => $text],
        'focal_length' => ['kind' => 'text', 'operators' => $text],
        'exposure_time' => ['kind' => 'text', 'operators' => $text],
        'exif_orientation' => ['kind' => 'number', 'operators' => $number],
        'gps' => ['kind' => 'presence', 'operators' => ['exists', 'missing']],
        'filename' => ['kind' => 'text', 'operators' => $text],
        'title' => ['kind' => 'text', 'operators' => $text],
        'description' => ['kind' => 'text', 'operators' => $text],
        'gallery_title' => ['kind' => 'text', 'operators' => $text],
        'ai_text' => ['kind' => 'text', 'operators' => $text],
        'ai_metadata' => ['kind' => 'presence', 'operators' => ['exists', 'missing']],
        'duplicate_status' => ['kind' => 'enum', 'operators' => ['unresolved', 'resolved', 'exists', 'none']],
        'rating' => ['kind' => 'number', 'operators' => ['equals', 'gte', 'lte', 'unrated']],
        'extension' => ['kind' => 'text', 'operators' => ['equals', 'not_equals']],
        'width' => ['kind' => 'number', 'operators' => $number],
        'height' => ['kind' => 'number', 'operators' => $number],
        'file_size' => ['kind' => 'number', 'operators' => $number],
        'media_orientation' => ['kind' => 'enum', 'operators' => ['landscape', 'portrait', 'square']],
    ];
}

/** Decode and validate a persisted or submitted rule JSON document. */
function smart_gallery_rules_from_json(string $json): array
{
    try {
        $decoded = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
    } catch (\JsonException) {
        throw new InvalidArgumentException('The rule document is not valid JSON.');
    }
    if (!is_array($decoded)) {
        throw new InvalidArgumentException('The rule document must be an object.');
    }
    return smart_gallery_validate_rules($decoded);
}

/** Validate and normalize a complete versioned rule document. */
function smart_gallery_validate_rules(array $rules): array
{
    if (($rules['version'] ?? null) !== SMART_GALLERY_RULE_VERSION || !is_array($rules['root'] ?? null)) {
        throw new InvalidArgumentException('This Smart Gallery uses an unsupported rule version.');
    }
    $count = 0;
    $root = smart_gallery_validate_rule_node($rules['root'], 0, $count);
    return ['version' => SMART_GALLERY_RULE_VERSION, 'root' => $root];
}

/** Validate one nested rule node while enforcing complexity limits. */
function smart_gallery_validate_rule_node(array $node, int $depth, int &$conditionCount): array
{
    if ($depth > SMART_GALLERY_MAX_DEPTH) {
        throw new InvalidArgumentException('Rule groups may be nested at most five levels deep.');
    }
    $type = (string) ($node['type'] ?? '');
    if ($type === 'group') {
        $operator = strtoupper((string) ($node['operator'] ?? ''));
        if (!in_array($operator, ['AND', 'OR', 'NOT'], true)) {
            throw new InvalidArgumentException('A rule group must use AND, OR, or NOT.');
        }
        $children = $node['children'] ?? null;
        if (!is_array($children) || ($operator === 'NOT' && count($children) !== 1)) {
            throw new InvalidArgumentException($operator === 'NOT' ? 'A NOT group must contain exactly one rule.' : 'A rule group must contain a rule list.');
        }
        if ($depth > 0 && $children === []) {
            throw new InvalidArgumentException('Nested rule groups cannot be empty.');
        }
        $normalizedChildren = [];
        foreach (array_values($children) as $child) {
            if (!is_array($child)) {
                throw new InvalidArgumentException('Every rule must be an object.');
            }
            $normalizedChildren[] = smart_gallery_validate_rule_node($child, $depth + 1, $conditionCount);
        }
        return ['type' => 'group', 'operator' => $operator, 'children' => $normalizedChildren];
    }
    if ($type !== 'condition') {
        throw new InvalidArgumentException('Every rule must be a condition or group.');
    }
    if (++$conditionCount > SMART_GALLERY_MAX_CONDITIONS) {
        throw new InvalidArgumentException('A Smart Gallery may contain at most 50 conditions.');
    }
    $field = (string) ($node['field'] ?? '');
    $operator = (string) ($node['operator'] ?? '');
    $catalog = smart_gallery_rule_catalog();
    if (!isset($catalog[$field])) {
        throw new InvalidArgumentException('The selected rule field is not supported.');
    }
    if (!in_array($operator, $catalog[$field]['operators'], true)) {
        throw new InvalidArgumentException('The selected operator is not supported for this field.');
    }
    $valueFree = in_array($operator, ['exists', 'missing', 'is_empty', 'not_empty', 'untagged', 'unrated', 'unresolved', 'resolved', 'none', 'landscape', 'portrait', 'square'], true);
    $value = $node['value'] ?? null;
    if (!$valueFree) {
        if ($operator === 'between') {
            if (!is_array($value) || count($value) !== 2) {
                throw new InvalidArgumentException('Between requires two values.');
            }
            $value = array_values($value);
        } elseif (in_array($operator, ['has_any_tags', 'has_all_tags'], true)) {
            if (!is_array($value) || $value === []) {
                throw new InvalidArgumentException('Select at least one tag.');
            }
            $value = array_values(array_unique(array_map('intval', $value)));
        } elseif ($field === 'capture_date') {
            $dateValues = $operator === 'between' ? $value : [$value];
            if (in_array($operator, ['year', 'month'], true)) {
                $number = (int) $value;
                if (($operator === 'year' && ($number < 1000 || $number > 9999)) || ($operator === 'month' && ($number < 1 || $number > 12))) {
                    throw new InvalidArgumentException('Enter a valid capture year or month.');
                }
                $value = $number;
            } else {
                foreach ($dateValues as $dateValue) {
                    $date = (string) $dateValue;
                    $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
                    if (!$parsed || $parsed->format('Y-m-d') !== $date) {
                        throw new InvalidArgumentException('Enter a valid capture date.');
                    }
                }
                $value = $operator === 'between' ? array_map('strval', $dateValues) : (string) $value;
            }
        } elseif ($catalog[$field]['kind'] === 'reference') {
            $value = (int) $value;
            if ($value <= 0) {
                throw new InvalidArgumentException('Select an existing referenced item.');
            }
        } elseif ($catalog[$field]['kind'] === 'number') {
            $values = $operator === 'between' ? $value : [$value];
            foreach ($values as $number) {
                if (!is_numeric($number)) {
                    throw new InvalidArgumentException('Enter a valid numeric value.');
                }
            }
            $value = $operator === 'between' ? array_map('floatval', $values) : (float) $value;
            if ($field === 'rating' && ($value < 1 || $value > 5)) {
                throw new InvalidArgumentException('Ratings must be between 1 and 5.');
            }
        } else {
            $value = trim((string) $value);
            if ($value === '') {
                throw new InvalidArgumentException('This condition requires a value.');
            }
        }
    }
    $normalized = ['type' => 'condition', 'field' => $field, 'operator' => $operator];
    if (!$valueFree) {
        $normalized['value'] = $value;
    }
    return $normalized;
}

/** Return all definitions for the Admin list. */
function smart_galleries_all(): array
{
    if (!smart_gallery_schema_ready()) return [];
    return smart_gallery_attach_placement_ids(\Gallery\Models\smart_gallery_model_all());
}

/** Attach physical placement IDs and per-parent metadata without issuing N+1 queries. */
function smart_gallery_attach_placement_ids(array $rows): array
{
    if ($rows === []) return [];
    $placements = [];
    foreach (\Gallery\Models\smart_gallery_model_all_placements(smart_gallery_attachment_schema_ready()) as $placement) {
        $smartId = (int) $placement['smart_gallery_id'];
        $placements[$smartId][] = [
            'gallery_id' => (int) $placement['gallery_id'],
            'placement' => smart_gallery_attachment_placement_value($placement['placement'] ?? 'bottom'),
            'placement_order' => smart_gallery_attachment_order_value($placement['placement_order'] ?? 0),
        ];
    }
    foreach ($rows as &$row) {
        $attachmentRows = $placements[(int) ($row['id'] ?? 0)] ?? [];
        $row['placement_gallery_ids'] = array_values(array_map(static fn (array $placement): int => (int) $placement['gallery_id'], $attachmentRows));
        $row['placement_assignments'] = $attachmentRows;
    }
    unset($row);
    return $rows;
}

/** Find one definition by id. */
function smart_gallery_find(int $id): ?array
{
    if (!smart_gallery_schema_ready()) return null;
    $row = \Gallery\Models\smart_gallery_model_find($id);
    return is_array($row) ? smart_gallery_attach_placement_ids([$row])[0] : null;
}

/** Find one enabled published definition by slug. */
function smart_gallery_find_public(string $slug): ?array
{
    if (function_exists('Gallery\\Services\\feature_capability_effective_enabled')
        && !feature_capability_effective_enabled('smart_galleries')) {
        return null;
    }
    if (!smart_gallery_schema_ready()) return null;
    return \Gallery\Models\smart_gallery_model_find_public_by_slug($slug);
}

/** Find one enabled published definition by trusted database id. */
function smart_gallery_find_public_by_id(int $id): ?array
{
    if (function_exists('Gallery\\Services\\feature_capability_effective_enabled')
        && !feature_capability_effective_enabled('smart_galleries')) {
        return null;
    }
    if ($id <= 0 || !smart_gallery_schema_ready()) return null;
    return \Gallery\Models\smart_gallery_model_find_public_by_id($id);
}

/** Create or update a definition after normalizing every submitted value. */
function smart_gallery_save(array $input, int $id = 0): array
{
    smart_gallery_assert_mutation_ready($id > 0 ? 'smart_gallery.update' : 'smart_gallery.create');
    $title = trim((string) ($input['title'] ?? ''));
    if ($title === '') throw new InvalidArgumentException('Enter a Smart Gallery title.');
    $rules = is_array($input['rules'] ?? null) ? smart_gallery_validate_rules($input['rules']) : smart_gallery_rules_from_json((string) ($input['rules_json'] ?? ''));
    if ($id > 0) smart_gallery_validate_definition_graph($id, $rules);
    $submittedSlug = trim((string) ($input['slug'] ?? ''));
    $slug = smart_gallery_unique_slug($submittedSlug !== '' ? $submittedSlug : $title, $id);
    $visibility = ($input['visibility'] ?? 'private') === 'public' ? 'public' : 'private';
    $placementMode = in_array($input['placement_mode'] ?? '', ['unlisted', 'root', 'gallery'], true) ? (string) $input['placement_mode'] : 'unlisted';
    $sortModes = ['capture_date', 'filename', 'created_at', 'title', 'rating', 'default'];
    $sortMode = in_array($input['sort_mode'] ?? '', $sortModes, true) ? (string) $input['sort_mode'] : 'capture_date';
    $direction = ($input['sort_direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
    $json = json_encode($rules, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $presentationJson = smart_gallery_presentation_json($input['presentation'] ?? $input['presentation_json'] ?? []);
    $now = now_sql();
    $id = \Gallery\Models\smart_gallery_model_save([
        'title' => $title,
        'slug' => $slug,
        'description' => trim((string) ($input['description'] ?? '')),
        'rules_json' => $json,
        'rule_version' => SMART_GALLERY_RULE_VERSION,
        'enabled' => !empty($input['enabled']) ? 1 : 0,
        'visibility' => $visibility,
        'placement_mode' => $placementMode,
        'sort_mode' => $sortMode,
        'sort_direction' => $direction,
        'presentation_json' => $presentationJson,
        'created_at' => $now,
        'updated_at' => $now,
    ], $id);
    smart_gallery_graph_cache_clear();
    return smart_gallery_find($id) ?? throw new InvalidArgumentException('The Smart Gallery could not be loaded after saving.');
}

/** Generate a unique stable public slug. */
function smart_gallery_unique_slug(string $value, int $excludeId = 0): string
{
    $base = slugify($value) ?: 'smart-gallery';
    $candidate = $base;
    for ($suffix = 2; $suffix < 10000; $suffix++) {
        if (!\Gallery\Models\smart_gallery_model_slug_exists($candidate, $excludeId)) return $candidate;
        $candidate = $base . '-' . $suffix;
    }
    throw new InvalidArgumentException('A unique Smart Gallery slug could not be generated.');
}

/** Delete one Smart Gallery definition without touching images or files. */
function smart_gallery_delete(int $id): void
{
    smart_gallery_assert_mutation_ready('smart_gallery.delete');
    \Gallery\Models\smart_gallery_model_delete($id);
    smart_gallery_graph_cache_clear();
}

/** Duplicate a definition as a disabled private draft. */
function smart_gallery_duplicate(int $id): array
{
    smart_gallery_assert_mutation_ready('smart_gallery.duplicate');
    $source = smart_gallery_find($id) ?? throw new InvalidArgumentException('Smart Gallery not found.');
    return smart_gallery_save(['title' => $source['title'] . ' copy', 'description' => $source['description'], 'rules_json' => $source['rules_json'], 'enabled' => 0, 'visibility' => 'private', 'placement_mode' => 'unlisted', 'sort_mode' => $source['sort_mode'], 'sort_direction' => $source['sort_direction'], 'presentation_json' => $source['presentation_json'] ?? '']);
}

/** Return all attachment rows for one physical gallery, including Admin-only definitions. */
function smart_gallery_attachment_rows_for_gallery(int $galleryId): array
{
    if (!smart_gallery_schema_ready() || $galleryId <= 0) return [];
    $snapshot = smart_gallery_graph_read_snapshot();
    $rows = [];
    foreach (\Gallery\Models\smart_gallery_model_attachment_rows_for_gallery($galleryId, smart_gallery_attachment_schema_ready()) as $row) {
        $smartId = (int) $row['id'];
        $diagnostic = smart_gallery_relationship_diagnostic($smartId, $galleryId, $snapshot);
        $row['placement'] = smart_gallery_attachment_placement_value($row['placement'] ?? 'bottom');
        $row['placement_order'] = smart_gallery_attachment_order_value($row['placement_order'] ?? 0);
        $row['relationship_valid'] = $diagnostic['valid'] ? 1 : 0;
        $row['relationship_code'] = (string) $diagnostic['code'];
        $rows[] = $row;
    }
    return $rows;
}

/** Return published Smart Galleries assigned to the public root or one physical parent gallery. */
function smart_galleries_for_placement(?int $parentGalleryId, bool $publicOnly): array
{
    if (function_exists('Gallery\\Services\\feature_capability_effective_enabled')
        && !feature_capability_effective_enabled('smart_galleries')) {
        return [];
    }
    if (!smart_gallery_schema_ready()) return [];
    $snapshot = $parentGalleryId !== null ? smart_gallery_graph_read_snapshot() : null;
    $rows = [];
    foreach (\Gallery\Models\smart_gallery_model_for_placement($parentGalleryId, smart_gallery_attachment_schema_ready()) as $row) {
        if ($parentGalleryId !== null) {
            $diagnostic = smart_gallery_relationship_diagnostic((int) $row['id'], $parentGalleryId, $snapshot);
            if (!$diagnostic['valid']) {
                smart_gallery_log_relationship_diagnostic((int) $row['id'], $parentGalleryId, (string) $diagnostic['code']);
                continue;
            }
            $row['placement'] = smart_gallery_attachment_placement_value($row['placement'] ?? 'bottom');
            $row['placement_order'] = smart_gallery_attachment_order_value($row['placement_order'] ?? 0);
        }
        $row['__smart_gallery'] = 1;
        $rows[] = $row;
    }
    return $rows;
}

/** Return ordered top and bottom Smart Gallery groups for one physical parent. */
function smart_gallery_attachment_groups(int $parentGalleryId, bool $publicOnly): array
{
    $groups = ['top' => [], 'bottom' => []];
    foreach (smart_galleries_for_placement($parentGalleryId, $publicOnly) as $row) {
        $groups[smart_gallery_attachment_placement_value($row['placement'] ?? 'bottom')][] = $row;
    }
    return $groups;
}

/** Replace placements beneath one physical gallery without changing other parents. */
function smart_gallery_assign_children_to_gallery(int $galleryId, array $selectedIds): void
{
    smart_gallery_assert_mutation_ready('smart_gallery.assign_children');
    $attachments = smart_gallery_validate_children_assignment($galleryId, $selectedIds);
    \Gallery\Models\smart_gallery_model_replace_gallery_attachments($galleryId, $attachments, now_sql());
    smart_gallery_graph_cache_clear();
}

/** Return every physical gallery currently listing one Smart Gallery with per-parent metadata. */
function smart_gallery_placement_galleries(int $smartGalleryId): array
{
    if (!smart_gallery_schema_ready() || $smartGalleryId <= 0) return [];
    $snapshot = smart_gallery_graph_read_snapshot();
    $rows = [];
    foreach (\Gallery\Models\smart_gallery_model_placement_galleries($smartGalleryId, smart_gallery_attachment_schema_ready()) as $row) {
        $diagnostic = smart_gallery_relationship_diagnostic($smartGalleryId, (int) $row['id'], $snapshot);
        $row['placement'] = smart_gallery_attachment_placement_value($row['placement'] ?? 'bottom');
        $row['placement_order'] = smart_gallery_attachment_order_value($row['placement_order'] ?? 0);
        $row['relationship_valid'] = $diagnostic['valid'] ? 1 : 0;
        $row['relationship_code'] = (string) $diagnostic['code'];
        $rows[] = $row;
    }
    return $rows;
}

/** Update one existing attachment placement/order without changing relationship graph edges. */
function smart_gallery_update_placement(int $smartGalleryId, int $galleryId, string $placement, int $placementOrder): bool
{
    smart_gallery_assert_attachment_mutation_ready('smart_gallery.update_placement');
    if ($smartGalleryId <= 0 || $galleryId <= 0) throw new InvalidArgumentException('Select a valid Smart Gallery placement.');
    $updated = \Gallery\Models\smart_gallery_model_update_placement(
        $smartGalleryId,
        $galleryId,
        smart_gallery_attachment_placement_value($placement),
        smart_gallery_attachment_order_value($placementOrder)
    );
    if (!$updated) throw new InvalidArgumentException('Smart Gallery placement not found.');
    // Placement area/order do not add or remove graph edges, so legacy invalid relations
    // can still be reordered while detach remains available as the repair path.
    return true;
}

/** Remove one physical placement without modifying any other attachment. */
function smart_gallery_remove_from_gallery(int $smartGalleryId, int $galleryId): bool
{
    smart_gallery_assert_mutation_ready('smart_gallery.remove_placement');
    if ($smartGalleryId <= 0 || $galleryId <= 0) throw new InvalidArgumentException('Select a valid Smart Gallery placement.');
    $removed = \Gallery\Models\smart_gallery_model_remove_placement($smartGalleryId, $galleryId);
    if ($removed) smart_gallery_graph_cache_clear();
    return $removed;
}

/** Return source gallery ids accessible in the current Admin or public context. */
function smart_gallery_accessible_gallery_ids(bool $publicOnly): array
{
    $cache = &smart_gallery_graph_request_cache();
    $cacheKey = $publicOnly ? 'accessible_gallery_ids_public' : 'accessible_gallery_ids_admin';
    if (isset($cache[$cacheKey]) && is_array($cache[$cacheKey])) return $cache[$cacheKey];

    $ids = [];
    $sourceGalleryRows = isset($cache['source_gallery_rows']) && is_array($cache['source_gallery_rows'])
        ? $cache['source_gallery_rows']
        : [];
    foreach (\Gallery\Models\smart_gallery_model_all_source_galleries() as $sourceGallery) {
        if ($publicOnly && (!gallery_is_public_listed($sourceGallery) || !visitor_can_access_gallery($sourceGallery))) continue;
        $galleryId = (int) ($sourceGallery['id'] ?? 0);
        if ($galleryId > 0) {
            $ids[] = $galleryId;
            $sourceGalleryRows[$galleryId] = $sourceGallery;
        }
    }
    $cache[$cacheKey] = $ids;
    $cache['source_gallery_rows'] = $sourceGalleryRows;
    return $ids;
}

/** Return normalized semantic query inputs without exposing SQL fragments outside the model. */
function smart_gallery_query_semantics(array $gallery, bool $publicOnly, ?array $accessibleGalleryIds = null): array
{
    if (!smart_gallery_schema_ready()) throw new InvalidArgumentException('Smart Gallery storage is unavailable.');
    smart_gallery_assert_runtime_safe($gallery);
    $rules = smart_gallery_rules_from_json((string) ($gallery['rules_json'] ?? ''));
    $ids = $accessibleGalleryIds ?? smart_gallery_accessible_gallery_ids($publicOnly);
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
    $allowNsfw = !$publicOnly || !nsfw_guard_schema_ready() || visitor_can_access_nsfw_content();
    return [
        'rules' => $rules,
        'accessible_gallery_ids' => $ids,
        'allow_nsfw' => $allowNsfw,
        'sort_mode' => (string) ($gallery['sort_mode'] ?? ''),
        'sort_direction' => (string) ($gallery['sort_direction'] ?? 'desc'),
    ];
}

/** Return whether one image remains a member of the canonical Smart Gallery result set. */
function smart_gallery_contains_image_for_accessible_ids(array $gallery, bool $publicOnly, array $accessibleGalleryIds, int $imageId): bool
{
    $semantic = smart_gallery_query_semantics($gallery, $publicOnly, $accessibleGalleryIds);
    return \Gallery\Models\smart_gallery_model_contains_image(
        $semantic['rules'],
        $semantic['accessible_gallery_ids'],
        $publicOnly,
        (bool) $semantic['allow_nsfw'],
        $imageId
    );
}

/** Count matching accessible images without loading image rows into PHP. */
function smart_gallery_count_images(array $gallery, bool $publicOnly): int
{
    $semantic = smart_gallery_query_semantics($gallery, $publicOnly);
    return \Gallery\Models\smart_gallery_model_count_images(
        $semantic['rules'],
        $semantic['accessible_gallery_ids'],
        $publicOnly,
        (bool) $semantic['allow_nsfw']
    );
}

/** Query one bounded database-paginated page of matching accessible images. */
function smart_gallery_query_images(array $gallery, bool $publicOnly, int $limit, int $offset): array
{
    $semantic = smart_gallery_query_semantics($gallery, $publicOnly);
    return \Gallery\Models\smart_gallery_model_query_images(
        $semantic['rules'],
        $semantic['accessible_gallery_ids'],
        $publicOnly,
        (bool) $semantic['allow_nsfw'],
        (string) $semantic['sort_mode'],
        (string) $semantic['sort_direction'],
        max(1, min(SMART_GALLERY_QUERY_MAX_PAGE_SIZE, $limit)),
        max(0, $offset)
    );
}

/** Query one bounded lazy-lightbox metadata window from the same authoritative result set. */
function smart_gallery_lightbox_fetch_images(array $gallery, bool $publicOnly, int $offset, int $limit): array
{
    return smart_gallery_query_images($gallery, $publicOnly, max(1, min(SMART_GALLERY_LIGHTBOX_MAX_WINDOW, $limit)), max(0, $offset));
}

/**
 * Load public/Admin card counts and cover rows in bounded SQL batches.
 *
 * Each batch is one UNION statement regardless of the number of Smart Gallery
 * cards in that chunk, so a page with many attachments does not issue count
 * and cover queries once per rendered card.
 */
function smart_gallery_card_summaries(array $smartGalleries, bool $publicOnly): array
{
    $byId = [];
    foreach ($smartGalleries as $smartGallery) {
        if (!is_array($smartGallery)) continue;
        $smartId = (int) ($smartGallery['id'] ?? 0);
        if ($smartId > 0) $byId[$smartId] = $smartGallery;
    }
    if ($byId === []) return [];

    $cache = &smart_gallery_graph_request_cache();
    $cacheKey = $publicOnly ? 'card_summaries_public' : 'card_summaries_admin';
    $cached = isset($cache[$cacheKey]) && is_array($cache[$cacheKey]) ? $cache[$cacheKey] : [];
    $missing = [];
    foreach ($byId as $smartId => $smartGallery) {
        if (!array_key_exists($smartId, $cached)) $missing[$smartId] = $smartGallery;
    }

    if ($missing !== []) {
        $accessibleGalleryIds = smart_gallery_accessible_gallery_ids($publicOnly);
        $allowNsfw = !$publicOnly || !nsfw_guard_schema_ready() || visitor_can_access_nsfw_content();
        foreach (array_chunk($missing, SMART_GALLERY_CARD_SUMMARY_BATCH_SIZE, true) as $chunk) {
            $definitions = [];
            foreach ($chunk as $smartId => $smartGallery) {
                try {
                    smart_gallery_assert_runtime_safe($smartGallery);
                    $definitions[] = [
                        'id' => $smartId,
                        'rules' => smart_gallery_rules_from_json((string) ($smartGallery['rules_json'] ?? '')),
                        'sort_mode' => (string) ($smartGallery['sort_mode'] ?? ''),
                        'sort_direction' => (string) ($smartGallery['sort_direction'] ?? 'desc'),
                    ];
                } catch (InvalidArgumentException) {
                    $cached[$smartId] = ['valid' => false, 'count' => 0, 'cover' => null, 'source_gallery' => null];
                }
            }
            foreach (\Gallery\Models\smart_gallery_model_card_summary_rows($definitions, $accessibleGalleryIds, $publicOnly, $allowNsfw) as $row) {
                $smartId = (int) ($row['smart_gallery_id'] ?? 0);
                if ($smartId <= 0) continue;
                $cached[$smartId] = [
                    'valid' => true,
                    'count' => max(0, (int) ($row['image_count'] ?? 0)),
                    'cover_image_id' => max(0, (int) ($row['cover_image_id'] ?? 0)),
                    'cover' => null,
                    'source_gallery' => null,
                ];
            }
        }

        $coverIds = [];
        foreach (array_keys($missing) as $smartId) {
            $coverId = (int) ($cached[$smartId]['cover_image_id'] ?? 0);
            if ($coverId > 0) $coverIds[$coverId] = $coverId;
        }
        $coversById = $coverIds === [] ? [] : \Gallery\Models\smart_gallery_model_images_by_ids(array_values($coverIds));
        $sourceGalleries = smart_gallery_source_galleries(array_values($coversById));
        foreach (array_keys($missing) as $smartId) {
            if (!isset($cached[$smartId])) {
                $cached[$smartId] = ['valid' => false, 'count' => 0, 'cover' => null, 'source_gallery' => null];
                continue;
            }
            $coverId = (int) ($cached[$smartId]['cover_image_id'] ?? 0);
            $cover = $coverId > 0 ? ($coversById[$coverId] ?? null) : null;
            $sourceGalleryId = is_array($cover) ? (int) ($cover['gallery_id'] ?? 0) : 0;
            $cached[$smartId]['cover'] = $cover;
            $cached[$smartId]['source_gallery'] = $sourceGalleryId > 0 ? ($sourceGalleries[$sourceGalleryId] ?? null) : null;
            unset($cached[$smartId]['cover_image_id']);
        }
        $cache[$cacheKey] = $cached;
    }

    $result = [];
    foreach (array_keys($byId) as $smartId) {
        if (isset($cached[$smartId]) && is_array($cached[$smartId])) $result[$smartId] = $cached[$smartId];
    }
    return $result;
}

/** Load physical source galleries for a Smart Gallery image set without N+1 lookups. */
function smart_gallery_source_galleries(array $images): array
{
    $ids = array_values(array_unique(array_filter(array_map(static fn (array $image): int => (int) ($image['gallery_id'] ?? 0), $images), static fn (int $id): bool => $id > 0)));
    if ($ids === []) return [];

    $cache = &smart_gallery_graph_request_cache();
    $cachedRows = isset($cache['source_gallery_rows']) && is_array($cache['source_gallery_rows']) ? $cache['source_gallery_rows'] : [];
    $missingIds = array_values(array_filter($ids, static fn (int $id): bool => !array_key_exists($id, $cachedRows)));
    if ($missingIds !== []) {
        $loaded = \Gallery\Models\smart_gallery_model_galleries_by_ids($missingIds);
        foreach ($missingIds as $missingId) $cachedRows[$missingId] = $loaded[$missingId] ?? null;
        $cache['source_gallery_rows'] = $cachedRows;
    }

    $byId = [];
    foreach ($ids as $id) if (isset($cachedRows[$id]) && is_array($cachedRows[$id])) $byId[$id] = $cachedRows[$id];
    return $byId;
}

/** Convert a compatible text search into a reusable OR rule tree. */
function smart_gallery_rules_from_search(string $query): array
{
    $query = public_search_normalize_query($query);
    if ($query === '') return smart_gallery_empty_rules();
    $fields = ['filename', 'title', 'description', 'gallery_title', 'ai_text'];
    return ['version' => SMART_GALLERY_RULE_VERSION, 'root' => ['type' => 'group', 'operator' => 'OR', 'children' => array_map(static fn (string $field): array => ['type' => 'condition', 'field' => $field, 'operator' => 'contains', 'value' => $query], $fields)]];
}

/** Build a translated, human-readable nested summary from authoritative rules. */
function smart_gallery_rule_summary(array $rules): array
{
    $validated = smart_gallery_validate_rules($rules);
    return smart_gallery_rule_summary_node($validated['root']);
}

/** Summarize one group recursively for Admin diagnostics. */
function smart_gallery_rule_summary_node(array $node): array
{
    if ($node['type'] === 'condition') {
        $value = $node['value'] ?? '';
        if ($node['field'] === 'tag' && !is_array($value) && (int) $value > 0) {
            $value = \Gallery\Models\smart_gallery_model_tag_name((int) $value) ?? t('smart_gallery.missing_reference', '[missing reference]');
        } elseif ($node['field'] === 'gallery' && (int) $value > 0) {
            $value = \Gallery\Models\smart_gallery_model_gallery_title((int) $value) ?? t('smart_gallery.missing_reference', '[missing reference]');
        } elseif (is_array($value)) {
            $value = implode(' – ', array_map('strval', $value));
        }
        return ['label' => t('smart_gallery.summary_condition', '{field} {operator} {value}', ['field' => smart_gallery_field_label($node['field']), 'operator' => t('smart_gallery.operator.' . $node['operator'], str_replace('_', ' ', $node['operator'])), 'value' => (string) $value]), 'children' => []];
    }
    return [
        'label' => t('smart_gallery.group.' . strtolower($node['operator']), match ($node['operator']) { 'OR' => 'Any of:', 'NOT' => 'Not:', default => 'All of:' }),
        'children' => array_map(static fn (array $child): array => smart_gallery_rule_summary_node($child), $node['children']),
    ];
}

/** Return the translated label for one stable rule field. */
function smart_gallery_field_label(string $field): string
{
    return t('smart_gallery.field.' . $field, ucfirst(str_replace('_', ' ', $field)));
}

/** Persist a private editorial image rating, where zero clears the rating. */
function smart_gallery_set_image_rating(int $imageId, int $rating): void
{
    smart_gallery_assert_mutation_ready('image.editorial_rating');
    if ($rating < 0 || $rating > 5) throw new InvalidArgumentException('Ratings must be between 0 and 5.');
    \Gallery\Models\smart_gallery_model_set_image_rating($imageId, $rating === 0 ? null : $rating, now_sql());
}

