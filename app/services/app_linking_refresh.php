<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/services/app_linking_refresh.php
 * Module Type: Service
 * Purpose: Define fail-closed rotating native-client refresh credentials.
 * Responsibilities:
 *   - Issue distinct opaque refresh values and retain only their hashes.
 *   - Require exact storage and client policy before serialized rotation.
 *   - Return fresh credentials only after a committed rotation succeeds.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Services;

use RuntimeException;
use Throwable;
use function Gallery\Models\app_link_refresh_model_revoke_token;
use function Gallery\Models\app_link_refresh_model_rotate;

/**
 * Distinguish renewable credentials by their wire prefix.
 * Type: string. Units: ASCII prefix. Scope: one native refresh token.
 * Consumers: app_link_refresh_token_generate, app_link_refresh_token_hash.
 * Rationale: pglr_ identifies refresh credentials before hashing.
 */
const APP_LINK_REFRESH_TOKEN_PREFIX = 'pglr_';

/**
 * Limit the absolute lifetime of an approved legacy refresh family.
 * Type: int. Units: seconds. Scope: one newly authorized grant family.
 * Consumers: code exchange and refresh issuance.
 * Rationale: 2592000 seconds is thirty days before fresh browser consent.
 */
const APP_LINK_REFRESH_FAMILY_TTL_SECONDS = 2592000;

/**
 * Expire a refresh family after a week without qualifying activity.
 * Type: int. Units: seconds. Scope: one refresh family and rolling replay history.
 * Consumers: code exchange, access authentication and refresh rotation.
 * Rationale: 604800 seconds allows a week between uses while bounding exposure.
 * Legacy refresh extends idle time up to its original cap. Rolling consent
 * renews both deadlines and retains consumed hashes for the same interval.
 */
const APP_LINK_REFRESH_IDLE_TTL_SECONDS = 604800;

/**
 * Determine whether grant reclamation can safely inspect refresh families.
 *
 * A confirmed absent table permanently preserves the pre-refresh grant path;
 * incomplete or unknown existing storage must refuse reclamation, not assume
 * that no live family exists. The owner must recheck family expiry under lock.
 *
 * @return 'absent'|'ready'|'unknown' Narrow three-state reclaim admission.
 */
function app_link_refresh_reclaim_state(): string
{
    $table = schema_inspection_table('app_link_refresh_families');
    if (schema_inspection_is_missing($table)) {
        return 'absent';
    }
    if (!schema_inspection_is_available($table)) {
        return 'unknown';
    }
    $requirements = [
        $table,
        schema_inspection_table_engine('app_link_refresh_families', 'InnoDB'),
        schema_inspection_unique_index_exact(
            'app_link_refresh_families', 'PRIMARY', ['grant_id'],
        ),
        schema_inspection_column_exact(
            'app_link_refresh_families', 'grant_id', 'bigint unsigned', false, null,
        ),
        schema_inspection_column_exact(
            'app_link_refresh_families', 'expires_at', 'datetime(6)', false, null,
        ),
        schema_inspection_column_exact(
            'app_link_refresh_families', 'idle_expires_at', 'datetime(6)', false, null,
        ),
        schema_inspection_column_exact(
            'app_link_refresh_families', 'revoked_at', 'datetime(6)', true, null,
        ),
        schema_inspection_table('app_link_refresh_tokens'),
        schema_inspection_foreign_key_exact(
            'app_link_refresh_families', 'app_link_refresh_family_grant_fk',
            'grant_id', 'app_link_grants', 'id', 'CASCADE',
        ),
        schema_inspection_foreign_key_exact(
            'app_link_refresh_tokens', 'app_link_refresh_token_family_fk',
            'grant_id', 'app_link_refresh_families', 'grant_id', 'CASCADE',
        ),
    ];
    $status = schema_inspection_feature('app_linking.refresh_reclaim', $requirements);
    return schema_inspection_is_available($status) ? 'ready' : 'unknown';
}

