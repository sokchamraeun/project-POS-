<?php
// sidebar.php - Shared Left Navigation Sidebar
if (!function_exists('can')) {
    require_once __DIR__ . '/auth.php';
}
require_once __DIR__ . '/config.php';

$_cur_page = basename($_SERVER['PHP_SELF'] ?? '');
$_is_admin = (($_SESSION['role'] ?? '') === 'admin');
$_username = $_SESSION['emp_name'] ?? ($_SESSION['username'] ?? 'User');
$_user_role = ucfirst($_SESSION['role'] ?? 'Staff');
$_role_color = ($_SESSION['role'] ?? '') === 'admin' ? '#ff6b6b' : (($_SESSION['role'] ?? '') === 'manager' ? '#f0b429' : '#d1904b');

// ── Calculate Low Stock / Out of Stock Alert Counts ──
$_stock_drink_alerts = 0;
$_stock_drink_has_out = false;
$_ingredient_alerts = 0;
$_ingredient_has_out = false;

if (isset($conn) && $conn instanceof mysqli) {
    try {
        $q_stock = $conn->query("
            SELECT 
                SUM(CASE WHEN quantity <= alert_level THEN 1 ELSE 0 END) AS alert_cnt,
                SUM(CASE WHEN quantity <= 0 THEN 1 ELSE 0 END) AS out_cnt
            FROM stock_items 
            WHERE item_type = 'direct_drink' AND is_active = 1
        ");
        if ($q_stock && ($r_stock = $q_stock->fetch_assoc())) {
            $_stock_drink_alerts = (int)($r_stock['alert_cnt'] ?? 0);
            $_stock_drink_has_out = (int)($r_stock['out_cnt'] ?? 0) > 0;
        }

        $q_ing = $conn->query("
            SELECT 
                SUM(CASE WHEN quantity <= alert_level THEN 1 ELSE 0 END) AS alert_cnt,
                SUM(CASE WHEN quantity <= 0 THEN 1 ELSE 0 END) AS out_cnt
            FROM stock_items 
            WHERE (item_type = 'ingredient' OR item_type = 'raw_ingredient') 
              AND is_active = 1 
              AND (item_name NOT LIKE '%Packaging Set%' AND item_name NOT LIKE '%ឈុត%')
        ");
        if ($q_ing && ($r_ing = $q_ing->fetch_assoc())) {
            $_ingredient_alerts = (int)($r_ing['alert_cnt'] ?? 0);
            $_ingredient_has_out = (int)($r_ing['out_cnt'] ?? 0) > 0;
        }
    } catch (Throwable $e) {}
}
?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Kantumruy+Pro:ital,wght@0,100..900;1,100..900&family=Noto+Sans+Khmer:wght@100..900&family=Siemreap&family=Poppins:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,400;1,600;1,700&display=swap" rel="stylesheet">
<style>
@import url('https://fonts.googleapis.com/css2?family=Kantumruy+Pro:ital,wght@0,100..900;1,100..900&family=Noto+Sans+Khmer:wght@100..900&family=Siemreap&family=Poppins:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,400;1,600;1,700&display=swap');

/* ══ Master Typography for English & Khmer on All Devices ══ */
body, input, select, textarea, button, .sidebar, .app-main, .modal-content, table, a, span, p, h1, h2, h3, h4, h5, h6, label, div {
    font-family: 'Kantumruy Pro', 'Noto Sans Khmer', 'Siemreap', 'Khmer OS Battambang', 'Khmer OS Siemreap', 'Poppins', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
    -webkit-font-smoothing: antialiased;
    -moz-osx-font-smoothing: grayscale;
    text-rendering: optimizeLegibility;
}
:lang(km), [data-lang="km"], html[lang="km"], html[lang="km"] * {
    font-family: 'Kantumruy Pro', 'Noto Sans Khmer', 'Siemreap', 'Khmer OS Battambang', 'Khmer OS Siemreap', 'Poppins', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif !important;
}

/* ══ Safeguard Font Awesome Icon Fonts ══ */
.fa, .fa-solid, .fa-regular, .fa-brands, .fa-duotone, .fa-light, .fa-thin, .fas, .far, .fab, .fad, [class*="fa-"], i.fa, i[class*="fa-"], i {
    font-family: 'Font Awesome 6 Free', 'FontAwesome' !important;
}
.fa-brands, .fab, [class*="fa-brands"] {
    font-family: 'Font Awesome 6 Brands', 'FontAwesome' !important;
}

/* ══ Persistent Master Layout Shell ══ */
:root {
    --sidebar-w: 240px;
}
:root.sidebar-collapsed {
    --sidebar-w: 72px;
}
body {
    margin: 0;
    padding: 0;
    background-color: var(--bg, #0b1329);
}
.app-layout, .layout {
    display: flex;
    height: 100vh;
    width: 100vw;
    overflow: hidden;
    position: relative;
    background-color: var(--bg, #0b1329);
}

/* ══ Modern Midnight Navy & Emerald Sidebar ══ */
.sidebar,
html[data-theme="light"] .sidebar,
[data-theme="light"] .sidebar {
    width: var(--sidebar-w, 240px) !important;
    min-width: var(--sidebar-w, 240px) !important;
    max-width: var(--sidebar-w, 240px) !important;
    flex-shrink: 0 !important;
    height: 100vh;
    position: relative !important;
    z-index: 50;
    background: #0b1329 !important;
    border-right: 1px solid #1e293b !important;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    padding: 1.25rem 0.85rem 1rem 0.85rem !important;
    overflow-y: auto;
    overflow-x: hidden;
    scrollbar-width: none !important;
    -ms-overflow-style: none !important;
    color: #94a3b8 !important;
    transition: width 0.22s cubic-bezier(0.4, 0, 0.2, 1), min-width 0.22s cubic-bezier(0.4, 0, 0.2, 1), max-width 0.22s cubic-bezier(0.4, 0, 0.2, 1), padding 0.22s ease !important;
}
.sidebar::-webkit-scrollbar {
    width: 0px !important;
    height: 0px !important;
    display: none !important;
}
.sidebar.sidebar-no-anim {
    transition: none !important;
}

/* Permanent Dark Sidebar Lock */
.sidebar *,
html[data-theme="light"] .sidebar *,
[data-theme="light"] .sidebar * {
    color-scheme: dark;
}

/* Header / Brand Block */
.sidebar-brand-block {
    padding: 0.25rem 0.5rem 0.75rem 0.5rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    position: relative;
}
.sidebar-brand-info {
    display: flex;
    flex-direction: column;
    min-width: 0;
}
.sidebar-brand-subtitle {
    font-size: 10.5px !important;
    font-weight: 700 !important;
    letter-spacing: 1.2px !important;
    text-transform: uppercase !important;
    color: #94a3b8 !important;
    line-height: 1.2 !important;
}
.sidebar-brand-title {
    font-size: 16px !important;
    font-weight: 800 !important;
    letter-spacing: 0.5px !important;
    text-transform: uppercase !important;
    color: #ffffff !important;
    margin-top: 4px !important;
    line-height: 1.2 !important;
    white-space: nowrap;
}
.sidebar-brand-cartoon {
    width: 84px;
    height: 84px;
    flex-shrink: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-top: -12px;
    margin-bottom: -12px;
    margin-right: -6px;
    perspective: 600px;
}
.sidebar-brand-cartoon img {
    width: 100%;
    height: 100%;
    object-fit: contain;
    filter: drop-shadow(0 8px 16px rgba(0, 0, 0, 0.5));
    animation: cartoonFloat 3s ease-in-out infinite !important;
    transform-origin: center bottom;
    user-select: none;
    -webkit-user-drag: none;
    pointer-events: auto;
}

@keyframes cartoonFloat {
    0%, 100% {
        transform: translateY(0px) rotate(0deg);
    }
    30% {
        transform: translateY(-6px) rotate(-2.5deg);
    }
    70% {
        transform: translateY(-3px) rotate(3deg);
    }
}

/* Section Label */
.sidebar-section-title {
    font-size: 10px !important;
    font-weight: 700 !important;
    letter-spacing: 1.2px !important;
    text-transform: uppercase !important;
    color: #64748b !important;
    padding: 10px 0.5rem 6px 0.5rem !important;
}

/* Navigation Links */
.sidebar .sidebar-nav {
    display: flex;
    flex-direction: column;
    gap: 4px;
}
.sidebar .nav-item,
button.nav-item,
#reportNavToggle,
html[data-theme="light"] .sidebar .nav-item,
[data-theme="light"] .sidebar .nav-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 8.5px 12px !important;
    border-radius: 8px !important;
    font-size: 13.5px !important;
    font-weight: 500 !important;
    color: #cbd5e1 !important;
    text-decoration: none;
    transition: all 0.18s ease !important;
    border: none !important;
    background: transparent !important;
    outline: none !important;
    box-shadow: none !important;
    cursor: pointer;
}
.sidebar .nav-item i,
html[data-theme="light"] .sidebar .nav-item i,
[data-theme="light"] .sidebar .nav-item i {
    font-size: 15px !important;
    width: 20px !important;
    text-align: center !important;
    color: #94a3b8 !important;
    transition: color 0.18s ease, transform 0.18s ease !important;
    flex-shrink: 0;
}

/* Nav Item Focus & Hover */
.sidebar .nav-item:focus,
#reportNavToggle:focus,
.sidebar button.nav-item:focus {
    outline: none !important;
    box-shadow: none !important;
    background: transparent !important;
}
.sidebar .nav-item:hover,
#reportNavToggle:hover,
button.nav-item:hover,
html[data-theme="light"] .sidebar .nav-item:hover,
[data-theme="light"] .sidebar .nav-item:hover {
    background: rgba(255, 255, 255, 0.07) !important;
    color: #ffffff !important;
}
.sidebar .nav-item:hover i,
#reportNavToggle:hover i {
    color: #ffffff !important;
    transform: scale(1.06);
}

/* Active Nav Item - Solid Emerald Pill (Links Only) */
.sidebar a.nav-item.active,
html[data-theme="light"] .sidebar a.nav-item.active,
[data-theme="light"] .sidebar a.nav-item.active {
    background: #10b981 !important;
    color: #022c22 !important;
    font-weight: 700 !important;
    box-shadow: 0 4px 14px rgba(16, 185, 129, 0.28) !important;
}
.sidebar a.nav-item.active i,
html[data-theme="light"] .sidebar a.nav-item.active i,
[data-theme="light"] .sidebar a.nav-item.active i {
    color: #022c22 !important;
    transform: scale(1.05);
}
.sidebar a.nav-item.active:hover {
    background: #10b981 !important;
    color: #022c22 !important;
}
.sidebar a.nav-item.active:hover i {
    color: #022c22 !important;
}

/* Group / Collapsible Nav Toggle Buttons - Transparent Background */
.sidebar button.nav-item,
#reportNavToggle,
#reportNavToggle.active,
#userMgmtNavToggle,
#userMgmtNavToggle.active {
    background: transparent !important;
    color: #cbd5e1 !important;
    font-weight: 500 !important;
    box-shadow: none !important;
}
.sidebar button.nav-item:hover,
#reportNavToggle:hover,
#userMgmtNavToggle:hover {
    background: rgba(255, 255, 255, 0.07) !important;
    color: #ffffff !important;
}
.sidebar button.nav-item i,
#reportNavToggle i,
#userMgmtNavToggle i {
    color: #94a3b8 !important;
}
.sidebar button.nav-item:hover i,
#reportNavToggle:hover i,
#userMgmtNavToggle:hover i {
    color: #ffffff !important;
}
.rotate-180 {
    transform: rotate(180deg) !important;
}

