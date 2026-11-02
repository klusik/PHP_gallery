<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/services/gallery_feature_plans.php
 * Module Type: Service
 * Purpose: Preview and revalidate gallery or subtree feature intentions before atomic application.
 * Responsibilities: Enforce existing capabilities, schema authority, coupling and stale-plan refusal.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);
namespace Gallery\Services;

use function Gallery\Core\now_sql;
use function Gallery\Models\gallery_feature_plan_model_snapshot;
use function Gallery\Models\gallery_feature_plan_model_transaction;
use function Gallery\Models\gallery_model_set_gps_map_enabled;
use function Gallery\Models\gallery_model_set_show_filenames;
use function Gallery\Models\gallery_model_set_voting_enabled;
use function Gallery\Models\gallery_model_set_picture_game_enabled;

/** Normalize a bounded ordered list without coalescing meaningful overlapping intentions.
 * @param array<array-key,array{root_id?:int,feature?:string,enabled?:bool|null,scope?:string}> $intents Untrusted semantic intentions, checked before use.
 * @return list<array{root_id:int,feature:string,enabled:bool|null,scope?:string}> Ordered intentions; null means inherited maps and omitted scope means subtree.
 */
function gallery_feature_plan_normalize(array $intents): array
{
    if ($intents === [] || count($intents) > 256) {
        throw new \InvalidArgumentException(
            'Stage between one and 256 feature changes.',
        );
    }
    $normalized = [];
    foreach ($intents as $intent) {
        if (
            !is_array($intent)
            || !isset($intent['root_id'], $intent['feature'])
            || !array_key_exists('enabled', $intent)
            || !is_int($intent['root_id'])
            || $intent['root_id'] < 1
            || !is_string($intent['feature'])
            || !in_array(
                $intent['feature'],
                ['maps', 'filenames', 'voting', 'game'],
                true,
            )
            || (!is_bool($intent['enabled'])
                && !($intent['feature'] === 'maps' && $intent['enabled'] === null))
            || (isset($intent['scope'])
                && !in_array($intent['scope'], ['gallery', 'subtree'], true))
        ) {
            throw new \InvalidArgumentException(
                'Invalid gallery feature change.',
            );
        }
        $normalized[] = [
            'root_id' => $intent['root_id'],
            'feature' => $intent['feature'],
            'enabled' => $intent['enabled'],
        ];
        if (isset($intent['scope'])) {
            $normalized[array_key_last($normalized)]['scope'] = $intent['scope'];
        }
    }
    return $normalized;
}

/** Resolve owned columns and coupled dependencies before observing optional storage.
 * @param list<array{root_id:int,feature:string,enabled:bool|null,scope?:string}> $intents Normalized intentions, with optional gallery-only scope.
 * @return list<string> Required semantic fields in deterministic order.
 */
function gallery_feature_plan_preflight(array $intents): array
{
    $features = [];
    foreach ($intents as $intent) {
        $feature = $intent['feature'];
        $capability =
            [
                'maps' => 'gallery_maps',
                'voting' => 'image_voting',
                'game' => 'picture_game',
            ][$feature] ?? '';
        if (
            $capability !== ''
            && !feature_capability_effective_enabled($capability)
        ) {
            throw new \DomainException(
                'A requested gallery feature is disabled.',
            );
        }
        if (
            $feature === 'game'
            && $intent['enabled']
            && !feature_capability_effective_enabled('image_voting')
        ) {
            throw new \DomainException(
                'Picture Game requires image voting availability.',
            );
        }
        $features[$feature] = true;
        if (
            (
                $feature === 'game'
                && $intent['enabled']
            )
            || (
                $feature === 'voting'
                && !$intent['enabled']
            )
        ) {
            $features['voting'] = true;
            $features['game'] = true;
        }
    }
    $required = array_keys($features);
    sort($required);
    $columns = [
        'id',
        'parent_id',
        'title',
        'folder_path',
        'sort_order',
        'edit_revision',
    ];
    $mapping = [
        'maps' => 'gps_map_enabled',
        'filenames' => 'show_filenames',
        'voting' => 'voting_enabled',
        'game' => 'picture_game_enabled',
    ];
    foreach ($required as $feature) {
        $columns[] = $mapping[$feature];
    }
    mutation_schema_assert_available(
        mutation_schema_table_columns_status(
            'gallery.features',
            'galleries',
            $columns,
        ),
        'gallery_features',
    );
    if (isset($features['maps'])) {
        mutation_schema_assert_available(
            presentation_gps_exif_schema_status(),
            'gallery_features_maps',
        );
        mutation_schema_assert_available(
            presentation_gps_override_schema_status(),
            'gallery_features_maps_override',
        );
    }
    if (isset($features['voting'])) {
        mutation_schema_assert_available(
            presentation_voting_schema_status(),
            'gallery_features_voting',
        );
    }
    if (isset($features['game'])) {
        mutation_schema_assert_available(
            presentation_picture_game_schema_status(),
            'gallery_features_game',
        );
    }
    return $required;
}

