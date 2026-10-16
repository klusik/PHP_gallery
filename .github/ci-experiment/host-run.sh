#!/usr/bin/env bash
# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: .github/ci-experiment/host-run.sh
# Module Type: Archived CI Experiment Shell Tool
# Purpose: Measure OCI pulls and start the exact immutable audit from the GitHub host.
# Responsibilities:
#   - Verify exact source and comparison identities before the experiment.
#   - Capture cold image transfers and run the selected central audit owner.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
set -euo pipefail
role="${1:?expected audit role}"
source_dir="${GITHUB_WORKSPACE:?}/source"
control_dir="${GITHUB_WORKSPACE}/control/.github/ci-experiment"
out_dir="${RUNNER_TEMP:?}/prepared-experiment"
mkdir -p "${out_dir}"
test "$(git -C "${source_dir}" rev-parse HEAD)" = "${TESTED_SHA:?}"
test "$(git -C "${source_dir}" rev-parse 'HEAD^{tree}')" = "${TESTED_TREE:?}"
git -C "${source_dir}" cat-file -e "${BASE_SHA:?}^{commit}"
git -C "${source_dir}" merge-base --is-ancestor "${BASE_SHA}" "${TESTED_SHA}"
test "$(git -C "${source_dir}" status --porcelain)" = ""
preexisting=false
if docker image inspect "${ENV_IMAGE:?}" >/dev/null 2>&1; then preexisting=true; fi
start="$(date +%s%N)"
docker pull "${ENV_IMAGE}" 2>&1 | tee "${out_dir}/pull-${role}.log"
end="$(date +%s%N)"
jq -n --arg role "${role}" --arg image "${ENV_IMAGE}" \
  --argjson start "${start}" --argjson end "${end}" \
  --argjson preexisting "${preexisting}" \
  '{role:$role,image:$image,pull_start_ns:$start,pull_end_ns:$end,
    elapsed_s:(($end-$start)/1000000000),preexisting_local_image:$preexisting}' \
  > "${out_dir}/pull-${role}.json"
vars=(GITHUB_ACTIONS GITHUB_REPOSITORY GITHUB_JOB GITHUB_RUN_ID GALLERY_WORKFLOW_ENABLE GALLERY_WORKFLOW_CI_ROOT_PASSWORD
 GALLERY_WORKFLOW_DB_HOST GALLERY_WORKFLOW_DB_PORT GALLERY_WORKFLOW_DB_USER
 GALLERY_WORKFLOW_REQUIRED GALLERY_WORKFLOW_BROWSER GALLERY_WORKFLOW_BROWSER_REQUIRED
 GALLERY_RUNTIME_NORMALIZATION GALLERY_SESSION_REQUIRED EXPERIMENT_DATABASE
 PHP_GALLERY_AUDIT_CANDIDATE PHP_GALLERY_AUDIT_OWNER PHP_GALLERY_AUDIT_WORKERS
 PHP_GALLERY_AUDIT_SHARD_INDEX PHP_GALLERY_AUDIT_SHARD_TOTAL
 PHP_GALLERY_SOURCE_OWNER PHP_GALLERY_PORTABLE_OWNER PHP_GALLERY_PACKAGING_OWNER
 PHP_GALLERY_PACKAGING_CANDIDATE PHP_GALLERY_SOURCE_BASE
 PHP_GALLERY_BROWSER PHP_GALLERY_BROWSER_REQUIRED CI_PHP_VERSION CI_PHP_INTL)
args=()
for name in "${vars[@]}"; do
    if [[ -v "${name}" ]]; then args+=(-e "${name}"); fi
done
mount="${source_dir}:/work/source"
if [[ "${role}" == manuals ]]; then mount="${mount}:ro"; fi
security_args=()
if [[ "$role" == browser || "${GALLERY_WORKFLOW_BROWSER_REQUIRED:-0}" == 1 ]]; then
    test -f "$control_dir/seccomp-chromium.json"
    security_args+=(--security-opt "seccomp=$control_dir/seccomp-chromium.json" --shm-size 1g)
    printf 'Chromium namespace sandbox permitted through test-only seccomp profile\n'
fi
start="$(date +%s%N)"
set +e
docker run --rm --init "${security_args[@]}" --network host --user "$(id -u):$(id -g)" \
    -v "${mount}" -v "${control_dir}:/ci:ro" \
    -v "${out_dir}:/ci-output" -w /work/source \
    "${args[@]}" \
    "${ENV_IMAGE}" bash /ci/runner.sh "${role}"
status=$?
set -e
end="$(date +%s%N)"
jq -n --arg role "${role}" --argjson start "${start}" \
  --argjson end "${end}" --argjson status "${status}" \
  '{role:$role,container_start_ns:$start,container_end_ns:$end,
    elapsed_s:(($end-$start)/1000000000),exit_code:$status}' \
  > "${out_dir}/audit-${role}.json"
exit "${status}"
