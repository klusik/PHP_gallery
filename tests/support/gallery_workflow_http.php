<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/support/gallery_workflow_http.php
 * Module Type: Test Fixture
 * Purpose: Provide cookie-isolated workflow HTTP assertions.
 * Responsibilities:
 *   - Compare responses with persistence outcomes in disposable application state.
 * Author: Rudolf Klusal
 * Cookie-isolated HTTP and persistence assertions for disposable workflows.
 */
declare(strict_types=1);

namespace GalleryWorkflow;

require_once __DIR__ . '/gallery_workflow_safety.php';

/** Real loopback HTTP with cookie isolation and no automatic redirects to another origin. */
final class Http
{
    private \CurlHandle $handle;

    /** Allocate an in-memory cookie jar scoped to a literal loopback origin. */
    public function __construct(private string $origin)
    {
        check(preg_match('~^http://127\.0\.0\.1:[1-9][0-9]{3,4}$~D', $origin) === 1, 'Invalid isolated HTTP origin.');
        $this->handle = curl_init();
        curl_setopt_array($this->handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => '',
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 45,
            CURLOPT_PROXY => '', CURLOPT_PROTOCOLS => CURLPROTO_HTTP]);
    }

    /**
     * Send one real application request, preserving cookies, status, headers and body.
     *
     * @param string $route Root-relative front-controller route, supported clean public gallery asset route, or clean Home/Gallery document route; an absolute URL is accepted only for a same-origin loopback redirect to one of those routes.
     * @param ?array<string,string|int|float|bool|null|\CURLFile|list<string|int|float|bool|null>> $fields Form fields keyed by input name; values are scalar values, top-level upload handles, or one-dimensional repeated scalar values.
     * @param bool $json Whether to request the JSON transport envelope.
     * @param string $method GET or HEAD; form submissions always use POST.
     * @param ?string $referer Optional absolute HTTP(S) referrer used by request-context integration tests.
     * @return array{status:int,body:string,headers:array<string,string>,json:scalar|array<array-key,mixed>|null} Real HTTP result; json is the associative decoded JSON tree with nested arrays recursively containing JSON scalars, arrays or null; null represents valid JSON null or failed/non-JSON decoding.
     */
    public function request(string $route, ?array $fields = null, bool $json = false, string $method = 'GET', ?string $referer = null): array
    {
        $routeParts = parse_url($route);
        $originParts = parse_url($this->origin);
        $absoluteRoute = is_array($routeParts) && isset($routeParts['scheme'], $routeParts['host']);
        $path = is_array($routeParts) ? ($routeParts['path'] ?? null) : null;
        $query = is_array($routeParts) ? ($routeParts['query'] ?? null) : null;
        $sameOriginAbsolute = $absoluteRoute && is_array($originParts)
            && strtolower((string) $routeParts['scheme']) === strtolower((string) $originParts['scheme'])
            && strtolower((string) $routeParts['host']) === strtolower((string) $originParts['host'])
            && (int) ($routeParts['port'] ?? 80) === (int) ($originParts['port'] ?? 80)
            && !isset($routeParts['user']) && !isset($routeParts['pass']);
        $relativeRoute = !$absoluteRoute && str_starts_with($route, '/') && !str_starts_with($route, '//');
        $safeSegmentPattern = '(?:[A-Za-z0-9._\~-]|%[A-Fa-f0-9]{2})+';
        $pathSegmentsSafe = is_string($path);
        if ($pathSegmentsSafe) {
            foreach (explode('/', trim($path, '/')) as $segment) {
                if ($segment === '') {
                    continue;
                }
                $decodedSegment = rawurldecode($segment);
                if (preg_match('~^' . $safeSegmentPattern . '$~D', $segment) !== 1
                    || in_array($decodedSegment, ['.', '..'], true)
                    || str_contains($decodedSegment, '/') || str_contains($decodedSegment, '\\')
                    || preg_match('/[\x00-\x1F\x7F]/', $decodedSegment) === 1) {
                    $pathSegmentsSafe = false;
                    break;
                }
            }
        }
        $frontControllerRoute = is_string($path) && $path === '/index.php'
            && is_string($query) && $query !== '';
        $cleanGalleryAssetRoute = is_string($path)
            && preg_match('~^/(?:(?:[A-Za-z0-9._\~-]|%[A-Fa-f0-9]{2})+/)*gallery/(?:(?:[A-Za-z0-9._\~-]|%[A-Fa-f0-9]{2})+/)*(?:media|thumb-[1-9][0-9]*\.(?:jpg|webp))$~iD', $path) === 1;
        $cleanHomeDocumentRoute = $path === '/';
        $cleanGalleryDocumentRoute = is_string($path)
            && preg_match('~^/gallery/(?:' . $safeSegmentPattern . '/)+$~D', $path) === 1;
        $cleanDocumentRoute = $cleanHomeDocumentRoute || $cleanGalleryDocumentRoute;
        $cleanDocumentQueryAllowed = true;
        if ($cleanDocumentRoute && is_string($query) && $query !== '') {
            $documentQuery = [];
            parse_str($query, $documentQuery);
            $allowedDocumentQuery = ['preview' => 'visual', 'view_as' => 'anonymous', 'visual_notice' => 'anonymous_fallback'];
            $cleanDocumentQueryAllowed = $documentQuery !== []
                && count($documentQuery) === count(explode('&', $query));
            foreach ($documentQuery as $key => $value) {
                if (!is_string($key) || !isset($allowedDocumentQuery[$key])
                    || $value !== $allowedDocumentQuery[$key]) {
                    $cleanDocumentQueryAllowed = false;
                    break;
                }
            }
        }
        $routePathAllowed = $frontControllerRoute || $cleanGalleryAssetRoute
            || ($cleanDocumentRoute && $cleanDocumentQueryAllowed);
        check(($relativeRoute || $sameOriginAbsolute) && $routePathAllowed && $pathSegmentsSafe
            && !str_contains($route, '#') && preg_match('/[\r\n\x00]/', $route) !== 1,
            'Workflow HTTP calls must target a supported same-origin application route.');
        if ($referer !== null) {
            $refererParts = parse_url($referer);
            check(preg_match('/[\r\n\x00]/', $referer) !== 1 && is_array($refererParts)
                && in_array(strtolower((string) ($refererParts['scheme'] ?? '')), ['http', 'https'], true)
                && (string) ($refererParts['host'] ?? '') !== ''
                && !array_key_exists('user', $refererParts) && !array_key_exists('pass', $refererParts)
                && !array_key_exists('fragment', $refererParts),
                'Workflow HTTP Referer must be an absolute HTTP(S) URI without credentials or fragments.');
        }
        check(in_array($method, ['GET', 'HEAD'], true) && ($method !== 'HEAD' || $fields === null),
            'Workflow HTTP method must be GET or HEAD without HEAD form fields.');
        $requestHeaders = [];
        $responseHeaders = [];
        if ($referer !== null) {
            $requestHeaders[] = 'Referer: ' . $referer;
        }
        $requestHeaders = array_merge($requestHeaders, $json ? ['Accept: application/json', 'X-Requested-With: XMLHttpRequest'] : []);
        $requestUrl = $absoluteRoute ? $route : $this->origin . $route;
        curl_setopt_array($this->handle, [CURLOPT_URL => $requestUrl,
            CURLOPT_HTTPHEADER => $requestHeaders,
            CURLOPT_HEADERFUNCTION => static function (\CurlHandle $handle, string $line) use (&$responseHeaders): int {
                if (str_contains($line, ':')) {
                    [$key, $value] = explode(':', $line, 2);
                    $responseHeaders[strtolower(trim($key))] = trim($value);
                }
                return strlen($line);
            }]);
        if ($fields !== null) {
            $multipart = count(array_filter($fields, static fn ($value): bool => $value instanceof \CURLFile)) > 0;
            curl_setopt($this->handle, CURLOPT_POST, true);
            curl_setopt($this->handle, CURLOPT_POSTFIELDS, $multipart ? $fields : http_build_query($fields));
        } elseif ($method === 'HEAD') {
            curl_setopt($this->handle, CURLOPT_NOBODY, true);
        } else {
            curl_setopt($this->handle, CURLOPT_HTTPGET, true);
        }
        try {
            $body = curl_exec($this->handle);
            check(is_string($body), 'Isolated HTTP transport failed.');
            return ['status' => (int) curl_getinfo($this->handle, CURLINFO_RESPONSE_CODE), 'body' => $body,
                'headers' => $responseHeaders, 'json' => json_decode($body, true)];
        } finally {
            if ($method === 'HEAD') {
                // Restore reusable handle semantics for the next request.
                curl_setopt($this->handle, CURLOPT_NOBODY, false);
            }
        }
    }

    /**
     * Read a fresh replay-safe operation key from a real rendered create/upload form.
     *
     * @param string $route Authenticated form route under the disposable origin.
     * @return string Explicit key for one intended operation; reuse only for its exact retry.
     */
    public function operationKey(string $route): string
    {
        $response = $this->request($route);
        check($response['status'] === 200, 'Operation-key form must load successfully.');
        $document = new \DOMDocument();
        @$document->loadHTML($response['body']);
        $field = (new \DOMXPath($document))->query('//input[@name="operation_key"]')->item(0);
        check($field instanceof \DOMElement && preg_match('/^[a-f0-9]{64}$/D', $field->getAttribute('value')) === 1,
            'Operation key missing from actual form.');
        return $field->getAttribute('value');
    }

    /** Parse the CSRF token from a real rendered form. */
    public function token(string $route): string
    {
        $response = $this->request($route);
        check($response['status'] === 200, 'CSRF form must load successfully.');
        $document = new \DOMDocument();
        @$document->loadHTML($response['body']);
        $field = (new \DOMXPath($document))->query('//input[@name="csrf_token"]')->item(0);
        check($field instanceof \DOMElement && $field->getAttribute('value') !== '', 'CSRF token missing from actual form.');
        return $field->getAttribute('value');
    }

    /** Authenticate through the real login route and return the current form CSRF token. */
    public function login(array $seed): string
    {
        $token = $this->token('/index.php?page=admin_login');
        $response = $this->request('/index.php?page=admin_login', ['csrf_token' => $token,
            'identifier' => $seed['username'], 'password' => $seed['password']]);
        check($response['status'] === 302, 'Real administrator login failed.');
        return $this->token('/index.php?page=admin_new_gallery&panel=1');
    }

    /** Return one cookie value from this loopback-only fixture client.
     * @param string $name Exact cookie name captured from the isolated server.
     * @return ?string Cookie value, or null when the fixture did not issue it.
     */
    public function cookieValue(string $name): ?string
    {
        foreach (curl_getinfo($this->handle, CURLINFO_COOKIELIST) ?: [] as $line) {
            $fields = explode("\t", $line);
            if (count($fields) >= 7 && $fields[5] === $name) {
                return $fields[6];
            }
        }
        return null;
    }
}

/** Verify the completion envelope returned by the application, without printing its contents. */
function envelope(array $response, string $label): array
{
    $result = $response['json'];
    check($response['status'] === 200 && is_array($result) && ($result['ok'] ?? false) === true, $label . ' HTTP success');
    check(is_array($result['mutation'] ?? null) && is_array($result['contexts'] ?? null)
        && is_array($result['fallback'] ?? null) && isset($result['message']), $label . ' completion envelope');
    return $result;
}

/** Read one durable fixture row with bound parameters. */
function row(\PDO $pdo, string $sql, array $parameters = []): array
{
    $statement = $pdo->prepare($sql);
    $statement->execute($parameters);
    return $statement->fetch(\PDO::FETCH_ASSOC) ?: [];
}

/**
 * Count rows from one fixed allowlist of disposable workflow tables.
 * @param \PDO $pdo Connection to the owned workflow fixture database.
 * @param string $table Allowlisted galleries, images, Gallery Trash, or public widget table.
 * @return int Number of rows stored in the selected fixture table.
 */
function countRows(\PDO $pdo, string $table): int
{
    check(in_array($table, ['galleries', 'images', 'gallery_trash_entries', 'public_content_widgets'], true), 'Unexpected fixture table.');
    return (int) $pdo->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
}
