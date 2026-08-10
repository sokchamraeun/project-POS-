<?php
// sidebar.php - Shared Left Navigation Sidebar
if (!function_exists('can')) {
    require_once __DIR__ . '/auth.php';
}
$_cur_page = basename($_SERVER['PHP_SELF'] ?? '');
$_is_admin = (($_SESSION['role'] ?? '') === 'admin');
$_username = $_SESSION['username'] ?? 'User';
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
    background-color: #0e0e10;
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
.sidebar .brand-icon,
.sidebar .brand-title-wrap,
.sidebar .nav-label,
.sidebar .sidebar-orders-badge,
.sidebar .nav-chevron,
.sidebar #reportChevron,
.sidebar #reportSubmenu,
.sidebar .lang-text-label,
.sidebar .lang-flag-badge {
    white-space: nowrap;
    opacity: 1;
    transition: opacity 0.15s ease;
}
.sidebar.collapsed .profile-info,
.sidebar.collapsed .brand-name,
.sidebar.collapsed .brand-icon,
.sidebar.collapsed .brand-title-wrap,
.sidebar.collapsed .nav-label,
.sidebar.collapsed .sidebar-orders-badge,
.sidebar.collapsed .nav-chevron,
.sidebar.collapsed #reportChevron,
.sidebar.collapsed #reportSubmenu,
.sidebar.collapsed .lang-text-label,
.sidebar.collapsed .lang-flag-badge {
    opacity: 0 !important;
    pointer-events: none !important;
    display: none !important;
    width: 0 !important;
    height: 0 !important;
    overflow: hidden !important;
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
.sidebar #reportSubmenu .nav-item {
    font-size: 0.88rem !important;
    font-weight: 600 !important;
}
.sidebar #reportSubmenu .nav-item i {
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
.sidebar {
    background: #121215 !important;
    border-right: 1px solid rgba(209, 144, 75, 0.18) !important;
    padding: 0.6rem 0.75rem !important;
    scrollbar-width: none !important; /* Firefox */
    -ms-overflow-style: none !important; /* IE & Edge */
}
.sidebar::-webkit-scrollbar {
    width: 0px !important;
    height: 0px !important;
    display: none !important;
}

.sidebar.collapsed {
    padding: 0.6rem 0.35rem !important;
}

/* Compact Nav Item Spacing */
.sidebar .nav-item {
    padding-top: 0.45rem !important;
    padding-bottom: 0.45rem !important;
    color: #c8c8d2;
    transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1) !important;
    border: 1px solid transparent;
}

/* All Nav Icons in Amber */
.sidebar .nav-item i {
    color: #d1904b !important;
    transition: transform 0.2s ease, color 0.2s ease !important;
}

/* Nav Item Hover */
.sidebar .nav-item:hover {
    background: rgba(209, 144, 75, 0.12) !important;
    border-color: rgba(209, 144, 75, 0.28) !important;
    color: #ffffff !important;
}
.sidebar .nav-item:hover i {
    transform: scale(1.1);
    color: #e5a15a !important;
}

/* Active Nav Item */
.sidebar .nav-item.active {
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
.sidebar-profile {
    border-color: rgba(209, 144, 75, 0.25) !important;
    background: rgba(255, 255, 255, 0.03) !important;
    padding: 0.5rem 0.75rem !important;
}
.sidebar-profile:hover {
    border-color: #d1904b !important;
    box-shadow: 0 0 16px rgba(209, 144, 75, 0.25) !important;
}

/* Report Submenu Left Border */
.sidebar #reportSubmenu {
    border-left: 2px solid #d1904b !important;
}

/* Collapse Toggle Button */
.sidebar-collapse-btn:hover {
    border-color: #d1904b !important;
    color: #d1904b !important;
}
</style>

<script>
// Immediate inline execution before render to prevent flash animation
(function() {
    window.__isSidebarCollapsed = (localStorage.getItem('sidebar_collapsed') === 'true');
})();
</script>