/** Prepare exact scope and effective changes from a single authoritative snapshot.
 * @param list<array{root_id:int,feature:string,enabled:bool|null,scope?:string}> $intents Normalized chronological intentions with optional gallery-only scope.
 * @param list<array{id:int|string,parent_id:int|string|null,title:string,folder_path:string,sort_order:int|string,edit_revision:int|string,gps_map_enabled?:int|string|null,show_filenames?:int|string,voting_enabled?:int|string,picture_game_enabled?:int|string}> $snapshot Non-secret catalog rows with preflight-selected feature columns.
 * @param bool $mapsDefault Current inherited GPS preference.
 * @return array{intents:list<array{root_id:int,feature:string,enabled:bool|null,scope?:string}>,fingerprint:string,gallery_count:int,changed_gallery_count:int,change_count:int,scope:list<array{id:int,title:string,parent_id:int}>,rows:list<array{id:int,title:string,parent_id:int,changes:list<array{feature:string,current:bool,next:bool|null,side_effect:bool,inherited:bool}>}>} Safe exact scope and changes plus comparison fingerprint.
 */
function gallery_feature_plan_build(
    array $intents,
    array $snapshot,
    bool $mapsDefault,
): array {
    $byId = [];
    $children = [];
    foreach ($snapshot as $row) {
        $id = (int) $row['id'];
        $byId[$id] = $row;
        $children[(int) ($row['parent_id'] ?? 0)][] = $id;
    }
    $targets = [];
    foreach ($intents as $intent) {
        $root = $intent['root_id'];
        if (!isset($byId[$root])) {
            throw new \DomainException('A selected gallery no longer exists.');
        }
        $queue = [$root];
        $seen = [];
        while ($queue !== []) {
            $id = array_pop($queue);
            if (isset($seen[$id])) {
                throw new \DomainException(
                    'Gallery hierarchy could not be verified.',
                );
            }
            $seen[$id] = true;
            $targets[$id][$intent['feature']] = [
                'next' => $intent['enabled'],
                'side_effect' => false,
            ];
            if ($intent['feature'] === 'game' && $intent['enabled']) {
                $targets[$id]['voting'] = [
                    'next' => true,
                    'side_effect' => true,
                ];
            }
            if ($intent['feature'] === 'voting' && !$intent['enabled']) {
                $targets[$id]['game'] = [
                    'next' => false,
                    'side_effect' => true,
                ];
            }
            if (($intent['scope'] ?? 'subtree') === 'subtree') {
                foreach ($children[$id] ?? [] as $child) {
                    $queue[] = $child;
                }
            }
        }
    }
    ksort($targets);
    $rows = [];
    $scope = [];
    $changeCount = 0;
    $mapping = [
        'maps' => 'gps_map_enabled',
        'filenames' => 'show_filenames',
        'voting' => 'voting_enabled',
        'game' => 'picture_game_enabled',
    ];
    foreach ($targets as $id => $features) {
        $row = $byId[$id];
        $safe = [
            'id' => $id,
            'title' => (string) $row['title'],
            'parent_id' => (int) ($row['parent_id'] ?? 0),
        ];
        $scope[] = $safe;
        $changes = [];
        foreach ($features as $feature => $target) {
            $stored = $row[$mapping[$feature]] ?? null;
            $current = (int) $stored === 1;
            if ($feature === 'maps') {
                $visited = [$id => true];
                $current = gallery_effective_gps_map_enabled(
                    $row,
                    /** Resolve inheritance from this verified snapshot and refuse cyclic or missing ancestors.
                     * @param int $parentId Requested ancestor identifier.
                     * @return array<string,mixed> Verified non-secret ancestor row.
                     */ static function (int $parentId) use (
                        $byId,
                        &$visited,
                    ): array {
                        if (
                            isset($visited[$parentId])
                            || !isset($byId[$parentId])
                        ) {
                            throw new \DomainException(
                                'Gallery hierarchy could not be verified.',
                            );
                        }
                        $visited[$parentId] = true;
                        return $byId[$parentId];
                    },
                );
            }
            // Map overrides compare stored preference, not its inherited effective value.
            $storedState = $stored === null ? null : (int) $stored === 1;
            $changed = $feature === 'maps'
                ? $storedState !== $target['next']
                : $current !== $target['next'];
            if ($changed) {
                $changes[] = [
                    'feature' => $feature,
                    'current' => $current,
                    'next' => $target['next'],
                    'side_effect' => $target['side_effect'],
                    'inherited' => $feature === 'maps' && $stored === null,
                ];
                $changeCount++;
            }
        }
        if ($changes !== []) {
            $rows[] = $safe + ['changes' => $changes];
        }
    }
    return [
        'intents' => $intents,
        'fingerprint' => hash('sha256', json_encode(
            [$intents, $snapshot, $mapsDefault],
            JSON_THROW_ON_ERROR,
        )),
        'gallery_count' => count($scope),
        'changed_gallery_count' => count($rows),
        'change_count' => $changeCount,
        'scope' => $scope,
        'rows' => $rows,
    ];
}

