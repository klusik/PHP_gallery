<?php

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

/** Inspect the complete persisted Smart Gallery capability with three-state semantics. */
function smart_gallery_schema_status(): array
{
    return mutation_schema_tables_status('mutation.smart_galleries', [
        'smart_galleries' => ['id', 'slug', 'rules_json', 'rule_version', 'enabled', 'visibility', 'placement_mode', 'parent_gallery_id', 'sort_mode', 'sort_direction'],
        'smart_gallery_placements' => ['smart_gallery_id', 'gallery_id', 'created_at'],
        'images' => ['id', 'gallery_id', 'editorial_rating'],
    ]);
}

/** Return true only when Smart Gallery storage is conclusively available. */
function smart_gallery_schema_ready(): bool
{
    return schema_inspection_is_available(smart_gallery_schema_status());
}

/** Refuse a Smart Gallery write before any mutation when schema is inconclusive. */
function smart_gallery_assert_mutation_ready(string $operation): void
{
    mutation_schema_assert_available(
        smart_gallery_schema_status(),
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

/** Compile a validated rule document into SQL and bound parameters. */
function smart_gallery_compile_rules(array $rules): array
{
    $validated = smart_gallery_validate_rules($rules);
    $params = [];
    $sql = smart_gallery_compile_node($validated['root'], $params);
    return ['sql' => $sql, 'params' => $params];
}

/** Compile one trusted, validated rule node. */
function smart_gallery_compile_node(array $node, array &$params): string
{
    if ($node['type'] === 'group') {
        $parts = [];
        foreach ($node['children'] as $child) {
            $parts[] = smart_gallery_compile_node($child, $params);
        }
        if ($parts === []) {
            return '1=1';
        }
        if ($node['operator'] === 'NOT') {
            return '(NOT (' . $parts[0] . '))';
        }
        return '(' . implode(' ' . $node['operator'] . ' ', $parts) . ')';
    }
    return smart_gallery_compile_condition($node, $params);
}

/** Compile one allowlisted condition into a parameterized SQL predicate. */
function smart_gallery_compile_condition(array $condition, array &$params): string
{
    $field = $condition['field'];
    $operator = $condition['operator'];
    $value = $condition['value'] ?? null;
    $columns = [
        'capture_date' => 'i.exif_taken_at', 'camera_make' => 'i.exif_camera_make', 'camera_model' => 'i.exif_camera_model',
        'lens' => 'i.exif_lens_model', 'iso' => 'i.exif_iso', 'aperture' => 'i.exif_aperture', 'focal_length' => 'i.exif_focal_length',
        'exposure_time' => 'i.exif_exposure_time', 'exif_orientation' => 'i.exif_orientation', 'filename' => 'i.filename',
        'title' => 'i.title', 'description' => 'i.description', 'gallery_title' => 'g.title', 'rating' => 'i.editorial_rating',
        'extension' => "LOWER(SUBSTRING_INDEX(i.filename, '.', -1))", 'width' => 'i.width', 'height' => 'i.height', 'file_size' => 'i.file_size',
    ];
    if ($field === 'gallery') {
        if (in_array($operator, ['equals', 'not_equals'], true)) {
            $params[] = (int) $value;
            return 'i.gallery_id ' . ($operator === 'equals' ? '=' : '<>') . ' ?';
        }
        $params[] = (int) $value;
        $params[] = (int) $value;
        $predicate = "(i.gallery_id = ? OR g.folder_path LIKE CONCAT((SELECT sg_parent.folder_path FROM galleries sg_parent WHERE sg_parent.id = ?), '/%'))";
        return $operator === 'under' ? $predicate : '(NOT ' . $predicate . ')';
    }
    if ($field === 'tag') {
        if ($operator === 'untagged') {
            return "NOT EXISTS (SELECT 1 FROM image_tags sg_it WHERE sg_it.image_id = i.id) AND NOT EXISTS (SELECT 1 FROM gallery_tags sg_gt JOIN galleries sg_tag_gallery ON sg_tag_gallery.id = sg_gt.gallery_id WHERE g.folder_path = sg_tag_gallery.folder_path OR g.folder_path LIKE CONCAT(sg_tag_gallery.folder_path, '/%'))";
        }
        $ids = is_array($value) ? array_values($value) : [(int) $value];
        if ($operator === 'has_all_tags') {
            $allPredicates = [];
            foreach ($ids as $tagId) {
                $params[] = $tagId;
                $params[] = $tagId;
                $allPredicates[] = "(EXISTS (SELECT 1 FROM image_tags sg_it WHERE sg_it.image_id = i.id AND sg_it.tag_id = ?) OR EXISTS (SELECT 1 FROM gallery_tags sg_gt JOIN galleries sg_tag_gallery ON sg_tag_gallery.id = sg_gt.gallery_id WHERE sg_gt.tag_id = ? AND (g.folder_path = sg_tag_gallery.folder_path OR g.folder_path LIKE CONCAT(sg_tag_gallery.folder_path, '/%'))))";
            }
            return '(' . implode(' AND ', $allPredicates) . ')';
        }
        $marks = implode(',', array_fill(0, count($ids), '?'));
        array_push($params, ...$ids, ...$ids);
        $exists = "(EXISTS (SELECT 1 FROM image_tags sg_it WHERE sg_it.image_id = i.id AND sg_it.tag_id IN ($marks)) OR EXISTS (SELECT 1 FROM gallery_tags sg_gt JOIN galleries sg_tag_gallery ON sg_tag_gallery.id = sg_gt.gallery_id WHERE sg_gt.tag_id IN ($marks) AND (g.folder_path = sg_tag_gallery.folder_path OR g.folder_path LIKE CONCAT(sg_tag_gallery.folder_path, '/%'))))";
        return $operator === 'not_has_tag' ? '(NOT ' . $exists . ')' : $exists;
    }
    if ($field === 'gps') {
        $exists = '(i.gps_lat IS NOT NULL AND i.gps_lng IS NOT NULL)';
        return $operator === 'exists' ? $exists : '(NOT ' . $exists . ')';
    }
    if ($field === 'ai_text' || $field === 'ai_metadata') {
        $base = 'EXISTS (SELECT 1 FROM image_ai_metadata sg_ai WHERE sg_ai.image_id = i.id';
        if ($field === 'ai_metadata') {
            return $operator === 'exists' ? $base . ')' : 'NOT ' . $base . ')';
        }
        $expression = 'sg_ai.searchable_text';
        $innerParams = [];
        $predicate = smart_gallery_compile_scalar($expression, $operator, $value, $innerParams);
        array_push($params, ...$innerParams);
        return $operator === 'missing' || $operator === 'is_empty' ? 'NOT EXISTS (SELECT 1 FROM image_ai_metadata sg_ai WHERE sg_ai.image_id = i.id AND ' . smart_gallery_nonempty_sql($expression) . ')' : $base . ' AND ' . $predicate . ')';
    }
    if ($field === 'duplicate_status') {
        $pair = "EXISTS (SELECT 1 FROM images sg_dupe WHERE sg_dupe.id <> i.id AND i.checksum IS NOT NULL AND i.checksum <> '' AND sg_dupe.checksum = i.checksum)";
        $unresolved = "EXISTS (SELECT 1 FROM images sg_dupe WHERE sg_dupe.id <> i.id AND i.checksum IS NOT NULL AND i.checksum <> '' AND sg_dupe.checksum = i.checksum AND NOT EXISTS (SELECT 1 FROM duplicate_photo_ledger_pairs sg_dl WHERE sg_dl.image_id_low = LEAST(i.id, sg_dupe.id) AND sg_dl.image_id_high = GREATEST(i.id, sg_dupe.id)))";
        return match ($operator) { 'unresolved' => $unresolved, 'resolved' => '(' . $pair . ' AND NOT ' . $unresolved . ')', 'exists' => $pair, default => '(NOT ' . $pair . ')' };
    }
    if ($field === 'media_orientation') {
        return match ($operator) { 'landscape' => 'i.width > i.height', 'portrait' => 'i.height > i.width', default => 'i.width = i.height' };
    }
    if ($operator === 'unrated') {
        return 'i.editorial_rating IS NULL';
    }
    return smart_gallery_compile_scalar($columns[$field], $operator, $value, $params);
}

/** Compile a scalar comparison using only a trusted column expression. */
function smart_gallery_compile_scalar(string $column, string $operator, mixed $value, array &$params): string
{
    if ($operator === 'exists') return smart_gallery_nonempty_sql($column);
    if ($operator === 'missing') return '(' . $column . ' IS NULL OR ' . $column . " = '')";
    if ($operator === 'is_empty') return '(' . $column . ' IS NULL OR TRIM(' . $column . ") = '')";
    if ($operator === 'not_empty') return smart_gallery_nonempty_sql($column);
    if ($operator === 'between') { $params[] = $value[0]; $params[] = $value[1]; return "$column BETWEEN ? AND ?"; }
    if ($operator === 'year') { $params[] = (int) $value; return "YEAR($column) = ?"; }
    if ($operator === 'month') { $params[] = (int) $value; return "MONTH($column) = ?"; }
    $sqlOperators = ['equals' => '=', 'not_equals' => '<>', 'gt' => '>', 'gte' => '>=', 'lt' => '<', 'lte' => '<=', 'before' => '<', 'after' => '>', 'exact' => '='];
    if (isset($sqlOperators[$operator])) { $params[] = $value; return "$column {$sqlOperators[$operator]} ?"; }
    $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string) $value);
    $pattern = match ($operator) { 'starts_with' => $escaped . '%', 'ends_with' => '%' . $escaped, default => '%' . $escaped . '%' };
    $params[] = $pattern;
    return $column . (in_array($operator, ['not_contains'], true) ? ' NOT LIKE ?' : ' LIKE ?') . " ESCAPE '\\\\'";
}

