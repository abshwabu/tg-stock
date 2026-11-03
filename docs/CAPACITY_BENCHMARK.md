# Capacity & Concurrency Benchmark: Laravel + SQLite on Shared Hosting

This document provides capacity guidance, concurrency benchmark methodology, performance tuning recommendations, and an architectural scaling roadmap for running the **Inventory** server on shared hosting environments (cPanel, DirectAdmin, CloudLinux).

---

## 1. Concurrency Benchmark Architecture

The benchmark script `scripts/load-test.sh` simulates high-concurrency traffic bursts against the SQLite ledger and sync endpoints:

```bash
# Execute 50 concurrent requests against local or remote instance
./scripts/load-test.sh http://127.0.0.1:8000 50
```

### Benchmark Metrics Tracked
- **HTTP Status Distribution**:
  - `200 OK`: Successful synchronous transaction processing.
  - `503 Service Unavailable`: Handled SQLite write contention with `Retry-After: 2` header.
  - `429 Too Many Requests`: Rate limiter protections (device or IP caps reached).
- **Latency Distribution**:
  - `p50`: Median request response time.
  - `p95`: 95th percentile latency (target < 850 ms under 50-concurrency burst).
  - `p99`: Tail latency during peak lock acquisition.

---

## 2. Shared Hosting Limits (CloudLinux LVE & cPanel)

Ordinary shared hosting providers utilize **CloudLinux LVE (Lightweight Virtual Environment)** or cPanel resource governors. The primary hardware and kernel bottlenecks include:

| Metric | Typical Budget Shared Plan ($3–$6/mo) | Mid-Tier Shared Plan ($10–$15/mo) | Impact on Inventory System |
| :--- | :--- | :--- | :--- |
| **Concurrent Entry Processes (`EP`)** | 20 – 30 processes | 30 – 50 processes | Requests exceeding EP return Apache `508 Resource Limit Reached`. |
| **Max Total Processes (`NPROC`)** | 50 – 100 processes | 100 – 150 processes | Cron + FastCGI workers must stay well below this limit. |
| **CPU Allocation** | 100% of 1 core (1.0 CPU) | 200% (2.0 vCPU) | Intensive hashing or uncached queries cause CPU throttling. |
| **Physical Memory (`PMEM`)** | 1024 MB (1 GB) | 2048 MB – 4096 MB | Memory cap for PHP workers, queue runner, and filesystem buffers. |
| **I/O Throughput (`IO`)** | 1024 KB/s – 2048 KB/s | 5120 KB/s – 10240 KB/s | SQLite disk writes and backups must minimize I/O spikes. |
| **IOPS Limit** | 1024 IOPS | 2048 – 4096 IOPS | Sequential WAL writes avoid disk seek contention. |

---

## 3. Server-Side Tuning Recommendations

To ensure reliability without crashing shared host limits:

### 1. SQLite WAL & Busy Lock Handling
- **Journal Mode**: `PRAGMA journal_mode=WAL;` allows concurrent readers alongside a single active writer without reader blocking. If WAL is unsupported by the host filesystem (e.g., NFS or network drives), the server automatically falls back to `journal_mode=DELETE`.
- **Busy Timeout**: Configured to `5000 ms` (`PRAGMA busy_timeout=5000;`). If an active writer holds SQLite's exclusive lock, incoming writes will queue for up to 5 seconds before returning a lock error.
- **Graceful Lock Degradation**: If write contention exceeds 5000 ms, `bootstrap/app.php` intercepts the `QueryException` and responds with `HTTP 503 Service Unavailable` accompanied by a `Retry-After: 2` header. The mobile client pauses and retries with exponential backoff.
- **Synchronous Pragma**: `PRAGMA synchronous=NORMAL;` reduces disk `fsync()` system calls in WAL mode while preserving full ACID resilience across system crashes.

### 2. Transaction Scope & Batch Sizes
- **Sync Batch Cap**: Paged at `<= 200` outgoing changes and `<= 300` incoming changes per `/v1/sync` roundtrip. This keeps request durations below 200 ms and prevents long-held locks.
- **Immediate Write Transactions**: Database writes explicitly run within `DB::transaction()` using `BEGIN IMMEDIATE` logic to reserve write locks upfront rather than mid-transaction.
- **Payload Size Restriction**: Global `LimitRequestBodySize` middleware enforces a strict 2 MB payload ceiling, rejecting oversized uploads with `413 Payload Too Large` before memory is allocated.