/** Preview ordered gallery or subtree changes without acquiring writer locks or persisting anything.
 * @param array<array-key,array{root_id?:int,feature?:string,enabled?:bool|null,scope?:string}> $intents Untrusted browser intentions.
 * @return array{intents:list<array{root_id:int,feature:string,enabled:bool|null,scope?:string}>,fingerprint:string,gallery_count:int,changed_gallery_count:int,change_count:int,scope:list<array{id:int,title:string,parent_id:int}>,rows:list<array{id:int,title:string,parent_id:int,changes:list<array{feature:string,current:bool,next:bool|null,side_effect:bool,inherited:bool}>}>} Exact safe preview and fingerprint.
 */
function gallery_feature_plan_preview(array $intents): array
{
    $normalized = gallery_feature_plan_normalize($intents);
    $features = gallery_feature_plan_preflight($normalized);
    return gallery_feature_plan_build(
        $normalized,
        gallery_feature_plan_model_snapshot($features),
        in_array('maps', $features, true) && exif_gps_default_enabled(),
    );
}

/** Revalidate a reviewed snapshot under locks and apply only its exact final per-row changes.
 * @param array<array-key,array{root_id?:int,feature?:string,enabled?:bool|null,scope?:string}> $intents Chronological intentions revalidated against the fingerprint.
 * @param string $fingerprint Reviewed snapshot digest.
 * @return array{entity_ids:list<int>,sidecar_refresh_failed:bool} Committed result, never a false failure after commit.
 */
function gallery_feature_plan_apply(array $intents, string $fingerprint): array
{
    $normalized = gallery_feature_plan_normalize($intents);
    $features = gallery_feature_plan_preflight($normalized);
    if (preg_match('/^[a-f0-9]{64}$/D', $fingerprint) !== 1) {
        throw new \DomainException(
            'Review the gallery changes again before applying them.',
        );
    }
    $plan = gallery_feature_plan_model_transaction(
        /** Compare and write while catalog locks remain owned by this transaction.
         * @return array<string,mixed> Revalidated committed plan.
         */ static function () use (
            $normalized,
            $features,
            $fingerprint,
        ): array {
            $plan = gallery_feature_plan_build(
                $normalized,
                gallery_feature_plan_model_snapshot($features, true),
                in_array('maps', $features, true) && exif_gps_default_enabled(),
            );
            if (!hash_equals($plan['fingerprint'], $fingerprint)) {
                throw new \DomainException(
                    'Gallery hierarchy or feature state changed. Review the changes again.',
                );
            }
            $now = now_sql();
            foreach ($plan['rows'] as $row) {
                foreach ($row['changes'] as $change) {
                    $ids = [$row['id']];
                    match ($change['feature']) {
                        'maps' => gallery_model_set_gps_map_enabled(
                            $ids,
                            $change['next'],
                            $now,
                        ),
                        'filenames' => gallery_model_set_show_filenames(
                            $ids,
                            $change['next'],
                            $now,
                        ),
                        'voting' => gallery_model_set_voting_enabled(
                            $ids,
                            $change['next'],
                            $now,
                        ),
                        'game' => gallery_model_set_picture_game_enabled(
                            $ids,
                            $change['next'],
                            $now,
                        ),
                    };
                }
            }
            return $plan;
        },
    );
    $ids = array_column($plan['rows'], 'id');
    $sidecarFailed = false;
    if (
        in_array('maps', $features, true)
        || in_array('filenames', $features, true)
    ) {
        try {
            gallery_bulk_refresh_sidecars($ids, true);
        } catch (\Throwable $exception) {
            $sidecarFailed = true;
        }
    }
    return ['entity_ids' => $ids, 'sidecar_refresh_failed' => $sidecarFailed];
}
