# Project: PHP Gallery
# File: .github/ci-experiment/Dockerfile
# Purpose: OCI test runtime built once, independent of the tested application SHA.
ARG NODE_IMAGE=node:22-bookworm-slim
FROM ${NODE_IMAGE} AS node-runtime
FROM ubuntu:24.04
LABEL org.opencontainers.image.source="https://github.com/klusik/PHP_gallery"
LABEL org.opencontainers.image.description="Isolated disposable PHP Gallery CI runtime"
ENV DEBIAN_FRONTEND=noninteractive TZ=Etc/UTC
RUN apt-get update && apt-get install -y --no-install-recommends \
    ca-certificates curl gnupg software-properties-common \
    git openssh-client unzip zip xz-utils tar gzip findutils diffutils \
    jq procps psmisc util-linux gawk perl make \
    python3 python3-venv python3-pip \
    fonts-liberation fonts-dejavu-core fonts-noto-core fonts-noto-color-emoji \
    libnss3 libgbm1 libasound2t64 libatk-bridge2.0-0 libgtk-3-0 \
    poppler-utils mariadb-client \
 && add-apt-repository -y ppa:ondrej/php \
 && apt-get update && apt-get install -y --no-install-recommends \
    php8.1-cli php8.1-common php8.1-mysql php8.1-sqlite3 \
    php8.1-curl php8.1-gd php8.1-zip php8.1-xml php8.1-mbstring php8.1-intl \
    php8.5-cli php8.5-common php8.5-mysql php8.5-sqlite3 \
    php8.5-curl php8.5-gd php8.5-zip php8.5-xml php8.5-mbstring php8.5-intl \
 && curl --fail --location --silent --show-error --retry 2 \
    https://dl.google.com/linux/direct/google-chrome-stable_current_amd64.deb \
    --output /tmp/google-chrome.deb \
 && apt-get install -y --no-install-recommends /tmp/google-chrome.deb \
 && rm -f /tmp/google-chrome.deb \
 && apt-get clean && rm -rf /var/lib/apt/lists/*
COPY --from=node-runtime /usr/local/ /usr/local/
COPY .github/ci-experiment/bin/php /usr/local/bin/php
RUN chmod 0755 /usr/local/bin/php \
 && useradd --create-home --uid 1001 --shell /bin/bash ci \
 && mkdir -p /toolchain /opt/gallery-php \
 && for version in 8.1 8.5; do \
      printf '%s\n' 'display_errors=On' 'error_reporting=E_ALL' \
        > "/etc/php/${version}/cli/conf.d/99-gallery-ci.ini"; \
      for profile in with-intl without-intl; do \
        dir="/opt/gallery-php/${version}/${profile}"; mkdir -p "${dir}"; \
        for f in /etc/php/${version}/cli/conf.d/*.ini; do \
          name="$(basename "${f}")"; \
          if [ "${profile}" = without-intl ] && [ "${name#*intl}" != "${name}" ]; then continue; fi; \
          ln -s "${f}" "${dir}/${name}"; \
        done; \
      done; \
    done
COPY --chown=ci:ci .github/texlive-lock.json /toolchain/.github/texlive-lock.json
COPY --chown=ci:ci .github/texlive-packages.txt /toolchain/.github/texlive-packages.txt
COPY --chown=ci:ci .github/scripts/provision_tinytex.sh /toolchain/.github/scripts/provision_tinytex.sh
ENV HOME=/home/ci
ENV RUNNER_TEMP=/tmp
ENV GITHUB_PATH=/tmp/gallery-github-path
ENV CI_PHP_VERSION=8.5
ENV CI_PHP_INTL=with-intl
ENV PHP_INI_SCAN_DIR=/opt/gallery-php/8.5/with-intl
ENV PATH=/home/ci/.TinyTeX/bin/x86_64-linux:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
USER ci
WORKDIR /toolchain
RUN bash .github/scripts/provision_tinytex.sh install \
 && bash .github/scripts/provision_tinytex.sh provision \
 && bash .github/scripts/provision_tinytex.sh verify \
 && php8.1 --version && php8.5 --version && node --version \
 && python3 --version && google-chrome --version \
 && test -x /home/ci/.TinyTeX/bin/x86_64-linux/pdflatex
WORKDIR /work/source
