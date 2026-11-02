# Inventory Architecture Contract

## 1. System Overview & Context

**Inventory** is an offline-first inventory management system designed specifically for small retail shops and kiosks in Ethiopia.

- **Primary Currency**: Ethiopian Birr (ETB), divided into 100 santim (cents).
- **Languages**: Bilingual support for Amharic (`am`) and English (`en`).
- **Core Architecture**:
  1. **Mobile App (`/app`)**: Offline-first Flutter application using a local SQLite database (via Drift). All core retail operations (sales, stock intake, adjustments, inventory checks) operate completely offline.
  2. **Online Server (`/server`)**: Lightweight Laravel application using SQLite running on commodity shared hosting (cPanel/DirectAdmin). Provides optional multi-device synchronization and a Telegram bot interface for the shop owner.

The system is designed with a strict "offline-first, cloud-optional" philosophy: the shop functions normally with zero internet connectivity. When connectivity is present, devices sync idempotently with the server.

---

## 2. Ledger Model: Products & Movements

To avoid conflicting stock counts between devices operating offline, stock is modeled as an append-only transaction ledger rather than an editable scalar counter.

### 2.1 Products (`products`)
- **Nature**: Mutable entity representing catalog metadata (e.g., barcode, SKU, name in Amharic/English, category, unit, unit selling price in cents).
- **Conflict Resolution**: Last-Write-Wins (LWW) based on `updated_at` epoch millisecond timestamps.
- **Role**: Holds metadata only. Does **not** store an authoritative stock balance.

### 2.2 Movements (`movements`)
- **Nature**: Strictly append-only ledger entries recording all physical inventory adjustments.
- **Identifier**: Time-ordered UUIDv7 generated on the client at the instant the movement occurs.
- **Signed Quantity**:
  - Positive (`+`): Stock intake, purchase from supplier, returns accepted, upward stock adjustments.
  - Negative (`-`): Point-of-sale customer purchase, shrinkage/spoilage, damaged goods, downward stock adjustments.
- **Immutability**: Movement rows are never updated or deleted. Corrections are made by appending a compensating movement record referencing the original movement ID.

### 2.3 Stock Calculation
- **Authoritative Rule**:
  $$\text{Stock} = \sum(\text{movements})$$
- **Sync Invariant**: Sync operations **never** overwrite or replace product stock values. Devices upload newly recorded movements and download movements created by other devices. Each node locally computes current stock as the sum of recorded movements.
- **Convergence**: Because addition is commutative and associative ($A + B = B + A$), all nodes with the same set of movements converge to identical stock balances regardless of the order in which sync batches arrive.

---

## 3. Synchronization Protocol

The sync protocol operates between the Flutter client's local SQLite database and the shared-hosting Laravel server over standard short-lived HTTPS requests.

```
+-------------------+                          +--------------------+
|  Flutter Client   |                          |   Laravel Server   |
|   (Local SQLite)  |                          |  (Shared Hosting)  |
+-------------------+                          +--------------------+
          |                                              |
          |  1. POST /api/v1/sync/push                   |
          |     { client_id, outbox: [movements, ...] }  |
          |--------------------------------------------->|
          |                                              | 2. Upsert products (LWW)
          |                                              |    Insert movements (Ignore dups)
          |  3. 200 OK { acknowledged_ids: [...] }       |
          |<---------------------------------------------|
          |                                              |
          |  4. GET /api/v1/sync/pull?cursor={cursor}    |
          |--------------------------------------------->|
          |                                              | 5. Query changes > cursor
          |  6. 200 OK { changes: [...], next_cursor }   |
          |<---------------------------------------------|
          |                                              |
          | 7. Apply to local DB & update cursor         |
          v                                              v
```

### 3.1 Client Outbox
- All mutations performed on the mobile device are written in a local transaction to both the entity table (`movements` or `products`) and a local `sync_outbox` table.
- A background sync manager pulls pending items from the outbox and batches them to the server.

### 3.2 Push Phase (`POST /api/v1/sync/push`)
- The client sends a batch of uncommitted outbox entries with their client-generated UUIDv7 IDs.
- **Idempotency**: The server performs an idempotent insert (`INSERT OR IGNORE` or `upsert` keyed on the UUIDv7 `id`). If a movement ID already exists in the database, the server treats it as a duplicate, ignores the insert, and returns success.
- The server responds with the list of acknowledged record IDs, allowing the client to safely mark them as synced in its local outbox.

### 3.3 Pull Phase (`GET /api/v1/sync/pull?cursor={cursor}`)
- The client sends its current high-water mark cursor (an integer sequence number or epoch millisecond watermark).
- The server responds with all changes (products updated, movements appended) that occurred after `cursor`, up to a bounded batch size (e.g., 500 records), along with `next_cursor`.
- The client applies incoming records locally and advances its stored cursor.

---

## 4. Identity & Authentication

Small shop owners in Ethiopia rarely possess business email addresses or wish to manage complex passwords across devices. The system utilizes Telegram as the root identity provider.

### 4.1 Root Identity
- A shop owner's verified Telegram account (`telegram_user_id`) serves as the root owner identity.
- Account creation occurs when the shop owner interacts with the official Telegram bot (`/start`).
- No passwords, email verification, or SMS OTPs are used.

