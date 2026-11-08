#!/bin/sh
# Copies the PHP Supertext Plugin Health (version reports to the Supertext Commando Center,
# the API key check result and the ping) from the Commando Center's GitHub repository into
# includes/PluginHealth, with its namespace changed from Supertext\PluginHealth to
# Supertext\Polylang\PluginHealth, so the plugin's own autoloader (Supertext\Polylang\ →
# includes/) loads it. The plugin ships this copy next to the Supertext SDK's (includes/Sdk),
# so sites install nothing besides the plugin, and another plugin that ships Plugin Health
# itself can never clash with it.
#
#   scripts/vendor-plugin-health.sh [commit-or-branch]   (default: main)
#   PLUGIN_HEALTH_DIR=/path/to/clone scripts/vendor-plugin-health.sh   (use a local clone as it is)
set -eu
REF="${1:-main}"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

if [ -n "${PLUGIN_HEALTH_DIR:-}" ]; then
    SRC="$PLUGIN_HEALTH_DIR"
else
    git clone --quiet https://github.com/supertext/Commando-Center-Supertext-Translation "$TMP/center"
    git -C "$TMP/center" checkout --quiet "$REF"
    SRC="$TMP/center"
fi
COMMIT="$(git -C "$SRC" rev-parse HEAD)"
VERSION="$(sed -n "s/.*PLUGIN_HEALTH_VERSION = '\\([^']*\\)'.*/\\1/p" "$SRC/plugin-health/php/src/PluginHealth.php")"

DEST="$ROOT/includes/PluginHealth"
rm -rf "$DEST"
mkdir -p "$DEST"
cp -R "$SRC/plugin-health/php/src/." "$DEST/"
cp "$SRC/LICENSE" "$DEST/LICENSE"
# Every namespace, including the sub-namespace (Supertext\PluginHealth\Http → Supertext\Polylang\PluginHealth\Http),
# and class names written with doubled backslashes inside strings.
find "$DEST" -name '*.php' -exec sed -i 's/Supertext\\PluginHealth\\/Supertext\\Polylang\\PluginHealth\\/g; s/Supertext\\PluginHealth;/Supertext\\Polylang\\PluginHealth;/g; s/Supertext\\\\PluginHealth\\\\/Supertext\\\\Polylang\\\\PluginHealth\\\\/g' {} +
printf '%s\n' "$COMMIT" > "$DEST/COMMIT"
printf '%s\n' "$VERSION" > "$DEST/VERSION"
cat > "$DEST/README.md" <<EOT
# Supertext Plugin Health (bundled copy)

PHP part of [Supertext Plugin Health](https://github.com/supertext/Commando-Center-Supertext-Translation/tree/main/plugin-health)
(MIT licence, see LICENSE), version $VERSION, commit \`$COMMIT\`, namespace \`Supertext\\Polylang\\PluginHealth\`
instead of \`Supertext\\PluginHealth\`.

Don't edit these files: fix it in Commando-Center-Supertext-Translation/plugin-health, then run
\`scripts/vendor-plugin-health.sh\`.
EOT
if grep -rqP 'Supertext(\\)+PluginHealth\b' "$DEST" --include='*.php'; then
    echo "vendor-plugin-health: unprefixed Supertext\\PluginHealth reference left in $DEST" >&2
    exit 1
fi
echo "includes/PluginHealth: Supertext Plugin Health (PHP) $VERSION from commit $COMMIT"
