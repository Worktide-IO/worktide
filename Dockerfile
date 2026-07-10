# syntax=docker/dockerfile:1
#
# Production image for the Worktide Symfony API — FrankenPHP runtime.
# Multi-stage: composer/deps + asset build → slim runtime.
# Built by Coolify (Dockerfile buildpack). Local dev stays on DDEV; this file
# is prod-only.
#
# The same image runs three roles, selected by the container command in
# compose.prod.yaml:
#   - app       : FrankenPHP HTTP server (default CMD)
#   - worker    : messenger:consume async ai_agents search
#   - scheduler : supercronic running the periodic console commands
#
# Base pinned to PHP 8.4 (composer requires >=8.4).

ARG PHP_VERSION=8.4
ARG FRANKENPHP_VERSION=1

########################
# 1. Base runtime
########################
FROM dunglas/frankenphp:${FRANKENPHP_VERSION}-php${PHP_VERSION} AS frankenphp_base

WORKDIR /app

# Persistent, so we can reuse them across builds
VOLUME /app/var/

# php extensions installer (bundled in the frankenphp image)
RUN install-php-extensions \
	@composer \
	apcu \
	intl \
	opcache \
	pdo_mysql \
	zip \
	gd \
	sockets \
	;

# supercronic — reliable cron runner for the scheduler role (no root cron daemon)
ARG SUPERCRONIC_VERSION=v0.2.33
ARG TARGETARCH
RUN SUPERCRONIC_URL="https://github.com/aptible/supercronic/releases/download/${SUPERCRONIC_VERSION}/supercronic-linux-${TARGETARCH:-amd64}" \
	&& curl -fsSLo /usr/local/bin/supercronic "$SUPERCRONIC_URL" \
	&& chmod +x /usr/local/bin/supercronic

###> recipes ###
###< recipes ###

COPY --link frankenphp/conf.d/10-app.ini $PHP_INI_DIR/conf.d/
COPY --link --chmod=755 frankenphp/docker-entrypoint.sh /usr/local/bin/docker-entrypoint
COPY --link frankenphp/Caddyfile /etc/frankenphp/Caddyfile
COPY --link frankenphp/crontab /etc/crontab

ENTRYPOINT ["docker-entrypoint"]

# No image-wide HEALTHCHECK — each role defines its own in compose.prod.yaml.
# (The old `curl :2019/metrics` check failed because FrankenPHP does not expose
# /metrics by default → containers were marked unhealthy and cycled.)
CMD [ "frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile" ]

########################
# 2. Dev image (unused by Coolify, kept for parity/local docker use)
########################
FROM frankenphp_base AS frankenphp_dev

ENV APP_ENV=dev XDEBUG_MODE=off
RUN mv "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"
RUN install-php-extensions xdebug
CMD [ "frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile", "--watch" ]

########################
# 3. Prod build — vendor + assets
########################
FROM frankenphp_base AS frankenphp_prod

# Build/version identity surfaced at GET /v1/version. Pass APP_COMMIT (git SHA,
# e.g. Coolify's SOURCE_COMMIT) and optionally APP_VERSION (release tag) as
# build args; BUILD_TIME is stamped below. All degrade gracefully if unset.
ARG APP_VERSION=""
ARG APP_COMMIT=""
ENV APP_VERSION=$APP_VERSION
ENV APP_COMMIT=$APP_COMMIT

ENV APP_ENV=prod
# Classic per-request mode by default (zero extra deps). To enable FrankenPHP
# worker mode: `composer require runtime/frankenphp-symfony`, keep worker.Caddyfile,
# and set FRANKENPHP_CONFIG="import worker.Caddyfile" as a Coolify env var.
ENV FRANKENPHP_CONFIG=""

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY --link frankenphp/conf.d/20-app.prod.ini $PHP_INI_DIR/conf.d/
COPY --link frankenphp/worker.Caddyfile /etc/frankenphp/

# Install composer dependencies (no dev, no scripts yet — source not copied)
COPY --link composer.* symfony.lock ./
RUN set -eux; \
	composer install --no-cache --prefer-dist --no-dev --no-autoloader --no-scripts --no-progress

# Copy application source
COPY --link . ./
RUN rm -Rf frankenphp/

# Finish composer + build assets. Dump prod env, warmup cache, install importmap
# assets and compile the asset map (non-fatal if the app ships no frontend assets).
RUN set -eux; \
	mkdir -p var/cache var/log var/uploads; \
	# .env is no longer committed (secrets removed). `composer dump-env prod`
	# still needs a base .env file, so materialize it from the secret-free
	# .env.example. Real secrets come from the runtime environment (Coolify),
	# which always wins over the baked .env.local.php.
	cp .env.example .env; \
	date -u +%Y-%m-%dT%H:%M:%SZ > BUILD_TIME; \
	composer dump-autoload --classmap-authoritative --no-dev; \
	composer dump-env prod; \
	composer run-script --no-dev post-install-cmd || true; \
	php bin/console importmap:install || true; \
	php bin/console asset-map:compile || true; \
	chmod +x bin/console; sync;