### 4.2 Device Pairing & Revocable Tokens
1. **Pairing Request**: A new Flutter mobile device launches, generates a temporary random numeric pairing code (e.g., `839-201`), and registers it with the server via `POST /api/v1/pairing/request`.
2. **Short Polling**: The mobile app short-polls `GET /api/v1/pairing/status?code=839201` every 3 seconds (for up to 90 seconds).
3. **Approval**: The shop owner opens the Telegram bot, inputs or approves the pairing code with a single tap (`Confirm Device "Addis Ababa Shop POS 1"`).
4. **Token Issuance**: Upon confirmation, the server assigns the device to the owner's `shop_id` and generates a cryptographically random, revocable bearer token (`device_token`).
5. **Secure Storage**: On the next short-poll tick, the mobile client receives the token, stores it securely using `flutter_secure_storage`, and terminates polling.
6. **Revocation**: The shop owner can run `/devices` in the Telegram bot to view all paired devices and immediately revoke any device token if a phone is lost or stolen.

---

## 5. Tenancy & Data Isolation

### 5.1 Single Database Multi-Tenancy
- The server utilizes a single SQLite database file (`database/database.sqlite`).
- Every business table (`products`, `movements`, `devices`, `categories`, `audit_logs`) includes a mandatory `shop_id` foreign key column.

### 5.2 Central Enforcement
- Multi-tenancy is enforced centrally via Laravel Eloquent Global Scopes (`ShopScope`) and route middleware (`EnforceTenantScope`).
- Raw database queries outside Eloquent are prohibited unless explicitly routed through a tenant-scoped query builder helper.
- Every authenticated API request inspects the bearer token, resolves the associated `shop_id`, and sets it in the tenant context for the duration of the request.
- Attempting to access or mutate records with a mismatched or missing `shop_id` aborts immediately with an HTTP 403 / 404.

---

## 6. Hosting Constraints & Shared Hosting Adaptation

The server must operate predictably on low-cost shared hosting environments (cPanel, DirectAdmin) with the following limitations:
- **No root access**
- **No long-running daemons or background processes** (no `supervisord`, no Docker, no permanent queue runners)
- **No Redis or in-memory caches**
- **Short-lived requests only** (max execution time 30–60 seconds imposed by PHP-FPM / Apache `mod_php`)
- **No WebSockets or server-sent events (SSE)**
- **Cron available only at coarse intervals** (commonly minimum 5 minutes, e.g., `*/5 * * * *`)
- **Possibly no SSH shell access**

### 6.1 Solutions for Shared Hosting
1. **Short-Lived HTTP Only**: All sync endpoints are stateless, short-lived HTTP REST requests. No streaming connections or long-polling.
2. **Cron Execution**:
   - The cPanel cron job invokes the standard Laravel scheduler every 5 minutes:
     ```bash
     * /5 * * * * /usr/local/bin/php /home/username/public_html/server/artisan schedule:run >> /dev/null 2>&1
     ```
   - Background work uses the `database` queue driver.
   - The scheduler runs a queue worker job configured to exit before request timeout:
     ```bash
     php artisan queue:work database --stop-when-empty --max-time=50 --tries=3
     ```
3. **Inline Telegram Webhook Replies**:
   - The Telegram bot operates via HTTP Webhook (`POST /api/v1/telegram/webhook`).
   - Rather than queueing outgoing Telegram messages to a background worker, interactive bot responses are returned **inline** directly in the HTTP webhook response body using Telegram's JSON response format:
     ```json
     {
       "method": "sendMessage",
       "chat_id": 123456789,
       "text": "Current stock for Teff: 45 kg"
     }
     ```
   - Non-interactive alerts or scheduled reports use Laravel's native HTTP client (`Http::post(...)`) synchronously during schedule execution.
4. **Short Polling for Device Pairing**:
   - Pairing avoids persistent connections. The client polls every 2–3 seconds with an exponential backoff and a hard 90-second timeout.

---

## 7. Wire Format Contract

Both the Flutter client (`/app`) and the Laravel server (`/server`) must strictly adhere to this wire format for all JSON payloads.

| Concept | Wire Format Data Type | Example | Server Storage Representation | Rationale |
|---|---|---|---|---|
| **Money** | Integer cents (`*_cents`) | `2550` | `INTEGER` (cents / santim) | Prevents IEEE 754 floating point rounding errors. 1 ETB = 100 cents. |
| **Quantity** | Fixed-decimal string with 3 decimal places | `"2.500"`, `"0.125"` | `INTEGER` (thousandths: `2500`, `125`) | Supports fractional weights (e.g., spices, grains in kg) with exact precision. |
| **Timestamps** | Integer epoch milliseconds UTC | `1762128000000` | `INTEGER` (64-bit epoch ms) | Eliminates timezone parsing ambiguities across platforms. |
| **Identifiers** | Canonical UUIDv7 string | `"018f4a3e-72bc-7d12-824c-9742456012ab"` | `TEXT` (36 chars) or binary | Globally unique, time-ordered, index-friendly, client-generated. |

### Example Wire Payload: Movement Record
```json
{
  "id": "018f4a3e-72bc-7d12-824c-9742456012ab",
  "shop_id": "018f4a3d-3b10-7e32-9c98-c923d87042a1",
  "product_id": "018f4a3d-a71c-7f55-b912-1029384756ab",
  "quantity": "-2.500",
  "unit_price_cents": 14000,
  "type": "sale",
  "note": "POS Sale #1042",
  "created_at": 1762128000000
}
```
