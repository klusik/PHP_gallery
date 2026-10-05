<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/support/audit_route_probe_fixture.php
 * Module Type: Test Fixture Support
 * Purpose: Run route probes using an already-owned disposable application fixture.
 * Responsibilities: Verify fixture identity, seed authenticated context and isolate route child processes.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

declare(strict_types=1);

namespace GalleryWorkflow;

require_once __DIR__ . '/gallery_workflow_safety.php';
require_once __DIR__ . '/gallery_workflow_http.php';
require_once dirname(__DIR__, 2) . '/scripts/audit_lib.php';

use function PhpGallery\Audit\run_process;

/**
 * Run each registered route through an isolated child against the active owned workflow fixture.
 *
 * @param array<string,array<string,mixed>> $definitions Stable route probe definitions from the audit registry.
 * @param string $probeScript Absolute path to the route measurement child script.
 * @return array<int,array<string,mixed>> Valid-shaped child metrics in registry order.
 */
function audit_route_probe_fixture_run(array $definitions, string $probeScript): array
{
    $fixtureDirectory = (string) getenv('GALLERY_WORKFLOW_FIXTURE');
    $token = (string) getenv('GALLERY_WORKFLOW_TOKEN');
    check(getenv('GALLERY_WORKFLOW_ENABLE') === 'disposable-only', 'Disposable workflow opt-in is required.');
    $fixtureDirectory = validateFixture($fixtureDirectory, $token);
    check(is_file($probeScript) && !is_link($probeScript), 'Route probe child script is unavailable.');

    $endpoint = json_decode((string) @file_get_contents($fixtureDirectory . '/endpoint.json'), true);
    $origin = is_array($endpoint) ? (string) ($endpoint['url'] ?? '') : '';
    check(preg_match('~^http://127\.0\.0\.1:[1-9][0-9]{3,4}$~D', $origin) === 1,
        'Disposable route probe endpoint identity is invalid.');
    $health = curl_init($origin . '/__workflow_' . $token . '/health');
    check($health instanceof \CurlHandle, 'Disposable route probe health request could not start.');
    curl_setopt_array($health, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_PROXY => '']);
    $healthIdentity = curl_exec($health);
    $healthStatus = (int) curl_getinfo($health, CURLINFO_RESPONSE_CODE);
    unset($health);
    check(is_string($healthIdentity) && hash_equals($token, $healthIdentity) && $healthStatus === 200,
        'Disposable route probe endpoint ownership check failed.');

    $pdo = fixtureDatabase($fixtureDirectory, $token);
    $gallery = $pdo->query("SELECT id, folder_path, url_path FROM galleries WHERE folder_path = 'seed' LIMIT 1")->fetch(\PDO::FETCH_ASSOC);
    check(is_array($gallery), 'Disposable public gallery identity is unavailable.');
    $imageStatement = $pdo->prepare('SELECT url_slug FROM images WHERE gallery_id = ? AND filename = ? LIMIT 1');
    $imageStatement->execute([(int) $gallery['id'], 'sample-1.jpg']);
    $image = $imageStatement->fetch(\PDO::FETCH_ASSOC);
    check(is_array($image) && preg_match('/^[A-Za-z0-9_-]+$/D', (string) ($image['url_slug'] ?? '')) === 1,
        'Disposable public image identity is unavailable.');
    $galleryPath = trim((string) ($gallery['url_path'] ?? ''), '/');
    if ($galleryPath === '') {
        $galleryPath = trim((string) $gallery['folder_path'], '/');
    }
    check(preg_match('~^[A-Za-z0-9_-]+(?:/[A-Za-z0-9_-]+)*$~D', $galleryPath) === 1,
        'Disposable public gallery path is invalid.');

    $contextPath = $fixtureDirectory . '/route-probe-context.json';
    $sessionPath = $fixtureDirectory . '/route-probe-session-id';
    $context = ['gallery_path' => $galleryPath, 'image_slug' => (string) $image['url_slug']];
    check(file_put_contents($contextPath, json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), LOCK_EX) !== false,
        'Could not prepare the disposable route context.');
    @chmod($contextPath, 0600);

    try {
        $seed = json_decode((string) file_get_contents($fixtureDirectory . '/seed.json'), true, 512, JSON_THROW_ON_ERROR);
        check(is_array($seed), 'Disposable administrator seed is unavailable.');
        $client = new Http($origin);
        $loginToken = $client->token('/index.php?page=admin_login');
        $login = $client->request('/index.php?page=admin_login', [
            'csrf_token' => $loginToken,
            'identifier' => (string) ($seed['username'] ?? ''),
            'password' => (string) ($seed['password'] ?? ''),
        ]);
        check($login['status'] === 302, 'Disposable administrator login did not complete.');
        $sessionName = 'workflow_' . $token;
        $sessionId = $client->cookieValue($sessionName);
        check(is_string($sessionId) && preg_match('/^[A-Za-z0-9,-]{16,128}$/D', $sessionId) === 1,
            'Disposable administrator session cookie is unavailable.');
        check(file_put_contents($sessionPath, $sessionId, LOCK_EX) !== false,
            'Could not prepare the private disposable session reference.');
        @chmod($sessionPath, 0600);

        $measurements = [];
        $projectRoot = dirname(__DIR__, 2);
        foreach ($definitions as $probeId => $_definition) {
            $process = run_process([PHP_BINARY, $probeScript, (string) $probeId, $fixtureDirectory, $token], $projectRoot, 90);
            $metric = json_decode(trim((string) $process['stdout']), true);
            check(!$process['timed_out'] && $process['exit_code'] === 0 && is_array($metric)
                && ($metric['probe'] ?? null) === $probeId,
                'Isolated route measurement failed: ' . (string) $probeId);
            $measurements[] = $metric;
        }
        return $measurements;
    } finally {
        if (is_file($contextPath) && !is_link($contextPath)) {
            unlink($contextPath);
        }
        if (is_file($sessionPath) && !is_link($sessionPath)) {
            unlink($sessionPath);
        }
    }
}
