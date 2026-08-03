<?php
date_default_timezone_set('Asia/Phnom_Penh');

// Database connection
// ⚠️  Run this once in phpMyAdmin/MySQL CLI before changing these credentials:
//   CREATE USER 'cafe_pos'@'localhost' IDENTIFIED BY 'Caf3P0S!2025#Kh';
//   GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER ON db_coffee.* TO 'cafe_pos'@'localhost';
//   FLUSH PRIVILEGES;
// Local XAMPP defaults. In production, override these via a git-ignored
// db_config.local.php (copy db_config.local.example.php) so real credentials
// never live in the repo — same pattern as bakong_config.local.php.
$servername = "localhost";
$username   = "root";
$password   = "";
$dbname     = "db_coffee";

if (is_file(__DIR__ . '/db_config.local.php')) {
    require __DIR__ . '/db_config.local.php';
}

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// ── CRITICAL: Force utf8mb4 so 4-byte emoji are read correctly ──
$conn->set_charset('utf8mb4');

// --- Check if constants are already defined before defining them ---
if (!defined('PAYMENT_API_URL')) {
    define('PAYMENT_API_URL', 'https://api.example.com/payment');
}
if (!defined('PAYMENT_API_TOKEN')) {
    define('PAYMENT_API_TOKEN', 'your_token_here');
}

