<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/google_auth.php
 * Module Type: Service
 *
 * Purpose:
 *   Provides Google OpenID Connect helpers for optional admin login.
 *
 * Responsibilities:
 *   - Build Google authorization URLs for login and account linking
 *   - Exchange authorization codes for ID tokens
 *   - Verify Google ID tokens without adding Composer dependencies
 *   - Store only stable Google account identity metadata, never access tokens
 *
 * Author:
 *   Rudolf Klusal
 *
 * Contact:
 *   https://github.com/klusik
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 *
 * Notes:
 *   - Keep comments and docstrings intact when modifying this file.
 *   - Prefer small, readable changes over broad rewrites.
 *
 * Last Updated:
 *   2026-05-31
 */

declare(strict_types=1);

const CMS_GOOGLE_AUTH_ENDPOINT = 'https://accounts.google.com/o/oauth2/v2/auth';
const CMS_GOOGLE_TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';
const CMS_GOOGLE_JWKS_ENDPOINT = 'https://www.googleapis.com/oauth2/v3/certs';

/**
 * Return Google login configuration merged with safe defaults.
 */
function google_auth_config(): array
{
    // $config stores the installed configuration array.
    $config = cms_config();
    // $google stores optional Google sign-in settings from config.php.
    $google = is_array($config['google_login'] ?? null) ? $config['google_login'] : [];
    // $redirectUri stores the configured callback URL or the automatic front-controller URL.
    $redirectUri = trim((string) ($google['redirect_uri'] ?? ''));
    if ($redirectUri === '') {
        $redirectUri = absolute_public_url(url_for('admin_google_callback'));
    }

    return [
        'enabled' => array_key_exists('enabled', $google) ? (bool) $google['enabled'] : false,
        'client_id' => trim((string) ($google['client_id'] ?? '')),
        'client_secret' => (string) ($google['client_secret'] ?? ''),
        'redirect_uri' => $redirectUri,
        'prompt' => trim((string) ($google['prompt'] ?? 'select_account')),
    ];
}

/**
 * Return true when Google login has configuration and database support.
 */
function google_auth_ready(): bool
{
    // $config stores normalized Google auth settings.
    $config = google_auth_config();
    return (bool) $config['enabled']
        && $config['client_id'] !== ''
        && $config['client_secret'] !== ''
        && function_exists('db_table_exists')
        && db_table_exists('user_google_accounts');
}

/**
 * Return true when the Google account link table exists.
 */
function google_auth_schema_ready(): bool
{
    return function_exists('db_table_exists') && db_table_exists('user_google_accounts');
}

/**
 * Return a Google authorization URL and remember its state in the session.
 */
function google_auth_authorization_url(string $mode, string $returnTarget = ''): string
{
    // $config stores normalized Google auth settings.
    $config = google_auth_config();
    // $state stores the CSRF value returned by Google.
    $state = bin2hex(random_bytes(24));
    // $states stores pending Google OAuth states for this admin session.
    $states = is_array($_SESSION['google_oauth_states'] ?? null) ? $_SESSION['google_oauth_states'] : [];

    foreach ($states as $key => $entry) {
        if (!is_array($entry) || (time() - (int) ($entry['created_at'] ?? 0)) > 900) {
            unset($states[$key]);
        }
    }

    $states[$state] = [
        'mode' => $mode === 'link' ? 'link' : 'login',
        'return' => sanitize_login_return_target($returnTarget, url_for('admin')),
        'user_id' => current_user() ? (int) current_user()['id'] : null,
        'created_at' => time(),
    ];
    $_SESSION['google_oauth_states'] = $states;

    // $query stores the Google authorization request parameters.
    $query = [
        'client_id' => $config['client_id'],
        'redirect_uri' => $config['redirect_uri'],
        'response_type' => 'code',
        'scope' => 'openid email profile',
        'state' => $state,
        'access_type' => 'online',
    ];
    if ($config['prompt'] !== '') {
        $query['prompt'] = $config['prompt'];
    }

    return CMS_GOOGLE_AUTH_ENDPOINT . '?' . http_build_query($query);
}

