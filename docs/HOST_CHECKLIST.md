# Shared Hosting Verification Checklist

This checklist defines the prerequisite hosting environment requirements for deploying the **Inventory** backend to commodity shared hosting (cPanel, DirectAdmin, Plesk, or bare-metal Linux).

Before provisioning or uploading the deployable release archive (`inventory-server.zip`), verify every item in this checklist.

---

## 1. Environment Checklist Matrix

| # | Check Item | Requirement | Verification Method | Status / Target |
|---|---|---|---|---|
| **1** | **PHP Version** | PHP 8.2 or newer (recommended PHP 8.2, 8.3, or 8.4) with active security support. | Run `php -v` in terminal or check cPanel "Select PHP Version". | Required (>= 8.2) |
| **2** | **Required PHP Extensions** | `pdo_sqlite`, `mbstring`, `openssl`, `curl`, `json`, `ctype`, `tokenizer`, `xml` | Run `php -m` or check `phpinfo()`. | All 8 required |
| **3** | **Cron Availability & Interval** | Ability to schedule cron jobs; host supports execution interval (minimum 5 minutes: `*/5 * * * *`). | Check cPanel "Cron Jobs" menu. | Required (<= 5 min) |
| **4** | **CLI PHP Binary Path** | Fixed path to CLI PHP binary matching target version (e.g., `/usr/local/bin/php`, `/usr/bin/php83`, or `/opt/cpanel/ea-php83/root/usr/bin/php`). | Run `which php` in cPanel Terminal or host documentation. | Absolute path identified |
| **5** | **Outbound HTTPS to Telegram** | Firewall allows outbound TCP port 443 connections to `api.telegram.org`. | Run `curl -v https://api.telegram.org` or diagnostic script. | HTTP 200/404 from Telegram |
| **6** | **Document Root Configuration** | Web server virtual host document root can point directly to `/public` directory (or use `.htaccess` rewrite without leaking application root). | Check cPanel Domains / Document Root settings. | Pointed to `/public` |
| **7** | **Local Disk for SQLite** | The SQLite database file (`database/database.sqlite`) must reside on a local POSIX filesystem (ext4, XFS, etc.), **never** on a networked filesystem (NFS, SMB/CIFS, GlusterFS) where file locks are unreliable. | Check host disk mounts via `df -T` or host specifications. | Non-network storage |

---

## 2. Detailed Verification Instructions

### 2.1 PHP Version & Extension Audit
In cPanel, open **Select PHP Version** or **MultiPHP Manager** and select PHP 8.2 or 8.3. Ensure the following extensions are enabled:
```ini
pdo_sqlite
mbstring
openssl
curl
json
ctype
tokenizer
xml
```
*Note on `mbstring`*: Essential for rendering and validating Amharic text characters (Ge'ez script) without string corruption.

### 2.2 Finding the CLI PHP Binary Path
Shared hosts often have multiple PHP versions installed. The default `php` in PATH may point to an older system PHP (e.g., PHP 7.4). You must find the absolute path to PHP 8.2+:
- **cPanel (ea-php)**: `/opt/cpanel/ea-php82/root/usr/bin/php` or `/opt/cpanel/ea-php83/root/usr/bin/php`
- **cPanel (CloudLinux / alt-php)**: `/usr/local/bin/ea-php82` or `/opt/alt/php82/usr/bin/php`
- **DirectAdmin**: `/usr/local/php82/bin/php`
- **Standard Linux**: `/usr/bin/php` or `/usr/local/bin/php`

### 2.3 Outbound HTTPS to Telegram API
Some budget shared hosting providers block outbound TCP port 443 or restrict outbound connections to a whitelist. Test connectivity:
```bash
curl -I https://api.telegram.org
```
Expected output:
```http
HTTP/2 200
server: nginx
date: ...
```
If this command times out or gives "Connection refused", contact your hosting support to whitelist outbound HTTPS traffic to `api.telegram.org`.

### 2.4 Document Root Isolation (`/public`)
For security, the web root must be isolated to `/public`. Files such as `.env`, `database/database.sqlite`, and application source code must **never** be directly accessible via HTTP.

- **Option A (Preferred - Subdomain or Root Domain)**: In cPanel "Domains", edit the Document Root of your domain/subdomain to point to `public_html/server/public` (or `inventory.yourdomain.com/public`).
- **Option B (Symlink if allowed)**: Symlink `public_html` to `server/public`.
- **Option C (.htaccess fallback if document root cannot be changed)**: Place an `.htaccess` in the parent folder routing all requests into `/public`:
  ```apache
  <IfModule mod_rewrite.c>
      RewriteEngine On
      RewriteRule ^(.*)$ public/$1 [L]
  </IfModule>
  ```
  *Ensure direct access to `.env` or `database.sqlite` is explicitly forbidden:*
  ```apache
  <FilesMatch "^\.env|\.sqlite$">
      Order allow,deny
      Deny from all
  </FilesMatch>
  ```

### 2.5 Cron Configuration
In cPanel **Cron Jobs**, configure a single entry running every 5 minutes:
```bash
*/5 * * * * /opt/cpanel/ea-php83/root/usr/bin/php /home/username/inventory/server/artisan schedule:run >> /dev/null 2>&1
```
This triggers:
1. Database queue processing (`queue:work --stop-when-empty --max-time=50`).
2. Scheduled inventory reports and low-stock checks.
3. Expired pairing code cleanups.

### 2.6 SQLite Storage Considerations
- SQLite requires working POSIX file locking (`fcntl` / `flock`).
- **Do not** store the SQLite file on network-attached storage (NFS or CIFS/Samba), as network file locks can silently fail or corrupt the database during concurrent sync operations.
- Ensure the directory `server/database/` and file `server/database/database.sqlite` are writable by the web server user (typically file permissions `0664` or `0660`, directory `0775`).

---

## 3. Automated Host Verification Script

A verification script is available in `/scripts/host-check.php`. You can run it directly on the host via CLI:

```bash
php scripts/host-check.php
```

Or upload it temporarily to your web directory to view the report in a browser, then remove it once verified.
