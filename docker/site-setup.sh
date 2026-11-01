#!/bin/sh
#
# Sets up the local sites (`make site-setup`, also run by `make up`). Safe to run again: every step checks first
# or overwrites with the same value, so it works on a fresh site volume and on an existing one.
#
# SITE_KIND=single (default, the wp-cli service) sets up the single site; SITE_KIND=network (the wp-cli-ms service) sets
# up the multisite: a subdirectory network with two sub-sites. Runs in the wp-cli container, in /var/www/html. Settings
# go into wp-config.php with `wp config set`, because the image only writes that file once, and so WP-CLI sees the same
# constants as the web server.

set -eu

SITE_KIND="${SITE_KIND:-single}"
case "$SITE_KIND" in
	single)
		SITE_URL="${SITE_URL:-http://localhost:8080}"
		REDIS_PREFIX='wpun-single:'
		NET=''
		;;
	network)
		SITE_URL="${SITE_URL:-http://localhost:8081}"
		REDIS_PREFIX='wpun-network:'
		NET='--network'
		;;
	*)
		echo "SITE_KIND must be single or network, not $SITE_KIND." >&2
		exit 1
		;;
esac

# On a fresh volume the wordpress container's entrypoint copies WordPress in and writes wp-config.php. Wait for
# that and for the database. The multisite's database may not exist yet (a site-db volume from before it was added;
# a new one gets it from docker/site/db-init.sql): wp db create makes it.
tries=0
until [ -f wp-config.php ] && { wp db query 'SELECT 1' >/dev/null 2>&1 || { [ "$SITE_KIND" = network ] && wp db create >/dev/null 2>&1; }; }; do
	tries=$((tries + 1))
	if [ "$tries" -gt 90 ]; then
		echo 'The site is not ready: no wp-config.php or no database after 90 s.' >&2
		exit 1
	fi
	sleep 1
done

if [ "$SITE_KIND" = network ]; then
	SITE_DOMAIN="${SITE_URL#http://}"
	if ! wp core is-installed --network 2>/dev/null; then
		wp core multisite-install --url="$SITE_URL" --title='Test Network' --admin_user=admin --admin_password=admin \
			--admin_email=a@example.com --skip-email
	elif [ "$(wp config get DOMAIN_CURRENT_SITE)" != "$SITE_DOMAIN" ]; then
		# SITE_HOST changed (see docker-compose.yml). A network keeps its domain in wp-config.php and in the site,
		# blogs and options tables, so all of them move. Log in again afterwards: the cookies belong to the old host.
		old_domain="$(wp config get DOMAIN_CURRENT_SITE)"
		wp config set DOMAIN_CURRENT_SITE "$SITE_DOMAIN" --type=constant --quiet
		wp search-replace "$old_domain" "$SITE_DOMAIN" --network --all-tables --skip-columns=guid --quiet
		wp cache flush --quiet
		echo "Network moved to $SITE_URL"
	fi
	# wp core multisite-install leaves the single-site rewrite rules the image wrote; a subdirectory network needs
	# its own. Written every time, so the file is right whatever state it was in.
	cat >.htaccess <<'HTACCESS'
# BEGIN WordPress
RewriteEngine On
RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
RewriteBase /
RewriteRule ^index\.php$ - [L]

# add a trailing slash to /wp-admin
RewriteRule ^([_0-9a-zA-Z-]+/)?wp-admin$ $1wp-admin/ [R=301,L]

RewriteCond %{REQUEST_FILENAME} -f [OR]
RewriteCond %{REQUEST_FILENAME} -d
RewriteRule ^ - [L]
RewriteRule ^([_0-9a-zA-Z-]+/)?(wp-(content|admin|includes).*) $2 [L]
RewriteRule ^([_0-9a-zA-Z-]+/)?(.*\.php)$ $2 [L]
RewriteRule . index.php [L]
# END WordPress
HTACCESS
elif ! wp core is-installed 2>/dev/null; then
	wp core install --url="$SITE_URL" --title=Test --admin_user=admin --admin_password=admin \
		--admin_email=a@example.com --skip-email
elif [ "$(wp option get home)" != "$SITE_URL" ]; then
	# SITE_HOST changed (see docker-compose.yml). Log in again afterwards: the cookies belong to the old host.
	wp option update home "$SITE_URL" --quiet
	wp option update siteurl "$SITE_URL" --quiet
	echo "Site URL changed to $SITE_URL"
fi

