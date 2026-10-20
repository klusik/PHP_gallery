<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: .github/scripts/patch_notes_handoff.php
 * Module Type: Release Notes Handoff
 * Purpose: Route costly or unavailable Copilot work to a resumable external AI bundle.
 * Responsibilities:
 *   - Retain the complete prompt, template and source diff before provider work
 *   - Limit automatic Copilot work by source size and changed-file count
 *   - Bind pasted editorial responses to the exact repository, source and prompt
 *   - Keep credentials and provider diagnostics outside the handoff artifact
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

require_once __DIR__ . '/patch_notes_ai.php';
require_once __DIR__ . '/patch_notes_ai_chunks.php';

/**
 * Cap automatic work at one existing evidence slice plus final synthesis.
 * @var int Units: bytes. Scope: automatic release-note routing.
 * Consumers: patch_notes_handoff_route().
 * Rationale: 100000 matches one existing slice; larger diffs need external prose.
 */
const PATCH_NOTES_HANDOFF_DIFF_BYTES = PATCH_NOTES_AI_DIFF_SLICE_BYTES;

/**
 * Bound broad changes even when their aggregate text diff is small.
 * @var int Units: files. Scope: automatic release-note routing.
 * Consumers: patch_notes_handoff_route().
 * Rationale: 20 source paths keep automatic editorial work focused and predictable.
 */
const PATCH_NOTES_HANDOFF_FILES = 20;

/**
 * Bound complete automatic context, including commit metadata and the template.
 * @var int Units: bytes. Scope: automatic release-note routing.
 * Consumers: patch_notes_handoff_route().
 * Rationale: 180000 uses the existing final-context ceiling before any provider call.
 */
const PATCH_NOTES_HANDOFF_PROMPT_BYTES = PATCH_NOTES_AI_FINAL_PROMPT_BYTES;

/**
 * Reserve dispatch payload room for transport and workflow input names.
 * @var int Units: UTF-8 bytes. Scope: pasted external response envelopes.
 * Consumers: patch_notes_handoff_response() and the external prompt.
 * Rationale: 60000 bytes stay below GitHub's 65535-character total inputs limit.
 */
const PATCH_NOTES_HANDOFF_RESPONSE_BYTES = 60000;

/**
 * Select an automatic or external route without contacting any AI provider.
 *
 * @param string $prompt Complete canonical evidence prompt.
 * @param bool $available Whether the workflow has configured Copilot credentials.
 * @param bool $external Whether the maintainer explicitly requested external AI.
 * @return array{mode:string,reason:string,diff_bytes:int,source_files:int,prompt_bytes:int} Route and factual source metrics.
 */
function patch_notes_handoff_route(
    string $prompt,
    bool $available,
    bool $external,
): array {
    $diff = patch_notes_chunks_extract($prompt)['diff'];
    $files = preg_match_all('/^diff --git /m', $diff);
    if ($files === false) {
        throw new RuntimeException('Unable to count changed source files.');
    }
    $large =
        strlen($diff) > PATCH_NOTES_HANDOFF_DIFF_BYTES
        || $files > PATCH_NOTES_HANDOFF_FILES
        || strlen($prompt) > PATCH_NOTES_HANDOFF_PROMPT_BYTES;
    $reason = $external
        ? 'maintainer-requested'
        : (
            $large
                ? 'large-change'
                : (!$available ? 'copilot-unconfigured' : 'small-change')
        );
    return [
        'mode' => $external || $large || !$available ? 'external' : 'copilot',
        'reason' => $reason,
        'diff_bytes' => strlen($diff),
        'source_files' => $files,
        'prompt_bytes' => strlen($prompt),
    ];
}

/**
 * Export complete evidence and a copy/paste response contract for any external AI.
 *
 * @param string $prompt Complete canonical prompt collected from immutable Git objects.
 * @param string $directory Runner-owned temporary output directory.
 * @param string $version Target version without the v_ prefix.
 * @param string $repository Exact owner/repository identity.
 * @param string $source Exact current origin/source commit in the prompt.
 * @return array{repository:string,version:string,release_branch:string,source_sha:string,prompt_sha256:string} Immutable response identity.
 */
