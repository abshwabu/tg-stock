# Inventory (የዕቃ ክምችት)

An offline-first inventory management system designed for small retail shops and kiosks in Ethiopia, featuring bilingual support (Amharic & English), ETB currency tracking, and optional multi-device cloud synchronization with a Telegram bot interface.

---

## 1. System Architecture Summary

The repository is organized as a monorepo containing:
- **Mobile App (`/app`)**: Offline-first Flutter client using local SQLite (via Drift). All sales, inventory checks, and stock receipts happen instantly offline.
- **Backend Server (`/server`)**: Lightweight Laravel application utilizing SQLite on commodity shared hosting (cPanel/DirectAdmin). Operates with short-lived requests, cron scheduling, and Telegram bot webhooks.

### Key Architecture Contracts
- **Ledger Invariant**: Products are mutable (`last-write-wins`), but movements are strictly **append-only** with signed quantities and client-generated UUIDv7 IDs. Current stock is always calculated as $\text{Stock} = \sum(\text{movements})$. Sync **never** overwrites stock balances.
- **Sync Model**: Client outbox pushes pending movements; server returns changes past a cursor. All writes are idempotent.
- **Identity**: The shop owner's Telegram account is the root identity. Devices pair via short-lived numeric codes and receive revocable bearer tokens. No passwords, SMS OTPs, or email accounts are needed.
- **Multi-Tenancy**: Single SQLite database file with centrally enforced `shop_id` scoping on every row.
- **Canonical Wire Format**:
  - **Money**: Integer cents (`*_cents`), where 1 ETB = 100 santim.
  - **Quantity**: 3-decimal fixed strings (`"2.500"`), stored server-side as integer thousandths.
  - **Timestamps**: UTC epoch milliseconds integer.
  - **IDs**: UUIDv7 strings.

For in-depth architectural specifications, see [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md).  
For the strict dependency allowlist and policy, see [docs/DEPENDENCIES.md](docs/DEPENDENCIES.md).  
For shared hosting environment checks, see [docs/HOST_CHECKLIST.md](docs/HOST_CHECKLIST.md).

---

## 2. Monorepo Structure

```
├── .github/
│   └── workflows/
│       └── ci.yml             # Strict CI (Composer validate/audit, PHPUnit, Flutter test/analyze, OSV-Scanner, Release zip)
├── app/                       # Flutter offline-first mobile application
│   ├── lib/                   # Application source code
│   ├── test/                  # Automated widget and unit tests
│   ├── pubspec.yaml           # Pinned allowlist dependencies
│   └── pubspec.lock           # Locked dependency tree (tracked in Git)
├── docs/
│   ├── ARCHITECTURE.md        # Architecture contract & protocol specification
│   ├── DEPENDENCIES.md        # Strict dependency allowlist & update policy
│   └── HOST_CHECKLIST.md      # Shared hosting pre-flight checklist & verification
├── scripts/
│   ├── build-zip.sh           # Builds standalone production server zip (vendor included)
│   └── host-check.php         # Standalone shared-hosting diagnostic CLI/web script
├── server/                    # Laravel backend API & Telegram webhook server
│   ├── app/                   # Controllers, middleware, scopes, models
│   ├── composer.json          # Zero extra runtime packages policy (Laravel framework only)
│   ├── composer.lock          # Locked dependency tree (tracked in Git)
│   ├── database/              # SQLite database and migrations
│   └── tests/                 # PHPUnit unit and feature tests
├── Makefile                   # Developer commands (make test, make audit, make build-zip)
└── README.md                  # This file
```

---

## 3. Quickstart & Developer Workflow

### Prerequisites
- **PHP**: `^8.2`, `^8.3`, `^8.4`, or `^8.5` with extensions: `pdo_sqlite`, `mbstring`, `openssl`, `curl`, `json`, `ctype`, `tokenizer`, `xml`
- **Composer**: `^2.2+`
- **Flutter SDK**: `^3.24+` with Dart `^3.5+`
- **Make** & **Zip** utilities

### Common Commands (`make`)

| Command | Action |
|---|---|
| `make test` | Run all automated tests (PHPUnit in `/server` and Flutter tests in `/app`) |
| `make test-server` | Run only PHPUnit tests in `/server` |
| `make test-app` | Run only Flutter tests in `/app` |
| `make audit` | Run strict composer validation, blocking composer security audit, dependency scan, and Flutter analysis |
| `make analyze` | Run static analysis across projects |
| `make build-zip` | Package standalone `dist/inventory-server.zip` ready for shared hosting deployment |
| `make clean` | Clean temporary build directories and caches |

---

## 4. Server Deployment to Shared Hosting

Because the server runs on shared hosting (cPanel/DirectAdmin) without root access or daemons:
1. Run `make build-zip` locally or download `inventory-server.zip` from the GitHub Actions release artifacts.
2. Upload and extract the zip archive into your host's application folder (e.g., `/home/username/inventory`).
3. Set your domain or subdomain Document Root to point to `/home/username/inventory/public`.
4. Copy `.env.example` to `.env` on the host, configure `APP_KEY`, and set your Telegram Bot token:
   ```env
   APP_ENV=production
   APP_DEBUG=false
   APP_URL=https://inventory.yourshop.et
   DB_CONNECTION=sqlite
   DB_DATABASE=/home/username/inventory/database/database.sqlite
   QUEUE_CONNECTION=database
   TELEGRAM_BOT_TOKEN=your_bot_token_here
   TELEGRAM_WEBHOOK_SECRET=your_random_secret_here
   ```
5. In cPanel **Cron Jobs**, configure a single cron entry to execute every 5 minutes:
   ```bash
   */5 * * * * /usr/local/bin/php /home/username/inventory/artisan schedule:run >> /dev/null 2>&1
   ```
6. Verify your hosting setup at any time by executing:
   ```bash
   php scripts/host-check.php
   ```

---

## 5. Mobile App Development

To run the Flutter app locally:

```bash
cd app
flutter pub get
flutter run
```

To run tests and lints:

```bash
cd app
flutter analyze
flutter test
```
