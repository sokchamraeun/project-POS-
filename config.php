<?php
date_default_timezone_set('Asia/Phnom_Penh');
require_once __DIR__ . '/lang.php';
require_once __DIR__ . '/cloudinary_config.php';

// ── SMART MULTI-ENVIRONMENT AUTO-CONFIG DATABASE CASCADE ──
// Auto-detects environment (Localhost XAMPP vs cPanel / Production Hosting)
// Works out of the box on deploy with zero manual setup required!

$db_candidates = [];

// 1. Highest priority: Local config file override if present
if (is_file(__DIR__ . '/db_config.local.php')) {
    require __DIR__ . '/db_config.local.php';
    if (isset($servername, $username, $password, $dbname)) {
        $db_candidates[] = [
            'host' => $servername,
            'user' => $username,
            'pass' => $password,
            'name' => $dbname
        ];
    }
}

// 2. Environment variables (Docker / Cloud / cPanel .env)
if (getenv('DB_NAME') || getenv('DB_USER')) {
    $db_candidates[] = [
        'host' => getenv('DB_HOST') ?: 'localhost',
        'user' => getenv('DB_USER') ?: 'root',
        'pass' => getenv('DB_PASS') !== false ? getenv('DB_PASS') : '',
        'name' => getenv('DB_NAME') ?: 'db_coffeeshop_final--'
    ];
}

// if (getenv('DB_NAME') || getenv('DB_USER')) {
//     $db_candidates[] = [
//         'host' => getenv('DB_HOST') ?: 'localhost',
//         'user' => getenv('DB_USER') ?: 'dpdc690_pos',
//         'pass' => getenv('DB_PASS') !== false ? getenv('Coffee@_1234') : '',
//         'name' => getenv('DB_NAME') ?: 'dpdc690_pos'
//     ];
// }

// 3. Detect if on Production Host or Localhost
$is_local_env = (
    (!empty($_SERVER['SERVER_NAME']) && in_array($_SERVER['SERVER_NAME'], ['localhost', '127.0.0.1', '::1'], true)) ||
    (!empty($_SERVER['HTTP_HOST']) && (str_contains($_SERVER['HTTP_HOST'], 'localhost') || str_contains($_SERVER['HTTP_HOST'], '127.0.0.1'))) ||
    (php_sapi_name() === 'cli' && stripos(__DIR__, 'xampp') !== false)
);

if ($is_local_env) {
    // Localhost XAMPP candidates first
    $db_candidates[] = ['host' => 'localhost', 'user' => 'root', 'pass' => '', 'name' => 'db_coffeeshop_final--'];
    $db_candidates[] = ['host' => 'localhost', 'user' => 'root', 'pass' => '', 'name' => 'db_coffee'];
    $db_candidates[] = ['host' => '127.0.0.1', 'user' => 'root', 'pass' => '', 'name' => 'db_coffeeshop_final--'];
    // Production candidates as fallback
    $db_candidates[] = ['host' => 'localhost', 'user' => 'dpdc690_pos', 'pass' => 'Coffee@_1234', 'name' => 'dpdc690_pos'];
    $db_candidates[] = ['host' => 'localhost', 'user' => 'dpdc690_dbcoffee', 'pass' => 'Coffee@_1234', 'name' => 'dpdc690_dbcoffee'];
} else {
    // Production Hosting candidates first (cPanel / thebirdnestcafe.online)
    $db_candidates[] = ['host' => 'localhost', 'user' => 'dpdc690_pos', 'pass' => 'Coffee@_1234', 'name' => 'dpdc690_pos'];
    $db_candidates[] = ['host' => 'localhost', 'user' => 'dpdc690_dbcoffee', 'pass' => 'Coffee@_1234', 'name' => 'dpdc690_dbcoffee'];
    $db_candidates[] = ['host' => 'localhost', 'user' => 'thebirdn_pos', 'pass' => 'Coffee@_1234', 'name' => 'thebirdn_pos'];
    // Localhost fallback
    $db_candidates[] = ['host' => 'localhost', 'user' => 'root', 'pass' => '', 'name' => 'db_coffeeshop_final--'];
}

// ── ROBUST AUTO-CONNECT ATTEMPT ──
mysqli_report(MYSQLI_REPORT_OFF);

$conn = null;
$conn_error_msg = null;
$servername = 'localhost';
$username   = 'root';
$password   = '';
$dbname     = 'db_coffeeshop_final--';

foreach ($db_candidates as $cand) {
    try {
        $c = @new mysqli($cand['host'], $cand['user'], $cand['pass'], $cand['name']);
        if ($c && !$c->connect_error) {
            $conn = $c;
            $servername = $cand['host'];
            $username   = $cand['user'];
            $password   = $cand['pass'];
            $dbname     = $cand['name'];
            break;
        }
    } catch (Throwable $e) {
        $conn_error_msg = $e->getMessage();
    }
}

