#!/usr/bin/env bash
#
# build-zip.sh — Build a distributable Magic Convert plugin zip.
#
# Reads the version from magic-convert.php and produces
# dist/magic-convert-<version>.zip. `.gitattributes` export-ignore rules strip
# dev-only files (tests/, docs/, .github/, bin/, etc.) from the archive.
#
# Production vs. dev autoloader (the important bit)
# ------------------------------------------------
# This repo COMMITS its production vendor/ packages (standard WordPress-plugin
# distribution convention) AND keeps the committed composer autoloader in the
# no-dev (production) state. A raw source install — a release zip OR a
# Composer/Bedrock `dev-master` checkout — therefore boots without a build step
# and never eager-requires a dev-only package that isn't shipped. This invariant
# is enforced in CI (.github/workflows/ci.yml boots the committed
# vendor/autoload.php with NO `composer install`).
#
# Dev packages (phpunit, nikic/php-parser, myclabs/deep-copy, …) are gitignored
# and never shipped. A local `composer install` pulls them in and regenerates the
# autoloader in its DEV form, so the tracked autoload_*.php files will show as
# modified afterwards — that is expected; do NOT commit them (CI rejects a
# dev-state committed autoloader). `composer test` / phpunit run from that
# post-install dev autoloader.
#
# This script still regenerates the no-dev autoloader TRANSIENTLY before
# archiving — so a build started after `composer install` is correct regardless
# of the working-tree state — and restores whatever autoloader it found on exit.
# The working tree is left exactly as it was found.
#
# Reproducibility: the archive source is HEAD (committed content) overlaid with
# the freshly generated no-dev autoloader. The build does NOT depend on any
# uncommitted working-tree changes other than the autoloader files it generates
# itself, so the artifact is reproducible from `git archive HEAD` + a no-dev
# `composer dump-autoload`.
#
# Correctness guard: the archived vendor/ tree is verified to be the production
# (no-dev) state. This script fails loudly if the archive is missing
# vendor/autoload.php, still contains the dev-only vendor/phpunit tree, or if the
# archived autoloader fails to load.
#
# Usage: bash bin/build-zip.sh   (or `composer build`)

set -euo pipefail

# Resolve repo root (this script lives in <root>/bin/).
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
cd "${ROOT_DIR}"

PLUGIN_FILE="magic-convert.php"

# Extract "Version: X.Y.Z" from the plugin header.
VERSION="$(grep -iE '^\s*\*?\s*Version:' "${PLUGIN_FILE}" | head -n1 | sed -E 's/.*Version:\s*//I' | tr -d '[:space:]')"
if [[ -z "${VERSION}" ]]; then
    echo "ERROR: could not read Version from ${PLUGIN_FILE}" >&2
    exit 1
fi

ZIP_PATH="dist/magic-convert-${VERSION}.zip"
mkdir -p dist

echo "Building ${ZIP_PATH} (version ${VERSION})..."

# The committed composer autoloader is already in the no-dev (production) state,
# but a build may be started after a local `composer install` has regenerated it
# in DEV form. Regenerate the no-dev autoloader transiently here and restore
# whatever autoloader was present on exit, no matter how the script terminates.
# Only the four generated autoloader files are touched; autoload_files.php
# (generated only when dev deps with file-autoload are present) is removed by
# --no-dev and restored too.
AUTOLOAD_FILES=(
    vendor/composer/autoload_classmap.php
    vendor/composer/autoload_psr4.php
    vendor/composer/autoload_real.php
    vendor/composer/autoload_static.php
    vendor/composer/autoload_files.php
)

# Snapshot the current autoloader so it can be restored verbatim. We use a git
# checkout for tracked files (exact committed bytes) and physically stash any
# untracked-but-present file (autoload_files.php) so nothing is lost.
RESTORE_DIR="$(mktemp -d)"
for f in "${AUTOLOAD_FILES[@]}"; do
    if [[ -e "${f}" ]]; then
        mkdir -p "${RESTORE_DIR}/$(dirname "${f}")"
        cp -p "${f}" "${RESTORE_DIR}/${f}"
    fi
done

