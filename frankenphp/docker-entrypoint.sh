#!/bin/sh
set -e

# Runs for every role (app / worker / scheduler). Heavy one-time setup
# (DB migrations) is guarded by AUTO_MIGRATE so only the app container does it
# and the workers/scheduler don't race on the same schema.

if [ "$1" = 'frankenphp' ] || [ "$1" = 'php' ] || [ "$1" = 'bin/console' ] || [ "$1" = 'supercronic' ]; then
	# Writable runtime dirs (var/ is a volume, may start empty)
	mkdir -p var/cache var/log var/uploads config/jwt

	# --- JWT keypair (lexik) -------------------------------------------------
	# Generated once into the persistent config/jwt volume so tokens stay valid
	# across restarts. JWT_PASSPHRASE must be set in the environment (Coolify).
	#
	# Self-healing on passphrase rotation: if a key already exists but can no
	# longer be decrypted with the current JWT_PASSPHRASE (i.e. the passphrase
	# was rotated in the environment), regenerate it. Without this, changing
	# JWT_PASSPHRASE leaves the old key undecryptable and every token-sign
	# fails while public endpoints keep working — masking the breakage.
	# The regenerate (--overwrite) is gated to the app container (AUTO_MIGRATE=1)
	# so the worker/scheduler don't race on the shared volume; the app is the
	# JWT signer and fixes the volume for all roles.
	if [ -n "$JWT_PASSPHRASE" ]; then
		if [ ! -f config/jwt/private.pem ]; then
			echo "[entrypoint] generating JWT keypair..."
			php bin/console lexik:jwt:generate-keypair --skip-if-exists --no-interaction || true
		elif [ "${AUTO_MIGRATE:-0}" = "1" ] && ! openssl pkey -in config/jwt/private.pem -passin "pass:$JWT_PASSPHRASE" -noout 2>/dev/null; then
			echo "[entrypoint] JWT_PASSPHRASE changed — regenerating keypair (invalidates existing tokens)"
			php bin/console lexik:jwt:generate-keypair --overwrite --no-interaction || true
		fi
	fi

	# --- Wait for the database ----------------------------------------------
	if grep -q DATABASE_URL .env.local.php 2>/dev/null || [ -n "$DATABASE_URL" ]; then
		echo "[entrypoint] waiting for database..."
		ATTEMPTS=0
		until php bin/console dbal:run-sql -q "SELECT 1" >/dev/null 2>&1; do
			ATTEMPTS=$((ATTEMPTS + 1))
			if [ "$ATTEMPTS" -ge 30 ]; then
				echo "[entrypoint] database not reachable after 30 tries — continuing anyway"
				break
			fi
			sleep 2
		done
	fi

	# --- Migrations (app container only) ------------------------------------
	if [ "${AUTO_MIGRATE:-0}" = "1" ]; then
		echo "[entrypoint] running doctrine migrations..."
		php bin/console doctrine:migrations:migrate --no-interaction --all-or-nothing --allow-no-migration

		# Idempotently provision the shared feedback board (platform workspace +
		# WTFB project + trackers/statuses). ORM-based + safe to re-run; kept out
		# of the migrations because it seeds trait-heavy entities via the ORM.
		echo "[entrypoint] bootstrapping feedback board..."
		php bin/console app:feedback:bootstrap --no-interaction || true
	fi

	# Warm the cache (idempotent, cheap when already warm)
	php bin/console cache:clear --no-warmup >/dev/null 2>&1 || true
	php bin/console cache:warmup >/dev/null 2>&1 || true

	setcap 'cap_net_bind_service=+ep' /usr/local/bin/frankenphp 2>/dev/null || true
	chown -R www-data:www-data var config/jwt 2>/dev/null || true
fi

exec docker-php-entrypoint "$@"
