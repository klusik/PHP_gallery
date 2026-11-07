<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/services/app_linking_policy.php
 * Module Type: Service
 * Purpose: Define the initial public-client and PKCE policy for app linking.
 * Responsibilities:
 *   - Restrict the registered client and loopback callback to exact values.
 *   - Verify S256 proofs and prepare distinct high-entropy opaque secrets.
 *   - Leave request handling, expiry, persistence and authority to their owners.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

declare(strict_types=1);

namespace Gallery\Services;

/**
 * Identify the native linking protocol independently of gallery releases.
 * Type: int. Units: protocol version. Scope: discovery and native clients.
 * Consumers: app_linking_discovery_model.
 * Rationale: 1 identifies the implemented PKCE and opaque credential contract.
 */
const APP_LINKING_PROTOCOL_VERSION = 1;
/**
 * Identify the automatic nonauthorizing browser-handle handoff.
 * Type: int. Units: handoff version. Scope: pushed requests and discovery.
 * Consumers: app_linking_discovery_model, cms_app_linking_push.
 * Rationale: 1 requires an explicit browser decision before a loopback code.
 */
const APP_LINKING_BROWSER_HANDOFF_VERSION = 1;

/**
 * Allow visibility grants subject to exact storage readiness.
 * Type: bool. Units: enabled state. Scope: native visibility authority.
 * Consumers: app_linking_scope_allowed and discovery readiness.
 * Rationale: Qualified writes preserve and reconcile partial sidecar evidence.
 */
const APP_LINKING_VISIBILITY_READY = true;

/**
 * Enable the native reader subject to its exact server export policy.
 * Type: bool. Units: enabled state. Scope: native read-only media grants.
 * Consumers: app_linking_media_ready.
 * Rationale: Disposable HTTPS binary and access tests verified the existing
 *     export policy and separate reader consent.
 */
const APP_LINKING_MEDIA_READY = true;

/**
 * Add public photo browsing to an explicitly approved link.
 * Type: string. Units: scope suffix. Scope: temporary photo grants.
 * Consumers: app_linking_public_client, app_linking_scope_without_media.
 * Rationale: The media:read suffix distinguishes photo access from gallery
 * listing.
 */
const APP_LINKING_MEDIA_SUFFIX = ' media:read';

/**
 * Require separate consent to read photos under the current Admin grant.
 * Type: string. Units: ordered scope suffix. Scope: temporary native links.
 * Consumers: app_linking_public_client and native media authorization.
 * Rationale: A public-media grant cannot widen to restricted content.
 */
const APP_LINKING_ADMIN_MEDIA_SUFFIX = ' media:admin';

/**
 * Require explicit consent before native permanent photo deletion.
 * Type: string. Units: ordered scope suffix. Scope: native Admin photo grants.
 * Consumers: app_linking_admin_media_scopes and delete authorization.
 * Rationale: Existing Admin photo grants are read-only and must not gain
 *     destructive authority during upgrade.
 */
const APP_LINKING_ADMIN_DELETE_SUFFIX = ' media:delete';

/**
 * Include explicitly approved public presentation playback in an Admin link.
 * Type: string. Units: ordered scope suffix. Scope: native photo and music grants.
 * Consumers: app_linking_admin_media_scopes and presentation authorization.
 * Rationale: The additional permission preserves the public playback boundary.
 */
const APP_LINKING_ADMIN_PRESENTATION_SUFFIX = APP_LINKING_ADMIN_MEDIA_SUFFIX
    . ' presentation:read';

/**
 * Add photo and presentation browsing to an explicitly approved link.
 * Type: string. Units: ordered scope suffix. Scope: temporary slideshow and
 * music grants.
 * Consumers: app_linking_public_client, app_linking_scope_without_media.
 * Rationale: The media:read and presentation:read pair binds both approvals.
 */
const APP_LINKING_PRESENTATION_SUFFIX = ' media:read presentation:read';

/**
 * Identify the registered public WinApp client.
 * Type: string. Units: client identifier. Scope: authorization, token exchange
 * and discovery.
 * Consumers: app_linking_public_client, app_linking_discovery_capabilities.
 * Rationale: php-gallery-winapp binds requests to one known public-client
 * policy.
 */
const APP_LINKING_WINAPP_CLIENT_ID = 'php-gallery-winapp';

/**
 * Pin the registered native loopback callback path.
 * Type: string. Units: URL path. Scope: one native authorization redirect.
 * Consumers: app_linking_public_client, app_linking_redirect_uri_valid.
 * Rationale: /oauth2redirect/php-gallery identifies the WinApp callback.
 */
const APP_LINKING_WINAPP_REDIRECT_PATH = '/oauth2redirect/php-gallery';

/**
 * Permit WinApp to verify the approved Admin account.
 * Type: string. Units: ordered scope tokens. Scope: temporary native grant.
 * Consumers: app_linking_public_client, app_linking_scope_base.
 * Rationale: The ordered bundle records the exact permissions shown during
 * approval.
 */
const APP_LINKING_IDENTITY_SCOPE = 'link:identity';

/**
 * Permit WinApp to verify the approved Admin account and list galleries.
 * Type: string. Units: ordered scope tokens. Scope: temporary native grant.
 * Consumers: app_linking_public_client, app_linking_scope_base.
 * Rationale: The ordered bundle records the exact permissions shown during
 * approval.
 */
const APP_LINKING_GALLERIES_SCOPE = 'link:identity galleries:list';

/**
 * Permit WinApp to verify the approved Admin account, list galleries and upload
 * images.
 * Type: string. Units: ordered scope tokens. Scope: temporary native grant.
 * Consumers: app_linking_public_client, app_linking_scope_base.
 * Rationale: The ordered bundle records the exact permissions shown during
 * approval.
 */
const APP_LINKING_UPLOAD_SCOPE = 'link:identity galleries:list gallery:upload';

/**
 * Permit WinApp to verify the approved Admin account, list galleries and search
 * gallery metadata.
 * Type: string. Units: ordered scope tokens. Scope: temporary native gallery
 * search grant.
 * Consumers: app_linking_public_client, app_linking_scope_base.
 * Rationale: The galleries:search token separately grants matching against
 * gallery metadata.
 */
const APP_LINKING_SEARCH_SCOPE = APP_LINKING_GALLERIES_SCOPE . ' galleries:search';

/**
 * Permit WinApp to verify the approved Admin account, list galleries, upload
 * images and search gallery metadata.
 * Type: string. Units: ordered scope tokens. Scope: temporary native gallery
 * search grant.
 * Consumers: app_linking_public_client, app_linking_scope_base.
 * Rationale: The galleries:search token separately grants matching against
 * gallery metadata.
 */
