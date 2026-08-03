<?php
require 'auth.php';
require 'config.php';

// Same gate as stock_count.php — whoever may run a count may review past ones.
if (!can('stock_count')) { header("Location: dashboard.php?denied=1"); exit; }

$filter_from = trim($_GET['from'] ?? '');
$filter_to   = trim($_GET['to']   ?? date('Y-m-d'));
if ($filter_from === '') $filter_from = date('Y-m-d', strtotime('-30 days'));
$filter_status = in_array($_GET['status'] ?? '', ['draft','submitted'], true) ? $_GET['status'] : '';

// The stock-count tables are created by stock_count.php on first use, so a fresh
// install can reach this page before they exist. Degrade to an empty state.
$sc_rows = [];
$box = ['total_items' => 0, 'counted' => 0, 'matched' => 0, 'shortage' => 0, 'overage' => 0];
$tables_ready = (int)$conn->query(
    "SELECT COUNT(*) FROM information_schema.tables
      WHERE table_schema = DATABASE() AND table_name IN ('stock_counts','stock_count_items')"
)->fetch_row()[0] === 2;

if ($tables_ready) {
    $status_sql = $filter_status !== '' ? ' AND sc.status = ?' : '';

    $sql = "
        SELECT
            sc.count_id, sc.business_date, sc.status, sc.submitted_by, sc.submitted_at,
            sc.reconciled_at, sc.reconciled_by, sc.notes,
            COUNT(sci.item_id)                                              AS total_items,
            SUM(sci.actual_qty IS NOT NULL)                                 AS counted_items,
            SUM(sci.variance < -0.01)                                       AS shortage_items,
            SUM(sci.variance >  0.01)                                       AS overage_items,
            SUM(CASE WHEN sci.variance < -0.01 THEN sci.variance ELSE 0 END) AS total_shortage,
            SUM(CASE WHEN sci.variance >  0.01 THEN sci.variance ELSE 0 END) AS total_overage
        FROM stock_counts sc
        LEFT JOIN stock_count_items sci ON sci.count_id = sc.count_id
        WHERE sc.business_date BETWEEN ? AND ?$status_sql
        GROUP BY sc.count_id
        ORDER BY sc.business_date DESC
    ";
    $stmt = $conn->prepare($sql);
    if ($filter_status !== '') $stmt->bind_param("sss", $filter_from, $filter_to, $filter_status);
    else                       $stmt->bind_param("ss",  $filter_from, $filter_to);
    $stmt->execute();
    $sc_rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    /* Stat boxes: line items across every session in range.
       The headline is items COUNTED, not items in existence, so the four boxes
       reconcile — counted = matched + shortage + overage. "Matched" requires the item
       to have actually been counted; an uncounted row has a NULL variance and must not
       be scored as agreeing with the system. */
    $bsql = "
        SELECT
            COUNT(sci.item_id)                                                        AS total_items,
            SUM(sci.actual_qty IS NOT NULL)                                            AS counted,
            SUM(sci.actual_qty IS NOT NULL AND ABS(sci.variance) <= 0.01)              AS matched,
            SUM(sci.variance < -0.01)                                                  AS shortage,
            SUM(sci.variance >  0.01)                                                  AS overage
        FROM stock_counts sc
        JOIN stock_count_items sci ON sci.count_id = sc.count_id
        WHERE sc.business_date BETWEEN ? AND ?$status_sql
    ";
    $bs = $conn->prepare($bsql);
    if ($filter_status !== '') $bs->bind_param("sss", $filter_from, $filter_to, $filter_status);
    else                       $bs->bind_param("ss",  $filter_from, $filter_to);
    $bs->execute();
    $brow = $bs->get_result()->fetch_assoc() ?: [];
    foreach ($box as $k => $_) $box[$k] = (int)($brow[$k] ?? 0);
}

