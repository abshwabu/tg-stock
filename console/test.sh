#!/usr/bin/env bash
set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
EXT_FLAG=""

if ! php -r "exit(extension_loaded('sodium') ? 0 : 1);" 2>/dev/null; then
    SO_PATH="$(realpath "$SCRIPT_DIR/../scripts/ext/sodium.so" 2>/dev/null || true)"
    if [ -f "$SO_PATH" ]; then
        EXT_FLAG="-d extension=$SO_PATH"
    fi
fi

exec php $EXT_FLAG "$SCRIPT_DIR/vendor/bin/phpunit" -c "$SCRIPT_DIR/phpunit.xml" "$@"
