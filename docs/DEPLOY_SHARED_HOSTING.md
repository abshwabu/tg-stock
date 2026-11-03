# Shared Hosting Deployment Guide (cPanel / DirectAdmin)

This guide walks you through deploying the **Inventory** Laravel + SQLite server on standard shared hosting (cPanel, DirectAdmin, CloudLinux).

---

## 1. Directory Structure & Document Root

Shared hosting accounts typically provide a home directory `/home/USERNAME/`.

### Recommended Setup (Domain Document Root Configurable)
Point your domain or subdomain's document root directly to `/home/USERNAME/tgstock/public`.
```text
/home/USERNAME/
├── tgstock/              <-- Entire application (OUTSIDE public_html)
│   ├── app/
│   ├── bootstrap/
│   ├── config/
│   ├── database/
│   ├── public/           <-- Document Root points HERE
│   │   ├── index.php
│   │   └── .htaccess
│   ├── storage/
│   │   └── app/db/inventory.sqlite  <-- Protected SQLite database
│   ├── vendor/
│   └── .env              <-- Environment file (OUTSIDE web root)
```

### Alternative Setup (Fixed `public_html`)
If your host locks document root to `public_html`:
1. Extract application files into `/home/USERNAME/tgstock_app/`.
2. Move the contents of `tgstock_app/public/*` (including `.htaccess` and `index.php`) into `public_html/`.
3. In `public_html/index.php`, update the paths to point to the parent directory:
   ```php
   require __DIR__.'/../tgstock_app/vendor/autoload.php';
   $app = require_once __DIR__.'/../tgstock_app/bootstrap/app.php';
   ```
4. Never put `.env`, `storage`, or `inventory.sqlite` inside `public_html`.

---

## 2. File Permissions & Security

From cPanel File Manager or SSH, set the required POSIX permissions:
```bash
# Directories to 755
find /home/USERNAME/tgstock -type d -exec chmod 755 {} \;

# Files to 644
find /home/USERNAME/tgstock -type f -exec chmod 644 {} \;

# Writable directories to 775 (or 750 depending on suPHP/CageFS)
chmod -R 775 /home/USERNAME/tgstock/storage
chmod -R 775 /home/USERNAME/tgstock/bootstrap/cache
chmod -R 775 /home/USERNAME/tgstock/storage/app/db
```

Ensure `.htaccess` denies access to sensitive file patterns:
```apache
<FilesMatch "(\.(env|sqlite|log|enc|lock|git)|composer\.json|artisan)$">
    Require all denied
</FilesMatch>
```

---

## 3. Environment Configuration (`.env`)

Create `.env` inside `/home/USERNAME/tgstock/.env`:
```ini
APP_NAME=Inventory
APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:GENERATE_STRONG_KEY_HERE
APP_URL=https://inventory.yourdomain.com
APP_TIMEZONE=Africa/Addis_Ababa

LOG_CHANNEL=daily
LOG_LEVEL=info

DB_CONNECTION=sqlite
DB_DATABASE=/home/USERNAME/tgstock/storage/app/db/inventory.sqlite
DB_FOREIGN_KEYS=true

CACHE_STORE=database
QUEUE_CONNECTION=database
SESSION_DRIVER=cookie

TELEGRAM_BOT_USERNAME=YourStoreBot
TELEGRAM_BOT_TOKEN=123456789:ABCdefGHIjklMNOpqrSTUvwxYZ
TELEGRAM_WEBHOOK_SECRET=your_32_character_random_webhook_secret
TELEGRAM_ADMIN_CHAT_ID=123456789

BACKUP_ENCRYPTION_KEY=your_32_character_random_hex_key
```

Generate `APP_KEY` using:
```bash
php artisan key:generate
```

---

## 4. Initial Setup & Migrations

### Method A: With SSH Access
Connect to your account via SSH and run:
```bash
cd /home/USERNAME/tgstock
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan bot:check
php artisan telegram:webhook set
```

### Method B: Without SSH (cPanel Cron Workaround)
If SSH is disabled on your shared hosting plan:
1. In cPanel, navigate to **Cron Jobs**.
2. Add a one-time cron command to execute once:
   ```bash
   /usr/local/bin/php /home/USERNAME/tgstock/artisan migrate --force && /usr/local/bin/php /home/USERNAME/tgstock/artisan config:cache && /usr/local/bin/php /home/USERNAME/tgstock/artisan telegram:webhook set > /home/USERNAME/setup.log 2>&1
   ```
3. Wait 1–2 minutes, check `/home/USERNAME/setup.log` to verify migration output.
4. **Delete the temporary setup cron entry immediately.**

---

## 5. Cron Job Configuration

Shared hosting runs on coarse cron schedules (typically every 1 or 5 minutes). All background processing (database queues, alerts, nightly backups, and heartbeat) runs from a single cron entry:

```cron
* * * * * /usr/local/bin/php /home/USERNAME/tgstock/artisan schedule:run >/dev/null 2>&1
```

### How to Find Your Host's CLI PHP Path
On shared hosting, the CLI PHP binary often differs from the web handler:
- Default path: `/usr/local/bin/php` or `/usr/bin/php`
- cPanel multi-PHP: `/usr/local/bin/ea-php83` or `/opt/cpanel/ea-php83/root/usr/bin/php`
- Run `which php` or `php -v` in terminal or check **cPanel > Select PHP Version**. Ensure the CLI version is PHP 8.2 or newer.

---

## 6. SSL & Telegram Webhook Registration

1. In cPanel, enable **AutoSSL** or **Let's Encrypt** for your domain. Ensure HTTPS works.
2. Register your webhook securely:
   ```bash
   php artisan telegram:webhook set
   ```
3. Verify status:
   ```bash
   php artisan telegram:webhook status
   ```

---

## 7. Operational Health Check (`/health`)

Set up a free monitor (e.g., UptimeRobot, BetterStack) to ping your server every 5 minutes:
```
GET https://inventory.yourdomain.com/health
```
- Returns `200 OK` if the SQLite database is writable and the cron heartbeat is < 15 minutes old.
- Returns `503 Service Unavailable` if database write fails or cron stops running, and automatically alerts the admin on Telegram.

---

## 8. Upgrades & Rollbacks

### Upgrade Procedure
1. Create a snapshot backup before upgrading:
   ```bash
   php artisan backup:run
   ```
2. Upload and extract new release zip overwriting application files (`app/`, `bootstrap/`, `config/`, `routes/`, `vendor/`).
3. Run migrations and clear cache:
   ```bash
   php artisan migrate --force
   php artisan config:cache
   php artisan route:cache
   ```
4. Verify system health:
   ```bash
   curl -I https://inventory.yourdomain.com/health
   ```

### Rollback Procedure
If an issue occurs:
1. Re-extract the previous release zip.
2. Restore database from backup:
   ```bash
   php artisan backup:restore --latest --force
   php artisan config:cache
   ```
