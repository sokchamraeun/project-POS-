<?php
// header_bar.php - Standard Top Bar Component for All Pages (Title + Language Switcher + Dark/Light Theme Toggle)
if (!function_exists('__')) {
    require_once __DIR__ . '/lang.php';
}

$_page_title = $page_title ?? '';
$_page_subtitle = $page_subtitle ?? '';
?>
<div class="flex items-center justify-between gap-4 mb-6 pb-2 border-b border-[#1f1f24] header-bar" style="border-bottom: 1px solid var(--border, #1f1f24); padding-bottom: 12px; margin-bottom: 20px;">
    <div class="flex items-center gap-3">
        <button type="button" 
                onclick="toggleSidebar()" 
                class="sidebar-toggle-btn"
                style="display: inline-flex; align-items: center; justify-content: center; width: 36px; height: 36px; border-radius: 10px; background: var(--surface-2, rgba(255,255,255,0.06)); border: 1px solid var(--border-hi, rgba(255,255,255,0.12)); color: var(--text, #fff); cursor: pointer; transition: all 0.2s;"
                title="Toggle Sidebar (Open/Close)">
            <i class="fa-solid fa-bars" style="font-size: 15px;"></i>
        </button>
        <div>
            <?php if ($_page_title !== ''): ?>
            <h1 class="text-xl font-bold text-white flex items-center gap-2" style="font-size: 20px; font-weight: 700; color: var(--text, #fff); margin: 0;">
                <?= htmlspecialchars($_page_title) ?>
            </h1>
            <?php endif; ?>
            <?php if ($_page_subtitle !== ''): ?>
            <p class="text-xs text-[#888899] mt-0.5" style="font-size: 12px; color: var(--text-muted, #888899); margin-top: 2px;">
                <?= htmlspecialchars($_page_subtitle) ?>
            </p>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Top Right Actions: Theme Toggle -->
    <div class="flex items-center gap-2.5 header-actions" style="display: flex; align-items: center; gap: 10px;">

        <!-- Theme Toggle -->
        <button type="button" 
                onclick="toggleTheme()" 
                class="top-theme-toggle"
                style="display: inline-flex; align-items: center; gap: 6px; padding: 7px 14px; border-radius: 10px; font-size: 12px; font-weight: 600; background: var(--surface-2, rgba(255,255,255,0.06)); border: 1px solid var(--border-hi, rgba(255,255,255,0.12)); color: var(--text, #fff); cursor: pointer; transition: all 0.2s;"
                title="Toggle Theme">
            <i class="fa-solid fa-moon" id="topThemeIcon"></i>
            <span id="topThemeText"><?= __('dark_mode', 'Dark') ?></span>
        </button>
    </div>
</div>