/* ══ Sidebar Stock & Ingredient Alert Badge ══ */
.sidebar-stock-badge {
    margin-left: auto;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 20px;
    height: 20px;
    padding: 0 6px;
    font-size: 11px;
    font-weight: 800;
    line-height: 1;
    border-radius: 9999px;
    flex-shrink: 0;
    transition: all 0.2s ease;
}
.sidebar-stock-badge.badge-danger {
    background: rgba(239, 68, 68, 0.22);
    color: #fca5a5;
    border: 1px solid rgba(239, 68, 68, 0.45);
    box-shadow: 0 0 10px rgba(239, 68, 68, 0.3);
}
.sidebar-stock-badge.badge-warning {
    background: rgba(245, 158, 11, 0.22);
    color: #fde68a;
    border: 1px solid rgba(245, 158, 11, 0.45);
}
.sidebar .nav-item.active .sidebar-stock-badge.badge-danger {
    background: #991b1b;
    color: #ffffff;
    border-color: #ef4444;
    box-shadow: none;
}
.sidebar .nav-item.active .sidebar-stock-badge.badge-warning {
    background: #92400e;
    color: #ffffff;
    border-color: #f59e0b;
    box-shadow: none;
}

/* Collapsed mode: glowing alert dot */
.sidebar.collapsed .sidebar-stock-badge {
    position: absolute;
    top: 8px;
    right: 12px;
    min-width: 8px;
    width: 8px;
    height: 8px;
    padding: 0;
    font-size: 0;
    border-radius: 50%;
}

/* Submenu Styles (Reports / Users) */
.sidebar #reportSubmenu,
.sidebar #userMgmtSubmenu {
    border-left: 2px solid #10b981 !important;
    margin-left: 14px;
    padding-left: 8px;
}
.sidebar #reportSubmenu .nav-item,
.sidebar #userMgmtSubmenu .nav-item {
    font-size: 12.5px !important;
    padding: 6px 10px !important;
}

/* Footer Section */
.sidebar-footer {
    display: flex;
    flex-direction: column;
    gap: 8px;
    padding-top: 14px;
    border-top: 1px solid rgba(255, 255, 255, 0.08);
}

/* ── Smooth Global Theme Transition Effect (Targeted for max performance) ── */
html.theme-transitioning,
html.theme-transitioning body,
html.theme-transitioning .pos-layout,
html.theme-transitioning .menu-panel,
html.theme-transitioning .menu-scroll,
html.theme-transitioning .cat-filter-bar,
html.theme-transitioning .pkg-filter-group,
html.theme-transitioning .product-card,
html.theme-transitioning .cart-panel,
html.theme-transitioning .sidebar,
html.theme-transitioning .main-content,
html.theme-transitioning .card,
html.theme-transitioning header {
    transition: background-color 0.38s cubic-bezier(0.4, 0, 0.2, 1),
                background 0.38s cubic-bezier(0.4, 0, 0.2, 1),
                border-color 0.38s cubic-bezier(0.4, 0, 0.2, 1),
                color 0.3s cubic-bezier(0.4, 0, 0.2, 1),
                box-shadow 0.38s cubic-bezier(0.4, 0, 0.2, 1);
}

/* Theme Segmented Switcher */
.sidebar-theme-switch {
    display: flex;
    align-items: center;
    position: relative;
    background: #090e1a;
    border: 1px solid rgba(255, 255, 255, 0.14) !important;
    border-radius: 9999px !important;
    padding: 3px !important;
    width: 100%;
    box-sizing: border-box;
    box-shadow: inset 0 2px 5px rgba(0, 0, 0, 0.5);
    isolation: isolate;
    overflow: hidden;
    cursor: pointer;
}

/* Sliding active pill indicator (Spring motion) */
.theme-switch-slider {
    position: absolute;
    top: 3px;
    bottom: 3px;
    left: 3px;
    width: calc(50% - 3px);
    border-radius: 9999px;
    background: #141c2e;
    border: 1.5px solid rgba(251, 191, 36, 0.75);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.4), 0 0 16px rgba(251, 191, 36, 0.35);
    transform: translateX(0);
    transition: transform 0.42s cubic-bezier(0.34, 1.56, 0.64, 1),
                border-color 0.35s ease,
                box-shadow 0.35s ease,
                background-color 0.35s ease;
    pointer-events: none;
    z-index: 0;
    will-change: transform;
}

/* When Light mode is active: slider on the LEFT */
[data-theme="light"] .theme-switch-slider,
.sidebar-theme-switch[data-theme-active="light"] .theme-switch-slider {
    transform: translateX(0) !important;
    border-color: rgba(251, 191, 36, 0.75) !important;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.4), 0 0 16px rgba(251, 191, 36, 0.4) !important;
}

/* When Dark mode is active: slider on the RIGHT */
[data-theme="dark"] .theme-switch-slider,
.sidebar-theme-switch[data-theme-active="dark"] .theme-switch-slider,
html:not([data-theme="light"]) .theme-switch-slider {
    transform: translateX(100%) !important;
    border-color: rgba(56, 189, 248, 0.65) !important;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.4), 0 0 16px rgba(56, 189, 248, 0.35) !important;
}

.theme-switch-btn {
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    padding: 7px 12px !important;
    border-radius: 9999px !important;
    background: transparent !important;
    border: none !important;
    color: #94a3b8 !important;
    font-size: 13px !important;
    font-weight: 600 !important;
    cursor: pointer;
    transition: color 0.3s ease, font-weight 0.3s ease !important;
    user-select: none;
    -webkit-user-select: none;
    position: relative;
    z-index: 1;
}