/**
 * Consume and validate one stored Google OAuth state value.
 */
function google_auth_consume_state(string $state): ?array
{
    // $states stores pending Google OAuth states for this admin session.
    $states = is_array($_SESSION['google_oauth_states'] ?? null) ? $_SESSION['google_oauth_states'] : [];
    if ($state === '' || empty($states[$state]) || !is_array($states[$state])) {
        return null;
    }

    // $entry stores the pending state metadata.
    $entry = $states[$state];
    unset($states[$state]);
    $_SESSION['google_oauth_states'] = $states;

    if ((time() - (int) ($entry['created_at'] ?? 0)) > 900) {
        return null;
    }

    return $entry;
}

/**
 * Send an HTTPS form POST and decode the returned JSON document.
 */
function google_auth_http_post_json(string $url, array $fields): array
{
    // $body stores the form-encoded request body.
    $body = http_build_query($fields);
    // $headers stores the HTTP request headers.
    $headers = ['Content-Type: application/x-www-form-urlencoded'];

    if (function_exists('curl_init')) {
        // $ch stores the cURL handle for hosts where cURL is enabled.
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        // $raw stores the HTTP response body.
        $raw = curl_exec($ch);
        // $error stores the cURL error when the request fails before an HTTP response.
        $error = curl_error($ch);
        // $status stores the HTTP status code.
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($raw === false || $status < 200 || $status >= 300) {
            throw new RuntimeException('Google token request failed: ' . ($error !== '' ? $error : 'HTTP ' . $status));
        }
    } else {
        // $context stores the stream wrapper request options.
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers),
                'content' => $body,
                'timeout' => 20,
            ],
        ]);
        // $raw stores the HTTP response body.
        $raw = @file_get_contents($url, false, $context);
        if ($raw === false) {
            throw new RuntimeException('Google token request failed.');
        }
    }

    // $json stores the decoded JSON response.
    $json = json_decode((string) $raw, true);
    if (!is_array($json)) {
        throw new RuntimeException('Google returned an invalid JSON response.');
    }
    return $json;
}

/**
 * Send an HTTPS GET and decode the returned JSON document.
 */
function google_auth_http_get_json(string $url): array
{
    if (function_exists('curl_init')) {
        // $ch stores the cURL handle for hosts where cURL is enabled.
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        // $raw stores the HTTP response body.
        $raw = curl_exec($ch);
        // $error stores the cURL error when the request fails before an HTTP response.
        $error = curl_error($ch);
        // $status stores the HTTP status code.
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($raw === false || $status < 200 || $status >= 300) {
            throw new RuntimeException('Google metadata request failed: ' . ($error !== '' ? $error : 'HTTP ' . $status));
        }
    } else {
        // $context stores the stream wrapper request options.
        $context = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 20]]);
        // $raw stores the HTTP response body.
        $raw = @file_get_contents($url, false, $context);
        if ($raw === false) {
            throw new RuntimeException('Google metadata request failed.');
        }
    }

    // $json stores the decoded JSON response.
    $json = json_decode((string) $raw, true);
    if (!is_array($json)) {
        throw new RuntimeException('Google returned invalid metadata JSON.');
    }
    return $json;
}

/**
 * Decode one base64url string.
 */
function google_auth_base64url_decode(string $value): string
{
    // $padded stores the value padded to a base64 length.
    $padded = strtr($value, '-_', '+/');
    $padded .= str_repeat('=', (4 - strlen($padded) % 4) % 4);
    // $decoded stores the decoded binary data.
    $decoded = base64_decode($padded, true);
    if ($decoded === false) {
        throw new RuntimeException('Invalid base64url value.');
    }
    return $decoded;
}

/**
 * Encode an ASN.1 DER length field.
 */