const APP_LINKING_UPLOAD_SEARCH_SCOPE = APP_LINKING_UPLOAD_SCOPE . ' galleries:search';

/**
 * Mark browser-approved refresh families as rolling seven-day authority.
 * Type: string. Units: ordered scope suffix. Scope: exact rolling bundles.
 * Consumers: client registration, scope validation and refresh lifetime.
 * Rationale: old offline_access-only grants keep their 30-day absolute cap.
 */
const APP_LINKING_ROLLING_SUFFIX = ' rolling_access offline_access';

/**
 * Register rolling consent for Admin identity and gallery listing.
 * Type: string. Units: ordered scope tokens. Scope: WinApp listing grants.
 * Consumers: app_linking_discovery_capabilities, app_linking_public_client,
 *     app_linking_scope_base, app_linking_scope_requests_rolling.
 * Rationale: The rolling suffix renews the exact listing permission bundle.
 */
const APP_LINKING_GALLERIES_ROLLING_SCOPE =
    APP_LINKING_GALLERIES_SCOPE . APP_LINKING_ROLLING_SUFFIX;

/**
 * Register rolling consent for gallery listing and upload.
 * Type: string. Units: ordered scope tokens. Scope: WinApp upload grants.
 * Consumers: app_linking_public_client, app_linking_scope_requests_rolling.
 * Rationale: The upload base retains the explicitly approved upload permission
 *     during renewal.
 */
const APP_LINKING_UPLOAD_ROLLING_SCOPE =
    APP_LINKING_UPLOAD_SCOPE . APP_LINKING_ROLLING_SUFFIX;

/**
 * Register rolling consent for gallery listing and metadata search.
 * Type: string. Units: ordered scope tokens. Scope: WinApp search grants.
 * Consumers: app_linking_public_client, app_linking_scope_base,
 *     app_linking_scope_allows_gallery_search, app_linking_scope_requests_rolling.
 * Rationale: The search base retains the separately approved search permission
 *     during renewal.
 */
const APP_LINKING_GALLERIES_SEARCH_ROLLING_SCOPE =
    APP_LINKING_SEARCH_SCOPE . APP_LINKING_ROLLING_SUFFIX;

/**
 * Register rolling consent for listing, upload and metadata search.
 * Type: string. Units: ordered scope tokens. Scope: WinApp upload/search grants.
 * Consumers: app_linking_public_client, app_linking_scope_allows_gallery_search,
 *     app_linking_scope_requests_rolling.
 * Rationale: The combined base retains both upload and search permissions
 *     during renewal.
 */
const APP_LINKING_UPLOAD_SEARCH_ROLLING_SCOPE =
    APP_LINKING_UPLOAD_SEARCH_SCOPE . APP_LINKING_ROLLING_SUFFIX;

/**
 * Preserve the exact approved presentation permission through rolling renewal.
 * Type: string. Units: ordered scope tokens. Scope: WinApp listing and playback.
 * Consumers: app_linking_discovery_capabilities,
 *     APP_LINKING_PRESENTATION_ROLLING_SCOPES.
 * Rationale: The presentation suffix binds public photo and music access to
 *     the listing grant that discovery checks for rolling playback support.
 */
const APP_LINKING_GALLERIES_PRESENTATION_ROLLING_SCOPE =
    APP_LINKING_GALLERIES_SCOPE . APP_LINKING_PRESENTATION_SUFFIX
    . APP_LINKING_ROLLING_SUFFIX;

/**
 * Register rolling consent for listing, upload and public presentation.
 * Type: string. Units: ordered scope tokens. Scope: WinApp upload and playback.
 * Consumers: APP_LINKING_PRESENTATION_ROLLING_SCOPES.
 * Rationale: The upload base and presentation suffix preserve both permissions
 *     during renewal.
 */
const APP_LINKING_UPLOAD_PRESENTATION_ROLLING_SCOPE =
    APP_LINKING_UPLOAD_SCOPE . APP_LINKING_PRESENTATION_SUFFIX
    . APP_LINKING_ROLLING_SUFFIX;

/**
 * Register rolling consent for listing, search and public presentation.
 * Type: string. Units: ordered scope tokens. Scope: WinApp search and playback.
 * Consumers: APP_LINKING_PRESENTATION_ROLLING_SCOPES,
 *     app_linking_scope_allows_gallery_search.
 * Rationale: The search base and presentation suffix preserve both permissions
 *     during renewal.
 */
const APP_LINKING_GALLERIES_SEARCH_PRESENTATION_ROLLING_SCOPE =
    APP_LINKING_SEARCH_SCOPE . APP_LINKING_PRESENTATION_SUFFIX
    . APP_LINKING_ROLLING_SUFFIX;

/**
 * Register rolling consent for listing, upload, search and public presentation.
 * Type: string. Units: ordered scope tokens.
 * Scope: WinApp upload, search and playback grants.
 * Consumers: APP_LINKING_PRESENTATION_ROLLING_SCOPES,
 *     app_linking_scope_allows_gallery_search.
 * Rationale: The combined base and presentation suffix retain all three
 *     additional permissions during renewal.
 */
const APP_LINKING_UPLOAD_SEARCH_PRESENTATION_ROLLING_SCOPE =
    APP_LINKING_UPLOAD_SEARCH_SCOPE . APP_LINKING_PRESENTATION_SUFFIX
    . APP_LINKING_ROLLING_SUFFIX;

/**
 * Collect the four registered WinApp rolling presentation bundles.
 * Type: list<string>. Units: exact ordered scope strings.
 * Scope: registration, capability resolution and storage readiness.
 * Consumers: app_linking_public_client, app_linking_scope_without_media,
 *     app_linking_scope_requests_rolling, app_linking_scope_allows_presentation,
 *     app_linking_recheck_wide_scope.
 * Rationale: One allowlist keeps listing, upload and search variants aligned
 *     across playback authorization and renewal checks.
 */
const APP_LINKING_PRESENTATION_ROLLING_SCOPES = [
    APP_LINKING_GALLERIES_PRESENTATION_ROLLING_SCOPE,
    APP_LINKING_UPLOAD_PRESENTATION_ROLLING_SCOPE,
    APP_LINKING_GALLERIES_SEARCH_PRESENTATION_ROLLING_SCOPE,
    APP_LINKING_UPLOAD_SEARCH_PRESENTATION_ROLLING_SCOPE,
];

/**
 * Permit WinApp to verify the approved Admin account, list galleries and read
 * selected gallery details.
 * Type: string. Units: ordered scope tokens. Scope: temporary native grant.
 * Consumers: app_linking_public_client, app_linking_scope_base.
 * Rationale: The ordered bundle records the exact permissions shown during
 * approval.
 */
const APP_LINKING_READ_SCOPE = 'link:identity galleries:list gallery:read';

