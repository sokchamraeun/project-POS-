<?php
// report_header.php - Shared Enterprise Report Header Component
if (!function_exists('can')) {
    require_once __DIR__ . '/auth.php';
}

$_cur_report_cat   = $report_category ?? 'Sales';
$_cur_report_title = $report_title    ?? 'Sales Report';
$_date_from        = $date_from       ?? date('Y-m-d');
$_date_to          = $date_to         ?? date('Y-m-d');
$_filters          = $filter_options  ?? [];
$_export_excel_url = $export_excel_url ?? '#';
$_export_pdf_url   = $export_pdf_url   ?? '#';
?>

<style>
/* Enterprise Report Layout Styles */
html, body {
    overflow-x: hidden !important;
    max-width: 100vw;
}
.app-layout {
    width: 100% !important;
    max-width: 100vw !important;
    overflow-x: hidden !important;
}
.er-container {
    padding: 1.25rem 1.5rem;
    background-color: var(--bg, #0e0e10);
    height: 100%;
    max-height: 100vh;
    display: flex;
    flex-direction: column;
    box-sizing: border-box;
    overflow: hidden !important;
    color: var(--text, #e0e0e0);
    font-family: 'Inter', system-ui, -apple-system, sans-serif;
}

/* Breadcrumb */
.er-breadcrumb {
    flex: 0 0 auto;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.9rem;
    font-weight: 500;
    color: #888899;
    margin-bottom: 1rem;
}
.er-breadcrumb a {
    color: #888899;
    text-decoration: none;
    transition: color 0.2s;
}
.er-breadcrumb a:hover {
    color: var(--accent, #d1904b);
}
.er-breadcrumb .er-sep {
    color: #555;
    font-size: 0.75rem;
}
.er-breadcrumb .er-current {
    color: var(--text, #ffffff);
    font-weight: 700;
}

/* Filter Bar Box */
.er-filter-card {
    flex: 0 0 auto;
    background: var(--surface, #15151a);
    border: 1px solid var(--border, #24242e);
    border-radius: 12px;
    padding: 1rem 1.25rem;
    margin-bottom: 1rem;
    box-shadow: 0 4px 16px rgba(0,0,0,0.15);
}
.er-filter-form {
    display: flex;
    flex-direction: column;
    gap: 0.85rem;
    width: 100%;
}
.er-filter-row {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-end;
    gap: 1rem;
    width: 100%;
}
.er-field-group {
    display: flex;
    flex-direction: column;
    gap: 0.35rem;
}
.er-field-group label {
    font-size: 0.78rem;
    font-weight: 700;
    color: #a0a0b2;
    text-transform: uppercase;
    letter-spacing: 0.04em;
}
.er-input, .er-select {
    background: var(--bg-card, #1a1a22);
    border: 1px solid var(--border, #2a2a36);
    color: var(--text, #ffffff);
    padding: 0.6rem 0.85rem;
    border-radius: 8px;
    font-size: 0.95rem;
    font-weight: 600;
    outline: none;
    transition: border-color 0.2s, box-shadow 0.2s;
    min-width: 150px;
    height: 42px;
}
.er-input[type="date"] {
    color-scheme: dark;
    font-size: 0.95rem;
    font-weight: 600;
}
.er-input[type="date"]::-webkit-calendar-picker-indicator {
    filter: brightness(0) invert(1);
    cursor: pointer;
    opacity: 1;
    width: 18px;
    height: 18px;
}
.er-input[type="date"]::-webkit-calendar-picker-indicator:hover {
    opacity: 0.8;
}
.er-input:focus, .er-select:focus {
    border-color: var(--accent, #d1904b);
    box-shadow: 0 0 0 2px rgba(209,144,75,0.25);
}
.er-btn-filter {
    background: #d1904b;
    color: #000;
    font-weight: 800;
    font-size: 0.95rem;
    padding: 0.6rem 1.75rem;
    border-radius: 8px;
    border: none;
    cursor: pointer;
    transition: all 0.2s;
    height: 42px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
}
.er-btn-filter:hover {
    background: #e5a15a;
    transform: translateY(-1px);
}

/* Action tools on the right */
.er-toolbar-actions {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    margin-left: auto;
}
.er-btn-icon {
    width: 36px;
    height: 36px;
    border-radius: 8px;
    background: var(--bg-card, #1a1a22);
    border: 1px solid var(--border, #2a2a36);
    color: var(--text, #ccc);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.2s;
    text-decoration: none;
}
.er-btn-icon:hover {
    background: #252532;
    color: var(--accent, #d1904b);
    border-color: var(--accent, #d1904b);
}
.er-search-box {
    position: relative;
}
.er-search-box input {
    padding-left: 2rem;
    min-width: 180px;
}
.er-search-box i {
    position: absolute;
    left: 0.75rem;
    top: 50%;
    transform: translateY(-50%);
    color: #666;
    font-size: 0.8rem;
}

/* Enterprise Data Table */
.er-table-card {
    flex: 1 1 0%;
    min-height: 0;
    display: flex;
    flex-direction: column;
    background: var(--surface, #15151a);
    border: 1px solid var(--border, #24242e);
    border-radius: 12px;
    overflow: hidden;
    margin-bottom: 1rem;
    box-shadow: 0 4px 16px rgba(0,0,0,0.15);
}
.er-table-wrap {
    flex: 1 1 0%;
    min-height: 0;
    overflow-x: auto;
    overflow-y: auto;
    width: 100%;
    scrollbar-width: thin;
    scrollbar-color: rgba(209,144,75,.4) transparent;
}
.er-table-wrap::-webkit-scrollbar {
    width: 6px;
    height: 6px;
}
.er-table-wrap::-webkit-scrollbar-track {
    background: transparent;
}
.er-table-wrap::-webkit-scrollbar-thumb {
    background: linear-gradient(180deg, var(--accent, #d1904b), rgba(209,144,75,.35));
    border-radius: 99px;
}
.er-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.85rem;
    text-align: left;
}
.er-table th {
    position: sticky;
    top: 0;
    z-index: 10;
    background: #1e2830;
    color: #92b4c0;
    font-weight: 700;
    font-size: 0.78rem;
    padding: 0.75rem 1rem;
    border-bottom: 1px solid var(--border, #282836);
    border-right: 1px solid rgba(255,255,255,0.05);
    white-space: nowrap;
    text-transform: uppercase;
    letter-spacing: 0.02em;
}
.er-table th:last-child {
    border-right: none;
}
.er-table td {
    padding: 0.75rem 1rem;
    border-bottom: 1px solid var(--border, #1f1f28);
    border-right: 1px solid rgba(255,255,255,0.03);
    color: var(--text, #d5d5e0);
}
.er-table td:last-child {
    border-right: none;
}
.er-table tbody tr:nth-child(even) {
    background-color: rgba(255,255,255,0.015);
}
.er-table tbody tr:hover {
    background-color: rgba(209,144,75,0.08);
}
.er-table .no-data {
    text-align: center;
    padding: 3rem 1rem;
    color: #777788;
    font-size: 1rem;
    font-weight: 500;
}

/* Light theme overrides matching app design system */
[data-theme="light"] .er-container {
    background-color: #f4efe9 !important;
    color: #1a1410 !important;
}
[data-theme="light"] .er-breadcrumb {
    color: #5a4a3a !important;
    font-weight: 400 !important;
}
[data-theme="light"] .er-breadcrumb a {
    color: #5a4a3a !important;
    font-weight: 400 !important;
}
[data-theme="light"] .er-breadcrumb .er-current {
    color: #1a1410 !important;
    font-weight: 500 !important;
}

[data-theme="light"] .er-filter-card,
[data-theme="light"] .er-table-card,
[data-theme="light"] .er-summary-card {
    background: #ffffff !important;
    border-color: #e0d4c4 !important;
    box-shadow: 0 4px 16px rgba(90,60,20,0.06) !important;
}
[data-theme="light"] .er-field-group label {
    color: #1a1410 !important;
    font-weight: 500 !important;
}
[data-theme="light"] .er-input,
[data-theme="light"] .er-select,
[data-theme="light"] .er-btn-icon,
[data-theme="light"] .er-btn-preset {
    background: #ede8e0 !important;
    border-color: #e0d4c4 !important;
    color: #1a1410 !important;
    font-weight: 400 !important;
}
[data-theme="light"] .er-input[type="date"] {
    color-scheme: light !important;
    color: #1a1410 !important;
    font-weight: 400 !important;
}
[data-theme="light"] .er-input[type="date"]::-webkit-calendar-picker-indicator {
    filter: none !important;
}
[data-theme="light"] .er-select option {
    background: #ffffff !important;
    color: #1a1410 !important;
    font-weight: 400 !important;
}
[data-theme="light"] .er-search-box i {
    color: #5a4a3a !important;
}
[data-theme="light"] .er-table th {
    background: #ede8e0 !important;
    color: #1a1410 !important;
    border-bottom: 2px solid #e0d4c4 !important;
    border-right: 1px solid #e0d4c4 !important;
    font-weight: 600 !important;
}
[data-theme="light"] .er-table td {
    border-bottom: 1px solid #f0e8e0 !important;
    border-right: 1px solid #f0e8e0 !important;
    color: #1a1410 !important;
    font-weight: 400 !important;
}

[data-theme="light"] .er-table td.font-bold,
[data-theme="light"] .er-table td.font-semibold,
[data-theme="light"] .er-table td .font-bold,
[data-theme="light"] .er-table td .font-semibold {
    color: #1a1410 !important;
    font-weight: 400 !important;
}

/* Default badge helper styles (Dark Mode) */
.er-badge-doc {
    display: inline-block;
    padding: 0.2rem 0.6rem;
    border-radius: 6px;
    font-size: 0.75rem;
    font-weight: 400;
    background: rgba(16, 185, 129, 0.12);
    border: 1px solid rgba(16, 185, 129, 0.25);
    color: #34d399;
}
.er-badge-cat {
    display: inline-block;
    padding: 0.2rem 0.6rem;
    border-radius: 6px;
    font-size: 0.75rem;
    font-weight: 400;
    background: rgba(51, 65, 85, 0.6);
    border: 1px solid rgba(148, 163, 184, 0.2);
    color: #cbd5e1;
}
.er-prod-name {
    color: #ffffff;
    font-weight: 400;
}
.er-badge-total {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    padding: 0.25rem 0.75rem;
    border-radius: 6px;
    background: rgba(209, 144, 75, 0.2);
    color: #d1904b;
    font-size: 0.8rem;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    border: 1px solid rgba(209, 144, 75, 0.35);
    font-weight: 500;
}
.er-qty-pill {
    display: inline-block;
    padding: 0.15rem 0.6rem;
    border-radius: 9999px;
    background: rgba(167, 139, 250, 0.18);
    color: #c084fc;
    font-weight: 400;
    font-size: 0.9rem;
    border: 1px solid rgba(167, 139, 250, 0.3);
}
.er-total-rev {
    color: #fbbf24;
    font-size: 1rem;
    font-weight: 500;
    text-shadow: 0 0 10px rgba(251, 191, 36, 0.25);
}

/* Light mode table badges & pills - Black/Dark text, clean light backgrounds */
[data-theme="light"] .er-badge-doc,
[data-theme="light"] .er-badge-cat,
[data-theme="light"] .er-badge-total,
[data-theme="light"] .er-qty-pill,
[data-theme="light"] .er-table td .bg-emerald-500\/10,
[data-theme="light"] .er-table td .bg-slate-700\/50,
[data-theme="light"] .er-table td .bg-slate-800,
[data-theme="light"] .er-table td .bg-amber-500\/15 {
    background: #ede8e0 !important;
    border: 1px solid #e0d4c4 !important;
    color: #1a1410 !important;
    font-weight: 400 !important;
}

[data-theme="light"] .er-prod-name {
    color: #1a1410 !important;
    font-weight: 400 !important;
}

[data-theme="light"] .er-total-rev {
    color: #1a1410 !important;
    font-weight: 500 !important;
    text-shadow: none !important;
}

[data-theme="light"] .er-table td .text-emerald-400,
[data-theme="light"] .er-table td .text-slate-300,
[data-theme="light"] .er-table td .text-slate-400,
[data-theme="light"] .er-table td .text-white,
[data-theme="light"] .er-table td .text-amber-400 {
    color: #1a1410 !important;
    font-weight: 400 !important;
}

/* Action View Button in Light Mode */
[data-theme="light"] .er-table td button,
[data-theme="light"] .er-table td a.er-btn-preset,
[data-theme="light"] .er-table td .bg-amber-500\/10 {
    background: #ede8e0 !important;
    border-color: #d1904b !important;
    color: #1a1410 !important;
    font-weight: 400 !important;
}
[data-theme="light"] .er-table td button:hover,
[data-theme="light"] .er-table td a.er-btn-preset:hover {
    background: #d1904b !important;
    color: #ffffff !important;
}

[data-theme="light"] .er-table tbody tr:nth-child(even) {
    background-color: #fdfaf6 !important;
}
[data-theme="light"] .er-table tbody tr:hover {
    background-color: rgba(209,144,75,0.12) !important;
}

/* Total summary row in Light Mode */
[data-theme="light"] .er-table tr.total-summary-row {
    background: #fcf8f3 !important;
    border-top: 2px solid #d1904b !important;
    border-bottom: 2px solid #e0d4c4 !important;
}

[data-theme="light"] .er-table tr.total-summary-row td {
    color: #1a1410 !important;
    font-weight: 500 !important;
}

[data-theme="light"] .er-table tr.total-summary-row span {
    background: #f4efe9 !important;
    border-color: #e0d4c4 !important;
    color: #1a1410 !important;
    font-weight: 500 !important;
}

/* Bottom Summary Card (Footer Info & Stats) */
[data-theme="light"] .er-summary-card {
    background: #ffffff !important;
    border-color: #e0d4c4 !important;
}

[data-theme="light"] .er-summary-info {
    color: #1a1410 !important;
    font-weight: 400 !important;
}

[data-theme="light"] .er-summary-info span {
    color: #1a1410 !important;
    font-weight: 400 !important;
}

[data-theme="light"] .er-summary-info span strong {
    color: #1a1410 !important;
    font-weight: 500 !important;
}

[data-theme="light"] .stat-label {
    color: #1a1410 !important;
    font-weight: 500 !important;
}

[data-theme="light"] .stat-val,
[data-theme="light"] .stat-val.text-amber-400,
[data-theme="light"] .stat-val.text-emerald-400 {
    color: #d1904b !important;
    font-weight: 600 !important;
}

[data-theme="light"] .er-btn-preset:hover {
    background: rgba(209,144,75,0.15) !important;
    border-color: #d1904b !important;
    color: #d1904b !important;
}

/* Summary Box Footer */
.er-summary-card {
    flex: 0 0 auto;
    background: var(--surface, #15151a);
    border: 1px solid var(--border, #24242e);
    border-radius: 12px;
    padding: 1rem 1.25rem;
    font-size: 0.85rem;
    display: flex;
    flex-wrap: wrap;
    justify-content: space-between;
    align-items: center;
    gap: 1.5rem;
}
.er-summary-info {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
    color: #a0a0b0;
}
.er-summary-info span strong {
    color: var(--text, #fff);
    font-weight: 600;
}
.er-summary-stats {
    display: flex;
    align-items: center;
    gap: 2rem;
}
.er-summary-stat-item {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
}
.er-summary-stat-item .stat-label {
    font-size: 0.72rem;
    text-transform: uppercase;
    color: #888899;
    letter-spacing: 0.04em;
}
.er-summary-stat-item .stat-val {
    font-size: 1.2rem;
    font-weight: 700;
    color: var(--accent, #d1904b);
}

.er-btn-preset {
    background: var(--bg-card, #1a1a22);
    border: 1px solid var(--border, #2a2a36);
    color: var(--text, #ffffff);
    padding: 0.5rem 0.85rem;
    border-radius: 8px;
    font-size: 0.82rem;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.2s;
    height: 42px;
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    white-space: nowrap;
}
.er-btn-preset:hover {
    background: #252532;
    border-color: var(--accent, #d1904b);
    color: var(--accent, #d1904b);
    transform: translateY(-1px);
}
</style>

<!-- Breadcrumb & Top Actions -->
<div class="er-breadcrumb flex items-center justify-between gap-4" style="display:flex;align-items:center;justify-content:space-between;width:100%;">
    <div style="display:flex;align-items:center;gap:0.5rem;">
        <a href="dashboard.php"><?= __('nav_report', 'Reports') ?></a>
        <i class="fa-solid fa-chevron-right er-sep"></i>
        <span><?= htmlspecialchars($_cur_report_cat) ?></span>
        <i class="fa-solid fa-chevron-right er-sep"></i>
        <span class="er-current"><?= htmlspecialchars($_cur_report_title) ?></span>
    </div>
</div>

<!-- Filter Bar -->
<div class="er-filter-card">
    <form method="get" class="er-filter-form" id="erFilterForm">
        <?php
        $_isKm_hdr = function_exists('current_lang') && current_lang() === 'km';
        $_hdr_months = [
            1  => ['en' => 'January',   'km' => 'មករា'],
            2  => ['en' => 'February',  'km' => 'កុម្ភៈ'],
            3  => ['en' => 'March',     'km' => 'មីនា'],
            4  => ['en' => 'April',     'km' => 'មេសា'],
            5  => ['en' => 'May',       'km' => 'ឧសភា'],
            6  => ['en' => 'June',      'km' => 'មិថុនា'],
            7  => ['en' => 'July',      'km' => 'កក្កដា'],
            8  => ['en' => 'August',    'km' => 'សីហា'],
            9  => ['en' => 'September', 'km' => 'កញ្ញា'],
            10 => ['en' => 'October',   'km' => 'តុលា'],
            11 => ['en' => 'November',  'km' => 'វិច្ឆិកា'],
            12 => ['en' => 'December',  'km' => 'ធ្នូ'],
        ];
        ?>
        <!-- All Filters & Actions in 1 Row -->
        <div class="er-filter-row">
            <div class="er-field-group">
                <label><?= $_isKm_hdr ? 'ចាប់ពីថ្ងៃ' : 'Date From' ?></label>
                <input type="date" name="from_date" value="<?= htmlspecialchars($_date_from) ?>" class="er-input">
            </div>

            <div class="er-field-group">
                <label><?= $_isKm_hdr ? 'ដល់ថ្ងៃ' : 'Date To' ?></label>
                <input type="date" name="to_date" value="<?= htmlspecialchars($_date_to) ?>" class="er-input">
            </div>

            <?php
            $_quick_range_val = trim($_GET['quick_range'] ?? '');
            $_select_month_val = trim($_GET['select_month'] ?? '');
            ?>
            <div class="er-field-group">
                <label><?= $_isKm_hdr ? 'ជ្រើសរើសខែ' : 'Select Month' ?></label>
                <select id="erMonthSelect" name="select_month" class="er-select" onchange="applyMonthPreset(this.value)">
                    <option value=""><?= $_isKm_hdr ? '-- គ្រប់ខែ --' : '-- All Months --' ?></option>
                    <?php foreach ($_hdr_months as $num => $m): ?>
                    <option value="<?= $num ?>" <?= (string)$_select_month_val === (string)$num ? 'selected' : '' ?>><?= htmlspecialchars($_isKm_hdr ? $m['km'] : $m['en']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="er-field-group">
                <label><?= $_isKm_hdr ? 'កាលបរិច្ឆេទលឿន' : 'Quick Range' ?></label>
                <select id="erQuickPeriodSelect" name="quick_range" class="er-select" onchange="applyQuickPreset(this.value)">
                    <option value=""><?= $_isKm_hdr ? '-- ជ្រើសរើស --' : '-- Quick Range --' ?></option>
                    <option value="today" <?= $_quick_range_val === 'today' ? 'selected' : '' ?>><?= $_isKm_hdr ? 'ថ្ងៃនេះ (Today)' : 'Today' ?></option>
                    <option value="month" <?= $_quick_range_val === 'month' ? 'selected' : '' ?>><?= $_isKm_hdr ? 'ខែនេះ (This Month)' : 'This Month' ?></option>
                    <option value="year" <?= $_quick_range_val === 'year' ? 'selected' : '' ?>><?= $_isKm_hdr ? 'ឆ្នាំនេះ (This Year)' : 'This Year' ?></option>
                </select>
            </div>

            <?php foreach ($_filters as $f): ?>
            <div class="er-field-group">
                <label><?= htmlspecialchars($f['label']) ?></label>
                <select name="<?= htmlspecialchars($f['name']) ?>" class="er-select">
                    <?php foreach ($f['options'] as $val => $text): ?>
                    <option value="<?= htmlspecialchars($val) ?>" <?= (string)($f['selected'] ?? '') === (string)$val ? 'selected' : '' ?>>
                        <?= htmlspecialchars($text) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endforeach; ?>

            <div class="er-field-group" style="justify-content: flex-end;">
                <button type="submit" class="er-btn-filter">
                    <i class="fa-solid fa-filter"></i> <?= $_isKm_hdr ? 'តម្រង' : 'Filter' ?>
                </button>
            </div>

            <div class="er-toolbar-actions">
                <button type="button" class="er-btn-icon" onclick="window.location.reload();" title="Refresh">
                    <i class="fa-solid fa-arrows-rotate"></i>
                </button>
                <?php if ($_export_pdf_url !== '#'): ?>
                <a href="<?= htmlspecialchars($_export_pdf_url) ?>" target="_blank" rel="noopener" class="er-btn-icon" title="Print PDF">
                    <i class="fa-solid fa-print text-amber-400"></i>
                </a>
                <?php endif; ?>
                <div class="er-search-box">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <input type="text" id="erTableSearch" placeholder="Search..." class="er-input">
                </div>
            </div>
        </div>
    </form>
</div>

<script>
function applyMonthPreset(monthNum) {
    if (!monthNum) return;
    const m = parseInt(monthNum, 10);
    if (isNaN(m) || m < 1 || m > 12) return;

    const today = new Date();
    const yyyy = today.getFullYear();
    const mmStr = String(m).padStart(2, '0');
    const fromStr = `${yyyy}-${mmStr}-01`;

    const lastDayObj = new Date(yyyy, m, 0);
    const lastDd = String(lastDayObj.getDate()).padStart(2, '0');
    let toStr = `${yyyy}-${mmStr}-${lastDd}`;

    const form = document.getElementById('erFilterForm');
    if (!form) return;
    let fromInput = form.querySelector('input[name="from_date"]') || form.querySelector('input[name="date_from"]');
    let toInput   = form.querySelector('input[name="to_date"]')   || form.querySelector('input[name="date_to"]');
    let quickInput = form.querySelector('select[name="quick_range"]');
    if (quickInput) quickInput.value = '';

    if (fromInput) fromInput.value = fromStr;
    if (toInput)   toInput.value   = toStr;

    form.submit();
}

function applyQuickPreset(period) {
    if (!period) return;
    const today = new Date();
    const yyyy = today.getFullYear();
    const mm = String(today.getMonth() + 1).padStart(2, '0');
    const dd = String(today.getDate()).padStart(2, '0');
    const todayStr = `${yyyy}-${mm}-${dd}`;

    let fromStr = todayStr;
    let toStr = todayStr;

    if (period === 'today') {
        fromStr = todayStr;
        toStr = todayStr;
    } else if (period === 'month') {
        fromStr = `${yyyy}-${mm}-01`;
        toStr = todayStr;
    } else if (period === 'year') {
        fromStr = `${yyyy}-01-01`;
        toStr = todayStr;
    }

    const form = document.getElementById('erFilterForm');
    if (!form) return;
    let fromInput = form.querySelector('input[name="from_date"]') || form.querySelector('input[name="date_from"]');
    let toInput   = form.querySelector('input[name="to_date"]')   || form.querySelector('input[name="date_to"]');
    let monthInput = form.querySelector('select[name="select_month"]');
    if (monthInput) monthInput.value = '';

    if (fromInput) fromInput.value = fromStr;
    if (toInput)   toInput.value   = toStr;

    form.submit();
}

document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('erFilterForm');
    if (form) {
        const fromInput = form.querySelector('input[name="from_date"]') || form.querySelector('input[name="date_from"]');
        const toInput   = form.querySelector('input[name="to_date"]')   || form.querySelector('input[name="date_to"]');
        const monthSelect = document.getElementById('erMonthSelect');
        const quickSelect = document.getElementById('erQuickPeriodSelect');

        if (fromInput && fromInput.value) {
            const fVal = fromInput.value;
            const tVal = toInput ? toInput.value : '';
            const today = new Date();
            const yyyy = today.getFullYear();
            const mm = String(today.getMonth() + 1).padStart(2, '0');
            const dd = String(today.getDate()).padStart(2, '0');
            const todayStr = `${yyyy}-${mm}-${dd}`;

            if (monthSelect && !monthSelect.value) {
                const parts = fVal.split('-');
                if (parts.length === 3 && parts[2] === '01') {
                    const m = parseInt(parts[1], 10);
                    if (m >= 1 && m <= 12) {
                        monthSelect.value = m;
                    }
                }
            }

            if (quickSelect && !quickSelect.value) {
                if (fVal === todayStr && (tVal === todayStr || !tVal)) {
                    quickSelect.value = 'today';
                } else if (fVal === `${yyyy}-${mm}-01`) {
                    quickSelect.value = 'month';
                } else if (fVal === `${yyyy}-01-01`) {
                    quickSelect.value = 'year';
                }
            }
        }
    }
    const searchInput = document.getElementById('erTableSearch');
    if (!searchInput) return;
    searchInput.addEventListener('input', (e) => {
        const val = e.target.value.toLowerCase().trim();
        const rows = document.querySelectorAll('.er-table tbody tr');
        rows.forEach(tr => {
            if (tr.classList.contains('no-data')) return;
            const text = tr.innerText.toLowerCase();
            tr.style.display = text.includes(val) ? '' : 'none';
        });
    });
});
</script>

<script>
if (typeof window.toggleTheme !== 'function') {
    window.toggleTheme = function() {
        var html = document.documentElement;
        var isLight = html.getAttribute('data-theme') === 'light';
        var nextTheme = isLight ? 'dark' : 'light';
        html.setAttribute('data-theme', nextTheme);
        localStorage.setItem('theme', nextTheme);

        document.querySelectorAll('#topThemeIcon, #themeIcon').forEach(function(icon) {
            icon.className = nextTheme === 'light' ? 'fa-solid fa-sun' : 'fa-solid fa-moon';
        });
        document.querySelectorAll('#topThemeText, #themeText').forEach(function(txt) {
            txt.textContent = nextTheme === 'light' ? 'Light' : 'Dark';
        });
        if (typeof initCharts === 'function') initCharts();
    };
}
(function() {
    var theme = localStorage.getItem('theme') || (document.documentElement.getAttribute('data-theme') === 'light' ? 'light' : 'dark');
    if (theme === 'light') {
        document.documentElement.setAttribute('data-theme', 'light');
    }
})();
document.addEventListener('DOMContentLoaded', function() {
    var theme = localStorage.getItem('theme') || (document.documentElement.getAttribute('data-theme') === 'light' ? 'light' : 'dark');
    if (theme === 'light') {
        document.documentElement.setAttribute('data-theme', 'light');
        document.querySelectorAll('#topThemeIcon, #themeIcon').forEach(function(icon) { icon.className = 'fa-solid fa-sun'; });
        document.querySelectorAll('#topThemeText, #themeText').forEach(function(txt) { txt.textContent = 'Light'; });
    }
});
</script>