/**
 * Require exact transactional storage before creating or rotating refresh tokens.
 *
 * No owner-revocation capability should depend on this broad readiness result;
 * grant revocation remains independently available under its narrower schema.
 *
 * @return array{state:string,feature:string,requirements:list<array{state:string,object_type:string,table:string,object:string,error_code:string,diagnostic:string}>}
 *     Three-state readiness including types, indexes, engines and foreign keys.
 */
function app_link_refresh_schema_status(): array
{
    $status = app_linking_schema_status(
        'app_linking.refresh',
        [
            'users' => ['id', 'role'],
            'app_link_grants' => [
                'id', 'user_id', 'client_id', 'issuer', 'scope', 'revoked_at',
                'access_token_hash', 'access_issued_at', 'access_expires_at',
            ],
            'app_link_refresh_families' => [
                'grant_id', 'user_id', 'client_id', 'issuer', 'scope',
                'created_at', 'expires_at', 'idle_expires_at', 'issued_count',
                'revoked_at',
            ],
            'app_link_refresh_tokens' => [
                'id', 'grant_id', 'token_hash', 'active_slot', 'issued_at',
                'consumed_at',
            ],
        ],
        [
            'users' => ['PRIMARY' => ['id']],
            'app_link_grants' => [
                'PRIMARY' => ['id'],
                'app_link_grants_token_hash' => ['access_token_hash'],
            ],
            'app_link_refresh_families' => ['PRIMARY' => ['grant_id']],
            'app_link_refresh_tokens' => [
                'PRIMARY' => ['id'],
                'app_link_refresh_token_hash' => ['token_hash'],
                'app_link_refresh_active_slot' => ['grant_id', 'active_slot'],
            ],
        ],
        ['users', 'app_link_grants', 'app_link_refresh_families',
            'app_link_refresh_tokens'],
    );
    // The shared base verifies established grant types. These are the exact
    // additional migration-defined types; unknown or partial storage refuses.
    $definitions = [
        'app_link_refresh_families' => [
            'grant_id' => ['bigint unsigned', false, null, false],
            'user_id' => ['bigint unsigned', false, null, false],
            'client_id' => ['varbinary(64)', false, null, false],
            'issuer' => ['varbinary(512)', false, null, false],
            'created_at' => ['datetime(6)', false, null, false],
            'expires_at' => ['datetime(6)', false, null, false],
            'idle_expires_at' => ['datetime(6)', false, null, false],
            'issued_count' => ['int unsigned', false, null, false],
            'revoked_at' => ['datetime(6)', true, null, false],
        ],
        'app_link_refresh_tokens' => [
            'id' => ['bigint unsigned', false, null, true],
            'grant_id' => ['bigint unsigned', false, null, false],
            'token_hash' => ['char(64)', false, 'ascii_bin', false],
            'active_slot' => ['tinyint unsigned', true, null, false],
            'issued_at' => ['datetime(6)', false, null, false],
            'consumed_at' => ['datetime(6)', true, null, false],
        ],
    ];
    foreach ($definitions as $table => $columns) {
        foreach ($columns as $column => $definition) {
            $status['requirements'][] = schema_inspection_column_exact(
                $table, $column, $definition[0], $definition[1],
                $definition[2], $definition[3],
            );
        }
    }
    $status['requirements'][] = schema_inspection_foreign_key_exact(
        'app_link_grants', 'app_link_grants_user_fk', 'user_id',
        'users', 'id', 'CASCADE',
    );
    $status['requirements'][] = schema_inspection_foreign_key_exact(
        'app_link_refresh_families', 'app_link_refresh_family_grant_fk',
        'grant_id', 'app_link_grants', 'id', 'CASCADE',
    );
    $status['requirements'][] = schema_inspection_foreign_key_exact(
        'app_link_refresh_tokens', 'app_link_refresh_token_family_fk',
        'grant_id', 'app_link_refresh_families', 'grant_id', 'CASCADE',
    );
    return schema_inspection_feature('app_linking.refresh', $status['requirements']);
}