/**
 * Permit WinApp to verify the approved Admin account, list galleries, read
 * selected gallery details and upload images.
 * Type: string. Units: ordered scope tokens. Scope: temporary native grant.
 * Consumers: app_linking_public_client, app_linking_scope_base.
 * Rationale: The ordered bundle records the exact permissions shown during
 * approval.
 */
const APP_LINKING_READ_UPLOAD_SCOPE = 'link:identity galleries:list gallery:read gallery:upload';

/**
 * Permit WinApp to verify the approved Admin account, list galleries and create
 * galleries.
 * Type: string. Units: ordered scope tokens. Scope: temporary native grant.
 * Consumers: app_linking_public_client, app_linking_scope_base.
 * Rationale: The ordered bundle records the exact permissions shown during
 * approval.
 */
const APP_LINKING_CREATE_SCOPE = 'link:identity galleries:list galleries:create';

/**
 * Permit WinApp to verify the approved Admin account, list galleries, read
 * selected gallery details and create galleries.
 * Type: string. Units: ordered scope tokens. Scope: temporary native grant.
 * Consumers: app_linking_public_client, app_linking_scope_base.
 * Rationale: The ordered bundle records the exact permissions shown during
 * approval.
 */
const APP_LINKING_READ_CREATE_SCOPE = 'link:identity galleries:list gallery:read galleries:create';

/**
 * Permit WinApp to verify the approved Admin account, list galleries, upload
 * images and create galleries.
 * Type: string. Units: ordered scope tokens. Scope: temporary native grant.
 * Consumers: app_linking_public_client, app_linking_scope_base.
 * Rationale: The ordered bundle records the exact permissions shown during
 * approval.
 */
const APP_LINKING_UPLOAD_CREATE_SCOPE = 'link:identity galleries:list gallery:upload galleries:create';

/**
 * Permit WinApp to verify the approved Admin account, list galleries, read
 * selected gallery details, upload images and create galleries.
 * Type: string. Units: ordered scope tokens. Scope: temporary native grant.
 * Consumers: app_linking_public_client, app_linking_scope_base.
 * Rationale: The ordered bundle records the exact permissions shown during
 * approval.
 */
const APP_LINKING_READ_UPLOAD_CREATE_SCOPE = 'link:identity galleries:list gallery:read gallery:upload galleries:create';

/**
 * Permit WinApp to verify the approved Admin account, list galleries, read
 * selected gallery details and change gallery visibility.
 * Type: string. Units: ordered scope tokens. Scope: temporary native visibility
 * grant.
 * Consumers: app_linking_public_client, app_linking_scope_base.
 * Rationale: The bundle requires gallery:read before granting
 * gallery:visibility.
 */
const APP_LINKING_VISIBILITY_SCOPE = 'link:identity galleries:list gallery:read gallery:visibility';

/**
 * Permit WinApp to verify the approved Admin account, list galleries, read
 * selected gallery details, upload images and change gallery visibility.
 * Type: string. Units: ordered scope tokens. Scope: temporary native visibility
 * grant.
 * Consumers: app_linking_public_client, app_linking_scope_base.
 * Rationale: The bundle requires gallery:read before granting
 * gallery:visibility.
 */
const APP_LINKING_UPLOAD_VISIBILITY_SCOPE = 'link:identity galleries:list gallery:read gallery:upload gallery:visibility';

/**
 * Permit WinApp to verify the approved Admin account, list galleries, read
 * selected gallery details, create galleries and change gallery visibility.
 * Type: string. Units: ordered scope tokens. Scope: temporary native visibility
 * grant.
 * Consumers: app_linking_public_client, app_linking_scope_base.
 * Rationale: The bundle requires gallery:read before granting
 * gallery:visibility.
 */
const APP_LINKING_CREATE_VISIBILITY_SCOPE = 'link:identity galleries:list gallery:read galleries:create gallery:visibility';

/**
 * Permit WinApp to verify the approved Admin account, list galleries, read
 * selected gallery details, upload images, create galleries and change gallery
 * visibility.
 * Type: string. Units: ordered scope tokens. Scope: temporary native visibility
 * grant.
 * Consumers: app_linking_public_client, app_linking_scope_base.
 * Rationale: The bundle requires gallery:read before granting
 * gallery:visibility.
 */
const APP_LINKING_UPLOAD_CREATE_VISIBILITY_SCOPE = 'link:identity galleries:list gallery:read gallery:upload galleries:create gallery:visibility';

/**
 * Permit WinApp to verify the approved Admin account and renew access.
 * Type: string. Units: ordered scope tokens. Scope: explicitly renewable native
 * grant.
 * Consumers: app_linking_public_client, app_linking_scope_base.
 * Rationale: The offline_access suffix permits renewal of exactly this approved
 * bundle.
 */
const APP_LINKING_IDENTITY_OFFLINE_SCOPE = 'link:identity offline_access';

/**
 * Permit WinApp to verify the approved Admin account, list galleries and renew
 * access.
 * Type: string. Units: ordered scope tokens. Scope: explicitly renewable native
 * grant.
 * Consumers: app_linking_public_client, app_linking_scope_base.
 * Rationale: The offline_access suffix permits renewal of exactly this approved
 * bundle.
 */
const APP_LINKING_GALLERIES_OFFLINE_SCOPE = 'link:identity galleries:list offline_access';

/**
 * Permit WinApp to verify the approved Admin account, list galleries, upload
 * images and renew access.
 * Type: string. Units: ordered scope tokens. Scope: explicitly renewable native
 * grant.
 * Consumers: app_linking_public_client, app_linking_scope_base.
 * Rationale: The offline_access suffix permits renewal of exactly this approved
 * bundle.
 */
const APP_LINKING_UPLOAD_OFFLINE_SCOPE = 'link:identity galleries:list gallery:upload offline_access';

/**
 * Permit WinApp to verify the approved Admin account, list galleries, read
 * selected gallery details and renew access.
 * Type: string. Units: ordered scope tokens. Scope: explicitly renewable native
 * grant.
 * Consumers: app_linking_public_client, app_linking_scope_base.
 * Rationale: The offline_access suffix permits renewal of exactly this approved
 * bundle.
 */
const APP_LINKING_READ_OFFLINE_SCOPE = 'link:identity galleries:list gallery:read offline_access';

/**
 * Permit WinApp to verify the approved Admin account, list galleries, read
 * selected gallery details, upload images and renew access.
 * Type: string. Units: ordered scope tokens. Scope: explicitly renewable native
 * grant.
 * Consumers: app_linking_public_client, app_linking_scope_base.
 * Rationale: The offline_access suffix permits renewal of exactly this approved
 * bundle.
 */