function patch_notes_handoff_bundle(
    string $prompt,
    string $directory,
    string $version,
    string $repository,
    string $source,
): array {
    if (
        !\PhpGallery\Release\valid_version($version)
        || preg_match('/^[\w.-]+\/[\w.-]+$/D', $repository) !== 1
        || preg_match('/^[a-f0-9]{40}$/D', $source) !== 1
        || !str_contains($prompt, "Candidate source commit: {$source}\n")
    ) {
        throw new RuntimeException(
            'Invalid immutable release-note handoff identity.',
        );
    }
    $identity = [
        'repository' => $repository,
        'version' => $version,
        'release_branch' => 'release/v_' . $version,
        'source_sha' => $source,
        'prompt_sha256' => hash('sha256', $prompt),
    ];
    // 0700 confines newly created handoff files to the runner account.
    if (!is_dir($directory) && !mkdir($directory, 0700, true)) {
        throw new RuntimeException(
            'Unable to create owned external release-note directory.',
        );
    }
    $parts = patch_notes_chunks_extract($prompt);
    $contractEnd = strpos($parts['prefix'], 'SECURITY / EVIDENCE RULE:');
    if ($contractEnd === false) {
        throw new RuntimeException(
            'Release-note handoff lacks the trusted evidence boundary.',
        );
    }
    $template = file_get_contents(
        dirname(__DIR__, 2) . '/PATCH_NOTES_TEMPLATE.md',
    );
    if (!is_string($template) || $template === '') {
        throw new RuntimeException(
            'Patch-note handoff template is unavailable.',
        );
    }
    $envelope = json_encode(
        $identity
            + [
                'notes_markdown' => 'REPLACE WITH THE COMPLETE MARKDOWN SECTION',
            ],
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
    );
    // Change only the trusted output contract; preserve every quoted evidence byte.
    $externalPrompt =
        str_replace(
            [
                '- Output Markdown only.',
                '- The first line must be exactly:',
                '- Output exactly one "## Version" section and nothing before or after it.',
            ],
            [
                '- Output one compact JSON object on a single line, using the RESPONSE ENVELOPE below.',
                '- The first line of notes_markdown must be exactly:',
                '- The notes_markdown value must contain exactly one "## Version" section.',
            ],
            substr($parts['prefix'], 0, $contractEnd),
        )
        . substr($parts['prefix'], $contractEnd)
        . "EVIDENCE - TEXT DIFF:\n<<<DIFF\n"
        . $parts['diff']
        . "\nDIFF\n\n"
        . "RESPONSE ENVELOPE - TRUSTED TRANSPORT CONTRACT:\n"
        . "Return this JSON shape, preserving all identity values exactly. Replace only notes_markdown.\n"
        . "Encode Markdown newlines as JSON \\n escapes; do not add code fences or explanatory text.\n"
        . 'Keep the entire UTF-8 JSON response at or below '
        . PATCH_NOTES_HANDOFF_RESPONSE_BYTES
        . " bytes.\n"
        . $envelope
        . "\n";
    patch_notes_chunks_write($directory . '/prompt.txt', $externalPrompt);
    patch_notes_chunks_write($directory . '/canonical-prompt.txt', $prompt);
    patch_notes_chunks_write($directory . '/changes.diff', $parts['diff']);
    patch_notes_chunks_write(
        $directory . '/PATCH_NOTES_TEMPLATE.md',
        $template,
    );
    patch_notes_chunks_write($directory . '/manifest.json', json_encode(
        $identity + ['diff_sha256' => hash('sha256', $parts['diff'])],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
    )
        . "\n");
    patch_notes_chunks_write(
        $directory . '/README.md',
        "# Continue release-note assembly\n\n"
            . "1. Give prompt.txt to your chosen AI. It includes the template and complete source diff.\n"
            . "2. Review its single-line JSON response; preserve the identity fields.\n"
            . "3. Open Release qualification > Run workflow, select {$identity['release_branch']},\n"
            . "   paste the JSON into external_notes and run. Leave notes_mode as auto.\n\n"
            . "If the Run workflow button is not registered on main yet, save the JSON as response.json\n"
            . "and run this command on one line:\n\n"
            . "`gh workflow run release-qualification.yml --ref {$identity['release_branch']} --field external_notes=@response.json`\n\n"
            . "The next run rebuilds and verifies the exact prompt before applying notes.\n"
            . "Changed sources need a fresh bundle. Existing completed notes remain authoritative.\n"
            . "No runner waits for input and no PR, qualification, tag or release is produced while notes are pending.\n"
            . "changes.diff excludes generated release files, PDFs and installer outputs as in the canonical evidence collector.\n",
    );
    return $identity;
}