$session_count = count($sc_rows);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Inventory Count | Bird's Nest Coffee</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script>(function(){try{if(localStorage.getItem("theme")==="light")document.documentElement.setAttribute("data-theme","light");}catch(e){}})();</script>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#0a0a0a;--surface:#111;--surface2:#161616;--border:rgba(255,255,255,.07);
  --amber:#d1904b;--amber-dim:rgba(209,144,75,.12);--amber-border:rgba(209,144,75,.2);
  --green:#22c55e;--green-dim:rgba(34,197,94,.1);--green-border:rgba(34,197,94,.2);
  --red:#ef4444;--red-dim:rgba(239,68,68,.1);--red-border:rgba(239,68,68,.2);
  --yellow:#f59e0b;--yellow-dim:rgba(245,158,11,.1);--yellow-border:rgba(245,158,11,.2);
  --blue:#3b82f6;--blue-dim:rgba(59,130,246,.1);--blue-border:rgba(59,130,246,.2);
  --text:#f0f0f0;--muted:#555;--muted2:#888;
  --radius:14px;
}
[data-theme="light"]{
  --bg:#F4F1EC;--surface:#FFFFFF;--surface2:#FAF8F5;--border:rgba(0,0,0,.09);
  --text:#1a1410;--muted:#9a8f84;--muted2:#6b6259;
}
@keyframes fadeInUp{from{opacity:0;transform:translateY(20px)}to{opacity:1;transform:translateY(0)}}
@keyframes scaleIn{from{opacity:0;transform:scale(.94)}to{opacity:1;transform:scale(1)}}

body{
  font-family:'Poppins',sans-serif;
  background:radial-gradient(ellipse 80% 40% at 50% 0%,rgba(209,144,75,.07) 0%,transparent 100%),var(--bg);
  color:var(--text);min-height:100vh;
}
[data-theme="light"] body{background:var(--bg);}

/* ── Top bar ── */
.topbar{
  display:flex;align-items:center;justify-content:space-between;
  padding:16px 28px;background:rgba(255,255,255,.02);
  border-bottom:1px solid var(--border);
}
[data-theme="light"] .topbar{background:#fff;}
.topbar-left{display:flex;align-items:center;gap:18px}
.back-btn{
  display:inline-flex;align-items:center;gap:7px;text-decoration:none;color:var(--amber);
  font-size:13px;font-weight:600;padding:7px 14px;border-radius:10px;
  border:1px solid var(--amber-border);background:var(--amber-dim);transition:all .2s;
}
.back-btn:hover{background:rgba(209,144,75,.2)}
.page-title{font-size:18px;font-weight:700;color:var(--text)}
.page-sub{font-size:12px;color:var(--muted2);margin-top:2px}

.wrap{max-width:1180px;margin:0 auto;padding:24px 20px 60px}

/* ── Stat boxes ── */
.cards{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:20px}
@media(max-width:820px){.cards{grid-template-columns:repeat(2,1fr)}}
.card{
  background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);
  padding:18px 20px;position:relative;overflow:hidden;animation:scaleIn .4s ease both;
}
.card:nth-child(2){animation-delay:.05s}
.card:nth-child(3){animation-delay:.1s}
.card:nth-child(4){animation-delay:.15s}
.card-icon{font-size:15px;margin-bottom:10px;opacity:.9}
.card-val{font-size:30px;font-weight:700;line-height:1}
.card-label{font-size:11px;color:var(--muted2);text-transform:uppercase;letter-spacing:.6px;margin-top:6px}
.card-of{font-size:15px;font-weight:600;color:var(--muted2);margin-left:6px}
.card.blue  {background:var(--blue-dim);  border-color:var(--blue-border)}
.card.blue   .card-icon,.card.blue   .card-val{color:var(--blue)}
.card.green {background:var(--green-dim); border-color:var(--green-border)}
.card.green  .card-icon,.card.green  .card-val{color:var(--green)}
.card.red   {background:var(--red-dim);   border-color:var(--red-border)}
.card.red    .card-icon,.card.red    .card-val{color:var(--red)}
.card.yellow{background:var(--yellow-dim);border-color:var(--yellow-border)}
.card.yellow .card-icon,.card.yellow .card-val{color:var(--yellow)}