/**
 * Require only the verified storage needed to revoke a refresh family.
 *
 * Revocation does not depend on access-token columns, live scopes, expiration
 * fields, a configured rate limiter or the broad issuance readiness check.
 *
 * @return array{state:string,feature:string,requirements:list<array{state:string,object_type:string,table:string,object:string,error_code:string,diagnostic:string}>}
 *     Three-state readiness for the independently safe removal path.
 */
function app_link_refresh_revocation_schema_status(): array
{
    $status = app_linking_schema_status(
        'app_linking.refresh_revocation',
        [
            'users' => ['id'],
            'app_link_grants' => [
                'id', 'user_id', 'client_id', 'issuer', 'revoked_at',
            ],
            'app_link_refresh_families' => [
                'grant_id', 'user_id', 'client_id', 'issuer', 'revoked_at',
            ],
            'app_link_refresh_tokens' => ['grant_id', 'token_hash'],
        ],
        [
            'users' => ['PRIMARY' => ['id']],
            'app_link_grants' => ['PRIMARY' => ['id']],
            'app_link_refresh_families' => ['PRIMARY' => ['grant_id']],
            'app_link_refresh_tokens' => [
                'app_link_refresh_token_hash' => ['token_hash'],
            ],
        ],
        ['users', 'app_link_grants', 'app_link_refresh_families',
            'app_link_refresh_tokens'],
    );
    $definitions = [
        'app_link_refresh_families' => [
            'grant_id' => ['bigint unsigned', false, null, false],
            'user_id' => ['bigint unsigned', false, null, false],
            'client_id' => ['varbinary(64)', false, null, false],
            'issuer' => ['varbinary(512)', false, null, false],
            'revoked_at' => ['datetime(6)', true, null, false],
        ],
        'app_link_refresh_tokens' => [
            'grant_id' => ['bigint unsigned', false, null, false],
            'token_hash' => ['char(64)', false, 'ascii_bin', false],
        ],
    ];
    foreach ($definitions as $table => $columns) {
        foreach ($columns as $column => $definition) {
            $status['requirements'][] = schema_inspection_column_exact(
                $table, $column, $definition[0], $definition[1],
                $definition[2], $definition[3],
            );
        }
    }
    return schema_inspection_feature(
        'app_linking.refresh_revocation', $status['requirements'],
    );
}

/**
 * Generate an opaque refresh value using the existing cryptographic owner.
 *
 * @return string Prefixed 256-bit unpadded base64url credential.
 * @throws Throwable When the platform cannot provide cryptographic randomness.
 */
function app_link_refresh_token_generate(): string
{
    return APP_LINK_REFRESH_TOKEN_PREFIX
        . security_opaque_token_generate(APP_LINKING_SECRET_ENTROPY_BYTES);
}

/**
 * Hash a canonical refresh credential without accepting another token kind.
 *
 * @param string $token Plaintext refresh credential received over HTTPS.
 * @return string|null Lowercase SHA-256 digest, or null for malformed input.
 */
function app_link_refresh_token_hash(string $token): ?string
{
    if (strlen($token) !== strlen(APP_LINK_REFRESH_TOKEN_PREFIX)
        + APP_LINKING_256BIT_ENCODED_LENGTH
        || !str_starts_with($token, APP_LINK_REFRESH_TOKEN_PREFIX)
        || !app_linking_canonical_256bit_value_valid(
            substr($token, strlen(APP_LINK_REFRESH_TOKEN_PREFIX)),
        )) {
        return null;
    }
    return security_authority_token_hash($token);
}

/**
 * Rotate a valid refresh credential and issue a replacement access credential.
 *
 * The caller must use verified same-issuer transport and bounded
 * rate limits with a generic invalid-grant response. A lost reply is ambiguous:
 * the caller must never automatically replay the old refresh token. A replay
 * revokes the grant and family after the model commits; callers receive null.
 *
 * @param string $refreshToken Plaintext refresh credential; never log or persist it.
 * @param string $clientId Claimed registered public-client identifier.
 * @param string $issuer Independently verified exact installation issuer and mount.
 * @return array{access_token:string,expires_in:int,refresh_token:string,refresh_expires_in:int,scope:string}|null
 *     Fresh credentials and bounded lifetimes, or null for invalid/replayed input.
 * @throws RuntimeException When schema, randomness or persistence fails.
 */
