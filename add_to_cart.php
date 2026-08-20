<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/config.php';

if (ob_get_level()) {
    ob_clean();
}

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

function json_out(bool $success, string $message, ?int $cart_count = null, ?float $cart_total = null, int $status = 200, ?array $cart_data = null): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    http_response_code($status);
    echo json_encode([
        'success'    => $success,
        'message'    => $message,
        'cart_count' => $cart_count,
        'cart_total' => $cart_total,
        'cart'       => $cart_data,
    ]);
    exit;
}

// CSRF
$csrf_token = $_POST['csrf_token'] ?? '';
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf_token)) {
    json_out(false, 'Invalid request token', 0, null, 403);
}

$product_id = (int)($_POST['id'] ?? 0);
if ($product_id <= 0) {
    json_out(false, 'Missing product ID', 0, null, 400);
}

$qty = max(1, min(100, (int)($_POST['qty'] ?? 1)));

$sweetness = trim((string)($_POST['sweetness'] ?? ''));
$ice       = trim((string)($_POST['ice']       ?? ''));
$milk      = trim((string)($_POST['milk']      ?? ''));
$size_code = trim((string)($_POST['size'] ?? ''));

// Validate options against allowed values
$valid_sweetness = ['0%', '25%', '50%', '75%', '100%', ''];
$valid_ice       = ['No Ice', 'Less Ice', 'Normal Ice', 'More Ice', ''];
$valid_milk = ['Fresh Milk', 'Almond Milk', 'Soy Milk', 'Oat Milk', ''];
if ($milk !== '' && !in_array($milk, $valid_milk)) {
    $milk = '';
}

// Fetch product
$stmt = $conn->prepare("SELECT p.product_id, p.name, p.price, p.image, p.has_sizes, p.promo_percent, p.category, COALESCE(c.earns_points,1) AS earns_points, COALESCE(c.offer_sweetness,0) AS offer_sweetness, COALESCE(c.offer_ice,0) AS offer_ice FROM products p LEFT JOIN categories c ON (c.category_id = p.category_id OR c.slug = p.category OR c.name = p.category) WHERE p.product_id = ?");
$stmt->bind_param("i", $product_id);
$stmt->execute();
$res = $stmt->get_result();

if (!$res || $res->num_rows === 0) {
    json_out(false, 'Product not found or unavailable', 0, null, 404);
}

$p = $res->fetch_assoc();
$has_sizes = (int)($p['has_sizes'] ?? 0);
$has_custom = ($has_sizes === 1 || (int)($p['offer_sweetness'] ?? 0) === 1 || (int)($p['offer_ice'] ?? 0) === 1) ? 1 : 0;

// ── Stock Check: Validate inventory ingredients ──
try {
    $current_in_cart = 0;
    foreach (($_SESSION['cart'] ?? []) as $cItem) {
        if ((int)($cItem['product_id'] ?? 0) === $product_id) {
            $current_in_cart += (int)($cItem['qty'] ?? 1);
        }
    }

    $max_stock = getProductMaxStock($conn, $product_id);
    if ($max_stock !== null) {
        $total_requested = $current_in_cart + $qty;
        if ($total_requested > $max_stock) {
            $remaining_avail = max(0, $max_stock - $current_in_cart);
            if ($remaining_avail <= 0) {
                json_out(false, "Cannot add '{$p['name']}': All available stock ({$max_stock} units) is already in your cart.", 0, null, 400);
            } else {
                json_out(false, "Cannot add {$qty}x '{$p['name']}': Only {$remaining_avail} more available in stock ({$max_stock} total in stock, {$current_in_cart} already in cart).", 0, null, 400);
            }
        }
    }
} catch (Throwable $e) {
    error_log("add_to_cart stock check error: " . $e->getMessage());
}

// ── Add-ons (removed) ──
$addons = [];
$addon_sum = 0.0;

// ── Resolve size (per-size absolute price; defensive fallback to base) ──
$line_price   = (float)$p['price'];   // products.price == Medium / base
$size_label   = '';
$size_factor  = 1.0;
$resolved_code = '';



// ── Per-product promo: discount the drink only, not add-ons. Round per unit. ──
$promo_percent = max(0, min(100, (int)($p['promo_percent'] ?? 0)));
$gross_drink   = $line_price;                                   // size or base price, pre-addons
$net_drink     = $promo_percent > 0 ? round($gross_drink * (1 - $promo_percent / 100), 2) : $gross_drink;
$orig_price    = $gross_drink + $addon_sum;                     // gross unit (for struck display)
$line_price    = $net_drink   + $addon_sum;                     // net unit (charged + summed everywhere)

// Category loyalty eligibility (merch = 0). Snapshotted so later category changes don't mutate the cart.
$earns_points  = (int)($p['earns_points'] ?? 1);

if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

$cart_was_empty = empty($_SESSION['cart']);

// Merge identical items up to max 100 per line item
$addon_sig = implode(',', array_map(fn($a) => $a['id'], $addons));  // ordered ids
$remaining_qty = $qty;

foreach ($_SESSION['cart'] as &$item) {
    if (
        $item['product_id'] == $product_id &&
        (int)($item['promo_percent'] ?? 0) == $promo_percent &&
        (int)($item['earns_points'] ?? 1) == $earns_points &&
        ($item['size_code'] ?? '') == $resolved_code &&
        $item['sweetness']  == $sweetness  &&
        $item['ice']        == $ice        &&
        $item['milk']       == $milk       &&
        (implode(',', array_map(fn($x) => $x['id'], $item['addons'] ?? [])) === $addon_sig) &&
        (int)($item['qty'] ?? 0) < 100
    ) {
        $space = 100 - (int)$item['qty'];
        $add_here = min($remaining_qty, $space);
        $item['qty'] += $add_here;
        $remaining_qty -= $add_here;
        if ($remaining_qty <= 0) {
            break;
        }
    }
}
unset($item);

// If there is still quantity to add (e.g. existing line was at 100), create new line(s) capped at 100
while ($remaining_qty > 0) {
    $line_qty = min(100, $remaining_qty);
    $_SESSION['cart'][] = [
        'product_id'        => $p['product_id'],
        'product_name'      => $p['name'],
        'category'          => $p['category'] ?? '',
        'has_sizes'         => $has_sizes,
        'has_customization' => $has_custom,
        'price'             => $line_price,
        'orig_price'        => $orig_price,
        'promo_percent'     => $promo_percent,
        'earns_points'      => $earns_points,
        'image'             => $p['image'],
        'size_code'         => $resolved_code,
        'size_label'        => $size_label,
        'size_factor'       => $size_factor,
        'sweetness'         => $sweetness,
        'ice'               => $ice,
        'milk'              => $milk,
        'addons'            => $addons,
        'qty'               => $line_qty,
    ];
    $remaining_qty -= $line_qty;
}

// Stamp when the first item is added to the cart
if ($cart_was_empty && !isset($_SESSION['cart_started_at'])) {
    $_SESSION['cart_started_at'] = date('Y-m-d H:i:s');
}

// Calculate totals
$total_qty   = 0;
$cart_total  = 0.0;
foreach ($_SESSION['cart'] as $item) {
    $q           = (int)($item['qty'] ?? 1);
    $total_qty  += $q;
    $cart_total += (float)($item['price'] ?? 0) * $q;
}

$cart_payload = function_exists('get_cart_payload') ? get_cart_payload($conn) : null;
json_out(true, 'Added to cart!', $total_qty, round($cart_total, 2), 200, $cart_payload);
