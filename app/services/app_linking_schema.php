<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/services/app_linking_schema.php
 * Module Type: Service
 * Purpose: Define fail-closed schema readiness for scoped app linking.
 * Responsibilities:
 *   - Require complete storage and transactional guarantees for issuance.
 *   - Preserve narrower independently verified credential revocation.
 *   - Expose only bounded three-state metadata diagnostics.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

declare(strict_types=1);

namespace Gallery\Services;

/**
 * Accept either exact deployed scope width while migrations are incomplete.
 *
 * @param string $table Fixed app-linking table with a scope column.
 * @return array{state:string,object_type:string,table:string,object:string,error_code:string,diagnostic:string} Exact supported column state; unknown inspection never authorizes use.
 * @throws \InvalidArgumentException When the table is outside the fixed scope owners.
 */
function app_linking_scope_column_status(string $table): array
{
    if (!in_array($table, [
        'app_link_grants', 'app_link_codes', 'app_link_requests',
        'app_link_refresh_families',
    ], true)) {
        throw new \InvalidArgumentException('Unsupported app-linking scope owner.');
    }
    $legacy = schema_inspection_column_exact(
        $table, 'scope', 'varbinary(64)', false, null, false, true,
    );
    $wide = schema_inspection_column_exact(
        $table, 'scope', 'varbinary(255)', false, null, false, true,
    );
    if (schema_inspection_is_unknown($legacy)) {
        return $legacy;
    }
    if (schema_inspection_is_unknown($wide)) {
        return $wide;
    }
    return schema_inspection_is_available($legacy) ? $legacy : $wide;
}

/**
 * Report whether every scope owner supports future longer exact bundles.
 *
 * This observation alone grants no new permission and does not change any
 * existing scope. A future scope issuer must explicitly require this status.
 *
 * @return array{state:string,feature:string,requirements:list<array{state:string,object_type:string,table:string,object:string,error_code:string,diagnostic:string}>} Three-state wide capacity readiness.
 */
function app_linking_wide_scope_schema_status(): array
{
    $requirements = [];
    foreach ([
        'app_link_requests', 'app_link_codes', 'app_link_grants',
        'app_link_refresh_families',
    ] as $table) {
        $requirements[] = schema_inspection_column_exact(
            $table, 'scope', 'varbinary(255)', false, null, false, true,
        );
    }
    return schema_inspection_feature('app_linking.wide_scope', $requirements);
}

/**
 * Assemble one app-linking capability from explicit schema requirements.
 *
 * @param string $feature Stable feature identifier used in safe diagnostics.
 * @param array<string,list<string>> $tables Required columns keyed by table.
 * @param array<string,array<string,list<string>>> $indexes Unique indexes keyed
 *     by table and index name, with their exact ordered full-column names.
 * @param list<string> $transactionalTables Tables requiring InnoDB row locks.
 * @return array{state:string,feature:string,requirements:list<array{state:string,object_type:string,table:string,object:string,error_code:string,diagnostic:string}>} Aggregated structured readiness result.
 * @throws \InvalidArgumentException For malformed schema requirement identifiers.
 */
function app_linking_schema_status(
    string $feature,
    array $tables,
    array $indexes,
    array $transactionalTables,
): array {
    $requirements = [];
    foreach ($tables as $table => $columns) {
        $tableStatus = schema_inspection_table($table);
        $requirements[] = $tableStatus;
        if (!schema_inspection_is_available($tableStatus)) {
            continue;
        }
        foreach ($columns as $column) {
            $requirements[] = schema_inspection_column($table, $column);
            if ($column === 'scope' && in_array($table, [
                'app_link_grants', 'app_link_codes', 'app_link_requests',
                'app_link_refresh_families',
            ], true)) {
                $requirements[] = app_linking_scope_column_status($table);
                continue;
            }
            $definition = app_linking_exact_column_definition($table, $column);
            if ($definition !== null) {
                $requirements[] = schema_inspection_column_exact(
                    $table,
                    $column,
                    $definition[0],
                    $definition[1],
                    $definition[2],
                    $definition[3],
                );
            }
        }
        foreach ($indexes[$table] ?? [] as $index => $indexColumns) {
            $requirements[] = schema_inspection_unique_index_exact(
                $table,
                $index,
                $indexColumns,
            );
        }
        if (in_array($table, $transactionalTables, true)) {
            $requirements[] = schema_inspection_table_engine($table, 'InnoDB');
        }
    }
    return schema_inspection_feature($feature, $requirements);
}

