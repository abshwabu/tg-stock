#!/usr/bin/env bash
set -euo pipefail

# build-zip.sh: Packages the Laravel server for shared hosting deployment.
# Runs composer install --no-dev --optimize-autoloader and generates inventory-server.zip.

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SERVER_DIR="${REPO_ROOT}/server"
DIST_DIR="${REPO_ROOT}/dist"
ZIP_NAME="inventory-server.zip"
TARGET_ZIP="${DIST_DIR}/${ZIP_NAME}"

echo "==> Preparing production build in ${SERVER_DIR}..."
mkdir -p "${DIST_DIR}"

# Step 1: Install production dependencies without dev packages
cd "${SERVER_DIR}"
echo "==> Running composer install --no-dev --optimize-autoloader..."
composer install --no-dev --optimize-autoloader --no-interaction

# Step 2: Ensure required runtime directories exist
echo "==> Ensuring storage and database directories exist..."
mkdir -p storage/app storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache database

# Create empty database sqlite placeholder if not exists
if [ ! -f "database/database.sqlite" ]; then
    touch database/database.sqlite
fi

# Step 3: Package into zip archive
echo "==> Packaging into ${TARGET_ZIP}..."
rm -f "${TARGET_ZIP}"

zip -r -q "${TARGET_ZIP}" . \
    -x ".env" \
    -x ".env.*" \
    -x ".git/*" \
    -x ".phpunit.cache/*" \
    -x ".phpunit.result.cache" \
    -x "tests/*" \
    -x "node_modules/*" \
    -x "storage/logs/*.log" \
    -x "*.DS_Store"

echo "==> Restoring development dependencies for local development..."
composer install --optimize-autoloader --no-interaction

echo "==> Build successful: ${TARGET_ZIP} ($(du -h "${TARGET_ZIP}" | cut -f1))"
