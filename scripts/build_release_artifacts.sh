#!/usr/bin/env bash
set -euo pipefail

# ==============================================================================
# Build Signed Release Artifacts & Generate Checksums
# Produces:
#  - app-release.aab (Play Store App Bundle)
#  - app-arm64-v8a-release.apk (Modern 64-bit phones)
#  - app-armeabi-v7a-release.apk (Legacy 32-bit phones)
#  - app-release.apk (Universal APK for sideloading)
#  - app-symbols.zip (R8 & Flutter obfuscation mapping symbols)
#  - SHA256SUMS.txt (Cryptographic integrity hashes)
# ==============================================================================

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
APP_DIR="${PROJECT_ROOT}/app"
DIST_DIR="${PROJECT_ROOT}/dist/release"
SYMBOLS_DIR="${APP_DIR}/build/symbols"

if [[ ! -f "${APP_DIR}/android/key.properties" ]]; then
  echo "Missing app/android/key.properties; release artifacts require an upload keystore." >&2
  exit 1
fi

VERSION_NAME="${1:-${VERSION_NAME:-1.0.0}}"
BUILD_NUMBER="${2:-${BUILD_NUMBER:-1}}"
VERSION_FLAGS=("--dart-define=APP_VERSION=${VERSION_NAME}" "--dart-define=APP_BUILD_NUMBER=${BUILD_NUMBER}")

echo "=============================================================================="
echo "🚀 Building Release Artifacts: Version ${VERSION_NAME} (Build ${BUILD_NUMBER})"
echo "=============================================================================="

rm -rf "${DIST_DIR}" "${SYMBOLS_DIR}"
mkdir -p "${DIST_DIR}" "${SYMBOLS_DIR}"

cd "${APP_DIR}"

# 1. Clean build cache
echo "🧹 Cleaning previous build..."
flutter clean
flutter pub get

# 2. Build Release Android App Bundle (AAB) with R8 obfuscation & debug symbol split
echo "📦 Building Release AAB..."
flutter build appbundle \
  --release \
  --obfuscate \
  --split-debug-info="${SYMBOLS_DIR}" \
  --build-name="${VERSION_NAME}" \
  --build-number="${BUILD_NUMBER}" \
  "${VERSION_FLAGS[@]}"

cp build/app/outputs/bundle/release/app-release.aab "${DIST_DIR}/app-release.aab"

# 3. Build Per-ABI Release APKs (arm64-v8a and armeabi-v7a)
echo "📱 Building Split Per-ABI Release APKs..."
flutter build apk \
  --release \
  --split-per-abi \
  --obfuscate \
  --split-debug-info="${SYMBOLS_DIR}" \
  --build-name="${VERSION_NAME}" \
  --build-number="${BUILD_NUMBER}" \
  "${VERSION_FLAGS[@]}"

cp build/app/outputs/flutter-apk/app-arm64-v8a-release.apk "${DIST_DIR}/app-arm64-v8a-release.apk"
cp build/app/outputs/flutter-apk/app-armeabi-v7a-release.apk "${DIST_DIR}/app-armeabi-v7a-release.apk"

# 4. Build Universal Release APK (contains all ABIs for universal offline distribution)
echo "🌐 Building Universal Release APK..."
flutter build apk \
  --release \
  --obfuscate \
  --split-debug-info="${SYMBOLS_DIR}" \
  --build-name="${VERSION_NAME}" \
  --build-number="${BUILD_NUMBER}" \
  "${VERSION_FLAGS[@]}"

cp build/app/outputs/flutter-apk/app-release.apk "${DIST_DIR}/app-release.apk"

# 5. Archive Obfuscation Debug Symbols
echo "🔒 Archiving Obfuscation Debug Symbols..."
cd "${SYMBOLS_DIR}"
zip -r "${DIST_DIR}/app-symbols-v${VERSION_NAME}-${BUILD_NUMBER}.zip" .
cd "${PROJECT_ROOT}"

# 6. Compute Cryptographic Checksums (SHA-256)
echo "🔐 Generating SHA-256 Checksums..."
cd "${DIST_DIR}"
sha256sum app-arm64-v8a-release.apk app-armeabi-v7a-release.apk app-release.apk app-release.aab > SHA256SUMS.txt
cd "${PROJECT_ROOT}"

# 7. Verify Size Threshold (arm64 must be strictly < 25 MB)
ARM64_BYTES=$(stat -c%s "${DIST_DIR}/app-arm64-v8a-release.apk")
MAX_BYTES=25000000
ARM64_MB=$(echo "scale=2; ${ARM64_BYTES} / (1024 * 1024)" | bc)

echo "=============================================================================="
echo "📊 Release Artifacts Summary:"
echo "------------------------------------------------------------------------------"
ls -lh "${DIST_DIR}"
echo "------------------------------------------------------------------------------"
echo "arm64-v8a APK size: ${ARM64_MB} MB (${ARM64_BYTES} bytes)"
if [ "${ARM64_BYTES}" -lt "${MAX_BYTES}" ]; then
  echo "✅ SIZE CHECK PASSED: arm64 APK is under 25 MB."
else
  echo "❌ SIZE CHECK FAILED: arm64 APK exceeds 25 MB threshold!"
  exit 1
fi
python3 "${PROJECT_ROOT}/scripts/report_apk_components.py" "${DIST_DIR}/app-arm64-v8a-release.apk"
cp "${DIST_DIR}/SHA256SUMS.txt" "${PROJECT_ROOT}/server/public/app/SHA256SUMS.txt"
echo "=============================================================================="
cat "${DIST_DIR}/SHA256SUMS.txt"
echo "=============================================================================="
echo "🎉 Artifact generation complete: ${DIST_DIR}"
