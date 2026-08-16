<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/content_localization.php
 * Module Type: Service Module
 *
 * Purpose:
 *   Resolves and persists optional multilingual gallery and image content.
 *
 * Responsibilities:
 *   - Keep supported content languages aligned with maintained UI languages
 *   - Preserve base title/description fields as source content
 *   - Batch-load translated overlays without public N+1 queries
 *   - Apply independent title and description fallback
 *   - Validate and persist Admin translation mutations
 */

declare(strict_types=1);

namespace Gallery\Services;

use InvalidArgumentException;
use RuntimeException;
use function Gallery\Core\db;
use function Gallery\Core\now_sql;

const CONTENT_LOCALIZATION_ENTITY_GALLERY = 'gallery';
const CONTENT_LOCALIZATION_ENTITY_IMAGE = 'image';

/** @var null|callable(string,array<int>,?string):array<int,array<string,array<string,mixed>>> */
$contentLocalizationLoaderForTests = null;

/**
 * Return content languages in the canonical maintained selector order.
 *
 * @return array<int,string> Supported two-letter language codes.
 */
function content_supported_languages(): array
{
    return translation_supported_languages();
}

/**
 * Return the Open Graph locale for one supported content language.
 *
 * @param string $language Supported two-letter code.
 * @return string Open Graph locale identifier.
 */
function content_language_og_locale(string $language): string
{
    return match (content_language_normalize($language)) {
        'cs' => 'cs_CZ',
        'de' => 'de_DE',
        'sv' => 'sv_SE',
        default => 'en_GB',
    };
}

/**
 * Normalize an optional content language.
 *
 * @param mixed $language Submitted language code.
 * @return ?string Supported code or null for unspecified/invalid values.
 */
function content_language_normalize(mixed $language): ?string
{
    $normalized = translation_normalize_language_code((string) $language);
    return in_array($normalized, content_supported_languages(), true) ? $normalized : null;
}

/**
 * Return validated storage metadata for one localization entity type.
 *
 * @param string $entityType Gallery or image architecture identifier.
 * @return array{table:string,owner_column:string,base_table:string} Storage metadata.
 */
function content_localization_storage(string $entityType): array
{
    if ($entityType === CONTENT_LOCALIZATION_ENTITY_GALLERY) {
        return ['table' => 'gallery_translations', 'owner_column' => 'gallery_id', 'base_table' => 'galleries'];
    }
    if ($entityType === CONTENT_LOCALIZATION_ENTITY_IMAGE) {
        return ['table' => 'image_translations', 'owner_column' => 'image_id', 'base_table' => 'images'];
    }
    throw new InvalidArgumentException('Unsupported localized content entity type.');
}

/**
 * Return structured schema readiness for one localized entity type.
 *
 * @param string $entityType Gallery or image architecture identifier.
 * @return array<string,mixed> Three-state feature inspection result.
 */
function content_localization_schema_status(string $entityType): array
{
    $storage = content_localization_storage($entityType);
    return schema_inspection_feature('content_localization_' . $entityType, [
        schema_inspection_column($storage['base_table'], 'content_language'),
        schema_inspection_table($storage['table']),
        schema_inspection_column($storage['table'], $storage['owner_column']),
        schema_inspection_column($storage['table'], 'language_code'),
        schema_inspection_column($storage['table'], 'title'),
        schema_inspection_column($storage['table'], 'description'),
    ]);
}

/**
 * Return whether localized storage is conclusively available.
 *
 * @param string $entityType Gallery or image architecture identifier.
 * @return bool True for complete schema.
 */
function content_localization_schema_ready(string $entityType): bool
{
    return schema_inspection_is_available(content_localization_schema_status($entityType));
}

/**
 * Install an isolated translation-row loader for standalone tests.
 *
 * @param null|callable(string,array<int>,?string):array<int,array<string,array<string,mixed>>> $loader Test loader.
 */
function content_localization_set_loader_for_tests(?callable $loader): void
{
    global $contentLocalizationLoaderForTests;
    $contentLocalizationLoaderForTests = $loader;
    content_localization_reset_request_cache();
}