// ── LOAD SETTINGS FROM DB ──
$_cafe_settings = [];
$_sr = $conn->query("SELECT setting_key, setting_value FROM settings");
if ($_sr) { while ($row = $_sr->fetch_assoc()) $_cafe_settings[$row['setting_key']] = $row['setting_value']; }

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
_migrate($conn, 'employees_user_id', function($db) {
    $db->query("ALTER TABLE employees ADD COLUMN IF NOT EXISTS user_id INT NULL");
});
_migrate($conn, 'employees_shift_v1', function($db) {
    $db->query("ALTER TABLE employees ADD COLUMN IF NOT EXISTS shift ENUM('morning','afternoon','night') NULL DEFAULT NULL");
});
// Display-only / non-POS staff (cleaner, waiter, etc.): is_pos=0 means no login, no role.
_migrate($conn, 'employees_is_pos_v1', function($db) {
    $db->query("ALTER TABLE employees ADD COLUMN IF NOT EXISTS is_pos TINYINT(1) NOT NULL DEFAULT 1");
});
// One employee per login: a user_id must map to at most one employee, else order
// attribution (confirm_order: WHERE user_id=? LIMIT 1) picks arbitrarily. UNIQUE allows
// multiple NULLs, so non-POS/unlinked staff are unaffected. If a DB still has a duplicate
// link the ALTER fails and this stays unapplied (loud) until the data is de-duplicated.
_migrate($conn, 'employees_user_id_unique_v1', function($db) {
    $has = $db->query("SELECT COUNT(*) c FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='employees' AND INDEX_NAME='uq_employees_user_id'")->fetch_assoc()['c'];
    if ((int)$has === 0) {
        $db->query("ALTER TABLE employees ADD UNIQUE INDEX uq_employees_user_id (user_id)");
    }
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
_migrate($conn, 'order_audit_log_v1', function($db) {
    $db->query("
        CREATE TABLE IF NOT EXISTS order_audit_log (
            audit_id     INT AUTO_INCREMENT PRIMARY KEY,
            order_id     INT NOT NULL,
            user_id      INT NULL,
            user_name    VARCHAR(120) NULL,
            action       VARCHAR(40)  NOT NULL,
            detail       TEXT NULL,
            total_before DECIMAL(10,2) NULL,
            total_after  DECIMAL(10,2) NULL,
            created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_oal_order (order_id),
            INDEX idx_oal_created (created_at),
            CONSTRAINT fk_oal_order FOREIGN KEY (order_id)
                REFERENCES orders(order_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
});

/**
 * Record a change to an already-placed order. Deliberately never throws and never
 * blocks the caller: a failed audit write must not roll back a real sale.
 */
if (!function_exists('log_order_audit')) {
    function log_order_audit($conn, $order_id, $action, $detail = '', $total_before = null, $total_after = null) {
        $uid   = $_SESSION['user_id'] ?? null;
        $uname = $_SESSION['username'] ?? ($_SESSION['name'] ?? null);
        try {
            $st = $conn->prepare("INSERT INTO order_audit_log
                (order_id, user_id, user_name, action, detail, total_before, total_after)
                VALUES (?,?,?,?,?,?,?)");
            if (!$st) return false;
            $st->bind_param("iisssdd", $order_id, $uid, $uname, $action, $detail, $total_before, $total_after);
            return $st->execute();
        } catch (Throwable $e) {
            return false;
        }
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

/**
 * The logged-in user's profile photo path (employees.photo) or '' if none set.
 * Cached per-request so the various header/sidebar avatars share one lookup.
 * Used to render the real photo (with an initial fallback) instead of just initials.
 */
if (!function_exists('current_user_photo')) {
    function current_user_photo(mysqli $conn): string {
        static $cached = null;
        if ($cached !== null) return $cached;
        $uid = (int)($_SESSION['user_id'] ?? 0);
        if ($uid <= 0) return $cached = '';
        $st = $conn->prepare("SELECT photo FROM employees WHERE user_id = ? AND photo IS NOT NULL AND photo <> '' LIMIT 1");
        $st->bind_param("i", $uid); $st->execute();
        $row = $st->get_result()->fetch_assoc();
        return $cached = ($row ? (string)$row['photo'] : '');
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
        return "{$p}is_open = 0 AND {$p}status NOT IN ('PendingPayment','Cancelled','Refunded','Void')";
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
 * Cost per unit for every ingredient, keyed by id AND by lowercased name.
 * The name key exists because recipes name their milk by type at order time.
 * cost_per_unit is derived (cost_price / purchase_qty) and is authoritative;
 * dividing is only a fallback for rows that predate it being populated.
 */
if (!function_exists('ingredient_cost_map')) {
    function ingredient_cost_map(mysqli $conn): array {
        $map = [];
        $q = $conn->query("SELECT ingredient_id, ingredient_name, cost_price, purchase_qty, cost_per_unit FROM ingredients");
        while ($r = $q->fetch_assoc()) {
            $cpu  = (float)$r['cost_per_unit'];
            $pq   = (float)$r['purchase_qty'];
            $cost = $cpu > 0 ? $cpu : ($pq > 0 ? (float)$r['cost_price'] / $pq : 0.0);
            $entry = ['name' => $r['ingredient_name'], 'unit_cost' => $cost];
            $map[(int)$r['ingredient_id']] = $entry;
            $map[strtolower(trim($r['ingredient_name']))] = $entry + ['id' => (int)$r['ingredient_id']];
        }
        return $map;
    }
}

/**
 * What the drinks in these orders cost us in ingredients.
 * Ingredients whose name contains "milk" are resolved through the milk the
 * customer actually chose on the line, not the recipe's default.
 *
 * Includes price = 0 lines (buy-X-get-1-free gift items): they are real
 * drinks that were really made and really cost ingredients, even though the
 * customer paid nothing for that line. Excluding them undercounts both cups
 * sold and the cost of a promo day.
 *
 * DIVERGES FROM report.php's private copy of this loop (report.php:~135),
 * which still filters `AND oi.price > 0` and therefore does NOT cost gift
 * lines. That was a deliberate choice, not an oversight: report.php also
 * needs per-category and per-order breakdowns this helper doesn't return,
 * so its copy was kept rather than repointed at this helper (see the
 * 2026-07-26 daily-report-redesign spec's "Shared code" section). Do not
 * "fix" this by unifying them without re-checking both callers' figures.
 */
if (!function_exists('order_cogs')) {
    function order_cogs(mysqli $conn, array $orderIds, array $costMap): array {
        $out = ['total' => 0.0, 'items' => 0, 'gift_items' => 0, 'by_product' => []];
        $ids = array_values(array_filter(array_map('intval', $orderIds)));
        if (!$ids) { return $out; }
        $in = implode(',', $ids);

        $items = [];
        $productIds = [];
        $q = $conn->query("
            SELECT oi.product_id, oi.product_name, oi.milk, oi.quantity, oi.price
            FROM order_items oi
            WHERE oi.order_id IN ($in)
        ");
        while ($it = $q->fetch_assoc()) {
            $items[] = $it;
            if ((int)$it['product_id'] > 0) { $productIds[(int)$it['product_id']] = true; }
        }

        $recipes = [];
        if ($productIds) {
            $pin = implode(',', array_keys($productIds));
            $qr = $conn->query("
                SELECT pi.product_id, pi.ingredient_id, pi.amount_used, i.ingredient_name
                FROM product_ingredients pi
                JOIN ingredients i ON i.ingredient_id = pi.ingredient_id
                WHERE pi.product_id IN ($pin)
            ");
            while ($r = $qr->fetch_assoc()) {
                $recipes[(int)$r['product_id']][] = [
                    'ingredient_id'   => (int)$r['ingredient_id'],
                    'ingredient_name' => $r['ingredient_name'],
                    'amount_used'     => (float)$r['amount_used'],
                ];
            }
        }

        foreach ($items as $it) {
            $pid  = (int)$it['product_id'];
            $qty  = max(1, (int)$it['quantity']);
            $milk = trim((string)$it['milk']);
            $name = (string)$it['product_name'];

            $cost = 0.0;
            foreach ($recipes[$pid] ?? [] as $rc) {
                $amount = $rc['amount_used'] * $qty;
                if ($amount <= 0) { continue; }
                if (strpos(strtolower(trim($rc['ingredient_name'])), 'milk') !== false) {
                    $key = strtolower(trim($milk !== '' ? $milk : 'Fresh Milk'));
                    if (isset($costMap[$key])) { $cost += $amount * (float)$costMap[$key]['unit_cost']; }
                } else {
                    $cost += $amount * (float)($costMap[$rc['ingredient_id']]['unit_cost'] ?? 0);
                }
            }

            // A loyalty redemption is not a sale. They ride in order_items under
            // the product_id = 0 sentinel, and product_id is the ONLY reliable
            // way to spot one:
            //   - the "[GIFT] " name prefix misses 6 rows written before it
            //     existed (Free Shirt/Free Drink/Free Toy "(Loyalty)");
            //   - price = 0 is worse still, because a buy-X-get-1-free promo
            //     drink is also $0 and IS a real cup that was poured. Filtering
            //     on price is what once excluded free promo drinks from the cup
            //     count and left them uncosted, overstating money kept on every
            //     promo day.
            // Flagged here, in the one helper the screen, the PDF and the
            // spreadsheet all share, so they cannot disagree about it again.
            $isGift = ($pid === 0);

            $out['total'] += $cost;
            $out['items'] += $qty;
            if ($isGift) { $out['gift_items'] += $qty; }
            if (!isset($out['by_product'][$name])) {
                $out['by_product'][$name] = ['qty' => 0, 'cost' => 0.0, 'revenue' => 0.0, 'is_gift' => $isGift];
            }
            $out['by_product'][$name]['qty']     += $qty;
            $out['by_product'][$name]['cost']    += $cost;
            $out['by_product'][$name]['revenue'] += (float)($it['price'] ?? 0) * $qty;
        }
        return $out;
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
 * What a purchase order is worth, ordered against actually delivered.
 *
 * purchase_orders.total_cost records the order that was placed and is never
 * rewritten — changing it would falsify a document already issued to a
 * supplier, and would make a short delivery indistinguishable from a small
 * order. The delivered value is derived here instead.
 *
 * Over-delivery is not clamped: if twelve cartons arrive against ten ordered,
 * the received value exceeds the ordered value and 'outstanding' is zero
 * rather than negative, because you cannot be owed a negative quantity.
 */
if (!function_exists('po_line_values')) {
    function po_line_values(mysqli $conn, int $po_id): array {
        $out = ['ordered' => 0.0, 'received' => 0.0, 'outstanding' => 0.0];
        if ($po_id <= 0) { return $out; }

        $stmt = $conn->prepare("
            SELECT COALESCE(SUM(qty_ordered  * unit_cost), 0),
                   COALESCE(SUM(qty_received * unit_cost), 0),
                   COALESCE(SUM(GREATEST(qty_ordered - qty_received, 0) * unit_cost), 0)
            FROM purchase_order_items WHERE po_id = ?
        ");
        $stmt->bind_param('i', $po_id);
        $stmt->execute();
        [$ordered, $received, $outstanding] = $stmt->get_result()->fetch_row();

        return [
            'ordered'     => (float)$ordered,
            'received'    => (float)$received,
            'outstanding' => (float)$outstanding,
        ];
    }
}

/**
 * The status a purchase order's lines say it should be in.
 *
 * Derived from the lines rather than assigned, so the badge can never drift
 * from the quantities beneath it. Only ever returns one of the two delivery
 * states — Draft, Ordered and Cancelled are decisions a person makes, not
 * facts the lines can tell you.
 */
if (!function_exists('po_status_from_lines')) {
    function po_status_from_lines(mysqli $conn, int $po_id): string {
        $stmt = $conn->prepare("
            SELECT COUNT(*) FROM purchase_order_items
            WHERE po_id = ? AND qty_received < qty_ordered
        ");
        $stmt->bind_param('i', $po_id);
        $stmt->execute();
        $short = (int)$stmt->get_result()->fetch_row()[0];
        return $short === 0 ? 'Received' : 'Partially Received';
    }
}

/**
 * Record one line of a delivery: claim it, move the stock, write the ledger.
 *
 * $seen is the qty_received the form was rendered against. The UPDATE only
 * matches while the line still holds that value, so a double-click, a
 * back-button re-POST, or a second clerk on another till claims nothing and
 * gets false back.
 *
 * This replaces the old guard, which claimed the whole PO by flipping
 * status='Ordered' and checking affected_rows. That worked only because
 * receiving happened exactly once; partial receiving means receiving the same
 * PO repeatedly and legitimately, so the guard had to move down to the line.
 *
 * Stock moves by $qty — what arrived this time — never by qty_ordered. Adding
 * the ordered amount regardless of the delivery is what put stock in the
 * system that was never in the building.
 *
 * The caller owns the transaction: a false return must roll it back.
 */
if (!function_exists('po_receive_line')) {
    function po_receive_line(mysqli $conn, int $po_id, int $poi_id, float $seen,
                             float $qty, ?string $by, string $po_num): bool {
        if ($qty <= 0) { return false; }

        // po_id is in the WHERE so a poi_id belonging to another order cannot
        // be claimed through this one.
        $claim = $conn->prepare("UPDATE purchase_order_items
                                 SET qty_received = qty_received + ?
                                 WHERE poi_id = ? AND po_id = ? AND qty_received = ?");
        $claim->bind_param('diid', $qty, $poi_id, $po_id, $seen);
        $claim->execute();
        if ($claim->affected_rows !== 1) { return false; }

        $line = $conn->prepare("SELECT ingredient_id FROM purchase_order_items
                                WHERE poi_id = ? AND po_id = ?");
        $line->bind_param('ii', $poi_id, $po_id);
        $line->execute();
        $row = $line->get_result()->fetch_row();
        if (!$row) { return false; }
        $iid = (int)$row[0];

        $upd = $conn->prepare("UPDATE ingredients SET stock_quantity = stock_quantity + ?
                               WHERE ingredient_id = ?");
        $upd->bind_param('di', $qty, $iid);
        $upd->execute();

        $note = "Received via $po_num";
        $log  = $conn->prepare("INSERT INTO stock_refills (ingredient_id, purchase_qty, notes)
                                VALUES (?,?,?)");
        $log->bind_param('ids', $iid, $qty, $note);
        $log->execute();

        $hist = $conn->prepare("INSERT INTO ingredient_history
                                (ingredient_id, change_type, amount, reference, created_by)
                                VALUES (?, 'po_received', ?, ?, ?)");
        $hist->bind_param('idss', $iid, $qty, $note, $by);
        $hist->execute();

        return true;
    }
}

/**
 * Was a refused receive a replay of a delivery that already landed?
 *
 * po_receive_line() returns a bare false for two different situations, and
 * reporting both as "this purchase order changed while you were looking at it"
 * is wrong for the common one. On a double-click, a back-button re-POST or a
 * bfcache replay nothing changed underneath the user — their own submission
 * already went through, and the guard did its job.
 *
 * That wording has now been misread twice, on 2026-08-01 and again on
 * 2026-08-02, as "the system will not let me enter a smaller quantity". It does
 * not: the Receiving now box is a DELTA, so a short delivery is entered by
 * typing what actually arrived. One sentence made a working feature look broken.
 *
 * The test is qty_received == seen + qty EXACTLY. Only that means this precise
 * submission is the one already stored.
 *
 * It must not be >=. A page rendered before an earlier delivery landed carries a
 * stale seen, and a >= test reads it as a replay: ordered 1000, 500 received,
 * then a genuine 300 submitted from the old page gives stored 800 >= seen 0 +
 * qty 300, and the clerk is told the delivery was already recorded when nothing
 * of it was. Stock sits at 500 while the message says everything is fine — worse
 * than the wording this function exists to replace, because "reload and try
 * again" at least prompts the re-entry that actually banks the goods.
 *
 * Everything else is a conflict and keeps the original wording: a stale page, a
 * second clerk receiving in between, or a line that does not resolve at all. A
 * replay that is ALSO overtaken by another clerk fails equality and reports a
 * conflict, which is the safe direction — it asks for a reload rather than
 * claiming goods arrived.
 *
 * Read-only, and only ever called after a claim has already been refused, so
 * the successful path pays nothing for it. It stays separate from
 * po_receive_line() so that function keeps its bool return: the distinction is
 * a message concern, and widening the return type would force every caller —
 * including the test — to care about a difference only the UI uses.
 */
if (!function_exists('po_receive_was_replay')) {
    function po_receive_was_replay(mysqli $conn, int $po_id, int $poi_id,
                                   float $seen, float $qty): bool {
        $q = $conn->prepare("SELECT qty_received FROM purchase_order_items
                             WHERE poi_id = ? AND po_id = ?");
        $q->bind_param('ii', $poi_id, $po_id);
        $q->execute();
        $row = $q->get_result()->fetch_row();
        if (!$row) { return false; }

        // Tolerance around equality, not a bare ==: qty_received is
        // DECIMAL(10,3) and both operands arrive as floats, so an exact-total
        // replay can miss a strict comparison in the third decimal place.
        return abs((float)$row[0] - ($seen + $qty)) < 0.0005;
    }
}

/**
 * Write a purchase order's derived status back, without mistaking a no-op for a
 * lost race.
 *
 * The status read before the transaction opened is stale by the time a delivery
 * commits — the PO could have been cancelled in another tab, and flipping it
 * back to Received with stock already added would hide that. So the acceptable
 * prior states are re-asserted in the WHERE clause rather than trusted.
 *
 * What that guard must NOT do is judge the outcome by affected_rows. MySQL
 * reports 0 both when no row matched AND when the row matched but every column
 * was already the value being written. A second partial delivery leaves the
 * order Partially Received, which is what it already was, so the UPDATE is a
 * no-op and affected_rows is 0 — and the handler was rolling the whole delivery
 * back and reporting "this purchase order changed while you were looking at it".
 *
 * The visible consequence was that receiving the FULL outstanding quantity
 * worked, because it flips the status to Received and the row genuinely changes,
 * while receiving LESS was claimed and then discarded. That is why entering a
 * smaller quantity looked forbidden when nothing ever forbade it.
 *
 * Re-reading the row answers the real question — is the order in the state we
 * just wrote? — for both a no-op and a genuine write. The same trap cost a
 * working loyalty card link (b25732c). Note the deliberate contrast with
 * po_receive_line(), which uses affected_rows CORRECTLY as a claim: there the
 * value always changes, so 0 can only mean the claim was lost.
 *
 * Connection-level CLIENT_FOUND_ROWS would also mask this, and must not be used:
 * it would silently break po_receive_line()'s claim, which depends on
 * affected_rows meaning matched AND changed.
 */
if (!function_exists('po_commit_status')) {
    function po_commit_status(mysqli $conn, int $po_id, string $new): bool {
        $st = $conn->prepare("UPDATE purchase_orders
                                 SET status = ?,
                                     received_at = CASE WHEN ? = 'Received' THEN NOW() ELSE received_at END
                               WHERE po_id = ? AND status IN ('Ordered','Partially Received')");
        $st->bind_param('ssi', $new, $new, $po_id);
        $st->execute();

        $chk = $conn->prepare("SELECT status FROM purchase_orders WHERE po_id = ?");
        $chk->bind_param('i', $po_id);
        $chk->execute();
        $row = $chk->get_result()->fetch_row();
        if (!$row) { return false; }

        return $row[0] === $new;
    }
}

/**
 * Who may write off the undelivered part of a purchase order.
 *
 * Closing short abandons goods that were ordered and, on most terms, will still
 * be invoiced — a commercial decision rather than a counting one. It is gated on
 * the role and not on the purchase_orders permission, which the clerk who
 * receives deliveries already holds. Mirrors stock_count.php: the clerk counts
 * what is there, a manager commits the consequence.
 */
if (!function_exists('po_may_close_short')) {
    function po_may_close_short(?string $role): bool {
        return in_array($role, ['admin', 'manager'], true);
    }
}

/**
 * Why the undelivered part of a purchase order was written off.
 *
 * One array, read by both the dropdown that offers these and the handler that
 * validates the submission, so the two cannot drift apart. The keys are stored in
 * purchase_orders.closed_short_reason and must stay stable — labels are free to
 * change, codes are not.
 *
 * Without this, a $7.00 gap between what a PO ordered and what arrived is
 * indistinguishable months later from a damaged pallet or a theft. The manager who
 * clicked the button knew; the system used to ask them nothing.
 */
if (!function_exists('po_short_reasons')) {
    function po_short_reasons(): array {
        return [
            'supplier_oos'    => 'Supplier out of stock',
            'damaged'         => 'Damaged in transit',
            'never_arrived'   => 'Delivery never arrived',
            'supplier_cancel' => 'Cancelled by supplier',
            'other'           => 'Other',
        ];
    }
}

/**
 * The human label for a stored reason code.
 *
 * Falls back to the raw code rather than blanking, so a row written by an older or
 * newer version of the list stays readable instead of looking like no reason was
 * given at all — which is a different and more serious claim.
 */
if (!function_exists('po_short_reason_label')) {
    function po_short_reason_label(?string $code): string {
        $code = trim((string)$code);
        if ($code === '') return 'No reason recorded';
        return po_short_reasons()[$code] ?? $code;
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

/**
 * Reconcile one order's loyalty effect to a given earning quantity.
/**
 * Resolve a card ID through any merge chain to its active target card.
 * Bounded rather than while(true): a cycle in merged_into would hang the
 * till, and ten hops is far beyond any real merge history.
 */
if (!function_exists('loyalty_resolve_card_id')) {
    function loyalty_resolve_card_id(mysqli $conn, int $card_id): int {
        if ($card_id <= 0) { return 0; }
        $seen = 0;
        while ($seen++ < 10) {
            $m = $conn->prepare("SELECT merged_into FROM loyalty_cards WHERE card_id = ?");
            $m->bind_param('i', $card_id);
            $m->execute();
            $row = $m->get_result()->fetch_row();
            if (!$row) { return 0; }                       // card gone entirely
            $next = (int)($row[0] ?? 0);
            if ($next <= 0 || $next === $card_id) { break; }
            $card_id = $next;
        }
        return $card_id;
    }
}

/**
 * Reconcile one order's loyalty effect to a given earning quantity.
 *
 * ONE writer for every loyalty movement, because the four sites that used to do
 * this each did it slightly differently. Takes the order's TOTAL earning
 * quantity, never a delta, and works out the difference itself against what the
 * order already recorded. That makes three cases one call:
 *
 *   first award      qty_total = the order's earning drinks
 *   add-to-order     qty_total = the NEW combined total
 *   full reversal    qty_total = 0
 *
 * The reversal is exact rather than approximate. progress_before is recovered
 * from what the order stored:
 *     progress_before = progress_now + (points_earned x Y) - (qty_recorded x X)
 * which is exact because points_earned = intdiv(progress_before + qty x X, Y)
 * and progress_now = (progress_before + qty x X) % Y. So award-then-reverse
 * returns the card to precisely where it started, which is what makes a refund
 * safe for a customer who was part-way to their next point.
 *
 * Card merges: the source card is deactivated, NOT deleted, and
 * orders.loyalty_card_id keeps pointing at it so the audit trail survives. Its
 * points have already moved to the target, so writing here would credit a card
 * nobody can spend from. merged_into is followed to the end of the chain first.
 *
 * Returns the net points change, positive or negative.
 */
if (!function_exists('loyalty_sync')) {
    function loyalty_sync(mysqli $conn, int $card_id, int $order_id, int $qty_total, string $note): int {
        if ($card_id <= 0 || $order_id <= 0) { return 0; }
        $x = max(1, (int)LOYALTY_POINTS_PER);
        $y = max(1, (int)LOYALTY_POINTS_DRINKS);
        $qty_total = max(0, $qty_total);

        // Follow a merge chain to the card that actually holds the balance.
        $card_id = loyalty_resolve_card_id($conn, $card_id);
        if ($card_id <= 0) { return 0; }

        $c = $conn->prepare("SELECT points_progress FROM loyalty_cards WHERE card_id = ?");
        $c->bind_param('i', $card_id);
        $c->execute();
        $crow = $c->get_result()->fetch_row();
        if (!$crow) { return 0; }
        $progress_now = (int)$crow[0];

        $o = $conn->prepare("SELECT points_earned, points_qty FROM orders WHERE order_id = ?");
        $o->bind_param('i', $order_id);
        $o->execute();
        $orow = $o->get_result()->fetch_assoc();
        if (!$orow) { return 0; }
        $points_old = (int)($orow['points_earned'] ?? 0);
        $qty_old    = (int)($orow['points_qty']    ?? 0);

        // Undo this order's contribution, then apply the new one.
        $progress_before = $progress_now + ($points_old * $y) - ($qty_old * $x);
        $numerator       = $progress_before + ($qty_total * $x);
        $points_new      = intdiv(max(0, $numerator), $y);
        $progress_new    = max(0, $numerator) % $y;

        // Symptom guard, not a fix. If this ever fires the arithmetic above is
        // wrong, and the log is the only thing that will say so before a
        // customer notices their points are off.
        if ($numerator < 0 || $progress_new < 0 || $progress_new >= $y) {
            error_log("loyalty_sync: progress out of range for card $card_id order $order_id "
                    . "(numerator $numerator, progress $progress_new, Y $y)");
            $progress_new = max(0, min($y - 1, $progress_new));
        }

        $delta_points = $points_new - $points_old;
        $delta_drinks = $qty_total - $qty_old;
        // An order counts once toward total_orders while it has earning drinks.
        $delta_orders = ($qty_total > 0 ? 1 : 0) - ($qty_old > 0 ? 1 : 0);

        $u = $conn->prepare("UPDATE loyalty_cards
                                SET points          = GREATEST(0, points + ?),
                                    points_progress = ?,
                                    total_orders    = GREATEST(0, total_orders + ?),
                                    total_drinks    = GREATEST(0, total_drinks + ?),
                                    last_used       = NOW()
                              WHERE card_id = ?");
        $u->bind_param('iiiii', $delta_points, $progress_new, $delta_orders, $delta_drinks, $card_id);
        $u->execute();

        // History records movement only. A sync that changes progress but awards
        // nothing is real and correct, and a zero-point row every time would bury
        // the entries a human actually reads.
        if ($delta_points !== 0) {
            $type = $delta_points > 0 ? 'earned' : 'adjusted_deduct';
            // 'earned' is hardcoded in SQL rather than bound so ENUM validation
            // happens at execute(); fall back for schemas without that member.
            $h = $conn->prepare("INSERT INTO loyalty_history (card_id, order_id, points_change, type, description)
                                 VALUES (?, ?, ?, '$type', ?)");
            if (!$h) {
                $alt = $delta_points > 0 ? 'adjusted_add' : 'adjusted_deduct';
                $h = $conn->prepare("INSERT INTO loyalty_history (card_id, order_id, points_change, type, description)
                                     VALUES (?, ?, ?, '$alt', ?)");
            }
            if ($h) { $h->bind_param('iiis', $card_id, $order_id, $delta_points, $note); $h->execute(); }
        }

        $so = $conn->prepare("UPDATE orders SET points_earned = ?, points_qty = ? WHERE order_id = ?");
        $so->bind_param('iii', $points_new, $qty_total, $order_id);
        $so->execute();

        return $delta_points;
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
                               ?string $reference = null): array {
        $shortfalls = [];
        $stmt = $conn->prepare("
            SELECT pi.ingredient_id, pi.amount_used, i.ingredient_name
            FROM product_ingredients pi
            JOIN ingredients i ON i.ingredient_id = pi.ingredient_id
            WHERE pi.product_id = ?
        ");
        $stmt->bind_param("i", $product_id);
        $stmt->execute();
        $rows = $stmt->get_result();
    
        $created_by = $_SESSION['username'] ?? null;
    
        while ($row = $rows->fetch_assoc()) {
            $ing_id    = (int)$row['ingredient_id'];
            $amount    = (float)$row['amount_used'] * $qty * $size_factor;
            $ing_name  = strtolower(trim($row['ingredient_name']));
            $disp_name = trim($row['ingredient_name']);
    
            // Substitute milk ingredient if customer chose a different milk
            if (strpos($ing_name, 'milk') !== false && !empty($milk_choice)) {
                $stmt_milk = $conn->prepare("SELECT ingredient_id, ingredient_name FROM ingredients WHERE LOWER(ingredient_name) = LOWER(?) LIMIT 1");
                $stmt_milk->bind_param("s", $milk_choice);
                $stmt_milk->execute();
                $milk_row = $stmt_milk->get_result()->fetch_assoc();
                if ($milk_row) {
                    $ing_id    = (int)$milk_row['ingredient_id'];
                    $disp_name = trim($milk_row['ingredient_name']);
                }
            }
    
            // Read current stock so we deduct (and log) only what's really on hand.
            $cs = $conn->prepare("SELECT stock_quantity FROM ingredients WHERE ingredient_id = ?");
            $cs->bind_param("i", $ing_id);
            $cs->execute();
            $have = (float)($cs->get_result()->fetch_assoc()['stock_quantity'] ?? 0);
    
            $deducted = $amount;
            if ($have < $amount) {
                // Oversell: take what's left and flag it. (Order still completes.)
                $deducted     = max(0, $have);
                $shortfalls[] = ['name' => $disp_name, 'need' => $amount, 'had' => max(0, $have)];
            }
    
            // GREATEST(0, …) keeps stock from going negative even under concurrent edits.
            $stmt_upd = $conn->prepare("UPDATE ingredients SET stock_quantity = GREATEST(0, stock_quantity - ?) WHERE ingredient_id = ?");
            $stmt_upd->bind_param("di", $amount, $ing_id);
            $stmt_upd->execute();
    
            // Log the amount actually removed, not the phantom full amount.
            if ($deducted > 0) {
                $oid = $order_id > 0 ? $order_id : null;
                $ref = $reference !== null ? $reference : ($oid ? "Order #$order_id" : null);
                $sh  = $conn->prepare("INSERT INTO ingredient_history (ingredient_id, change_type, amount, order_id, reference, created_by) VALUES (?, 'order_deduct', ?, ?, ?, ?)");
                $sh->bind_param("idiss", $ing_id, $deducted, $oid, $ref, $created_by);
                $sh->execute();
            }
        }
        return $shortfalls;
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
        if ($all_riel) {
            return [
                'usd'   => 0,
                'khr'   => (int)(round(($change * KHR_RATE) / 100) * 100),
                'short' => false,
            ];
        }
        $dollars = (int)floor($change);
        $riel    = (int)(round((($change - $dollars) * KHR_RATE) / 100) * 100);
        if ($riel >= KHR_RATE) { $dollars += 1; $riel = 0; }
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
        if ($khr > 0) {
            return $usd > 0
                ? '$' . $usd . ' + KHR ' . number_format($khr)
                : 'KHR ' . number_format($khr);
        }
        return '$' . number_format(max(0, $usd), 2);
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
            SELECT business_date, SUM(total) AS takings
            FROM orders
            WHERE business_date < ?
              AND DAYOFWEEK(business_date) = DAYOFWEEK(?)
              AND " . paid_orders_where() . "
            GROUP BY business_date
            HAVING takings > 0
            ORDER BY business_date DESC
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
                'dates' => array_column($days, 'business_date'),
            ];
        }

        $yesterday = date('Y-m-d', strtotime($date . ' -1 day'));
        $stmt = $conn->prepare("SELECT SUM(total) FROM orders WHERE business_date = ? AND " . paid_orders_where());
        $stmt->bind_param("s", $yesterday);
        $stmt->execute();
        $y = $stmt->get_result()->fetch_row()[0];

        if ($y === null || (float)$y <= 0) { return $none; }
        return ['value' => (float)$y, 'basis' => 'yesterday', 'label' => 'yesterday', 'days' => 1, 'dates' => [$yesterday]];
    }
}

// ── Add-ons (toppings) library + per-product mapping ──
$conn->query("CREATE TABLE IF NOT EXISTS addons (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    display_order INT NOT NULL DEFAULT 0
) DEFAULT CHARSET=utf8mb4");

$conn->query("CREATE TABLE IF NOT EXISTS product_addons (
    product_id INT NOT NULL,
    addon_id INT NOT NULL,
    PRIMARY KEY (product_id, addon_id),
    INDEX idx_pa_addon (addon_id)
) DEFAULT CHARSET=utf8mb4");

_migrate($conn, 'order_items_addons_snapshot_v1', function($db) {
    $db->query("ALTER TABLE order_items ADD COLUMN IF NOT EXISTS addons_snapshot TEXT NULL");
});

// product_addons shipped without referential integrity (product_sizes has it, this didn't):
// deleting a product/addon orphaned join rows. Add the same ON DELETE CASCADE FKs.
_migrate($conn, 'product_addons_fks_v1', function($db) {
    $db->query("DELETE pa FROM product_addons pa LEFT JOIN products p ON p.product_id = pa.product_id WHERE p.product_id IS NULL");
    $db->query("DELETE pa FROM product_addons pa LEFT JOIN addons a   ON a.id = pa.addon_id           WHERE a.id IS NULL");
    $db->query("ALTER TABLE product_addons ENGINE=InnoDB");
    // Guard: skip if FKs already present (e.g. added out-of-band) so the migration doesn't error and stays applied
    $fk = (int)$db->query("SELECT COUNT(*) c FROM information_schema.TABLE_CONSTRAINTS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'product_addons' AND CONSTRAINT_TYPE = 'FOREIGN KEY'")->fetch_assoc()['c'];
    if ($fk === 0) {
        $db->query("ALTER TABLE product_addons
            ADD CONSTRAINT fk_pa_product FOREIGN KEY (product_id) REFERENCES products(product_id) ON DELETE CASCADE,
            ADD CONSTRAINT fk_pa_addon   FOREIGN KEY (addon_id)   REFERENCES addons(id)           ON DELETE CASCADE");
    }
});

// product_ingredients (the recipe join) shipped without a composite UNIQUE and with
// RESTRICT FKs (unlike product_sizes/product_addons which CASCADE): the same ingredient
// could be added twice (double stock), and deleting a product that had a recipe errored
// with FK 1451. Add UNIQUE(product_id,ingredient_id) + swap both FKs to ON DELETE CASCADE.
_migrate($conn, 'product_ingredients_unique_cascade_v1', function($db) {
    // 1) Collapse any existing duplicate (product_id, ingredient_id) rows into one, summing amounts.
    $db->query("CREATE TEMPORARY TABLE _pi_agg AS
        SELECT product_id, ingredient_id, SUM(amount_used) AS amt
        FROM product_ingredients GROUP BY product_id, ingredient_id");
    $db->query("DELETE FROM product_ingredients");
    $db->query("INSERT INTO product_ingredients (product_id, ingredient_id, amount_used)
        SELECT product_id, ingredient_id, amt FROM _pi_agg");
    $db->query("DROP TEMPORARY TABLE _pi_agg");

    // 2) Skip the structural change if the UNIQUE already exists (idempotent / added out-of-band).
    $hasUq = (int)$db->query("SELECT COUNT(*) c FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='product_ingredients'
          AND INDEX_NAME='uq_product_ingredient'")->fetch_assoc()['c'];
    if ($hasUq === 0) {
        // Drop the existing (auto-named) FKs so they can be re-added as CASCADE.
        $fks = $db->query("SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='product_ingredients'
              AND CONSTRAINT_TYPE='FOREIGN KEY'");
        while ($fk = $fks->fetch_assoc()) {
            $db->query("ALTER TABLE product_ingredients DROP FOREIGN KEY `" . $fk['CONSTRAINT_NAME'] . "`");
        }
        $db->query("ALTER TABLE product_ingredients
            ADD UNIQUE KEY uq_product_ingredient (product_id, ingredient_id)");
        $db->query("ALTER TABLE product_ingredients
            ADD CONSTRAINT fk_pi_product    FOREIGN KEY (product_id)    REFERENCES products(product_id)       ON DELETE CASCADE,
            ADD CONSTRAINT fk_pi_ingredient FOREIGN KEY (ingredient_id) REFERENCES ingredients(ingredient_id) ON DELETE CASCADE");
    }
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

// ── Milk options library (admin-managed via manage_milk.php) ──
$conn->query("CREATE TABLE IF NOT EXISTS milk_options (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL,
    display_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    INDEX idx_active_order (is_active, display_order)
) DEFAULT CHARSET=utf8mb4");

// Seed the current hardcoded milk set once (only if the table is empty).
// Fresh Milk is the default — matches the prior hardcoded default in menu.php.
_migrate($conn, 'milk_options_seed_v1', function($db) {
    $n = (int)$db->query("SELECT COUNT(*) AS n FROM milk_options")->fetch_assoc()['n'];
    if ($n === 0) {
        $db->query("INSERT INTO milk_options (name, display_order, is_active, is_default) VALUES
            ('Fresh Milk', 1, 1, 1),
            ('Almond Milk', 2, 1, 0),
            ('Soy Milk', 3, 1, 0),
            ('Oat Milk', 4, 1, 0)");
    }
});

// Announcements gain a "Show From" (schedule) date: future-dated ones stay hidden until then.
// The table itself is created in announcements.php; only ALTER when it already exists.
_migrate($conn, 'announcements_starts_at_v1', function($db) {
    $has = $db->query("SHOW TABLES LIKE 'announcements'");
    if ($has && $has->num_rows) {
        $db->query("ALTER TABLE announcements ADD COLUMN IF NOT EXISTS starts_at DATE NULL AFTER expires_at");
    }
});

$conn->query("CREATE TABLE IF NOT EXISTS login_attempts (id INT AUTO_INCREMENT PRIMARY KEY, ip VARCHAR(45) NOT NULL, username VARCHAR(255) NOT NULL DEFAULT '', attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX idx_ip_time (ip, attempted_at)) DEFAULT CHARSET=utf8mb4");
_migrate($conn, 'login_attempts_username_v1', function($db) {
    $db->query("ALTER TABLE login_attempts ADD COLUMN IF NOT EXISTS username VARCHAR(255) NOT NULL DEFAULT '' AFTER ip");
    $db->query("ALTER TABLE login_attempts ADD INDEX IF NOT EXISTS idx_user_time (username, attempted_at)");
});

// Canonical table is cash_counts (renamed from the legacy cash_reconciliations).
// Create it under the real name so we never recreate the old zombie every load.
$conn->query("CREATE TABLE IF NOT EXISTS cash_counts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    username VARCHAR(100) NOT NULL,
    shift_date DATE NOT NULL,
    login_time DATETIME NOT NULL,
    expected_cash DECIMAL(10,2) NOT NULL DEFAULT 0,
    actual_cash DECIMAL(10,2) NOT NULL DEFAULT 0,
    difference DECIMAL(10,2) GENERATED ALWAYS AS (actual_cash - expected_cash) STORED,
    recorded_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_date (user_id, shift_date)
) DEFAULT CHARSET=utf8mb4");

$conn->query("CREATE TABLE IF NOT EXISTS announcement_reads (
    user_id INT NOT NULL,
    announcement_id INT NOT NULL,
    read_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, announcement_id)
) DEFAULT CHARSET=utf8mb4");

$conn->query("CREATE TABLE IF NOT EXISTS ingredient_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ingredient_id INT NOT NULL,
    change_type ENUM('order_deduct','order_restore','quick_restock','po_received','manual_adjust','count_adjust') NOT NULL,
    amount DECIMAL(10,4) NOT NULL,
    order_id INT NULL,
    reference VARCHAR(255) NULL,
    created_by VARCHAR(100) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ing (ingredient_id),
    INDEX idx_created (created_at)
) DEFAULT CHARSET=utf8mb4");
_migrate($conn, 'ingredient_history_enum_v1', function($db) {
    $db->query("ALTER TABLE ingredient_history MODIFY COLUMN change_type ENUM('order_deduct','order_restore','quick_restock','po_received','manual_adjust') NOT NULL");
});
_migrate($conn, 'ingredient_history_count_adjust_v1', function($db) {
    $db->query("ALTER TABLE ingredient_history MODIFY COLUMN change_type ENUM('order_deduct','order_restore','quick_restock','po_received','manual_adjust','count_adjust') NOT NULL");
});
_migrate($conn, 'stock_counts_reconciled_v1', function($db) {
    $db->query("ALTER TABLE stock_counts
        ADD COLUMN IF NOT EXISTS reconciled_at DATETIME NULL,
        ADD COLUMN IF NOT EXISTS reconciled_by VARCHAR(100) NULL");
});
_migrate($conn, 'ingredients_cost_precision_v1', function($db) {
    // Ingredients are costed per ml / per g, so real unit costs are sub-cent:
    // $3.00 for a 1000ml bottle is 0.003/ml. DECIMAL(10,2) rounded every one of
    // those to 0.01 — 29 of 50 rows sat at exactly that floor — which silently
    // inflated cheap ingredients and made recipe COGS meaningless.
    // Widening only; no existing value loses precision.
    $db->query("ALTER TABLE ingredients MODIFY COLUMN cost_per_unit DECIMAL(10,4) NOT NULL DEFAULT 0.0000");
});
_migrate($conn, 'attendance_manager_adjust_v1', function($db) {
    // A manager can clock staff in/out on their behalf. That writes payroll data
    // for someone else, so record who did it — an unattributed override is exactly
    // the "can the numbers be falsified?" hole.
    $db->query("ALTER TABLE attendance
        ADD COLUMN IF NOT EXISTS adjusted_by VARCHAR(100) NULL,
        ADD COLUMN IF NOT EXISTS adjusted_at DATETIME NULL");
});
_migrate($conn, 'orders_report_indexes_v1', function($db) {
    /* orders carried indexes only on its foreign keys, so every dashboard and report
       query — all of which filter on business_date and status — was a full table scan.
       Fine at a few hundred rows, linear decay after that.
       (business_date, status) is composite because they are almost always filtered
       together; its leftmost prefix still serves business_date alone, so no separate
       single-column index is needed. */
    $db->query("CREATE INDEX idx_orders_bdate_status ON orders (business_date, status)");
    $db->query("CREATE INDEX idx_orders_status ON orders (status)");
    // Top Sellers and the report join order_items back to products on this column.
    $db->query("CREATE INDEX idx_order_items_product ON order_items (product_id)");
});
_migrate($conn, 'loyalty_card_holder_v1', function($db) {
    // A card carried no owner at all, so staff could only find one by the number the
    // customer was already holding — which is why the same person could collect several
    // cards and split their points. Both fields stay optional: an anonymous card still
    // works exactly as before, and merged/legacy cards keep NULL.
    $db->query("ALTER TABLE loyalty_cards
        ADD COLUMN IF NOT EXISTS holder_name  VARCHAR(100) NULL,
        ADD COLUMN IF NOT EXISTS holder_phone VARCHAR(30)  NULL,
        ADD COLUMN IF NOT EXISTS merged_into  INT NULL,
        ADD COLUMN IF NOT EXISTS merged_at    DATETIME NULL");
    // Deliberately NOT unique: a household can legitimately share one number, so the
    // duplicate check is a warning at the till, not a database-level block.
    $db->query("CREATE INDEX idx_loyalty_holder_phone ON loyalty_cards (holder_phone)");
});
_migrate($conn, 'cash_counts_resolution_v1', function($db) {
    // Manager follow-up on an Over/Short drawer. Deliberately does NOT touch
    // expected_cash/actual_cash/difference — the variance is a financial fact and
    // stays on the record; resolving only attaches the investigation outcome.
    $db->query("ALTER TABLE cash_counts
        ADD COLUMN IF NOT EXISTS resolved_at DATETIME NULL,
        ADD COLUMN IF NOT EXISTS resolved_by VARCHAR(100) NULL,
        ADD COLUMN IF NOT EXISTS resolution_note TEXT NULL");
});
_migrate($conn, 'users_must_set_security_v1', function($db) {
    $db->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS must_set_security TINYINT(1) NOT NULL DEFAULT 0");
});
_migrate($conn, 'order_remakes_v1', function($db) {
    $db->query("CREATE TABLE IF NOT EXISTS order_remakes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        order_id INT NOT NULL,
        reason TEXT NOT NULL,
        remade_by VARCHAR(100) NOT NULL,
        remade_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_order (order_id)
    ) DEFAULT CHARSET=utf8mb4");
});

// ── New tables: categories, customers ──
$conn->query("CREATE TABLE IF NOT EXISTS categories (
    category_id INT AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(50) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL,
    icon VARCHAR(50) DEFAULT 'fa-circle',
    display_order INT DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1
) DEFAULT CHARSET=utf8mb4");

if ((int)$conn->query("SELECT COUNT(*) FROM categories")->fetch_row()[0] === 0) {
    $conn->query("INSERT INTO categories (slug, name, icon, display_order) VALUES
        ('Iced','Iced Beverages','fa-snowflake',1),
        ('Hot','Hot Beverages','fa-mug-hot',2),
        ('Frappe','Frappes','fa-blender',3),
        ('Juice','Juices','fa-lemon',4),
        ('Milk Tea','Milk Tea','fa-circle-dot',5)");
}

$conn->query("CREATE TABLE IF NOT EXISTS customers (
    customer_id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    phone VARCHAR(20) DEFAULT NULL,
    email VARCHAR(100) DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) DEFAULT CHARSET=utf8mb4");

// ── RBAC: create tables ──
$conn->query("CREATE TABLE IF NOT EXISTS permissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    slug VARCHAR(100) NOT NULL UNIQUE,
    module VARCHAR(50) NOT NULL,
    sort_order INT DEFAULT 0
) DEFAULT CHARSET=utf8mb4");
$conn->query("CREATE TABLE IF NOT EXISTS role_permissions (
    role VARCHAR(50) NOT NULL,
    permission_id INT NOT NULL,
    PRIMARY KEY (role, permission_id)
) DEFAULT CHARSET=utf8mb4");

$conn->query("CREATE TABLE IF NOT EXISTS roles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(50) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL,
    icon VARCHAR(50) DEFAULT 'fa-user',
    color VARCHAR(20) DEFAULT '#888888',
    description VARCHAR(200) DEFAULT '',
    is_system TINYINT(1) DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) DEFAULT CHARSET=utf8mb4");

if ((int)$conn->query("SELECT COUNT(*) FROM roles")->fetch_row()[0] === 0) {
    $conn->query("INSERT INTO roles (slug, name, icon, color, description, is_system) VALUES
        ('admin',            'Admin',            'fa-user-shield',  '#d1904b', 'Full system access — cannot be restricted', 1),
        ('manager',          'Manager',          'fa-user-tie',     '#3498db', 'Operational access — configure below',     1),
        ('staff',            'Cashier',          'fa-user',         '#55e087', 'Limited access — configure below',         1),
        ('barista',          'Barista',          'fa-mug-hot',      '#d1904b', 'Kitchen display + recipe reference',        0),
        ('supervisor',       'Supervisor',       'fa-user-check',   '#f39c12', 'Shift runner — operational oversight',      0),
        ('inventory_clerk',  'Inventory',        'fa-box-open',     '#1abc9c', 'Stock and procurement management',          0)");
}
$conn->query("UPDATE roles SET name='Inventory' WHERE slug='inventory_clerk' AND name='Inventory Clerk'");

// ── RBAC: seed permissions + defaults (runs once) ──
if ((int)$conn->query("SELECT COUNT(*) FROM permissions")->fetch_row()[0] === 0) {
    $perms = [
        ['Dashboard',          'dashboard',       'Overview',    1],
        ['Find Unpaid Orders', 'find_orders',     'Orders',      2],
        ['View Orders',        'view_orders',     'Orders',      3],
        ['Loyalty Card',       'loyalty',         'Loyalty',     4],
        ['Products',           'products',        'Inventory',   5],
        ['Ingredients',        'ingredients',     'Inventory',   6],
        ['Drink Recipe',       'recipes',         'Inventory',   7],
        ['Manage Recipes',     'manage_recipes',  'Inventory',   17],
        ['Suppliers',          'suppliers',       'Procurement', 8],
        ['Purchase Orders',    'purchase_orders', 'Procurement', 9],
        ['Daily Report',       'report',          'Analytics',   10],
        ['Employees',          'employees',       'Staff',       11],
        ['Announcements',      'announcements',   'Staff',       12],
        ['Attendance',         'attendance',      'Staff',       13],
        ['Promotions',         'promotions',      'Staff',       14],
        ['Manage Roles',       'manage_roles',    'Admin',       15],
        ['Reset Password',     'reset_password',  'Staff',       18],
    ];
    $ps = $conn->prepare("INSERT IGNORE INTO permissions (name,slug,module,sort_order) VALUES (?,?,?,?)");
    foreach ($perms as $p) { $ps->bind_param("sssi",$p[0],$p[1],$p[2],$p[3]); $ps->execute(); }

    // Default manager permissions
    $conn->query("INSERT IGNORE INTO role_permissions (role,permission_id) SELECT 'manager',id FROM permissions WHERE slug IN ('dashboard','find_orders','view_orders','loyalty','products','ingredients','recipes','manage_recipes','suppliers','purchase_orders','report','announcements','attendance','promotions','reset_password')");

    // Default staff permissions
    $conn->query("INSERT IGNORE INTO role_permissions (role,permission_id) SELECT 'staff',id FROM permissions WHERE slug IN ('dashboard','find_orders','loyalty')");
}

// ── RBAC: register newly-added permissions for existing installs (run once via migrations) ──
_migrate($conn, 'rbac_perm_upgrades_v1', function($db) {
    $db->query("INSERT IGNORE INTO permissions (name, slug, module, sort_order) VALUES ('Manage Recipes', 'manage_recipes', 'Inventory', 17)");
    $db->query("INSERT IGNORE INTO role_permissions (role, permission_id) SELECT 'manager', id FROM permissions WHERE slug='manage_recipes'");
    $db->query("INSERT IGNORE INTO role_permissions (role, permission_id) SELECT 'manager', id FROM permissions WHERE slug='promotions'");
    $db->query("INSERT IGNORE INTO role_permissions (role, permission_id) SELECT 'barista', id FROM permissions WHERE slug IN ('view_orders','recipes')");
    $db->query("INSERT IGNORE INTO role_permissions (role, permission_id) SELECT 'supervisor', id FROM permissions WHERE slug IN (
        'dashboard','find_orders','view_orders','loyalty',
        'ingredients','recipes','manage_recipes','suppliers',
        'announcements','attendance'
    )");
    $db->query("INSERT IGNORE INTO role_permissions (role, permission_id) SELECT 'inventory_clerk', id FROM permissions WHERE slug IN ('products','ingredients','recipes','suppliers','purchase_orders')");
    $db->query("INSERT IGNORE INTO permissions (name, slug, module, sort_order) VALUES ('Reset Password', 'reset_password', 'Staff', 18)");
    $db->query("INSERT IGNORE INTO role_permissions (role, permission_id) SELECT 'manager', id FROM permissions WHERE slug='reset_password'");
});

_migrate($conn, 'rbac_my_profile_v1', function($db) {
    $db->query("INSERT IGNORE INTO permissions (name, slug, module, sort_order) VALUES ('My Profile', 'my_profile', 'Staff', 19)");
    $db->query("INSERT IGNORE INTO role_permissions (role, permission_id) SELECT 'manager', id FROM permissions WHERE slug='my_profile'");
    $db->query("INSERT IGNORE INTO role_permissions (role, permission_id) SELECT 'staff', id FROM permissions WHERE slug='my_profile'");
});

_migrate($conn, 'rbac_my_profile_v2', function($db) {
    $db->query("INSERT IGNORE INTO role_permissions (role, permission_id) SELECT 'barista', id FROM permissions WHERE slug='my_profile'");
    $db->query("INSERT IGNORE INTO role_permissions (role, permission_id) SELECT 'supervisor', id FROM permissions WHERE slug='my_profile'");
    $db->query("INSERT IGNORE INTO role_permissions (role, permission_id) SELECT 'inventory_clerk', id FROM permissions WHERE slug='my_profile'");
});

_migrate($conn, 'rbac_barista_station_recon_v1', function($db) {
    $db->query("INSERT IGNORE INTO permissions (name, slug, module, sort_order) VALUES ('Barista Station', 'barista_station', 'Operations', 20)");
    $db->query("INSERT IGNORE INTO permissions (name, slug, module, sort_order) VALUES ('Cash Count', 'cash_reconciliation', 'Analytics', 21)");
    // Barista station: all operational roles
    $db->query("INSERT IGNORE INTO role_permissions (role, permission_id) SELECT 'admin',    id FROM permissions WHERE slug='barista_station'");
    $db->query("INSERT IGNORE INTO role_permissions (role, permission_id) SELECT 'manager',  id FROM permissions WHERE slug='barista_station'");
    $db->query("INSERT IGNORE INTO role_permissions (role, permission_id) SELECT 'supervisor',id FROM permissions WHERE slug='barista_station'");
    $db->query("INSERT IGNORE INTO role_permissions (role, permission_id) SELECT 'staff',    id FROM permissions WHERE slug='barista_station'");
    $db->query("INSERT IGNORE INTO role_permissions (role, permission_id) SELECT 'barista',  id FROM permissions WHERE slug='barista_station'");
    // Cash reconciliation report: managers and admins only
    $db->query("INSERT IGNORE INTO role_permissions (role, permission_id) SELECT 'admin',   id FROM permissions WHERE slug='cash_reconciliation'");
    $db->query("INSERT IGNORE INTO role_permissions (role, permission_id) SELECT 'manager', id FROM permissions WHERE slug='cash_reconciliation'");
});

// ── Add customer_display permission ──
_migrate($conn, 'rbac_customer_display_v1', function($db) {
    $db->query("INSERT IGNORE INTO permissions (name, slug, module, sort_order) VALUES ('Customer Display', 'customer_display', 'Operations', 21)");
    $db->query("INSERT IGNORE INTO role_permissions (role, permission_id) SELECT 'supervisor', id FROM permissions WHERE slug='customer_display'");
    $db->query("INSERT IGNORE INTO role_permissions (role, permission_id) SELECT 'staff',      id FROM permissions WHERE slug='customer_display'");
    $db->query("INSERT IGNORE INTO role_permissions (role, permission_id) SELECT 'barista',    id FROM permissions WHERE slug='customer_display'");
});

// ── Remove barista_station from management roles (they use full dashboard, not barista display) ──
_migrate($conn, 'rbac_barista_station_mgmt_fix_v1', function($db) {
    $db->query("DELETE rp FROM role_permissions rp
                JOIN permissions p ON rp.permission_id = p.id
                WHERE p.slug = 'barista_station'
                  AND rp.role IN ('admin', 'manager', 'supervisor')");
});

// ── Drop legacy token_number_old column (unused) ──
_migrate($conn, 'orders_drop_token_number_old_v1', function($db) {
    $db->query("ALTER TABLE orders DROP COLUMN IF EXISTS token_number_old");
});

// ── Remove redundant Table Management (cafe_tables) — superseded by stand numbers ──
_migrate($conn, 'remove_cafe_tables_v1', function($db) {
    $db->query("DELETE rp FROM role_permissions rp
                JOIN permissions p ON rp.permission_id = p.id
                WHERE p.slug = 'tables'");
    $db->query("DELETE FROM permissions WHERE slug = 'tables'");
    $db->query("DROP TABLE IF EXISTS cafe_tables");
});

// ── Migrate role_permissions: replace role VARCHAR with role_id INT FK ──
_migrate($conn, 'rbac_role_permissions_int_fk_v1', function($db) {
    // Add role_id column (idempotent — IF NOT EXISTS)
    $db->query("ALTER TABLE role_permissions ADD COLUMN IF NOT EXISTS role_id INT NULL");
    if ($db->errno) return;

    // Populate role_id from slug
    $db->query("UPDATE role_permissions rp JOIN roles r ON r.slug = rp.role SET rp.role_id = r.id WHERE rp.role_id IS NULL");
    if ($db->errno) return;

    // Remove rows that cannot be migrated — orphaned permission_id or unrecognised role slug
    $db->query("DELETE FROM role_permissions WHERE permission_id NOT IN (SELECT id FROM permissions)");
    if ($db->errno) return;
    $db->query("DELETE FROM role_permissions WHERE role_id IS NULL");
    if ($db->errno) return;

    // Restructure: drop old composite PK, add auto-increment id as PK,
    // add created_at, make role_id NOT NULL, drop old role VARCHAR, add FK constraints
    $db->query("ALTER TABLE role_permissions
        DROP PRIMARY KEY,
        ADD COLUMN id INT NOT NULL AUTO_INCREMENT FIRST,
        ADD PRIMARY KEY (id),
        ADD COLUMN created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        MODIFY COLUMN role_id INT NOT NULL,
        DROP COLUMN role,
        ADD UNIQUE KEY uq_role_perm (role_id, permission_id),
        ADD CONSTRAINT fk_rp_role FOREIGN KEY (role_id)       REFERENCES roles(id)       ON DELETE CASCADE,
        ADD CONSTRAINT fk_rp_perm FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE");
});

// ── Migrate users: replace role VARCHAR with role_id INT FK ──
_migrate($conn, 'rbac_users_role_id_v1', function($db) {
    $db->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS role_id INT NULL");
    if ($db->errno) return;
    $db->query("UPDATE users u JOIN roles r ON r.slug = u.role SET u.role_id = r.id WHERE u.role_id IS NULL");
    if ($db->errno) return;
    // Fallback: any user whose role slug has no match → map to 'staff'
    $db->query("UPDATE users u JOIN roles r ON r.slug='staff' SET u.role_id = r.id WHERE u.role_id IS NULL");
    if ($db->errno) return;
    $db->query("ALTER TABLE users
        MODIFY COLUMN role_id INT NOT NULL,
        DROP COLUMN role,
        ADD CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles(id)");
});

// ── Audit log table ──
_migrate($conn, 'role_audit_log_v1', function($db) {
    $db->query("CREATE TABLE IF NOT EXISTS role_audit_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        action VARCHAR(50) NOT NULL,
        role_slug VARCHAR(50) NOT NULL,
        detail TEXT NULL,
        performed_by VARCHAR(100) NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_role (role_slug),
        INDEX idx_created (created_at)
    ) DEFAULT CHARSET=utf8mb4");
});

// ── Split cancel/refund columns out of orders into dedicated tables ──
_migrate($conn, 'orders_split_cancel_refund_v1', function($db) {
    $db->query("CREATE TABLE IF NOT EXISTS order_cancellations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        order_id INT NOT NULL UNIQUE,
        cancel_reason VARCHAR(255) NOT NULL,
        cancelled_at DATETIME NOT NULL,
        cancelled_by VARCHAR(100) NOT NULL DEFAULT '',
        CONSTRAINT fk_oc_order FOREIGN KEY (order_id) REFERENCES orders(order_id) ON DELETE CASCADE
    ) DEFAULT CHARSET=utf8mb4");
    if ($db->errno) return;

    $db->query("CREATE TABLE IF NOT EXISTS order_refunds (
        id INT AUTO_INCREMENT PRIMARY KEY,
        order_id INT NOT NULL UNIQUE,
        refund_amount DECIMAL(10,2) NOT NULL,
        refund_reason VARCHAR(255) NOT NULL DEFAULT '',
        refunded_at DATETIME NOT NULL,
        refunded_by VARCHAR(100) NOT NULL DEFAULT '',
        CONSTRAINT fk_ref_order FOREIGN KEY (order_id) REFERENCES orders(order_id) ON DELETE CASCADE
    ) DEFAULT CHARSET=utf8mb4");
    if ($db->errno) return;

    // Migrate existing cancellation data
    $db->query("INSERT IGNORE INTO order_cancellations (order_id, cancel_reason, cancelled_at, cancelled_by)
        SELECT order_id, cancel_reason, COALESCE(cancelled_at, NOW()), COALESCE(cancelled_by, '')
        FROM orders WHERE cancel_reason IS NOT NULL AND cancel_reason != ''");
    if ($db->errno) return;

    // Migrate existing refund data
    $db->query("INSERT IGNORE INTO order_refunds (order_id, refund_amount, refund_reason, refunded_at, refunded_by)
        SELECT order_id, refund_amount, COALESCE(refund_reason, ''), COALESCE(refunded_at, NOW()), COALESCE(refunded_by, '')
        FROM orders WHERE is_refunded = 1");
    if ($db->errno) return;

    $db->query("ALTER TABLE orders
        DROP COLUMN cancel_reason,
        DROP COLUMN cancelled_at,
        DROP COLUMN cancelled_by,
        DROP COLUMN refund_amount,
        DROP COLUMN refund_reason,
        DROP COLUMN refunded_at,
        DROP COLUMN refunded_by,
        DROP COLUMN is_refunded");
});

// ── Add missing FK constraints across all tables ──
_migrate($conn, 'add_missing_fks_v1', function($db) {
    // Nullify orphaned rows before attaching FKs
    $db->query("UPDATE orders SET employee_id = NULL WHERE employee_id IS NOT NULL AND employee_id NOT IN (SELECT employee_id FROM employees)");
    if ($db->errno) return;
    $db->query("UPDATE ingredient_history SET order_id = NULL WHERE order_id IS NOT NULL AND order_id NOT IN (SELECT order_id FROM orders)");
    if ($db->errno) return;

    // orders → users / customers / employees (all nullable → SET NULL on delete)
    $db->query("ALTER TABLE orders ADD CONSTRAINT fk_orders_user     FOREIGN KEY (user_id)     REFERENCES users(user_id)           ON DELETE SET NULL");
    if ($db->errno) return;
    $db->query("ALTER TABLE orders ADD CONSTRAINT fk_orders_customer FOREIGN KEY (customer_id) REFERENCES customers(customer_id)   ON DELETE SET NULL");
    if ($db->errno) return;
    $db->query("ALTER TABLE orders ADD CONSTRAINT fk_orders_employee FOREIGN KEY (employee_id) REFERENCES employees(employee_id)   ON DELETE SET NULL");
    if ($db->errno) return;

    // employees → users (nullable → SET NULL)
    $db->query("ALTER TABLE employees ADD CONSTRAINT fk_employees_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE SET NULL");
    if ($db->errno) return;

    // attendance → users (NOT NULL → RESTRICT so records are preserved)
    $db->query("ALTER TABLE attendance ADD CONSTRAINT fk_attendance_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE RESTRICT");
    if ($db->errno) return;

    // announcement_reads → users + announcements (CASCADE: delete reads when parent goes)
    $db->query("ALTER TABLE announcement_reads ADD CONSTRAINT fk_ar_user         FOREIGN KEY (user_id)         REFERENCES users(user_id)   ON DELETE CASCADE");
    if ($db->errno) return;
    $db->query("ALTER TABLE announcement_reads ADD CONSTRAINT fk_ar_announcement FOREIGN KEY (announcement_id) REFERENCES announcements(id) ON DELETE CASCADE");
    if ($db->errno) return;

    // cash_counts → users (RESTRICT: keep financial history)
    $db->query("ALTER TABLE cash_counts ADD CONSTRAINT fk_cr_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE RESTRICT");
    if ($db->errno) return;

    // ingredients → suppliers (nullable → SET NULL when supplier deleted)
    $db->query("ALTER TABLE ingredients ADD CONSTRAINT fk_ingredients_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(supplier_id) ON DELETE SET NULL");
    if ($db->errno) return;

    // ingredient_daily_stock → ingredients (CASCADE: stock rows belong to ingredient)
    $db->query("ALTER TABLE ingredient_daily_stock ADD CONSTRAINT fk_ids_ingredient FOREIGN KEY (ingredient_id) REFERENCES ingredients(ingredient_id) ON DELETE CASCADE");
    if ($db->errno) return;

    // ingredient_history → ingredients (RESTRICT) + orders (nullable → SET NULL)
    $db->query("ALTER TABLE ingredient_history ADD CONSTRAINT fk_ih_ingredient FOREIGN KEY (ingredient_id) REFERENCES ingredients(ingredient_id) ON DELETE RESTRICT");
    if ($db->errno) return;
    $db->query("ALTER TABLE ingredient_history ADD CONSTRAINT fk_ih_order FOREIGN KEY (order_id) REFERENCES orders(order_id) ON DELETE SET NULL");
    if ($db->errno) return;

    // order_remakes → orders (CASCADE: remakes belong to the order)
    $db->query("ALTER TABLE order_remakes ADD CONSTRAINT fk_or_order FOREIGN KEY (order_id) REFERENCES orders(order_id) ON DELETE CASCADE");
    if ($db->errno) return;

    // stock_refills → ingredients (RESTRICT: keep refill history)
    $db->query("ALTER TABLE stock_refills ADD CONSTRAINT fk_sr_ingredient FOREIGN KEY (ingredient_id) REFERENCES ingredients(ingredient_id) ON DELETE RESTRICT");
});

// ── Add category_id FK to products (categories table already exists) ──
_migrate($conn, 'products_category_fk_v1', function($db) {
    $db->query("ALTER TABLE products ADD COLUMN IF NOT EXISTS category_id INT NULL");
    if ($db->errno) return;
    // Populate from slug match (all existing slugs match exactly)
    $db->query("UPDATE products p JOIN categories c ON c.slug = p.category SET p.category_id = c.category_id WHERE p.category_id IS NULL");
    if ($db->errno) return;
    $db->query("ALTER TABLE products ADD CONSTRAINT fk_products_category FOREIGN KEY (category_id) REFERENCES categories(category_id) ON DELETE SET NULL");
});

// ── Rename cash_reconciliations → cash_counts (legacy installs only) ──
_migrate($conn, 'rename_cash_reconciliations_to_cash_counts_v1', function($db) {
    // Only rename when the old table still exists and the new one doesn't.
    // Fresh installs already create cash_counts directly above, so this is a no-op there.
    $hasOld = $db->query("SHOW TABLES LIKE 'cash_reconciliations'")->num_rows > 0;
    $hasNew = $db->query("SHOW TABLES LIKE 'cash_counts'")->num_rows > 0;
    if ($hasOld && !$hasNew) {
        $db->query("RENAME TABLE cash_reconciliations TO cash_counts");
    }
});

// ── Drop the zombie cash_reconciliations table ──
// A stale CREATE used to recreate it (empty) on every page load after the rename
// above had already moved real data to cash_counts. The CREATE now targets
// cash_counts, so this one-time drop sticks. Safe: no code writes the old table.
_migrate($conn, 'drop_zombie_cash_reconciliations_v1', function($db) {
    $db->query("DROP TABLE IF EXISTS cash_reconciliations");
});

// ── Rename permission display name ──
_migrate($conn, 'rename_permission_cash_reconciliation_to_cash_count_v1', function($db) {
    $db->query("UPDATE permissions SET name = 'Cash Count' WHERE slug = 'cash_reconciliation'");
});

// ── Remove test permission ──
_migrate($conn, 'delete_test_permission_only_sigma_boy_v3', function($db) {
    $db->query("DELETE FROM permissions WHERE name = 'OnlySigmaBoy' OR slug IN ('only_sigma_boy','onlysigmaboy')");
    $db->query("DELETE FROM role_permissions WHERE permission_id NOT IN (SELECT id FROM permissions)");
});

// ── Stock Count: tables + permission + role grants ──
_migrate($conn, 'stock_count_v1', function($db) {
    $db->query("CREATE TABLE IF NOT EXISTS stock_counts (
        count_id      INT AUTO_INCREMENT PRIMARY KEY,
        business_date DATE NOT NULL,
        status        ENUM('draft','submitted') NOT NULL DEFAULT 'draft',
        created_by    VARCHAR(100) NULL,
        submitted_by  VARCHAR(100) NULL,
        submitted_at  DATETIME NULL,
        notes         TEXT NULL,
        created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_business_date (business_date)
    ) DEFAULT CHARSET=utf8mb4");
    if ($db->errno) return;

    $db->query("CREATE TABLE IF NOT EXISTS stock_count_items (
        item_id       INT AUTO_INCREMENT PRIMARY KEY,
        count_id      INT NOT NULL,
        ingredient_id INT NOT NULL,
        opening_stock DECIMAL(10,4) NOT NULL DEFAULT 0,
        system_used   DECIMAL(10,4) NOT NULL DEFAULT 0,
        expected_qty  DECIMAL(10,4) NOT NULL DEFAULT 0,
        actual_qty    DECIMAL(10,4) NULL,
        variance      DECIMAL(10,4) NULL,
        UNIQUE KEY uq_count_ingredient (count_id, ingredient_id),
        CONSTRAINT fk_sci_count      FOREIGN KEY (count_id)      REFERENCES stock_counts(count_id)          ON DELETE CASCADE,
        CONSTRAINT fk_sci_ingredient FOREIGN KEY (ingredient_id) REFERENCES ingredients(ingredient_id)      ON DELETE RESTRICT
    ) DEFAULT CHARSET=utf8mb4");
    if ($db->errno) return;

    $db->query("INSERT IGNORE INTO permissions (name, slug, module, sort_order) VALUES ('Stock Count', 'stock_count', 'Reconciliation', 22)");
    if ($db->errno) return;

    // Grant to admin, manager, inventory_clerk, supervisor by default
    foreach (['admin','manager','inventory_clerk','supervisor'] as $role) {
        $db->query("INSERT IGNORE INTO role_permissions (role_id, permission_id)
            SELECT r.id, p.id FROM roles r, permissions p
            WHERE r.slug='$role' AND p.slug='stock_count'");
    }
});

_migrate($conn, 'stock_count_module_fix_v2', function($db) {
    $db->query("UPDATE permissions SET module='Reconciliation' WHERE slug IN ('stock_count','cash_reconciliation')");
});

// ── Re-grant barista_station via role_id ──
// The legacy rbac_barista_station_recon_v1 inserted into a `role` (slug) column
// that was later dropped in favour of role_id, so those grants silently failed
// and NO role actually held barista_station — only admin (can() bypass) could
// reach barista_display.php. Re-grant to the operational roles using role_id.
// admin bypasses can(), so it does not need an explicit row.
_migrate($conn, 'rbac_barista_station_roleid_v1', function($db) {
    foreach (['barista', 'manager'] as $role) {
        $db->query("INSERT IGNORE INTO role_permissions (role_id, permission_id)
            SELECT r.id, p.id FROM roles r, permissions p
            WHERE r.slug='$role' AND p.slug='barista_station'");
    }
});

// ── Drink sizes: products.has_sizes, product_sizes table, order_items size columns ──
_migrate($conn, 'drink_sizes_v1', function($db) {
    $db->query("ALTER TABLE products ADD COLUMN IF NOT EXISTS has_sizes TINYINT(1) NOT NULL DEFAULT 0");
    if ($db->errno) return;

    $db->query("CREATE TABLE IF NOT EXISTS product_sizes (
        size_id     INT(11) NOT NULL AUTO_INCREMENT,
        product_id  INT(11) NOT NULL,
        size_code   VARCHAR(10) NOT NULL,
        label       VARCHAR(20) NOT NULL,
        price       DECIMAL(10,2) NOT NULL,
        size_factor DECIMAL(4,2) NOT NULL DEFAULT 1.00,
        sort_order  INT(11) NOT NULL DEFAULT 0,
        PRIMARY KEY (size_id),
        UNIQUE KEY uq_product_size (product_id, size_code),
        CONSTRAINT fk_product_sizes_product FOREIGN KEY (product_id)
            REFERENCES products(product_id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    if ($db->errno) return;

    $db->query("ALTER TABLE order_items
        ADD COLUMN IF NOT EXISTS size_code  VARCHAR(10) NULL,
        ADD COLUMN IF NOT EXISTS size_label VARCHAR(20) NULL");
});

// ── Loyalty history: widen type ENUM so adjustment rows store correctly ──
// Code writes 'adjusted_add'/'adjusted_deduct' (cancel reversal, order-edit point sync).
// The original ENUM lacked them → on strict-mode MySQL those INSERTs fail; on lax mode
// they silently stored ''. Add the values so every loyalty path records accurately.
_migrate($conn, 'loyalty_history_type_enum_v1', function($db) {
    $db->query("ALTER TABLE loyalty_history MODIFY COLUMN type ENUM('earned','redeemed','bonus','created','adjusted_add','adjusted_deduct') NOT NULL");
});

// ── Partial receiving: record what actually arrived, not what was ordered ──
// mark_received used to add qty_ordered to stock regardless of the delivery,
// so a short delivery silently inflated inventory. qty_received is the number
// the clerk counted off the truck.
_migrate($conn, 'po_partial_receive_v1', function($db) {
    $db->query("ALTER TABLE purchase_order_items
                ADD COLUMN IF NOT EXISTS qty_received DECIMAL(10,3) NOT NULL DEFAULT 0");

    $db->query("ALTER TABLE purchase_orders
                MODIFY COLUMN status
                ENUM('Draft','Ordered','Partially Received','Received','Cancelled')
                NULL DEFAULT 'Draft'");

    $db->query("ALTER TABLE purchase_orders
                ADD COLUMN IF NOT EXISTS closed_short TINYINT(1) NOT NULL DEFAULT 0");
    $db->query("ALTER TABLE purchase_orders
                ADD COLUMN IF NOT EXISTS closed_short_at DATETIME NULL DEFAULT NULL");
    $db->query("ALTER TABLE purchase_orders
                ADD COLUMN IF NOT EXISTS closed_short_by VARCHAR(100) NULL DEFAULT NULL");

    // Anything already Received was received in full — that was the only
    // behaviour the old code had. Without this backfill all twelve historical
    // POs would render as shortfalls the moment the new columns appear.
    $db->query("UPDATE purchase_order_items poi
                JOIN purchase_orders p ON p.po_id = poi.po_id
                SET poi.qty_received = poi.qty_ordered
                WHERE p.status = 'Received'");
});

_migrate($conn, 'po_close_short_reason_v1', function($db) {
    // Why the remainder was written off. Stored as a stable CODE so relabelling an
    // option later cannot orphan history, and so write-offs stay groupable.
    //
    // No backfill: there is nothing to guess at, and '' renders honestly as
    // "No reason recorded" rather than inventing an explanation for a decision
    // nobody recorded.
    $db->query("ALTER TABLE purchase_orders
                ADD COLUMN IF NOT EXISTS closed_short_reason VARCHAR(40) NOT NULL DEFAULT ''");
    $db->query("ALTER TABLE purchase_orders
                ADD COLUMN IF NOT EXISTS closed_short_note VARCHAR(255) NULL DEFAULT NULL");
});

// points_progress is the carry toward the next point, always 0..Y-1. points_qty
// records how many earning drinks an order counted — stored rather than
// recomputed because order_items can change afterwards through add-to-order, so
// the quantity that actually earned is not recoverable at refund time.
// Both default to 0, which is already correct for every existing card and order
// at the 1:1 default rate, so no backfill is needed.
_migrate($conn, 'loyalty_progress_v1', function($db) {
    $db->query("ALTER TABLE loyalty_cards ADD COLUMN IF NOT EXISTS points_progress INT NOT NULL DEFAULT 0");
    $db->query("ALTER TABLE orders        ADD COLUMN IF NOT EXISTS points_qty      INT NOT NULL DEFAULT 0");
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

// ── LOYALTY SYSTEM FUNCTIONS ──

if (!function_exists('generateLoyaltyId')) {
    function generateLoyaltyId() {
        return 'CARD-' . str_pad(random_int(1, 99999), 5, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('getLoyaltyCard')) {
    function getLoyaltyCard($conn, $loyalty_id) {
        $stmt = $conn->prepare("
            SELECT * FROM loyalty_cards
            WHERE loyalty_id = ? AND is_active = 1
        ");
        $stmt->bind_param("s", $loyalty_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }
}

if (!function_exists('getLoyaltyHistory')) {
    function getLoyaltyHistory($conn, $card_id, $limit = 10) {
        $stmt = $conn->prepare("
            SELECT * FROM loyalty_history
            WHERE card_id = ?
            ORDER BY created_at DESC
            LIMIT ?
        ");
        $stmt->bind_param("ii", $card_id, $limit);
        $stmt->execute();
        return $stmt->get_result();
    }
}

if (!function_exists('getAvailableRewards')) {
    function getAvailableRewards($conn) {
        $stmt = $conn->prepare("SELECT * FROM rewards WHERE is_active = 1 ORDER BY points_required ASC");
        $stmt->execute();
        return $stmt->get_result();
    }
}

// ── RBAC: can() — check if current session role has a permission ──
if (!function_exists('can')) {
    function can(string $slug): bool {
        global $conn;
        static $perms    = null;
        static $is_admin = null;
        if ($is_admin === null) {
            if (session_status() === PHP_SESSION_NONE) session_start();
            $role     = $_SESSION['role'] ?? 'staff';
            $is_admin = ($role === 'admin');
            if (!$is_admin) {
                $perms = [];
                $r = $conn->prepare("SELECT p.slug FROM permissions p JOIN role_permissions rp ON rp.permission_id=p.id JOIN roles ro ON ro.id=rp.role_id WHERE ro.slug=?");
                $r->bind_param("s", $role);
                $r->execute();
                $res = $r->get_result();
                while ($row = $res->fetch_assoc()) $perms[$row['slug']] = true;
            }
        }
        return $is_admin || isset($perms[$slug]);
    }
}
?>