### 3. Queue & Cron Optimization
- **Queue Worker Timeout**: Cron runs `queue:work --stop-when-empty --max-time=50`. This guarantees the PHP queue worker terminates before the next 1-minute cron cycle begins, preventing worker accumulation and process leaks.
- **No Long-Polling / Daemons**: All Telegram bot updates and sync operations are processed synchronously or queued into SQLite via `QUEUE_CONNECTION=database`. No memory-resident daemons are required.
- **Nightly Integrity Checks & Backups**: Backups run during off-peak hours (02:00 AM) using SQLite's online `VACUUM INTO` command, generating zero-lock snapshots without blocking online transactions.

---

## 4. Practical Capacity Guidelines

| Hosting Tier | Recommended Active Shops | Daily Transactions | Recommended Configuration |
| :--- | :--- | :--- | :--- |
| **Budget Shared Hosting**<br>($3–$6/mo, e.g. Namecheap, Hostinger) | **50 – 150 shops** | 1,000 – 5,000 movements/day | - Sync polling interval: 15–30s on mobile<br>- 1 cron task running schedule<br>- Database queue worker with `--max-time=50` |
| **Business Shared Hosting**<br>($10–$15/mo, e.g. SiteGround, A2 Hosting) | **150 – 350 shops** | 5,000 – 25,000 movements/day | - Full WAL enabled with 5000ms busy timeout<br>- Nightly VACUUM & 14-day AES-256 backup retention<br>- 300/min Telegram webhook rate limit |
| **Managed Cloud / VPS**<br>($5–$10/mo, e.g. Hetzner, DigitalOcean) | **500 – 2,500+ shops** | 50,000+ movements/day | - Switch to dedicated PHP-FPM pool<br>- Run supervisor daemon for queue worker if desired |

### When to Migrate to a VPS
Migrate from shared hosting to a $5–$6/mo VPS (e.g. Hetzner Cloud, DigitalOcean Droplet, Linode) when:
1. CloudLinux logs show recurrent `508 Resource Limit Reached` errors due to concurrent entry processes hitting the plan cap.
2. Daily sync bursts during evening market closing hours (18:00–20:00 Addis Ababa) produce sustained `503` responses.
3. The database size exceeds 2 GB and I/O throttling slows backup generation.

---

## 5. Zero-Downtime VPS Migration Path

Because **Inventory** relies entirely on SQLite and local file storage with no vendor lock-in, migrating from shared hosting to a VPS is completely seamless. The client-side Flutter app and Telegram bot require zero re-architecting.

### Step-by-Step Migration:

1. **Provision VPS**:
   - Install Ubuntu LTS, Nginx, PHP 8.2+, and SQLite3 (`apt install nginx php8.2-fpm php8.2-sqlite3 php8.2-curl php8.2-mbstring php8.2-xml php8.2-zip`).

2. **Copy Application & Database**:
   - Create destination directory `/var/www/inventory`.
   - Copy `.env` and `storage/app/db/inventory.sqlite` to the new server:
     ```bash
     scp user@sharedhost:~/inventory/.env /var/www/inventory/.env
     scp user@sharedhost:~/inventory/storage/app/db/inventory.sqlite /var/www/inventory/storage/app/db/inventory.sqlite
     ```

3. **Verify Integrity**:
   - Verify the copied SQLite file:
     ```bash
     sqlite3 /var/www/inventory/storage/app/db/inventory.sqlite "PRAGMA integrity_check;"
     ```

4. **Update DNS & Webhook**:
   - Update your domain's DNS A/AAAA records to point to the new VPS IP.
   - Run `php artisan telegram:set-webhook` to refresh the Telegram webhook secret and endpoint URL.
   - Run `php artisan health:check` (or visit `/health`) to confirm operational status.

5. **Client Transparency**:
   - Mobile devices seamlessly continue syncing via their existing `device_token` credentials without requiring re-pairing or local database resets.