/**
 * Accept one data-only external response bound to the freshly rebuilt evidence.
 *
 * @param string $response Pasted single-line JSON envelope, optionally surrounded by whitespace.
 * @param array{repository:string,version:string,release_branch:string,source_sha:string,prompt_sha256:string} $identity Current immutable evidence identity.
 * @return string Validated target-version Markdown with one trailing newline.
 */
function patch_notes_handoff_response(string $response, array $identity): string
{
    if (strlen($response) > PATCH_NOTES_HANDOFF_RESPONSE_BYTES) {
        throw new RuntimeException(
            'External release-note response exceeds the dispatch payload budget.',
        );
    }
    $decoded = json_decode($response, true, flags: JSON_THROW_ON_ERROR);
    if (!is_array($decoded) || count($decoded) !== (count($identity) + 1)) {
        throw new RuntimeException(
            'External release-note response has an invalid envelope.',
        );
    }
    foreach ($identity as $key => $value) {
        if (($decoded[$key] ?? null) !== $value) {
            throw new RuntimeException(
                'External release-note response differs from current '
                    . $key
                    . '.',
            );
        }
    }
    if (!is_string($decoded['notes_markdown'] ?? null)) {
        throw new RuntimeException(
            'External release-note response lacks Markdown prose.',
        );
    }
    return patch_notes_ai_validate_response(
        $identity['version'],
        $decoded['notes_markdown'],
    );
}

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) !== __FILE__) {
    return;
}

try {
    [$script, $version, $repository, $promptPath, $directory] = array_pad(
        $argv,
        5,
        '',
    );
    $prompt = file_get_contents($promptPath);
    if (!is_string($prompt)) {
        throw new RuntimeException(
            'Complete release-note prompt is unavailable.',
        );
    }
    $source = trim(patch_notes_ai_git(
        ['git', 'rev-parse', 'HEAD^{commit}'],
        null,
    ));
    $identity = patch_notes_handoff_bundle(
        $prompt,
        $directory,
        $version,
        $repository,
        $source,
    );
    $mode = getenv('NOTES_MODE') ?: 'auto';
    if (!in_array($mode, ['auto', 'external'], true)) {
        throw new RuntimeException('Unsupported release-note provider mode.');
    }
    $route = patch_notes_handoff_route(
        $prompt,
        getenv('COPILOT_AVAILABLE') === 'true',
        $mode === 'external',
    );
    $response = getenv('EXTERNAL_NOTES') ?: '';
    if ($response !== '') {
        patch_notes_chunks_write($directory
            . '/accepted-notes.md', patch_notes_handoff_response(
            $response,
            $identity,
        ));
        $route['mode'] = 'supplied';
        $route['reason'] = 'verified-external-response';
    }
    $output = getenv('GITHUB_OUTPUT');
    if (!is_string($output) || $output === '') {
        throw new RuntimeException('Workflow output path is unavailable.');
    }
    foreach ($route as $key => $value) {
        file_put_contents($output, $key . '=' . $value . "\n", FILE_APPEND);
    }
    fwrite(STDOUT, json_encode($route, JSON_THROW_ON_ERROR) . "\n");
} catch (Throwable $exception) {
    fwrite(
        STDERR,
        'BLOCKED external release-note handoff: '
            . $exception->getMessage()
            . "\n",
    );
    exit(1);
}