function google_auth_asn1_length(int $length): string
{
    if ($length < 128) {
        return chr($length);
    }

    // $bytes stores the big-endian length bytes.
    $bytes = '';
    while ($length > 0) {
        $bytes = chr($length & 0xff) . $bytes;
        $length >>= 8;
    }
    return chr(0x80 | strlen($bytes)) . $bytes;
}

/**
 * Encode one ASN.1 DER tag with content.
 */
function google_auth_asn1_tag(int $tag, string $content): string
{
    return chr($tag) . google_auth_asn1_length(strlen($content)) . $content;
}

/**
 * Encode a positive ASN.1 integer.
 */
function google_auth_asn1_integer(string $integer): string
{
    $integer = ltrim($integer, "\x00");
    if ($integer === '') {
        $integer = "\x00";
    }
    if ((ord($integer[0]) & 0x80) !== 0) {
        $integer = "\x00" . $integer;
    }
    return google_auth_asn1_tag(0x02, $integer);
}

/**
 * Encode an ASN.1 object identifier.
 */
function google_auth_asn1_oid(string $oid): string
{
    // $parts stores dotted OID integers.
    $parts = array_map('intval', explode('.', $oid));
    if (count($parts) < 2) {
        throw new RuntimeException('Invalid object identifier.');
    }

    // $encoded stores the binary DER OID body.
    $encoded = chr(($parts[0] * 40) + $parts[1]);
    for ($i = 2; $i < count($parts); $i++) {
        // $value stores one OID segment.
        $value = $parts[$i];
        // $segment stores base-128 bytes for the segment.
        $segment = chr($value & 0x7f);
        $value >>= 7;
        while ($value > 0) {
            $segment = chr(0x80 | ($value & 0x7f)) . $segment;
            $value >>= 7;
        }
        $encoded .= $segment;
    }

    return google_auth_asn1_tag(0x06, $encoded);
}

/**
 * Convert an RSA JWK public key to PEM.
 */
function google_auth_jwk_to_pem(array $jwk): string
{
    if (($jwk['kty'] ?? '') !== 'RSA' || empty($jwk['n']) || empty($jwk['e'])) {
        throw new RuntimeException('Unsupported Google JWK key.');
    }

    // $modulus stores the RSA modulus bytes.
    $modulus = google_auth_base64url_decode((string) $jwk['n']);
    // $exponent stores the RSA public exponent bytes.
    $exponent = google_auth_base64url_decode((string) $jwk['e']);
    // $rsaPublicKey stores the DER RSAPublicKey sequence.
    $rsaPublicKey = google_auth_asn1_tag(0x30, google_auth_asn1_integer($modulus) . google_auth_asn1_integer($exponent));
    // $algorithm stores the DER AlgorithmIdentifier for rsaEncryption.
    $algorithm = google_auth_asn1_tag(0x30, google_auth_asn1_oid('1.2.840.113549.1.1.1') . google_auth_asn1_tag(0x05, ''));
    // $subjectPublicKey stores the DER BIT STRING wrapping the RSA key.
    $subjectPublicKey = google_auth_asn1_tag(0x03, "\x00" . $rsaPublicKey);
    // $spki stores the DER SubjectPublicKeyInfo sequence.
    $spki = google_auth_asn1_tag(0x30, $algorithm . $subjectPublicKey);

    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
}

/**
 * Return cached Google public keys, refreshing them when the local cache expires.
 */