.theme-switch-btn:hover {
    color: #f1f5f9 !important;
}

.theme-switch-btn .theme-switch-icon {
    font-size: 14px !important;
    transition: transform 0.42s cubic-bezier(0.34, 1.56, 0.64, 1),
                color 0.35s ease,
                filter 0.35s ease !important;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}

/* Active Segment Text States */
.theme-switch-btn.theme-switch-light.active {
    color: #fbbf24 !important;
    font-weight: 700 !important;
}

.theme-switch-btn.theme-switch-light.active .theme-switch-icon {
    color: #fbbf24 !important;
    transform: rotate(45deg) scale(1.15);
    filter: drop-shadow(0 0 6px rgba(251, 191, 36, 0.6));
    animation: sunPop 0.45s cubic-bezier(0.34, 1.56, 0.64, 1);
}

.theme-switch-btn.theme-switch-dark.active {
    color: #38bdf8 !important;
    font-weight: 700 !important;
}

.theme-switch-btn.theme-switch-dark.active .theme-switch-icon {
    color: #38bdf8 !important;
    transform: rotate(-15deg) scale(1.15);
    filter: drop-shadow(0 0 6px rgba(56, 189, 248, 0.6));
    animation: moonPop 0.45s cubic-bezier(0.34, 1.56, 0.64, 1);
}

@keyframes sunPop {
    0% { transform: rotate(0deg) scale(0.8); }
    50% { transform: rotate(70deg) scale(1.25); }
    100% { transform: rotate(45deg) scale(1.15); }
}

@keyframes moonPop {
    0% { transform: rotate(0deg) scale(0.8); }
    50% { transform: rotate(-30deg) scale(1.25); }
    100% { transform: rotate(-15deg) scale(1.15); }
}

@keyframes sunPop {
    0% { transform: rotate(0deg) scale(0.8); }
    50% { transform: rotate(70deg) scale(1.25); }
    100% { transform: rotate(45deg) scale(1.15); }
}

@keyframes moonPop {
    0% { transform: rotate(0deg) scale(0.8); }
    50% { transform: rotate(-30deg) scale(1.25); }
    100% { transform: rotate(-15deg) scale(1.15); }
}

/* ══ Language Selector - Neumorphic Soft Depth ══ */
.sidebar-lang-card {
    position: relative;
    width: 100%;
    box-sizing: border-box;
}
.sidebar-lang-btn {
    display: flex;
    align-items: center;
    justify-content: space-between;
    width: 100%;
    padding: 8.5px 13px !important;
    background: #080e1c !important;
    border: 1px solid rgba(255, 255, 255, 0.05) !important;
    border-radius: 9999px !important;
    text-decoration: none;
    cursor: pointer;
    box-sizing: border-box;
    /* Neumorphic Soft Depth Inset & Highlight Shadows */
    box-shadow: inset 0 3px 6px rgba(0, 0, 0, 0.78),
                inset 0 1px 2px rgba(0, 0, 0, 0.95),
                inset 0 -1px 2px rgba(255, 255, 255, 0.06),
                0 1px 1px rgba(255, 255, 255, 0.03) !important;
    transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1) !important;
    user-select: none;
    -webkit-user-select: none;
    position: relative;
    overflow: hidden;
}
.sidebar-lang-btn:hover {
    background: #0a1224 !important;
    border-color: rgba(16, 185, 129, 0.3) !important;
    box-shadow: inset 0 2px 5px rgba(0, 0, 0, 0.65),
                inset 0 -1px 2px rgba(255, 255, 255, 0.08),
                0 0 16px rgba(16, 185, 129, 0.18),
                0 1px 2px rgba(255, 255, 255, 0.05) !important;
}
.sidebar-lang-btn:active {
    transform: scale(0.985);
    box-shadow: inset 0 4px 8px rgba(0, 0, 0, 0.9),
                inset 0 -1px 1px rgba(255, 255, 255, 0.04) !important;
}
.sidebar-lang-main {
    display: flex;
    align-items: center;
    gap: 10px;
    min-width: 0;
}
.sidebar-lang-flag {
    font-size: 18px;
    line-height: 1;
    display: inline-flex;
    align-items: center;
    flex-shrink: 0;
}
.sidebar-lang-flag-svg {
    border-radius: 3px !important;
    overflow: hidden;
    flex-shrink: 0;
    display: block;
    box-shadow: 0 1px 4px rgba(0, 0, 0, 0.5) !important;
}
.sidebar-lang-name {
    font-size: 13.5px !important;
    font-weight: 700 !important;
    color: #ffffff !important;
    letter-spacing: 0.01em;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    text-shadow: 0 1px 2px rgba(0, 0, 0, 0.6);
}
.sidebar-lang-right {
    display: flex;
    align-items: center;
    gap: 7px;
    flex-shrink: 0;
}
.sidebar-lang-badge {
    background: #043e2e !important;
    color: #10b981 !important;
    border: 1px solid rgba(16, 185, 129, 0.38) !important;
    font-size: 11px !important;
    font-weight: 800 !important;
    padding: 2.5px 8px !important;
    border-radius: 9999px !important;
    letter-spacing: 0.5px;
    line-height: 1.2;
    box-shadow: inset 0 1px 2px rgba(0, 0, 0, 0.35), 0 0 8px rgba(16, 185, 129, 0.2) !important;
    transition: all 0.2s ease;
}
.sidebar-lang-btn:hover .sidebar-lang-badge {
    background: #064e3b !important;
    color: #34d399 !important;
    border-color: rgba(52, 211, 153, 0.5) !important;
    box-shadow: inset 0 1px 2px rgba(0, 0, 0, 0.35), 0 0 12px rgba(16, 185, 129, 0.4) !important;
}
.sidebar-lang-chevron {
    color: #cbd5e1 !important;
    font-size: 10.5px !important;
    transition: transform 0.25s cubic-bezier(0.34, 1.56, 0.64, 1), color 0.2s ease !important;
}
.sidebar-lang-btn:hover .sidebar-lang-chevron {
    color: #ffffff !important;
    transform: translateY(1.5px);
}

/* User Profile & Logout Card */
.sidebar-user-card {
    display: flex;
    align-items: center;
    justify-content: space-between;
    width: 100%;
    padding: 8px 12px;
    background: rgba(15, 23, 42, 0.4);
    border: 1px solid rgba(255, 255, 255, 0.08);
    border-radius: 10px;
    box-sizing: border-box;
    transition: all 0.2s ease;
}
.sidebar-user-name {
    font-size: 13.5px !important;
    font-weight: 700 !important;
    color: #ffffff !important;
    text-decoration: none;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    transition: color 0.2s ease;
}
.sidebar-user-name:hover {
    color: #10b981 !important;
}
.sidebar-logout-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    color: #94a3b8 !important;
    font-size: 14px !important;
    text-decoration: none;
    cursor: pointer;
    transition: all 0.2s ease;
    padding: 2px 4px;
    border-radius: 4px;
}
.sidebar-logout-btn:hover {
    color: #ef4444 !important;
    transform: translateX(2px);
}

/* ── LOGOUT MODAL (EMERALD DARK POS THEME) ── */
.logout-modal-overlay {
    position: fixed;
    inset: 0;
    z-index: 999999;
    background: rgba(3, 8, 6, 0.85);
    backdrop-filter: blur(12px);
    -webkit-backdrop-filter: blur(12px);
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.25s cubic-bezier(0.16, 1, 0.3, 1);
}

.logout-modal-overlay.active {
    opacity: 1;
    pointer-events: auto;
}

.logout-modal-card {
    width: 100%;
    max-width: 440px;
    background: #091511;
    border: 1px solid rgba(0, 245, 160, 0.25);
    border-radius: 28px;
    padding: 36px 32px 30px;
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
    position: relative;
    box-shadow:
        0 0 50px -10px rgba(0, 245, 160, 0.22),
        0 30px 60px -15px rgba(0, 0, 0, 0.9),
        inset 0 1px 1px rgba(255, 255, 255, 0.08);
    transform: scale(0.93) translateY(15px);
    transition: transform 0.3s cubic-bezier(0.16, 1, 0.3, 1);
    font-family: 'Kantumruy Pro', 'Noto Sans Khmer', 'Plus Jakarta Sans', sans-serif;
}

.logout-modal-overlay.active .logout-modal-card {
    transform: scale(1) translateY(0);
}