/* ── Filter bar ── */
.filter-bar{
  display:flex;flex-wrap:wrap;align-items:flex-end;gap:14px;
  background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);
  padding:16px 18px;margin-bottom:18px;
}
.filter-group{display:flex;flex-direction:column;gap:5px}
.filter-group label{font-size:11px;color:var(--muted2);text-transform:uppercase;letter-spacing:.5px;font-weight:600}
.filter-group input,.filter-group select{
  background:var(--surface2);border:1px solid var(--border);border-radius:9px;
  padding:9px 12px;color:var(--text);font-family:'Poppins',sans-serif;font-size:13px;outline:none;
}
.filter-group input:focus,.filter-group select:focus{border-color:var(--amber)}
[data-theme="light"] select option{background:#fff}
.btn-filter,.btn-reset{
  display:inline-flex;align-items:center;gap:7px;height:37px;padding:0 16px;border-radius:9px;
  font-size:13px;font-weight:600;cursor:pointer;text-decoration:none;font-family:'Poppins',sans-serif;
}
.btn-filter{background:var(--amber);border:none;color:#000}
.btn-reset{background:transparent;border:1px solid var(--border);color:var(--muted2)}
.btn-reset:hover{color:var(--text);border-color:var(--amber)}

/* ── Session table ── */
.inv-section{
  background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);
  overflow:hidden;animation:fadeInUp .45s ease both;animation-delay:.1s;
}
.inv-header{
  display:flex;align-items:center;justify-content:space-between;gap:12px;
  padding:18px 20px;border-bottom:1px solid var(--border);
}
.inv-title{font-size:13px;font-weight:600;color:var(--muted2);text-transform:uppercase;letter-spacing:.5px;display:flex;align-items:center;gap:8px}
.inv-new-btn{
  display:inline-flex;align-items:center;gap:7px;padding:8px 14px;border-radius:9px;
  background:var(--amber-dim);border:1px solid var(--amber-border);color:var(--amber);
  font-size:12.5px;font-weight:600;text-decoration:none;transition:all .2s;
}
.inv-new-btn:hover{background:rgba(209,144,75,.2)}
.inv-view-btn{
  display:inline-flex;align-items:center;gap:6px;padding:6px 12px;border-radius:8px;
  background:rgba(255,255,255,.05);border:1px solid var(--border);color:var(--muted2);
  font-size:12px;font-weight:600;text-decoration:none;transition:all .2s;
}
.inv-view-btn:hover{color:var(--text);background:rgba(255,255,255,.09)}
table{width:100%;border-collapse:collapse}
th{
  text-align:left;padding:11px 14px;font-size:11px;font-weight:600;color:var(--muted2);
  text-transform:uppercase;letter-spacing:.5px;border-bottom:1px solid var(--border);
  background:rgba(255,255,255,.02);
}
td{padding:13px 14px;font-size:13px;border-bottom:1px solid var(--border);color:var(--text)}
tbody tr:last-child td{border-bottom:none}
tbody tr:hover{background:rgba(255,255,255,.02)}
tr.row-short{box-shadow:inset 3px 0 0 var(--red)}
tr.row-over {box-shadow:inset 3px 0 0 var(--yellow)}
tr.row-match{box-shadow:inset 3px 0 0 var(--green)}
.badge{display:inline-flex;align-items:center;gap:5px;padding:3px 9px;border-radius:20px;font-size:11px;font-weight:600}
.badge.match{background:var(--green-dim);color:var(--green);border:1px solid var(--green-border)}
.badge.short{background:var(--red-dim);color:var(--red);border:1px solid var(--red-border)}
.badge.over {background:var(--yellow-dim);color:var(--yellow);border:1px solid var(--yellow-border)}
.empty{padding:48px 20px;text-align:center;color:var(--muted2)}
.empty i{font-size:38px;opacity:.25;margin-bottom:14px;display:block}
.empty h3{font-size:15px;font-weight:600;color:var(--text);margin-bottom:6px}
.empty p{font-size:13px}

/* ── Pagination ── */
.sc-pg{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;padding:14px 18px;border-top:1px solid var(--border)}
.sc-pg .info{font-size:12px;color:var(--muted2)}
.sc-pg nav{display:flex;gap:4px;flex-wrap:wrap}
.sc-pgb{display:inline-flex;align-items:center;justify-content:center;min-width:32px;height:32px;padding:0 6px;border-radius:8px;border:1px solid var(--border);background:transparent;color:var(--muted2);font-size:13px;font-weight:600;text-decoration:none;cursor:pointer}
.sc-pgb:hover{border-color:var(--amber);color:var(--amber)}
.sc-pgb.act{background:var(--amber);border-color:var(--amber);color:#000;font-weight:700}
.sc-pgb.dis{opacity:.35;cursor:default}
.sc-pge{display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;color:var(--muted2);font-size:13px}
</style>
</head>
<body>

<div class="topbar">
    <div class="topbar-left">
        <a href="dashboard.php" class="back-btn"><i class="fa-solid fa-arrow-left"></i> Dashboard</a>
        <div>
            <div class="page-title"><i class="fa-solid fa-clipboard-list" style="color:var(--amber);margin-right:8px"></i>Inventory Count</div>
            <div class="page-sub">Stock count sessions and their variances</div>
        </div>
    </div>
    <div style="font-size:12px;color:var(--muted)"><?= date('d M Y') ?></div>
</div>

<div class="wrap">

    <!-- Stat boxes: line items across every session in the filtered range -->
    <div class="cards">
        <div class="card blue">
            <div class="card-icon"><i class="fa-solid fa-layer-group"></i></div>
            <div class="card-val"><?= number_format($box['counted']) ?><span class="card-of">/ <?= number_format($box['total_items']) ?></span></div>
            <div class="card-label">Items Counted</div>
        </div>
        <div class="card green">
            <div class="card-icon"><i class="fa-solid fa-circle-check"></i></div>
            <div class="card-val"><?= number_format($box['matched']) ?></div>
            <div class="card-label">Matched</div>
        </div>
        <div class="card red">
            <div class="card-icon"><i class="fa-solid fa-arrow-trend-down"></i></div>
            <div class="card-val"><?= number_format($box['shortage']) ?></div>
            <div class="card-label">Shortage</div>
        </div>
        <div class="card yellow">
            <div class="card-icon"><i class="fa-solid fa-arrow-trend-up"></i></div>
            <div class="card-val"><?= number_format($box['overage']) ?></div>
            <div class="card-label">Overage</div>
        </div>
    </div>

    <!-- Filter bar -->
    <form method="GET" class="filter-bar">
        <div class="filter-group">
            <label>Start Date</label>
            <input type="date" name="from" value="<?= htmlspecialchars($filter_from) ?>">
        </div>
        <div class="filter-group">
            <label>End Date</label>
            <input type="date" name="to" value="<?= htmlspecialchars($filter_to) ?>">
        </div>
        <div class="filter-group">
            <label>Status</label>
            <select name="status">
                <option value="">All statuses</option>
                <option value="draft"     <?= $filter_status === 'draft'     ? 'selected' : '' ?>>Draft</option>
                <option value="submitted" <?= $filter_status === 'submitted' ? 'selected' : '' ?>>Submitted</option>
            </select>
        </div>
        <button type="submit" class="btn-filter"><i class="fa-solid fa-magnifying-glass"></i> Filter</button>
        <a href="inventory_count.php" class="btn-reset"><i class="fa-solid fa-rotate-left"></i> Reset</a>
    </form>

    <div class="inv-section">
        <div class="inv-header">
            <div class="inv-title">
                <i class="fa-solid fa-clipboard-list" style="color:var(--amber)"></i>
                Inventory Stock Counts
            </div>
            <a href="stock_count.php" class="inv-new-btn">
                <i class="fa-solid fa-plus"></i> New Count
            </a>
        </div>

        <?php if (!$tables_ready): ?>
        <div class="empty">
            <i class="fa-solid fa-clipboard-list"></i>
            <h3>Stock counting not set up yet</h3>
            <p>Open the Stock Count page once to create the first count session.</p>
        </div>
        <?php elseif (empty($sc_rows)): ?>
        <div class="empty">
            <i class="fa-solid fa-clipboard-list"></i>
            <h3>No stock counts in this period</h3>
            <p>Counts submitted via the Stock Count page will appear here.</p>
        </div>
        <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Status</th>
                    <th style="text-align:right">Items Counted</th>
                    <th style="text-align:right;color:#f87171">Shortages</th>
                    <th style="text-align:right;color:#fbbf24">Overages</th>
                    <th>Submitted By</th>
                    <th>Reconciled</th>
                    <th>Notes</th>
                    <th></th>
                </tr>
            </thead>
            <tbody id="scBody">
            <?php foreach ($sc_rows as $scr):
                $shortage = (int)$scr['shortage_items'];
                $overage  = (int)$scr['overage_items'];
                $counted  = (int)$scr['counted_items'];
                $total_i  = (int)$scr['total_items'];
                $row_class = $shortage > 0 ? 'row-short' : ($overage > 0 ? 'row-over' : ($counted > 0 ? 'row-match' : ''));
            ?>
            <tr class="<?= $row_class ?>">
                <td>
                    <strong><?= date('d M Y', strtotime($scr['business_date'])) ?></strong>
                    <?php if ($scr['business_date'] === date('Y-m-d')): ?>
                    <span class="badge match" style="font-size:10px;padding:2px 7px;margin-left:6px">Today</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($scr['status'] === 'submitted'): ?>
                    <span class="badge match"><i class="fa-solid fa-lock"></i> Submitted</span>
                    <?php else: ?>
                    <span class="badge" style="background:rgba(255,255,255,.06);color:#888;border:1px solid rgba(255,255,255,.1)">
                        <i class="fa-solid fa-pencil"></i> Draft
                    </span>
                    <?php endif; ?>
                </td>
                <td style="text-align:right;color:var(--muted2)"><?= $counted ?> / <?= $total_i ?></td>
                <td style="text-align:right">
                    <?php if ($shortage > 0): ?>
                    <span class="badge short"><?= $shortage ?> item<?= $shortage !== 1 ? 's' : '' ?></span>
                    <?php else: ?>
                    <span style="color:var(--muted)">—</span>
                    <?php endif; ?>
                </td>
                <td style="text-align:right">
                    <?php if ($overage > 0): ?>
                    <span class="badge over"><?= $overage ?> item<?= $overage !== 1 ? 's' : '' ?></span>
                    <?php else: ?>
                    <span style="color:var(--muted)">—</span>
                    <?php endif; ?>
                </td>
                <td style="color:var(--muted2)"><?= htmlspecialchars($scr['submitted_by'] ?? '—') ?></td>
                <td>
                    <?php if ($scr['status'] !== 'submitted'): ?>
                        <span style="color:var(--muted2)">—</span>
                    <?php elseif (!empty($scr['reconciled_at'])): ?>
                        <span class="badge match" title="<?= date('d M Y, g:i A', strtotime($scr['reconciled_at'])) ?>">
                            <i class="fa-solid fa-clipboard-check"></i> <?= htmlspecialchars($scr['reconciled_by'] ?? '', ENT_QUOTES) ?>
                        </span>
                    <?php else: ?>
                        <span class="badge" style="background:var(--amber-dim);color:#fbbf24;border:1px solid var(--amber-border)">
                            <i class="fa-solid fa-hourglass-half"></i> Pending
                        </span>
                    <?php endif; ?>
                </td>
                <td style="color:var(--muted);font-size:12px;max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                    <?= htmlspecialchars($scr['notes'] ?? '') ?>
                </td>
                <td style="white-space:nowrap">
                    <a href="stock_count.php?date=<?= $scr['business_date'] ?>" class="inv-view-btn">
                        View <i class="fa-solid fa-arrow-right" style="font-size:10px"></i>
                    </a>
                    <?php /* Keyed on count_id rather than the date: a date can carry more
                             than one count row, and the manager filing this needs the sheet
                             they are looking at, not whichever one sorts last. */ ?>
                    <a href="stock_count_pdf.php?count_id=<?= (int)$scr['count_id'] ?>"
                       target="_blank" rel="noopener" class="inv-view-btn"
                       title="Export this count as PDF" style="margin-left:6px">
                        <i class="fa-solid fa-file-pdf" style="font-size:10px"></i> PDF
                    </a>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <div class="sc-pg" id="scPg" style="display:none">
            <span class="info" id="scPgInfo"></span>
            <nav id="scPgNav"></nav>
        </div>
        <?php endif; ?>
    </div>

</div>

<script>
(function(){
    var PER = 10;
    var body = document.getElementById('scBody');
    if (!body) return;
    var rows = Array.prototype.slice.call(body.querySelectorAll('tr'));
    if (rows.length <= PER) return; // pager not needed
    var pg = document.getElementById('scPg'), info = document.getElementById('scPgInfo'), nav = document.getElementById('scPgNav');
    var page = 1, totalPages = Math.ceil(rows.length / PER);
    pg.style.display = 'flex';
    function render(){
        page = Math.max(1, Math.min(page, totalPages));
        var start = (page - 1) * PER, end = start + PER;
        rows.forEach(function(r, i){ r.style.display = (i >= start && i < end) ? '' : 'none'; });
        info.textContent = 'Page ' + page + ' of ' + totalPages + ' · ' + rows.length + ' sessions';
        var html = page > 1
            ? '<a href="#" class="sc-pgb" data-p="1">«</a><a href="#" class="sc-pgb" data-p="' + (page - 1) + '">‹</a>'
            : '<span class="sc-pgb dis">«</span><span class="sc-pgb dis">‹</span>';
        var ws = Math.max(1, page - 2), we = Math.min(totalPages, page + 2);
        if (ws > 1) html += '<span class="sc-pge">…</span>';
        for (var i = ws; i <= we; i++){
            html += i === page ? '<span class="sc-pgb act">' + i + '</span>'
                               : '<a href="#" class="sc-pgb" data-p="' + i + '">' + i + '</a>';
        }
        if (we < totalPages) html += '<span class="sc-pge">…</span>';
        html += page < totalPages
            ? '<a href="#" class="sc-pgb" data-p="' + (page + 1) + '">›</a><a href="#" class="sc-pgb" data-p="' + totalPages + '">»</a>'
            : '<span class="sc-pgb dis">›</span><span class="sc-pgb dis">»</span>';
        nav.innerHTML = html;
        nav.querySelectorAll('a.sc-pgb').forEach(function(a){
            a.addEventListener('click', function(e){ e.preventDefault(); page = parseInt(a.getAttribute('data-p'), 10); render(); });
        });
    }
    render();
})();

// follows shared theme key (toggled elsewhere)
window.addEventListener('storage', function (e) {
    if (e.key === 'theme') {
        if (e.newValue === 'light') document.documentElement.setAttribute('data-theme', 'light');
        else document.documentElement.removeAttribute('data-theme');
    }
});
</script>
</body>
</html>
