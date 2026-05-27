#!/usr/bin/env bash

# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
#
# File: scripts/deploy.sh
# Module Type: Deployment Script
#
# Purpose:
#   Automates deployment packaging or upload workflows for PHP Gallery on macOS/Linux.
#
# Responsibilities:
#   - Collect deployment inputs safely
#   - Prepare files for local or remote deployment
#   - Report deployment failures clearly
#   - Match the Windows deploy workflow without requiring PowerShell
#
# Author:
#   Rudolf Klusal
#
# Contact:
#   https://github.com/klusik
#
# License:
#   MIT License (see LICENSE file in repository)
#
# Notes:
#   - Keep comments and docstrings intact when modifying this file.
#   - Prefer small, readable changes over broad rewrites.
#
# Last Updated:
#   2026-05-12

set -euo pipefail

# Variable script_dir stores the folder containing this script.
script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# Variable root stores the repository root path.
root="$(cd "${script_dir}/.." && pwd)"
# Variable mode stores the selected deployment mode.
mode=""
# Variable host_name stores the FTP host name.
host_name=""
# Variable user_name stores the FTP user name.
user_name=""
# Variable password stores the FTP password.
password=""
# Variable remote_folder stores the FTP remote folder.
remote_folder=""
# Variable deploy_folder stores the local deploy destination.
deploy_folder=""
# Variable upload_media stores whether media folders are included.
upload_media=""
# Variable make_zip_deploy stores whether a zip package is created.
make_zip_deploy=""
# Variable update_manifest stores whether the integrity manifest is refreshed.
update_manifest=""
# Variable deploy_target stores the resolved local deployment target.
deploy_target=""

# Array exclude_dirs stores folders skipped by deployment.
exclude_dirs=(".git" "cache" "logs" "tmp" "deploy")
# Array exclude_dir_names_anywhere stores folder names skipped wherever they appear in the repository tree.
exclude_dir_names_anywhere=("__pycache__")
# Array exclude_files stores file name patterns skipped by deployment.
exclude_files=(".gitignore" "config.php" ".env" "*.log" "*.tmp")
# Array always_include_relatives stores deploy paths that must stay packaged even as filters evolve.
always_include_relatives=("app/lang")

# Function print_usage prints supported command-line arguments.
print_usage() {
    cat <<'USAGE'
Usage: ./deploy.sh [options]

Options:
  --mode local|ftp
  --host-name HOST
  --user-name USER
  --password PASSWORD
  --remote-folder PATH
  --deploy-folder PATH
  --upload-media true|false
  --make-zip-deploy true|false
  --update-manifest true|false
  -h, --help

Compatibility aliases:
  -Mode, -HostName, -UserName, -Password, -RemoteFolder, -DeployFolder,
  -UploadMedia, -MakeZipDeploy, -UpdateManifest
USAGE
}

# Function is_truthy returns success for common true values.
is_truthy() {
    case "${1:-}" in
        1|true|TRUE|True|yes|YES|Yes|y|Y) return 0 ;;
        *) return 1 ;;
    esac
}

# Function is_falsey returns success for common false values.
is_falsey() {
    case "${1:-}" in
        0|false|FALSE|False|no|NO|No|n|N) return 0 ;;
        *) return 1 ;;
    esac
}

# Function read_answer reads a value from stdin with a prompt.
read_answer() {
    # Variable prompt stores this scripts working prompt.
    local prompt="$1"
    # Variable answer stores this scripts working value.
    local answer=""
    read -r -p "$prompt" answer
    printf '%s' "$answer"
}

