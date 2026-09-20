<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/services/admin_operation_keys/diagnostics.php
 * Module Type: Service Part
 * Purpose: Preserve bounded partial-success feedback without persisting native diagnostics.
 * Responsibilities:
 *   - Project closed reason/count records and legacy-compatible translated upload messages.
 * Author: Rudolf Klusal
 * Contact: https://github.com/klusik
 * License: MIT License (see LICENSE file in repository)
 * Notes: Loaded only by the admin_operation_keys service entry point.
 */
declare(strict_types=1);

namespace Gallery\Services;

use const Gallery\Core\ADMIN_OPERATION_DIAGNOSTIC_MAX_FILENAMES;
use const Gallery\Core\ADMIN_OPERATION_DIAGNOSTIC_FILENAME_MAX_BYTES;
use const Gallery\Core\ADMIN_OPERATION_DIAGNOSTIC_MAX_COUNT;
use const Gallery\Core\ADMIN_OPERATION_MAX_FILES;

/**
 * Admit only a bounded safe image basename, never a path, URL or native error.
 *
 * @param scalar|array<array-key,mixed>|object|resource|null $value Untrusted filename entry; only a bounded safe image-basename string survives projection.
 * @return string Safe basename or an empty withheld marker.
 */
function admin_operation_diagnostic_filename(mixed $value): string
{
    if (!is_string($value) || strlen($value) > ADMIN_OPERATION_DIAGNOSTIC_FILENAME_MAX_BYTES
        || preg_match('//u', $value) !== 1 || preg_match('~[\\\\/:?=#\x00-\x1f\x7f]~', $value)
        || !preg_match('/^[^.].*\.(?:jpe?g|png|gif|webp|avif|heic|heif|dng)$/iD', $value)) {
        return '';
    }
    return $value;
}

/**
 * Preserve safe filenames and disclose how many provided entries were withheld.
 *
 * @param array<array-key,mixed>|scalar|object|resource|null $values Raw filename list; non-arrays become an empty list and non-string entries are withheld.
 * @param int $limit Maximum admitted entries, capped by the classic request file bound.
 * @return array{filenames:list<string>,filenames_omitted:int} Bounded diagnostic identity data.
 */
function admin_operation_diagnostic_filenames(mixed $values, int $limit = ADMIN_OPERATION_DIAGNOSTIC_MAX_FILENAMES): array
{
    $values = is_array($values) ? $values : [];
    $names = [];
    foreach (array_slice($values, 0, max(0, min(ADMIN_OPERATION_MAX_FILES, $limit))) as $value) {
        $name = admin_operation_diagnostic_filename($value);
        if ($name !== '') {
            $names[] = $name;
        }
    }
    return ['filenames' => $names, 'filenames_omitted' => min(ADMIN_OPERATION_DIAGNOSTIC_MAX_COUNT, max(0, count($values) - count($names)))];
}

/**
 * Bound one processing counter while excluding arbitrary event payloads.
 *
 * @param scalar|array<array-key,mixed>|object|resource|null $value Counter candidate; integers, floats and digit strings are bounded, other types become zero.
 * @return int Nonnegative bounded integer.
 */
function admin_operation_diagnostic_count(mixed $value): int
{
    return is_int($value) || is_float($value) || (is_string($value) && ctype_digit($value))
        ? max(0, min(ADMIN_OPERATION_DIAGNOSTIC_MAX_COUNT, (int) $value)) : 0;
}

/**
 * Prepare a closed, translated event; raw log text and exception data never enter it.
 *
 * @param 'upload_stored'|'upload_scanned'|'upload_renamed'|'scan_failed'|'thumbnail_failed'|'rename_warning'|'rename_failed' $code Closed upload diagnostic reason.
 * @param int $count Bounded item count.
 * @param list<string> $filenames Already validated bounded image basenames.
 * @return array{code:'upload_stored'|'upload_scanned'|'upload_renamed'|'scan_failed'|'thumbnail_failed'|'rename_warning'|'rename_failed',count:int,filenames:list<string>,message:string} Legacy-compatible translated event, without raw event context.
 */
function admin_operation_diagnostic_event(string $code, int $count, array $filenames = []): array
{
    $fallback = match ($code) {
        'upload_stored' => 'Stored uploaded files: {count}.',
        'upload_scanned' => 'Indexed uploaded images: {count}.',
        'upload_renamed' => 'Renamed uploaded images: {count}.',
        'scan_failed' => 'Files stored but not indexed: {count}. Review these files before uploading again. {filenames}',
        'thumbnail_failed' => 'Thumbnail generation failures: {count}. Originals were retained. {filenames}',
        'rename_warning' => 'Upload renaming reported {count} warning(s). Review the uploaded filenames before continuing.',
        'rename_failed' => 'Upload renaming reported {count} failure(s). Review existing files; do not repeat the upload as a repair.',
        default => throw new \LogicException('Unsupported safe upload diagnostic.'),
    };
    $parameters = ['count' => (string) $count, 'filenames' => implode(', ', $filenames)];
    $message = function_exists('Gallery\\Services\\t')
        ? t('admin.operation.diagnostic_' . $code, $fallback, $parameters)
        : strtr($fallback, ['{count}' => $parameters['count'], '{filenames}' => $parameters['filenames']]);
    return ['code' => $code, 'count' => $count, 'filenames' => $filenames, 'message' => $message];
}

