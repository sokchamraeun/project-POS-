<?php
session_start();
require 'config.php';

header('Content-Type: application/json; charset=UTF-8');

function json_out(bool $success, string $message, ?int $cart_count = null, ?float $cart_total = null, int $status = 200): void {
    http_response_code($status);
    echo json_encode([
        'success'    => $success,
        'message'    => $message,
        'cart_count' => $cart_count,
        'cart_total' => $cart_total,
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

$qty = max(1, min(99, (int)($_POST['qty'] ?? 1)));

$sweetness = trim((string)($_POST['sweetness'] ?? ''));
$ice       = trim((string)($_POST['ice']       ?? ''));
$milk      = trim((string)($_POST['milk']      ?? ''));
$size_code = trim((string)($_POST['size'] ?? ''));

// Validate options against allowed values
$valid_sweetness = ['0%', '25%', '50%', '75%', '100%', ''];
$valid_ice       = ['No Ice', 'Less Ice', 'Normal Ice', 'More Ice', ''];
// Milk whitelist is admin-managed (milk_options); build it from the active set + '' (no milk).
$valid_milk = [''];
$mk_wl = $conn->query("SELECT name FROM milk_options WHERE is_active = 1");
if ($mk_wl) { while ($mw = $mk_wl->fetch_assoc()) $valid_milk[] = $mw['name']; }

if ($sweetness !== '' && !in_array($sweetness, $valid_sweetness)) {
    json_out(false, 'Invalid sweetness option', 0, null, 400);
}
if ($ice !== '' && !in_array($ice, $valid_ice)) {
    json_out(false, 'Invalid ice option', 0, null, 400);
}
if ($milk !== '' && !in_array($milk, $valid_milk)) {
    json_out(false, 'Invalid milk option', 0, null, 400);
}

// Fetch product
$stmt = $conn->prepare("SELECT p.product_id, p.name, p.price, p.image, p.has_sizes, p.promo_percent, COALESCE(c.earns_points,1) AS earns_points FROM products p LEFT JOIN categories c ON c.category_id = p.category_id WHERE p.product_id = ?");
$stmt->bind_param("i", $product_id);
$stmt->execute();
$res = $stmt->get_result();

if (!$res || $res->num_rows === 0) {
    json_out(false, 'Product not found or unavailable', 0, null, 404);
}

$p = $res->fetch_assoc();

// ── Add-ons: validate posted ids against this product's active assignments ──
$posted_addons = array_values(array_unique(array_map('intval', $_POST['addons'] ?? [])));
$addons = [];       // ordered [{id,name,price}]
$addon_sum = 0.0;
if ($posted_addons) {
    $in = implode(',', array_fill(0, count($posted_addons), '?'));
    $types = str_repeat('i', count($posted_addons));
    // Gate on the product's category Offer too (mirror menu: hidden pills must not be addable via a stale/crafted POST)
    $sql = "SELECT a.id, a.name, a.price
            FROM product_addons pa
            JOIN addons a      ON a.id = pa.addon_id
            JOIN products pr   ON pr.product_id = pa.product_id
            JOIN categories c  ON c.slug = pr.category
            WHERE pa.product_id = ? AND a.is_active = 1 AND c.offer_addons = 1 AND a.id IN ($in)
            ORDER BY a.display_order ASC, a.id ASC";
    $st = $conn->prepare($sql);
    $st->bind_param('i' . $types, $product_id, ...$posted_addons);
    $st->execute();
    $rs = $st->get_result();
    $valid_ids = [];
    while ($r = $rs->fetch_assoc()) {
        $addons[] = ['id'=>(int)$r['id'], 'name'=>$r['name'], 'price'=>(float)$r['price']];
        $addon_sum += (float)$r['price'];
        $valid_ids[] = (int)$r['id'];
    }
    // reject if any posted id was not a valid assignment
    if (count($valid_ids) !== count($posted_addons)) {
        json_out(false, 'Invalid add-on selection', 0, null, 400);
    }
}

// ── Resolve size (per-size absolute price; defensive fallback to base) ──
$line_price   = (float)$p['price'];   // products.price == Medium / base
$size_label   = '';
$size_factor  = 1.0;
$resolved_code = '';

if ((int)$p['has_sizes'] === 1) {
    $rows = [];
    $sz = $conn->prepare("SELECT size_code, label, price, size_factor FROM product_sizes WHERE product_id = ?");
    $sz->bind_param("i", $product_id);
    $sz->execute();
    $rs = $sz->get_result();
    while ($r = $rs->fetch_assoc()) { $rows[$r['size_code']] = $r; }

    if (!empty($rows)) {
        if ($size_code === '') {
            $size_code = count($rows) === 1 ? (string)array_key_first($rows) : ($rows['M']['size_code'] ?? (string)array_key_first($rows));
        }
        // size_code is required for a sized product
        if (!isset($rows[$size_code])) {
            json_out(false, 'Please choose a size', 0, null, 400);
        }
        $chosen        = $rows[$size_code];
        $line_price    = (float)$chosen['price'];
        $size_label    = (string)$chosen['label'];
        $size_factor   = (float)$chosen['size_factor'];
        $resolved_code = $size_code;
    }
    // has_sizes=1 but zero rows → fall through as unsized (base price, factor 1.0)
}

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

// Merge identical items
$addon_sig = implode(',', array_map(fn($a) => $a['id'], $addons));  // ordered ids
$found = false;
foreach ($_SESSION['cart'] as &$item) {
    if (
        $item['product_id'] == $product_id &&
        (int)($item['promo_percent'] ?? 0) == $promo_percent &&
        (int)($item['earns_points'] ?? 1) == $earns_points &&
        ($item['size_code'] ?? '') == $resolved_code &&
        $item['sweetness']  == $sweetness  &&
        $item['ice']        == $ice        &&
        $item['milk']       == $milk       &&
        (implode(',', array_map(fn($x) => $x['id'], $item['addons'] ?? [])) === $addon_sig)
    ) {
        $item['qty'] += $qty;
        $found = true;
        break;
    }
}
unset($item);

if (!$found) {
    $_SESSION['cart'][] = [
        'product_id'   => $p['product_id'],
        'product_name' => $p['name'],
        'price'        => $line_price,
        'orig_price'   => $orig_price,
        'promo_percent'=> $promo_percent,
        'earns_points' => $earns_points,
        'image'        => $p['image'],
        'size_code'    => $resolved_code,
        'size_label'   => $size_label,
        'size_factor'  => $size_factor,
        'sweetness'    => $sweetness,
        'ice'          => $ice,
        'milk'         => $milk,
        'addons'       => $addons,
        'qty'          => $qty,
    ];
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

json_out(true, 'Added to cart!', $total_qty, round($cart_total, 2));
