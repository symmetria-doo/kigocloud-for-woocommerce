#!/usr/bin/env bash
#
# Builds the wordpress.org variant of the plugin into build/kigocloud-for-woocommerce
# and build/kigocloud-for-woocommerce.zip.
#
# wordpress.org delivers the updates itself and does not allow a plugin to update
# from anywhere else, so this build drops what the GitHub release keeps:
#   - the vendor/plugin-update-checker folder (via .distignore),
#   - the "Update URI" header,
#   - the updater bootstrap between the wporg-strip markers in the main file.
# The GitHub release (.github/workflows/release.yml) is not affected.
#
# Needs: bash, rsync, zip, msgfmt (gettext). Usage: bin/build-wporg.sh [output dir]

set -euo pipefail

SLUG="kigocloud-for-woocommerce"
SRC="$(cd "$(dirname "$0")/.." && pwd)"
OUT="${1:-${SRC}/build}"
DEST="${OUT}/${SLUG}"
MAIN="${DEST}/${SLUG}.php"

rm -rf "${DEST}" "${OUT}/${SLUG}.zip"
mkdir -p "${DEST}"

rsync -a --exclude-from="${SRC}/.distignore" "${SRC}/" "${DEST}/"

find "${DEST}" -type d -exec chmod 755 {} +
find "${DEST}" -type f -exec chmod 644 {} +

sed -i '/^ \* Update URI:/d' "${MAIN}"
sed -i '/wporg-strip-start/,/wporg-strip-end/d' "${MAIN}"

# languages/*.mo is ignored by git; WordPress reads only the compiled files.
for po in "${DEST}"/languages/*.po; do
    msgfmt -o "${po%.po}.mo" "${po}"
done

# The build must not be able to update itself.
if grep -rniE 'plugin-update-checker|PucFactory|Update URI' "${DEST}" --include='*.php'; then
    echo "Updater code left in the wordpress.org build." >&2
    exit 1
fi
if [ -d "${DEST}/vendor" ]; then
    echo "vendor/ left in the wordpress.org build." >&2
    exit 1
fi

# Stable tag in readme.txt must match the plugin version.
VERSION="$(sed -n 's/^ \* Version:[[:space:]]*//p' "${MAIN}" | tr -d '\r')"
STABLE="$(sed -n 's/^Stable tag:[[:space:]]*//p' "${DEST}/readme.txt" | tr -d '\r')"
if [ "${VERSION}" != "${STABLE}" ]; then
    echo "Version ${VERSION} in the header, Stable tag ${STABLE} in readme.txt." >&2
    exit 1
fi
DEFINED="$(sed -n "s/^define( 'WOO_KIGOCLOUD_PLUGIN_NAME_VERSION', '\(.*\)' );/\1/p" "${MAIN}" | tr -d '\r')"
CONSTANT="$(sed -n "s/^[[:space:]]*const PLUGIN_VERSION = '\(.*\)';/\1/p" "${DEST}/includes/class-woo-kigocloud.php" | tr -d '\r')"
if [ "${VERSION}" != "${DEFINED}" ] || [ "${VERSION}" != "${CONSTANT}" ]; then
    echo "Version ${VERSION} in the header, ${DEFINED} in the define, ${CONSTANT} in Woo_KigoCloud::PLUGIN_VERSION." >&2
    exit 1
fi

if command -v php >/dev/null 2>&1; then
    find "${DEST}" -name '*.php' -print0 | xargs -0 -n1 php -l >/dev/null
fi

(cd "${OUT}" && zip -r -q "${SLUG}.zip" "${SLUG}")
echo "Built ${OUT}/${SLUG}.zip (version ${VERSION})"