# SITE_MODE=dast (tests/dast/compose.yml, `make dast`) sets up the site the scanner will attack: production-like, with
# no debug output, no developer tools and no object cache, and only the pieces of the plugin the scan needs.
# SITE_MODE=e2e (tests/e2e/compose.yml, `make a11y`) sets up the same, for the browser checks: debugging output and
# developer tools such as Query Monitor would add markup the checks should not see.
SITE_MODE="${SITE_MODE:-dev}"

case "$SITE_MODE" in dast | e2e) throwaway=yes ;; *) throwaway=no ;; esac

if [ "$SITE_KIND" = network ] && [ "$SITE_MODE" = dast ]; then
	echo 'The multisite is for local use and the flows (make e2e-ms) only: SITE_MODE must be dev or e2e.' >&2
	exit 1
fi

if [ "$throwaway" = yes ]; then
	wp config set WP_ENVIRONMENT_TYPE production --type=constant --quiet
	wp config set WP_DEBUG false --raw --type=constant --quiet
	# On the multisite (make e2e-ms) they are network-activated: $NET is empty on the single site, so the unquoted
	# expansion is meant.
	# shellcheck disable=SC2086
	{
		wp plugin is-active wp-updates-notifier $NET || wp plugin activate wp-updates-notifier $NET --quiet
		wp plugin is-active wpun-field-showcase $NET || wp plugin activate wpun-field-showcase $NET --quiet
	}
fi

if [ "$throwaway" = no ]; then
	# Debugging (#175). Errors show on the page and go to wp-content/debug.log (`make site-log`).
	wp config set WP_ENVIRONMENT_TYPE local --type=constant --quiet
	wp config set WP_DEBUG true --raw --type=constant --quiet
	wp config set WP_DEBUG_LOG true --raw --type=constant --quiet
	wp config set WP_DEBUG_DISPLAY true --raw --type=constant --quiet
	wp config set SCRIPT_DEBUG true --raw --type=constant --quiet
	wp config set SAVEQUERIES true --raw --type=constant --quiet
	wp config set WP_DEVELOPMENT_MODE plugin --type=constant --quiet

	# install_plugin <slug>: install the latest version unless it is there, then activate it (on the multisite, for
	# the whole network: $NET is empty on the single site, so the unquoted expansion is meant).
	# shellcheck disable=SC2086
	install_plugin() {
		wp plugin is-installed "$1" || wp plugin install "$1" --quiet
		wp plugin is-active "$1" $NET || wp plugin activate "$1" $NET --quiet
	}

	# shellcheck disable=SC2086
	{
		wp plugin is-active wp-updates-notifier $NET || wp plugin activate wp-updates-notifier $NET --quiet
		# The field showcase channel (#172): every settings field, for checking the channel forms by hand.
		wp plugin is-active wpun-field-showcase $NET || wp plugin activate wpun-field-showcase $NET --quiet
	}
	install_plugin query-monitor
	install_plugin debug-bar
	install_plugin wp-crontrol

	# Persistent object cache (#177): Redis Object Cache with phpredis, talking to the valkey service. The prefix keeps
	# this site's keys apart (wpun-single:, wpun-network:), so the local sites share the same Valkey. OBJECT_CACHE=off
	# removes the drop-in.
	wp config set WP_REDIS_HOST valkey --type=constant --quiet
	wp config set WP_REDIS_PORT 6379 --raw --type=constant --quiet
	wp config set WP_REDIS_PREFIX "$REDIS_PREFIX" --type=constant --quiet
	wp config set WP_REDIS_CLIENT phpredis --type=constant --quiet
	install_plugin redis-cache
	if [ "${OBJECT_CACHE:-on}" = off ]; then
		if [ -f wp-content/object-cache.php ]; then
			wp redis disable --quiet
		fi
		echo 'Persistent object cache: off'
	else
		# update-dropin writes the drop-in whether or not one is there, and keeps it current after a plugin update.
		was_on=no
		[ -f wp-content/object-cache.php ] && was_on=yes
		wp redis update-dropin --quiet
		if [ "$was_on" = no ]; then
			# Valkey still holds what it cached before the cache was switched off, and the database has changed since.
			wp cache flush --quiet
		fi
		echo 'Persistent object cache: on (Valkey)'
	fi
fi

