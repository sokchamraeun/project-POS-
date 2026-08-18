# Deploying Bird's Nest Coffee POS to production

This app needs **PHP 8.x + MySQL/MariaDB** and — critically — a host that allows
**outbound HTTPS from PHP** (cURL to external servers). Bakong payment
verification (`check_payment.php` → `api-bakong.nbc.gov.kh`) only works on such a
host.

> ⚠️ **InfinityFree free tier does NOT work for Bakong auto-verify** — it blocks
> outbound connections from PHP, so the payment check always fails. Use a host
> that allows outbound cURL: Hostinger (paid, ~$3/mo), or app hosts like
> Railway / Render / Fly.io.

---

## What must NOT come from the repo (upload manually on the server)

Three files can hold environment-specific secrets and are git-ignored:

| File | From template | Holds |
|------|---------------|-------|
| `db_config.local.php` | `db_config.local.example.php` | production DB host/user/pass/name |
| `bakong_config.local.php` | `bakong_config.local.example.php` | real Bakong token + merchant identity |
| `cloudinary_config.local.php` | `cloudinary_config.local.example.php` | *(optional)* production Cloudinary keys |

Without `bakong_config.local.php`, QR generation uses placeholders and no payment
can be verified. Without `db_config.local.php`, the app falls back to local XAMPP
defaults (`localhost` / `root` / no password) and will fail to connect on a host.
Cloudinary credentials come pre-configured in `cloudinary_config.php` and will work out of the box (with automatic native cURL fallback on shared hosting even if Composer packages are omitted).

---

## Steps (Hostinger / cPanel shared hosting)

1. **Buy a plan** that allows outbound cURL (Hostinger Premium Shared, PHP 8.x).

2. **Create the database** in the host panel:
   - Create a MySQL database + user, grant the user all privileges on it.
   - Note the **host** (usually `localhost`), **db name**, **user**, **password**
     (these are typically prefixed, e.g. `u123456_dbcoffee` / `u123456_cafe`).

3. **Import the data:**
   - A fresh dump is generated locally as `db_coffee_export.sql`
     (regenerate any time — see "Regenerating the dump" below).
   - In the host's **phpMyAdmin → Import**, upload `db_coffee_export.sql`.
   - All product images already stored on Cloudinary will immediately load via HTTPS on your live domain!

4. **Upload the app files** into `public_html/` (File Manager zip-upload, FTP, or
   `git clone` then `composer install` if vendor/ isn't shipped).

5. **Create `db_config.local.php`** on the server (copy `db_config.local.example.php`)
   and fill in the DB credentials from step 2. Do not edit `config.php` — the
   loader picks this file up automatically.

6. **Create `bakong_config.local.php`** on the server (copy
   `bakong_config.local.example.php`) and paste the real Bakong token + merchant
   fields. The current token is valid until **2026-09-20**; renew with
   `php renew_bakong_token.php <registered-email>` when it expires.

7. **Enable HTTPS:** turn on the host's free SSL, then uncomment the HTTPS-redirect
   block in `.htaccess`.

8. **Verify:**
   - Log in, place a Bakong order, scan the QR — it should auto-confirm once paid
     (this is the test that proves outbound works).
   - If it still shows "Could not verify payment", outbound is blocked → wrong host.

---

## Known limitations on shared hosting

- **Real-time push is disabled.** `check_payment.php` posts to a local Node service
  at `localhost:3000/notify` for live KDS updates / sound. That service does not
  run on shared hosting; the calls time out harmlessly (2s) and the app falls back
  to its normal AJAX polling. No action needed unless you want to host the Node
  service separately.
- **Cron jobs** (`cron_daily_stock.php`, `cron_stock_alert.php`) must be registered
  in the host's cron panel if you want scheduled stock alerts/reports.

## Regenerating the dump

From the project root with XAMPP MySQL running:

```bash
"C:/xampp/mysql/bin/mysqldump.exe" -u root --default-character-set=utf8mb4 \
  --routines --single-transaction db_coffee > db_coffee_export.sql
```

`db_coffee_export.sql` is git-ignored (it may contain live data) — transfer it to
the host out-of-band (phpMyAdmin import / SFTP), never commit it.