/**
 * Replace raw diagnostics with safe typed details and compatible progress-log fields.
 *
 * Raw event time/context/message fields and rename/thumbnail exception strings
 * are never copied. Filename omissions remain explicit. Completed replay does
 * not call this projector again, preserving the original translated messages.
 *
 * @param array<string,mixed> $response Newly built upload result or operator-verified result.
 * @return array{
 *   filenames:list<string>,filenames_omitted:int,
 *   uploaded?:int,scanned?:int,renamed?:int,thumbnails?:int,scan_failed?:int,thumbnail_failed?:int,
 *   scan_failed_filenames:list<string>,thumbnail_failed_filenames:list<string>,
 *   thumbnail_errors:list<string>,rename_warnings:list<string>,rename_failures:list<string>,
 *   upload_events:list<array{code:'upload_stored'|'upload_scanned'|'upload_renamed'|'scan_failed'|'thumbnail_failed'|'rename_warning'|'rename_failed',count:int,filenames:list<string>,message:string}>,
 *   upload_diagnostics:list<array{code:'scan_failed'|'thumbnail_failed'|'rename_warning'|'rename_failed',count:int,filenames:list<string>,filenames_omitted:int}>
 * } Bounded compatibility fields and closed partial-success records; absent counters stay absent.
 */
function admin_operation_upload_diagnostics(array $response): array
{
    $safe = ['scan_failed_filenames' => [], 'thumbnail_failed_filenames' => [],
        'thumbnail_errors' => [], 'rename_warnings' => [], 'rename_failures' => [],
        'upload_events' => [], 'upload_diagnostics' => []];
    $names = admin_operation_diagnostic_filenames($response['filenames'] ?? [], ADMIN_OPERATION_MAX_FILES);
    $safe['filenames'] = $names['filenames'];
    $safe['filenames_omitted'] = max($names['filenames_omitted'], admin_operation_diagnostic_count($response['filenames_omitted'] ?? 0));
    foreach (['uploaded', 'scanned', 'renamed', 'thumbnails', 'scan_failed', 'thumbnail_failed'] as $field) {
        if (array_key_exists($field, $response)) {
            $safe[$field] = admin_operation_diagnostic_count($response[$field]);
        }
    }
    foreach (['uploaded' => 'upload_stored', 'scanned' => 'upload_scanned', 'renamed' => 'upload_renamed'] as $field => $code) {
        if (array_key_exists($field, $response)) {
            $safe['upload_events'][] = admin_operation_diagnostic_event($code, admin_operation_diagnostic_count($response[$field]));
        }
    }
    foreach ([
        'scan_failed' => ['scan_failed_filenames', null],
        'thumbnail_failed' => ['thumbnail_failed_filenames', 'thumbnail_errors'],
        'rename_warning' => [null, 'rename_warnings'],
        'rename_failed' => [null, 'rename_failures'],
    ] as $code => [$filenameField, $messageField]) {
        $names = admin_operation_diagnostic_filenames($filenameField === null ? [] : ($response[$filenameField] ?? []));
        $count = max(admin_operation_diagnostic_count($response[$code] ?? 0),
            count($names['filenames']) + $names['filenames_omitted'],
            $messageField !== null && is_array($response[$messageField] ?? null) ? min(ADMIN_OPERATION_DIAGNOSTIC_MAX_COUNT, count($response[$messageField])) : 0);
        // A previously projected, operator-reviewed result keeps its exact count,
        // but never supplies messages, paths or filenames through this typed list.
        foreach (array_slice((array) ($response['upload_diagnostics'] ?? []), 0, 4) as $prior) {
            if (is_array($prior) && ($prior['code'] ?? '') === $code) {
                $count = max($count, admin_operation_diagnostic_count($prior['count'] ?? 0));
                $names['filenames_omitted'] = max($names['filenames_omitted'], admin_operation_diagnostic_count($prior['filenames_omitted'] ?? 0));
            }
        }
        if ($filenameField !== null) {
            $safe[$filenameField] = $names['filenames'];
        }
        if ($count === 0) {
            continue;
        }
        $event = admin_operation_diagnostic_event($code, $count, $names['filenames']);
        $safe['upload_diagnostics'][] = ['code' => $code, 'count' => $count] + $names;
        $safe['upload_events'][] = $event;
        if ($messageField !== null) {
            $safe[$messageField] = [$event['message']];
        }
    }
    return $safe;
}
