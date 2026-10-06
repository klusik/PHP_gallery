#!/usr/bin/env bash

# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
#
# File: scripts/deploy.sh
# Module Type: Deployment Script
#
# Purpose:
#   Packages or uploads the canonical PHP Gallery production file set.
#
# Responsibilities:
#   - Validate release integrity and canonical package membership
#   - Build local folders or ZIP archives without walking the workspace
#   - Upload only validated files through FTP
#
# Author:
#   Rudolf Klusal
#
# Contact:
#   https://github.com/klusik
#
# License:
#   MIT License (see LICENSE file in repository)

set -euo pipefail

# Resolve the repository root from the location of this script.
script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
root="$(cd "${script_dir}/.." && pwd -P)"
mode=""
host_name=""
user_name=""
password=""
remote_folder=""
deploy_folder=""
upload_media=""
make_zip_deploy=""
include_tests=""
zip_deploy="false"
include_media="false"
include_repository_tests="false"
temporary_stage=""
temporary_archive=""

# Print the supported command-line options.
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
  --include-tests true|false   Include tests in local source packages (default: false; unavailable for FTP)
  -h, --help

Compatibility aliases:
  -Mode, -HostName, -UserName, -Password, -RemoteFolder, -DeployFolder,
  -UploadMedia, -MakeZipDeploy, -IncludeTests
USAGE
}

# Return success when an input represents true.
is_truthy() {
    case "${1:-}" in
        1|true|TRUE|True|yes|YES|Yes|y|Y) return 0 ;;
        *) return 1 ;;
    esac
}

# Read one interactive value from stdin.
read_answer() {
    local prompt="$1"
    local answer=""
    read -r -p "$prompt" answer
    printf '%s' "$answer"
}

# Parse explicit wrapper options and compatibility aliases.
parse_arguments() {
    while (($# > 0)); do
        case "$1" in
            --mode|-Mode) mode="${2:-}"; shift 2 ;;
            --host-name|-HostName) host_name="${2:-}"; shift 2 ;;
            --user-name|-UserName) user_name="${2:-}"; shift 2 ;;
            --password|-Password) password="${2:-}"; shift 2 ;;
            --remote-folder|-RemoteFolder) remote_folder="${2:-}"; shift 2 ;;
            --deploy-folder|-DeployFolder) deploy_folder="${2:-}"; shift 2 ;;
            --upload-media|-UploadMedia) upload_media="${2:-}"; shift 2 ;;
            --make-zip-deploy|-MakeZipDeploy) make_zip_deploy="${2:-}"; shift 2 ;;
            --include-tests|-IncludeTests) include_tests="${2:-}"; shift 2 ;;
            -h|--help) print_usage; exit 0 ;;
            *)
                printf 'Unknown deploy option: %s\n' "$1" >&2
                print_usage >&2
                exit 2
                ;;
        esac
    done
}

# Return the resolver profile chosen by the controlled local tests option.
package_profile() {
    if [[ "$include_repository_tests" == "true" ]]; then
        printf 'source-review'
    else
        printf 'production'
    fi
}

# Convert filesystem paths to the native form expected by Windows PHP under Git Bash.
php_path_argument() {
    if command -v cygpath >/dev/null 2>&1; then
        cygpath -w "$1"
    else
        printf '%s' "$1"
    fi
}

# Run the required manifest freshness check against the repository being packaged.
check_manifest() {
    php "$root/scripts/generate_manifest.php" --check "--root=$(php_path_argument "$root")"
}

# Emit the exact canonical package paths as NUL-delimited values.
list_package_paths() {
    local php_root="$(php_path_argument "$root")"
    local arguments=("$root/scripts/release_files.php" list "--root=$php_root" "--profile=$(package_profile)" --format=nul)
    if [[ "$include_media" == "true" ]]; then
        arguments+=(--include-media)
    fi
    php "${arguments[@]}"
}

# Verify a staged tree against the same canonical package profile used to build it.
verify_package_tree() {
    local stage="$1"
    local php_stage="$(php_path_argument "$stage")"
    local php_root="$(php_path_argument "$root")"
    local arguments=("$root/scripts/release_files.php" verify "--root=$php_stage" "--source-root=$php_root" "--profile=$(package_profile)")
    if [[ "$include_media" == "true" ]]; then
        arguments+=(--include-media)
    fi
    php "${arguments[@]}"
}

