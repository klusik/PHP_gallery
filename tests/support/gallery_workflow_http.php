<?php
/**
 * Project: PHP Gallery
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

    /** Send one real application request, preserving status, headers and body privately. */
    public function request(string $route, ?array $fields = null, bool $json = false): array
    {
        check(str_starts_with($route, '/index.php?'), 'Workflow HTTP calls must target the local application router.');
        $headers = [];
        curl_setopt_array($this->handle, [CURLOPT_URL => $this->origin . $route,
            CURLOPT_HTTPHEADER => $json ? ['Accept: application/json', 'X-Requested-With: XMLHttpRequest'] : [],
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$headers): int {
                if (str_contains($line, ':')) {
                    [$key, $value] = explode(':', $line, 2);
                    $headers[strtolower(trim($key))] = trim($value);
                }
                return strlen($line);
            }]);
        if ($fields !== null) {
            $multipart = count(array_filter($fields, static fn ($value): bool => $value instanceof \CURLFile)) > 0;
            curl_setopt($this->handle, CURLOPT_POST, true);
            curl_setopt($this->handle, CURLOPT_POSTFIELDS, $multipart ? $fields : http_build_query($fields));
        } else {
            curl_setopt($this->handle, CURLOPT_HTTPGET, true);
        }
        $body = curl_exec($this->handle);
        check(is_string($body), 'Isolated HTTP transport failed.');
        return ['status' => (int) curl_getinfo($this->handle, CURLINFO_RESPONSE_CODE), 'body' => $body,
            'headers' => $headers, 'json' => json_decode($body, true)];
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

/** Count a fixed allowlisted fixture table. */
function countRows(\PDO $pdo, string $table): int
{
    check(in_array($table, ['galleries', 'images', 'gallery_trash_entries'], true), 'Unexpected fixture table.');
    return (int) $pdo->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
}
