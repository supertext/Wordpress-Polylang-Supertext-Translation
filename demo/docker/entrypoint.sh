#!/bin/sh
# Demo start: database, wp-config.php, WordPress install, Polylang, demo accounts and
# content, then Apache. Safe to run on every start (each step checks what's there).
set -e
cd /var/www/html
WP="wp --allow-root --path=/var/www/html"

# Railway's MySQL service exposes its connection as separate variables.
if [ -z "$DATABASE_URL" ] && [ -n "$MYSQLHOST" ]; then
    export DATABASE_URL="mysql://${MYSQLUSER:-root}:${MYSQLPASSWORD:-}@${MYSQLHOST}:${MYSQLPORT:-3306}/${MYSQLDATABASE:-wordpress}"
fi
if [ -z "$DATABASE_URL" ]; then
    echo "DATABASE_URL (or MYSQLHOST etc.) is not set" >&2
    exit 1
fi
if [ -z "$DEMO_ADMIN_EMAIL" ] || [ -z "$DEMO_ADMIN_PASSWORD" ]; then
    echo "DEMO_ADMIN_EMAIL and DEMO_ADMIN_PASSWORD must be set" >&2
    exit 1
fi

# Split the URL with PHP (handles URL-encoded passwords).
eval "$(php -r '
$u = parse_url(getenv("DATABASE_URL"));
$db = getenv("WORDPRESS_DB_NAME") ?: ltrim($u["path"] ?? "", "/") ?: "wordpress";
foreach (["DB_HOST" => ($u["host"] ?? "") . ":" . ($u["port"] ?? 3306), "DB_USER" => urldecode($u["user"] ?? ""), "DB_PASS" => urldecode($u["pass"] ?? ""), "DB_NAME" => $db] as $k => $v) {
    echo $k, "=", escapeshellarg($v), "\n";
}')"

# Apache's "Listen" uses the PORT variable; it must be visible to Apache.
echo "export PORT=${PORT:-8080}" >> /etc/apache2/envvars

echo "Waiting for the database..."
i=0
until php -r '$u = parse_url(getenv("DATABASE_URL")); new PDO(sprintf("mysql:host=%s;port=%d", $u["host"], $u["port"] ?? 3306), urldecode($u["user"] ?? ""), urldecode($u["pass"] ?? ""));' >/dev/null 2>&1; do
    i=$((i + 1))
    if [ "$i" -ge 60 ]; then
        echo "Database not reachable after 120 s" >&2
        exit 1
    fi
    sleep 2
done
# Several demos share one MySQL server: each gets its own database.
DB_NAME="$DB_NAME" php -r '$u = parse_url(getenv("DATABASE_URL")); $p = new PDO(sprintf("mysql:host=%s;port=%d", $u["host"], $u["port"] ?? 3306), urldecode($u["user"] ?? ""), urldecode($u["pass"] ?? "")); $p->exec("CREATE DATABASE IF NOT EXISTS `" . str_replace("`", "", getenv("DB_NAME")) . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");'

# wp-config.php is rebuilt on every start from the variables. The salts are derived from
# DATABASE_URL so logins survive redeploys (override with WORDPRESS_SALT).
rm -f wp-config.php
$WP config create --dbname="$DB_NAME" --dbuser="$DB_USER" --dbpass="$DB_PASS" --dbhost="$DB_HOST" \
    --skip-check --skip-salts --extra-php <<'PHP'
require '/usr/local/share/wordpress-demo/wp-config-extra.php';
PHP
SALT_SEED="${WORDPRESS_SALT:-$DATABASE_URL}"
for key in AUTH_KEY SECURE_AUTH_KEY LOGGED_IN_KEY NONCE_KEY AUTH_SALT SECURE_AUTH_SALT LOGGED_IN_SALT NONCE_SALT; do
    $WP config set "$key" "$(printf '%s:%s' "$SALT_SEED" "$key" | sha256sum | cut -d' ' -f1)" --quiet
done

SITE_URL="${DEMO_SITE_URL:-}"
[ -z "$SITE_URL" ] && [ -n "$RAILWAY_PUBLIC_DOMAIN" ] && SITE_URL="https://$RAILWAY_PUBLIC_DOMAIN"
[ -z "$SITE_URL" ] && SITE_URL="http://localhost:${PORT:-8080}"

if ! $WP core is-installed 2>/dev/null; then
    $WP core install --url="$SITE_URL" --title="Supertext Demo (WordPress + Polylang)" \
        --admin_user=admin --admin_email="$DEMO_ADMIN_EMAIL" --admin_password="$DEMO_ADMIN_PASSWORD" --skip-email
fi
$WP option update siteurl "$SITE_URL" --quiet
$WP option update home "$SITE_URL" --quiet

# Pretty permalinks (Polylang puts the language in the URL).
$WP rewrite structure '/%postname%/' --quiet
cat > .htaccess <<'HT'
# BEGIN WordPress
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
RewriteBase /
RewriteRule ^index\.php$ - [L]
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule . /index.php [L]
</IfModule>
# END WordPress
HT

# Optional: Polylang Pro (paid) from a private URL. Without it, free Polylang runs and the
# plugin shows its "requires Polylang Pro" notice for the translation services.
if [ -n "$POLYLANG_PRO_ZIP_URL" ] && [ ! -d wp-content/plugins/polylang-pro ]; then
    echo "Installing Polylang Pro..."
    if curl -fsSL -o /tmp/polylang-pro.zip "$POLYLANG_PRO_ZIP_URL"; then
        unzip -q -o /tmp/polylang-pro.zip -d wp-content/plugins && rm -f /tmp/polylang-pro.zip
    else
        echo "Could not download POLYLANG_PRO_ZIP_URL, staying on free Polylang" >&2
    fi
fi
if [ -d wp-content/plugins/polylang-pro ]; then
    $WP plugin deactivate polylang --quiet 2>/dev/null || true
    $WP plugin activate polylang-pro --quiet
else
    $WP plugin activate polylang --quiet
fi
$WP plugin activate supertext-polylang --quiet

# Languages, accounts, settings and sample content.
$WP eval-file /usr/local/share/wordpress-demo/setup.php

# Language packs for the back end in the demo languages (best effort, needs internet).
for locale in de_DE fr_FR it_IT; do
    $WP language core install "$locale" --quiet 2>/dev/null || true
    $WP language plugin install polylang "$locale" --quiet 2>/dev/null || true
done

chown -R www-data:www-data /var/www/html

# Exactly one Apache MPM: mod_php needs prefork. On some hosts (Railway) another MPM
# ends up enabled, and Apache refuses to start with "More than one MPM loaded".
rm -f /etc/apache2/mods-enabled/mpm_event.* /etc/apache2/mods-enabled/mpm_worker.*
[ -e /etc/apache2/mods-enabled/mpm_prefork.load ] || a2enmod -q mpm_prefork

echo "WordPress demo ready at $SITE_URL"
exec apache2-foreground