restore_autoloader() {
    # Restore the autoloader exactly as it was found before the build.
    for f in "${AUTOLOAD_FILES[@]}"; do
        if [[ -e "${RESTORE_DIR}/${f}" ]]; then
            cp -p "${RESTORE_DIR}/${f}" "${f}"
        else
            # File did not exist before the build (e.g. autoload_files.php under
            # --no-dev); make sure a build-time-generated copy doesn't linger.
            rm -f "${f}"
        fi
    done
    rm -rf "${RESTORE_DIR}"
}

# Explicit XXXXXX template (not `-t prefix`): portable across GNU and BSD mktemp.
TMP_ZIP="$(mktemp "${TMPDIR:-/tmp}/magic-convert-build.XXXXXX")"
VERIFY_DIR="$(mktemp -d)"
# Clean up the temp artifacts AND always restore the autoloader as it was found.
trap 'rm -f "${TMP_ZIP}"; rm -rf "${VERIFY_DIR}"; restore_autoloader' EXIT

# Regenerate the production (no-dev) autoloader in place. --optimize builds an
# authoritative classmap; --no-dev drops phpunit and friends from the autoloader.
echo "Generating production (no-dev) autoloader..."
composer dump-autoload --no-dev --optimize --quiet

# Archive HEAD (committed source) but OVERLAY the freshly generated no-dev
# autoloader so the produced source is reproducible: it is exactly the committed
# tree with only the autoloader swapped to its production form. `git stash create`
# captures the current tracked working tree (HEAD + our regenerated autoloader)
# as a throwaway commit without touching HEAD, the branch, or the stash list.
# When the working tree is otherwise clean it prints nothing, so we fall back to
# HEAD. Because the ONLY tracked working-tree delta during the build is the
# autoloader we just generated, the archived source == HEAD + no-dev autoloader.
ARCHIVE_REF="$(git stash create || true)"
ARCHIVE_REF="${ARCHIVE_REF:-HEAD}"

# --worktree-attributes makes git archive honor the working-tree .gitattributes
# (export-ignore rules), so the build is correct whether or not .gitattributes
# has been committed yet. --format=zip is explicit because git otherwise infers
# the format from the output filename's extension, and the temp path is not .zip.
git archive --format=zip --worktree-attributes --prefix=magic-convert/ -o "${TMP_ZIP}" "${ARCHIVE_REF}"

# --- Correctness checks: verify the archived vendor/ is production state -------
CONTENTS="$(unzip -Z1 "${TMP_ZIP}")"

if ! grep -qx 'magic-convert/vendor/autoload.php' <<<"${CONTENTS}"; then
    echo "ERROR: ${ZIP_PATH} is missing vendor/autoload.php — vendor/ not committed?" >&2
    exit 1
fi

if grep -q '^magic-convert/vendor/phpunit/' <<<"${CONTENTS}"; then
    echo "ERROR: ${ZIP_PATH} contains vendor/phpunit — archived vendor/ is in DEV state." >&2
    exit 1
fi

if grep -q '^magic-convert/vendor/bin/' <<<"${CONTENTS}"; then
    echo "ERROR: ${ZIP_PATH} contains vendor/bin — archived vendor/ is in DEV state." >&2
    exit 1
fi

# Deepest check: extract the archived tree and actually LOAD vendor/autoload.php.
# A dev autoloader hard-codes PSR-4 / file-autoload entries for dev-only packages
# (e.g. nikic/php-parser, myclabs/deep-copy via require-dev/phpunit). Those
# packages are gitignored and absent from the zip, so requiring such an
# autoloader fatals at runtime even though vendor/phpunit itself is excluded.
# Fail loudly here so a broken release can never ship.
unzip -q "${TMP_ZIP}" -d "${VERIFY_DIR}"
if ! php -d display_errors=1 -r 'require $argv[1]."/magic-convert/vendor/autoload.php";' "${VERIFY_DIR}" 2>"${VERIFY_DIR}/err.log"; then
    echo "ERROR: archived vendor/autoload.php fails to load — autoloader is NOT in production (no-dev) state." >&2
    sed 's/^/       /' "${VERIFY_DIR}/err.log" >&2 || true
    exit 1
fi

# All checks passed — only NOW publish the artifact into dist/. Building to a
# temp path and moving on success guarantees a failed build never leaves a broken
# zip in dist/.
mv -f "${TMP_ZIP}" "${ZIP_PATH}"

SIZE="$(du -h "${ZIP_PATH}" | cut -f1)"
echo "OK: built ${ZIP_PATH} (${SIZE})"