/**
 * Return migration-defined security storage metadata for a required column.
 *
 * @param string $table Schema table name.
 * @param string $column Required column name.
 * @return array{string,bool,string|null,bool}|null Type, nullability, collation and auto-increment flag, or null for unrelated legacy fields.
 */
function app_linking_exact_column_definition(string $table, string $column): ?array
{
    $definitions = [
        'users' => ['id' => ['bigint unsigned', false, null, true]],
        'app_link_installation_key' => [
            'id' => ['tinyint unsigned', false, null, false],
            'secret' => ['binary(32)', false, null, false],
        ],
        'app_link_grants' => [
            'id' => ['bigint unsigned', false, null, true],
            'user_id' => ['bigint unsigned', false, null, false],
            'client_id' => ['varbinary(64)', false, null, false],
            'issuer' => ['varbinary(512)', false, null, false],
            'scope' => ['varbinary(64)', false, null, false],
            'created_at' => ['datetime(6)', false, null, false],
            'revoked_at' => ['datetime(6)', true, null, false],
            'access_token_hash' => ['char(64)', true, 'ascii_bin', false],
            'access_issued_at' => ['datetime(6)', true, null, false],
            'access_expires_at' => ['datetime(6)', true, null, false],
        ],
        'app_link_codes' => [
            'id' => ['bigint unsigned', false, null, true],
            'user_id' => ['bigint unsigned', false, null, false],
            'code_hash' => ['char(64)', false, 'ascii_bin', false],
            'client_id' => ['varbinary(64)', false, null, false],
            'issuer' => ['varbinary(512)', false, null, false],
            'scope' => ['varbinary(64)', false, null, false],
            'redirect_uri' => ['varbinary(512)', false, null, false],
            'pkce_s256' => ['char(43)', false, 'ascii_bin', false],
            'created_at' => ['datetime(6)', false, null, false],
            'expires_at' => ['datetime(6)', false, null, false],
            'consumed_at' => ['datetime(6)', true, null, false],
        ],
        'app_link_request_capacity' => [
            'id' => ['tinyint unsigned', false, null, false],
        ],
        'app_link_requests' => [
            'id' => ['bigint unsigned', false, null, true],
            'claim_hash' => ['char(64)', false, 'ascii_bin', false],
            'issuer' => ['varbinary(512)', false, null, false],
            'client_id' => ['varbinary(64)', false, null, false],
            'scope' => ['varbinary(64)', false, null, false],
            'redirect_uri' => ['varbinary(512)', false, null, false],
            'pkce_s256' => ['char(43)', false, 'ascii_bin', false],
            'state_nonce' => ['binary(12)', false, null, false],
            'state_cipher' => ['varbinary(1024)', false, null, false],
            'state_tag' => ['binary(16)', false, null, false],
            'created_at' => ['datetime(6)', false, null, false],
            'expires_at' => ['datetime(6)', false, null, false],
            'claimed_at' => ['datetime(6)', true, null, false],
            'claimed_user_id' => ['bigint unsigned', true, null, false],
            'session_hash' => ['char(64)', true, 'ascii_bin', false],
            'consent_consumed_at' => ['datetime(6)', true, null, false],
        ],
        'viewer_rate_limit_buckets' => [
            'bucket' => ['varchar(64)', false, 'utf8mb4_unicode_ci', false],
            'entry_count' => ['int unsigned', false, null, false],
            'updated_at' => ['datetime', false, null, false],
        ],
        'viewer_rate_limits' => [
            'bucket' => ['varchar(64)', false, 'utf8mb4_unicode_ci', false],
            'subject_hash' => ['char(64)', false, 'utf8mb4_unicode_ci', false],
            'attempts' => ['int unsigned', false, null, false],
            'first_attempt_at' => ['datetime', false, null, false],
            'last_attempt_at' => ['datetime', false, null, false],
            'locked_until' => ['datetime', true, null, false],
        ],
    ];
    return $definitions[$table][$column] ?? null;
}

