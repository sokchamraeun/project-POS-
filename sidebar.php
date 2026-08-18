<?php
// sidebar.php - Shared Left Navigation Sidebar
if (!function_exists('can')) {
    require_once __DIR__ . '/auth.php';
}
$_cur_page = basename($_SERVER['PHP_SELF'] ?? '');
$_is_admin = (($_SESSION['role'] ?? '') === 'admin');
$_username = $_SESSION['emp_name'] ?? ($_SESSION['username'] ?? 'User');
$_user_role = ucfirst($_SESSION['role'] ?? 'Staff');
$_role_color = ($_SESSION['role'] ?? '') === 'admin' ? '#ff6b6b' : (($_SESSION['role'] ?? '') === 'manager' ? '#f0b429' : '#d1904b');
?>
<style>
/* ══ Persistent Master Layout Shell ══ */
:root {
    --sidebar-w: 256px;
}
:root.sidebar-collapsed {
    --sidebar-w: 76px;
}
body {
    margin: 0;
    padding: 0;
    background-color: var(--bg, #0e0e10);
}
.app-layout, .layout {
    display: flex;
    height: 100vh;
    width: 100vw;
    overflow: hidden;
    position: relative;
    background-color: var(--bg, #0e0e10);
}
.sidebar {
    width: var(--sidebar-w, 256px) !important;
    min-width: var(--sidebar-w, 256px) !important;
    max-width: var(--sidebar-w, 256px) !important;
    flex-shrink: 0 !important;
    height: 100vh;
    position: relative !important;
    z-index: 50;
    background: var(--surface, #121215);
    border-right: 1px solid var(--border, #1f1f24);
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    padding: 1rem !important;
    overflow-y: auto;
    overflow-x: hidden;
    transition: width 0.22s cubic-bezier(0.4, 0, 0.2, 1), min-width 0.22s cubic-bezier(0.4, 0, 0.2, 1), max-width 0.22s cubic-bezier(0.4, 0, 0.2, 1), padding 0.22s ease !important;
}
.sidebar.sidebar-no-anim {
    transition: none !important;
}

/* Collapsed State */
.sidebar.collapsed {
    --sidebar-w: 76px;
    width: 76px !important;
    min-width: 76px !important;
    max-width: 76px !important;
    padding: 0.75rem 0.35rem !important;
}
.sidebar .profile-info,
.sidebar .brand-name,
.sidebar .sidebar-brand-icon,
.sidebar .brand-title-wrap,
.sidebar .nav-label,
.sidebar .sidebar-orders-badge,
.sidebar .nav-chevron,
.sidebar #reportChevron,
.sidebar #reportSubmenu,
.sidebar #userMgmtChevron,
.sidebar #userMgmtSubmenu,
.sidebar .lang-text-label,
.sidebar .lang-flag-badge {
    white-space: nowrap;
    opacity: 1;
    transition: opacity 0.15s ease;
}
.sidebar.collapsed .profile-info,
.sidebar.collapsed .brand-name,
.sidebar.collapsed .sidebar-brand-icon,
.sidebar.collapsed .brand-title-wrap,
.sidebar.collapsed .nav-label,
.sidebar.collapsed .sidebar-orders-badge,
.sidebar.collapsed .nav-chevron,
.sidebar.collapsed #reportChevron,
.sidebar.collapsed #reportSubmenu,
.sidebar.collapsed #userMgmtChevron,
.sidebar.collapsed #userMgmtSubmenu,
.sidebar.collapsed .lang-text-label,
.sidebar.collapsed .lang-flag-badge {
    opacity: 0 !important;
    pointer-events: none !important;
    display: none !important;
    width: 0 !important;
    height: 0 !important;
    overflow: hidden !important;
}
.sidebar .sidebar-brand-icon {
    background: transparent !important;
    background-image: none !important;
    width: auto !important;
    height: auto !important;
    border-radius: 0 !important;
    box-shadow: none !important;
}
.sidebar.collapsed .sidebar-header {
    justify-content: center !important;
    align-items: center !important;
    padding: 4px 0 !important;
    width: 100% !important;
}
.sidebar.collapsed .sidebar-header .brand-title-wrap {
    display: none !important;
}
.sidebar.collapsed .sidebar-collapse-btn {
    margin: 0 auto !important;
}
.sidebar.collapsed .sidebar-profile {
    justify-content: center !important;
    padding: 8px 4px !important;
    width: 100% !important;
}
.sidebar.collapsed .profile-avatar {
    margin: 0 auto !important;
}
.sidebar.collapsed .nav-item {
    justify-content: center !important;
    padding: 10px 0 !important;
    width: 100% !important;
    text-align: center !important;
}
.sidebar.collapsed .nav-item > div {
    justify-content: center !important;
    gap: 0 !important;
}
.sidebar.collapsed .nav-item i {
    margin: 0 !important;
    font-size: 18px !important;
    width: 100% !important;
    text-align: center !important;
}
.sidebar.collapsed .sidebar-footer {
    padding-top: 8px !important;
}
.sidebar.collapsed .sidebar-footer a {
    justify-content: center !important;
    padding: 10px 0 !important;
    width: 100% !important;
}
.sidebar.collapsed .sidebar-footer a i {
    font-size: 18px !important;
    margin: 0 !important;
    width: 100% !important;
    text-align: center !important;
}
.sidebar-profile {
    margin: 0 !important;
}
/* Sidebar Typography Upgrades */
.sidebar .nav-item {
    font-size: 0.95rem !important;
    font-weight: 600 !important;
}
.sidebar .nav-item i {
    font-size: 1.05rem !important;
}
.sidebar #reportSubmenu .nav-item,
.sidebar #userMgmtSubmenu .nav-item {
    font-size: 0.88rem !important;
    font-weight: 600 !important;
}
.sidebar #reportSubmenu .nav-item i,
.sidebar #userMgmtSubmenu .nav-item i {
    font-size: 0.95rem !important;
}
.sidebar .profile-name {
    font-size: 0.98rem !important;
    font-weight: 700 !important;
}
.sidebar .profile-role {
    font-size: 0.8rem !important;
    font-weight: 600 !important;
}
.sidebar .brand-name {
    font-size: 1.15rem !important;
    font-weight: 900 !important;
}
.sidebar .sidebar-footer .nav-item {
    font-size: 0.95rem !important;
    font-weight: 700 !important;
}
.sidebar .lang-flag-badge {
    font-size: 0.78rem !important;
    font-weight: 800 !important;
}
.app-main, .main-content-container {
    margin-left: 0 !important;
    flex: 1;
    min-width: 0;
    height: 100vh;
    overflow-y: auto;
    box-sizing: border-box;
    transition: opacity 0.15s ease-in-out;
}

/* ══ Amber Sidebar Design Upgrade & Scrollbar Hiding ══ */
.sidebar,
html[data-theme="light"] .sidebar,
[data-theme="light"] .sidebar {
    background: #121215 !important;
    border-right: 1px solid rgba(209, 144, 75, 0.18) !important;
    padding: 0.6rem 0.75rem !important;
    scrollbar-width: none !important; /* Firefox */
    -ms-overflow-style: none !important; /* IE & Edge */
    color: #a0a0ab !important;
}
.sidebar::-webkit-scrollbar {
    width: 0px !important;
    height: 0px !important;
    display: none !important;
}

.sidebar.collapsed {
    padding: 0.6rem 0.35rem !important;
}

/* Permanent Dark Sidebar Lock (Light Mode Immunity) */
.sidebar *,
html[data-theme="light"] .sidebar *,
[data-theme="light"] .sidebar * {
    color-scheme: dark;
}

/* Compact Nav Item Spacing */
.sidebar .nav-item,
html[data-theme="light"] .sidebar .nav-item,
[data-theme="light"] .sidebar .nav-item {
    padding-top: 0.45rem !important;
    padding-bottom: 0.45rem !important;
    color: #c8c8d2 !important;
    transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1) !important;
    border: 1px solid transparent;
}

/* All Nav Icons in Amber */
.sidebar .nav-item i,
html[data-theme="light"] .sidebar .nav-item i,
[data-theme="light"] .sidebar .nav-item i {
    color: #d1904b !important;
    transition: transform 0.2s ease, color 0.2s ease !important;
}

/* Nav Item Hover */
.sidebar .nav-item:hover,
html[data-theme="light"] .sidebar .nav-item:hover,
[data-theme="light"] .sidebar .nav-item:hover {
    background: rgba(209, 144, 75, 0.12) !important;
    border-color: rgba(209, 144, 75, 0.28) !important;
    color: #ffffff !important;
}
.sidebar .nav-item:hover i {
    transform: scale(1.1);
    color: #e5a15a !important;
}

/* Active Nav Item */
.sidebar .nav-item.active,
html[data-theme="light"] .sidebar .nav-item.active,
[data-theme="light"] .sidebar .nav-item.active {
    background: rgba(209, 144, 75, 0.18) !important;
    border: 1px solid rgba(209, 144, 75, 0.38) !important;
    color: #d1904b !important;
    font-weight: 700 !important;
    box-shadow: 0 3px 12px rgba(209, 144, 75, 0.2);
}
.sidebar .nav-item.active i {
    color: #d1904b !important;
    transform: scale(1.08);
}

/* Profile Card Accent */
.sidebar-profile,
html[data-theme="light"] .sidebar .sidebar-profile,
[data-theme="light"] .sidebar .sidebar-profile {
    border-color: rgba(209, 144, 75, 0.25) !important;
    background: rgba(255, 255, 255, 0.03) !important;
    padding: 0.5rem 0.75rem !important;
}
.sidebar-profile:hover {
    border-color: #d1904b !important;
    box-shadow: 0 0 16px rgba(209, 144, 75, 0.25) !important;
}

.sidebar .profile-name,
.sidebar .brand-name,
html[data-theme="light"] .sidebar .profile-name,
html[data-theme="light"] .sidebar .brand-name,
[data-theme="light"] .sidebar .profile-name,
[data-theme="light"] .sidebar .brand-name {
    color: #ffffff !important;
}

.sidebar .profile-role,
html[data-theme="light"] .sidebar .profile-role,
[data-theme="light"] .sidebar .profile-role {
    color: #888888 !important;
}

/* Report & User Mgmt Submenu Left Border */
.sidebar #reportSubmenu,
.sidebar #userMgmtSubmenu,
html[data-theme="light"] .sidebar #reportSubmenu,
html[data-theme="light"] .sidebar #userMgmtSubmenu,
[data-theme="light"] .sidebar #reportSubmenu,
[data-theme="light"] .sidebar #userMgmtSubmenu {
    border-left: 2px solid #d1904b !important;
}

