<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/support/cache_lifecycle_chain_http_fixture.php
 * Module Type: Hosted HTTP Regression Test
 * Purpose: Prove real server continuation finds and deletes expired data after one public visit.
 * Responsibilities: Own fixed-port process/storage, observe actual authenticated worker arrivals and require safe partial coverage, opt-out and bounded summary logging.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);
namespace Gallery\Services {
    /**
     * Read only the parent's owned opt-out marker and absent recovery preference.
     * @param string $key Requested setting identity.
     * @param string|null $default Missing-value fallback.
     * @return string|null Fixture OFF, empty recovery path or supplied fallback.
     */
    function app_setting(string $key, ?string $default = null): ?string
    {
        if ($key === 'application_update_beta_backup_path') return '';
        return $key === 'feature_flag.automatic_cache_cleanup.enabled' && is_file(CACHE_LIFECYCLE_TEST_ROOT . '/off') ? '0' : $default;
    }
}
namespace {
    /**
     * Pace bounded readiness, autonomous-progress and opt-out observations.
     * Type: int. Units: microseconds. Scope: owned server probes only.
     * Consumers: startup and private-file observation loops. Rationale: twenty-five milliseconds permits bounded responsiveness without busy polling or changing production cadence.
     */
    const CACHE_CHAIN_HTTP_POLL_US = 25000;

    /**
     * Require one actual autonomous HTTP postcondition.
     * @param bool $condition Required observation.
     * @param string $message Failure explanation.
     * @return void Throws when the real worker did not satisfy the contract.
     */
    function chain_http_assert(bool $condition, string $message): void { if (!$condition) throw new \RuntimeException($message); }