/** Clear request-local translated-row caches. */
function content_localization_reset_request_cache(): void
{
    $GLOBALS['content_localization_request_cache'] = [];
}

/**
 * Load translations for entity IDs, grouped by owner and language.
 *
 * @param string $entityType Gallery or image architecture identifier.
 * @param array<int,int> $entityIds Entity identifiers.
 * @param ?string $language Optional single supported language.
 * @return array<int,array<string,array<string,mixed>>> Rows grouped by entity and language.
 */
function content_translation_rows(string $entityType, array $entityIds, ?string $language = null): array
{
    global $contentLocalizationLoaderForTests;
    $storage = content_localization_storage($entityType);
    $entityIds = array_values(array_unique(array_filter(array_map('intval', $entityIds), static fn (int $id): bool => $id > 0)));
    $language = $language === null ? null : content_language_normalize($language);
    if ($entityIds === []) {
        return [];
    }

    sort($entityIds);
    $cacheKey = $entityType . ':' . implode(',', $entityIds) . ':' . ($language ?? '*');
    $cache = $GLOBALS['content_localization_request_cache'] ?? [];
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    if (is_callable($contentLocalizationLoaderForTests)) {
        $grouped = $contentLocalizationLoaderForTests($entityType, $entityIds, $language);
        $GLOBALS['content_localization_request_cache'][$cacheKey] = $grouped;
        return $grouped;
    }
    if (!content_localization_schema_ready($entityType)) {
        $GLOBALS['content_localization_request_cache'][$cacheKey] = [];
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($entityIds), '?'));
    $sql = 'SELECT ' . $storage['owner_column'] . ' AS owner_id, language_code, title, description, created_at, updated_at FROM ' . $storage['table'] . ' WHERE ' . $storage['owner_column'] . ' IN (' . $placeholders . ')';
    $params = $entityIds;
    if ($language !== null) {
        $sql .= ' AND language_code = ?';
        $params[] = $language;
    }
    $sql .= ' ORDER BY ' . $storage['owner_column'] . ', language_code';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $grouped = [];
    foreach ($stmt->fetchAll() as $row) {
        $ownerId = (int) ($row['owner_id'] ?? 0);
        $rowLanguage = content_language_normalize($row['language_code'] ?? null);
        if ($ownerId > 0 && $rowLanguage !== null) {
            $grouped[$ownerId][$rowLanguage] = $row;
        }
    }
    $GLOBALS['content_localization_request_cache'][$cacheKey] = $grouped;
    return $grouped;
}

/**
 * Resolve one entity's title and description for a requested language.
 *
 * @param array<string,mixed> $entity Gallery or image row.
 * @param string $language Requested viewer language.
 * @param array<string,array<string,mixed>> $translations Preloaded rows keyed by language.
 * @param bool $fallbackMissingFields Whether blank translated fields fall back independently.
 * @return array{title:string,description:string,requested_language:string,source_language:?string,title_source:string,description_source:string,translation:?array<string,mixed>}
 */
function content_localized_fields(array $entity, string $language, array $translations = [], bool $fallbackMissingFields = true): array
{
    $requested = content_language_normalize($language) ?? translation_default_language();
    $sourceLanguage = content_language_normalize($entity['content_language'] ?? null);
    $translation = isset($translations[$requested]) && is_array($translations[$requested]) ? $translations[$requested] : null;
    $baseTitle = trim((string) ($entity['title'] ?? ''));
    $baseDescription = (string) ($entity['description'] ?? '');
    $translatedTitle = trim((string) ($translation['title'] ?? ''));
    $translatedDescription = (string) ($translation['description'] ?? '');
    $useTranslation = $translation !== null && $requested !== $sourceLanguage;
    $useTranslatedTitle = $useTranslation && ($translatedTitle !== '' || !$fallbackMissingFields);
    $useTranslatedDescription = $useTranslation && (trim($translatedDescription) !== '' || !$fallbackMissingFields);

    return [
        'title' => $useTranslatedTitle ? $translatedTitle : $baseTitle,
        'description' => $useTranslatedDescription ? $translatedDescription : $baseDescription,
        'requested_language' => $requested,
        'source_language' => $sourceLanguage,
        'title_source' => $useTranslatedTitle ? 'translation' : 'source',
        'description_source' => $useTranslatedDescription ? 'translation' : 'source',
        'translation' => $translation,
    ];
}