/* Collapse Toggle Button */
.sidebar .sidebar-collapse-btn,
html[data-theme="light"] .sidebar .sidebar-collapse-btn,
[data-theme="light"] .sidebar .sidebar-collapse-btn {
    background: #18181c !important;
    border-color: #24242b !important;
    color: #888888 !important;
}
.sidebar-collapse-btn:hover,
html[data-theme="light"] .sidebar .sidebar-collapse-btn:hover,
[data-theme="light"] .sidebar .sidebar-collapse-btn:hover {
    border-color: #d1904b !important;
    color: #d1904b !important;
}

.sidebar .sidebar-footer,
html[data-theme="light"] .sidebar .sidebar-footer,
[data-theme="light"] .sidebar .sidebar-footer {
    border-top-color: #24242b !important;
}

.sidebar .nav-logout,
html[data-theme="light"] .sidebar .nav-logout,
[data-theme="light"] .sidebar .nav-logout {
    color: #ff6b6b !important;
}
.sidebar .nav-logout:hover,
html[data-theme="light"] .sidebar .nav-logout:hover,
[data-theme="light"] .sidebar .nav-logout:hover {
    background: rgba(255, 107, 107, 0.10) !important;
}

.sidebar .lang-flag-badge,
html[data-theme="light"] .sidebar .lang-flag-badge,
[data-theme="light"] .sidebar .lang-flag-badge {
    background: rgba(209, 144, 75, 0.15) !important;
    color: #d1904b !important;
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
        width: 280px !important;
        min-width: 280px !important;
        max-width: 280px !important;
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

<aside class="w-64 h-full shrink-0 bg-[#121215] border-r border-[#1f1f24] flex flex-col justify-between p-4 text-[#a0a0ab] z-50 overflow-y-auto sidebar sidebar-no-anim" id="sidebar">
    <script>
    if (window.__isSidebarCollapsed) {
        document.getElementById('sidebar').classList.add('collapsed');
        if (window.innerWidth > 768) {
            document.documentElement.classList.add('sidebar-collapsed');
        }
    }
    </script>
    <div class="flex flex-col gap-2">
        <!-- Profile Header -->
        <a href="profile.php" class="flex items-center gap-3 p-2.5 rounded-2xl bg-[#18181c] border border-[#24242b] sidebar-profile hover:border-[#d1904b] transition-all cursor-pointer" title="View My Profile">
            <div class="w-8 h-8 rounded-full bg-[#d1904b]/20 text-[#d1904b] font-bold flex items-center justify-center text-xs flex-shrink-0 profile-avatar">
                <?= strtoupper(substr($_username, 0, 1)) ?>
            </div>
            <div class="min-w-0 flex-1 profile-info">
                <div class="text-sm font-semibold text-white truncate profile-name"><?= htmlspecialchars($_username) ?></div>
                <div class="text-xs text-[#888] truncate profile-role" style="--role-color: <?= $_role_color ?>;"><?= htmlspecialchars($_user_role) ?></div>
            </div>
        </a>

        <!-- Brand Header with Collapse Toggle Button -->
        <div class="flex items-center justify-between px-2 py-0.5 sidebar-header">
            <div class="flex items-center gap-2.5 brand-title-wrap">
                <i class="fa-solid fa-mug-hot text-[#d1904b] text-lg flex-shrink-0 sidebar-brand-icon"></i>
                <h2 class="text-base font-extrabold text-white truncate brand-name">Bird's Nest</h2>
            </div>
            <button type="button" 
                    id="sidebarToggleBtn" 
                    onclick="toggleSidebar()" 
                    class="sidebar-collapse-btn flex items-center justify-center w-7 h-7 rounded-lg bg-[#18181c] border border-[#24242b] text-[#888] hover:text-white hover:border-[#d1904b] transition-all cursor-pointer" 
                    title="Close / Open Sidebar">
                <i class="fa-solid fa-angles-left text-xs" id="sidebarToggleIcon"></i>
            </button>
        </div>

        <!-- Vertical Stacked Nav Links -->
        <nav class="flex flex-col gap-1.5 sidebar-nav" id="sidebarNav">

            <?php if (can('take_order')): ?>
            <a class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium transition-all hover:bg-[#1a1a20] hover:text-white<?= $_cur_page === 'menu.php' ? ' active bg-[#1a1a20] text-[#d1904b] font-semibold' : '' ?>" href="menu.php">
                <i class="fa-solid fa-cart-shopping w-5 text-center"></i>
                <span class="nav-label"><?= __('nav_take_order', 'Take Order') ?></span>
            </a>
            <?php endif; ?>

            <?php if (can('dashboard')): ?>
            <a class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium transition-all hover:bg-[#1a1a20] hover:text-white<?= $_cur_page === 'dashboard.php' ? ' active bg-[#1a1a20] text-[#d1904b] font-semibold' : '' ?>" href="dashboard.php">
                <i class="fa-solid fa-chart-pie w-5 text-center"></i>
                <span class="nav-label"><?= __('nav_dashboard', 'Dashboard') ?></span>
            </a>
            <?php endif; ?>

            <?php if (can('view_orders')): ?>
            <a class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium transition-all hover:bg-[#1a1a20] hover:text-white<?= $_cur_page === 'view_order.php' ? ' active bg-[#1a1a20] text-[#d1904b] font-semibold' : '' ?>" href="view_order.php">
                <i class="fa-solid fa-receipt w-5 text-center"></i>
                <span class="nav-label"><?= __('nav_orders', 'Orders') ?></span>
            </a>
            <?php endif; ?>

            <?php if (can('products')): ?>
            <a class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium transition-all hover:bg-[#1a1a20] hover:text-white<?= in_array($_cur_page, ['products.php', 'edit_product.php', 'add_product.php']) ? ' active bg-[#1a1a20] text-[#d1904b] font-semibold' : '' ?>" href="products.php">
                <i class="fa-solid fa-cube w-5 text-center"></i>
                <span class="nav-label"><?= __('nav_products', 'Products') ?></span>
            </a>
            <?php endif; ?>

            <?php if (can('products') || can('inventory') || in_array($_SESSION['role'] ?? '', ['admin', 'manager', 'staff'])): ?>
            <a class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium transition-all hover:bg-[#1a1a20] hover:text-white<?= in_array($_cur_page, ['stock.php', 'stock_count.php']) ? ' active bg-[#1a1a20] text-[#d1904b] font-semibold' : '' ?>" href="stock.php">
                <i class="fa-solid fa-wine-bottle w-5 text-center"></i>
                <span class="nav-label"><?= __('nav_stock_drinks', 'Direct Drinks Stock') ?></span>
            </a>
            <a class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium transition-all hover:bg-[#1a1a20] hover:text-white<?= $_cur_page === 'ingredients.php' ? ' active bg-[#1a1a20] text-[#d1904b] font-semibold' : '' ?>" href="ingredients.php">
                <i class="fa-solid fa-seedling w-5 text-center"></i>
                <span class="nav-label"><?= __('nav_raw_ingredients', 'Raw Ingredients') ?></span>
            </a>
            <?php endif; ?>

            <?php if (can('manage_categories')): ?>
            <a class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium transition-all hover:bg-[#1a1a20] hover:text-white<?= $_cur_page === 'manage_categories.php' ? ' active bg-[#1a1a20] text-[#d1904b] font-semibold' : '' ?>" href="manage_categories.php">
                <i class="fa-solid fa-tags w-5 text-center"></i>
                <span class="nav-label"><?= __('nav_categories', 'Categories') ?></span>
            </a>
            <?php endif; ?>

            <?php if (can('daily_report') || can('sales_report') || can('shift_report')): ?>
            <!-- Reports Collapsible Group -->
            <div>
                <button type="button" 
                        id="reportNavToggle" 
                        onclick="toggleReportSubmenu(event)" 
                        class="nav-item w-full flex items-center justify-between px-3 py-2.5 rounded-xl text-sm font-medium transition-all hover:bg-[#1a1a20] hover:text-white cursor-pointer<?= in_array($_cur_page, ['daily_report.php', 'report.php', 'shift_report.php']) ? ' active bg-[#1a1a20] text-[#d1904b] font-semibold' : '' ?>">
                    <div class="flex items-center gap-3 min-w-0">
                        <i class="fa-solid fa-chart-column w-5 text-center flex-shrink-0"></i>
                        <span class="nav-label truncate"><?= __('nav_reports', 'Reports') ?></span>
                    </div>
                    <i class="fa-solid fa-chevron-down text-xs transition-transform duration-200 nav-chevron" id="reportChevron"></i>
                </button>
                <div class="hidden flex-col gap-1 pl-4 mt-1 space-y-1" id="reportSubmenu">
                    <?php if (can('daily_report')): ?>
                    <a class="nav-item flex items-center gap-2.5 px-3 py-2 rounded-lg text-xs font-medium transition-all hover:bg-[#1a1a20] hover:text-white<?= $_cur_page === 'daily_report.php' ? ' active bg-[#1a1a20] text-[#d1904b] font-semibold' : '' ?>" href="daily_report.php">
                        <i class="fa-solid fa-[#d1904b] fa-calendar-day w-4 text-center"></i>
                        <span class="nav-label"><?= __('nav_daily_report', 'Daily Summary') ?></span>
                    </a>
                    <?php endif; ?>
                    <?php if (can('sales_report')): ?>
                    <a class="nav-item flex items-center gap-2.5 px-3 py-2 rounded-lg text-xs font-medium transition-all hover:bg-[#1a1a20] hover:text-white<?= $_cur_page === 'report.php' ? ' active bg-[#1a1a20] text-[#d1904b] font-semibold' : '' ?>" href="report.php">
                        <i class="fa-solid fa-[#d1904b] fa-chart-line w-4 text-center"></i>
                        <span class="nav-label"><?= __('nav_sales_report', 'Analytics & Export') ?></span>
                    </a>
                    <?php endif; ?>
                    <?php if (can('shift_report')): ?>
                    <a class="nav-item flex items-center gap-2.5 px-3 py-2 rounded-lg text-xs font-medium transition-all hover:bg-[#1a1a20] hover:text-white<?= $_cur_page === 'shift_report.php' ? ' active bg-[#1a1a20] text-[#d1904b] font-semibold' : '' ?>" href="shift_report.php">
                        <i class="fa-solid fa-[#d1904b] fa-clock w-4 text-center"></i>
                        <span class="nav-label"><?= __('nav_shift_report', 'Shift Audit') ?></span>
                    </a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if (can('settings')): ?>
            <a class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium transition-all hover:bg-[#1a1a20] hover:text-white<?= $_cur_page === 'settings.php' ? ' active bg-[#1a1a20] text-[#d1904b] font-semibold' : '' ?>" href="settings.php">
                <i class="fa-solid fa-gear w-5 text-center"></i>
                <span class="nav-label"><?= __('nav_settings', 'Settings') ?></span>
            </a>
            <?php endif; ?>

            <?php if (in_array($_SESSION['role'] ?? '', ['admin', 'manager'], true)): ?>
            <a class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium transition-all hover:bg-[#1a1a20] hover:text-white<?= $_cur_page === 'users.php' ? ' active bg-[#1a1a20] text-[#d1904b] font-semibold' : '' ?>" href="users.php">
                <i class="fa-solid fa-users-gear w-5 text-center"></i>
                <span class="nav-label">Users</span>
            </a>
            <?php endif; ?>

        </nav>
    </div>

    <!-- Sidebar Footer -->
    <div class="pt-3 border-t border-[#24242b] flex flex-col gap-1.5 sidebar-footer">
        <!-- Dark/Light Theme Toggle Button -->
        <button type="button" 
                onclick="toggleTheme()" 
                class="nav-item w-full flex items-center justify-between px-3 py-2 rounded-xl text-xs font-semibold text-[#a0a0ab] hover:bg-[#1a1a20] hover:text-white transition-all cursor-pointer" 
                title="Toggle Theme">
            <div class="flex items-center gap-3 min-w-0">
                <i class="fa-solid fa-moon text-[#d1904b] w-5 text-center flex-shrink-0" id="sidebarThemeIcon"></i>
                <span class="nav-label truncate" id="sidebarThemeText">Dark Mode</span>
            </div>
            <span class="px-2 py-0.5 rounded-full bg-[#18181c] border border-[#24242b] text-[#d1904b] font-bold text-[10px] uppercase tracking-wider sidebar-orders-badge" id="sidebarThemeBadge">
                Dark
            </span>
        </button>
        <!-- Language Switcher Button -->
        <a href="set_language.php?lang=<?= current_lang() === 'en' ? 'km' : 'en' ?>" class="nav-item flex items-center justify-between px-3 py-2 rounded-xl text-xs font-semibold text-[#a0a0ab] hover:bg-[#1a1a20] hover:text-white transition-all" title="Language Switcher">
            <i class="fa-solid fa-globe text-[#d1904b] w-5 text-center"></i>
            <span class="px-2 py-0.5 rounded-full bg-[#d1904b]/15 text-[#d1904b] font-bold text-[11px] lang-flag-badge">
                <?= current_lang() === 'en' ? '🇬🇧 English' : '🇰🇭 Khmer (ខ្មែរ)' ?>
            </span>
        </a>
        <a class="nav-item nav-logout flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium text-[#ff6b6b] hover:bg-[#ff6b6b]/10 transition-all" href="logout.php" title="Logout">
            <i class="fa-solid fa-right-from-bracket w-5 text-center"></i>
            <span class="nav-label"><?= __('logout', 'Logout') ?></span>
        </a>
    </div>
</aside>

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
        chevron.classList.add('rotate-180', 'text-[#d1904b]');
    } else {
        menu.classList.add('hidden');
        chevron.classList.remove('rotate-180', 'text-[#d1904b]');
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
        chevron.classList.add('rotate-180', 'text-[#d1904b]');
    } else {
        menu.classList.add('hidden');
        chevron.classList.remove('rotate-180', 'text-[#d1904b]');
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
                el.classList.add('active', 'bg-[#1a1a20]', 'text-[#d1904b]', 'font-semibold');
            } else {
                el.classList.remove('active', 'bg-[#1a1a20]', 'text-[#d1904b]', 'font-semibold');
            }
        });

        const reportPages = ['daily_report.php', 'report.php', 'shift_report.php'];
        const reportNavToggle = document.getElementById('reportNavToggle');
        const reportSubmenu = document.getElementById('reportSubmenu');
        const reportChevron = document.getElementById('reportChevron');
        if (reportPages.includes(page)) {
            if (reportNavToggle) reportNavToggle.classList.add('text-[#d1904b]', 'font-semibold', 'bg-[#1a1a20]');
            if (reportSubmenu) reportSubmenu.classList.remove('hidden');
            if (reportChevron) reportChevron.classList.add('rotate-180', 'text-[#d1904b]');
        }

        const userMgmtPages = ['employees.php', 'employee_add.php', 'manage_admin.php', 'manage_roles.php'];
        const userMgmtNavToggle = document.getElementById('userMgmtNavToggle');
        const userMgmtSubmenu = document.getElementById('userMgmtSubmenu');
        const userMgmtChevron = document.getElementById('userMgmtChevron');
        if (userMgmtPages.includes(page)) {
            if (userMgmtNavToggle) userMgmtNavToggle.classList.add('text-[#d1904b]', 'font-semibold', 'bg-[#1a1a20]');
            if (userMgmtSubmenu) userMgmtSubmenu.classList.remove('hidden');
            if (userMgmtChevron) userMgmtChevron.classList.add('rotate-180', 'text-[#d1904b]');
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
window.toggleTheme = function() {
    var html = document.documentElement;
    var isLight = html.getAttribute('data-theme') === 'light';
    var nextTheme = isLight ? 'dark' : 'light';
    html.setAttribute('data-theme', nextTheme);
    localStorage.setItem('theme', nextTheme);

    syncThemeUI(nextTheme);
    if (typeof initCharts === 'function') initCharts();
};

function syncThemeUI(theme) {
    var isLight = theme === 'light';
    var iconClass = isLight ? 'fa-solid fa-sun' : 'fa-solid fa-moon';
    var textLabel = isLight ? 'Light' : 'Dark';

    document.querySelectorAll('#topThemeIcon, #themeIcon, #sidebarThemeIcon, #themeToggle i').forEach(function(icon) {
        if (icon.id === 'sidebarThemeIcon') {
            icon.className = iconClass + ' text-[#d1904b] text-center w-5';
        } else {
            icon.className = iconClass;
        }
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
</script>