.logout-icon-box {
    width: 72px;
    height: 72px;
    border-radius: 22px;
    background: rgba(0, 245, 160, 0.06);
    border: 1.5px solid rgba(0, 245, 160, 0.35);
    display: flex;
    align-items: center;
    justify-content: center;
    color: #00f5a0;
    box-shadow: 0 0 25px rgba(0, 245, 160, 0.18), inset 0 0 12px rgba(0, 245, 160, 0.08);
    margin-bottom: 24px;
    flex-shrink: 0;
}

.logout-icon-box svg {
    color: #00f5a0;
    filter: drop-shadow(0 0 6px rgba(0, 245, 160, 0.45));
}

.logout-modal-title {
    font-size: 23px;
    font-weight: 800;
    color: #ffffff;
    letter-spacing: -0.01em;
    margin-bottom: 8px;
    line-height: 1.3;
}

.logout-modal-sub {
    font-size: 13.5px;
    color: #708b82;
    line-height: 1.5;
    margin-bottom: 20px;
    font-weight: 400;
}

.logout-highlight-user {
    color: #00f5a0;
    font-weight: 700;
}

.logout-notice-pill {
    width: 100%;
    background: rgba(6, 17, 13, 0.85);
    border: 1px solid rgba(0, 245, 160, 0.2);
    border-radius: 14px;
    padding: 12px 16px;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 9px;
    font-size: 12.5px;
    font-weight: 600;
    color: #9cb1aa;
    margin-bottom: 24px;
    box-shadow: inset 0 1px 1px rgba(255, 255, 255, 0.04);
}

.notice-shield-icon {
    color: #f59e0b;
    filter: drop-shadow(0 0 5px rgba(245, 158, 11, 0.4));
    flex-shrink: 0;
}

.logout-actions {
    display: flex;
    align-items: center;
    gap: 14px;
    width: 100%;
}

.logout-btn-cancel {
    flex: 1;
    height: 48px;
    border-radius: 14px;
    background: rgba(255, 255, 255, 0.05);
    border: 1px solid rgba(255, 255, 255, 0.1);
    color: #ffffff;
    font-size: 14px;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.2s ease;
    display: flex;
    align-items: center;
    justify-content: center;
    font-family: inherit;
}

.logout-btn-cancel:hover {
    background: rgba(255, 255, 255, 0.1);
    border-color: rgba(255, 255, 255, 0.22);
    transform: translateY(-1px);
}

.logout-btn-confirm {
    flex: 1;
    height: 48px;
    border-radius: 14px;
    background: linear-gradient(135deg, #00f5a0 0%, #00d486 100%);
    color: #041f15 !important;
    font-size: 14.5px;
    font-weight: 800;
    border: none;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    box-shadow: 0 4px 20px rgba(0, 245, 160, 0.35);
    transition: all 0.2s ease;
    text-decoration: none;
    font-family: inherit;
}

.logout-btn-confirm:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 28px rgba(0, 245, 160, 0.55);
    filter: brightness(1.05);
    color: #041f15 !important;
}

/* Collapsed State */
.sidebar.collapsed {
    --sidebar-w: 72px;
    width: 72px !important;
    min-width: 72px !important;
    max-width: 72px !important;
    padding: 1rem 0.4rem !important;
}
.sidebar.collapsed .sidebar-brand-block,
.sidebar.collapsed .sidebar-section-title,
.sidebar.collapsed .nav-label,
.sidebar.collapsed .theme-switch-label,
.sidebar.collapsed .sidebar-lang-name,
.sidebar.collapsed .sidebar-lang-right,
.sidebar.collapsed .sidebar-user-name,
.sidebar.collapsed .nav-chevron,
.sidebar.collapsed #reportSubmenu,
.sidebar.collapsed #userMgmtSubmenu {
    display: none !important;
}
.sidebar.collapsed .sidebar-theme-switch {
    flex-direction: column !important;
    padding: 2px !important;
    gap: 2px !important;
}
.sidebar.collapsed .theme-switch-btn {
    padding: 6px 0 !important;
    justify-content: center !important;
}
.sidebar.collapsed .sidebar-lang-btn {
    justify-content: center !important;
    padding: 8px 4px !important;
}
.sidebar.collapsed .sidebar-user-card {
    justify-content: center !important;
    padding: 8px 4px !important;
}
.sidebar.collapsed .nav-item {
    justify-content: center !important;
    padding: 10px 0 !important;
}

/* App Main Background for Light Mode */
.app-main, .main-content-container {
    margin-left: 0 !important;
    flex: 1;
    min-width: 0;
    height: 100vh;
    overflow-y: auto;
    box-sizing: border-box;
}
html[data-theme="light"] .app-main,
[data-theme="light"] .app-main,
html[data-theme="light"] .main-content-container,
[data-theme="light"] .main-content-container {
    background-color: #ffffff !important;
}

/* ══ Mobile Responsive Drawer Overlay ══ */
.sidebar-overlay {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.6);
    backdrop-filter: blur(4px);
    z-index: 99998;
    opacity: 0;
    transition: opacity 0.25s ease;
}
.sidebar-overlay.active {
    display: block;
    opacity: 1;
}

@media (max-width: 768px) {
    .sidebar {
        position: fixed !important;
        top: 0 !important;
        left: 0 !important;
        bottom: 0 !important;
        width: 256px !important;
        min-width: 256px !important;
        max-width: 256px !important;
        z-index: 99999 !important;
        height: 100vh !important;
        height: 100dvh !important;
        box-shadow: 8px 0 32px rgba(0, 0, 0, 0.75) !important;
        transform: translateX(0);
        transition: transform 0.25s cubic-bezier(0.4, 0, 0.2, 1), width 0.22s ease !important;
    }
    .sidebar.collapsed {
        transform: translateX(-100%) !important;
    }
}

/* ═══════════════════════════════════════════════════════════════════
   PREMIUM GLOBAL TOAST & ALERT NOTIFICATION SYSTEM
   ═══════════════════════════════════════════════════════════════════ */
#toast-container {
    position: fixed !important;
    top: 20px !important;
    left: 50% !important;
    transform: translateX(-50%) !important;
    z-index: 2147483647 !important;
    display: flex !important;
    flex-direction: column !important;
    align-items: center !important;
    gap: 8px !important;
    pointer-events: none !important;
    width: auto !important;
    max-width: calc(100vw - 32px) !important;
}

.toast {
    pointer-events: auto !important;
    display: inline-flex !important;
    align-items: center !important;
    gap: 10px !important;
    padding: 9px 14px 9px 10px !important;
    border-radius: 9999px !important;
    font-family: 'Poppins', 'Kantumruy Pro', sans-serif !important;
    font-size: 13px !important;
    font-weight: 600 !important;
    letter-spacing: 0.01em !important;
    white-space: nowrap !important;
    -webkit-backdrop-filter: blur(20px) !important;
    backdrop-filter: blur(20px) !important;
    background: rgba(18, 18, 24, 0.94) !important;
    color: #f9fafb !important;
    border: 1px solid rgba(255, 255, 255, 0.1) !important;
    box-shadow: 0 16px 40px -4px rgba(0, 0, 0, 0.65), 0 4px 14px rgba(0, 0, 0, 0.35), inset 0 1px 0 rgba(255, 255, 255, 0.08) !important;
    transform: translateY(-24px) scale(0.92) !important;
    opacity: 0 !important;
    transition: transform 0.36s cubic-bezier(0.34, 1.56, 0.64, 1), opacity 0.22s ease !important;
    -webkit-user-select: none !important;
    user-select: none !important;
    position: relative !important;
    overflow: hidden !important;
}

.toast.show {
    transform: translateY(0) scale(1) !important;
    opacity: 1 !important;
}

.toast.hide {
    transform: translateY(-16px) scale(0.92) !important;
    opacity: 0 !important;
    transition: transform 0.22s ease-in, opacity 0.22s ease-in !important;
}

[data-theme="light"] .toast,
html[data-theme="light"] .toast {
    background: rgba(255, 255, 255, 0.96) !important;
    color: #111827 !important;
    border: 1px solid rgba(229, 231, 235, 0.95) !important;
    box-shadow: 0 14px 34px -4px rgba(0, 0, 0, 0.12), 0 4px 12px rgba(0, 0, 0, 0.04), inset 0 1px 0 rgba(255, 255, 255, 1) !important;
}

.toast-icon-badge {
    width: 26px !important;
    height: 26px !important;
    border-radius: 50% !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    flex-shrink: 0 !important;
    font-size: 12px !important;
}

.toast.success .toast-icon-badge {
    background: linear-gradient(135deg, rgba(16, 185, 129, 0.2), rgba(5, 150, 105, 0.25)) !important;
    color: #10b981 !important;
    border: 1px solid rgba(16, 185, 129, 0.35) !important;
    box-shadow: 0 0 10px rgba(16, 185, 129, 0.2) !important;
}