<aside class="w-64 h-full shrink-0 bg-[#121215] border-r border-[#1f1f24] flex flex-col justify-between p-4 text-[#a0a0ab] z-50 overflow-y-auto sidebar sidebar-no-anim" id="sidebar">
    <script>
    if (window.__isSidebarCollapsed) {
        document.getElementById('sidebar').classList.add('collapsed');
    }
    </script>
    <div class="flex flex-col gap-2">
        <!-- Profile Header -->
        <a href="profile.php" class="flex items-center gap-3 p-2.5 rounded-2xl bg-[#18181c] border border-[#24242b] hover:border-[#d1904b] transition-all sidebar-profile" title="My Profile">
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
                <i class="fa-solid fa-mug-hot text-[#d1904b] text-lg flex-shrink-0 brand-icon"></i>
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

            <?php if (can('find_orders')): ?>
            <a class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium transition-all hover:bg-[#1a1a20] hover:text-white<?= $_cur_page === 'menu.php' ? ' active bg-[#1a1a20] text-[#d1904b] font-semibold' : '' ?>" href="menu.php">
                <i class="fa-solid fa-cart-shopping w-5 text-center"></i>
                <span class="nav-label"><?= __('nav_take_order', 'Take Order') ?></span>
            </a>
            <?php endif; ?>

            <a class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium transition-all hover:bg-[#1a1a20] hover:text-white<?= $_cur_page === 'dashboard.php' ? ' active bg-[#1a1a20] text-[#d1904b] font-semibold' : '' ?>" href="dashboard.php">
                <i class="fa-solid fa-chart-pie w-5 text-center"></i>
                <span class="nav-label"><?= __('nav_dashboard', 'Dashboard') ?></span>
            </a>

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
            <a class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium transition-all hover:bg-[#1a1a20] hover:text-white<?= $_cur_page === 'manage_categories.php' ? ' active bg-[#1a1a20] text-[#d1904b] font-semibold' : '' ?>" href="manage_categories.php">
                <i class="fa-solid fa-tags w-5 text-center"></i>
                <span class="nav-label"><?= __('nav_categories', 'Categories') ?></span>
            </a>
            <?php endif; ?>

            <?php if (can('ingredients')): ?>
            <a class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium transition-all hover:bg-[#1a1a20] hover:text-white<?= $_cur_page === 'ingredients.php' ? ' active bg-[#1a1a20] text-[#d1904b] font-semibold' : '' ?>" href="ingredients.php">
                <i class="fa-solid fa-boxes-stacked w-5 text-center"></i>
                <span class="nav-label"><?= __('nav_ingredients', 'Ingredients') ?></span>
            </a>
            <?php endif; ?>

            <?php 
            $_report_pages = ['daily_report.php', 'report.php', 'ingredient_report.php', 'shift_report.php'];
            $_is_report_active = in_array($_cur_page, $_report_pages, true);
            ?>

            <?php if (can('report') || can('ingredients') || can('employees')): ?>
            <div class="nav-group flex flex-col">
                <button type="button" 
                        id="reportNavToggle"
                        class="nav-item flex items-center justify-between px-3 py-2.5 rounded-xl text-sm font-medium transition-all hover:bg-[#1a1a20] hover:text-white cursor-pointer w-full text-left <?= $_is_report_active ? 'text-[#d1904b] font-semibold bg-[#1a1a20]' : '' ?>"
                        onclick="toggleReportSubmenu(event)">
                    <div class="flex items-center gap-3">
                        <i class="fa-solid fa-chart-simple w-5 text-center"></i>
                        <span class="nav-label"><?= __('nav_report', 'Report') ?></span>
                    </div>
                    <i id="reportChevron" class="fa-solid fa-chevron-down text-xs transition-transform duration-200 <?= $_is_report_active ? 'rotate-180 text-[#d1904b]' : 'text-[#666]' ?>"></i>
                </button>
                
                <div id="reportSubmenu" class="mt-1 flex flex-col gap-1 <?= $_is_report_active ? '' : 'hidden' ?>" style="padding-left: 1.5rem; border-left: 2px solid rgba(209, 144, 75, 0.25); margin-left: 1.25rem;">
                    <?php if (can('report')): ?>
                    <a class="nav-item flex items-center gap-2.5 px-3 py-2 rounded-lg text-xs font-medium transition-all hover:bg-[#1a1a20] hover:text-white <?= $_cur_page === 'daily_report.php' ? ' active bg-[#1a1a20] text-[#d1904b] font-semibold' : 'text-[#a0a0ab]' ?>" href="daily_report.php">
                        <i class="fa-solid fa-chart-line w-4 text-center"></i>
                        <span class="nav-label"><?= __('nav_report_sale', 'Report Sale') ?></span>
                    </a>
                    <a class="nav-item flex items-center gap-2.5 px-3 py-2 rounded-lg text-xs font-medium transition-all hover:bg-[#1a1a20] hover:text-white <?= $_cur_page === 'report.php' ? ' active bg-[#1a1a20] text-[#d1904b] font-semibold' : 'text-[#a0a0ab]' ?>" href="report.php">
                        <i class="fa-solid fa-cube w-4 text-center"></i>
                        <span class="nav-label"><?= __('nav_report_product', 'Report Product') ?></span>
                    </a>
                    <?php endif; ?>

                    <?php if (can('ingredients') || can('report')): ?>
                    <a class="nav-item flex items-center gap-2.5 px-3 py-2 rounded-lg text-xs font-medium transition-all hover:bg-[#1a1a20] hover:text-white <?= $_cur_page === 'ingredient_report.php' ? ' active bg-[#1a1a20] text-[#d1904b] font-semibold' : 'text-[#a0a0ab]' ?>" href="ingredient_report.php">
                        <i class="fa-solid fa-boxes-stacked w-4 text-center"></i>
                        <span class="nav-label"><?= __('nav_inventory_report', 'Inventory') ?></span>
                    </a>
                    <?php endif; ?>

                    <?php if (can('employees') || can('report')): ?>
                    <a class="nav-item flex items-center gap-2.5 px-3 py-2 rounded-lg text-xs font-medium transition-all hover:bg-[#1a1a20] hover:text-white <?= $_cur_page === 'shift_report.php' ? ' active bg-[#1a1a20] text-[#d1904b] font-semibold' : 'text-[#a0a0ab]' ?>" href="shift_report.php">
                        <i class="fa-solid fa-user-tie w-4 text-center"></i>
                        <span class="nav-label"><?= __('nav_employee_report', 'Employee') ?></span>
                    </a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if (can('employees')): ?>
            <a class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium transition-all hover:bg-[#1a1a20] hover:text-white<?= $_cur_page === 'employees.php' ? ' active bg-[#1a1a20] text-[#d1904b] font-semibold' : '' ?>" href="employees.php">
                <i class="fa-solid fa-user-tie w-5 text-center"></i>
                <span class="nav-label"><?= __('nav_employees', 'Employees') ?></span>
            </a>
            <?php endif; ?>

            <?php if ($_is_admin || can('manage_users') || can('employees')): ?>
            <a class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium transition-all hover:bg-[#1a1a20] hover:text-white<?= $_cur_page === 'manage_admin.php' ? ' active bg-[#1a1a20] text-[#d1904b] font-semibold' : '' ?>" href="manage_admin.php">
                <i class="fa-solid fa-users-gear w-5 text-center"></i>
                <span class="nav-label"><?= __('nav_users', 'Users') ?></span>
            </a>
            <?php endif; ?>

            <?php if ($_is_admin || can('manage_roles')): ?>
            <a class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium transition-all hover:bg-[#1a1a20] hover:text-white<?= $_cur_page === 'manage_roles.php' ? ' active bg-[#1a1a20] text-[#d1904b] font-semibold' : '' ?>" href="manage_roles.php">
                <i class="fa-solid fa-shield-halved w-5 text-center"></i>
                <span class="nav-label"><?= __('nav_manage_roles', 'Manage Roles') ?></span>
            </a>
            <?php endif; ?>

            <?php if ($_is_admin || can('promotions') || can('settings')): ?>
            <a class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium transition-all hover:bg-[#1a1a20] hover:text-white<?= $_cur_page === 'settings.php' ? ' active bg-[#1a1a20] text-[#d1904b] font-semibold' : '' ?>" href="settings.php">
                <i class="fa-solid fa-gear w-5 text-center"></i>
                <span class="nav-label"><?= __('nav_settings', 'Settings') ?></span>
            </a>
            <?php endif; ?>

            <?php if (can('my_profile')): ?>
            <a class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium transition-all hover:bg-[#1a1a20] hover:text-white<?= $_cur_page === 'profile.php' ? ' active bg-[#1a1a20] text-[#d1904b] font-semibold' : '' ?>" href="profile.php">
                <i class="fa-solid fa-circle-user w-5 text-center"></i>
                <span class="nav-label"><?= __('nav_my_profile', 'My Profile') ?></span>
            </a>
            <?php endif; ?>

        </nav>
    </div>

    <!-- SIDEBAR FOOTER -->
    <div class="pt-3 border-t border-[#24242b] sidebar-footer flex flex-col gap-2">
        <!-- Language Switcher Button -->
        <a href="set_language.php?lang=<?= current_lang() === 'en' ? 'km' : 'en' ?>" class="nav-item flex items-center justify-between px-3 py-2 rounded-xl text-xs font-semibold text-[#a0a0ab] hover:bg-[#1a1a20] hover:text-white transition-all" title="Language Switcher">
            <div class="flex items-center gap-2">
                <i class="fa-solid fa-globe text-[#d1904b]"></i>
                <span class="lang-text-label"><?= __('language', 'Language') ?></span>
            </div>
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
function toggleSidebar() {
    const sidebar = document.getElementById('sidebar');
    if (!sidebar) return;
    sidebar.classList.remove('sidebar-no-anim');
    const isCollapsed = sidebar.classList.toggle('collapsed');
    document.documentElement.classList.toggle('sidebar-collapsed', isCollapsed);
    localStorage.setItem('sidebar_collapsed', isCollapsed ? 'true' : 'false');

    const toggleIcon = document.getElementById('sidebarToggleIcon');
    if (toggleIcon) {
        if (isCollapsed) {
            toggleIcon.classList.remove('fa-angles-left');
            toggleIcon.classList.add('fa-angles-right');
        } else {
            toggleIcon.classList.remove('fa-angles-right');
            toggleIcon.classList.add('fa-angles-left');
        }
    }
}

// Enable smooth transitions only after initial render completes
document.addEventListener('DOMContentLoaded', () => {
    const sidebar = document.getElementById('sidebar');
    if (sidebar) {
        setTimeout(() => {
            sidebar.classList.remove('sidebar-no-anim');
        }, 50);
    }
    const toggleIcon = document.getElementById('sidebarToggleIcon');
    if (toggleIcon && localStorage.getItem('sidebar_collapsed') === 'true') {
        toggleIcon.classList.remove('fa-angles-left');
        toggleIcon.classList.add('fa-angles-right');
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

document.addEventListener('DOMContentLoaded', () => {
    const sidebarNav = document.getElementById('sidebarNav');
    if (!sidebarNav) return;

    function setActiveNavTab(targetHref) {
        const page = targetHref ? targetHref.split('?')[0].split('/').pop() : (window.location.pathname.split('/').pop() || 'dashboard.php');
        sidebarNav.querySelectorAll('a.nav-item').forEach(el => {
            const itemHref = (el.getAttribute('href') || '').split('?')[0].split('/').pop();
            if (itemHref && itemHref === page) {
                el.classList.add('active', 'bg-[#1a1a20]', 'text-[#d1904b]', 'font-semibold');
            } else {
                el.classList.remove('active', 'bg-[#1a1a20]', 'text-[#d1904b]', 'font-semibold');
            }
        });

        const reportPages = ['daily_report.php', 'report.php', 'ingredient_report.php', 'shift_report.php'];
        const reportNavToggle = document.getElementById('reportNavToggle');
        const reportSubmenu = document.getElementById('reportSubmenu');
        const reportChevron = document.getElementById('reportChevron');
        if (reportPages.includes(page)) {
            if (reportNavToggle) reportNavToggle.classList.add('text-[#d1904b]', 'font-semibold', 'bg-[#1a1a20]');
            if (reportSubmenu) reportSubmenu.classList.remove('hidden');
            if (reportChevron) reportChevron.classList.add('rotate-180', 'text-[#d1904b]');
        }
    }

    // Initialize active tab on load
    setActiveNavTab();

    sidebarNav.addEventListener('click', (e) => {
        const link = e.target.closest('a.nav-item');
        if (!link) return;
        const href = link.getAttribute('href');
        if (!href || href === '#' || href.startsWith('logout.php')) return;

        setActiveNavTab(href);
        window.location.href = href;
    });

    window.addEventListener('popstate', () => {
        setActiveNavTab();
    });
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