const APP_LINKING_READ_UPLOAD_OFFLINE_SCOPE = 'link:identity galleries:list gallery:read gallery:upload offline_access';

/**
 * Permit WinApp to verify the approved Admin account, list galleries, create
 * galleries and renew access.
 * Type: string. Units: ordered scope tokens. Scope: explicitly renewable native
 * grant.
 * Consumers: app_linking_public_client, app_linking_scope_base.
 * Rationale: The offline_access suffix permits renewal of exactly this approved
 * bundle.
 */
const APP_LINKING_CREATE_OFFLINE_SCOPE = 'link:identity galleries:list galleries:create offline_access';

/**
 * Permit WinApp to verify the approved Admin account, list galleries, read
 * selected gallery details, create galleries and renew access.
 * Type: string. Units: ordered scope tokens. Scope: explicitly renewable native
 * grant.
 * Consumers: app_linking_public_client, app_linking_scope_base.
 * Rationale: The offline_access suffix permits renewal of exactly this approved
 * bundle.
 */
const APP_LINKING_READ_CREATE_OFFLINE_SCOPE = 'link:identity galleries:list gallery:read galleries:create offline_access';

/**
 * Permit WinApp to verify the approved Admin account, list galleries, upload
 * images, create galleries and renew access.
 * Type: string. Units: ordered scope tokens. Scope: explicitly renewable native
 * grant.
 * Consumers: app_linking_public_client, app_linking_scope_base.
 * Rationale: The offline_access suffix permits renewal of exactly this approved
 * bundle.
 */
const APP_LINKING_UPLOAD_CREATE_OFFLINE_SCOPE = 'link:identity galleries:list gallery:upload galleries:create offline_access';

/**
 * Permit WinApp to verify the approved Admin account, list galleries, read
 * selected gallery details, upload images, create galleries and renew access.
 * Type: string. Units: ordered scope tokens. Scope: explicitly renewable native
 * grant.
 * Consumers: app_linking_public_client, app_linking_scope_base.
 * Rationale: The offline_access suffix permits renewal of exactly this approved
 * bundle.
 */
const APP_LINKING_READ_UPLOAD_CREATE_OFFLINE_SCOPE = 'link:identity galleries:list gallery:read gallery:upload galleries:create offline_access';

/**
 * Permit WinApp to verify the approved Admin account, list galleries, read
 * selected gallery details, change gallery visibility and renew access.
 * Type: string. Units: ordered scope tokens. Scope: explicitly renewable native
 * grant.
 * Consumers: app_linking_public_client, app_linking_scope_base.
 * Rationale: The offline_access suffix permits renewal of exactly this approved
 * bundle.
 */
const APP_LINKING_VISIBILITY_OFFLINE_SCOPE = 'link:identity galleries:list gallery:read gallery:visibility offline_access';

/**
 * Permit WinApp to verify the approved Admin account, list galleries, read
 * selected gallery details, upload images, change gallery visibility and renew
 * access.
 * Type: string. Units: ordered scope tokens. Scope: explicitly renewable native
 * grant.
 * Consumers: app_linking_public_client, app_linking_scope_base.
 * Rationale: The offline_access suffix permits renewal of exactly this approved
 * bundle.
 */
const APP_LINKING_UPLOAD_VISIBILITY_OFFLINE_SCOPE = 'link:identity galleries:list gallery:read gallery:upload gallery:visibility offline_access';

/**
 * Permit WinApp to verify the approved Admin account, list galleries, read
 * selected gallery details, create galleries, change gallery visibility and
 * renew access.
 * Type: string. Units: ordered scope tokens. Scope: explicitly renewable native
 * grant.
 * Consumers: app_linking_public_client, app_linking_scope_base.
 * Rationale: The offline_access suffix permits renewal of exactly this approved
 * bundle.
 */
const APP_LINKING_CREATE_VISIBILITY_OFFLINE_SCOPE = 'link:identity galleries:list gallery:read galleries:create gallery:visibility offline_access';

/**
 * Permit WinApp to verify the approved Admin account, list galleries, read
 * selected gallery details, upload images, create galleries, change gallery
 * visibility and renew access.
 * Type: string. Units: ordered scope tokens. Scope: explicitly renewable native
 * grant.
 * Consumers: app_linking_public_client, app_linking_scope_base.
 * Rationale: The offline_access suffix permits renewal of exactly this approved
 * bundle.
 */
const APP_LINKING_UPLOAD_CREATE_VISIBILITY_OFFLINE_SCOPE = 'link:identity galleries:list gallery:read gallery:upload galleries:create gallery:visibility offline_access';

/**
 * Distinguish opaque authorization codes by their wire prefix.
 * Type: string. Units: ASCII prefix. Scope: one authorization code.
 * Consumers: app_linking_code_generate, app_linking_code_hash.
 * Rationale: pglc_ identifies the code before its canonical payload is hashed.
 */
const APP_LINKING_CODE_PREFIX = 'pglc_';

/**
 * Distinguish opaque access tokens by their wire prefix.
 * Type: string. Units: ASCII prefix. Scope: one native bearer token.
 * Consumers: app_linking_access_token_generate, app_linking_access_token_hash.
 * Rationale: pgla_ identifies an access token before its canonical payload is
 * hashed.
 */
const APP_LINKING_ACCESS_TOKEN_PREFIX = 'pgla_';

/**
 * Generate authority material with 256 bits of cryptographic entropy.
 * Type: int. Units: random bytes. Scope: one code or access token.
 * Consumers: app_linking_code_generate, app_linking_access_token_generate.
 * Rationale: 32 random bytes resist guessing without a client secret.
 */
const APP_LINKING_SECRET_ENTROPY_BYTES = 32;

/**
 * Recognize the canonical unpadded base64url encoding of 32 bytes.
 * Type: int. Units: ASCII characters. Scope: S256 and opaque secret payloads.
 * Consumers: app_linking_canonical_256bit_value_valid.
 * Rationale: 32 bytes produce exactly 43 unpadded base64url characters.
 */
const APP_LINKING_256BIT_ENCODED_LENGTH = 43;

/**
 * Enforce the lower length boundary defined for a PKCE verifier.
 * Type: int. Units: ASCII characters. Scope: one verifier.
 * Consumers: app_linking_pkce_s256_verify.
 * Rationale: RFC 7636 requires at least 43 unreserved characters.
 */
const APP_LINKING_PKCE_VERIFIER_MIN_LENGTH = 43;

/**
 * Enforce the upper length boundary defined for a PKCE verifier.
 * Type: int. Units: ASCII characters. Scope: one verifier.
 * Consumers: app_linking_pkce_s256_verify.
 * Rationale: RFC 7636 permits at most 128 unreserved characters.
 */