.toast.warning .toast-icon-badge {
    background: linear-gradient(135deg, rgba(245, 158, 11, 0.2), rgba(217, 119, 6, 0.25)) !important;
    color: #f59e0b !important;
    border: 1px solid rgba(245, 158, 11, 0.35) !important;
    box-shadow: 0 0 10px rgba(245, 158, 11, 0.2) !important;
}

.toast.error .toast-icon-badge {
    background: linear-gradient(135deg, rgba(239, 68, 68, 0.2), rgba(220, 38, 38, 0.25)) !important;
    color: #ef4444 !important;
    border: 1px solid rgba(239, 68, 68, 0.35) !important;
    box-shadow: 0 0 10px rgba(239, 68, 68, 0.2) !important;
}

.toast.info .toast-icon-badge {
    background: linear-gradient(135deg, rgba(59, 130, 246, 0.2), rgba(37, 99, 235, 0.25)) !important;
    color: #3b82f6 !important;
    border: 1px solid rgba(59, 130, 246, 0.35) !important;
    box-shadow: 0 0 10px rgba(59, 130, 246, 0.2) !important;
}

.toast-msg {
    flex: 1 !important;
    line-height: 1.4 !important;
    padding-right: 2px !important;
}

.toast-dismiss {
    width: 20px !important;
    height: 20px !important;
    border-radius: 50% !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    background: transparent !important;
    border: none !important;
    color: #9ca3af !important;
    font-size: 10.5px !important;
    cursor: pointer !important;
    transition: all 0.15s ease !important;
    padding: 0 !important;
    margin-left: 2px !important;
    flex-shrink: 0 !important;
}

.toast-dismiss:hover {
    background: rgba(255, 255, 255, 0.12) !important;
    color: #ffffff !important;
}

[data-theme="light"] .toast-dismiss:hover,
html[data-theme="light"] .toast-dismiss:hover {
    background: rgba(0, 0, 0, 0.08) !important;
    color: #111827 !important;
}

.toast-progress {
    position: absolute !important;
    bottom: 0 !important;
    left: 14px !important;
    right: 14px !important;
    height: 2px !important;
    border-radius: 99px !important;
    background: rgba(255, 255, 255, 0.1) !important;
    overflow: hidden !important;
}

.toast-progress-bar {
    height: 100% !important;
    width: 100% !important;
    transform-origin: left !important;
    animation: toastProgress 2.8s linear forwards !important;
}

