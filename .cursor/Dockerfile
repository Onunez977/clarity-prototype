# Cursor Cloud Agent base image for Clarity (insight-lpr/platform).
# System packages and toolchains only — Cursor checks out the repo separately.
# Do not COPY the application. The production FrankenPHP Dockerfile at the
# repo root is not this image.

FROM ubuntu:24.04

ENV DEBIAN_FRONTEND=noninteractive \
    LANG=C.UTF-8 \
    LC_ALL=C.UTF-8

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
        git \
        gnupg \
        lsb-release \
        pandoc \
        postgresql-client \
        software-properties-common \
        sudo \
        unzip \
        wget \
    && rm -rf /var/lib/apt/lists/*

# PHP 8.4 — matches CI (.github/workflows/lint_check_and_test.yml). App code
# must stay 8.3-compatible (composer.json platform pin + production).
RUN add-apt-repository -y ppa:ondrej/php \
    && apt-get update \
    && apt-get install -y --no-install-recommends \
        php8.4-cli \
        php8.4-bcmath \
        php8.4-curl \
        php8.4-gd \
        php8.4-igbinary \
        php8.4-intl \
        php8.4-mbstring \
        php8.4-pgsql \
        php8.4-readline \
        php8.4-redis \
        php8.4-soap \
        php8.4-sqlite3 \
        php8.4-xml \
        php8.4-zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Node 22 — matches .nvmrc
COPY --from=node:22-bookworm /usr/local/bin/node /usr/local/bin/node
COPY --from=node:22-bookworm /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -sf /usr/local/lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && ln -sf /usr/local/lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx

########################################################
# DOCKER (nested) — fuse-overlayfs + iptables-legacy
# https://cursor.com/docs/cloud-agent/setup.md#running-docker
########################################################

RUN install -m 0755 -d /etc/apt/keyrings \
    && curl --retry 3 --retry-delay 5 -fsSL https://download.docker.com/linux/ubuntu/gpg \
        | gpg --dearmor -o /etc/apt/keyrings/docker.gpg \
    && chmod a+r /etc/apt/keyrings/docker.gpg \
    && echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/ubuntu \
        $(. /etc/os-release && echo "$VERSION_CODENAME") stable" \
        > /etc/apt/sources.list.d/docker.list \
    && apt-get update \
    && apt-get install -y --no-install-recommends \
        -o Dpkg::Options::="--force-confold" \
        docker-ce=5:28.5.2-1~ubuntu.24.04~noble \
        docker-ce-cli=5:28.5.2-1~ubuntu.24.04~noble \
        containerd.io \
        docker-buildx-plugin \
        docker-compose-plugin \
        fuse-overlayfs \
        iptables \
    && rm -rf /var/lib/apt/lists/* \
    && mkdir -p /etc/docker \
    && printf '%s\n' '{' '  "storage-driver": "fuse-overlayfs"' '}' > /etc/docker/daemon.json \
    && update-alternatives --set iptables /usr/sbin/iptables-legacy \
    && update-alternatives --set ip6tables /usr/sbin/ip6tables-legacy

RUN mkdir -p /etc/ssh/sshd_config.d \
    && printf '%s\n' 'PasswordAuthentication no' 'ChallengeResponseAuthentication no' 'UsePAM no' \
        > /etc/ssh/sshd_config.d/disable_password_auth.conf \
    && (id -u ubuntu >/dev/null 2>&1 || useradd -m -s /bin/bash ubuntu) \
    && groupadd -f docker \
    && usermod -aG docker ubuntu \
    && usermod -aG sudo ubuntu \
    && echo "ubuntu ALL=(ALL) NOPASSWD:ALL" > /etc/sudoers.d/ubuntu \
    && echo "ubuntu:ubuntu" | chpasswd

USER ubuntu
WORKDIR /workspace