# Copy the canonical listed paths into a newly created staging directory.
stage_package() {
    local stage="$1"
    local relative_path=""
    local parent_path=""
    local previous_parent=""
    local path_list_file="$stage/.package-paths.nul"
    list_package_paths > "$path_list_file"
    while IFS= read -r -d '' relative_path; do
        # Canonical paths are sorted; shell expansion avoids a child per dirname.
        parent_path="."
        if [[ "$relative_path" == */* ]]; then
            parent_path="${relative_path%/*}"
        fi
        if [[ "$parent_path" != "$previous_parent" ]]; then
            mkdir -p "$stage/$parent_path"
            previous_parent="$parent_path"
        fi
        cp -p "$root/$relative_path" "$stage/$relative_path"
    done < "$path_list_file"
    rm -f -- "$path_list_file"
    verify_package_tree "$stage"
}

# Resolve a destination against its existing parent without following a final symlink.
resolve_destination() {
    local candidate="$1"
    local parent=""
    local final_component=""
    if [[ "$candidate" != /* ]]; then
        candidate="$root/$candidate"
    fi
    final_component="$(basename "$candidate")"
    if [[ "$final_component" == "." || "$final_component" == ".." ]]; then
        printf 'Local deploy target must name a new directory path.\n' >&2
        return 1
    fi
    mkdir -p "$(dirname "$candidate")"
    parent="$(cd "$(dirname "$candidate")" && pwd -P)"
    printf '%s/%s' "$parent" "$final_component"
}

# Upload one already-validated source path to the selected FTP folder.
upload_file() {
    local stage="$1"
    local relative_path="$2"
    local remote_base="ftp://${host_name}/${remote_folder#/}"
    remote_base="${remote_base%/}"
    curl --silent --show-error --fail --ftp-create-dirs \
        --user "${user_name}:${password}" \
        --upload-file "$stage/$relative_path" \
        "${remote_base}/${relative_path}" >/dev/null
    printf 'Uploaded %s\n' "$relative_path"
}

# Build a simple stored ZIP archive from an already verified staging tree.
new_compatible_zip_archive() {
    local source_directory="$1"
    local destination_zip="$2"
    if ! command -v zip >/dev/null 2>&1; then
        printf 'The zip command was not found. Install zip or create a folder deploy instead.\n' >&2
        return 1
    fi
    (
        cd "$source_directory"
        zip -0 -q -r -D "$destination_zip" .
    )
}

# Publish verified files below a directory atomically claimed by mkdir.
publish_folder_no_clobber() {
    local stage="$1"
    local destination="$2"
    local relative_path=""
    local parent_path=""
    local previous_parent=""
    mkdir "$destination"
    while IFS= read -r -d '' relative_path; do
        # Keep Bash 3.2 support while creating each consecutive parent only once.
        parent_path="."
        if [[ "$relative_path" == */* ]]; then
            parent_path="${relative_path%/*}"
        fi
        if [[ "$parent_path" != "$previous_parent" ]]; then
            mkdir -p "$destination/$parent_path"
            previous_parent="$parent_path"
        fi
        cp -p -n "$stage/$relative_path" "$destination/$relative_path"
    done < <(list_package_paths)
    verify_package_tree "$destination"
}

# Remove only temporary paths created by this invocation.
cleanup_temporary_paths() {
    if [[ -n "$temporary_stage" && -d "$temporary_stage" && ! -L "$temporary_stage" ]]; then
        rm -rf -- "$temporary_stage"
    fi
    if [[ -n "$temporary_archive" && -d "$temporary_archive" && ! -L "$temporary_archive" ]]; then
        case "$temporary_archive" in
            "$deploy_target"/.php-gallery-zip.*) rm -rf -- "$temporary_archive" ;;
        esac
    fi
}

parse_arguments "$@"
trap cleanup_temporary_paths EXIT

if [[ -z "$mode" ]]; then
    answer="$(read_answer 'Deployment mode: local deploy folder or FTP upload? [L/f] ')"
    if [[ "$answer" =~ ^[Ff] ]]; then mode="ftp"; else mode="local"; fi
fi
if [[ "$mode" != "local" && "$mode" != "ftp" ]]; then
    printf 'Deployment mode must be local or ftp.\n' >&2
    exit 2
fi

if [[ -n "$upload_media" ]]; then
    if is_truthy "$upload_media"; then include_media="true"; fi
else
    media_answer="$(read_answer 'Upload media folders? y/N ')"
    if [[ "$media_answer" =~ ^[Yy] ]]; then include_media="true"; fi
fi

if [[ -n "$include_tests" ]]; then
    if is_truthy "$include_tests"; then include_repository_tests="true"; fi
elif [[ "$mode" == "local" ]]; then
    tests_answer="$(read_answer 'Include tests folder? y/N ')"
    if [[ "$tests_answer" =~ ^[Yy] ]]; then include_repository_tests="true"; fi
fi
if [[ "$include_repository_tests" == "true" && "$mode" == "ftp" ]]; then
    printf '%s\n' 'Tests may be included only in local deployment folders or ZIP packages.' >&2
    exit 2
fi

if [[ "$mode" == "ftp" ]]; then
    [[ -n "$host_name" ]] || host_name="$(read_answer 'FTP host ')"
    [[ -n "$user_name" ]] || user_name="$(read_answer 'FTP user ')"
    [[ -n "$password" ]] || password="$(read_answer 'FTP password ')"
    [[ -n "$remote_folder" ]] || remote_folder="$(read_answer 'Remote folder ')"
else
    [[ -n "$deploy_folder" ]] || deploy_folder="$(read_answer 'Local deploy folder [deploy/package] ')"
    [[ -n "$deploy_folder" ]] || deploy_folder="deploy/package"
    if [[ -n "$make_zip_deploy" ]]; then
        if is_truthy "$make_zip_deploy"; then zip_deploy="true"; fi
    else
        zip_answer="$(read_answer 'Make a zip deploy? Y/n ')"
        if [[ ! "$zip_answer" =~ ^[Nn] ]]; then zip_deploy="true"; fi
    fi