function app_link_refresh_rotate(
    string $refreshToken,
    string $clientId,
    string $issuer,
): ?array {
    $hash = app_link_refresh_token_hash($refreshToken);
    $client = app_linking_public_client($clientId);
    if ($hash === null || $client === null
        || !app_linking_issuer_valid($issuer)) {
        return null;
    }
    try {
        app_linking_require_schema(app_link_refresh_schema_status());
        $accessToken = null;
        $nextRefreshToken = null;
        $allowedScopes = array_values(array_filter(
            $client['scopes'],
            static fn(string $scope): bool =>
                app_linking_scope_requests_refresh($scope)
                && app_linking_scope_transport_allowed($issuer, $scope)
                && app_linking_scope_allowed($clientId, $scope),
        ));
        if ($allowedScopes === []) {
            return null;
        }
        $result = app_link_refresh_model_rotate(
            $hash,
            $clientId,
            $issuer,
            $allowedScopes,
            static function (string $scope) use (
                $clientId, &$accessToken, &$nextRefreshToken,
            ): array {
                // Reinspect after locking the grant and before consumption.
                app_linking_recheck_wide_scope($clientId, $scope);
                $accessToken = app_linking_access_token_generate();
                $nextRefreshToken = app_link_refresh_token_generate();
                $accessHash = app_linking_access_token_hash($accessToken);
                $nextHash = app_link_refresh_token_hash($nextRefreshToken);
                if ($accessHash === null || $nextHash === null) {
                    throw new RuntimeException('Invalid generated credential.');
                }
                return ['access_hash' => $accessHash, 'refresh_hash' => $nextHash];
            },
            APP_LINKING_ACCESS_TTL_SECONDS,
            APP_LINK_REFRESH_IDLE_TTL_SECONDS,
            array_values(array_filter(
                $allowedScopes,
                static fn(string $scope): bool => app_linking_scope_requests_rolling($scope),
            )),
        );
        if ($result === null || $result['status'] !== 'rotated'
            || !is_string($accessToken) || !is_string($nextRefreshToken)) {
            return null;
        }
        return [
            'access_token' => $accessToken,
            'expires_in' => $result['expires_in'],
            'refresh_token' => $nextRefreshToken,
            'refresh_expires_in' => $result['refresh_expires_in'],
            'scope' => $result['scope'],
        ];
    } catch (Throwable) {
        // Never expose raw SQL, tokens or internal security diagnostics.
        throw new RuntimeException('App linking is unavailable.');
    }
}

/**
 * Revoke a holder's refresh family and grant using narrower verified storage.
 *
 * The controller must not reveal whether the credential existed. It must
 * not equate a lost response with successful revocation or blindly retry it.
 *
 * @param string $refreshToken Plaintext refresh credential; never log it.
 * @param string $clientId Claimed registered public-client identifier.
 * @param string $issuer Independently verified exact installation issuer and mount.
 * @return bool True for a matching family; false for invalid or absent input.
 * @throws RuntimeException When required schema or persistence is unavailable.
 */
function app_link_refresh_revoke_token(
    string $refreshToken,
    string $clientId,
    string $issuer,
): bool {
    $hash = app_link_refresh_token_hash($refreshToken);
    if ($hash === null || app_linking_public_client($clientId) === null
        || !app_linking_issuer_valid($issuer)) {
        return false;
    }
    try {
        app_linking_require_schema(app_link_refresh_revocation_schema_status());
        return app_link_refresh_model_revoke_token($hash, $clientId, $issuer);
    } catch (Throwable) {
        throw new RuntimeException('App linking is unavailable.');
    }
}