/**
 * Require complete grant and code storage before issuance or exchange.
 *
 * @return array{state:string,feature:string,requirements:list<array{state:string,object_type:string,table:string,object:string,error_code:string,diagnostic:string}>} Three-state issuance and exchange readiness.
 */
function app_linking_issuance_schema_status(): array
{
    $status = app_linking_schema_status(
        'app_linking.issuance',
        [
            'users' => ['id', 'role'],
            'app_link_grants' => [
                'id', 'user_id', 'client_id', 'issuer', 'scope', 'created_at',
                'revoked_at', 'access_token_hash', 'access_issued_at',
                'access_expires_at',
            ],
            'app_link_codes' => [
                'id', 'user_id', 'code_hash', 'client_id', 'issuer', 'scope',
                'redirect_uri', 'pkce_s256', 'created_at', 'expires_at',
                'consumed_at',
            ],
        ],
        [
            'users' => ['PRIMARY' => ['id']],
            'app_link_grants' => [
                'PRIMARY' => ['id'],
                'app_link_grants_token_hash' => ['access_token_hash'],
            ],
            'app_link_codes' => [
                'PRIMARY' => ['id'],
                'app_link_codes_hash' => ['code_hash'],
            ],
        ],
        ['users', 'app_link_grants', 'app_link_codes'],
    );
    $status['requirements'][] = schema_inspection_foreign_key_exact(
        'app_link_grants', 'app_link_grants_user_fk', 'user_id',
        'users', 'id', 'CASCADE',
    );
    $status['requirements'][] = schema_inspection_foreign_key_exact(
        'app_link_codes', 'app_link_codes_user_fk', 'user_id',
        'users', 'id', 'CASCADE',
    );
    if (schema_inspection_is_available(schema_inspection_table('app_link_codes'))) {
        $status['requirements'][] = schema_inspection_nonunique_index_exact(
            'app_link_codes', 'app_link_codes_owner', ['user_id', 'expires_at', 'id'],
        );
    }
    if (schema_inspection_is_available(
        schema_inspection_table('app_link_grants'),
    )) {
        // Admission forces this exact owner-first index for its bounded scan.
        $status['requirements'][] = schema_inspection_nonunique_index_exact(
            'app_link_grants',
            'app_link_grants_owner',
            ['user_id', 'revoked_at', 'id'],
        );
    }
    return schema_inspection_feature(
        'app_linking.issuance',
        $status['requirements'],
    );
}

/**
 * Require the live Admin and unrevoked grant fields used by token lookup.
 *
 * @return array{state:string,feature:string,requirements:list<array{state:string,object_type:string,table:string,object:string,error_code:string,diagnostic:string}>} Three-state token authentication readiness.
 */
function app_linking_authentication_schema_status(): array
{
    return app_linking_schema_status(
        'app_linking.authentication',
        [
            'users' => ['id', 'username', 'role'],
            'app_link_grants' => [
                'id', 'user_id', 'issuer', 'scope', 'revoked_at',
                'access_token_hash', 'access_expires_at',
            ],
        ],
        [
            'users' => ['PRIMARY' => ['id']],
            'app_link_grants' => [
                'PRIMARY' => ['id'],
                'app_link_grants_token_hash' => ['access_token_hash'],
            ],
        ],
        [],
    );
}

/**
 * Require the independent atomic limiter storage used by app-linking requests.
 *
 * This capability does not depend on the Viewer Accounts master setting and
 * does not participate in the narrower grant-revocation schema predicates.
 *
 * @return array{state:string,feature:string,requirements:list<array{state:string,object_type:string,table:string,object:string,error_code:string,diagnostic:string}>} Three-state limiter storage readiness.
 */