const APP_LINKING_PKCE_VERIFIER_MAX_LENGTH = 128;

/**
 * Reject redirect ports outside the TCP port range.
 * Type: int. Units: TCP port number. Scope: loopback redirect registration.
 * Consumers: app_linking_redirect_uri_valid.
 * Rationale: 65535 is the largest valid transport port.
 */
const APP_LINKING_LOOPBACK_PORT_MAX = 65535;

/**
 * Resolve the one built-in public native client without implying binary trust.
 *
 * @param string $clientId Client identifier supplied by the authorization request.
 * @return array{client_id:string,type:string,redirect_path:string,scopes:list<string>}|null
 *     Registered public-client policy, or null when the identifier is unknown.
 */
function app_linking_public_client(string $clientId): ?array
{
    if ($clientId !== APP_LINKING_WINAPP_CLIENT_ID) {
        return null;
    }

    $scopes = [
        APP_LINKING_IDENTITY_SCOPE,
        APP_LINKING_GALLERIES_SCOPE,
        APP_LINKING_UPLOAD_SCOPE,
        APP_LINKING_READ_SCOPE,
        APP_LINKING_READ_UPLOAD_SCOPE,
        APP_LINKING_IDENTITY_OFFLINE_SCOPE,
        APP_LINKING_GALLERIES_OFFLINE_SCOPE,
        APP_LINKING_UPLOAD_OFFLINE_SCOPE,
        APP_LINKING_READ_OFFLINE_SCOPE,
        APP_LINKING_READ_UPLOAD_OFFLINE_SCOPE,
        APP_LINKING_CREATE_SCOPE,
        APP_LINKING_READ_CREATE_SCOPE,
        APP_LINKING_UPLOAD_CREATE_SCOPE,
        APP_LINKING_READ_UPLOAD_CREATE_SCOPE,
        APP_LINKING_CREATE_OFFLINE_SCOPE,
        APP_LINKING_READ_CREATE_OFFLINE_SCOPE,
        APP_LINKING_UPLOAD_CREATE_OFFLINE_SCOPE,
        APP_LINKING_READ_UPLOAD_CREATE_OFFLINE_SCOPE,
        APP_LINKING_VISIBILITY_SCOPE,
        APP_LINKING_UPLOAD_VISIBILITY_SCOPE,
        APP_LINKING_CREATE_VISIBILITY_SCOPE,
        APP_LINKING_UPLOAD_CREATE_VISIBILITY_SCOPE,
        APP_LINKING_VISIBILITY_OFFLINE_SCOPE,
        APP_LINKING_UPLOAD_VISIBILITY_OFFLINE_SCOPE,
        APP_LINKING_CREATE_VISIBILITY_OFFLINE_SCOPE,
        APP_LINKING_UPLOAD_CREATE_VISIBILITY_OFFLINE_SCOPE,
    ];
    $mediaScopes = [];
    foreach ($scopes as $scope) {
        if ($scope !== APP_LINKING_IDENTITY_SCOPE && !app_linking_scope_requests_refresh($scope)) {
            $mediaScopes[] = $scope . APP_LINKING_MEDIA_SUFFIX;
            $mediaScopes[] = $scope . APP_LINKING_PRESENTATION_SUFFIX;
        }
    }
    return [
        'client_id' => APP_LINKING_WINAPP_CLIENT_ID,
        'type' => 'public',
        'redirect_path' => APP_LINKING_WINAPP_REDIRECT_PATH,
        'scopes' => array_merge($scopes, $mediaScopes, [
            APP_LINKING_SEARCH_SCOPE, APP_LINKING_UPLOAD_SEARCH_SCOPE,
            APP_LINKING_GALLERIES_ROLLING_SCOPE,
            APP_LINKING_UPLOAD_ROLLING_SCOPE,
            APP_LINKING_GALLERIES_SEARCH_ROLLING_SCOPE,
            APP_LINKING_UPLOAD_SEARCH_ROLLING_SCOPE,
            ...APP_LINKING_PRESENTATION_ROLLING_SCOPES,
        ], array_keys(app_linking_admin_media_scopes())),
    ];
}

/**
 * Register exact Admin photo bundles and their underlying capability bases.
 *
 * @return array<string,string> Registered exact scope to base grant mapping.
 */
function app_linking_admin_media_scopes(): array
{
    static $scopes = [];
    if ($scopes !== []) {
        return $scopes;
    }
    $bases = [
        APP_LINKING_GALLERIES_SCOPE, APP_LINKING_UPLOAD_SCOPE,
        APP_LINKING_READ_SCOPE, APP_LINKING_READ_UPLOAD_SCOPE,
        APP_LINKING_CREATE_SCOPE, APP_LINKING_READ_CREATE_SCOPE,
        APP_LINKING_UPLOAD_CREATE_SCOPE, APP_LINKING_READ_UPLOAD_CREATE_SCOPE,
        APP_LINKING_VISIBILITY_SCOPE, APP_LINKING_UPLOAD_VISIBILITY_SCOPE,
        APP_LINKING_CREATE_VISIBILITY_SCOPE, APP_LINKING_UPLOAD_CREATE_VISIBILITY_SCOPE,
    ];
    foreach ($bases as $base) {
        foreach (['', ' galleries:search'] as $search) {
            foreach ([
                APP_LINKING_ADMIN_MEDIA_SUFFIX,
                APP_LINKING_ADMIN_MEDIA_SUFFIX . APP_LINKING_ADMIN_DELETE_SUFFIX,
                APP_LINKING_ADMIN_PRESENTATION_SUFFIX,
                APP_LINKING_ADMIN_PRESENTATION_SUFFIX . APP_LINKING_ADMIN_DELETE_SUFFIX,
            ] as $media) {
                $scope = $base . $search . $media;
                $scopes[$scope] = $base;
                $scopes[$scope . APP_LINKING_ROLLING_SUFFIX] = $base;
            }
        }
    }
    return $scopes;
}

/**
 * Separate the canonical native reader suffix from the underlying grant bundle.
 *
 * @param string $scope Exact scope string from the request or stored grant.
 * @return string Underlying bundle before the known suffix, or the input unchanged.
 */
function app_linking_scope_without_media(string $scope): string
{
    $adminBase = app_linking_admin_media_scopes()[$scope] ?? null;
    if ($adminBase !== null) {
        return $adminBase;
    }
    if (in_array($scope, APP_LINKING_PRESENTATION_ROLLING_SCOPES, true)) {
        $suffix = APP_LINKING_PRESENTATION_SUFFIX . APP_LINKING_ROLLING_SUFFIX;
        return substr($scope, 0, -strlen($suffix)) . APP_LINKING_ROLLING_SUFFIX;
    }
    foreach ([
        APP_LINKING_ADMIN_MEDIA_SUFFIX . APP_LINKING_ADMIN_DELETE_SUFFIX,
        APP_LINKING_ADMIN_PRESENTATION_SUFFIX . APP_LINKING_ADMIN_DELETE_SUFFIX,
        APP_LINKING_ADMIN_DELETE_SUFFIX,
        APP_LINKING_PRESENTATION_SUFFIX,
        APP_LINKING_MEDIA_SUFFIX,
    ] as $suffix) {
        if (str_ends_with($scope, $suffix)) {
            return substr($scope, 0, -strlen($suffix));
        }
    }
    return $scope;
}

