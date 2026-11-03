# Dependency Policy & Allowlist

This document defines the strict dependency policy for both the server (`/server`) and mobile application (`/app`).

The guiding principle is **minimal surface area, maximal longevity, and zero bloat**. Third-party packages introduce supply chain risks, maintenance burdens, compatibility churn across framework upgrades, and deployment friction on resource-constrained shared hosts.

---

## 1. Server (`/server`) Dependency Policy

The backend runs on commodity shared web hosting (cPanel, DirectAdmin) running PHP and SQLite. To ensure high portability and zero reliance on Composer execution on production servers, the backend enforces a **zero third-party runtime package policy**.

### 1.1 Runtime Composer Dependencies: Laravel Framework Only
- **Approved runtime packages**:
  - `php`: `^8.2 || ^8.3 || ^8.4 || ^8.5`
  - `laravel/framework`: The newest stable release supported by the target host's PHP version that receives active security fixes.
- **Strictly Prohibited**:
  - **No Telegram SDKs** (e.g., `irazasyed/telegram-bot-sdk`, `nutgram/nutgram`): Use Laravel's built-in `Illuminate\Support\Facades\Http` client and inline JSON webhook responses.
  - **No Sanctum or Passport**: The authentication model uses custom revocable bearer tokens (hash-matched against `devices.token_hash` in a lightweight middleware).
  - **No Spreadsheet / Excel packages** (e.g., `phpoffice/phpspreadsheet`, `maatwebsite/excel`): All tabular exports and imports are formatted as standard CSV using PHP's native `fputcsv()` and `fgetcsv()`.
  - **No JWT libraries**: Device tokens are high-entropy random strings hashed with SHA-256 in the database.
  - **No Queue or Cache drivers requiring daemons** (e.g., Redis, RabbitMQ): Queue uses the native `database` driver; cache uses `file` or `array` drivers.

### 1.2 Development Dependencies
Dev dependencies are strictly limited to what Laravel ships out of the box for automated unit and integration testing:
- `phpunit/phpunit`: Standard automated test harness.
- `mockery/mockery`: Object mocking for unit and integration testing.

### 1.3 Required PHP Extensions
The shared hosting PHP runtime must have the following native extensions enabled:
- `pdo_sqlite`: Required for the SQLite database engine.
- `mbstring`: Multibyte string processing (essential for Amharic Unicode script support).
- `openssl`: Cryptographic token generation, hashing, and HTTPS communication.
- `curl`: Outbound HTTP requests to `api.telegram.org`.
- `json`: JSON encoding and decoding for API wire payloads and Telegram webhooks.
- `ctype`: Character type checking required by framework utilities.
- `tokenizer`: Required for framework configuration caching and code reflection.
- `xml`: XML and DOM parsing.

---

## 2. App (`/app`) Allowlist & Justifications

The mobile application is an offline-first Flutter application. To prevent package bloat and ensure long-term stability across Flutter upgrades, only packages on this allowlist may be added to `pubspec.yaml`.

### 2.1 Allowlist

| Package | Category | Purpose & Justification |
|---|---|---|
| `drift` | Database | Reactive, type-safe SQLite database layer for offline-first local persistence and migration management. |
| `sqlite3_flutter_libs` | Database | Bundles the latest native SQLite C library binaries across all mobile platforms (Android & iOS) to avoid platform-specific SQLite bug variance. |
| `http` | Networking | Standard official HTTP client for dispatching sync batches, short-polling pairing requests, and network calls. |
| `flutter_secure_storage` | Security | Hardware-backed secure keystore/keychain storage for the device bearer token and sensitive credentials. |
| `local_auth` | Security | Biometric (fingerprint/face) and device PIN authentication to unlock the POS application on shared retail devices. |
| `flutter_local_notifications` | UX / Alerts | Offline local notification triggers for low-stock warnings, sync completion, and transaction summaries without requiring Firebase or push daemons. |
| `share_plus` | Utility | Native OS share sheet integration for exporting and sending CSV receipts/reports via Telegram, WhatsApp, or local file sharing. |
| `path_provider` | File System | Locating local device storage directories to house the SQLite database and exported reports. |
| `intl` | Localization | Date, time, currency formatting, and pluralization for Amharic (`am`) and English (`en`). |
| `uuid` | Identity | Client-side UUIDv7 generation for append-only movement records, products, and outbox mutations before synchronization. |
| `path` | File System | Platform-agnostic file path operations for locating and managing local SQLite database files. |
| `url_launcher` | Networking / UX | Securely launching Telegram deep link (`https://t.me/<bot>?start=pair_<code>`) during device pairing. |

### 2.2 Dev Tooling Justifications

| Package | Category | Justification |
|---|---|---|
| `flutter_test` | Dev / Testing | Official Flutter test framework (bundled with Flutter SDK). |
| `flutter_lints` | Dev / Quality | Official Dart/Flutter linting rules to enforce consistent idiomatic code quality. |
| `drift_dev` | Dev / Code Gen | Drift code generator that turns Dart table definitions and SQL queries into type-safe code at build time. |
| `build_runner` | Dev / Code Gen | Official Dart build tool required to execute `drift_dev` code generation. |

### 2.3 Policy for Proposing New Dependencies
Any dependency not explicitly listed above requires a formal written justification before inclusion:
1. What native platform capability or standard library function fails to solve this need?
2. What is the bundle size and transitive dependency impact?
3. What is the maintainer track record and compatibility timeline with new Flutter releases?
4. How is the package audited for security vulnerabilities?

---

## 3. Version Pinning & Upgrade Process

To guarantee reproducible, deterministic builds and prevent unintended breaking changes:

1. **Exact Version Pinning**: All dependencies in `composer.json` and `pubspec.yaml` must use pinned versions or tightly constrained semantic version ranges. Caret (`^`) constraints that allow uncontrolled major or minor shifts in critical builds must be avoided in release configurations.
2. **Lockfiles Committed**: Both `server/composer.lock` and `app/pubspec.lock` are strictly tracked in Git version control. Never add lockfiles to `.gitignore`.
3. **Diff Review Policy**: Every dependency upgrade must be executed in a dedicated Git commit with the full diff of the lockfile reviewed.
4. **Automated Vulnerability Scanning**:
   - `composer audit` runs in CI and on `make audit` (blocking on any reported advisory).
   - `osv-scanner` scans both `composer.lock` and `pubspec.lock` on every commit and PR.