function app_linking_rate_limit_schema_status(): array
{
    $status = app_linking_schema_status(
        'app_linking.rate_limits',
        [
            'viewer_rate_limit_buckets' => [
                'bucket', 'entry_count', 'updated_at',
            ],
            'viewer_rate_limits' => [
                'bucket', 'subject_hash', 'attempts', 'first_attempt_at',
                'last_attempt_at', 'locked_until',
            ],
        ],
        [
            'viewer_rate_limit_buckets' => ['PRIMARY' => ['bucket']],
            'viewer_rate_limits' => ['PRIMARY' => ['bucket', 'subject_hash']],
        ],
        ['viewer_rate_limit_buckets', 'viewer_rate_limits'],
    );
    $status['requirements'][] = schema_inspection_foreign_key_exact(
        'viewer_rate_limits', 'viewer_rate_limits_bucket_foreign', 'bucket',
        'viewer_rate_limit_buckets', 'bucket', 'CASCADE',
    );
    return schema_inspection_feature(
        'app_linking.rate_limits',
        $status['requirements'],
    );
}

/**
 * Require owner-scoped listing fields and its bounded owner-first index.
 *
 * @return array{state:string,feature:string,requirements:list<array{state:string,object_type:string,table:string,object:string,error_code:string,diagnostic:string}>} Three-state owner listing readiness.
 */
function app_linking_owner_listing_schema_status(): array
{
    $status = app_linking_schema_status(
        'app_linking.owner_listing',
        [
            'users' => ['id', 'role'],
            'app_link_grants' => [
                'id', 'user_id', 'issuer', 'client_id', 'scope', 'created_at',
                'revoked_at', 'access_issued_at', 'access_expires_at',
            ],
        ],
        [
            'users' => ['PRIMARY' => ['id']],
            'app_link_grants' => ['PRIMARY' => ['id']],
        ],
        ['users', 'app_link_grants'],
    );
    if (schema_inspection_is_available(
        schema_inspection_table('app_link_grants'),
    )) {
        // Listing forces this exact owner-first index for its bounded scan.
        $status['requirements'][] = schema_inspection_nonunique_index_exact(
            'app_link_grants',
            'app_link_grants_owner',
            ['user_id', 'revoked_at', 'id'],
        );
    }
    return schema_inspection_feature(
        'app_linking.owner_listing',
        $status['requirements'],
    );
}

/**
 * Require only owner, grant and locking fields needed to revoke one grant.
 *
 * @return array{state:string,feature:string,requirements:list<array{state:string,object_type:string,table:string,object:string,error_code:string,diagnostic:string}>} Three-state owner revocation readiness.
 */
function app_linking_owner_revocation_schema_status(): array
{
    return app_linking_schema_status(
        'app_linking.owner_revocation',
        [
            'users' => ['id', 'role'],
            'app_link_grants' => [
                'id', 'user_id', 'revoked_at',
            ],
        ],
        [
            'users' => ['PRIMARY' => ['id']],
            'app_link_grants' => ['PRIMARY' => ['id']],
        ],
        ['users', 'app_link_grants'],
    );
}

/**
 * Require the owner revocation fields plus exact token lookup uniqueness.
 *
 * @return array{state:string,feature:string,requirements:list<array{state:string,object_type:string,table:string,object:string,error_code:string,diagnostic:string}>} Three-state bearer revocation readiness.
 */
function app_linking_token_revocation_schema_status(): array
{
    return app_linking_schema_status(
        'app_linking.token_revocation',
        [
            'users' => ['id'],
            'app_link_grants' => [
                'id', 'user_id', 'issuer', 'access_token_hash', 'revoked_at',
            ],
        ],
        [
            'users' => ['PRIMARY' => ['id']],
            'app_link_grants' => [
                'PRIMARY' => ['id'],
                'app_link_grants_token_hash' => ['access_token_hash'],
            ],
        ],
        ['users', 'app_link_grants'],
    );
}

/**
 * Require the independent pushed-request storage and its bounded scan indexes.
 *
 * @return array{state:string,feature:string,requirements:list<array{state:string,object_type:string,table:string,object:string,error_code:string,diagnostic:string}>} Three-state readiness.
 */