    /**
     * Read complete persisted reclamation receipts while excluding an in-progress log append.
     * @param string $path Owned JSONL sink written by the real Admin log model under an exclusive lock.
     * @return list<array{key:string,context:array{removed_entries:int,bytes_removed:int,from_at:int,to_at:int}}> Confirmed safe aggregate receipts, or an empty list before any reclamation is logged.
     */
    function chain_http_reclamation_receipts(string $path): array
    {
        if (!is_file($path)) return [];
        $stream = fopen($path, 'rb');
        chain_http_assert(is_resource($stream), 'Owned Admin log receipt sink is unreadable.');
        try {
            chain_http_assert(flock($stream, LOCK_SH), 'Could not snapshot the owned Admin log receipt sink.');
            $contents = stream_get_contents($stream);
            chain_http_assert(is_string($contents), 'Owned Admin log receipt snapshot is incomplete.');
        } finally {
            flock($stream, LOCK_UN);
            fclose($stream);
        }
        $receipts = [];
        foreach (explode("\n", rtrim($contents, "\n")) as $line) {
            if ($line === '') continue;
            $event = json_decode($line, true, 16, JSON_THROW_ON_ERROR);
            chain_http_assert(is_array($event), 'Owned Admin log receipt is not a JSON object.');
            if (($event['key'] ?? '') !== 'cache.automatic_reclaimed') continue;
            $context = $event['context'] ?? null;
            chain_http_assert(is_array($context)
                && array_keys($context) === ['removed_entries','bytes_removed','from_at','to_at'],
                'Actual removal receipt contains missing or unsafe context fields.');
            foreach ($context as $value) chain_http_assert(is_int($value), 'Actual removal receipt contains a non-integer aggregate.');
            chain_http_assert($context['removed_entries'] > 0 && $context['bytes_removed'] >= 0
                && $context['from_at'] > 0 && $context['to_at'] >= $context['from_at'],
                'Actual removal receipt contains invalid removal counters or timestamps.');
            $receipts[] = $event;
        }
        return $receipts;
    }
    if (getenv('CI') !== 'true') { echo "SKIP hosted-only autonomous HTTP fixture.\n"; exit(0); }
    $root = sys_get_temp_dir() . '/gallery-cache-chain-http-' . bin2hex(random_bytes(8));
    // Confine server workers and every deletion to this owned random directory.
    // Type: string. Units: absolute path. Scope: this parent and its disposable child.
    // Consumers: canonical cache paths. Rationale: the serial HTTP owner supplies the fixed port; no live installation participates.
    define('CACHE_LIFECYCLE_TEST_ROOT', $root);
    mkdir($root . '/cache/unregistered-original', 0775, true);
    file_put_contents($root . '/.chain-http-owner', 'owned');
    file_put_contents($root . '/clock', (string) time());
    file_put_contents($root . '/cache/unregistered-original/payload', 'protected original');
    file_put_contents($root . '/cache/unregistered-original/.keep', 'pin');
    require_once __DIR__ . '/cache_registry_fixture.php';
    $GLOBALS['cache_lifecycle_test_database'] = cache_registry_fixture_database($root);
    $environment = getenv();
    $environment['CACHE_CHAIN_HTTP_ROOT'] = $root;
    // The real timer must have a spare worker while another request waits.
    // Type: string. Units: PHP worker count. Scope: this owned hosted server.
    // Consumers: PHP built-in server. Rationale: two workers exercise supported concurrency rather than relying on a single-worker virtual-time exception.
    $environment['PHP_CLI_SERVER_WORKERS'] = '2';
    $process = proc_open([PHP_BINARY,'-d','max_execution_time=30','-S','127.0.0.1:18812',
        dirname(__DIR__) . '/fixtures/cache_lifecycle_chain_router.php'],
        [0=>['pipe','r'],1=>['file',$root . '/server.log','a'],2=>['file',$root . '/server.log','a']], $pipes, $root, $environment);
    try {
        chain_http_assert(is_resource($process), 'Owned autonomous HTTP server did not start.');
        fclose($pipes[0]);
        $url = 'http://127.0.0.1:18812/';
        $context = stream_context_create(['http'=>['timeout'=>3,'ignore_errors'=>true,'header'=>"Host: untrusted.invalid\r\n"]]);
        $body = false;
        for ($attempt = 0; $attempt < 80 && $body === false; $attempt++) {
            $body = @file_get_contents($url, false, $context);
            if ($body === false) usleep(CACHE_CHAIN_HTTP_POLL_US);
        }
        chain_http_assert($body === '<main>One public visit</main>', 'Public kickoff output was replaced by worker output.');
        // Publish expired producer proof only after all payload bytes exist.
        $relative = 'map-browser-profile-Chain1';
        mkdir($root . '/cache/' . $relative);
        file_put_contents($root . '/cache/' . $relative . '/payload', str_repeat('safe', 1024));
        file_put_contents($root . '/cache/' . $relative . '/cache-owner.json', json_encode(['schema_version'=>1,
            'producer'=>'php_gallery_browser_fixture','directory'=>$relative,
            'created_at'=>time()-86401], JSON_THROW_ON_ERROR));
        // An unrelated dense directory must be fully measurable and remain protected.
        mkdir($root . '/cache/dense-unregistered');
        for ($index = 0; $index <= 4096; $index++) file_put_contents($root . '/cache/dense-unregistered/name-' . $index, '');
        require_once dirname(__DIR__, 2) . '/app/services/cache_lifecycle.php';
        $finished = false;
        $receipt = null;
        $receipts = [];
        for ($probe = 0; $probe < 240; $probe++) {
            clearstatcache();
            $status = \Gallery\Services\cache_lifecycle_status();
            $receipts = chain_http_reclamation_receipts($root . '/events.jsonl');
            foreach ($receipts as $candidate) {
                if ($candidate['context']['bytes_removed'] >= 4096) { $receipt = $candidate; break; }
            }
            // Registry commits and physical unlink precede the logger's receipt.
            // Earlier status events cannot stand in for confirmed reclamation;
            // retain the same bounded observation window until all three exist.
            if (!is_dir($root . '/cache/' . $relative) && !$status['partial'] && $status['totals']['unknown']['files'] >= 4097
                && $receipt !== null) { $finished = true; break; }
            usleep(CACHE_CHAIN_HTTP_POLL_US);
        }
        chain_http_assert($finished && $status['scheduler_source'] === 'cache_chain',
            'Autonomous HTTP requests did not reclaim eligible data and measure protected dense storage: '
            . json_encode(array_diff_key($status, ['policies'=>true,'totals'=>true,'sample'=>true]), JSON_THROW_ON_ERROR)
            . '\nConfirmed removal receipts: ' . json_encode($receipts, JSON_THROW_ON_ERROR)
            . '\nOwned server log: ' . file_get_contents($root . '/server.log'));
        chain_http_assert(file_get_contents($root . '/cache/unregistered-original/payload') === 'protected original'
            && is_file($root . '/cache/dense-unregistered/name-4096'), 'An autonomous cleaner deleted unregistered/pinned data.');
        $arrivals = file($root . '/arrivals.jsonl', FILE_IGNORE_NEW_LINES);
        chain_http_assert(count(array_filter($arrivals, static fn(string $kind): bool => $kind === 'public')) === 1
            && count(array_filter($arrivals, static fn(string $kind): bool => $kind === 'worker')) >= 2,
            'The server chain needed another public visit or never recorded real worker requests.');
        chain_http_assert($receipt !== null && $receipt['key'] === 'cache.automatic_reclaimed'
            && $receipt['context']['bytes_removed'] >= 4096
            && array_keys($receipt['context']) === ['removed_entries','bytes_removed','from_at','to_at'], 'Actual removal lacked a safe aggregate Admin log receipt.');
        file_put_contents($root . '/off', '0');
        $stopped = false;
        for ($probe = 0; $probe < 80; $probe++) {
            $chain = \Gallery\Services\cache_lifecycle_read_json($root . '/cache/cache-lifecycle/chain.json');
            if (($chain['last_error'] ?? '') === 'automatic_cache_cleanup_disabled' && empty($chain['ticket'])) { $stopped = true; break; }
            usleep(CACHE_CHAIN_HTTP_POLL_US);
        }
        chain_http_assert($stopped, 'Explicit OFF did not stop subsequent autonomous HTTP dispatches.');
        echo "Single public kickoff; real authenticated server continuation, dense coverage, immediate reclamation, aggregate logs and OFF: PASS\n";
    } finally {
        file_put_contents($root . '/off', '0');
        if (is_resource($process)) {
            require_once dirname(__DIR__, 2) . '/scripts/audit_process.php';
            $server = proc_get_status($process);
            // Reuse the existing owned-process tree terminator; stopping only
            // the parent would leave forked workers holding the serial port.
            \PhpGallery\Audit\terminate_process(['process'=>$process,'pid'=>$server['pid'],'group'=>false]);
            proc_close($process);
        }
        require_once dirname(__DIR__, 2) . '/app/services/cache_lifecycle.php';
        unset($GLOBALS['cache_lifecycle_test_database']);
        for ($step = 0; $step < 100 && is_dir($root); $step++) \Gallery\Services\application_update_delete_tree_slice($root, 5000, 0.5);
    }
}
