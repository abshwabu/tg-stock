#!/bin/bash
set -e

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" >/dev/null 2>&1 && pwd)"
cd "$DIR"

SODIUM_OPT=""
if ! php -r "exit(extension_loaded('sodium') ? 0 : 1);" 2>/dev/null; then
    if [ -f "../scripts/ext/sodium.so" ]; then
        SODIUM_OPT="-d extension=../scripts/ext/sodium.so"
    fi
fi

php $SODIUM_OPT ./vendor/bin/phpunit "$@"