# Function parse_arguments reads command-line options.
parse_arguments() {
    while (($# > 0)); do
        case "$1" in
            --mode|-Mode)
                mode="${2:-}"
                shift 2
                ;;
            --host-name|-HostName)
                host_name="${2:-}"
                shift 2
                ;;
            --user-name|-UserName)
                user_name="${2:-}"
                shift 2
                ;;
            --password|-Password)
                password="${2:-}"
                shift 2
                ;;
            --remote-folder|-RemoteFolder)
                remote_folder="${2:-}"
                shift 2
                ;;
            --deploy-folder|-DeployFolder)
                deploy_folder="${2:-}"
                shift 2
                ;;
            --upload-media|-UploadMedia)
                upload_media="${2:-}"
                shift 2
                ;;
            --make-zip-deploy|-MakeZipDeploy)
                make_zip_deploy="${2:-}"
                shift 2
                ;;
            --update-manifest|-UpdateManifest)
                update_manifest="${2:-}"
                shift 2
                ;;
            -h|--help)
                print_usage
                exit 0
                ;;
            *)
                printf 'Unknown deploy option: %s\n' "$1" >&2
                print_usage >&2
                exit 2
                ;;
        esac
    done
}

# Function invoke_manifest_generator handles manifest refresh before deployment.
invoke_manifest_generator() {
    if ! command -v php >/dev/null 2>&1; then
        printf 'Warning: PHP executable was not found in PATH. Integrity manifest update was skipped.\n' >&2
        return 0
    fi

    # Variable manifest_script stores this scripts working value.
    local manifest_script="${root}/scripts/generate_manifest.php"
    if [[ ! -f "$manifest_script" ]]; then
        printf 'Warning: Manifest generator was not found: %s. Integrity manifest update was skipped.\n' "$manifest_script" >&2
        return 0
    fi

    printf 'Updating integrity manifest...\n'
    if ! php "$manifest_script"; then
        printf 'Warning: Manifest generator failed. Deploy will continue without updating the integrity manifest.\n' >&2
        return 0
    fi
}

# Function get_deploy_relative_path returns a repository-relative path.
get_deploy_relative_path() {
    # Variable path stores this scripts working value.
    local path="$1"
    # Variable absolute_path stores this scripts working value.
    local absolute_path
    absolute_path="$(cd "$(dirname "$path")" && pwd)/$(basename "$path")"
    # Variable relative stores this scripts working value.
    local relative="${absolute_path#"${root}/"}"
    printf '%s' "$relative"
}

# Function is_always_included returns success for protected include paths.
is_always_included() {
    # Variable portable_relative stores this scripts working value.
    local portable_relative="$1"
    # Variable always_include_relative stores this scripts working value.
    local always_include_relative=""
    for always_include_relative in "${always_include_relatives[@]}"; do
        # Variable portable_always_include stores one deploy path that must not be filtered out.
        local portable_always_include="${always_include_relative//\\//}"
        portable_always_include="${portable_always_include#/}"
        portable_always_include="${portable_always_include%/}"
        if [[ "$portable_relative" == "$portable_always_include" || "$portable_relative" == "$portable_always_include/"* ]]; then
            return 0
        fi
    done
    return 1
}

# Function should_skip returns success when a file should not be deployed.
should_skip() {
    # Variable path stores this scripts working value.
    local path="$1"

    if [[ -n "$deploy_target" ]]; then
        # Variable full_path stores this scripts working value.
        local full_path
        full_path="$(cd "$(dirname "$path")" && pwd)/$(basename "$path")"
        # Variable deploy_target_path stores this scripts working value.
        local deploy_target_path
        deploy_target_path="$(mkdir -p "$deploy_target" && cd "$deploy_target" && pwd)"
        if [[ "$full_path" == "$deploy_target_path" || "$full_path" == "$deploy_target_path/"* ]]; then
            return 0
        fi
    fi

    # Variable relative stores this scripts working value.
    local relative
    relative="$(get_deploy_relative_path "$path")"
    # Variable portable_relative stores this scripts working value.
    local portable_relative="${relative//\\//}"

    if [[ "$portable_relative" == "cache/.htaccess" || "$portable_relative" == "galleries/.htaccess" ]]; then
        return 1
    fi

    if is_always_included "$portable_relative"; then
        return 1
    fi

    # Variable dir stores this scripts working value.
    local dir=""
    for dir in "${exclude_dirs[@]}"; do
        if [[ "$portable_relative" == "$dir" || "$portable_relative" == "$dir/"* ]]; then
            return 0
        fi
    done

    # Variable dir_name stores this scripts working value.
    local dir_name=""
    for dir_name in "${exclude_dir_names_anywhere[@]}"; do
        if [[ "$portable_relative" == "$dir_name" || "$portable_relative" == "$dir_name/"* || "$portable_relative" == *"/$dir_name" || "$portable_relative" == *"/$dir_name/"* ]]; then
            return 0
        fi
    done

    # Variable file_name stores this scripts working value.
    local file_name
    file_name="$(basename "$path")"
    # Variable pattern stores this scripts working value.
    local pattern=""
    for pattern in "${exclude_files[@]}"; do
        case "$file_name" in
            $pattern) return 0 ;;
        esac
    done

    return 1
}