/**
 * Resolve the exact capability bundle without widening an approved scope.
 *
 * @param string $scope Exact registered scope bundle retained on a grant.
 * @return string|null Its exact transient capability bundle, or null if unknown.
 */
function app_linking_scope_base(string $scope): ?string
{
    if (app_linking_scope_allows_admin_media($scope)) {
        return app_linking_scope_without_media($scope);
    }
    if (app_linking_scope_requests_rolling($scope)) {
        return match (app_linking_scope_without_media($scope)) {
            APP_LINKING_GALLERIES_ROLLING_SCOPE,
            APP_LINKING_GALLERIES_SEARCH_ROLLING_SCOPE => APP_LINKING_GALLERIES_SCOPE,
            default => APP_LINKING_UPLOAD_SCOPE,
        };
    }

    return match (app_linking_scope_without_media($scope)) {
        APP_LINKING_IDENTITY_SCOPE, APP_LINKING_IDENTITY_OFFLINE_SCOPE =>
            APP_LINKING_IDENTITY_SCOPE,
        APP_LINKING_GALLERIES_SCOPE, APP_LINKING_GALLERIES_OFFLINE_SCOPE,
        APP_LINKING_SEARCH_SCOPE =>
            APP_LINKING_GALLERIES_SCOPE,
        APP_LINKING_UPLOAD_SCOPE, APP_LINKING_UPLOAD_OFFLINE_SCOPE,
        APP_LINKING_UPLOAD_SEARCH_SCOPE =>
            APP_LINKING_UPLOAD_SCOPE,
        APP_LINKING_READ_SCOPE, APP_LINKING_READ_OFFLINE_SCOPE =>
            APP_LINKING_READ_SCOPE,
        APP_LINKING_READ_UPLOAD_SCOPE, APP_LINKING_READ_UPLOAD_OFFLINE_SCOPE =>
            APP_LINKING_READ_UPLOAD_SCOPE,
        APP_LINKING_CREATE_SCOPE, APP_LINKING_CREATE_OFFLINE_SCOPE =>
            APP_LINKING_CREATE_SCOPE,
        APP_LINKING_READ_CREATE_SCOPE, APP_LINKING_READ_CREATE_OFFLINE_SCOPE =>
            APP_LINKING_READ_CREATE_SCOPE,
        APP_LINKING_UPLOAD_CREATE_SCOPE, APP_LINKING_UPLOAD_CREATE_OFFLINE_SCOPE =>
            APP_LINKING_UPLOAD_CREATE_SCOPE,
        APP_LINKING_READ_UPLOAD_CREATE_SCOPE, APP_LINKING_READ_UPLOAD_CREATE_OFFLINE_SCOPE =>
            APP_LINKING_READ_UPLOAD_CREATE_SCOPE,
        APP_LINKING_VISIBILITY_SCOPE, APP_LINKING_VISIBILITY_OFFLINE_SCOPE =>
            APP_LINKING_VISIBILITY_SCOPE,
        APP_LINKING_UPLOAD_VISIBILITY_SCOPE, APP_LINKING_UPLOAD_VISIBILITY_OFFLINE_SCOPE =>
            APP_LINKING_UPLOAD_VISIBILITY_SCOPE,
        APP_LINKING_CREATE_VISIBILITY_SCOPE, APP_LINKING_CREATE_VISIBILITY_OFFLINE_SCOPE =>
            APP_LINKING_CREATE_VISIBILITY_SCOPE,
        APP_LINKING_UPLOAD_CREATE_VISIBILITY_SCOPE, APP_LINKING_UPLOAD_CREATE_VISIBILITY_OFFLINE_SCOPE =>
            APP_LINKING_UPLOAD_CREATE_VISIBILITY_SCOPE,
        default => null,
    };
}

/**
 * Recognize the gallery listing permission in an exact registered bundle.
 *
 * @param string $scope Exact scope string from the live grant.
 * @return bool True for a known bundle containing the listing permission.
 */
function app_linking_scope_allows_gallery_listing(string $scope): bool
{
    $base = app_linking_scope_base($scope);
    return $base !== null && $base !== APP_LINKING_IDENTITY_SCOPE;
}

/**
 * Recognize the explicitly approved metadata search permission.
 *
 * @param string $scope Exact consented bundle from the live grant.
 * @return bool True for a registered search bundle.
 */
function app_linking_scope_allows_gallery_search(string $scope): bool
{
    if (app_linking_scope_allows_admin_media($scope)) {
        return str_contains($scope, ' galleries:search' . APP_LINKING_ADMIN_MEDIA_SUFFIX);
    }
    return in_array(
        $scope,
        [
            APP_LINKING_SEARCH_SCOPE,
            APP_LINKING_UPLOAD_SEARCH_SCOPE,
            APP_LINKING_GALLERIES_SEARCH_ROLLING_SCOPE,
            APP_LINKING_UPLOAD_SEARCH_ROLLING_SCOPE,
            APP_LINKING_GALLERIES_SEARCH_PRESENTATION_ROLLING_SCOPE,
            APP_LINKING_UPLOAD_SEARCH_PRESENTATION_ROLLING_SCOPE,
        ],
        true,
    );
}

/**
 * Recognize only the registered rolling access bundles.
 *
 * @param string $scope Exact proposed or previously approved scope bundle.
 * @return bool True for a registered rolling access bundle.
 */
function app_linking_scope_requests_rolling(string $scope): bool
{
    if (app_linking_scope_allows_admin_media($scope)) {
        return str_ends_with($scope, APP_LINKING_ROLLING_SUFFIX);
    }
    return in_array($scope, [
        APP_LINKING_GALLERIES_ROLLING_SCOPE,
        APP_LINKING_UPLOAD_ROLLING_SCOPE,
        APP_LINKING_GALLERIES_SEARCH_ROLLING_SCOPE,
        APP_LINKING_UPLOAD_SEARCH_ROLLING_SCOPE,
        ...APP_LINKING_PRESENTATION_ROLLING_SCOPES,
    ], true);
}

/**
 * Recognize exact temporary and rolling grants for restricted photo access.
 *
 * @param string $scope Exact consented scope from the current grant.
 * @return bool True only for a registered Admin media bundle.
 */
