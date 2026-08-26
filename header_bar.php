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
    <div class="flex items-center gap-2">
        <!-- Language Switcher in Header Bar -->
        <a href="set_language.php?lang=<?= current_lang() === 'en' ? 'km' : 'en' ?>" 
           class="header-lang-btn"
           style="display: inline-flex; align-items: center; justify-content: center; gap: 6px; padding: 7px 12px; border-radius: 12px; background: var(--surface-2, rgba(255,255,255,0.06)); border: 1px solid var(--border-hi, rgba(255,255,255,0.12)); color: var(--text, #fff); text-decoration: none; font-size: 13px; font-weight: 600; cursor: pointer; transition: all 0.2s;"
           title="<?= current_lang() === 'en' ? 'Switch to ភាសាខ្មែរ' : 'Switch to English' ?>">
            <span style="font-size: 14px; line-height: 1;"><?= current_lang() === 'km' ? '🇰🇭' : '🇬🇧' ?></span>
            <span style="font-family: <?= current_lang() === 'km' ? "'Poppins', sans-serif" : "'Kantumruy Pro', 'Siemreap', sans-serif" ?>; font-size: 13px;"><?= current_lang() === 'km' ? 'ភាសាខ្មែរ' : 'English' ?></span>
        </a>
        <button type="button" 
                onclick="window.toggleTheme()" 
                class="theme-toggle-btn"
                style="display: inline-flex; align-items: center; justify-content: center; gap: 8px; padding: 7px 14px; border-radius: 12px; background: var(--surface-2, rgba(255,255,255,0.06)); border: 1px solid var(--border-hi, rgba(255,255,255,0.12)); color: var(--text, #fff); cursor: pointer; transition: all 0.2s; font-size: 13px; font-weight: 600;"
                title="Toggle Dark / Light Theme">
            <i class="fa-solid fa-moon" id="topThemeIcon"></i>
            <span id="topThemeText">Dark</span>
        </button>
    </div>
</div>

<style>
/* Header Bar Typography Guarantee for All Devices */
body, input, select, textarea, button, table {
    font-family: 'Poppins', 'Kantumruy Pro', 'Noto Sans Khmer', 'Siemreap', 'Khmer OS Battambang', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
    -webkit-font-smoothing: antialiased;
    -moz-osx-font-smoothing: grayscale;
}
:lang(km), [data-lang="km"], html[lang="km"], html[lang="km"] body, html[lang="km"] input, html[lang="km"] select, html[lang="km"] textarea, html[lang="km"] button, html[lang="km"] table, html[lang="km"] h1, html[lang="km"] h2, html[lang="km"] h3, html[lang="km"] h4, html[lang="km"] h5, html[lang="km"] h6, html[lang="km"] p, html[lang="km"] span, html[lang="km"] a {
    font-family: 'Kantumruy Pro', 'Noto Sans Khmer', 'Siemreap', 'Khmer OS Battambang', 'Khmer OS Siemreap', 'Poppins', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif !important;
}
</style>

<script>
if (typeof window.toggleTheme !== 'function') {
    window.toggleTheme = function() {
        var html = document.documentElement;
        var isLight = html.getAttribute('data-theme') === 'light';
        var nextTheme = isLight ? 'dark' : 'light';
        
        html.classList.add('theme-transitioning');
        html.setAttribute('data-theme', nextTheme);
        localStorage.setItem('theme', nextTheme);

        document.querySelectorAll('#topThemeIcon, #themeIcon').forEach(function(icon) {
            icon.className = nextTheme === 'light' ? 'fa-solid fa-sun' : 'fa-solid fa-moon';
        });
        document.querySelectorAll('#topThemeText, #themeText').forEach(function(txt) {
            txt.textContent = nextTheme === 'light' ? 'Light' : 'Dark';
        });
        if (typeof initCharts === 'function') initCharts();

        clearTimeout(window.__themeTransitionTimer);
        window.__themeTransitionTimer = setTimeout(function() {
            html.classList.remove('theme-transitioning');
        }, 450);
    };
}
document.addEventListener('DOMContentLoaded', function() {
    var theme = localStorage.getItem('theme') || (document.documentElement.getAttribute('data-theme') === 'light' ? 'light' : 'dark');
    if (theme === 'light') {
        document.documentElement.setAttribute('data-theme', 'light');
        document.querySelectorAll('#topThemeIcon, #themeIcon').forEach(function(icon) { icon.className = 'fa-solid fa-sun'; });
        document.querySelectorAll('#topThemeText, #themeText').forEach(function(txt) { txt.textContent = 'Light'; });
    }
});
</script>
