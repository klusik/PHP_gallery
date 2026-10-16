#!/usr/bin/env bash
# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: .github/ci-experiment/runner.sh
# Module Type: Archived CI Experiment Shell Tool
# Purpose: Select a prepared OCI runtime and invoke its existing central audit owner.
# Responsibilities:
#   - Verify PHP version and intl variants and required tool versions.
#   - Dispatch the original smoke, manual, source, runtime, browser and package audit roles.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
set -euo pipefail
role="${1:?expected central audit role}"
case "${CI_PHP_VERSION:-8.5}" in 8.1|8.5) ;; *) exit 2 ;; esac
case "${CI_PHP_INTL:-with-intl}" in with-intl|without-intl) ;; *) exit 2 ;; esac
export PHP_INI_SCAN_DIR="/opt/gallery-php/${CI_PHP_VERSION}/${CI_PHP_INTL}"
export GITHUB_PATH=/tmp/gallery-github-path
export RUNNER_TEMP=/tmp
export PATH="/home/ci/.TinyTeX/bin/x86_64-linux:/usr/local/bin:/usr/bin:/bin"
test "$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')" = "${CI_PHP_VERSION}"
if [[ "${CI_PHP_INTL}" == with-intl ]]; then
    php -r 'exit(extension_loaded("intl") ? 0 : 1);'
else
    php -r 'exit(extension_loaded("intl") ? 1 : 0);'
fi
printf 'Runtime PHP=%s intl=%s role=%s T=%s B=%s\n' \
  "$(php -v | head -n 1)" "${CI_PHP_INTL}" "${role}" \
  "$(git rev-parse HEAD)" "${PHP_GALLERY_SOURCE_BASE:-unset}"
case "${role}" in
    smoke)
        for version in 8.1 8.5; do
            for profile in with-intl without-intl; do
                expected=0; if [[ "${profile}" == with-intl ]]; then expected=1; fi
                CI_PHP_VERSION="${version}" PHP_INI_SCAN_DIR="/opt/gallery-php/${version}/${profile}" \
                    php -r 'if ((int)extension_loaded("intl") !== (int)$argv[1]) { exit(4); }
                            printf("PHP %s intl=%s\n", PHP_VERSION, extension_loaded("intl") ? "yes" : "no");' \
                    "$expected"
            done
        done
        node --version
        python3 --version
        git --version
        google-chrome --version
        (cd /toolchain && bash .github/scripts/provision_tinytex.sh verify)
        php -r '
            $db = new PDO("mysql:host=127.0.0.1;port=13316", "root",
                getenv("GALLERY_WORKFLOW_CI_ROOT_PASSWORD"), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            if ($db->query("SELECT @@hostname")->fetchColumn() !== "gallery-workflow-ci") { exit(2); }
            $version = (string)$db->query("SELECT @@version")->fetchColumn();
            $comment = (string)$db->query("SELECT @@version_comment")->fetchColumn();
            $engine = (string)getenv("EXPERIMENT_DATABASE");
            $prefix = ["mysql84" => "8.4.", "mariadb1011" => "10.11.", "mariadb114" => "11.4."][$engine] ?? null;
            if ($prefix === null || !str_starts_with($version, $prefix)) { exit(3); }
            printf("DATABASE version=%s comment=%s\n", $version, $comment);
        '
        ;;
    manuals) exec bash /ci/manuals.sh /work/source /ci-output/manuals ;;
    preflight) exec php scripts/audit.php --profile=candidate-preflight ;;
    source-contracts) exec php scripts/audit.php --profile=source-contracts ;;
    portable) exec php scripts/audit.php --profile=portable ;;
    compat) exec php scripts/audit.php --profile=hosted-full ;;
    database) exec php scripts/gallery_workflow_ci.php ;;
    browser) exec php scripts/audit.php --suite=browser-map ;;
    package) exec php scripts/audit.php --suite=production-package ;;
    *) echo "BLOCKED unknown central audit owner: ${role}" >&2; exit 2 ;;
esac
