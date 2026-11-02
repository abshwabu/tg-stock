.PHONY: all test test-server test-app audit audit-composer audit-osv analyze build-zip clean help

all: test audit

help:
	@echo "Available commands:"
	@echo "  make test          - Run both server (PHPUnit) and mobile app (Flutter) tests"
	@echo "  make test-server   - Run PHPUnit tests in /server"
	@echo "  make test-app      - Run Flutter tests in /app"
	@echo "  make audit         - Run composer validate, composer audit, and dependency scans"
	@echo "  make analyze       - Run static analysis on /server and /app"
	@echo "  make build-zip     - Package production server zip for shared hosting deployment"
	@echo "  make clean         - Remove build artifacts and temporary caches"

test: test-server test-app

test-server:
	@echo "==> Running server tests (PHPUnit)..."
	cd server && ./vendor/bin/phpunit

test-app:
	@echo "==> Running app tests (Flutter)..."
	cd app && flutter test

audit: audit-composer audit-osv analyze

audit-composer:
	@echo "==> Running composer validate --strict..."
	cd server && composer validate --strict
	@echo "==> Running composer audit (blocking)..."
	cd server && composer audit

audit-osv:
	@echo "==> Checking dependencies with osv-scanner..."
	@if command -v osv-scanner >/dev/null 2>&1; then \
		osv-scanner --lockfile=server/composer.lock --lockfile=app/pubspec.lock; \
	else \
		echo "[NOTICE] osv-scanner not found in PATH; skipping local osv scan (runs in CI)."; \
	fi

analyze:
	@echo "==> Running Flutter analyze..."
	cd app && flutter analyze

build-zip:
	@echo "==> Building deployable zip for shared hosting..."
	./scripts/build-zip.sh

clean:
	rm -rf dist/
	rm -rf app/build/
	rm -f server/.phpunit.result.cache