.toast.success .toast-progress-bar { background: #10b981 !important; }
.toast.warning .toast-progress-bar { background: #f59e0b !important; }
.toast.error .toast-progress-bar   { background: #ef4444 !important; }
.toast.info .toast-progress-bar    { background: #3b82f6 !important; }

@keyframes toastProgress {
    from { transform: scaleX(1); }
    to   { transform: scaleX(0); }
}
</style>

<script>
// Immediate inline execution before render to prevent flash animation
(function() {
    if (window.innerWidth <= 768) {
        window.__isSidebarCollapsed = true;
    } else {
        window.__isSidebarCollapsed = (localStorage.getItem('sidebar_collapsed') === 'true');
    }
})();
</script>

<div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

<aside class="sidebar sidebar-no-anim" id="sidebar">
    <script>
    if (window.__isSidebarCollapsed) {
        document.getElementById('sidebar').classList.add('collapsed');
        if (window.innerWidth > 768) {
            document.documentElement.classList.add('sidebar-collapsed');
        }
    }
    </script>
    <div class="flex flex-col gap-1">
        <!-- Brand Header Section -->
        <div class="sidebar-brand-block">
            <div class="sidebar-brand-info">
                <div class="sidebar-brand-subtitle">POINT OF SALE</div>
                <div class="sidebar-brand-title">BIRD'S NEST</div>
            </div>
            <div class="sidebar-brand-cartoon">
                <img src="images/sidebar-cartoon.webp" alt="Bird's Nest Barista" />
            </div>
        </div>

        <!-- Section Label: MENU -->
        <div class="sidebar-section-title">MENU</div>

        <!-- Vertical Stacked Nav Links -->
        <nav class="sidebar-nav" id="sidebarNav">

            <?php if (can('take_order')): ?>
            <a class="nav-item<?= $_cur_page === 'menu.php' ? ' active' : '' ?>" href="menu.php">
                <i class="fa-solid fa-bag-shopping"></i>
                <span class="nav-label"><?= __('nav_take_order', 'បង្កើតការកុម្ម៉ង់') ?></span>
            </a>
            <?php endif; ?>

            <?php if (can('dashboard')): ?>
            <a class="nav-item<?= $_cur_page === 'dashboard.php' ? ' active' : '' ?>" href="dashboard.php">
                <i class="fa-solid fa-table-cells-large"></i>
                <span class="nav-label"><?= __('nav_dashboard', 'ផ្ទាំងបញ្ជា') ?></span>
            </a>
            <?php endif; ?>

            <?php if (can('view_orders')): ?>
            <a class="nav-item<?= $_cur_page === 'view_order.php' ? ' active' : '' ?>" href="view_order.php">
                <i class="fa-solid fa-receipt"></i>
                <span class="nav-label"><?= __('nav_orders', 'បញ្ជីការកុម្ម៉ង់') ?></span>
            </a>
            <?php endif; ?>

            <?php if (can('products')): ?>
            <a class="nav-item<?= in_array($_cur_page, ['products.php', 'edit_product.php', 'add_product.php']) ? ' active' : '' ?>" href="products.php">
                <i class="fa-solid fa-cube"></i>
                <span class="nav-label"><?= __('nav_products', 'ទំនិញ') ?></span>
            </a>
            <?php endif; ?>

            <?php if (can('products') || can('inventory') || in_array($_SESSION['role'] ?? '', ['admin', 'manager', 'staff'])): ?>
            <a id="sidebarNavStockDrink" class="nav-item relative<?= in_array($_cur_page, ['stock.php', 'stock_count.php']) ? ' active' : '' ?>" href="stock.php">
                <i class="fa-solid fa-wine-bottle"></i>
                <span class="nav-label"><?= __('nav_stock_drinks', 'ស្តុកភេសជ្ជៈ') ?></span>
                <span id="sidebarStockDrinkBadge" class="sidebar-stock-badge <?= $_stock_drink_has_out ? 'badge-danger' : 'badge-warning' ?>" style="<?= $_stock_drink_alerts > 0 ? '' : 'display:none;' ?>" title="<?= $_stock_drink_alerts ?> <?= __('stock_alert', 'Low / Out of Stock') ?>">
                    <?= $_stock_drink_alerts ?>
                </span>
            </a>
            <a id="sidebarNavIngredient" class="nav-item relative<?= $_cur_page === 'ingredients.php' ? ' active' : '' ?>" href="ingredients.php">
                <i class="fa-solid fa-seedling"></i>
                <span class="nav-label"><?= __('nav_raw_ingredients', 'គ្រឿងផ្សំ') ?></span>
                <span id="sidebarIngredientBadge" class="sidebar-stock-badge <?= $_ingredient_has_out ? 'badge-danger' : 'badge-warning' ?>" style="<?= $_ingredient_alerts > 0 ? '' : 'display:none;' ?>" title="<?= $_ingredient_alerts ?> <?= __('stock_alert', 'Low / Out of Stock') ?>">
                    <?= $_ingredient_alerts ?>
                </span>
            </a>
            <?php endif; ?>

            <?php if (can('manage_categories')): ?>
            <a class="nav-item<?= $_cur_page === 'manage_categories.php' ? ' active' : '' ?>" href="manage_categories.php">
                <i class="fa-solid fa-tags"></i>
                <span class="nav-label"><?= __('nav_categories', 'ប្រភេទទំនិញ') ?></span>
            </a>
            <?php endif; ?>

            <?php if (can('daily_report') || can('sales_report') || can('shift_report')): ?>
            <!-- Reports Collapsible Group -->
            <div>
                <button type="button" 
                        id="reportNavToggle" 
                        onclick="toggleReportSubmenu(event)" 
                        class="nav-item w-full justify-between cursor-pointer">
                    <div class="flex items-center gap-3 min-w-0">
                        <i class="fa-solid fa-chart-simple"></i>
                        <span class="nav-label truncate"><?= __('nav_reports', 'របាយការណ៍') ?></span>
                    </div>
                    <i class="fa-solid fa-chevron-down text-xs transition-transform duration-200 nav-chevron" id="reportChevron"></i>
                </button>
                <div class="<?= in_array($_cur_page, ['daily_report.php', 'report.php', 'shift_report.php']) ? 'flex' : 'hidden' ?> flex-col gap-1 mt-1" id="reportSubmenu">
                    <?php if (can('daily_report')): ?>
                    <a class="nav-item<?= $_cur_page === 'daily_report.php' ? ' active' : '' ?>" href="daily_report.php">
                        <i class="fa-solid fa-calendar-day"></i>
                        <span class="nav-label"><?= __('nav_daily_report', 'Daily Summary') ?></span>
                    </a>
                    <?php endif; ?>
                    <?php if (can('sales_report')): ?>
                    <a class="nav-item<?= $_cur_page === 'report.php' ? ' active' : '' ?>" href="report.php">
                        <i class="fa-solid fa-chart-line"></i>
                        <span class="nav-label"><?= __('nav_sales_report', 'Analytics & Export') ?></span>
                    </a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if (can('settings')): ?>
            <a class="nav-item<?= $_cur_page === 'settings.php' ? ' active' : '' ?>" href="settings.php">
                <i class="fa-solid fa-gear"></i>
                <span class="nav-label"><?= __('nav_settings', 'ការកំណត់') ?></span>
            </a>
            <?php endif; ?>

            <a class="nav-item<?= $_cur_page === 'users.php' ? ' active' : '' ?>" href="users.php">
                <i class="fa-solid fa-users-gear"></i>
                <span class="nav-label"><?= __('nav_users', 'អ្នកប្រើប្រាស់') ?></span>
            </a>

        </nav>
    </div>

    <!-- Sidebar Footer -->
    <div class="sidebar-footer">
        <!-- Theme Mode Switcher -->
        <div class="sidebar-theme-switch" id="sidebarThemeSwitch">
            <div class="theme-switch-slider" id="themeSwitchSlider"></div>
            <button type="button" class="theme-switch-btn theme-switch-light" id="themeBtnLight" onclick="setAppTheme('light')" title="<?= current_lang() === 'km' ? 'ប្តូរទៅពន្លឺ (Light Mode)' : 'Switch to Light Mode' ?>">
                <i class="fa-solid fa-sun theme-switch-icon"></i>
                <span class="theme-switch-label"><?= current_lang() === 'km' ? 'ពន្លឺ' : 'Light' ?></span>
            </button>
            <button type="button" class="theme-switch-btn theme-switch-dark active" id="themeBtnDark" onclick="setAppTheme('dark')" title="<?= current_lang() === 'km' ? 'ប្តូរទៅងងឹត (Dark Mode)' : 'Switch to Dark Mode' ?>">
                <i class="fa-solid fa-moon theme-switch-icon"></i>
                <span class="theme-switch-label"><?= current_lang() === 'km' ? 'ងងឹត' : 'Dark' ?></span>
            </button>
        </div>

        <!-- Language Switcher Card -->
        <div class="sidebar-lang-card">
            <a href="set_language.php?lang=<?= current_lang() === 'en' ? 'km' : 'en' ?>" class="sidebar-lang-btn" title="<?= current_lang() === 'en' ? 'Switch to ភាសាខ្មែរ' : 'Switch to English' ?>">
                <div class="sidebar-lang-main">
                    <span class="sidebar-lang-flag">
                        <?php if (current_lang() === 'km'): ?>
                        <svg class="sidebar-lang-flag-svg" viewBox="0 0 640 480" width="22" height="15" style="border-radius: 3px; overflow: hidden; flex-shrink: 0; display: block; box-shadow: 0 1px 3px rgba(0,0,0,0.35);">
                            <path fill="#032ea1" d="M0 0h640v480H0z"/>
                            <path fill="#e00025" d="M0 120h640v240H0z"/>
                            <g fill="#ffffff">
                                <path d="M320 160l18 64h-36zm-58 32l16 52h-32zm116 0l16 52h-32z"/>
                                <path d="M236 244h168v16H236zm14 16h140v16H250zm14 16h112v16H264zm14 16h84v14H278z"/>
                                <path d="M312 200h16v44h-16zm-58 30h16v14h-16zm116 0h16v14h-16z"/>
                            </g>
                        </svg>
                        <?php else: ?>
                        <svg class="sidebar-lang-flag-svg" viewBox="0 0 60 30" width="22" height="15" style="border-radius: 3px; overflow: hidden; flex-shrink: 0; display: block; box-shadow: 0 1px 3px rgba(0,0,0,0.35);">
                            <clipPath id="uk-flag-clip"><path d="M0 0v30h60V0z"/></clipPath>
                            <g clip-path="url(#uk-flag-clip)">
                                <path d="M0 0v30h60V0z" fill="#012169"/>
                                <path d="M0 0l60 30m0-30L0 30" stroke="#ffffff" stroke-width="6"/>
                                <path d="M0 0l60 30m0-30L0 30" stroke="#c8102e" stroke-width="4"/>
                                <path d="M30 0v30M0 15h60" stroke="#ffffff" stroke-width="10"/>
                                <path d="M30 0v30M0 15h60" stroke="#c8102e" stroke-width="6"/>
                            </g>
                        </svg>
                        <?php endif; ?>
                    </span>
                    <span class="sidebar-lang-name"><?= current_lang() === 'km' ? 'ភាសាខ្មែរ' : 'English' ?></span>
                </div>
                <div class="sidebar-lang-right">
                    <span class="sidebar-lang-badge"><?= current_lang() === 'km' ? 'KH' : 'EN' ?></span>
                    <i class="fa-solid fa-chevron-down sidebar-lang-chevron"></i>
                </div>
            </a>
        </div>

        <!-- User Profile & Logout Card -->
        <div class="sidebar-user-card">
            <a href="profile.php" class="sidebar-user-name" title="View Profile">
                <?= htmlspecialchars($_username) ?>
            </a>
            <a class="sidebar-logout-btn" href="logout.php" onclick="openLogoutModal(event)" title="Logout">
                <i class="fa-solid fa-arrow-right-from-bracket"></i>
            </a>
        </div>
    </div>
</aside>

<!-- Logout Confirmation Modal (Emerald Dark POS Theme) -->
<div id="logoutModalOverlay" class="logout-modal-overlay" aria-hidden="true" role="dialog" aria-modal="true">
    <div class="logout-modal-card">
        <!-- Icon Box -->
        <div class="logout-icon-box">
            <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
                <polyline points="16 17 21 12 16 7"></polyline>
                <line x1="21" y1="12" x2="9" y2="12"></line>
            </svg>
        </div>

        <!-- Title & Subtitle -->
        <h2 class="logout-modal-title">ចាកចេញពីប្រព័ន្ធ?</h2>
        <p class="logout-modal-sub">
            តើអ្នកពិតជាចង់ចាកចេញពីគណនី <strong class="logout-highlight-user"><?= htmlspecialchars($_username ?? 'Root') ?></strong> មែនទេ?
        </p>

        <!-- Security / Data Saved Notice Pill -->
        <div class="logout-notice-pill">
            <svg class="notice-shield-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path>
                <line x1="12" y1="8" x2="12" y2="12"></line>
                <line x1="12" y1="16" x2="12.01" y2="16"></line>
            </svg>
            <span>ទិន្នន័យការលក់ទាំងអស់ត្រូវបានរក្សាទុក</span>
        </div>

        <!-- Action Buttons -->
        <div class="logout-actions">
            <button type="button" class="logout-btn-cancel" onclick="closeLogoutModal()">
                បោះបង់ (Cancel)
            </button>
            <a href="logout.php" class="logout-btn-confirm" id="confirmLogoutLink">
                <span>ចាកចេញ</span>
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="5" y1="12" x2="19" y2="12"></line>
                    <polyline points="12 5 19 12 12 19"></polyline>
                </svg>
            </a>
        </div>
    </div>
</div>

<script>
function closeSidebar() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    if (!sidebar) return;
    sidebar.classList.remove('sidebar-no-anim');
    sidebar.classList.add('collapsed');
    if (window.innerWidth > 768) {
        document.documentElement.classList.add('sidebar-collapsed');
        localStorage.setItem('sidebar_collapsed', 'true');
    } else {
        document.documentElement.classList.remove('sidebar-collapsed');
    }

    if (overlay) {
        overlay.classList.remove('active');
    }

    const toggleIcon = document.getElementById('sidebarToggleIcon');
    if (toggleIcon) {
        toggleIcon.classList.remove('fa-angles-left');
        toggleIcon.classList.add('fa-angles-right');
    }
}

function openSidebar() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    if (!sidebar) return;
    sidebar.classList.remove('sidebar-no-anim');
    sidebar.classList.remove('collapsed');
    if (window.innerWidth > 768) {
        document.documentElement.classList.remove('sidebar-collapsed');
        localStorage.setItem('sidebar_collapsed', 'false');
    } else {
        document.documentElement.classList.remove('sidebar-collapsed');
    }

    if (overlay && window.innerWidth <= 768) {
        overlay.classList.add('active');
    }

    const toggleIcon = document.getElementById('sidebarToggleIcon');
    if (toggleIcon) {
        toggleIcon.classList.remove('fa-angles-right');
        toggleIcon.classList.add('fa-angles-left');
    }
}

function toggleSidebar() {
    const sidebar = document.getElementById('sidebar');
    if (!sidebar) return;
    if (sidebar.classList.contains('collapsed')) {
        openSidebar();
    } else {
        closeSidebar();
    }
}

window.closeSidebar = closeSidebar;
window.openSidebar = openSidebar;
window.toggleSidebar = toggleSidebar;

// Enable smooth transitions only after initial render completes
document.addEventListener('DOMContentLoaded', () => {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');

    if (sidebar) {
        setTimeout(() => {
            sidebar.classList.remove('sidebar-no-anim');
        }, 50);
    }
    const toggleIcon = document.getElementById('sidebarToggleIcon');
    if (toggleIcon) {
        const isCollapsed = sidebar ? sidebar.classList.contains('collapsed') : false;
        if (isCollapsed) {
            toggleIcon.classList.remove('fa-angles-left');
            toggleIcon.classList.add('fa-angles-right');
        } else {
            toggleIcon.classList.remove('fa-angles-right');
            toggleIcon.classList.add('fa-angles-left');
        }
    }
});

function toggleReportSubmenu(e) {
    if (e) {
        e.preventDefault();
        e.stopPropagation();
    }
    const menu = document.getElementById('reportSubmenu');
    const chevron = document.getElementById('reportChevron');
    if (!menu || !chevron) return;

    const isHidden = menu.classList.contains('hidden');
    if (isHidden) {
        menu.classList.remove('hidden');
        chevron.classList.add('rotate-180');
    } else {
        menu.classList.add('hidden');
        chevron.classList.remove('rotate-180');
    }
}

function toggleUserMgmtSubmenu(e) {
    if (e) {
        e.preventDefault();
        e.stopPropagation();
    }
    const menu = document.getElementById('userMgmtSubmenu');
    const chevron = document.getElementById('userMgmtChevron');
    if (!menu || !chevron) return;

    const isHidden = menu.classList.contains('hidden');
    if (isHidden) {
        menu.classList.remove('hidden');
        chevron.classList.add('rotate-180');
    } else {
        menu.classList.add('hidden');
        chevron.classList.remove('rotate-180');
    }
}

document.addEventListener('DOMContentLoaded', () => {
    const sidebar = document.getElementById('sidebar');
    if (!sidebar) return;

    function setActiveNavTab(targetHref) {
        const page = targetHref ? targetHref.split('?')[0].split('/').pop() : (window.location.pathname.split('/').pop() || 'dashboard.php');
        sidebar.querySelectorAll('a.nav-item').forEach(el => {
            const itemHref = (el.getAttribute('href') || '').split('?')[0].split('/').pop();
            if (itemHref && itemHref === page) {
                el.classList.add('active');
            } else {
                el.classList.remove('active');
            }
        });

        const reportPages = ['daily_report.php', 'report.php', 'shift_report.php'];
        const reportSubmenu = document.getElementById('reportSubmenu');
        const reportChevron = document.getElementById('reportChevron');
        if (reportPages.includes(page)) {
            if (reportSubmenu) reportSubmenu.classList.remove('hidden');
            if (reportChevron) reportChevron.classList.add('rotate-180');
        }

        const userMgmtPages = ['employees.php', 'employee_add.php', 'manage_admin.php', 'manage_roles.php'];
        const userMgmtSubmenu = document.getElementById('userMgmtSubmenu');
        const userMgmtChevron = document.getElementById('userMgmtChevron');
        if (userMgmtPages.includes(page)) {
            if (userMgmtSubmenu) userMgmtSubmenu.classList.remove('hidden');
            if (userMgmtChevron) userMgmtChevron.classList.add('rotate-180');
        }
    }

    window.setActiveNavTab = setActiveNavTab;

    // Initialize active tab on load
    setActiveNavTab();

    // Auto-close sidebar when clicking navigation links on small screens
    sidebar.addEventListener('click', (e) => {
        const link = e.target.closest('a');
        if (!link) return;

        const href = link.getAttribute('href');
        if (!href || href === '#' || href.startsWith('javascript:')) return;

        if (window.innerWidth <= 768) {
            closeSidebar();
        }

        const currentPage = window.location.pathname.split('/').pop() || 'dashboard.php';
        const targetPage = href.split('?')[0].split('/').pop();
        if (currentPage === targetPage && !href.includes('?') && !href.includes('#')) {
            e.preventDefault();
        }
    });

    window.addEventListener('popstate', () => {
        setActiveNavTab();
    });

    // Close sidebar on Escape key
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && window.innerWidth <= 768) {
            closeSidebar();
        }
    });

    // Handle screen resize cleanly
    window.addEventListener('resize', () => {
        const overlay = document.getElementById('sidebarOverlay');
        if (window.innerWidth > 768) {
            if (overlay) overlay.classList.remove('active');
            const isCollapsed = localStorage.getItem('sidebar_collapsed') === 'true';
            if (isCollapsed) {
                sidebar.classList.add('collapsed');
                document.documentElement.classList.add('sidebar-collapsed');
            } else {
                sidebar.classList.remove('collapsed');
                document.documentElement.classList.remove('sidebar-collapsed');
            }
        } else {
            if (sidebar.classList.contains('collapsed') && overlay) {
                overlay.classList.remove('active');
            }
        }
    });
});