function app_linking_push_schema_status(): array
{
    $status = app_linking_schema_status(
        'app_linking.push_requests',
        [
            'users' => ['id', 'role'],
            'app_link_request_capacity' => ['id'],
            'app_link_requests' => [
                'id', 'claim_hash', 'issuer', 'client_id', 'scope',
                'redirect_uri', 'pkce_s256', 'state_nonce', 'state_cipher',
                'state_tag', 'created_at', 'expires_at', 'claimed_at',
                'claimed_user_id', 'session_hash', 'consent_consumed_at',
            ],
        ],
        [
            'users' => ['PRIMARY' => ['id']],
            'app_link_request_capacity' => ['PRIMARY' => ['id']],
            'app_link_requests' => [
                'PRIMARY' => ['id'],
                'app_link_requests_claim' => ['claim_hash'],
            ],
        ],
        ['users', 'app_link_request_capacity', 'app_link_requests'],
    );
    if (schema_inspection_is_available(schema_inspection_table('app_link_requests'))) {
        $status['requirements'][] = schema_inspection_nonunique_index_exact(
            'app_link_requests',
            'app_link_requests_expiry',
            ['expires_at', 'id'],
        );
    }
    return schema_inspection_feature(
        'app_linking.push_requests',
        $status['requirements'],
    );
}

/**
 * Inspect the gallery fields and primary index needed for bounded listing.
 *
 * @return array{state:string,feature:string,requirements:list<array{state:string,object_type:string,table:string,object:string,error_code:string,diagnostic:string}>} Three-state readiness.
 */
function app_linking_gallery_listing_schema_status(): array
{
    return app_linking_schema_status(
        'app_linking.gallery_listing',
        ['galleries' => ['id', 'parent_id', 'title']],
        ['galleries' => ['PRIMARY' => ['id']]],
        [],
    );
}

/**
 * Observe the existing gallery and tag columns needed by native typeahead.
 *
 * @return array{state:string,feature:string,requirements:list<array{state:string,object_type:string,table:string,object:string,error_code:string,diagnostic:string}>} Three-state search readiness.
 */
function app_linking_gallery_search_schema_status(): array
{
    return app_linking_schema_status(
        'app_linking.gallery_search',
        [
            'galleries' => ['id', 'parent_id', 'title', 'description', 'folder_path'],
            'gallery_tags' => ['gallery_id', 'tag_id'],
            'tags' => ['id', 'name', 'description'],
        ],
        [
            'galleries' => ['PRIMARY' => ['id']],
            'gallery_tags' => ['PRIMARY' => ['gallery_id', 'tag_id']],
            'tags' => ['PRIMARY' => ['id']],
        ],
        [],
    );
}

/**
 * Require the existing image ingestion and inventory fields before upload.
 *
 * @return array{state:string,feature:string,requirements:list<array{state:string,object_type:string,table:string,object:string,error_code:string,diagnostic:string}>} Three-state readiness.
 */
function app_linking_upload_schema_status(): array
{
    $ingestion = upload_ingestion_schema_status();
    $inventory = mutation_schema_tables_status('app_linking.upload_inventory', [
        'galleries' => ['id', 'folder_path', 'title'],
        'images' => [
            'id', 'gallery_id', 'relative_path', 'filename', 'file_size',
            'checksum_sha256', 'updated_at',
        ],
    ]);
    return schema_inspection_feature('app_linking.upload', array_merge(
        $ingestion['requirements'],
        $inventory['requirements'],
    ));
}

/**
 * Observe storage used only by explicit native Watcher generations.
 * @return array{state:string,feature:string,requirements:list<array{state:string,object_type:string,table:string,object:string,error_code:string,diagnostic:string}>} Three-state watcher-key readiness.
 */
function app_linking_watch_key_schema_status(): array
{
    $status = mutation_schema_tables_status('app_linking.watch_keys', [
        'app_linking_watch_keys' => [
            'grant_id', 'gallery_id', 'key_hash', 'expires_at',
        ],
    ]);
    $status['requirements'][] = schema_inspection_index_exact(
        'app_linking_watch_keys', 'PRIMARY', ['grant_id', 'gallery_id'], true,
    );
    $status['requirements'][] = schema_inspection_index_exact(
        'app_linking_watch_keys', 'app_linking_watch_key_expiry', ['expires_at'], false,
    );
    return schema_inspection_feature('app_linking.watch_keys', $status['requirements']);
}