if (!isset($conn) || $conn->connect_error || !empty($conn_error_msg)) {
    $err_details = $conn_error_msg ?? ($conn->connect_error ?? 'Unknown database error');
    http_response_code(200); // Prevent generic 500 server error
    die('
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Database Setup Required</title>
        <style>
            body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #121212; color: #e0e0e0; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; padding: 20px; }
            .box { background: #1e1e1e; border: 1px solid #333; border-radius: 12px; max-width: 600px; width: 100%; padding: 28px; box-shadow: 0 10px 30px rgba(0,0,0,0.5); }
            h2 { color: #f44336; margin-top: 0; display: flex; align-items: center; gap: 10px; font-size: 20px; }
            .err-msg { background: rgba(244,67,54,0.1); border-left: 4px solid #f44336; padding: 12px 16px; border-radius: 4px; font-family: monospace; font-size: 13px; color: #ff8a80; margin: 16px 0; word-break: break-all; }
            ol { padding-left: 20px; line-height: 1.6; color: #b0b0b0; font-size: 14px; }
            code { background: #2a2a2a; color: #d1904b; padding: 2px 6px; border-radius: 4px; font-family: monospace; font-size: 12px; }
            .badge { display: inline-block; background: #d1904b; color: #000; font-weight: bold; padding: 4px 8px; border-radius: 4px; font-size: 11px; text-transform: uppercase; margin-bottom: 12px; }
        </style>
    </head>
    <body>
        <div class="box">
            <span class="badge">Hosting Setup Guide</span>
            <h2>⚠️ Cannot Connect to MySQL Database</h2>
            <p style="color:#bbb;font-size:14px;">The application could not establish a connection to your MySQL server on hosting.</p>
            
            <div class="err-msg">Error: ' . htmlspecialchars($err_details) . '</div>

            <h4 style="color:#d1904b;margin-bottom:8px;">How to fix this on your server:</h4>
            <ol>
                <li>In your hosting File Manager, create a file named <code>db_config.local.php</code> in the root folder.</li>
                <li>Add your real hosting database credentials:
                    <pre style="background:#111;padding:12px;border-radius:6px;color:#81c784;font-size:12px;overflow-x:auto;">&lt;?php
$servername = "localhost";
$username   = "YOUR_CPANEL_DB_USER";
$password   = "YOUR_CPANEL_DB_PASSWORD";
$dbname     = "YOUR_CPANEL_DB_NAME";</pre>
                </li>
                <li>Import <code>db_coffee_export.sql</code> into phpMyAdmin on your hosting server.</li>
                <li>Refresh this page.</li>
            </ol>
        </div>
    </body>
    </html>
    ');
}

// ── CRITICAL: Force utf8mb4 so 4-byte emoji are read correctly ──
$conn->set_charset('utf8mb4');

// ── Hosting compatibility: Align MySQL timezone to Asia/Phnom_Penh (UTC+7) & prevent ONLY_FULL_GROUP_BY crashes ──
@$conn->query("SET time_zone = '+07:00'");
@$conn->query("SET SESSION sql_mode = (SELECT REPLACE(@@sql_mode, 'ONLY_FULL_GROUP_BY', ''))");

if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

try {
    @$conn->query("ALTER TABLE products ADD COLUMN IF NOT EXISTS cost_price DECIMAL(10,2) DEFAULT 0.00 AFTER price");
} catch (Throwable $e) {}

// --- Check if constants are already defined before defining them ---
if (!defined('PAYMENT_API_URL')) {
    define('PAYMENT_API_URL', 'https://api.example.com/payment');
}
if (!defined('PAYMENT_API_TOKEN')) {
    define('PAYMENT_API_TOKEN', 'your_token_here');
}

// ── LOAD SETTINGS FROM DB ──
$_cafe_settings = [];
try {
    @$conn->query("CREATE TABLE IF NOT EXISTS `settings` (
        `setting_id` INT AUTO_INCREMENT PRIMARY KEY,
        `setting_key` VARCHAR(100) NOT NULL UNIQUE,
        `setting_value` LONGTEXT NULL,
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    @$conn->query("ALTER TABLE `settings` MODIFY COLUMN `setting_value` LONGTEXT NULL");

    $_sr = @$conn->query("SELECT setting_key, setting_value FROM settings");
    if ($_sr) { while ($row = $_sr->fetch_assoc()) $_cafe_settings[$row['setting_key']] = $row['setting_value']; }
} catch (Throwable $e) {}

// ── Date-range check for promotions ──
$_today = date('Y-m-d');
$_hh_sd = $_cafe_settings['happy_hour_start_date'] ?? '';
$_hh_ed = $_cafe_settings['happy_hour_end_date']   ?? '';
$_hh_in_range = (($_hh_sd === '' || $_today >= $_hh_sd) && ($_hh_ed === '' || $_today <= $_hh_ed));
$_bx_sd = $_cafe_settings['buy_x_start_date'] ?? '';
$_bx_ed = $_cafe_settings['buy_x_end_date']   ?? '';
$_bx_in_range = (($_bx_sd === '' || $_today >= $_bx_sd) && ($_bx_ed === '' || $_today <= $_bx_ed));

if (!defined('HAPPY_HOUR_ENABLED'))  define('HAPPY_HOUR_ENABLED',  (bool)(int)($_cafe_settings['happy_hour_enabled']  ?? 1) && $_hh_in_range);
if (!defined('HAPPY_HOUR_START'))    define('HAPPY_HOUR_START',    (int)($_cafe_settings['happy_hour_start']    ?? 14));
if (!defined('HAPPY_HOUR_END'))      define('HAPPY_HOUR_END',      (int)($_cafe_settings['happy_hour_end']      ?? 16));
if (!defined('HAPPY_HOUR_DISCOUNT')) define('HAPPY_HOUR_DISCOUNT', (int)($_cafe_settings['happy_hour_discount'] ?? 20));
if (!defined('BUY_X_GET_1_ENABLED')) define('BUY_X_GET_1_ENABLED',(bool)(int)($_cafe_settings['buy_x_get_1_enabled'] ?? 1) && $_bx_in_range);
if (!defined('BUY_X_COUNT'))         define('BUY_X_COUNT',         (int)($_cafe_settings['buy_x_count']         ?? 3));
if (!defined('KHR_RATE'))            define('KHR_RATE',             (int)($_cafe_settings['khr_exchange_rate']   ?? 4100));
if (!defined('FREE_ITEM_PRODUCT_ID')) define('FREE_ITEM_PRODUCT_ID', (int)($_cafe_settings['free_item_product_id'] ?? 0));
if (!defined('TAX_RATE'))            define('TAX_RATE',             (float)($_cafe_settings['tax_rate']           ?? 10));
if (!defined('DAILY_SALES_TARGET'))  define('DAILY_SALES_TARGET',   (float)($_cafe_settings['daily_sales_target'] ?? 500));
if (!defined('STAND_COUNT'))         define('STAND_COUNT',          max(1, min(100, (int)($_cafe_settings['stand_count'] ?? 20))));
if (!defined('OVERDUE_MINUTES'))     define('OVERDUE_MINUTES',      max(1, min(120, (int)($_cafe_settings['overdue_minutes'] ?? 10))));
if (!defined('PAYLATER_FOLLOWUP_MINUTES')) define('PAYLATER_FOLLOWUP_MINUTES', max(5, min(240, (int)($_cafe_settings['paylater_followup_minutes'] ?? 45))));
// Loyalty earn rate, expressed as a ratio: X points per Y drinks. Both clamp to
// a minimum of 1 — zero points awards nothing forever, zero drinks divides by
// zero, and neither is a rate anyone means to type. The defaults reproduce the
// old hardcoded behaviour exactly: one point per drink.
if (!defined('LOYALTY_POINTS_PER'))    define('LOYALTY_POINTS_PER',    max(1, (int)($_cafe_settings['loyalty_points_per']    ?? 1)));
if (!defined('LOYALTY_POINTS_DRINKS')) define('LOYALTY_POINTS_DRINKS', max(1, (int)($_cafe_settings['loyalty_points_drinks'] ?? 1)));
if (!defined('LOYALTY_MODE'))          define('LOYALTY_MODE',          in_array($_cafe_settings['loyalty_mode'] ?? 'drinks', ['drinks', 'spend']) ? ($_cafe_settings['loyalty_mode'] ?? 'drinks') : 'drinks');
if (!defined('RECEIPT_SHOP_NAME'))  define('RECEIPT_SHOP_NAME',  $_cafe_settings['receipt_shop_name']  ?? "The Bird Nest Cafe");
if (!defined('RECEIPT_LOCATION'))   define('RECEIPT_LOCATION',   $_cafe_settings['receipt_location']   ?? "Phnom Penh");
if (!defined('RECEIPT_PHONE'))      define('RECEIPT_PHONE',      $_cafe_settings['receipt_phone']      ?? "+855 12 345 678");
if (!defined('RECEIPT_FOOTER_MSG')) define('RECEIPT_FOOTER_MSG', $_cafe_settings['receipt_footer_msg'] ?? "Thank You!");
unset($_cafe_settings, $_sr, $_today, $_hh_sd, $_hh_ed, $_hh_in_range, $_bx_sd, $_bx_ed, $_bx_in_range);

// ── Schema migrations tracker ──
$conn->query("CREATE TABLE IF NOT EXISTS schema_migrations (id VARCHAR(100) NOT NULL PRIMARY KEY, applied_at DATETIME DEFAULT CURRENT_TIMESTAMP) DEFAULT CHARSET=utf8mb4");
if (!function_exists('_migrate')) {
    function _migrate(mysqli $db, string $id, callable $fn): void {
        $chk = $db->prepare("SELECT id FROM schema_migrations WHERE id=?");
        $chk->bind_param("s", $id); $chk->execute();
        if ($chk->get_result()->num_rows) return;
        $fn($db);
        if ($db->errno !== 0) return; // don't mark applied if last query failed
        $ins = $db->prepare("INSERT IGNORE INTO schema_migrations (id) VALUES (?)");
        $ins->bind_param("s", $id); $ins->execute();
    }
}

// ── One-time schema migrations ──
_migrate($conn, 'orders_cols_v1', function($db) {
    $db->query("ALTER TABLE orders ADD COLUMN IF NOT EXISTS prepared_by VARCHAR(100) NULL DEFAULT NULL");
    $db->query("ALTER TABLE orders ADD COLUMN IF NOT EXISTS prepared_by_role VARCHAR(50) NULL DEFAULT NULL");
    $db->query("ALTER TABLE orders ADD COLUMN IF NOT EXISTS table_number VARCHAR(10) NULL DEFAULT NULL");
    $db->query("ALTER TABLE orders ADD COLUMN IF NOT EXISTS customer_id INT NULL");
});
_migrate($conn, 'orders_started_at_v1', function($db) {
    $db->query("ALTER TABLE orders ADD COLUMN IF NOT EXISTS started_at DATETIME NULL DEFAULT NULL");
});

_migrate($conn, 'products_badge_text', function($db) {
    $db->query("ALTER TABLE products ADD COLUMN IF NOT EXISTS badge_text VARCHAR(40) NULL DEFAULT NULL");
});
_migrate($conn, 'products_promo_percent', function($db) {
    $db->query("ALTER TABLE products ADD COLUMN IF NOT EXISTS promo_percent TINYINT UNSIGNED NOT NULL DEFAULT 0");
});
_migrate($conn, 'order_items_promo_percent', function($db) {
    $db->query("ALTER TABLE order_items ADD COLUMN IF NOT EXISTS promo_percent TINYINT UNSIGNED NOT NULL DEFAULT 0");
});
_migrate($conn, 'order_items_orig_price', function($db) {
    $db->query("ALTER TABLE order_items ADD COLUMN IF NOT EXISTS orig_price DECIMAL(10,2) NOT NULL DEFAULT 0");
});

_migrate($conn, 'users_email_security_v1', function($db) {
    $existing = [];
    $res = $db->query("SHOW COLUMNS FROM `users`");
    if ($res) {
        while ($c = $res->fetch_assoc()) {
            $existing[strtolower($c['Field'])] = true;
        }
    }
    if (!isset($existing['name']))                 @$db->query("ALTER TABLE `users` ADD `name` VARCHAR(100) NULL DEFAULT NULL");
    if (!isset($existing['email']))                @$db->query("ALTER TABLE `users` ADD `email` VARCHAR(255) NULL DEFAULT NULL");
    if (!isset($existing['is_active']))            @$db->query("ALTER TABLE `users` ADD `is_active` TINYINT(1) NOT NULL DEFAULT 1");
    if (!isset($existing['role']))                 @$db->query("ALTER TABLE `users` ADD `role` VARCHAR(50) NOT NULL DEFAULT 'staff'");
    if (!isset($existing['must_change_password'])) @$db->query("ALTER TABLE `users` ADD `must_change_password` TINYINT(1) NOT NULL DEFAULT 0");
    if (!isset($existing['must_set_security']))    @$db->query("ALTER TABLE `users` ADD `must_set_security` TINYINT(1) NOT NULL DEFAULT 0");
    if (!isset($existing['security_question']))    @$db->query("ALTER TABLE `users` ADD `security_question` VARCHAR(255) NULL DEFAULT NULL");
    if (!isset($existing['security_answer']))      @$db->query("ALTER TABLE `users` ADD `security_answer` VARCHAR(255) NULL DEFAULT NULL");

    @$db->query("CREATE TABLE IF NOT EXISTS `password_resets` (
      `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      `user_id` INT NOT NULL,
      `email` VARCHAR(255) NOT NULL,
      `token_hash` CHAR(64) NOT NULL,
      `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      `expires_at` DATETIME NOT NULL,
      PRIMARY KEY (`id`),
      INDEX `idx_user_id` (`user_id`),
      INDEX `idx_email` (`email`),
      INDEX `idx_token_hash` (`token_hash`),
      INDEX `idx_expires_at` (`expires_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
});

// Barista unmade-item tracking: made_at NULL = drink not yet made (in the queue).
// Stamped when the barista completes an order. Backfill everything not currently in the
// queue so history doesn't flood the barista station on first load.
_migrate($conn, 'order_items_made_at_v1', function($db) {
    $db->query("ALTER TABLE order_items ADD COLUMN IF NOT EXISTS made_at DATETIME NULL DEFAULT NULL");
    $db->query("
        UPDATE order_items oi
        JOIN orders o ON o.order_id = oi.order_id
        SET oi.made_at = COALESCE(o.completed_at, o.order_date)
        WHERE oi.made_at IS NULL
          AND o.status NOT IN ('Preparing','PendingPayment')
    ");
});

// Per-drink made tracking: made_qty = how many units of a row are made (0..quantity).
// Backfill rows already fully made (made_at set) to their full quantity.
_migrate($conn, 'order_items_made_qty_v1', function($db) {
    $db->query("ALTER TABLE order_items ADD COLUMN IF NOT EXISTS made_qty INT NOT NULL DEFAULT 0");
    $db->query("UPDATE order_items SET made_qty = quantity WHERE made_at IS NOT NULL AND made_qty = 0");
});

// Order audit trail: who changed a placed order, when, and what the money did.
// Dashboard/report figures are SUMs over `orders`, so the only way to falsify them
// is to alter an order after the fact — this table makes that leave a mark.
// Append-only by convention: nothing in the app updates or deletes a row here.
if (!function_exists('log_order_audit')) {
    function log_order_audit($conn, $order_id, $action, $detail = '', $total_before = null, $total_after = null) {
        return true;
    }
}

/**
 * The badge label to show for a product: a non-zero promo auto-generates "N% OFF"
 * and wins over the free-text badge; otherwise fall back to the manual badge_text.
 * (function_exists guard mirrors config.php's existing _migrate guard — config can be
 * required more than once in some flows; do not "simplify" it away.)
 */
if (!function_exists('product_badge_label')) {
    function product_badge_label(array $row): string {
        $promo = (int)($row['promo_percent'] ?? 0);
        if ($promo > 0) return $promo . '% OFF';
        return trim((string)($row['badge_text'] ?? ''));
    }
}

/**
 * Human-readable shift duration. Anything under an hour reads as minutes —
 * number_format(0.02, 1) rendered a real 1-minute shift as "0.0h", which looks
 * like a broken total rather than a short shift.
 * Shared by attendance.php and attendance_action.php so both agree.
 */
if (!function_exists('fmt_hours')) {
    function fmt_hours(float $h): string {
        if ($h <= 0)  return '0m';
        if ($h < 1)   return max(1, (int)round($h * 60)) . 'm';
        return rtrim(rtrim(number_format($h, 1), '0'), '.') . 'h';
    }
}

if (!function_exists('current_user_photo')) {
    function current_user_photo(mysqli $conn): string {
        return '';
    }
}

/**
 * WHERE fragment selecting orders whose money is actually collected — the single
 * source of truth for "revenue = payment received" across dashboard/reports.
 * Cash/riel are paid at checkout, Bakong after the QR scan, Pay Later after settle;
 * pending/cancelled/refunded/void are excluded. $alias 'o' -> "o.".
 */
if (!function_exists('paid_orders_where')) {
    function paid_orders_where(string $alias = ''): string {
        $p = $alias !== '' ? $alias . '.' : '';
        return "{$p}order_id NOT IN (SELECT op_pending.order_id FROM order_payments op_pending WHERE op_pending.payment_status = 'pending')";
    }
}

/**
 * The business day a moment belongs to. Trade before 06:00 belongs to the
 * previous calendar day, matching orders.business_date.
 */
if (!function_exists('business_date_today')) {
    function business_date_today(): string {
        $now = new DateTime();
        if ((int)$now->format('H') < 6) { $now->modify('-1 day'); }
        return $now->format('Y-m-d');
    }
}

/**
 * Determines whether a product is a direct/packaged item (soft drink, bottle, can, water)
 * or a make-to-order beverage that requires recipe ingredients (BOM).
 */
if (!function_exists('is_direct_drink_product')) {
    function is_direct_drink_product(array $product, ?mysqli $db = null): bool {
        if (!empty($product['product_type']) && $product['product_type'] === 'direct_drink') {
            return true;
        }
        $cat = strtolower($product['category'] ?? '');
        $name = strtolower($product['name'] ?? '');
        $directKeywords = [
            'soft', 'direct', 'bottle', 'can', 'water', 'coca', 'coke', 'sting', 'ize', 
            'red bull', 'redbull', 'bacchus', 'bachas', 'carabao', 'pocari', 'fanta', 
            'sprite', 'mirinda', 'yeo', 'aquarius', 'beer', 'vital', 'juice', 'snack', 
            'drink', 'soda', 'energy'
        ];
        foreach ($directKeywords as $kw) {
            if (str_contains($cat, $kw) || str_contains($name, $kw)) {
                return true;
            }
        }

        // Check if matching direct stock item exists in database
        global $conn;
        $connToUse = $db ?? $conn;
        if ($connToUse instanceof mysqli) {
            static $directStockNames = null;
            if ($directStockNames === null) {
                $directStockNames = [];
                $sRes = @$connToUse->query("SELECT item_name FROM stock_items WHERE item_type = 'direct_drink' OR category = 'Direct Drinks' OR category = 'Soft Drinks'");
                if ($sRes) {
                    while ($sr = $sRes->fetch_assoc()) {
                        $directStockNames[] = strtolower(str_replace(' ', '', $sr['item_name']));
                    }
                }
            }
            if (!empty($directStockNames)) {
                $cleanP = strtolower(str_replace(' ', '', $product['name'] ?? ''));
                foreach ($directStockNames as $dsName) {
                    if ($cleanP === $dsName || str_contains($cleanP, $dsName) || str_contains($dsName, $cleanP)) {
                        return true;
                    }
                }
            }
        }

        if (isset($product['recipe_count']) && (int)$product['recipe_count'] === 0 && (float)($product['cost_price'] ?? 0) > 0) {
            return true;
        }

        return false;
    }
}

if (!function_exists('is_box_product_name')) {
    function is_box_product_name(string $name): bool {
        return (bool)(
            preg_match('/\((?:Box|កេស|កេសធំ|កាតុង|Carton|Case|Pack|យួរ|Package|កញ្ចប់|Dozen|ឡូ|Crate|ស្នោ)\)/ui', $name) ||
            preg_match('/\b(?:Box|Carton|Case|Pack|Package|Dozen|Crate)\b/ui', $name) ||
            preg_match('/(?:កេស|កាតុង|យួរ|កញ្ចប់|ឡូ|ស្នោ)/u', $name)
        );
    }
}

if (!function_exists('find_direct_stock_item_for_product')) {
    function find_direct_stock_item_for_product(mysqli $conn, array $product): ?array {
        $pName = trim((string)($product['name'] ?? ''));
        if ($pName === '') return null;

        $cleanBase = trim(preg_replace('/\s*\((?:Box|កេស|កេសធំ|កាតុង|Carton|Case|Unit|កំប៉ុង|ដប|Pack|យួរ|Package|កញ្ចប់|Dozen|ឡូ|Crate|ស្នោ)\)/ui', '', $pName));
        $cleanBase = trim(preg_replace('/\s+(?:Box|កេស|កេសធំ|កាតុង|Carton|Case|Pack|យួរ|Package|កញ្ចប់|Dozen|ឡូ|Crate|ស្នោ)$/ui', '', $cleanBase));

        $isBox = is_box_product_name($pName);

        $stmt = $conn->prepare("SELECT item_id, item_name, quantity, unit, purchase_unit, conversion_rate, alert_level, cost_per_unit, cost_per_purchase_unit, selling_price_per_unit, selling_price_per_box 
                                FROM stock_items 
                                WHERE item_type = 'direct_drink' AND is_active = 1 
                                  AND (
                                    LOWER(REPLACE(item_name, ' ', '')) = LOWER(REPLACE(?, ' ', '')) 
                                    OR LOWER(REPLACE(item_name, ' ', '')) = LOWER(REPLACE(?, ' ', ''))
                                    OR item_name LIKE ? 
                                    OR ? LIKE CONCAT('%', item_name, '%')
                                    OR ? LIKE CONCAT('%', item_name, '%')
                                  ) 
                                ORDER BY (LOWER(TRIM(item_name)) = LOWER(TRIM(?))) DESC, LENGTH(item_name) DESC
                                LIMIT 1");
        if (!$stmt) return null;

        $wild = "%{$cleanBase}%";
        $stmt->bind_param("ssssss", $cleanBase, $pName, $wild, $cleanBase, $pName, $cleanBase);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res->fetch_assoc();
        $stmt->close();

        if (!$row) return null;

        $convRate = (float)($row['conversion_rate'] ?? 24);
        if ($convRate <= 0) $convRate = 24;

        $stockQty = (float)($row['quantity'] ?? 0);
        $maxServings = $isBox ? (int)floor($stockQty / $convRate) : (int)floor($stockQty);

        return [
            'stock_item'           => $row,
            'item_id'              => (int)$row['item_id'],
            'item_name'            => $row['item_name'],
            'is_box'               => $isBox,
            'conversion_rate'      => $convRate,
            'available_base_units' => $stockQty,
            'unit'                 => $row['unit'] ?: 'unit',
            'purchase_unit'        => $row['purchase_unit'] ?: 'box',
            'max_servings'         => max(0, $maxServings),
            'alert_level'          => (float)($row['alert_level'] ?? 0)
        ];
    }
}

/**
 * Returns maximum servings/units available for a product based on its recipe and stock_items.
 * Returns null if the product is not inventory/recipe tracked.
 */
if (!function_exists('getProductMaxStock')) {
    function getProductMaxStock(mysqli $conn, int $productId): ?int {
        // 1. Check product_recipes (Bill of Materials) - Exclude Auto Packaging Sets from stock limiting
        $stmt = $conn->prepare("
            SELECT 
                MIN(CASE WHEN s.item_id IS NOT NULL AND (s.item_name NOT LIKE '%Packaging Set%' AND s.item_name NOT LIKE '%ឈុត%' AND s.category != 'Packaging') AND r.quantity_required > 0 THEN FLOOR(s.quantity / r.quantity_required) ELSE NULL END) AS max_servings,
                SUM(CASE WHEN (s.item_name NOT LIKE '%Packaging Set%' AND s.item_name NOT LIKE '%ឈុត%' AND s.category != 'Packaging') THEN 1 ELSE 0 END) AS physical_recipe_count
            FROM product_recipes r
            JOIN stock_items s ON r.item_id = s.item_id AND s.is_active = 1
            WHERE r.product_id = ?
        ");
        if ($stmt) {
            $stmt->bind_param("i", $productId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($row && (int)($row['physical_recipe_count'] ?? 0) > 0 && $row['max_servings'] !== null) {
                return max(0, (int)$row['max_servings']);
            }
        }

        // 2. Check if product is direct drink or made-to-order
        $pStmt = $conn->prepare("SELECT product_id, name, category, price FROM products WHERE product_id = ?");
        if ($pStmt) {
            $pStmt->bind_param("i", $productId);
            $pStmt->execute();
            $pRow = $pStmt->get_result()->fetch_assoc();
            $pStmt->close();

            if ($pRow) {
                $direct = find_direct_stock_item_for_product($conn, $pRow);
                if ($direct) {
                    return (int)$direct['max_servings'];
                }
                if (is_direct_drink_product($pRow)) {
                    return 100;
                } else {
                    return 0;
                }
            }
        }

        return null;
    }
}

/**
 * Evaluates live stock availability for all products, accounting for ingredients & cart session reservations.
 */
if (!function_exists('evaluate_products_stock')) {
    function evaluate_products_stock(mysqli $conn, array $cart = []): array {
        $reserved_by_item = []; // item_id => total base units in cart

        // 1. Fetch active stock items
        $stock_items = [];
        $si_res = $conn->query("SELECT item_id, item_name, quantity, alert_level, category, item_type, unit, purchase_unit, conversion_rate FROM stock_items WHERE is_active = 1");
        if ($si_res) {
            while ($si = $si_res->fetch_assoc()) {
                $stock_items[(int)$si['item_id']] = $si;
            }
        }

        // 2. Fetch products
        $products = [];
        $p_res = $conn->query("SELECT product_id, name, price, category, is_available, has_sizes, cost_price FROM products WHERE is_available = 1");
        if ($p_res) {
            while ($p = $p_res->fetch_assoc()) {
                $products[(int)$p['product_id']] = $p;
            }
        }

        // 3. Fetch recipes
        $prod_recipes = [];
        $r_res = $conn->query("SELECT r.product_id, r.item_id, r.quantity_required, s.item_name, s.category 
                              FROM product_recipes r 
                              JOIN stock_items s ON r.item_id = s.item_id 
                              WHERE s.is_active = 1");
        if ($r_res) {
            while ($r = $r_res->fetch_assoc()) {
                if (stripos($r['item_name'], 'packaging set') !== false || str_contains($r['item_name'], 'ឈុត') || $r['category'] === 'Packaging') {
                    continue;
                }
                $prod_recipes[(int)$r['product_id']][] = [
                    'item_id'           => (int)$r['item_id'],
                    'quantity_required' => (float)$r['quantity_required'],
                ];
            }
        }

        // 4. Calculate shared reserved stock from current cart
        if (!empty($cart)) {
            foreach ($cart as $cItem) {
                $cPid = (int)($cItem['product_id'] ?? 0);
                $cQty = max(1, (int)($cItem['qty'] ?? 1));
                if ($cPid <= 0) continue;

                $p = $products[$cPid] ?? ['product_id' => $cPid, 'name' => $cItem['product_name'] ?? ''];
                if (!empty($prod_recipes[$cPid])) {
                    foreach ($prod_recipes[$cPid] as $rec) {
                        $itemId = (int)$rec['item_id'];
                        $qtyReq = (float)$rec['quantity_required'];
                        $reserved_by_item[$itemId] = ($reserved_by_item[$itemId] ?? 0.0) + ($qtyReq * $cQty);
                    }
                } else {
                    $direct = find_direct_stock_item_for_product($conn, $p);
                    if ($direct) {
                        $itemId = (int)$direct['item_id'];
                        $baseUnits = $direct['is_box'] ? ($cQty * $direct['conversion_rate']) : $cQty;
                        $reserved_by_item[$itemId] = ($reserved_by_item[$itemId] ?? 0.0) + $baseUnits;
                    }
                }
            }
        }

        $results = [];
        foreach ($products as $pId => $p) {
            $pName = $p['name'];
            $recipes = $prod_recipes[$pId] ?? [];
            $max_servings = null;
            $missing = [];
            $low = [];
            $has_physical_recipe = !empty($recipes);

            if ($has_physical_recipe) {
                foreach ($recipes as $rec) {
                    $itemId = (int)$rec['item_id'];
                    $req = (float)$rec['quantity_required'];
                    if ($req <= 0) continue;

                    $si = $stock_items[$itemId] ?? null;
                    if (!$si) continue;

                    $rawQty = (float)$si['quantity'];
                    $reserved = $reserved_by_item[$itemId] ?? 0.0;
                    $effQty = max(0.0, $rawQty - $reserved);
                    $servings = (int)floor($effQty / $req);

                    if ($max_servings === null || $servings < $max_servings) {
                        $max_servings = $servings;
                    }

                    if ($effQty < $req) {
                        if ($rawQty < $req) {
                            $missing[] = $si['item_name'];
                        } else {
                            $missing[] = $si['item_name'] . ' (All in cart)';
                        }
                    } elseif ($effQty <= (float)$si['alert_level']) {
                        $low[] = $si['item_name'];
                    }
                }
            } else {
                $direct = find_direct_stock_item_for_product($conn, $p);
                if ($direct) {
                    $itemId = (int)$direct['item_id'];
                    $si = $stock_items[$itemId] ?? $direct['stock_item'];
                    $rawQty = (float)$si['quantity'];
                    $reserved = $reserved_by_item[$itemId] ?? 0.0;
                    $effQty = max(0.0, $rawQty - $reserved);
                    $convRate = (float)$direct['conversion_rate'];
                    if ($convRate <= 0) $convRate = 24;

                    if ($direct['is_box']) {
                        $max_servings = (int)floor($effQty / $convRate);
                        if ($max_servings <= 0) {
                            if ($rawQty < $convRate) {
                                $missing[] = $si['item_name'] . " (In stock: " . (int)$rawQty . " " . $direct['unit'] . ")";
                            } else {
                                $missing[] = $si['item_name'] . ' (All in cart)';
                            }
                        } elseif ($effQty <= (float)$si['alert_level'] || $max_servings <= 3) {
                            $low[] = $si['item_name'];
                        }
                    } else {
                        $max_servings = (int)floor($effQty);
                        if ($max_servings <= 0) {
                            if ($rawQty <= 0) {
                                $missing[] = $si['item_name'];
                            } else {
                                $missing[] = $si['item_name'] . ' (All in cart)';
                            }
                        } elseif ($effQty <= (float)$si['alert_level'] || $max_servings <= 10) {
                            $low[] = $si['item_name'];
                        }
                    }
                } elseif (is_direct_drink_product($p)) {
                    $max_servings = 100;
                } else {
                    $max_servings = 0;
                }
            }

            $status = 'in_stock';
            $reason = '';

            if (!$has_physical_recipe && !is_direct_drink_product($p)) {
                $status = 'out_of_stock';
                $reason = 'No recipe linked';
                $max_servings = 0;
            } elseif ((int)$p['is_available'] === 0) {
                $status = 'out_of_stock';
                $reason = 'Item marked unavailable';
            } elseif (!empty($missing) || ($max_servings !== null && $max_servings <= 0)) {
                $status = 'out_of_stock';
                if (!empty($missing)) {
                    $reason = 'Out of ' . implode(', ', array_unique($missing));
                } else {
                    $reason = 'Out of stock';
                }
            } elseif (!empty($low)) {
                $status = 'low_stock';
                $reason = 'Low on ' . implode(', ', array_unique($low));
            }

            $results[$pId] = [
                'product_id'   => $pId,
                'name'         => $pName,
                'status'       => $status,
                'reason'       => $reason,
                'max_servings' => $max_servings,
                'is_available' => ($status !== 'out_of_stock'),
            ];
        }

        return $results;
    }
}

/**
 * Strict Multi-Line Inventory Capacity Allocator & Clamper.
 * Accurately tracks ingredient & direct drink stock consumption across ALL cart lines.
 * Clamps any line item whose requested quantity exceeds remaining physical inventory.
 * Returns per-line max_stock allowances for frontend controls.
 */
if (!function_exists('reconcile_cart_stock')) {
    function reconcile_cart_stock(mysqli $conn, array &$cart): array {
        $line_max_stocks = [];
        if (empty($cart)) return $line_max_stocks;

        // 1. Load active stock inventory
        $stock_remaining = [];
        $si_res = $conn->query("SELECT item_id, item_name, quantity, item_type, category, unit, purchase_unit, conversion_rate FROM stock_items WHERE is_active = 1");
        if ($si_res) {
            while ($si = $si_res->fetch_assoc()) {
                $stock_remaining[(int)$si['item_id']] = [
                    'name'            => $si['item_name'],
                    'qty'             => (float)$si['quantity'],
                    'item_type'       => $si['item_type'],
                    'category'        => $si['category'],
                    'unit'            => $si['unit'],
                    'purchase_unit'   => $si['purchase_unit'],
                    'conversion_rate' => (float)($si['conversion_rate'] ?: 24),
                ];
            }
        }

        // 2. Load recipes for all products in cart
        $pids = [];
        foreach ($cart as $it) {
            $pid = (int)($it['product_id'] ?? 0);
            if ($pid > 0) $pids[$pid] = true;
        }

        $prod_recipes = [];
        if (!empty($pids)) {
            $pids_in = implode(',', array_keys($pids));
            $r_res = $conn->query("
                SELECT r.product_id, r.item_id, r.quantity_required, s.item_name, s.category, s.item_type
                FROM product_recipes r
                JOIN stock_items s ON r.item_id = s.item_id
                WHERE r.product_id IN ($pids_in)
                  AND s.is_active = 1
                  AND (s.item_name NOT LIKE '%Packaging Set%' AND s.item_name NOT LIKE '%ឈុត%' AND s.category != 'Packaging')
            ");
            if ($r_res) {
                while ($r = $r_res->fetch_assoc()) {
                    $prod_recipes[(int)$r['product_id']][] = [
                        'item_id'           => (int)$r['item_id'],
                        'quantity_required' => (float)$r['quantity_required'],
                    ];
                }
            }
        }

        // 3. Sequential Allocation & Clamping per line item
        foreach ($cart as $idx => &$cItem) {
            $pId = (int)($cItem['product_id'] ?? 0);
            $pName = $cItem['product_name'] ?? '';
            $requestedQty = max(1, min(100, (int)($cItem['qty'] ?? 1)));
            $recipes = $prod_recipes[$pId] ?? [];

            $max_possible_servings = 100;

            if (!empty($recipes)) {
                foreach ($recipes as $rec) {
                    $itemId = $rec['item_id'];
                    $req = (float)$rec['quantity_required'];
                    if ($req <= 0) continue;

                    $avail = $stock_remaining[$itemId]['qty'] ?? 0.0;
                    $servings = (int)floor($avail / $req);
                    if ($servings < $max_possible_servings) {
                        $max_possible_servings = max(0, $servings);
                    }
                }
            } else {
                $pRow = ['product_id' => $pId, 'name' => $pName];
                $direct = find_direct_stock_item_for_product($conn, $pRow);
                if ($direct) {
                    $itemId = $direct['item_id'];
                    $availBaseUnits = $stock_remaining[$itemId]['qty'] ?? 0.0;
                    $convRate = (float)$direct['conversion_rate'];
                    if ($convRate <= 0) $convRate = 24;

                    if ($direct['is_box']) {
                        $max_possible_servings = (int)floor($availBaseUnits / $convRate);
                    } else {
                        $max_possible_servings = (int)floor($availBaseUnits);
                    }
                    $max_possible_servings = max(0, $max_possible_servings);
                } elseif (is_direct_drink_product($pRow)) {
                    $max_possible_servings = 100;
                } else {
                    $max_possible_servings = 0;
                }
            }

            // Allowed quantity for this line item
            $allowedQty = min($requestedQty, $max_possible_servings);
            $cItem['qty'] = max(1, $allowedQty);
            $line_max_stocks[$idx] = min(100, $max_possible_servings);

            // Deduct allocated quantity from working stock pool
            if (!empty($recipes)) {
                foreach ($recipes as $rec) {
                    $itemId = $rec['item_id'];
                    $req = (float)$rec['quantity_required'];
                    if (isset($stock_remaining[$itemId])) {
                        $stock_remaining[$itemId]['qty'] = max(0.0, $stock_remaining[$itemId]['qty'] - ($req * $allowedQty));
                    }
                }
            } else {
                $pRow = ['product_id' => $pId, 'name' => $pName];
                $direct = find_direct_stock_item_for_product($conn, $pRow);
                if ($direct) {
                    $itemId = $direct['item_id'];
                    $convRate = (float)$direct['conversion_rate'];
                    if ($convRate <= 0) $convRate = 24;
                    $baseUnitsUsed = $direct['is_box'] ? ($allowedQty * $convRate) : $allowedQty;
                    if (isset($stock_remaining[$itemId])) {
                        $stock_remaining[$itemId]['qty'] = max(0.0, $stock_remaining[$itemId]['qty'] - $baseUnitsUsed);
                    }
                }
            }
        }
        unset($cItem);

        return $line_max_stocks;
    }
}

/**
 * Calculates and returns full formatted cart payload for POS & AJAX endpoints.
 */
if (!function_exists('get_cart_payload')) {
    function get_cart_payload(mysqli $conn): array {
        $cart = $_SESSION['cart'] ?? [];
        $line_max_stocks = function_exists('reconcile_cart_stock') ? reconcile_cart_stock($conn, $cart) : [];
        $_SESSION['cart'] = $cart;

        $subtotal = 0.0; $total_qty = 0; $item_promos = 0.0;
        $min_price = PHP_FLOAT_MAX; $cheapest_idx = -1;
        $_fpid = defined('FREE_ITEM_PRODUCT_ID') ? (int)FREE_ITEM_PRODUCT_ID : 0;
        $_fname = ''; $_fprice = 0.0; $_fidx = -1;

        $items_out = [];
        foreach ($cart as $i => $item) {
            $q = (int)($item['qty'] ?? 1);
            $p = (float)($item['price'] ?? 0);
            $pId = (int)($item['product_id'] ?? 0);

            $itemLineTotal = $p * $q;
            $subtotal += $itemLineTotal;
            $total_qty += $q;

            // Per-item manual discount
            $itemDisc = 0.0;
            $discType = $item['discount_type'] ?? '';
            $discAmt  = (float)($item['discount_amount'] ?? 0);

            if ($discAmt > 0) {
                if ($discType === 'flat') {
                    $itemDisc = min($itemLineTotal, $discAmt);
                } else {
                    $itemDisc = $itemLineTotal * (min(100, $discAmt) / 100.0);
                }
            } elseif ((int)($item['promo_percent'] ?? 0) > 0) {
                $origP = (float)($item['orig_price'] ?? $p);
                if ($origP > $p) {
                    $itemDisc = ($origP - $p) * $q;
                }
            }

            $item_promos += $itemDisc;

            if ($p < $min_price) { $min_price = $p; $cheapest_idx = $i; }
            if ($_fpid > 0 && $pId === $_fpid && $_fidx < 0) {
                $_fidx = $i; $_fname = $item['product_name'] ?? ''; $_fprice = $p;
            }

            // Check customization requirement
            $has_custom = isset($item['has_customization']) ? (int)$item['has_customization'] : null;
            if ($has_custom === null && $pId > 0) {
                $_chk_s = $conn->prepare("SELECT p.has_sizes, COALESCE(c.offer_sweetness, 0) AS offer_sweetness, COALESCE(c.offer_ice, 0) AS offer_ice FROM products p LEFT JOIN categories c ON (c.category_id = p.category_id OR c.slug = p.category OR c.name = p.category) WHERE p.product_id = ?");
                if ($_chk_s) {
                    $_chk_s->bind_param("i", $pId);
                    $_chk_s->execute();
                    if ($_chk_r = $_chk_s->get_result()->fetch_assoc()) {
                        $has_custom = ((int)$_chk_r['has_sizes'] === 1 || (int)$_chk_r['offer_sweetness'] === 1 || (int)$_chk_r['offer_ice'] === 1) ? 1 : 0;
                    }
                    $_chk_s->close();
                }
            }
            if ($has_custom === null) {
                $has_custom = (!empty($item['sweetness']) || !empty($item['ice']) || !empty($item['size_label'])) ? 1 : 0;
            }

            $lineMaxStock = $line_max_stocks[$i] ?? 100;

            $items_out[] = [
                'index'             => $i,
                'product_id'        => $pId,
                'product_name'      => $item['product_name'] ?? '',
                'has_customization' => (int)$has_custom,
                'price'             => $p,
                'orig_price'        => (float)($item['orig_price'] ?? $p),
                'promo_percent'     => (int)($item['promo_percent'] ?? 0),
                'qty'               => $q,
                'max_stock'         => $lineMaxStock,
                'image'             => $item['image'] ?? '',
                'size_code'         => $item['size_code']  ?? '',
                'size_label'        => $item['size_label'] ?? '',
                'sweetness'         => $item['sweetness'] ?? '',
                'ice'               => $item['ice'] ?? '',
                'milk'              => $item['milk'] ?? '',
                'addons'            => $item['addons'] ?? [],
                'discount_type'     => $discType,
                'discount_amount'   => $discAmt,
                'item_discount'     => round($itemDisc, 2),
                'lineTotal'         => round($itemLineTotal, 2),
            ];
        }

        // If configured free item isn't in cart, fetch its name/price from DB
        if ($_fpid > 0 && $_fname === '') {
            $_fp_s = $conn->prepare("SELECT name, price FROM products WHERE product_id = ?");
            if ($_fp_s) { 
                $_fp_s->bind_param("i", $_fpid); 
                $_fp_s->execute();
                if ($_fp_r = $_fp_s->get_result()->fetch_assoc()) { 
                    $_fname = $_fp_r['name']; 
                    $_fprice = (float)$_fp_r['price']; 
                }
                $_fp_s->close(); 
            }
        }
        $cheapest_name  = ($cheapest_idx >= 0) ? ($cart[$cheapest_idx]['product_name'] ?? '') : '';
        $cheapest_price = ($cheapest_idx >= 0 && $min_price < PHP_FLOAT_MAX) ? $min_price : 0.0;
        $free_name  = ($_fpid > 0 && $_fname !== '') ? $_fname : $cheapest_name;
        $free_price = ($_fpid > 0 && $_fprice > 0) ? $_fprice : $cheapest_price;

        $_free_idx = ($_fpid > 0 && $_fidx >= 0) ? $_fidx : $cheapest_idx;
        $buy3_enabled = defined('BUY_X_GET_1_ENABLED') && BUY_X_GET_1_ENABLED;
        $buy3_count   = defined('BUY_X_COUNT') ? (int)BUY_X_COUNT : 3;
        $buy3 = ($buy3_enabled && $total_qty >= $buy3_count && $min_price < PHP_FLOAT_MAX && $_free_idx >= 0)
            ? floor($total_qty / $buy3_count) * $free_price : 0.0;

        $hh_enabled  = defined('HAPPY_HOUR_ENABLED') && HAPPY_HOUR_ENABLED;
        $hh_start    = defined('HAPPY_HOUR_START') ? (int)HAPPY_HOUR_START : 14;
        $hh_end      = defined('HAPPY_HOUR_END') ? (int)HAPPY_HOUR_END : 17;
        $hh_discount = defined('HAPPY_HOUR_DISCOUNT') ? (float)HAPPY_HOUR_DISCOUNT : 0;
        $hh = 0.0;
        if ($hh_enabled && (int)date('H') >= $hh_start && (int)date('H') < $hh_end) {
            $hh = ($subtotal - $item_promos) * ($hh_discount / 100);
        }

        $after = max(0, $subtotal - $item_promos - $hh);
        $md = $_SESSION['manual_discount'] ?? null;
        $manual = 0.0; $manual_label = '';
        if ($md && (float)($md['amount'] ?? 0) > 0) {
            $manual = $md['type'] === 'flat'
                ? min((float)$md['amount'], max(0, $after))
                : max(0, $after) * ((float)$md['amount'] / 100.0);
            $r = trim($md['reason'] ?? ''); $manual_label = $r ?: 'Discount';
            if ($md['type'] === 'percent') $manual_label .= ' (' . (int)$md['amount'] . '% off)';
            $after -= $manual;
        }
        $tax_rate = defined('TAX_RATE') ? (float)TAX_RATE : 0.0;
        $tax   = $after * ($tax_rate / 100);
        $total = round($after + $tax, 2);

        return [
            'items'          => $items_out,
            'count'          => $total_qty,
            'subtotal'       => number_format($subtotal, 2, '.', ''),
            'item_promos'    => number_format($item_promos, 2, '.', ''),
            'buy3'           => number_format($buy3, 2, '.', ''),
            'buy3_name'      => $free_name,
            'buy3_price'     => number_format($free_price, 2, '.', ''),
            'buy3_count'     => $buy3_count,
            'happy_hour'     => number_format($hh, 2, '.', ''),
            'happy_hour_pct' => $hh_discount,
            'manual'         => number_format($manual, 2, '.', ''),
            'manual_label'   => $manual_label,
            'tax'            => number_format($tax, 2, '.', ''),
            'total'          => number_format($total, 2, '.', ''),
            'statuses'       => evaluate_products_stock($conn, $cart),
        ];
    }
}

/**
 * Cost per unit for every ingredient, keyed by id AND by lowercased name.
 * The name key exists because recipes name their milk by type at order time.
 * cost_per_unit is derived (cost_price / purchase_qty) and is authoritative;
 * dividing is only a fallback for rows that predate it being populated.
 */
if (!function_exists('ingredient_cost_map')) {
    function ingredient_cost_map(mysqli $conn): array {
        return [];
    }
}

if (!function_exists('order_cogs')) {
    function order_cogs(mysqli $conn, array $orderIds, array $costMap): array {
        return ['total' => 0.0, 'items' => 0, 'gift_items' => 0, 'by_product' => []];
    }
}

/**
 * Cups actually poured, from an order_cogs() result.
 *
 * order_cogs()['items'] counts every line in the order, loyalty redemptions
 * included, which is right for costing and wrong for a card headed "cups sold":
 * a shirt handed over for points is not a cup. On 1 June that gap was 22 of 92.
 *
 * This drops the handful of redemptions that were free *drinks* along with the
 * shirts and toys — three lines in the entire database — because product_id is
 * the only trustworthy signal and it cannot tell one gift from another. Three
 * cups understated beats thirty-eight overstated.
 *
 * Exists as one function rather than a subtraction repeated at each call site
 * so tab 1, tab 2, the PDF and the spreadsheet cannot drift apart. They already
 * did once: tab 1 said 70 cups while tab 2 said 92 on the same day.
 */
if (!function_exists('cogs_cups')) {
    function cogs_cups(array $cogs): int {
        return max(0, (int)($cogs['items'] ?? 0) - (int)($cogs['gift_items'] ?? 0));
    }
}

/**
 * How many drinks on an order actually earn loyalty points.
 *
 * Earning lines only: a category flagged earns_points = 0 (merch) never earns,
 * a zero-priced gift line never earns, and product_id = 0 is the only reliable
 * gift test — the "[GIFT] " name prefix misses six older rows, and price = 0
 * also matches a buy-X-get-1-free promo drink, which IS a real cup.
 *
 * This exists because two of the four award sites did not filter at all:
 * admin_pay_cash.php and check_payment.php counted SUM(quantity), so a
 * pay-later order awarded points for a T-shirt and for the free gift drink
 * itself, while the same basket paid up front awarded neither.
 */
if (!function_exists('loyalty_earning_qty')) {
    function loyalty_earning_qty(mysqli $conn, int $order_id): int {
        $q = $conn->prepare("SELECT COALESCE(SUM(quantity), 0)
                             FROM order_items
                             WHERE order_id = ? AND earns_points = 1
                               AND price > 0 AND product_id <> 0");
        $q->bind_param('i', $order_id);
        $q->execute();
        return (int)($q->get_result()->fetch_row()[0] ?? 0);
    }
}

if (!function_exists('loyalty_earning_units')) {
    function loyalty_earning_units(mysqli $conn, int $order_id): int {
        if (LOYALTY_MODE === 'spend') {
            $q = $conn->prepare("SELECT total FROM orders WHERE order_id = ?");
            $q->bind_param('i', $order_id);
            $q->execute();
            $tot = (float)($q->get_result()->fetch_row()[0] ?? 0);
            return (int)floor($tot);
        } else {
            return loyalty_earning_qty($conn, $order_id);
        }
    }
}

if (!function_exists('loyalty_resolve_card_id')) {
    function loyalty_resolve_card_id(mysqli $conn, int $card_id): int {
        return 0;
    }
}

if (!function_exists('loyalty_sync')) {
    function loyalty_sync(mysqli $conn, int $card_id, int $order_id, int $qty_total, string $note): int {
        return 0;
    }
}

/**
 * Deduct ingredient stock for one drink.
 *
 * Deducts and logs only what is actually on hand (never goes negative, never logs
 * a phantom full deduction when short). Returns a list of shortfalls so the caller
 * can warn staff: each ['name' => ingredient, 'need' => required, 'had' => available].
 *
 * Lives here rather than in confirm_order.php because remake_order.php needs the
 * same arithmetic, including the milk substitution — a remake can change the milk.
 * One writer, never a copy.
 *
 * $reference overrides the ledger reference. A remake passes its own so a second
 * deduction against one order is identifiable, without adding a change_type that
 * the five deduct classifiers in ingredient_history.php would each have to learn.
 */
if (!function_exists('_deduct_stock')) {
    function _deduct_stock(mysqli $conn, int $product_id, int $qty, string $milk_choice,
                           int $order_id = 0, float $size_factor = 1.0,
                           ?string $reference = null, string $sweetness = '', string $ice = ''): array {
        if ($product_id <= 0 || $qty <= 0) return [];
        $warnings = [];
        try {
            // 1. Resolve Sweetness Factor
            $sweetnessFactor = 1.0;
            $swNorm = str_replace(' ', '', strtolower(trim((string)$sweetness)));
            if ($swNorm === '0%' || $swNorm === '0' || $swNorm === 'nosugar' || $swNorm === 'គ្មានស្ករ') {
                $sweetnessFactor = 0.0;
            } elseif ($swNorm === '25%' || $swNorm === '25') {
                $sweetnessFactor = 0.25;
            } elseif ($swNorm === '50%' || $swNorm === '50') {
                $sweetnessFactor = 0.50;
            } elseif ($swNorm === '75%' || $swNorm === '75') {
                $sweetnessFactor = 0.75;
            } elseif ($swNorm === '100%' || $swNorm === '100') {
                $sweetnessFactor = 1.0;
            }

            // 2. Resolve Ice Factor
            $iceFactor = 1.0;
            $iceNorm = strtolower(trim((string)$ice));
            if (str_contains($iceNorm, 'no ice') || str_contains($iceNorm, 'គ្មានទឹកកក') || $iceNorm === 'no') {
                $iceFactor = 0.0;
            } elseif (str_contains($iceNorm, 'less ice') || str_contains($iceNorm, 'ទឹកកកតិច')) {
                $iceFactor = 0.5;
            } elseif (str_contains($iceNorm, 'more ice') || str_contains($iceNorm, 'extra ice') || str_contains($iceNorm, 'ទឹកកកច្រើន')) {
                $iceFactor = 1.3;
            } elseif (str_contains($iceNorm, 'normal') || str_contains($iceNorm, 'ធម្មតា')) {
                $iceFactor = 1.0;
            }

            // Fetch product name for human-readable audit ledger notes
            $productName = "Product #{$product_id}";
            $pnStmt = $conn->prepare("SELECT name FROM products WHERE product_id = ? LIMIT 1");
            if ($pnStmt) {
                $pnStmt->bind_param("i", $product_id);
                $pnStmt->execute();
                if ($pnRow = $pnStmt->get_result()->fetch_assoc()) {
                    $productName = $pnRow['name'];
                }
                $pnStmt->close();
            }

            // 3. Deduct using product_recipes BOM if recipe exists
            $stmt = $conn->prepare("SELECT r.item_id, r.quantity_required, s.item_name, s.category, s.quantity AS current_stock, s.alert_level, s.cost_per_unit, s.unit 
                                    FROM product_recipes r 
                                    JOIN stock_items s ON r.item_id = s.item_id 
                                    WHERE r.product_id = ? AND s.is_active = 1");
            if ($stmt) {
                $stmt->bind_param("i", $product_id);
                $stmt->execute();
                $res = $stmt->get_result();
                $upd = $conn->prepare("UPDATE stock_items SET quantity = GREATEST(0, quantity - ?) WHERE item_id = ?");
                $log = $conn->prepare("INSERT INTO stock_logs (item_id, order_id, product_id, change_type, quantity_changed, stock_before, stock_after, cost_at_time, notes, created_by) VALUES (?, ?, ?, 'sale_deduct', ?, ?, ?, ?, ?, ?)");
                $perf = $_SESSION['username'] ?? 'POS';

                $hasBOM = false;
                $hasSugarInRecipe = false;
                $hasIceInRecipe = false;

                while ($row = $res->fetch_assoc()) {
                    $hasBOM = true;
                    $itemId = (int)$row['item_id'];
                    $iName = strtolower($row['item_name'] ?? '');
                    $cat = $row['category'] ?? '';

                    // Skip Auto Packaging Sets from physical stock deduction
                    if (str_contains($iName, 'packaging set') || str_contains($row['item_name'], 'ឈុត')) {
                        continue;
                    }

                    $baseQty = (float)$row['quantity_required'];
                    $currentStock = (float)$row['current_stock'];
                    $alertLevel = (float)$row['alert_level'];
                    $cost = (float)$row['cost_per_unit'];
                    $itemName = $row['item_name'];
                    $unit = $row['unit'];

                    // Milk Substitution (e.g. Oat Milk)
                    $milkNorm = strtolower(trim($milk_choice));
                    if (!empty($milkNorm) && (str_contains($iName, 'milk') || str_contains($iName, 'ទឹកដោះគោ')) && !str_contains($iName, 'oat') && str_contains($milkNorm, 'oat')) {
                        $subStmt = $conn->prepare("SELECT item_id, item_name, quantity, alert_level, cost_per_unit, unit FROM stock_items WHERE LOWER(item_name) LIKE '%oat milk%' AND is_active = 1 LIMIT 1");
                        if ($subStmt) {
                            $subStmt->execute();
                            if ($subRow = $subStmt->get_result()->fetch_assoc()) {
                                $itemId = (int)$subRow['item_id'];
                                $itemName = $subRow['item_name'];
                                $currentStock = (float)$subRow['quantity'];
                                $alertLevel = (float)$subRow['alert_level'];
                                $cost = (float)$subRow['cost_per_unit'];
                                $unit = $subRow['unit'];
                            }
                            $subStmt->close();
                        }
                    }

                    // Sugar / Sweetness customization multiplier
                    $customMultiplier = 1.0;
                    if (str_contains($iName, 'sugar') || str_contains($iName, 'syrup') || str_contains($row['item_name'], 'ស្ករ') || str_contains($row['item_name'], 'ទឹកស្ករ') || $cat === 'Syrups') {
                        $hasSugarInRecipe = true;
                        $customMultiplier = $sweetnessFactor;
                    } elseif (str_contains($iName, 'ice') || str_contains($row['item_name'], 'ទឹកកក') || $cat === 'Ice') {
                        $hasIceInRecipe = true;
                        $customMultiplier = $iceFactor;
                    }

                    $req = $baseQty * (float)$qty * (float)$size_factor * $customMultiplier;

                    // If 0% sugar or No Ice, requirement is 0
                    if ($req <= 0) {
                        continue;
                    }

                    $after = max(0.0, $currentStock - $req);

                    $upd->bind_param("di", $req, $itemId);
                    $upd->execute();

                    $chg = -$req;
                    $notes = "Order #{$order_id}: Used for {$productName} (x{$qty})";
                    if (!empty($reference)) $notes .= " [{$reference}]";
                    if ($customMultiplier !== 1.0) {
                        $notes .= " (Custom: factor {$customMultiplier})";
                    }

                    $log->bind_param("iiidddsss", $itemId, $order_id, $product_id, $chg, $currentStock, $after, $cost, $notes, $perf);
                    $log->execute();

                    if ($after <= $alertLevel) {
                        $warnings[] = "Low stock on {$itemName}: only {$after} {$unit} left.";
                    }
                }
                $stmt->close();

                // Dynamic Fallback: Deduct Sugar Syrup if sweetness was selected and not in recipe
                if (!$hasSugarInRecipe && $sweetness !== '' && $sweetnessFactor > 0) {
                    $sugStmt = $conn->prepare("SELECT item_id, item_name, quantity, alert_level, cost_per_unit, unit FROM stock_items WHERE (item_name LIKE '%sugar%' OR item_name LIKE '%syrup%' OR item_name LIKE '%ទឹកស្ករ%' OR category = 'Syrups') AND is_active = 1 LIMIT 1");
                    if ($sugStmt) {
                        $sugStmt->execute();
                        if ($sugRow = $sugStmt->get_result()->fetch_assoc()) {
                            $sugId = (int)$sugRow['item_id'];
                            $reqSug = 20.0 * $sweetnessFactor * (float)$qty * (float)$size_factor; // Base 20ml
                            $curSug = (float)$sugRow['quantity'];
                            $afterSug = max(0.0, $curSug - $reqSug);
                            $costSug = (float)$sugRow['cost_per_unit'];

                            $upd->bind_param("di", $reqSug, $sugId);
                            $upd->execute();

                            $chgSug = -$reqSug;
                            $notesSug = "Order #{$order_id}: Custom Sugar ({$sweetness}) for {$productName} (x{$qty})";
                            if (!empty($reference)) $notesSug .= " [{$reference}]";

                            $log->bind_param("iiidddsss", $sugId, $order_id, $product_id, $chgSug, $curSug, $afterSug, $costSug, $notesSug, $perf);
                            $log->execute();

                            if ($afterSug <= (float)$sugRow['alert_level']) {
                                $warnings[] = "Low stock on {$sugRow['item_name']}: only {$afterSug} {$sugRow['unit']} left.";
                            }
                        }
                        $sugStmt->close();
                    }
                }

                // Dynamic Fallback: Deduct Ice if ice was selected and not in recipe
                if (!$hasIceInRecipe && $ice !== '' && $iceFactor > 0) {
                    $iceStmt = $conn->prepare("SELECT item_id, item_name, quantity, alert_level, cost_per_unit, unit FROM stock_items WHERE (item_name LIKE '%ice%' OR item_name LIKE '%ទឹកកក%') AND is_active = 1 LIMIT 1");
                    if ($iceStmt) {
                        $iceStmt->execute();
                        if ($iceRow = $iceStmt->get_result()->fetch_assoc()) {
                            $iceId = (int)$iceRow['item_id'];
                            $reqIce = 150.0 * $iceFactor * (float)$qty * (float)$size_factor; // Base 150g
                            $curIce = (float)$iceRow['quantity'];
                            $afterIce = max(0.0, $curIce - $reqIce);
                            $costIce = (float)$iceRow['cost_per_unit'];

                            $upd->bind_param("di", $reqIce, $iceId);
                            $upd->execute();

                            $chgIce = -$reqIce;
                            $notesIce = "Order #{$order_id}: Custom Ice ({$ice}) for {$productName} (x{$qty})";
                            if (!empty($reference)) $notesIce .= " [{$reference}]";

                            $log->bind_param("iiidddsss", $iceId, $order_id, $product_id, $chgIce, $curIce, $afterIce, $costIce, $notesIce, $perf);
                            $log->execute();

                            if ($afterIce <= (float)$iceRow['alert_level']) {
                                $warnings[] = "Low stock on {$iceRow['item_name']}: only {$afterIce} {$iceRow['unit']} left.";
                            }
                        }
                        $iceStmt->close();
                    }
                }

                // 4. Direct Drink Fallback: If no recipe exists, check direct drink stock match by name
                if (!$hasBOM) {
                    $pStmt = $conn->prepare("SELECT name, price FROM products WHERE product_id = ?");
                    if ($pStmt) {
                        $pStmt->bind_param("i", $product_id);
                        $pStmt->execute();
                        $pRes = $pStmt->get_result();
                        if ($pRow = $pRes->fetch_assoc()) {
                            $pName = trim($pRow['name']);
                            $pPrice = (float)($pRow['price'] ?? 0);

                            // Detect if this product is sold as a BOX (e.g. contains (Box), (កេស), (Carton), (កាតុង) or Box/កេស)
                            $isBoxSale = false;
                            if (preg_match('/\((?:Box|កេស|កាតុង|Carton|Case)\)/ui', $pName) ||
                                preg_match('/\b(?:Box|Carton|Case)\b/ui', $pName) ||
                                preg_match('/(?:កេស|កាតុង)/u', $pName)) {
                                $isBoxSale = true;
                            }

                            // Clean product name to match base stock item
                            $cleanBase = trim(preg_replace('/\s*\((?:Box|កេស|កាតុង|Carton|Case|Unit|កំប៉ុង|ដប)\)/ui', '', $pName));
                            $cleanBase = trim(preg_replace('/\s+(?:Box|កេស|កាតុង|Carton|Case)$/ui', '', $cleanBase));

                            $dStmt = $conn->prepare("SELECT item_id, item_name, quantity, alert_level, cost_per_unit, unit, purchase_unit, conversion_rate, selling_price_per_box, selling_price_per_unit 
                                                     FROM stock_items 
                                                     WHERE item_type = 'direct_drink' AND is_active = 1 
                                                       AND (
                                                         LOWER(REPLACE(item_name, ' ', '')) = LOWER(REPLACE(?, ' ', '')) 
                                                         OR LOWER(REPLACE(item_name, ' ', '')) = LOWER(REPLACE(?, ' ', ''))
                                                         OR item_name LIKE ? 
                                                         OR ? LIKE CONCAT('%', item_name, '%')
                                                         OR ? LIKE CONCAT('%', item_name, '%')
                                                       ) 
                                                     LIMIT 1");
                            if ($dStmt) {
                                $wildName = "%{$cleanBase}%";
                                $dStmt->bind_param("sssss", $cleanBase, $pName, $wildName, $cleanBase, $pName);
                                $dStmt->execute();
                                $dRes = $dStmt->get_result();
                                if ($dRow = $dRes->fetch_assoc()) {
                                    $itemId = (int)$dRow['item_id'];
                                    $convRate = (float)($dRow['conversion_rate'] ?? 24);
                                    if ($convRate <= 0) $convRate = 24;

                                    // Secondary check: if product price is close to box selling price
                                    $sellBox = (float)($dRow['selling_price_per_box'] ?? 0);
                                    $sellUnit = (float)($dRow['selling_price_per_unit'] ?? 0);
                                    if (!$isBoxSale && $sellBox > 0) {
                                        if (abs($pPrice - $sellBox) < 0.01 || ($sellUnit > 0 && $pPrice >= $sellUnit * ($convRate * 0.7))) {
                                            $isBoxSale = true;
                                        }
                                    }

                                    // Calculate total single units to deduct from stock
                                    if ($isBoxSale) {
                                        $req = (float)$qty * $convRate;
                                        $pUnit = !empty($dRow['purchase_unit']) ? $dRow['purchase_unit'] : 'boxes';
                                        $uUnit = !empty($dRow['unit']) ? $dRow['unit'] : 'units';
                                        $notes = "Order #{$order_id}: Direct Drink Box Sale ({$qty} {$pUnit} = {$req} {$uUnit}) for {$pName}";
                                    } else {
                                        $req = (float)$qty;
                                        $notes = "Order #{$order_id}: Direct Drink Unit Sale for {$pName} (x{$qty})";
                                    }

                                    $cur = (float)$dRow['quantity'];
                                    $after = max(0.0, $cur - $req);
                                    $cost = (float)$dRow['cost_per_unit'];

                                    $upd->bind_param("di", $req, $itemId);
                                    $upd->execute();

                                    $chg = -$req;
                                    if (!empty($reference)) $notes .= " [{$reference}]";

                                    $log->bind_param("iiidddsss", $itemId, $order_id, $product_id, $chg, $cur, $after, $cost, $notes, $perf);
                                    $log->execute();

                                    if ($after <= (float)$dRow['alert_level']) {
                                        $warnings[] = "Low stock on {$dRow['item_name']}: only {$after} {$dRow['unit']} left.";
                                    }
                                }
                                $dStmt->close();
                            }
                        }
                        $pStmt->close();
                    }
                }

                if ($upd) $upd->close();
                if ($log) $log->close();
            }
        } catch (Throwable $e) {
            error_log("_deduct_stock error: " . $e->getMessage());
        }
        return $warnings;
    }
}


/**
 * What an order's status means for FULFILMENT, as opposed to money.
 *
 * orders.status mixes the two: 'Paid' answers "has the money arrived", which tells
 * a barista or a customer nothing about whether the drink is made. Translate it:
 *
 *   Paid + is_open=1 → Preparing   paid up front, still to be made
 *   Paid + is_open=0 → Completed   settled and closed, nothing outstanding
 *
 * Everything else already names a fulfilment state and passes through.
 *
 * The PHP twin of boardState() in view_order.php:1862. Keep the two in step — every
 * screen that answers "is this drink made?" must give the same answer. Screens about
 * money (find_order, the payment pages, the reports) read the raw status on purpose.
 */
if (!function_exists('order_board_state')) {
    function order_board_state(?string $status, $is_open): string {
        if ($status === 'Paid') return ((int)$is_open === 1) ? 'Preparing' : 'Completed';
        return (string)$status;
    }
}

/**
 * The payment methods this system recognises.
 *
 * orders.payment_method is a varchar with no constraint, so whatever a POST
 * supplied was stored verbatim. 195 orders from 2026-05-27..30 carry the literal
 * string '0' as a result: they count toward revenue but match no method, so a
 * by-method breakdown for those dates is $430.81 short of its own total. Nothing
 * validated the write, so nothing stopped it.
 *
 * 'riel' is still listed because 4 historical orders use it. It is scheduled to be
 * folded into cash — do not add it to new UI.
 */
if (!function_exists('order_payment_methods')) {
    function order_payment_methods(): array {
        return ['cash', 'bakong', 'paylater', 'riel'];
    }
}

/**
 * Coerce a submitted payment method to a known one.
 *
 * Falls back rather than throwing: a checkout that has already taken the customer's
 * money must not die on an unrecognised label. The fallback is recorded honestly by
 * the caller instead of being written as an unusable value.
 */
if (!function_exists('order_payment_method_or')) {
    function order_payment_method_or(?string $method, string $fallback = 'cash'): string {
        $method = strtolower(trim((string)$method));
        return in_array($method, order_payment_methods(), true) ? $method : $fallback;
    }
}

/**
 * A cash tender, recorded in two currencies.
 *
 * order_payments.reference has always held one bare number for a cash tender —
 * the dollars handed over — and receipts read it back to print Received and
 * Change. Taking riel as well needs two numbers in one column.
 *
 * The bare number is kept for a dollars-only tender so the 49 existing rows,
 * and every future dollars-only sale, write and read byte-identically. The
 * two-part form appears only when riel is actually involved.
 *
 * Riel is not a separate payment method here. The shop has one drawer; splitting
 * it across two tender types made the counter and the checkout disagree.
 */
if (!function_exists('tender_ref')) {
    function tender_ref(float $usd, int $khr): string {
        $usd = max(0, $usd);
        $khr = max(0, $khr);
        if ($usd <= 0 && $khr <= 0) { return ''; }
        if ($khr <= 0) { return number_format($usd, 2, '.', ''); }
        return number_format($usd, 2, '.', '') . '|' . $khr;
    }
}

/**
 * Read a stored tender back, or null if the value is not one.
 *
 * Exactly two shapes are recognised. Everything else — a Bakong transaction id,
 * an empty reference, a malformed string — returns null, so no reader can
 * mistake a non-tender for money. This replaces is_numeric(), which would have
 * accepted a bare '22000' written by anything at all.
 */
if (!function_exists('tender_parts')) {
    function tender_parts(?string $ref): ?array {
        $ref = trim((string)$ref);
        if ($ref === '') { return null; }
        if (preg_match('/^(\d+(?:\.\d+)?)$/', $ref, $m)) {
            return ['usd' => (float)$m[1], 'khr' => 0];
        }
        if (preg_match('/^(\d+(?:\.\d+)?)\|(\d+)$/', $ref, $m)) {
            return ['usd' => (float)$m[1], 'khr' => (int)$m[2]];
        }
        return null;
    }
}

/**
 * What a stored tender is worth in dollars. Zero for anything that is not one.
 *
 * The riel half is converted at the CURRENT KHR_RATE, not at the rate that was
 * in force when the sale was rung up — the rate is a live setting and nothing
 * is stamped on the row. If the shop changes the rate between a sale and a
 * receipt reprint, the reprinted dollar equivalent moves. The raw riel figure
 * is what the customer actually handed over and stays correct either way, which
 * is why the reference stores riel and not a converted dollar amount.
 */
if (!function_exists('tender_usd_total')) {
    function tender_usd_total(?string $ref): float {
        $p = tender_parts($ref);
        if ($p === null) { return 0.0; }
        return $p['usd'] + ($p['khr'] / KHR_RATE);
    }
}

/**
 * Did the customer pay entirely in riel?
 *
 * The one definition of that question, because tender_change() now branches on
 * it and EVERY call site — two live screens, two receipt sites, the counter
 * settlement page and the drawer figure — has to answer it identically. The
 * last time a rule like this was spelled out at more than one site the copies
 * drifted and the same sale read $4.00 on screen and $3.99 on the receipt. One
 * function, called from all of them.
 *
 * Zero dollars AND positive riel. A zero tender is not riel-only: nothing was
 * handed over, so nothing was handed over in riel, and treating it as riel
 * would put the change path in the wrong branch on an empty field. Null parts
 * (a Bakong reference, an unparsable string) are not a tender at all.
 */
if (!function_exists('tender_is_riel_only')) {
    function tender_is_riel_only(?array $parts): bool {
        if ($parts === null) { return false; }
        return (float)($parts['usd'] ?? 0) <= 0 && (int)($parts['khr'] ?? 0) > 0;
    }
}

/**
 * What the cashier physically hands back.
 *
 * Split the way it actually happens in Cambodia: whole dollars as notes, the
 * remainder under a dollar in riel, because no US coins circulate. Showing
 * "$3.66 or ៛15,000" and letting the cashier decide would leave the mental
 * arithmetic this screen exists to remove.
 *
 * The riel rounds to ៛100, the smallest note in practice, so a handover can be
 * a few cents either side of exact. That is already true of the ៛ total shown on
 * every screen. When the rounding fills a whole dollar it is promoted to a
 * dollar bill rather than handed over as 4,100 riel in small notes.
 *
 * FOLLOW THE CURRENCY ($all_riel). A customer who paid entirely in riel gets
 * change entirely in riel. Handing dollars back to someone who paid in riel
 * converts currency on them at the shop's rate without being asked — the
 * customer walks out holding a currency they did not choose, on a conversion
 * they never agreed to, and if they want their riel back they pay the spread
 * twice. A shop gives back what it was given. Every other tender (dollars-only,
 * or dollars and riel mixed) keeps the dollars-first split above: dollars were
 * in the exchange, so dollars may come back.
 *
 * NO CARRY on this path, deliberately. The carry above promotes a riel
 * remainder that fills a whole dollar into a dollar note; letting it run here
 * would hand a dollar back to a riel payer and reintroduce exactly what the
 * rule forbids. The whole change is one riel figure rounded to ៛100 — the same
 * smallest-note rounding, applied once instead of to a sub-dollar remainder.
 *
 * The flag is a parameter and not something this function derives, because the
 * live screens hold two input fields and no reference string yet; they answer
 * tender_is_riel_only() from the fields, the stored-reference callers answer it
 * from tender_parts(). Defaulting to false keeps every unconverted caller on
 * the old path.
 *
 * Short tenders report short and hand back nothing. The order still settles in
 * full — a cashier who has already counted the change must not be blocked by a
 * field they skipped.
 */
if (!function_exists('tender_change')) {
    function tender_change(float $received_usd_total, float $owed, bool $all_riel = false): array {
        $change = round($received_usd_total - $owed, 4);
        if ($change <= 0) {
            return ['usd' => 0, 'khr' => 0, 'short' => $change < 0];
        }
        if ($all_riel || $change < 10.0) {
            return [
                'usd'   => 0,
                'khr'   => (int)(round(($change * KHR_RATE) / 100) * 100),
                'short' => false,
            ];
        }
        $dollars = (int)(floor($change / 10) * 10);
        $riel    = (int)(round((($change - $dollars) * KHR_RATE) / 100) * 100);
        if ($riel >= (10 * KHR_RATE)) { $dollars += 10; $riel = 0; }
        return ['usd' => $dollars, 'khr' => $riel, 'short' => false];
    }
}

/**
 * The change line, as one string. The PHP twin of tender.js tenderChangeText().
 *
 * This existed three times, hand-copied into receipt_pdf.php twice and
 * payment_cash.php once, and the copies had already drifted: the dollars-only
 * branch printed the RAW received-minus-owed difference instead of $ch['usd'],
 * so a $5.33 tender on a $1.34 bill printed $3.99 on the receipt while the
 * screen said $4.00. The carry inside tender_change() had promoted the riel
 * remainder to a whole dollar and the copies never saw it. One function now.
 *
 * DELIBERATE DIFFERENCE FROM THE JS TWIN: the currency is spelled 'KHR', not
 * '៛'. This string goes into dompdf output, whose bundled fonts have no Khmer
 * glyph, and a missing glyph on a receipt is worse than an ASCII prefix. The
 * screen keeps the ៛ symbol via tenderChangeText(). The NUMBERS must agree; the
 * spelling is allowed not to.
 *
 * A short tender reads '$0.00' here rather than the JS 'Need $x more': a
 * receipt is printed after the money has changed hands and has no owed/received
 * pair to subtract, and telling the customer they still owe money on a settled
 * bill would be worse than saying nothing.
 */
if (!function_exists('tender_change_text')) {
    function tender_change_text(array $ch): string {
        $usd = (int)($ch['usd'] ?? 0);
        $khr = (int)($ch['khr'] ?? 0);
        if ($usd > 0 && $khr > 0) {
            return '$' . $usd . ' + KHR ' . number_format($khr);
        }
        if ($khr > 0) {
            return 'KHR ' . number_format($khr);
        }
        if ($usd > 0) {
            return '$' . number_format($usd, 2);
        }
        return '$0.00';
    }
}

/**
 * The received line, as one string. Same spelling rule as tender_change_text().
 *
 * Takes the parsed parts so the two currencies can be shown as the customer
 * actually handed them over, and the dollar total as the fallback for a
 * dollars-only or unparsed tender. Passing null parts is the legacy path: a row
 * whose reference is not a recognised tender still has a dollar figure to show.
 */
if (!function_exists('tender_received_text')) {
    function tender_received_text(?array $parts, float $usd_total): string {
        if ($parts !== null && (int)$parts['khr'] > 0) {
            return (float)$parts['usd'] > 0
                ? '$' . number_format((float)$parts['usd'], 2) . ' + KHR ' . number_format((int)$parts['khr'])
                : 'KHR ' . number_format((int)$parts['khr']);
        }
        return '$' . number_format($usd_total, 2);
    }
}

/**
 * The dollar-equivalent of riel taken on cash sales, which never entered the
 * dollar drawer.
 *
 * Riel is not its own payment method any more — a riel sale is recorded as
 * payment_method='cash' with the riel half in the reference. That is right for
 * revenue (one drawer, one bucket) and wrong for the drawer COUNT, which the
 * cashier does in dollars: shift_report.php's expected-cash figure is the sum of
 * cash-method payments, so a $1.34 sale paid entirely in ៛5,500 told the cashier
 * to expect $1.34 more in dollar notes than the till could possibly hold. That
 * variance is written to cash_counts and dashboard.php raises a shortage alert
 * on it — a permanent financial record accusing a cashier of a shortage that is
 * arithmetic, not money. Before this feature the old payment_method='riel' rows
 * were excluded from the cash bucket outright and the figure was correct.
 *
 * NET of the riel handed back, and WHICH currency comes back matters here, so
 * this passes tender_is_riel_only() through to tender_change() exactly as the
 * screens and the receipts do. Get that flag wrong and this figure silently
 * disagrees with the change the cashier actually counted out.
 *
 * A riel-ONLY sale now nets to the order total, and that is the point. ៛20,000
 * on a $1.34 bill hands back ៛14,500, so ៛5,500 stays — worth $1.34, the bill
 * exactly. shift_report.php subtracts this from the cash bucket, which holds
 * that same $1.34, so the sale contributes nothing to the expected DOLLAR
 * drawer. Correct: no dollar note ever moved. (To the nearest ៛100, which is the
 * smallest note handled: the change rounding leaves up to ±៛50, about ±$0.012 a
 * sale, so a riel-heavy shift will not reconcile to the exact cent. It is a
 * rounding residue, not a shortage.) Under the old dollars-first rule
 * the same sale handed back $3 in notes, left ៛17,800 (worth $4.34) and pulled
 * the expected figure $3 negative — true then, because three real dollars left
 * the till, and no longer true now that they do not.
 *
 * A MIXED tender still pays dollars back and still can: $1.00 + ៛20,000 on that
 * bill returns $4 + ៛2,200, leaves ៛17,800, and drives expected cash negative
 * by the four notes that genuinely left the drawer. That is why
 * shift_report.php must keep allowing a negative figure — see the comment
 * there. Clamping it to $0.00 was the phantom-shortage bug.
 *
 * The change portion is measured with tender_change() against the ORDER TOTAL,
 * mirroring receipt_pdf.php — the payment row's own amount can be stale on a
 * pay-later tab that grew after it was opened.
 *
 * Conversion uses the current KHR_RATE, the same rate the checkout screen used
 * to accept the tender, so the count and the sale agree on the same shift.
 *
 * SCOPE, deliberately: only riel that came IN is counted. A dollars-only tender
 * whose change goes back as riel (the $5.00 on a $1.34 bill that hands back
 * $3 + ៛2,700) also moves the two drawers apart, but in the other direction, and
 * it did so identically before riel was ever a tender field — no US coins
 * circulate, so that change has always been handed over in riel while the system
 * called it dollars. Netting that direction too would rewrite the expected-cash
 * figure for historical dollars-only shifts, which is not what this fix is for.
 * Rows with no riel tendered contribute exactly zero.
 */
if (!function_exists('tender_riel_share')) {
    function tender_riel_share(mysqli $conn, array $order_ids): float {
        $ids = array_values(array_unique(array_map('intval', $order_ids)));
        if (!$ids) { return 0.0; }

        $ph    = implode(',', array_fill(0, count($ids), '?'));
        $types = str_repeat('i', count($ids));
        $st = $conn->prepare("
            SELECT op.reference, o.total
            FROM order_payments op
            JOIN orders o ON o.order_id = op.order_id
            WHERE op.payment_method = 'cash'
              AND op.order_id IN ($ph)
        ");
        if (!$st) { return 0.0; }
        $st->bind_param($types, ...$ids);
        $st->execute();
        $res = $st->get_result();

        $khr_net = 0;
        while ($r = $res->fetch_assoc()) {
            $parts = tender_parts($r['reference']);
            if ($parts === null || $parts['khr'] <= 0) { continue; }
            $ch = tender_change(tender_usd_total($r['reference']), (float)$r['total'],
                                tender_is_riel_only($parts));
            // Only riel is handed back as riel; the dollar part of the change
            // comes out of the dollar drawer and is already accounted for by
            // the cash total this figure is subtracted from. On a riel-only
            // tender there IS no dollar part, so the whole change nets off here
            // and the row leaves the dollar drawer alone.
            $khr_net += max(0, (int)$parts['khr'] - (int)$ch['khr']);
        }
        return round($khr_net / KHR_RATE, 2);
    }
}

/**
 * Where a cashier returns to after settling an order at the counter.
 *
 * The destination arrives as a query parameter and ends up in a Location:
 * header, so it is validated against a fixed list rather than interpolated.
 * 'pending' is the fallback because it is where the majority of counter
 * settlements start; a wrong-but-safe tab beats an open redirect.
 *
 * 'dashboard' has no caller today. It is kept because dropping it would mean
 * revisiting all three files the first time a dashboard cash button is added.
 */
if (!function_exists('pay_return_tab')) {
    function pay_return_tab(?string $raw): string {
        $allowed = ['all', 'pending', 'paylater', 'dashboard'];
        return in_array($raw, $allowed, true) ? $raw : 'pending';
    }
}

if (!function_exists('pay_return_url')) {
    function pay_return_url(string $tab): string {
        // Re-validate rather than trust the caller: this is the last stop
        // before a Location: header.
        $tab = pay_return_tab($tab);
        return $tab === 'dashboard' ? 'dashboard.php' : 'find_order.php?tab=' . $tab;
    }
}

/**
 * What a normal <weekday> takes, for judging today against.
 *
 * A cafe's trade is weekly-seasonal, so Saturday is only fair against other
 * Saturdays. Averages the last $want same-weekday business dates that
 * actually traded. Some weekdays are thin, so this degrades: fewer than two
 * such days falls back to yesterday, and no yesterday returns no basis at
 * all rather than comparing today against a day that never happened.
 */
if (!function_exists('weekday_baseline')) {
    function weekday_baseline(mysqli $conn, string $date, int $want = 4): array {
        $want = max(1, $want);
        $none = ['value' => null, 'basis' => 'none', 'label' => '', 'days' => 0, 'dates' => []];

        $stmt = $conn->prepare("
            SELECT DATE(order_date) AS bdate, SUM(total) AS takings
            FROM orders
            WHERE DATE(order_date) < ?
              AND DAYOFWEEK(order_date) = DAYOFWEEK(?)
              AND " . paid_orders_where() . "
            GROUP BY DATE(order_date)
            HAVING takings > 0
            ORDER BY bdate DESC
            LIMIT ?
        ");
        $stmt->bind_param("ssi", $date, $date, $want);
        $stmt->execute();
        $days = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        if (count($days) >= 2) {
            $sum = 0.0;
            foreach ($days as $d) { $sum += (float)$d['takings']; }
            return [
                'value' => $sum / count($days),
                'basis' => 'weekday',
                'label' => 'a normal ' . date('l', strtotime($date)),
                'days'  => count($days),
                'dates' => array_column($days, 'bdate'),
            ];
        }

        $yesterday = date('Y-m-d', strtotime($date . ' -1 day'));
        $stmt = $conn->prepare("SELECT SUM(total) FROM orders WHERE DATE(order_date) = ? AND " . paid_orders_where());
        $stmt->bind_param("s", $yesterday);
        $stmt->execute();
        $y = $stmt->get_result()->fetch_row()[0];

        if ($y === null || (float)$y <= 0) { return $none; }
        return ['value' => (float)$y, 'basis' => 'yesterday', 'label' => 'yesterday', 'days' => 1, 'dates' => [$yesterday]];
    }
}

_migrate($conn, 'order_items_addons_snapshot_v1', function($db) {
    $db->query("ALTER TABLE order_items ADD COLUMN IF NOT EXISTS addons_snapshot TEXT NULL");
});



// Seed a starter set once (only if the library is empty)
_migrate($conn, 'addons_seed_v1', function($db) {
    $n = (int)$db->query("SELECT COUNT(*) AS n FROM addons")->fetch_assoc()['n'];
    if ($n === 0) {
        $db->query("INSERT INTO addons (name, price, is_active, display_order) VALUES
            ('Boba', 0.50, 1, 1),
            ('Jelly', 0.50, 1, 2),
            ('Tapioca', 0.50, 1, 3),
            ('Whipped Cream', 0.75, 1, 4),
            ('Coffee Jelly', 1.00, 1, 5),
            ('Extra Shot', 1.00, 1, 6)");
    }
});

// ── Per-category option visibility (sweetness / ice / milk) ──
_migrate($conn, 'categories_option_flags_v1', function($db) {
    $db->query("ALTER TABLE categories
        ADD COLUMN IF NOT EXISTS offer_sweetness TINYINT(1) NOT NULL DEFAULT 1,
        ADD COLUMN IF NOT EXISTS offer_ice       TINYINT(1) NOT NULL DEFAULT 1,
        ADD COLUMN IF NOT EXISTS offer_milk      TINYINT(1) NOT NULL DEFAULT 1");
    // Preserve the prior hardcoded behavior: Juice offers none; Hot offers no ice.
    $db->query("UPDATE categories SET offer_sweetness=0, offer_ice=0, offer_milk=0 WHERE slug='Juice'");
    $db->query("UPDATE categories SET offer_ice=0 WHERE slug='Hot'");
});

// ── Per-category add-on availability (master gate; products still pick which add-ons) ──
_migrate($conn, 'categories_full_columns_v1', function($db) {
    $db->query("ALTER TABLE categories ADD COLUMN IF NOT EXISTS description VARCHAR(255) NULL DEFAULT ''");
    $db->query("ALTER TABLE categories ADD COLUMN IF NOT EXISTS offer_sweetness TINYINT(1) NOT NULL DEFAULT 1");
    $db->query("ALTER TABLE categories ADD COLUMN IF NOT EXISTS offer_ice TINYINT(1) NOT NULL DEFAULT 1");
    $db->query("ALTER TABLE categories ADD COLUMN IF NOT EXISTS offer_milk TINYINT(1) NOT NULL DEFAULT 1");
    $db->query("ALTER TABLE categories ADD COLUMN IF NOT EXISTS offer_addons TINYINT(1) NOT NULL DEFAULT 1");
    $db->query("ALTER TABLE categories ADD COLUMN IF NOT EXISTS earns_points TINYINT(1) NOT NULL DEFAULT 1");
});

_migrate($conn, 'categories_offer_addons_v1', function($db) {
    $db->query("ALTER TABLE categories ADD COLUMN IF NOT EXISTS offer_addons TINYINT(1) NOT NULL DEFAULT 1");
    $db->query("UPDATE categories SET offer_addons=0 WHERE slug='Juice'");
});
_migrate($conn, 'categories_earns_points_v1', function($db) {
    $db->query("ALTER TABLE categories ADD COLUMN IF NOT EXISTS earns_points TINYINT(1) NOT NULL DEFAULT 1");
});
_migrate($conn, 'order_items_earns_points_v1', function($db) {
    $db->query("ALTER TABLE order_items ADD COLUMN IF NOT EXISTS earns_points TINYINT(1) NOT NULL DEFAULT 1");
});





// ── New tables: categories ──
$conn->query("CREATE TABLE IF NOT EXISTS categories (
    category_id INT AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(50) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL,
    description VARCHAR(255) NULL DEFAULT '',
    icon VARCHAR(50) DEFAULT 'fa-circle',
    display_order INT DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    offer_sweetness TINYINT(1) NOT NULL DEFAULT 1,
    offer_ice TINYINT(1) NOT NULL DEFAULT 1,
    offer_milk TINYINT(1) NOT NULL DEFAULT 1,
    offer_addons TINYINT(1) NOT NULL DEFAULT 1,
    earns_points TINYINT(1) NOT NULL DEFAULT 1
) DEFAULT CHARSET=utf8mb4");

$catCountRes = $conn->query("SELECT COUNT(*) FROM categories");
$catCount = ($catCountRes && ($row = $catCountRes->fetch_row())) ? (int)$row[0] : 0;

if ($catCount === 0) {
    // 1. Seed standard default categories
    $defaultCats = [
        ['slug' => 'Iced',        'name' => 'Iced Beverages', 'icon' => 'fa-snowflake',  'order' => 1],
        ['slug' => 'Hot',         'name' => 'Hot Beverages',  'icon' => 'fa-mug-hot',    'order' => 2],
        ['slug' => 'Frappe',      'name' => 'Frappes',        'icon' => 'fa-blender',    'order' => 3],
        ['slug' => 'Juice',       'name' => 'Juices',         'icon' => 'fa-lemon',      'order' => 4],
        ['slug' => 'Milk Tea',    'name' => 'Milk Tea',       'icon' => 'fa-circle-dot', 'order' => 5],
        ['slug' => 'Soft Drinks', 'name' => 'Soft Drinks',    'icon' => 'fa-bottle-water','order' => 6],
    ];
    $insStmt = $conn->prepare("INSERT IGNORE INTO categories (slug, name, icon, display_order, is_active, offer_sweetness, offer_ice, offer_milk, offer_addons, earns_points) VALUES (?, ?, ?, ?, 1, 1, 1, 1, 1, 1)");
    if ($insStmt) {
        foreach ($defaultCats as $dc) {
            $insStmt->bind_param('sssi', $dc['slug'], $dc['name'], $dc['icon'], $dc['order']);
            $insStmt->execute();
        }
    }

    // 2. Also import any categories currently used in products table
    $prodCatsRes = $conn->query("SELECT DISTINCT category FROM products WHERE category IS NOT NULL AND TRIM(category) != ''");
    if ($prodCatsRes) {
        $nextOrder = 10;
        while ($pRow = $prodCatsRes->fetch_assoc()) {
            $pCatName = trim($pRow['category']);
            if ($pCatName === '') continue;
            $insCat = $conn->prepare("INSERT IGNORE INTO categories (slug, name, icon, display_order, is_active) VALUES (?, ?, 'fa-circle', ?, 1)");
            if ($insCat) {
                $insCat->bind_param('ssi', $pCatName, $pCatName, $nextOrder);
                $insCat->execute();
                $nextOrder++;
            }
        }
    }
}

// Ensure products.category_id is mapped to categories.category_id
$conn->query("UPDATE products p JOIN categories c ON (c.slug = p.category OR c.name = p.category) SET p.category_id = c.category_id WHERE p.category_id IS NULL OR p.category_id = 0");



// ── Order cancellations table ──
_migrate($conn, 'orders_split_cancel_refund_v1', function($db) {
    $db->query("CREATE TABLE IF NOT EXISTS order_cancellations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        order_id INT NOT NULL UNIQUE,
        cancel_reason VARCHAR(255) NOT NULL,
        cancelled_at DATETIME NOT NULL,
        cancelled_by VARCHAR(100) NOT NULL DEFAULT '',
        CONSTRAINT fk_oc_order FOREIGN KEY (order_id) REFERENCES orders(order_id) ON DELETE CASCADE
    ) DEFAULT CHARSET=utf8mb4");
});

// ── Add missing FK constraints across all tables ──
_migrate($conn, 'add_missing_fks_v1', function($db) {
    $db->query("ALTER TABLE orders ADD CONSTRAINT fk_orders_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE SET NULL");
    if ($db->errno) return;
    $db->query("ALTER TABLE attendance ADD CONSTRAINT fk_attendance_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE RESTRICT");
});

// ── Add category_id FK to products (categories table already exists) ──
_migrate($conn, 'products_category_fk_v1', function($db) {
    $db->query("ALTER TABLE products ADD COLUMN IF NOT EXISTS category_id INT NULL");
    if ($db->errno) return;
    $db->query("UPDATE products p JOIN categories c ON c.slug = p.category SET p.category_id = c.category_id WHERE p.category_id IS NULL");
    if ($db->errno) return;
    $db->query("ALTER TABLE products ADD CONSTRAINT fk_products_category FOREIGN KEY (category_id) REFERENCES categories(category_id) ON DELETE SET NULL");
});

// ── SANITIZE FUNCTION ──
if (!function_exists('sanitizeForReceipt')) {
    function sanitizeForReceipt(string $text): string {
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        $text = iconv('UTF-8', 'ASCII//IGNORE', $text);
        $text = preg_replace('/\s{2,}/', ' ', $text ?? '');
        return trim($text);
    }
}

// ── LOYALTY SYSTEM FUNCTIONS (disabled) ──
if (!function_exists('generateLoyaltyId')) {
    function generateLoyaltyId() {
        return 'CARD-' . str_pad(random_int(1, 99999), 5, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('getLoyaltyCard')) {
    function getLoyaltyCard($conn, $loyalty_id) {
        return null;
    }
}

if (!function_exists('getLoyaltyHistory')) {
    function getLoyaltyHistory($conn, $card_id, $limit = 10) {
        return false;
    }
}

if (!function_exists('getAvailableRewards')) {
    function getAvailableRewards($conn) {
        return false;
    }
}

// ── RBAC: can() — check if current session user / role has a permission ──
if (!function_exists('can')) {
    function can(string $slug): bool {
        if (session_status() === PHP_SESSION_NONE) session_start();
        $role = $_SESSION['role'] ?? 'staff';
        if ($role === 'admin' || $role === 'manager') return true;

        $staff_allowed = ['dashboard', 'find_orders', 'view_orders', 'loyalty', 'barista_station', 'my_profile', 'customer_display'];
        return in_array($slug, $staff_allowed, true);
    }
}

// ── AUTO-SYNC: Sync Product selling/cost price edits directly to Stock Drink (stock_items) ──
if (!function_exists('sync_product_to_stock_item')) {
    function sync_product_to_stock_item($conn, $name, $price, $cost_price = null, $image = null, $product_id = null) {
        if (empty($name) || (float)$price <= 0 || !$conn) return false;

        $nameTrimmed = trim((string)$name);
        // Check if product name represents Box/Package (e.g. "IZE (កេស)", "Carabao (Box)", "Sting (យួរ)")
        $isBox = (bool)preg_match('/\s*\((?:Box|box|កេស|យួរ|Pack|pack)\)/ui', $nameTrimmed);
        
        // Base name without any unit/box suffix
        $baseName = trim(preg_replace('/\s*\((?:Box|box|កេស|យួរ|Pack|pack|កំប៉ុង|Can|can|ដប|Bottle|bottle|Unit|unit)\)/ui', '', $nameTrimmed));
        if ($baseName === '') $baseName = $nameTrimmed;

        // Search for direct_drink in stock_items matching base name or full name
        $stmt = $conn->prepare("
            SELECT item_id, item_name, unit, purchase_unit, conversion_rate, 
                   selling_price_per_unit, selling_price_per_box, cost_per_unit, cost_per_purchase_unit, 
                   image, image_box 
            FROM stock_items 
            WHERE item_type = 'direct_drink' AND is_active = 1 
              AND (
                LOWER(REPLACE(REPLACE(TRIM(item_name), ' ', ''), '-', '')) = LOWER(REPLACE(REPLACE(TRIM(?), ' ', ''), '-', ''))
                OR LOWER(REPLACE(REPLACE(TRIM(item_name), ' ', ''), '-', '')) = LOWER(REPLACE(REPLACE(TRIM(?), ' ', ''), '-', ''))
              )
            LIMIT 1
        ");

        if (!$stmt) return false;

        $stmt->bind_param("ss", $baseName, $nameTrimmed);
        $stmt->execute();
        $stockItem = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        // If not found by name, try to check if product has a recipe linked to direct_drink item
        if (!$stockItem && $product_id > 0) {
            $rStmt = $conn->prepare("
                SELECT s.item_id, s.item_name, s.unit, s.purchase_unit, s.conversion_rate, 
                       s.selling_price_per_unit, s.selling_price_per_box, s.cost_per_unit, s.cost_per_purchase_unit, 
                       s.image, s.image_box 
                FROM product_recipes pr 
                JOIN stock_items s ON pr.item_id = s.item_id 
                WHERE pr.product_id = ? AND s.item_type = 'direct_drink' AND s.is_active = 1 
                LIMIT 1
            ");
            if ($rStmt) {
                $rStmt->bind_param("i", $product_id);
                $rStmt->execute();
                $stockItem = $rStmt->get_result()->fetch_assoc();
                $rStmt->close();
            }
        }

        if (!$stockItem) return false;

        $itemId = (int)$stockItem['item_id'];
        $rate   = max(1.0, (float)($stockItem['conversion_rate'] ?? 24));
        $curUnitSell = (float)($stockItem['selling_price_per_unit'] ?? 0);
        $curBoxSell  = (float)($stockItem['selling_price_per_box'] ?? 0);
        $curUnitCost = (float)($stockItem['cost_per_unit'] ?? 0);
        $curBoxCost  = (float)($stockItem['cost_per_purchase_unit'] ?? 0);

        $newUnitSell = $curUnitSell;
        $newBoxSell  = $curBoxSell;
        $newUnitCost = $curUnitCost;
        $newBoxCost  = $curBoxCost;

        if ($isBox) {
            // Updated Box selling price
            $newBoxSell = round((float)$price, 2);
            if ($cost_price !== null && (float)$cost_price > 0) {
                $newBoxCost = round((float)$cost_price, 2);
            }
            // If unit selling price is 0 or needs default from box rate
            if ($newUnitSell <= 0 && $rate > 0) {
                $newUnitSell = round($newBoxSell / $rate, 2);
            }
            if ($newUnitCost <= 0 && $newBoxCost > 0 && $rate > 0) {
                $newUnitCost = round($newBoxCost / $rate, 2);
            }
        } else {
            // Updated Unit selling price
            $newUnitSell = round((float)$price, 2);
            if ($cost_price !== null && (float)$cost_price > 0) {
                $newUnitCost = round((float)$cost_price, 2);
            }
            // If box selling price is 0 or needs default from unit rate
            if ($newBoxSell <= 0 && $rate > 0) {
                $newBoxSell = round($newUnitSell * $rate, 2);
            }
            if ($newBoxCost <= 0 && $newUnitCost > 0 && $rate > 0) {
                $newBoxCost = round($newUnitCost * $rate, 2);
            }
        }

        // Prepare update for stock_items
        $upd = $conn->prepare("
            UPDATE stock_items 
            SET selling_price_per_unit = ?, 
                selling_price_per_box = ?, 
                cost_per_unit = ?, 
                cost_per_purchase_unit = ?, 
                updated_at = NOW() 
            WHERE item_id = ?
        ");

        if ($upd) {
            $upd->bind_param("ddddi", $newUnitSell, $newBoxSell, $newUnitCost, $newBoxCost, $itemId);
            $upd->execute();
            $upd->close();
            return true;
        }

        return false;
    }
}
?>