function app_linking_scope_allows_admin_media(string $scope): bool
{
    return isset(app_linking_admin_media_scopes()[$scope]);
}

/**
 * Recognize the exact registered grants with explicit presentation consent.
 *
 * @param string $scope Exact scope proposed by the client or stored on a grant.
 * @return bool True for a registered temporary or rolling presentation grant.
 */
function app_linking_scope_allows_presentation(string $scope): bool
{
    if (app_linking_scope_allows_admin_media($scope)) {
        return str_contains($scope, APP_LINKING_ADMIN_PRESENTATION_SUFFIX);
    }
    if (in_array($scope, APP_LINKING_PRESENTATION_ROLLING_SCOPES, true)) {
        return true;
    }
    $client = app_linking_public_client(APP_LINKING_WINAPP_CLIENT_ID);
    return str_ends_with($scope, APP_LINKING_PRESENTATION_SUFFIX)
        && in_array($scope, $client['scopes'] ?? [], true);
}

/**
 * Recognize only bundles explicitly requesting refresh-token authority.
 *
 * @param string $scope Exact registered scope bundle requested by the client.
 * @return bool True only when offline access was explicitly requested.
 */
function app_linking_scope_requests_refresh(string $scope): bool
{
    return app_linking_scope_requests_rolling($scope) || in_array($scope, [
        APP_LINKING_IDENTITY_OFFLINE_SCOPE,
        APP_LINKING_GALLERIES_OFFLINE_SCOPE,
        APP_LINKING_UPLOAD_OFFLINE_SCOPE,
        APP_LINKING_READ_OFFLINE_SCOPE,
        APP_LINKING_READ_UPLOAD_OFFLINE_SCOPE,
        APP_LINKING_CREATE_OFFLINE_SCOPE,
        APP_LINKING_READ_CREATE_OFFLINE_SCOPE,
        APP_LINKING_UPLOAD_CREATE_OFFLINE_SCOPE,
        APP_LINKING_READ_UPLOAD_CREATE_OFFLINE_SCOPE,
        APP_LINKING_VISIBILITY_OFFLINE_SCOPE,
        APP_LINKING_UPLOAD_VISIBILITY_OFFLINE_SCOPE,
        APP_LINKING_CREATE_VISIBILITY_OFFLINE_SCOPE,
        APP_LINKING_UPLOAD_CREATE_VISIBILITY_OFFLINE_SCOPE,
    ], true);
}

/**
 * Admit rolling renewal on HTTPS and verified direct localhost HTTP.
 *
 * The HTTP boundary must obtain the issuer from the configured address and
 * verify the request socket. Existing offline-only bundles still require HTTPS.
 *
 * @param string $issuer Previously validated exact installation issuer.
 * @param string $scope Exact scope bundle requested or granted.
 * @return bool True when this transport permits the scope's renewal policy.
 */
function app_linking_scope_transport_allowed(string $issuer, string $scope): bool
{
    return str_starts_with($issuer, 'https://')
        || !app_linking_scope_requests_refresh($scope)
        || (app_linking_scope_requests_rolling($scope)
            && parse_url($issuer, PHP_URL_SCHEME) === 'http'
            && parse_url($issuer, PHP_URL_HOST) === 'localhost');
}

/**
 * Recognize an exact registered scope bundle for the named public client.
 *
 * @param string $clientId Registered public-client identifier.
 * @param string $scope Exact scope bundle requested or granted.
 * @return bool True only for a registered bundle with verified required storage.
 */
function app_linking_scope_allowed(string $clientId, string $scope): bool
{
    $client = app_linking_public_client($clientId);
    if ($client === null || !in_array($scope, $client['scopes'], true)) {
        return false;
    }
    if ($scope !== app_linking_scope_without_media($scope)) {
        try {
            if (!app_linking_media_ready(app_linking_scope_allows_presentation($scope))
                || (app_linking_scope_allows_admin_media($scope)
                    && !app_linking_admin_media_ready())
                || !schema_inspection_is_available(app_linking_wide_scope_schema_status())) {
                return false;
            }
        } catch (\Throwable) {
            return false;
        }
    }
    if (app_linking_visibility_scope_allowed($scope)) {
        if (!APP_LINKING_VISIBILITY_READY) {
            return false;
        }
        try {
            app_linking_visibility_require_schema();
        } catch (\Throwable) {
            // Incomplete mutation storage removes only visibility authority.
            return false;
        }
    }
    if (in_array(app_linking_scope_base($scope), [
        APP_LINKING_READ_SCOPE, APP_LINKING_READ_UPLOAD_SCOPE,
        APP_LINKING_CREATE_SCOPE, APP_LINKING_READ_CREATE_SCOPE,
        APP_LINKING_UPLOAD_CREATE_SCOPE, APP_LINKING_READ_UPLOAD_CREATE_SCOPE,
        APP_LINKING_VISIBILITY_SCOPE, APP_LINKING_UPLOAD_VISIBILITY_SCOPE,
        APP_LINKING_CREATE_VISIBILITY_SCOPE, APP_LINKING_UPLOAD_CREATE_VISIBILITY_SCOPE,
    ], true)) {
        try {
            return schema_inspection_is_available(app_linking_wide_scope_schema_status());
        } catch (\Throwable) {
            // Unknown wide storage must not remove existing short-scope support.
            return false;
        }
    }
    return true;
}

/**
 * Recognize the exact bundles explicitly requesting gallery visibility changes.
 *
 * @param string $scope Exact grant scope, including any offline suffix.
 * @return bool True for a registered visibility bundle, independent of readiness.
 */
function app_linking_visibility_scope_allowed(string $scope): bool
{
    return in_array(app_linking_scope_base($scope), [
        APP_LINKING_VISIBILITY_SCOPE,
        APP_LINKING_UPLOAD_VISIBILITY_SCOPE,
        APP_LINKING_CREATE_VISIBILITY_SCOPE,
        APP_LINKING_UPLOAD_CREATE_VISIBILITY_SCOPE,
    ], true);
}

/**
 * Reinspect long-scope storage immediately before an authority transition.
 *
 * @param string $clientId Registered public-client identifier.
 * @param string $scope Exact proposed or previously approved scope bundle.
 * @return void Returns only while a wide scope retains verified required storage.
 * @throws \RuntimeException When wide storage becomes missing or unknown.
 */