/**
 * Localize a batch of entities without per-row queries.
 *
 * @param string $entityType Gallery or image architecture identifier.
 * @param array<int,array<string,mixed>> $entities Entity rows.
 * @param string $language Requested viewer language.
 * @return array<int,array<string,mixed>> Rows with resolved title/description and localization metadata.
 */
function content_localize_entities(string $entityType, array $entities, string $language): array
{
    $ids = array_map(static fn (array $entity): int => (int) ($entity['id'] ?? 0), $entities);
    $translations = content_translation_rows($entityType, $ids, $language);
    foreach ($entities as &$entity) {
        $id = (int) ($entity['id'] ?? 0);
        // A photo translation is one visible caption variant. Once present, its
        // blank field stays blank so source-language text is not mixed beneath
        // translated photo text. Galleries retain independent field fallback.
        $resolved = content_localized_fields($entity, $language, $translations[$id] ?? [], $entityType !== 'image');
        $entity['source_title'] = (string) ($entity['title'] ?? '');
        $entity['source_description'] = (string) ($entity['description'] ?? '');
        $entity['title'] = $resolved['title'];
        $entity['description'] = $resolved['description'];
        $entity['_content_localization'] = $resolved;
    }
    unset($entity);
    return $entities;
}

/**
 * Localize one entity using the shared batch path.
 *
 * @param string $entityType Gallery or image architecture identifier.
 * @param array<string,mixed> $entity Entity row.
 * @param string $language Requested viewer language.
 * @return array<string,mixed> Localized entity row.
 */
function content_localize_entity(string $entityType, array $entity, string $language): array
{
    return content_localize_entities($entityType, [$entity], $language)[0];
}

/**
 * Persist one entity's source-language tag and complete submitted translations.
 *
 * @param string $entityType Gallery or image architecture identifier.
 * @param int $entityId Owning entity identifier.
 * @param mixed $sourceLanguage Optional source language.
 * @param mixed $translations Submitted translations keyed by language.
 */
function content_save_localizations(string $entityType, int $entityId, mixed $sourceLanguage, mixed $translations): void
{
    if ($entityId < 1 || !content_localization_schema_ready($entityType)) {
        throw new RuntimeException('Multilingual content storage is unavailable. Apply pending database migrations and try again.');
    }
    $storage = content_localization_storage($entityType);
    $normalizedSource = content_language_normalize($sourceLanguage);
    $submitted = is_array($translations) ? $translations : [];
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $sourceStmt = $pdo->prepare('UPDATE ' . $storage['base_table'] . ' SET content_language = ?, updated_at = ? WHERE id = ?');
        $sourceStmt->execute([$normalizedSource, now_sql(), $entityId]);
        $deleteStmt = $pdo->prepare('DELETE FROM ' . $storage['table'] . ' WHERE ' . $storage['owner_column'] . ' = ? AND language_code = ?');
        $upsertStmt = $pdo->prepare('INSERT INTO ' . $storage['table'] . ' (' . $storage['owner_column'] . ', language_code, title, description, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE title = VALUES(title), description = VALUES(description), updated_at = VALUES(updated_at)');
        foreach (content_supported_languages() as $language) {
            if ($language === $normalizedSource) {
                $deleteStmt->execute([$entityId, $language]);
                continue;
            }
            $row = is_array($submitted[$language] ?? null) ? $submitted[$language] : [];
            $title = trim((string) ($row['title'] ?? ''));
            $description = (string) ($row['description'] ?? '');
            if ($title === '' && trim($description) === '') {
                $deleteStmt->execute([$entityId, $language]);
                continue;
            }
            $now = now_sql();
            $upsertStmt->execute([$entityId, $language, $title !== '' ? $title : null, trim($description) !== '' ? $description : null, $now, $now]);
        }
        $pdo->commit();
    } catch (\Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
    content_localization_reset_request_cache();
}