/** Return a reusable SQL non-empty test for a trusted column expression. */
function smart_gallery_nonempty_sql(string $column): string
{
    return '(' . $column . ' IS NOT NULL AND TRIM(' . $column . ") <> '')";
}

/** Return all definitions for the Admin list. */
function smart_galleries_all(): array
{
    if (!smart_gallery_schema_ready()) return [];
    return smart_gallery_attach_placement_ids(db()->query('SELECT * FROM smart_galleries ORDER BY title ASC, id ASC')->fetchAll());
}

/** Attach physical placement IDs to definition rows without issuing N+1 queries. */
function smart_gallery_attach_placement_ids(array $rows): array
{
    if ($rows === []) return [];
    $placements = [];
    foreach (db()->query('SELECT smart_gallery_id, gallery_id FROM smart_gallery_placements ORDER BY gallery_id')->fetchAll() as $placement) {
        $placements[(int) $placement['smart_gallery_id']][] = (int) $placement['gallery_id'];
    }
    foreach ($rows as &$row) $row['placement_gallery_ids'] = $placements[(int) ($row['id'] ?? 0)] ?? [];
    unset($row);
    return $rows;
}

/** Find one definition by id. */
function smart_gallery_find(int $id): ?array
{
    if (!smart_gallery_schema_ready()) return null;
    $stmt = db()->prepare('SELECT * FROM smart_galleries WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return is_array($row) ? smart_gallery_attach_placement_ids([$row])[0] : null;
}

/** Find one enabled published definition by slug. */
function smart_gallery_find_public(string $slug): ?array
{
    if (!smart_gallery_schema_ready()) return null;
    $stmt = db()->prepare("SELECT * FROM smart_galleries WHERE slug = ? AND enabled = 1 AND visibility = 'public' LIMIT 1");
    $stmt->execute([$slug]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

/** Create or update a definition after normalizing every submitted value. */
function smart_gallery_save(array $input, int $id = 0): array
{
    smart_gallery_assert_mutation_ready($id > 0 ? 'smart_gallery.update' : 'smart_gallery.create');
    $title = trim((string) ($input['title'] ?? ''));
    if ($title === '') throw new InvalidArgumentException('Enter a Smart Gallery title.');
    $rules = is_array($input['rules'] ?? null) ? smart_gallery_validate_rules($input['rules']) : smart_gallery_rules_from_json((string) ($input['rules_json'] ?? ''));
    $submittedSlug = trim((string) ($input['slug'] ?? ''));
    $slug = smart_gallery_unique_slug($submittedSlug !== '' ? $submittedSlug : $title, $id);
    $visibility = ($input['visibility'] ?? 'private') === 'public' ? 'public' : 'private';
    $placementMode = in_array($input['placement_mode'] ?? '', ['unlisted', 'root', 'gallery'], true) ? (string) $input['placement_mode'] : 'unlisted';
    $sortModes = ['capture_date', 'filename', 'created_at', 'title', 'rating', 'default'];
    $sortMode = in_array($input['sort_mode'] ?? '', $sortModes, true) ? (string) $input['sort_mode'] : 'capture_date';
    $direction = ($input['sort_direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
    $json = json_encode($rules, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    if ($id > 0) {
        $stmt = db()->prepare('UPDATE smart_galleries SET title=?, slug=?, description=?, rules_json=?, rule_version=?, enabled=?, visibility=?, placement_mode=?, parent_gallery_id=?, sort_mode=?, sort_direction=?, updated_at=? WHERE id=?');
        $stmt->execute([$title, $slug, trim((string) ($input['description'] ?? '')), $json, SMART_GALLERY_RULE_VERSION, !empty($input['enabled']) ? 1 : 0, $visibility, $placementMode, null, $sortMode, $direction, now_sql(), $id]);
    } else {
        $stmt = db()->prepare('INSERT INTO smart_galleries (title,slug,description,rules_json,rule_version,enabled,visibility,placement_mode,parent_gallery_id,sort_mode,sort_direction,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $now = now_sql();
        $stmt->execute([$title, $slug, trim((string) ($input['description'] ?? '')), $json, SMART_GALLERY_RULE_VERSION, !empty($input['enabled']) ? 1 : 0, $visibility, $placementMode, null, $sortMode, $direction, $now, $now]);
        $id = (int) db()->lastInsertId();
    }
    return smart_gallery_find($id) ?? throw new InvalidArgumentException('The Smart Gallery could not be loaded after saving.');
}

/** Generate a unique stable public slug. */
function smart_gallery_unique_slug(string $value, int $excludeId = 0): string
{
    $base = slugify($value) ?: 'smart-gallery';
    $candidate = $base;
    for ($suffix = 2; $suffix < 10000; $suffix++) {
        $stmt = db()->prepare('SELECT id FROM smart_galleries WHERE slug = ? AND id <> ? LIMIT 1');
        $stmt->execute([$candidate, $excludeId]);
        if (!$stmt->fetchColumn()) return $candidate;
        $candidate = $base . '-' . $suffix;
    }
    throw new InvalidArgumentException('A unique Smart Gallery slug could not be generated.');
}

/** Delete one Smart Gallery definition without touching images or files. */
function smart_gallery_delete(int $id): void
{
    smart_gallery_assert_mutation_ready('smart_gallery.delete');
    $stmt = db()->prepare('DELETE FROM smart_galleries WHERE id = ?');
    $stmt->execute([$id]);
}

/** Duplicate a definition as a disabled private draft. */
function smart_gallery_duplicate(int $id): array
{
    smart_gallery_assert_mutation_ready('smart_gallery.duplicate');
    $source = smart_gallery_find($id) ?? throw new InvalidArgumentException('Smart Gallery not found.');
    return smart_gallery_save(['title' => $source['title'] . ' copy', 'description' => $source['description'], 'rules_json' => $source['rules_json'], 'enabled' => 0, 'visibility' => 'private', 'placement_mode' => 'unlisted', 'sort_mode' => $source['sort_mode'], 'sort_direction' => $source['sort_direction']]);
}

/** Return Smart Galleries assigned to the public root or one physical parent gallery. */
function smart_galleries_for_placement(?int $parentGalleryId, bool $publicOnly): array
{
    if (!smart_gallery_schema_ready()) return [];
    $join = '';
    $where = "sg.placement_mode = 'root'";
    $params = [];
    if ($parentGalleryId !== null) {
        $join = ' INNER JOIN smart_gallery_placements sgp ON sgp.smart_gallery_id = sg.id';
        $where = "sg.placement_mode = 'gallery' AND sgp.gallery_id = ?";
        $params[] = $parentGalleryId;
    }
    // Placement controls discoverability only; disabled/private definitions never become listing cards, including in an authenticated public-page preview.
    $where .= " AND sg.enabled = 1 AND sg.visibility = 'public'";
    $stmt = db()->prepare('SELECT sg.* FROM smart_galleries sg' . $join . ' WHERE ' . $where . ' ORDER BY sg.title, sg.id');
    $stmt->execute($params);
    return array_map(static function (array $row): array { $row['__smart_gallery'] = 1; return $row; }, $stmt->fetchAll());
}

/** Replace placements beneath one physical gallery without changing other parents. */
function smart_gallery_assign_children_to_gallery(int $galleryId, array $selectedIds): void
{
    smart_gallery_assert_mutation_ready('smart_gallery.assign_children');
    $selectedIds = array_values(array_unique(array_filter(array_map('intval', $selectedIds), static fn (int $id): bool => $id > 0)));
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $clear = $pdo->prepare('DELETE FROM smart_gallery_placements WHERE gallery_id = ?');
        $clear->execute([$galleryId]);
        if ($selectedIds) {
            $marks = implode(',', array_fill(0, count($selectedIds), '?'));
            $valid = $pdo->prepare("SELECT id FROM smart_galleries WHERE id IN ($marks)");
            $valid->execute($selectedIds);
            $validIds = array_map('intval', $valid->fetchAll(\PDO::FETCH_COLUMN));
            if (count($validIds) !== count($selectedIds)) throw new InvalidArgumentException('One or more selected Smart Galleries no longer exist.');
            $place = $pdo->prepare('INSERT INTO smart_gallery_placements (smart_gallery_id, gallery_id, created_at) VALUES (?, ?, ?)');
            foreach ($validIds as $smartGalleryId) $place->execute([$smartGalleryId, $galleryId, now_sql()]);
            $markAsChildren = $pdo->prepare("UPDATE smart_galleries SET placement_mode='gallery', parent_gallery_id=NULL, updated_at=? WHERE id IN ($marks)");
            $markAsChildren->execute(array_merge([now_sql()], $validIds));
        }
        $pdo->commit();
    } catch (\Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $exception;
    }
}

/** Return every physical gallery currently listing one Smart Gallery. */
function smart_gallery_placement_galleries(int $smartGalleryId): array
{
    if (!smart_gallery_schema_ready() || $smartGalleryId <= 0) return [];
    $stmt = db()->prepare('SELECT g.id, g.title, g.folder_path FROM smart_gallery_placements sgp INNER JOIN galleries g ON g.id = sgp.gallery_id WHERE sgp.smart_gallery_id = ? ORDER BY g.folder_path, g.title, g.id');
    $stmt->execute([$smartGalleryId]);
    return $stmt->fetchAll();
}

/** Remove one physical placement without modifying any other attachment. */
function smart_gallery_remove_from_gallery(int $smartGalleryId, int $galleryId): bool
{
    smart_gallery_assert_mutation_ready('smart_gallery.remove_placement');
    if ($smartGalleryId <= 0 || $galleryId <= 0) throw new InvalidArgumentException('Select a valid Smart Gallery placement.');
    $stmt = db()->prepare('DELETE FROM smart_gallery_placements WHERE smart_gallery_id = ? AND gallery_id = ?');
    $stmt->execute([$smartGalleryId, $galleryId]);
    return $stmt->rowCount() > 0;
}

/** Return source gallery ids accessible in the current Admin or public context. */
function smart_gallery_accessible_gallery_ids(bool $publicOnly): array
{
    $rows = db()->query('SELECT * FROM galleries')->fetchAll();
    $ids = [];
    foreach ($rows as $gallery) {
        if (!$publicOnly || visitor_can_access_gallery($gallery)) $ids[] = (int) $gallery['id'];
    }
    return $ids;
}

/** Count matching accessible images without loading image rows into PHP. */
function smart_gallery_count_images(array $gallery, bool $publicOnly): int
{
    [$where, $params] = smart_gallery_query_where($gallery, $publicOnly);
    $stmt = db()->prepare('SELECT COUNT(*) FROM images i INNER JOIN galleries g ON g.id=i.gallery_id WHERE ' . $where);
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

/** Query one database-paginated page of matching accessible images. */
function smart_gallery_query_images(array $gallery, bool $publicOnly, int $limit, int $offset): array
{
    [$where, $params] = smart_gallery_query_where($gallery, $publicOnly);
    $order = smart_gallery_order_sql((string) ($gallery['sort_mode'] ?? ''), (string) ($gallery['sort_direction'] ?? 'desc'));
    $sql = 'SELECT i.*, g.title AS source_gallery_title, g.slug AS source_gallery_slug, g.folder_path AS source_gallery_folder_path, g.visibility AS source_gallery_visibility, g.access_mode AS source_gallery_access_mode FROM images i INNER JOIN galleries g ON g.id=i.gallery_id WHERE ' . $where . ' ORDER BY ' . $order . ' LIMIT ' . max(1, min(200, $limit)) . ' OFFSET ' . max(0, $offset);
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/** Build the common rule plus access predicate used by count and page queries. */
function smart_gallery_query_where(array $gallery, bool $publicOnly): array
{
    if (!smart_gallery_schema_ready()) throw new InvalidArgumentException('Smart Gallery storage is unavailable.');
    $rules = smart_gallery_rules_from_json((string) ($gallery['rules_json'] ?? ''));
    $compiled = smart_gallery_compile_rules($rules);
    $ids = smart_gallery_accessible_gallery_ids($publicOnly);
    if ($ids === []) return ['1=0', []];
    $where = $compiled['sql'] . ' AND i.gallery_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
    if ($publicOnly) {
        $where .= " AND i.visibility = 'public'";
        if (!visitor_can_access_nsfw_content()) {
            $where .= ' AND COALESCE(i.nsfw_enabled, 0) = 0 AND COALESCE(g.nsfw_enabled, 0) = 0';
        }
    }
    return [$where, array_merge($compiled['params'], $ids)];
}

/** Return a hardcoded safe ORDER BY expression for one supported mode. */
function smart_gallery_order_sql(string $mode, string $direction): string
{
    $direction = $direction === 'asc' ? 'ASC' : 'DESC';
    $column = match ($mode) { 'filename' => 'i.filename', 'created_at' => 'i.created_at', 'title' => 'COALESCE(NULLIF(i.title,\'\'),i.filename)', 'rating' => 'i.editorial_rating', 'default' => 'i.sort_order', default => 'i.exif_taken_at' };
    return $column . ' ' . $direction . ', i.id ' . $direction;
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
            $stmt = db()->prepare('SELECT name FROM tags WHERE id = ?'); $stmt->execute([(int) $value]);
            $value = $stmt->fetchColumn() ?: t('smart_gallery.missing_reference', '[missing reference]');
        } elseif ($node['field'] === 'gallery' && (int) $value > 0) {
            $stmt = db()->prepare('SELECT title FROM galleries WHERE id = ?'); $stmt->execute([(int) $value]);
            $value = $stmt->fetchColumn() ?: t('smart_gallery.missing_reference', '[missing reference]');
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
    $stmt = db()->prepare('UPDATE images SET editorial_rating = ?, updated_at = ? WHERE id = ?');
    $stmt->execute([$rating === 0 ? null : $rating, now_sql(), $imageId]);
}