// ── Global Theme Toggle & Sync System ──
window.setAppTheme = function(theme) {
    var html = document.documentElement;
    if (html.getAttribute('data-theme') === theme) return;

    // Trigger smooth global page animation
    html.classList.add('theme-transitioning');

    html.setAttribute('data-theme', theme);
    localStorage.setItem('theme', theme);

    syncThemeUI(theme);
    if (typeof initCharts === 'function') initCharts();

    // Clean up class after animation completes so hover/scroll aren't throttled
    clearTimeout(window.__themeTransitionTimer);
    window.__themeTransitionTimer = setTimeout(function() {
        html.classList.remove('theme-transitioning');
    }, 450);
};

window.toggleTheme = function() {
    var html = document.documentElement;
    var isLight = html.getAttribute('data-theme') === 'light';
    var nextTheme = isLight ? 'dark' : 'light';
    window.setAppTheme(nextTheme);
};

function syncThemeUI(theme) {
    var isLight = theme === 'light';
    var iconClass = isLight ? 'fa-solid fa-sun' : 'fa-solid fa-moon';
    var textLabel = isLight ? 'Light' : 'Dark';

    var lightBtn = document.getElementById('themeBtnLight');
    var darkBtn = document.getElementById('themeBtnDark');
    if (lightBtn && darkBtn) {
        if (isLight) {
            lightBtn.classList.add('active');
            darkBtn.classList.remove('active');
        } else {
            darkBtn.classList.add('active');
            lightBtn.classList.remove('active');
        }
    }

    var switchContainer = document.getElementById('sidebarThemeSwitch');
    if (switchContainer) {
        switchContainer.setAttribute('data-theme-active', theme);
    }

    document.querySelectorAll('#topThemeIcon, #themeIcon, #sidebarThemeIcon, #themeToggle i').forEach(function(icon) {
        icon.className = iconClass;
    });
    document.querySelectorAll('#topThemeText, #themeText, #sidebarThemeText').forEach(function(txt) {
        if (txt.id === 'sidebarThemeText') {
            txt.textContent = isLight ? 'Light Mode' : 'Dark Mode';
        } else {
            txt.textContent = textLabel;
        }
    });
    document.querySelectorAll('#sidebarThemeBadge').forEach(function(badge) {
        badge.textContent = textLabel;
    });
}

document.addEventListener('DOMContentLoaded', function() {
    var currentTheme = localStorage.getItem('theme') || (document.documentElement.getAttribute('data-theme') === 'light' ? 'light' : 'dark');
    document.documentElement.setAttribute('data-theme', currentTheme);
    syncThemeUI(currentTheme);
});

