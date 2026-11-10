#!/bin/sh
# Copies the PHP Supertext SDK from its GitHub repository into includes/Sdk, with its
# namespace changed from Supertext\Sdk to Supertext\Polylang\Sdk, so the plugin's own
# autoloader (Supertext\Polylang\ → includes/) loads it. The plugin ships this copy, so
# sites install nothing besides the plugin, and another plugin that ships the SDK itself
# can never clash with it.
#
#   scripts/vendor-sdk.sh [commit-or-branch]   (default: main)
#   SDK_DIR=/path/to/clone scripts/vendor-sdk.sh   (use a local clone as it is)
set -eu
REF="${1:-main}"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

if [ -n "${SDK_DIR:-}" ]; then
    SRC="$SDK_DIR"
else
    git clone --quiet https://github.com/Supertext/Supertext-API-SDK-Guide "$TMP/sdk"
    git -C "$TMP/sdk" checkout --quiet "$REF"
    SRC="$TMP/sdk"
fi
COMMIT="$(git -C "$SRC" rev-parse HEAD)"
VERSION="$(sed -n "s/.*SDK_VERSION = '\\([^']*\\)'.*/\\1/p" "$SRC/sdk/php/src/Supertext.php")"

DEST="$ROOT/includes/Sdk"
rm -rf "$DEST"
mkdir -p "$DEST"
cp -R "$SRC/sdk/php/src/." "$DEST/"
cp "$SRC/LICENSE" "$DEST/LICENSE"
# Every namespace, including the API version's sub-namespace (Supertext\Sdk\V1 → Supertext\Polylang\Sdk\V1),
# and class names written with doubled backslashes inside strings.
find "$DEST" -name '*.php' -exec sed -i 's/Supertext\\Sdk\\/Supertext\\Polylang\\Sdk\\/g; s/Supertext\\Sdk;/Supertext\\Polylang\\Sdk;/g; s/Supertext\\\\Sdk\\\\/Supertext\\\\Polylang\\\\Sdk\\\\/g' {} +
printf '%s\n' "$COMMIT" > "$DEST/COMMIT"
cat > "$DEST/README.md" <<EOT
# Supertext SDK (bundled copy)

PHP part of the [Supertext SDK](https://github.com/Supertext/Supertext-API-SDK-Guide) (MIT licence, see LICENSE),
version $VERSION, commit \`$COMMIT\`, namespace \`Supertext\\Polylang\\Sdk\` instead of \`Supertext\\Sdk\`
(the API classes are in \`Supertext\\Polylang\\Sdk\\V1\`, in \`V1/\`).

Don't edit these files: fix the SDK in its own repository, then run \`scripts/vendor-sdk.sh\`.
EOT
if grep -rqP 'Supertext(\\)+Sdk\b' "$DEST" --include='*.php'; then
    echo "vendor-sdk: unprefixed Supertext\\Sdk reference left in $DEST" >&2
    exit 1
fi
echo "includes/Sdk: Supertext SDK (PHP) $VERSION from commit $COMMIT"
