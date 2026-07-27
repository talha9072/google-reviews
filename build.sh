#!/usr/bin/env bash
#
# Build the plugin zip you give to clients.
#
#   ./build.sh
#
# Produces build/google-reviews-<version>.zip containing only what a customer
# needs. The connect service, tests, tooling and dev files are excluded — the
# Google client secret must never leave your server.

set -euo pipefail

PLUGIN_SLUG="google-reviews"
ROOT="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
BUILD="${ROOT}/build"
STAGE="${BUILD}/${PLUGIN_SLUG}"

VERSION="$( grep -m1 "^ \* Version:" "${ROOT}/${PLUGIN_SLUG}.php" | awk '{print $3}' )"

if [ -z "${VERSION}" ]; then
	echo "Could not read the version from ${PLUGIN_SLUG}.php" >&2
	exit 1
fi

# --- Refuse to ship a build that would not work -----------------------------

SERVICE_URL="$( grep -m1 "GBRW_CONNECT_SERVICE_DEFAULT" "${ROOT}/${PLUGIN_SLUG}.php" | sed -E "s/.*'([^']+)'[^']*$/\1/" )"

if echo "${SERVICE_URL}" | grep -q "example.com"; then
	echo "REFUSING TO BUILD: GBRW_CONNECT_SERVICE_DEFAULT is still the placeholder." >&2
	echo "Customers would install this and find the Connect button disabled." >&2
	exit 1
fi

if ! echo "${SERVICE_URL}" | grep -q "^https://"; then
	echo "REFUSING TO BUILD: the connect service URL must be HTTPS. Found: ${SERVICE_URL}" >&2
	exit 1
fi

echo "Version:         ${VERSION}"
echo "Connect service: ${SERVICE_URL}"
echo

rm -rf "${BUILD}"
mkdir -p "${STAGE}"

# --- Copy only what ships ---------------------------------------------------

for item in \
	"${PLUGIN_SLUG}.php" \
	uninstall.php \
	readme.txt \
	includes \
	assets \
	languages
do
	if [ -e "${ROOT}/${item}" ]; then
		cp -R "${ROOT}/${item}" "${STAGE}/"
	fi
done

# Admin build artefacts only; never the sources.
rm -rf "${STAGE}/assets/admin/src" 2>/dev/null || true

# --- Paranoia: make sure nothing sensitive slipped in ------------------------

if [ -d "${STAGE}/connect-service" ]; then
	echo "ABORTING: the connect service ended up inside the zip." >&2
	exit 1
fi

# Look for actual secret VALUES, not the field names. The plugin legitimately
# refers to "client_secret" as a form field, a request key, and a redaction
# target — none of which is a leak.
LEAKED=0

# Google client secrets are issued with this prefix.
if grep -rIl "GOCSPX-" "${STAGE}" 2>/dev/null | grep -q .; then
	echo "ABORTING: a Google client secret is inside the build." >&2
	grep -rIl "GOCSPX-" "${STAGE}" >&2
	LEAKED=1
fi

# The connect service's signing secret, if config.php were ever copied in.
if [ -f "${ROOT}/connect-service/config.php" ]; then
	STATE_SECRET="$( grep -m1 "state_secret" "${ROOT}/connect-service/config.php" | sed -E "s/.*'([^']{16,})'.*/\1/" )"

	if [ -n "${STATE_SECRET}" ] && grep -rIl "${STATE_SECRET}" "${STAGE}" 2>/dev/null | grep -q .; then
		echo "ABORTING: the connect service state secret is inside the build." >&2
		LEAKED=1
	fi
fi

if [ -f "${STAGE}/config.php" ]; then
	echo "ABORTING: a config.php ended up in the build." >&2
	LEAKED=1
fi

if [ "${LEAKED}" -ne 0 ]; then
	exit 1
fi

# --- Zip --------------------------------------------------------------------

ZIP="${BUILD}/${PLUGIN_SLUG}-${VERSION}.zip"

find "${STAGE}" -name ".DS_Store" -delete 2>/dev/null || true

if command -v zip >/dev/null 2>&1; then
	( cd "${BUILD}" && zip -rq "$( basename "${ZIP}" )" "${PLUGIN_SLUG}" )
elif command -v powershell >/dev/null 2>&1; then
	# Windows without the zip binary (Git Bash, Local by Flywheel, etc.).
	powershell -NoProfile -Command \
		"Compress-Archive -Path '$( cygpath -w "${STAGE}" 2>/dev/null || echo "${STAGE}" )' -DestinationPath '$( cygpath -w "${ZIP}" 2>/dev/null || echo "${ZIP}" )' -Force" >/dev/null
else
	echo "Neither 'zip' nor PowerShell is available to create the archive." >&2
	exit 1
fi

rm -rf "${STAGE}"

echo "Built: ${ZIP}"
echo "Size:  $( du -h "${ZIP}" | cut -f1 )"
echo
echo "Give this zip to clients. They install it, activate, and click Connect Google."
