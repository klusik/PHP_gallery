#!/usr/bin/env bash
# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: .github/scripts/provision_tinytex.sh
# Module Type: Release Toolchain Provisioning
# Purpose: Install and verify checksum-locked TinyTeX with frozen TeX Live packages.
# Responsibilities:
#   - Verify bundle and frozen repository checksums before provisioning
#   - Bound downloads and package operations without live-repository fallbacks
#   - Validate cache provenance and required local manual resources
# Author: Rudolf Klusal
# No rolling repository, installer script, automatic self-update or latest fallback is used.
set -euo pipefail

mode="${1:?Expected install, provision or verify}"
lock=.github/texlive-lock.json
packages=.github/texlive-packages.txt
tex_root="${HOME}/.TinyTeX"
tex_bin="${tex_root}/bin/x86_64-linux"
fingerprint="$(sha256sum "${lock}" "${packages}" .github/scripts/provision_tinytex.sh | sha256sum | cut -d ' ' -f 1)"
mapfile -t settings < <(php -r '
    $lock = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
    foreach (["bundle_url", "bundle_sha256", "repository", "repository_metadata_sha256", "texlive_year", "infra_revision"] as $key) {
        if (!isset($lock[$key]) || !is_scalar($lock[$key])) {
            throw new RuntimeException("Invalid TinyTeX lock field: " . $key);
        }
        echo $lock[$key], PHP_EOL;
    }
' "${lock}")
[[ "${#settings[@]}" -eq 6 ]] || { echo "Invalid TinyTeX lock." >&2; exit 1; }
bundle_url="${settings[0]}"
bundle_sha="${settings[1]}"
repository="${settings[2]}"
metadata_sha="${settings[3]}"
texlive_year="${settings[4]}"
infra_revision="${settings[5]}"
export PATH="${tex_bin}:${PATH}"

case "${mode}" in
    install)
        [[ ! -e "${tex_root}" ]] || { echo "Refusing an unexpected partial TinyTeX installation." >&2; exit 1; }
        archive="${RUNNER_TEMP}/tinytex-locked.tar.gz"
        curl --fail --location --silent --show-error --connect-timeout 20 --max-time 180 \
            --retry 2 --retry-delay 2 --retry-max-time 240 "${bundle_url}" -o "${archive}"
        printf '%s  %s\n' "${bundle_sha}" "${archive}" | sha256sum --check --strict
        tar -xzf "${archive}" -C "${HOME}"
        [[ -x "${tex_bin}/tlmgr" ]] || { echo "Locked bundle has no expected x86_64-linux binaries." >&2; exit 1; }
        ;;
    provision)
        metadata="${RUNNER_TEMP}/texlive-locked.tlpdb"
        curl --fail --location --silent --show-error --connect-timeout 20 --max-time 180 \
            --retry 2 --retry-delay 2 --retry-max-time 240 \
            "${repository}/tlpkg/texlive.tlpdb" -o "${metadata}"
        printf '%s  %s\n' "${metadata_sha}" "${metadata}" | sha256sum --check --strict
        grep -Fxq "depend release/${texlive_year}" "${tex_root}/tlpkg/texlive.tlpdb"
        # The selected bundle already has the frozen repository's tlmgr revision.
        # Refuse mismatches rather than updating the manager from a live feed.
        installed_infra="$(awk -v RS='' '$0 ~ /^name texlive.infra\n/ { for (i=1;i<=NF;i++) if ($i=="revision") print $(i+1) }' "${tex_root}/tlpkg/texlive.tlpdb")"
        [[ "${installed_infra}" == "${infra_revision}" ]] || { echo "Locked tlmgr revision mismatch." >&2; exit 1; }
        "${tex_bin}/tlmgr" option repository "${repository}"
        # Align existing base packages and new styles to the same frozen snapshot.
        timeout --kill-after=15s 360 "${tex_bin}/tlmgr" --repository "${repository}" update --all
        mapfile -t required < <(grep -Ev '^[[:space:]]*(#|$)' "${packages}")
        [[ "${#required[@]}" -gt 0 ]] || { echo "Empty manual package manifest." >&2; exit 1; }
        timeout --kill-after=15s 360 "${tex_bin}/tlmgr" --repository "${repository}" install "${required[@]}"
        printf '%s\n' "${fingerprint}" > "${tex_root}/.php-gallery-provenance"
        ;;
    verify)
        [[ "$(cat "${tex_root}/.php-gallery-provenance")" == "${fingerprint}" ]] || { echo "Stale TinyTeX cache provenance." >&2; exit 1; }
        [[ -x "${tex_bin}/pdflatex" && -x "${tex_bin}/makeindex" ]] || { echo "TinyTeX binaries unavailable." >&2; exit 1; }
        # Cache hits check local package/resource inventory without networking.
        while IFS= read -r package; do
            [[ -z "${package}" || "${package}" == \#* ]] && continue
            grep -Fxq "name ${package}" "${tex_root}/tlpkg/texlive.tlpdb" || { echo "Missing locked package: ${package}" >&2; exit 1; }
        done < "${packages}"
        for resource in czech.ldf ngerman.ldf swedish.ldf lmodern.sty microtype.sty footmisc.sty titlesec.sty; do
            [[ -n "$("${tex_bin}/kpsewhich" "${resource}")" ]] || { echo "Missing manual resource: ${resource}" >&2; exit 1; }
        done
        "${tex_bin}/pdflatex" --version | head -n 1
        echo "${tex_bin}" >> "${GITHUB_PATH}"
        ;;
    *)
        echo "Unknown TinyTeX provisioning mode." >&2
        exit 2
        ;;
esac