function app_linking_recheck_wide_scope(string $clientId, string $scope): void
{
    if (!app_linking_scope_allows_admin_media($scope)
        && !in_array($scope, APP_LINKING_PRESENTATION_ROLLING_SCOPES, true)
        && !in_array(app_linking_scope_base($scope), [
            APP_LINKING_READ_SCOPE, APP_LINKING_READ_UPLOAD_SCOPE,
            APP_LINKING_CREATE_SCOPE, APP_LINKING_READ_CREATE_SCOPE,
            APP_LINKING_UPLOAD_CREATE_SCOPE, APP_LINKING_READ_UPLOAD_CREATE_SCOPE,
            APP_LINKING_VISIBILITY_SCOPE, APP_LINKING_UPLOAD_VISIBILITY_SCOPE,
            APP_LINKING_CREATE_VISIBILITY_SCOPE, APP_LINKING_UPLOAD_CREATE_VISIBILITY_SCOPE,
        ], true)) {
        return;
    }
    schema_inspection_reset_request_cache();
    if (!app_linking_scope_allowed($clientId, $scope)) {
        throw new \RuntimeException('Gallery scoped access is unavailable.');
    }
}

/**
 * Accept only a canonical registered loopback callback with an explicit port.
 *
 * The selected complete URI, including its port, must also be bound to the
 * authorization request and compared exactly during exchange. This predicate
 * validates syntax only; it does not prove the client binary's identity.
 *
 * @param string $clientId Registered public-client identifier.
 * @param string $redirectUri Complete candidate callback URI.
 * @return bool True only for the registered path and literal loopback host.
 */
function app_linking_redirect_uri_valid(
    string $clientId,
    string $redirectUri,
): bool {
    if (app_linking_public_client($clientId) === null) {
        return false;
    }
    // A TCP port has at most five decimal digits; reject zero and leading zeros.
    $pattern = '~\Ahttp://(?:127\.0\.0\.1|\[::1\]):([1-9][0-9]{0,4})'
        . preg_quote(APP_LINKING_WINAPP_REDIRECT_PATH, '~') . '\z~D';
    if (preg_match($pattern, $redirectUri, $matches) !== 1) {
        return false;
    }

    return (int) $matches[1] <= APP_LINKING_LOOPBACK_PORT_MAX;
}

/**
 * Reject malformed or noncanonical base64url representations of 32 bytes.
 *
 * @param string $value Unpadded ASCII base64url value to validate.
 * @return bool True only for the exact canonical encoding of 32 bytes.
 */
function app_linking_canonical_256bit_value_valid(string $value): bool
{
    if (
        strlen($value) !== APP_LINKING_256BIT_ENCODED_LENGTH
        || preg_match('/\A[A-Za-z0-9_-]+\z/D', $value) !== 1
    ) {
        return false;
    }

    // The fixed 32-byte encoding requires exactly one base64 padding character.
    $decoded = base64_decode(strtr($value, '-_', '+/') . '=', true);
    if ($decoded === false) {
        return false;
    }
    $canonical = rtrim(strtr(base64_encode($decoded), '+/', '-_'), '=');
    return hash_equals($canonical, $value);
}

/**
 * Validate the canonical SHA-256 PKCE challenge before storing it.
 *
 * @param string $challenge Unpadded base64url S256 challenge.
 * @return bool True only for a canonical 32-byte SHA-256 representation.
 */
function app_linking_pkce_s256_challenge_valid(string $challenge): bool
{
    return app_linking_canonical_256bit_value_valid($challenge);
}

/**
 * Verify the RFC 7636 verifier against the stored S256 challenge.
 *
 * @param string $challenge Canonical stored unpadded base64url challenge.
 * @param string $verifier Client-provided ASCII PKCE verifier.
 * @return bool True only for a valid verifier with a matching S256 digest.
 */
function app_linking_pkce_s256_verify(
    string $challenge,
    string $verifier,
): bool {
    $length = strlen($verifier);
    if (
        !app_linking_pkce_s256_challenge_valid($challenge)
        || $length < APP_LINKING_PKCE_VERIFIER_MIN_LENGTH
        || $length > APP_LINKING_PKCE_VERIFIER_MAX_LENGTH
        || preg_match('/\A[A-Za-z0-9._~-]+\z/D', $verifier) !== 1
    ) {
        return false;
    }
    $digest = hash('sha256', $verifier, true);
    $computed = rtrim(strtr(base64_encode($digest), '+/', '-_'), '=');
    return hash_equals($challenge, $computed);
}

/**
 * Create one opaque authorization code using the shared cryptographic owner.
 *
 * The caller must bind and expire the code, persist only its hash, and deliver
 * its plaintext only through the approved authorization flow.
 *
 * @return string Prefixed 256-bit URL-safe authorization code.
 * @throws \Exception If the platform cannot provide cryptographic randomness.
 */
function app_linking_code_generate(): string
{
    return APP_LINKING_CODE_PREFIX
        . security_opaque_token_generate(APP_LINKING_SECRET_ENTROPY_BYTES);
}

/**
 * Create one opaque access token using the shared cryptographic owner.
 *
 * The caller must bind and expire the token, persist only its hash, and deliver
 * its plaintext only through the approved token exchange.
 *
 * @return string Prefixed 256-bit URL-safe access token.
 * @throws \Exception If the platform cannot provide cryptographic randomness.
 */
function app_linking_access_token_generate(): string
{
    return APP_LINKING_ACCESS_TOKEN_PREFIX
        . security_opaque_token_generate(APP_LINKING_SECRET_ENTROPY_BYTES);
}

/**
 * Hash a canonical authorization code without accepting an access token.
 *
 * @param string $code Plaintext authorization code received from the client.
 * @return string|null Lowercase SHA-256 digest, or null for a malformed code.
 */
function app_linking_code_hash(string $code): ?string
{
    if (
        strlen($code) !== strlen(APP_LINKING_CODE_PREFIX)
            + APP_LINKING_256BIT_ENCODED_LENGTH
        || !str_starts_with($code, APP_LINKING_CODE_PREFIX)
        || !app_linking_canonical_256bit_value_valid(
            substr($code, strlen(APP_LINKING_CODE_PREFIX)),
        )
    ) {
        return null;
    }
    return security_authority_token_hash($code);
}

/**
 * Hash a canonical access token without accepting an authorization code.
 *
 * @param string $token Plaintext access token received from the client.
 * @return string|null Lowercase SHA-256 digest, or null for a malformed token.
 */
function app_linking_access_token_hash(string $token): ?string
{
    if (
        strlen($token) !== strlen(APP_LINKING_ACCESS_TOKEN_PREFIX)
            + APP_LINKING_256BIT_ENCODED_LENGTH
        || !str_starts_with($token, APP_LINKING_ACCESS_TOKEN_PREFIX)
        || !app_linking_canonical_256bit_value_valid(
            substr($token, strlen(APP_LINKING_ACCESS_TOKEN_PREFIX)),
        )
    ) {
        return null;
    }
    return security_authority_token_hash($token);
}