fi

cd "$root"
check_manifest

if [[ "$mode" == "local" ]]; then
    deploy_target="$(resolve_destination "$deploy_folder")"
    if [[ "$deploy_target" == "$root" || "$root" == "$deploy_target/"* ]]; then
        printf 'Local deploy target cannot be the project root or one of its parent directories.\n' >&2
        exit 1
    fi
    if [[ -L "$deploy_target" ]]; then
        printf 'Local deploy target cannot be a symlink.\n' >&2
        exit 1
    fi
    if [[ "$include_media" == "true" ]]; then
        gallery_root="$(cd "$root/galleries" && pwd -P)"
        if [[ "$deploy_target" == "$gallery_root" || "$deploy_target" == "$gallery_root/"* ]]; then
            printf 'Media-enabled local output cannot be created inside the source galleries tree.\n' >&2
            exit 1
        fi
    fi
    deploy_parent="$(dirname "$deploy_target")"
    mkdir -p "$deploy_parent"
    if [[ "$zip_deploy" == "true" ]]; then
        if [[ -e "$deploy_target" && ! -d "$deploy_target" ]]; then
            printf 'ZIP deploy destination must be a directory: %s\n' "$deploy_target" >&2
            exit 1
        fi
        zip_path="$deploy_target/php-gallery-deploy.zip"
        if [[ -e "$zip_path" || -L "$zip_path" ]]; then
            printf 'Refusing to overwrite an existing ZIP deploy: %s\n' "$zip_path" >&2
            exit 1
        fi
    elif [[ -e "$deploy_target" ]]; then
        printf 'Refusing to replace an existing deploy directory: %s\n' "$deploy_target" >&2
        exit 1
    fi
fi
if [[ "$mode" == "local" && "$zip_deploy" != "true" ]]; then
    temporary_stage="$(mktemp -d "$(dirname "$deploy_target")/.php-gallery-stage.XXXXXX")"
else
    temporary_stage="$(mktemp -d "${TMPDIR:-/tmp}/php-gallery-deploy.XXXXXX")"
fi
stage_package "$temporary_stage"

if [[ "$mode" == "local" ]]; then
    deploy_target="$(resolve_destination "$deploy_folder")"
    if [[ "$deploy_target" == "$root" || -L "$deploy_target" ]]; then
        printf 'Local deploy target must be a path other than the project root and cannot be a symlink.\n' >&2
        exit 1
    fi

    if [[ "$zip_deploy" == "true" ]]; then
        if [[ -e "$deploy_target" && ! -d "$deploy_target" ]]; then
            printf 'ZIP deploy destination must be a directory: %s\n' "$deploy_target" >&2
            exit 1
        fi
        mkdir -p "$deploy_target"
        zip_path="${deploy_target}/php-gallery-deploy.zip"
        if [[ -e "$zip_path" || -L "$zip_path" ]]; then
            printf 'Refusing to overwrite an existing ZIP deploy: %s\n' "$zip_path" >&2
            exit 1
        fi
        temporary_archive="$(mktemp -d "$deploy_target/.php-gallery-zip.XXXXXX")"
        archive_candidate="$temporary_archive/php-gallery-deploy.zip"
        new_compatible_zip_archive "$temporary_stage" "$archive_candidate"
        if [[ ! -f "$archive_candidate" || -L "$archive_candidate" ]]; then
            printf 'The ZIP tool did not create a regular archive file.\n' >&2
            exit 1
        fi
        if ! command -v unzip >/dev/null 2>&1; then
            printf 'The unzip command is required to validate a ZIP deploy.\n' >&2
            exit 1
        fi
        unzip -tq "$archive_candidate" >/dev/null
        if ! ln "$archive_candidate" "$zip_path"; then
            printf 'Could not publish the ZIP deploy without replacing an existing file.\n' >&2
            exit 1
        fi
        rm -f -- "$archive_candidate"
        rm -rf -- "$temporary_archive"
        temporary_archive=""
        printf 'Local zip deploy created at %s\n' "$zip_path"
    else
        if [[ -e "$deploy_target" ]]; then
            printf 'Refusing to replace an existing deploy directory: %s\n' "$deploy_target" >&2
            exit 1
        fi
        publish_folder_no_clobber "$temporary_stage" "$deploy_target"
        printf 'Local deploy folder created at %s\n' "$deploy_target"
    fi
else
    if ! command -v curl >/dev/null 2>&1; then
        printf 'curl was not found. FTP deployment requires curl on macOS/Linux.\n' >&2
        exit 1
    fi
    ftp_path_list="$temporary_stage/.package-paths.nul"
    list_package_paths > "$ftp_path_list"
    while IFS= read -r -d '' relative_path; do
        upload_file "$temporary_stage" "$relative_path"
    done < "$ftp_path_list"
    rm -f -- "$ftp_path_list"
fi