// ── Global Button & UI Click Sound (Web Audio API) ──
(function() {
    let clickAudioCtx = null;
    function playUIClickSound() {
        try {
            if (!clickAudioCtx) {
                clickAudioCtx = new (window.AudioContext || window.webkitAudioContext)();
            }
            if (clickAudioCtx.state === 'suspended') {
                clickAudioCtx.resume();
            }
            const osc = clickAudioCtx.createOscillator();
            const gain = clickAudioCtx.createGain();
            
            osc.type = 'sine';
            osc.frequency.setValueAtTime(1200, clickAudioCtx.currentTime);
            osc.frequency.exponentialRampToValueAtTime(400, clickAudioCtx.currentTime + 0.025);
            
            gain.gain.setValueAtTime(0.15, clickAudioCtx.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.001, clickAudioCtx.currentTime + 0.025);
            
            osc.connect(gain);
            gain.connect(clickAudioCtx.destination);
            
            osc.start(clickAudioCtx.currentTime);
            osc.stop(clickAudioCtx.currentTime + 0.026);
        } catch (e) {}
    }

    document.addEventListener('click', function(e) {
        const target = e.target.closest('button, .btn, a, .product-card, .vo-stat-box, .category-pill, .cart-item, [role="button"], input[type="submit"], input[type="button"]');
        if (target) {
            playUIClickSound();
        }
    }, true);
})();

// ── Global Standardized Toast Engine (Max 3 to 5 concurrent alerts) ──
if (typeof window.showToast !== 'function') {
    window.showToast = function(message, type, duration) {
        type = type || 'success';
        duration = duration || 3200;
        var MAX_VISIBLE_TOASTS = 5;

        var container = document.getElementById('toast-container');
        if (!container) {
            container = document.createElement('div');
            container.id = 'toast-container';
            document.body.appendChild(container);
        }

        // Cap visible toasts to max 5 (gracefully dismiss oldest if full)
        var activeToasts = container.querySelectorAll('.toast:not(.hide)');
        if (activeToasts.length >= MAX_VISIBLE_TOASTS) {
            for (var i = 0; i <= activeToasts.length - MAX_VISIBLE_TOASTS; i++) {
                var oldest = activeToasts[i];
                if (oldest && !oldest.classList.contains('hide')) {
                    oldest.classList.remove('show');
                    oldest.classList.add('hide');
                    (function(el) {
                        setTimeout(function() {
                            if (el && el.parentNode) el.parentNode.removeChild(el);
                        }, 220);
                    })(oldest);
                }
            }
        }

        var cleanMsg = String(message || '').replace(/^[✅❌⚠️🔁🗑️🔔👨‍🍳\s]+/, '');
        var toast = document.createElement('div');
        toast.className = 'toast ' + type;

        var iconClass = 'fa-check';
        if (type === 'warning') iconClass = 'fa-triangle-exclamation';
        else if (type === 'error') iconClass = 'fa-xmark';
        else if (type === 'info') iconClass = 'fa-circle-info';

        toast.innerHTML = 
            '<div class="toast-icon-badge"><i class="fa-solid ' + iconClass + '"></i></div>' +
            '<span class="toast-msg">' + cleanMsg + '</span>' +
            '<button type="button" class="toast-dismiss" title="Dismiss"><i class="fa-solid fa-xmark"></i></button>' +
            '<div class="toast-progress"><div class="toast-progress-bar" style="animation-duration:' + (duration / 1000) + 's"></div></div>';

        var dismissTimeout = null;
        var remainingTime = duration;
        var startTime = Date.now();
        var isPaused = false;

        function startTimer(ms) {
            startTime = Date.now();
            dismissTimeout = setTimeout(removeToast, ms);
        }

        function removeToast() {
            if (dismissTimeout) clearTimeout(dismissTimeout);
            toast.classList.remove('show');
            toast.classList.add('hide');
            setTimeout(function() {
                if (toast && toast.parentNode) toast.parentNode.removeChild(toast);
            }, 220);
        }

        // Hover to pause countdown timer
        var progressBar = toast.querySelector('.toast-progress-bar');
        toast.addEventListener('mouseenter', function() {
            if (isPaused) return;
            isPaused = true;
            clearTimeout(dismissTimeout);
            var elapsed = Date.now() - startTime;
            remainingTime = Math.max(500, remainingTime - elapsed);
            if (progressBar) {
                progressBar.style.animationPlayState = 'paused';
            }
        });

        toast.addEventListener('mouseleave', function() {
            if (!isPaused) return;
            isPaused = false;
            if (progressBar) {
                progressBar.style.animationPlayState = 'running';
            }
            startTimer(remainingTime);
        });

        var closeBtn = toast.querySelector('.toast-dismiss');
        if (closeBtn) {
            closeBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                removeToast();
            });
        }

        container.appendChild(toast);
        requestAnimationFrame(function() {
            toast.classList.add('show');
        });

        startTimer(duration);
    };
}

// ── Real-Time Sidebar Stock Alert Badges Updater ──
function updateSidebarStockBadges(alerts) {
    if (!alerts) return;

    // 1. Stock Drinks
    var stockBadge = document.getElementById('sidebarStockDrinkBadge');
    var stockNav = document.getElementById('sidebarNavStockDrink');
    if (alerts.stock_drinks) {
        var cnt = parseInt(alerts.stock_drinks.count || 0, 10);
        var hasOut = !!alerts.stock_drinks.has_out;
        if (cnt > 0) {
            if (!stockBadge && stockNav) {
                stockBadge = document.createElement('span');
                stockBadge.id = 'sidebarStockDrinkBadge';
                stockNav.appendChild(stockBadge);
            }
            if (stockBadge) {
                stockBadge.style.display = 'inline-flex';
                stockBadge.className = 'sidebar-stock-badge ' + (hasOut ? 'badge-danger' : 'badge-warning');
                stockBadge.textContent = cnt;
                stockBadge.title = cnt + (window.CPM_IS_KM ? ' អស់/ជិតអស់ស្តុក' : ' Low / Out of Stock');
            }
        } else if (stockBadge) {
            stockBadge.style.display = 'none';
        }
    }

    // 2. Raw Ingredients
    var ingBadge = document.getElementById('sidebarIngredientBadge');
    var ingNav = document.getElementById('sidebarNavIngredient');
    if (alerts.ingredients) {
        var cnt = parseInt(alerts.ingredients.count || 0, 10);
        var hasOut = !!alerts.ingredients.has_out;
        if (cnt > 0) {
            if (!ingBadge && ingNav) {
                ingBadge = document.createElement('span');
                ingBadge.id = 'sidebarIngredientBadge';
                ingNav.appendChild(ingBadge);
            }
            if (ingBadge) {
                ingBadge.style.display = 'inline-flex';
                ingBadge.className = 'sidebar-stock-badge ' + (hasOut ? 'badge-danger' : 'badge-warning');
                ingBadge.textContent = cnt;
                ingBadge.title = cnt + (window.CPM_IS_KM ? ' អស់/ជិតអស់ស្តុក' : ' Low / Out of Stock');
            }
        } else if (ingBadge) {
            ingBadge.style.display = 'none';
        }
    }
}
window.updateSidebarStockBadges = updateSidebarStockBadges;

function pollSidebarStockBadges() {
    fetch('api_stock_status.php?_t=' + Date.now(), { cache: 'no-store' })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data && data.sidebar_alerts) {
                updateSidebarStockBadges(data.sidebar_alerts);
            }
        })
        .catch(function() {});
}
window.pollSidebarStockBadges = pollSidebarStockBadges;

// Automatically poll sidebar badges across all pages every 8 seconds
setInterval(pollSidebarStockBadges, 8000);

// ── LOGOUT MODAL CONTROLLERS ──
function openLogoutModal(e) {
    if (e && e.preventDefault) e.preventDefault();
    var modal = document.getElementById('logoutModalOverlay');
    if (modal) {
        modal.classList.add('active');
        modal.setAttribute('aria-hidden', 'false');
    }
    return false;
}
window.openLogoutModal = openLogoutModal;

function closeLogoutModal() {
    var modal = document.getElementById('logoutModalOverlay');
    if (modal) {
        modal.classList.remove('active');
        modal.setAttribute('aria-hidden', 'true');
    }
}
window.closeLogoutModal = closeLogoutModal;

document.addEventListener('DOMContentLoaded', function() {
    // Intercept any logout link that hasn't already been bound
    document.querySelectorAll('a[href="logout.php"], a[href$="/logout.php"]').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            if (btn.closest('#logoutModalOverlay')) return; // Allow confirmed logout
            openLogoutModal(e);
        });
    });

    // Close on backdrop click
    var overlay = document.getElementById('logoutModalOverlay');
    if (overlay) {
        overlay.addEventListener('click', function(e) {
            if (e.target === overlay) {
                closeLogoutModal();
            }
        });
    }

    // Close on Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeLogoutModal();
        }
    });
});
</script>
