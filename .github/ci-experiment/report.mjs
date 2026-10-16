#!/usr/bin/env node
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: .github/ci-experiment/report.mjs
 * Module Type: Archived CI Experiment Reporter
 * Purpose: Capture an exact experimental run's observable hosted timing and coverage.
 * Responsibilities:
 *   - Read immutable GitHub job and step timestamps with the read-only Actions API.
 *   - Retain incomplete and failed jobs rather than selecting successful runs.
 *   - Write machine-readable JSON and CSV without claiming container-pull timing
 *     from timestamps that are only visible inside the separate host-run artifact.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import { mkdir, writeFile } from 'node:fs/promises';
import { resolve } from 'node:path';

const token = process.env.GITHUB_TOKEN ?? '';
const repository = process.env.GITHUB_REPOSITORY ?? '';
const runId = process.env.GITHUB_RUN_ID ?? '';
const outputDir = process.env.EXPERIMENT_REPORT_DIR ?? '';
const arm = process.env.EXPERIMENT_ARM ?? '';
const sample = process.env.EXPERIMENT_SAMPLE ?? '';
const expectedT = '4d373023b96554364e295fb76d931ff3535f7c5a';
const expectedB = 'e4abeb386354e7254a9083dbae5e16e8131ee314';
if (!token || !/^klusik\/PHP_gallery$/.test(repository) || !/^[0-9]+$/.test(runId)
    || !['A', 'B'].includes(arm) || !/^[1-9][0-9]*$/.test(sample) || !outputDir) {
    throw new Error('BLOCKED incomplete experiment reporter environment.');
}
const get = async path => {
    const response = await fetch('https://api.github.com/repos/' + repository + path, {
        headers: {
            Authorization: 'Bearer ' + token,
            Accept: 'application/vnd.github+json',
            'X-GitHub-Api-Version': '2022-11-28',
        },
    });
    if (!response.ok) {
        throw new Error('BLOCKED read-only GitHub Actions API ' + response.status + ' ' + path);
    }
    return response.json();
};
const run = await get('/actions/runs/' + runId);
if (run.head_branch !== 'feature/prepared-ci-environment') {
    throw new Error('BLOCKED unexpected measured branch.');
}
const jobs = [];
for (let page = 1; page <= 10; page++) {
    const result = await get('/actions/runs/' + runId + '/jobs?per_page=100&page=' + page);
    if (!Array.isArray(result.jobs)) throw new Error('BLOCKED missing jobs collection.');
    jobs.push(...result.jobs);
    if (result.jobs.length < 100) break;
    if (page === 10) throw new Error('BLOCKED excessive pagination.');
}
const duration = (start, end) => {
    if (!start || !end) return null;
    return Math.max(0, (Date.parse(end) - Date.parse(start)) / 1000);
};
const measuredJobs = jobs.map(job => ({
    job_id: job.id,
    name: job.name,
    runner_name: job.runner_name,
    runner_group_name: job.runner_group_name,
    status: job.status,
    conclusion: job.conclusion,
    created_at: job.created_at,
    started_at: job.started_at,
    completed_at: job.completed_at,
    created_to_start_seconds: duration(job.created_at, job.started_at),
    runner_seconds: duration(job.started_at, job.completed_at),
    steps: (job.steps ?? []).map(step => ({
        number: step.number,
        name: step.name,
        status: step.status,
        conclusion: step.conclusion,
        started_at: step.started_at,
        completed_at: step.completed_at,
        elapsed_seconds: duration(step.started_at, step.completed_at),
    })),
}));
const completedJobs = measuredJobs.filter(job => job.completed_at);
const lastCompletedAt = completedJobs
    .map(job => job.completed_at)
    .sort().at(-1) ?? null;
const elapsed = duration(run.created_at, lastCompletedAt);
const sumRunnerSeconds = measuredJobs
    .reduce((sum, job) => sum + (job.runner_seconds ?? 0), 0);
const result = {
    schema_version: 1,
    measurement_status: 'in_workflow_snapshot_before_final_artifact_upload',
    source_experiment_sha: run.head_sha,
    application_tested_sha: expectedT,
    comparison_base_sha: expectedB,
    run_id: Number(runId),
    run_attempt: run.run_attempt,
    run_url: run.html_url,
    arm,
    sample: Number(sample),
    run_created_at: run.created_at,
    observed_at: new Date().toISOString(),
    observed_critical_path_seconds: elapsed,
    observed_sum_runner_seconds: sumRunnerSeconds,
    job_count: measuredJobs.length,
    jobs: measuredJobs,
    caveats: [
        'created_to_start_seconds includes queue and runner provisioning, not a pure scheduler queue.',
        'host-run pull/audit JSON artifacts provide the external image transfer, startup and audit split.',
        'This in-workflow snapshot excludes its own completion and final artifact upload.',
        'Missing, incomplete, BLOCKED, skipped and failed mandatory owners do not constitute full PASS.',
    ],
};
const csv = value => {
    const text = value == null ? '' : String(value);
    return '"' + text.replaceAll('"', '""') + '"';
};
const rows = [[
    'run_id', 'arm', 'sample', 'job_id', 'name', 'conclusion',
    'created_to_start_seconds', 'runner_seconds', 'step_number',
    'step_name', 'step_conclusion', 'step_elapsed_seconds',
]];
for (const job of measuredJobs) {
    for (const step of job.steps) {
        rows.push([
            runId, arm, sample, job.job_id, job.name, job.conclusion,
            job.created_to_start_seconds, job.runner_seconds, step.number,
            step.name, step.conclusion, step.elapsed_seconds,
        ]);
    }
}
await mkdir(outputDir, { recursive: true });
await writeFile(resolve(outputDir, 'run.json'), JSON.stringify(result, null, 2) + '\n');
await writeFile(resolve(outputDir, 'steps.csv'),
    rows.map(row => row.map(csv).join(',')).join('\n') + '\n');
console.log('EXPERIMENT report:', JSON.stringify({
    run: runId, arm, sample,
    jobs: result.job_count,
    observed_elapsed_s: elapsed,
    observed_runner_s: sumRunnerSeconds,
}));