function google_auth_jwks(): array
{
    // $cachePath stores the local key cache path.
    $cachePath = dirname(__DIR__, 2) . '/cache/google_oidc_jwks.json';
    if (is_file($cachePath) && (time() - (int) filemtime($cachePath)) < 43200) {
        // $cached stores decoded cached keys.
        $cached = json_decode((string) file_get_contents($cachePath), true);
        if (is_array($cached) && is_array($cached['keys'] ?? null)) {
            return $cached;
        }
    }

    // $jwks stores the fetched Google public key set.
    $jwks = google_auth_http_get_json(CMS_GOOGLE_JWKS_ENDPOINT);
    if (!is_array($jwks['keys'] ?? null)) {
        throw new RuntimeException('Google key set did not contain public keys.');
    }
    if (!is_dir(dirname($cachePath))) {
        mkdir(dirname($cachePath), 0775, true);
    }
    file_put_contents($cachePath, json_encode($jwks, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    return $jwks;
}

/**
 * Verify a Google ID token and return its claims.
 */
function google_auth_verify_id_token(string $idToken): array
{
    // $parts stores the JWT header, claims, and signature segments.
    $parts = explode('.', $idToken);
    if (count($parts) !== 3) {
        throw new RuntimeException('Google ID token has an invalid shape.');
    }

    // $header stores decoded JWT header data.
    $header = json_decode(google_auth_base64url_decode($parts[0]), true);
    // $claims stores decoded JWT claims.
    $claims = json_decode(google_auth_base64url_decode($parts[1]), true);
    if (!is_array($header) || !is_array($claims)) {
        throw new RuntimeException('Google ID token JSON could not be decoded.');
    }
    if (($header['alg'] ?? '') !== 'RS256' || empty($header['kid'])) {
        throw new RuntimeException('Google ID token uses an unexpected signing header.');
    }

    // $jwks stores current Google signing keys.
    $jwks = google_auth_jwks();
    // $matchingKey stores the public key matching the JWT kid.
    $matchingKey = null;
    foreach ((array) $jwks['keys'] as $key) {
        if (is_array($key) && (string) ($key['kid'] ?? '') === (string) $header['kid']) {
            $matchingKey = $key;
            break;
        }
    }
    if ($matchingKey === null) {
        throw new RuntimeException('No matching Google signing key was found.');
    }

    if (!function_exists('openssl_verify')) {
        throw new RuntimeException('The PHP OpenSSL extension is required for Google ID token verification.');
    }

    // $pem stores the PEM public key used to verify the signature.
    $pem = google_auth_jwk_to_pem($matchingKey);
    // $signature stores the decoded JWT signature.
    $signature = google_auth_base64url_decode($parts[2]);
    // $signedPayload stores the signed header and claims bytes.
    $signedPayload = $parts[0] . '.' . $parts[1];
    // $verified stores the OpenSSL signature verification result.
    $verified = openssl_verify($signedPayload, $signature, $pem, OPENSSL_ALGO_SHA256);
    if ($verified !== 1) {
        throw new RuntimeException('Google ID token signature verification failed.');
    }

    // $config stores normalized Google auth settings.
    $config = google_auth_config();
    if ((string) ($claims['aud'] ?? '') !== $config['client_id']) {
        throw new RuntimeException('Google ID token audience does not match this site.');
    }
    if (!in_array((string) ($claims['iss'] ?? ''), ['accounts.google.com', 'https://accounts.google.com'], true)) {
        throw new RuntimeException('Google ID token issuer is invalid.');
    }
    if ((int) ($claims['exp'] ?? 0) < time()) {
        throw new RuntimeException('Google ID token has expired.');
    }
    if ((string) ($claims['sub'] ?? '') === '') {
        throw new RuntimeException('Google ID token does not contain a stable account id.');
    }
    if (($claims['email_verified'] ?? false) !== true && (string) ($claims['email_verified'] ?? '') !== 'true') {
        throw new RuntimeException('Google account email is not verified.');
    }

    return $claims;
}

/**
 * Exchange a Google authorization code for verified OpenID Connect claims.
 */
function google_auth_claims_from_code(string $code): array
{
    // $config stores normalized Google auth settings.
    $config = google_auth_config();
    // $response stores the OAuth token response.
    $response = google_auth_http_post_json(CMS_GOOGLE_TOKEN_ENDPOINT, [
        'code' => $code,
        'client_id' => $config['client_id'],
        'client_secret' => $config['client_secret'],
        'redirect_uri' => $config['redirect_uri'],
        'grant_type' => 'authorization_code',
    ]);

    if (empty($response['id_token'])) {
        throw new RuntimeException('Google did not return an ID token.');
    }

    return google_auth_verify_id_token((string) $response['id_token']);
}

/**
 * Return the Google account linked to one user, if present.
 */
function google_auth_linked_account(int $userId): ?array
{
    if (!google_auth_schema_ready()) {
        return null;
    }

    // $stmt stores the linked-account lookup query.
    $stmt = db()->prepare('SELECT * FROM user_google_accounts WHERE user_id = ? LIMIT 1');
    $stmt->execute([$userId]);
    // $row stores the linked Google account row.
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Return the admin user linked to a Google subject id, if present.
 */
function google_auth_user_by_subject(string $subject): ?array
{
    if (!google_auth_schema_ready() || $subject === '') {
        return null;
    }

    // $stmt stores the linked admin lookup query.
    $stmt = db()->prepare('SELECT u.id, u.username, u.email, u.role, uga.id AS google_account_id FROM user_google_accounts uga INNER JOIN users u ON u.id = uga.user_id WHERE uga.google_sub = ? LIMIT 1');
    $stmt->execute([$subject]);
    // $row stores the linked admin row.
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Link or refresh one Google identity for an existing admin account.
 */
function google_auth_link_account(int $userId, array $claims): void
{
    if (!google_auth_schema_ready()) {
        throw new RuntimeException('Google login migration has not been applied.');
    }

    // $subject stores Google's stable account identifier.
    $subject = (string) ($claims['sub'] ?? '');
    if ($subject === '') {
        throw new RuntimeException('Google account id is missing.');
    }

    // $existingUser stores any account already linked to the same Google subject.
    $existingUser = google_auth_user_by_subject($subject);
    if ($existingUser && (int) $existingUser['id'] !== $userId) {
        throw new RuntimeException('This Google account is already linked to another admin profile.');
    }

    // $email stores the verified Google account email.
    $email = cms_normalize_account_email((string) ($claims['email'] ?? ''));
    // $name stores the Google display name.
    $name = trim((string) ($claims['name'] ?? ''));
    // $pictureUrl stores the optional Google profile picture URL.
    $pictureUrl = trim((string) ($claims['picture'] ?? ''));
    // $emailVerified stores whether Google reported the email as verified.
    $emailVerified = (($claims['email_verified'] ?? false) === true || (string) ($claims['email_verified'] ?? '') === 'true') ? 1 : 0;

    // $stmt stores the account upsert query.
    $stmt = db()->prepare('INSERT INTO user_google_accounts (user_id, google_sub, email, email_verified, name, picture_url, linked_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE google_sub = VALUES(google_sub), email = VALUES(email), email_verified = VALUES(email_verified), name = VALUES(name), picture_url = VALUES(picture_url), updated_at = VALUES(updated_at)');
    $stmt->execute([$userId, $subject, $email !== '' ? $email : null, $emailVerified, $name !== '' ? $name : null, $pictureUrl !== '' ? $pictureUrl : null, now_sql(), now_sql()]);
}

/**
 * Disconnect Google login from one user profile.
 */
function google_auth_disconnect_account(int $userId): void
{
    if (!google_auth_schema_ready()) {
        return;
    }

    // $stmt stores the unlink query.
    $stmt = db()->prepare('DELETE FROM user_google_accounts WHERE user_id = ?');
    $stmt->execute([$userId]);
}

/**
 * Record a successful Google login timestamp.
 */
function google_auth_touch_login(int $googleAccountId): void
{
    if (!google_auth_schema_ready()) {
        return;
    }

    // $stmt stores the login timestamp update.
    $stmt = db()->prepare('UPDATE user_google_accounts SET last_login_at = ?, updated_at = ? WHERE id = ?');
    $stmt->execute([now_sql(), now_sql(), $googleAccountId]);
}