# Outdated plugins and themes (#173), so an update check finds real updates. Each is put back at its pinned version
# when it differs, so running this again after updating one resets it. hello (bundled with WordPress) stays current
# as the control. hello-dolly is WordPress.org's copy of it: keep it inactive, the two can't be active together.
# The third argument of seed_plugin can also be keep: install only, leave the activation alone (the multisite).
seed_plugin() { # <slug> <version> <active|inactive>
	if [ "$(wp plugin get "$1" --field=version 2>/dev/null)" != "$2" ]; then
		wp plugin install "$1" --version="$2" --force --quiet
	fi
	if [ "$3" = active ]; then
		wp plugin is-active "$1" || wp plugin activate "$1" --quiet
	elif [ "$3" = inactive ] && wp plugin is-active "$1"; then
		wp plugin deactivate "$1" --quiet
	fi
}
seed_theme() { # <slug> <version>
	if [ "$(wp theme get "$1" --field=version 2>/dev/null)" != "$2" ]; then
		wp theme install "$1" --version="$2" --force --quiet
	fi
}

# The throwaway multisite (SITE_MODE=e2e, make e2e-ms) needs no more: tests/flows/seed.sh adds its sub-site and plugins.
if [ "$SITE_KIND" = network ] && [ "$throwaway" = yes ]; then
	echo "Throwaway multisite ready: $SITE_URL (admin / admin)"
	exit 0
fi

# The multisite (#176): the same outdated plugins and themes, installed once for the whole network, a main site and two
# sub-sites. Each site has its own theme and plugin, so "active only" across sites shows. The dev plugins above are
# network-activated; these are activated per site.
if [ "$SITE_KIND" = network ]; then
	seed_plugin akismet 5.6 keep
	seed_plugin classic-editor 1.6.7 keep
	seed_plugin hello-dolly 1.6 keep
	if ! wp plugin auto-updates status hello-dolly --enabled-only --field=name | grep -q hello-dolly; then
		wp plugin auto-updates enable hello-dolly --quiet
	fi
	seed_theme twentytwentyfour 1.3
	seed_theme twentytwentythree 1.5
	wp theme is-installed twentytwentyfive || wp theme install twentytwentyfive --quiet

	# site_setup <slug or empty for the main site> <title> <theme> <plugin>
	site_setup() {
		if [ -n "$1" ] && ! wp site list --field=path | grep -qx "/$1/"; then
			wp site create --slug="$1" --title="$2" --email=a@example.com --quiet
		fi
		url="$SITE_URL/${1:+$1/}"
		# A theme has to be enabled for the network before a site can use it.
		wp theme enable "$3" --network --quiet
		[ "$(wp option get stylesheet --url="$url")" = "$3" ] || wp theme activate "$3" --url="$url" --quiet
		wp plugin is-active "$4" --url="$url" || wp plugin activate "$4" --url="$url" --quiet
	}
	site_setup '' 'Test Network' twentytwentyfour akismet
	site_setup alpha Alpha twentytwentythree classic-editor
	site_setup beta Beta twentytwentyfive hello-dolly
	# The bundled Hello Dolly and the dummy plugin stay on the main site, where hello-dolly is not active.
	wp plugin is-active hello --url="$SITE_URL/" || wp plugin activate hello --url="$SITE_URL/" --quiet
	wp plugin is-installed wpun-dummy && { wp plugin is-active wpun-dummy --url="$SITE_URL/" || wp plugin activate wpun-dummy --url="$SITE_URL/" --quiet; }

	echo "Local multisite ready: $SITE_URL (admin / admin), sub-sites alpha and beta, network admin $SITE_URL/wp-admin/network/, mail in Mailpit on http://localhost:8025"
	exit 0
fi

# SITE_MODE=e2e skips these downloads from WordPress.org: the checks do not depend on pending updates, and without
# them the setup is faster and works offline apart from WordPress itself.
if [ "$SITE_MODE" != e2e ]; then
	seed_plugin akismet 5.6 active
	seed_plugin classic-editor 1.6.7 inactive
	seed_plugin hello-dolly 1.6 inactive
	if ! wp plugin auto-updates status hello-dolly --enabled-only --field=name | grep -q hello-dolly; then
		wp plugin auto-updates enable hello-dolly --quiet
	fi
fi
wp plugin is-active hello || wp plugin activate hello --quiet
wp plugin is-installed wpun-dummy && { wp plugin is-active wpun-dummy || wp plugin activate wpun-dummy --quiet; }
if [ "$SITE_MODE" != e2e ]; then
	seed_theme twentytwentyfour 1.3
	seed_theme twentytwentythree 1.5
	wp theme is-installed twentytwentyfive || wp theme install twentytwentyfive --quiet
	[ "$(wp option get stylesheet)" = twentytwentyfour ] || wp theme activate twentytwentyfour --quiet
fi

echo "Local site ready: $SITE_URL (admin / admin), mail in Mailpit on http://localhost:8025"