# Function copy_deploy_file copies one file to the local deploy target.
copy_deploy_file() {
    # Variable local_path stores this scripts working value.
    local local_path="$1"
    # Variable relative stores this scripts working value.
    local relative
    relative="$(get_deploy_relative_path "$local_path")"
    # Variable destination stores this scripts working value.
    local destination="${deploy_target}/${relative}"
    mkdir -p "$(dirname "$destination")"
    cp -f "$local_path" "$destination"
    printf 'Copied %s\n' "$relative"
}

# Function upload_file uploads one file through FTP using curl.
upload_file() {
    # Variable local_path stores this scripts working value.
    local local_path="$1"
    # Variable relative stores this scripts working value.
    local relative
    relative="$(get_deploy_relative_path "$local_path")"
    # Variable remote_base stores this scripts working value.
    local remote_base="ftp://${host_name}/${remote_folder#/}"
    remote_base="${remote_base%/}"
    # Variable uri stores this scripts working value.
    local uri="${remote_base}/${relative}"

    curl --silent --show-error --fail --ftp-create-dirs \
        --user "${user_name}:${password}" \
        --upload-file "$local_path" \
        "$uri" >/dev/null

    printf 'Uploaded %s\n' "$relative"
}

# Function new_compatible_zip_archive creates a simple deploy ZIP archive.
new_compatible_zip_archive() {
    # Variable source_directory stores this scripts working value.
    local source_directory="$1"
    # Variable destination_zip stores this scripts working value.
    local destination_zip="$2"

    if ! command -v zip >/dev/null 2>&1; then
        printf 'The zip command was not found. Install zip or create a folder deploy instead.\n' >&2
        exit 1
    fi

    rm -f "$destination_zip"
    (
        cd "$source_directory"
        # The -0 option creates a stored ZIP archive. That keeps the file format simple for older hosting tools.
        zip -0 -q -r "$destination_zip" .
    )
}

# Function collect_files prints deployable files, one per line.
collect_files() {
    (
        cd "$root"
        find . -type f -not -path './__MACOSX/*' -print0
    ) | while IFS= read -r -d '' relative_path; do
        # Variable full_path stores this scripts working value.
        local full_path="${root}/${relative_path#./}"
        if ! should_skip "$full_path"; then
            printf '%s\0' "$full_path"
        fi
    done
}

parse_arguments "$@"

if [[ -z "$mode" ]]; then
    # Variable answer stores this scripts working value.
    answer="$(read_answer 'Deployment mode: local deploy folder or FTP upload? [L/f] ')"
    if [[ "$answer" =~ ^[Ff] ]]; then
        mode="ftp"
    else
        mode="local"
    fi
fi

if [[ "$mode" != "local" && "$mode" != "ftp" ]]; then
    printf 'Deployment mode must be local or ftp.\n' >&2
    exit 2
fi

# Variable include_media stores this scripts working value.
include_media="false"
if [[ -n "$upload_media" ]]; then
    if is_truthy "$upload_media"; then
        include_media="true"
    fi
else
    # Variable media_answer stores this scripts working value.
    media_answer="$(read_answer 'Upload media folders? y/N ')"
    if [[ "$media_answer" =~ ^[Yy] ]]; then
        include_media="true"
    fi
fi

if [[ "$include_media" != "true" ]]; then
    exclude_dirs+=("galleries")
fi

