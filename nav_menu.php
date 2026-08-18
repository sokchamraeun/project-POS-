<?php
// nav_menu.php — canonical permission→navigation registry (single source of truth).
// Access control stays on each page (can() guards); this drives discovery/links only.
// Requires: auth.php already loaded (can() available) and a mysqli $conn.

// Route/icon registry keyed by permission slug: [href, icon, admin_only?].
// Perms absent here are non-navigable by design (manage_recipes, my_profile).
// `dashboard` is intentionally absent — the home link is hardcoded per layout.
$NAV_REGISTRY = [
    'view_orders'         => ['view_order.php',            'fa-receipt'],
    'products'            => ['products.php',              'fa-cube'],
    'manage_categories'   => ['manage_categories.php',     'fa-tags'],
    'report'              => ['daily_report.php',          'fa-chart-column'],
];

// Explicit section display order — never trust DB module/insertion order.
$NAV_SECTION_ORDER = ['Orders','Inventory','Analytics','Staff'];

/**
 * Nav items the current user may see. Each: slug,label,href,icon,section,admin_only.
 * Filtered by admin_only + can(slug). Sorted by (section index, sort_order, id).
 */
function nav_items(mysqli $conn): array {
    global $NAV_REGISTRY, $NAV_SECTION_ORDER;
    $isAdmin = (($_SESSION['role'] ?? '') === 'admin');
    $rows = [];
    $static_perms = [
        ['id' => 1, 'slug' => 'view_orders', 'name' => 'View Orders', 'module' => 'Orders', 'sort_order' => 1],
        ['id' => 2, 'slug' => 'products', 'name' => 'Products', 'module' => 'Inventory', 'sort_order' => 2],
        ['id' => 3, 'slug' => 'manage_categories', 'name' => 'Categories', 'module' => 'Inventory', 'sort_order' => 3],
        ['id' => 4, 'slug' => 'report', 'name' => 'Daily Report', 'module' => 'Analytics', 'sort_order' => 4],
    ];
    foreach ($static_perms as $p) {
        $slug = $p['slug'];
        if (!isset($NAV_REGISTRY[$slug])) continue;                 // non-navigable
        [$href, $icon, $adminOnly] = array_pad($NAV_REGISTRY[$slug], 3, false);
        if ($adminOnly && !$isAdmin) continue;
        if (!can($slug)) continue;                                  // grant gate
        $secIdx = array_search($p['module'], $NAV_SECTION_ORDER, true);
        $localizedLabel = __('nav_' . $slug, $p['name']);
        $localizedSection = __('sec_' . strtolower($p['module']), $p['module']);
        $rows[] = [
            'slug'=>$slug, 'label'=>$localizedLabel, 'href'=>$href, 'icon'=>$icon,
            'section'=>$localizedSection, 'admin_only'=>(bool)$adminOnly,
            '_sec'=>($secIdx===false ? PHP_INT_MAX : $secIdx),
            '_ord'=>(int)$p['sort_order'], '_id'=>(int)$p['id'],
        ];
    }
    usort($rows, fn($a,$b) => [$a['_sec'],$a['_ord'],$a['_id']] <=> [$b['_sec'],$b['_ord'],$b['_id']]);
    return $rows;
}

/** nav_items() grouped by section, groups already in $NAV_SECTION_ORDER. */
function nav_items_grouped(mysqli $conn): array {
    $g = [];
    foreach (nav_items($conn) as $it) $g[$it['section']][] = $it;
    return $g;
}