if [[ "$mode" == "ftp" ]]; then
    if [[ -z "$host_name" ]]; then host_name="$(read_answer 'FTP host ')"; fi
    if [[ -z "$user_name" ]]; then user_name="$(read_answer 'FTP user ')"; fi
    if [[ -z "$password" ]]; then password="$(read_answer 'FTP password ')"; fi
    if [[ -z "$remote_folder" ]]; then remote_folder="$(read_answer 'Remote folder ')"; fi
fi

if [[ "$mode" == "local" && -z "$deploy_folder" ]]; then
    deploy_folder="$(read_answer 'Local deploy folder [deploy] ')"
    if [[ -z "$deploy_folder" ]]; then
        deploy_folder="deploy"
    fi
fi

# Variable zip_deploy stores this scripts working value.
zip_deploy="false"
if [[ "$mode" == "local" ]]; then
    if [[ -n "$make_zip_deploy" ]]; then
        if is_truthy "$make_zip_deploy"; then
            zip_deploy="true"
        fi
    else
        # Variable zip_answer stores this scripts working value.
        zip_answer="$(read_answer 'Make a zip deploy? Y/n ')"
        if [[ ! "$zip_answer" =~ ^[Nn] ]]; then
            zip_deploy="true"
        fi
    fi
fi

# Variable refresh_manifest stores this scripts working value.
refresh_manifest="true"
if [[ -n "$update_manifest" ]]; then
    if is_falsey "$update_manifest"; then
        refresh_manifest="false"
    fi
else
    # Variable manifest_answer stores this scripts working value.
    manifest_answer="$(read_answer 'Update integrity manifest before deploy? y/N ')"
    if [[ "$manifest_answer" =~ ^[Yy] ]]; then
        refresh_manifest="true"
    else
        refresh_manifest="false"
    fi
fi

if [[ "$refresh_manifest" == "true" ]]; then
    invoke_manifest_generator
fi

cd "$root"

if [[ "$mode" == "local" ]]; then
    if [[ "$deploy_folder" == /* ]]; then
        deploy_target="$deploy_folder"
    else
        deploy_target="${root}/${deploy_folder}"
    fi

    # Variable root_path stores this scripts working value.
    root_path="$(cd "$root" && pwd)"
    # Variable deploy_target_parent stores this scripts working value.
    deploy_target_parent="$(mkdir -p "$(dirname "$deploy_target")" && cd "$(dirname "$deploy_target")" && pwd)"
    # Variable deploy_target_path stores this scripts working value.
    deploy_target_path="${deploy_target_parent}/$(basename "$deploy_target")"
    if [[ "$deploy_target_path" == "$root_path" ]]; then
        printf 'Local deploy folder cannot be the project root.\n' >&2
        exit 1
    fi

    rm -rf "$deploy_target"
    mkdir -p "$deploy_target"

    if [[ "$zip_deploy" == "true" ]]; then
        # Variable deploy_staging stores this scripts working value.
        deploy_staging="$(mktemp -d "${TMPDIR:-/tmp}/php-gallery-deploy.XXXXXX")"
        # Variable previous_deploy_target stores this scripts working value.
        previous_deploy_target="$deploy_target"
        trap 'rm -rf "$deploy_staging"' EXIT
        deploy_target="$deploy_staging"
        while IFS= read -r -d '' file_path; do
            copy_deploy_file "$file_path"
        done < <(collect_files)

        # Variable zip_path stores this scripts working value.
        zip_path="${previous_deploy_target}/php-gallery-deploy.zip"
        new_compatible_zip_archive "$deploy_staging" "$zip_path"
        deploy_target="$previous_deploy_target"
        printf 'Local zip deploy created at %s\n' "$zip_path"
    else
        while IFS= read -r -d '' file_path; do
            copy_deploy_file "$file_path"
        done < <(collect_files)
        printf 'Local deploy folder created at %s\n' "$deploy_target"
    fi
else
    if ! command -v curl >/dev/null 2>&1; then
        printf 'curl was not found. FTP deployment requires curl on macOS/Linux.\n' >&2
        exit 1
    fi

    while IFS= read -r -d '' file_path; do
        upload_file "$file_path"
    done < <(collect_files)
fi
