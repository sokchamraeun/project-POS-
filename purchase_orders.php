<?php
require 'auth.php';
require 'config.php';
if (!can('purchase_orders')) { header("Location: dashboard.php?denied=1"); exit; }
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

// Result of a status action fired from this list (handled by purchase_order_view.php).
$list_msg = match($_GET['msg'] ?? '') {
    'ordered'   => ['text'=>'Purchase order marked as Ordered.',              'type'=>'info'],
    'received'  => ['text'=>'Stock received and added to inventory.',         'type'=>'success'],
    'cancelled' => ['text'=>'Purchase order cancelled.',                      'type'=>'danger'],
    'nochange'  => ['text'=>'No change — that order was already updated.',    'type'=>'info'],
    'badtoken'  => ['text'=>'Session expired. Reload and try again.',         'type'=>'danger'],
    'partial'   => ['text'=>'Delivery recorded. Some items are still outstanding.', 'type'=>'info'],
    'stale'     => ['text'=>'This purchase order changed while you were looking at it. Reload and try again.', 'type'=>'danger'],
    'nothing'   => ['text'=>'Nothing was recorded — every quantity was zero.',      'type'=>'info'],
    'badqty'    => ['text'=>'A received quantity cannot be negative.',              'type'=>'danger'],
    'error'     => ['text'=>'The delivery could not be saved. Nothing was changed.','type'=>'danger'],
    'shortclosed' => ['text'=>'Purchase order closed short. The outstanding items were written off.', 'type'=>'info'],
    default     => null,
};

$filter = $_GET['status'] ?? 'all';
$allowed = ['all','Draft','Ordered','Partially Received','Received','Cancelled'];
if (!in_array($filter, $allowed)) $filter = 'all';

// Stats
$stats = [];
$sres = $conn->query("SELECT status, COUNT(*) AS cnt, IFNULL(SUM(total_cost),0) AS tot
                      FROM purchase_orders GROUP BY status");
$allTotal = 0; $allCount = 0; $pendingCount = 0;
while ($sr = $sres->fetch_assoc()) {
    $stats[$sr['status']] = $sr;
    $allCount += $sr['cnt'];
    $allTotal += $sr['tot'];
    if (in_array($sr['status'], ['Draft','Ordered','Partially Received'])) $pendingCount += $sr['cnt'];
}

// Money actually spent, not money ordered. Summing total_cost here counted the
// full value of any order that arrived short.
$receivedTotal = (float)$conn->query("
    SELECT IFNULL(SUM(poi.qty_received * poi.unit_cost), 0)
    FROM purchase_order_items poi
    JOIN purchase_orders p ON p.po_id = poi.po_id
    WHERE p.status IN ('Received','Partially Received')
")->fetch_row()[0];

// Pagination
$filter_count = $filter === 'all' ? $allCount : (int)($stats[$filter]['cnt'] ?? 0);
$per_page     = 10;
$page         = max(1, (int)($_GET['page'] ?? 1));
$total_pages  = max(1, (int)ceil($filter_count / $per_page));
$page         = min($page, $total_pages);
$offset       = ($page - 1) * $per_page;
$pg_base      = 'purchase_orders.php?status=' . urlencode($filter) . '&page=';

// PO list (paginated)
$where_clause = $filter !== 'all' ? "WHERE p.status = '$filter'" : '';
$pos = [];
$stmt = $conn->prepare("
    SELECT p.*, s.name AS supplier_name,
           COUNT(i.poi_id) AS item_count
    FROM purchase_orders p
    JOIN suppliers s ON s.supplier_id = p.supplier_id
    LEFT JOIN purchase_order_items i ON i.po_id = p.po_id
    $where_clause
    GROUP BY p.po_id
    ORDER BY p.created_at DESC
    LIMIT ? OFFSET ?
");
$stmt->bind_param("ii", $per_page, $offset);
$stmt->execute();
$res = $stmt->get_result();
if ($res) while ($r = $res->fetch_assoc()) $pos[] = $r;

$statusColors = [
    'Draft'     => ['bg'=>'rgba(255,255,255,.06)',  'color'=>'#888',     'icon'=>'fa-pen'],
    'Ordered'   => ['bg'=>'rgba(52,152,219,.15)',   'color'=>'#3498db',  'icon'=>'fa-clock'],
    // Amber, not red: a part delivery needs attention, it is not a failure.
    'Partially Received' => ['bg'=>'rgba(224,169,85,.14)', 'color'=>'#e0a955', 'icon'=>'fa-truck-ramp-box'],
    'Received'  => ['bg'=>'rgba(85,224,135,.13)',   'color'=>'#55e087',  'icon'=>'fa-check'],
    'Cancelled' => ['bg'=>'rgba(255,95,95,.12)',    'color'=>'#ff5f5f',  'icon'=>'fa-xmark'],
];
function he($s){ return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Purchase Orders | Bird's Nest Coffee</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script>(function(){try{if(localStorage.getItem("theme")==="light")document.documentElement.setAttribute("data-theme","light");}catch(e){}})();</script>
<style>
:root{
    --bg:#0b0b0b; --bg-card:#131313; --bg-input:#1a1a1a;
    --border:#222; --border-hover:#333;
    --accent:#d1904b; --accent-light:#e8b87a; --accent-dark:#a0702a;
    --text:#f5f5f5; --text-muted:#888; --text-light:#fff;
    --success:#55e087; --danger:#ff5f5f; --warning:#f1c40f; --info:#3498db;
    --shadow-sm:0 2px 8px rgba(0,0,0,.35);
    --shadow-md:0 4px 20px rgba(0,0,0,.45);
    --shadow-lg:0 8px 40px rgba(0,0,0,.55);
    --shadow-accent:0 4px 20px rgba(209,144,75,.18);
    --radius:14px; --transition:all .22s cubic-bezier(.4,0,.2,1);
}
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Poppins',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;}

.topbar{display:flex;align-items:center;gap:12px;padding:18px 32px;border-bottom:1px solid var(--border);background:var(--bg-card);position:sticky;top:0;z-index:100;}
.topbar-back{display:flex;align-items:center;gap:7px;color:#d1904b;text-decoration:none;font-size:13px;font-weight:600;padding:7px 14px;border-radius:10px;border:1px solid rgba(209,144,75,.35);background:rgba(209,144,75,.08);transition:var(--transition);}
.topbar-back:hover{background:rgba(209,144,75,.16);border-color:#d1904b;}
.topbar-title{font-size:18px;font-weight:700;color:var(--text-light);flex:1;}
.topbar-title i{color:var(--accent);margin-right:8px;}
.btn{display:inline-flex;align-items:center;gap:7px;padding:8px 18px;border-radius:9px;border:none;font-family:'Poppins',sans-serif;font-size:13px;font-weight:600;cursor:pointer;transition:var(--transition);text-decoration:none;}
.btn-accent{background:linear-gradient(135deg,var(--accent),var(--accent-light));color:#000;}
.btn-accent:hover{transform:translateY(-2px);box-shadow:var(--shadow-accent);filter:brightness(1.08);}
.btn-outline{background:transparent;color:var(--text-muted);border:1px solid var(--border);}
.btn-outline:hover{color:var(--accent);border-color:var(--accent);}
.btn-sm{padding:5px 12px;font-size:12px;}

.content{max-width:1100px;margin:0 auto;padding:32px 24px;}

.stats-row{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:28px;}
.stat-card{background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius);padding:18px 20px;display:flex;align-items:center;gap:14px;animation:fadeUp .4s ease both;}
.stat-card:nth-child(2){animation-delay:.06s;}
.stat-card:nth-child(3){animation-delay:.12s;}
.stat-card:nth-child(4){animation-delay:.18s;}
.stat-icon{width:42px;height:42px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:17px;flex-shrink:0;}
.stat-icon.orange{background:rgba(209,144,75,.15);color:var(--accent);}
.stat-icon.blue{background:rgba(52,152,219,.12);color:var(--info);}
.stat-icon.green{background:rgba(85,224,135,.12);color:var(--success);}
.stat-icon.yellow{background:rgba(241,196,15,.12);color:var(--warning);}
.stat-val{font-size:20px;font-weight:700;color:var(--text-light);}
.stat-lbl{font-size:11px;color:var(--text-muted);}

/* Tabs */
.tabs{display:flex;gap:4px;background:var(--bg-card);border:1px solid var(--border);border-radius:10px;padding:4px;margin-bottom:16px;animation:fadeUp .4s .22s ease both;overflow-x:auto;}
.tab{display:flex;align-items:center;gap:6px;padding:7px 16px;border-radius:7px;font-size:12px;font-weight:600;color:var(--text-muted);text-decoration:none;white-space:nowrap;transition:var(--transition);}
.tab:hover{color:var(--text);background:rgba(255,255,255,.05);}
.tab.active{background:var(--accent);color:#000;}
.tab .cnt{background:rgba(0,0,0,.2);border-radius:20px;padding:1px 7px;font-size:10px;}
.tab.active .cnt{background:rgba(0,0,0,.25);}

.table-wrap{background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden;animation:fadeUp .45s .28s ease both;}
table{width:100%;border-collapse:collapse;}
thead th{padding:12px 18px;text-align:left;font-size:11px;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px;border-bottom:1px solid var(--border);background:rgba(255,255,255,.02);}
tbody td{padding:13px 18px;border-bottom:1px solid var(--border);font-size:13px;vertical-align:middle;}
tbody tr:last-child td{border-bottom:none;}
tbody tr:hover td{background:rgba(255,255,255,.025);}

.po-num{font-weight:700;color:var(--accent);font-size:13px;}
.po-date{font-size:11px;color:var(--text-muted);}
.status-badge{display:inline-flex;align-items:center;gap:5px;padding:4px 11px;border-radius:20px;font-size:11px;font-weight:600;}
.actions{display:flex;gap:6px;justify-content:flex-end;}
.empty-state{text-align:center;padding:60px 20px;color:var(--text-muted);}
.empty-state i{font-size:48px;color:var(--border-hover);display:block;margin-bottom:12px;}

/* Buttons stay optically centred in the row; the record count is pinned to the
   right corner out of the flex flow so it can't shift the centring. */
.po-pagination{display:flex;align-items:center;gap:4px;flex-wrap:wrap;justify-content:center;padding:16px 18px;border-top:1px solid var(--border);position:relative;}
.pg-btn{display:inline-flex;align-items:center;justify-content:center;min-width:34px;height:34px;padding:0 10px;border-radius:8px;border:1px solid var(--border);background:rgba(255,255,255,.04);color:var(--text-muted);text-decoration:none;font-size:13px;font-weight:500;transition:var(--transition);cursor:pointer;}
.pg-btn:hover:not(.disabled){border-color:var(--accent);color:var(--accent);}
.pg-btn.pg-active{background:var(--accent);border-color:var(--accent);color:#000;font-weight:700;cursor:default;}
.pg-btn.disabled{opacity:.3;cursor:default;pointer-events:none;}
.pg-ellipsis{color:var(--text-muted);padding:0 2px;font-size:14px;}
.pg-info{position:absolute;right:18px;top:50%;transform:translateY(-50%);font-size:12px;color:var(--text-muted);white-space:nowrap;}
/* Below ~760px the absolute label would sit on top of the buttons — drop it back
   into flow on its own line instead. */
@media (max-width:760px){
    .pg-info{position:static;transform:none;width:100%;text-align:center;margin-top:10px;}
}

@keyframes fadeUp{from{opacity:0;transform:translateY(14px)}to{opacity:1;transform:translateY(0)}}
@media(max-width:768px){
    .topbar{padding:14px 16px;}
    .content{padding:20px 12px;}
    .stats-row{grid-template-columns:1fr 1fr;}
    thead th:nth-child(4),tbody td:nth-child(4){display:none;}
}
/* Light theme (follows shared localStorage theme) */
[data-theme="light"]{--bg:#ECEEF2;--bg-card:#FFFFFF;--bg-input:#F5F7FA;--border:#E2E5EA;--border-hover:#CDD0D8;--text:#111827;--text-muted:#5A6373;--text-light:#0B0F19;}
[data-theme="light"] .topbar{background:rgba(255,255,255,.92);}
/* ── Confirm dialog (replaces the native browser confirm) ── */
.pc-back{display:none;position:fixed;inset:0;z-index:9999;padding:20px;background:rgba(0,0,0,.72);backdrop-filter:blur(6px);align-items:center;justify-content:center;}
.pc-back.open{display:flex;animation:pcFade .18s ease both;}
@keyframes pcFade{from{opacity:0}to{opacity:1}}
@keyframes pcPop{from{opacity:0;transform:scale(.94) translateY(8px)}to{opacity:1;transform:none}}
.pc{width:100%;max-width:410px;background:var(--bg-card);border:1px solid var(--border);border-radius:16px;overflow:hidden;animation:pcPop .22s cubic-bezier(.2,.9,.3,1) both;}
.pc-glow{height:3px;width:100%;}
.pc-body{padding:24px 24px 20px;text-align:center;}
.pc-icon{width:54px;height:54px;margin:0 auto 14px;border-radius:16px;display:flex;align-items:center;justify-content:center;font-size:22px;}
.pc-title{font-size:16px;font-weight:700;color:var(--text-light);margin-bottom:7px;}
.pc-msg{font-size:13.5px;line-height:1.55;color:var(--text-muted);}
.pc-msg strong{color:var(--text);font-weight:600;}
.pc-note{margin-top:12px;padding:9px 12px;border-radius:9px;font-size:12px;background:rgba(255,255,255,.04);border:1px solid var(--border);color:var(--text-muted);}
.pc-foot{display:flex;gap:9px;padding:0 24px 22px;}
.pc-btn{flex:1;padding:10px 16px;border-radius:10px;font-family:inherit;font-size:13px;font-weight:600;cursor:pointer;border:1px solid var(--border);background:transparent;color:var(--text-muted);transition:var(--transition);}
.pc-btn:hover{color:var(--text-light);border-color:var(--border-hover);}
.pc-btn.go{color:#000;border:none;}
.pc-btn.go:hover{filter:brightness(1.08);}
.pc-btn:disabled{opacity:.6;cursor:not-allowed;}

.po-alert{display:flex;align-items:center;gap:9px;padding:12px 16px;border-radius:12px;margin-bottom:18px;font-size:13.5px;font-weight:500;}
.po-alert-success{background:rgba(85,224,135,.1);border:1px solid rgba(85,224,135,.25);color:#55e087;}
.po-alert-info{background:rgba(52,152,219,.1);border:1px solid rgba(52,152,219,.25);color:#3498db;}
.po-alert-danger{background:rgba(255,95,95,.1);border:1px solid rgba(255,95,95,.25);color:#ff5f5f;}
.actions form{display:inline;}
.actions .btn{white-space:nowrap;}
</style>
</head>
<body>

<div class="topbar">
    <a class="topbar-back" href="suppliers.php"><i class="fa-solid fa-arrow-left"></i> Suppliers</a>
    <div class="topbar-title"><i class="fa-solid fa-file-invoice"></i> Purchase Orders</div>
    <a class="btn btn-accent" href="purchase_order_create.php"><i class="fa-solid fa-plus"></i> New PO</a>
</div>

<div class="content">
    <?php if ($list_msg): ?>
    <div class="po-alert po-alert-<?= $list_msg['type'] ?>">
        <i class="fa-solid <?= $list_msg['type']==='success' ? 'fa-circle-check' : ($list_msg['type']==='danger' ? 'fa-circle-exclamation' : 'fa-circle-info') ?>"></i>
        <?= he($list_msg['text']) ?>
    </div>
    <?php endif; ?>
    <div class="stats-row">
        <div class="stat-card">
            <div class="stat-icon orange"><i class="fa-solid fa-file-invoice"></i></div>
            <div><div class="stat-val"><?= $allCount ?></div><div class="stat-lbl">Total POs</div></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon yellow"><i class="fa-solid fa-clock"></i></div>
            <div><div class="stat-val"><?= $pendingCount ?></div><div class="stat-lbl">Pending</div></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon green"><i class="fa-solid fa-check-circle"></i></div>
            <div><div class="stat-val">$<?= number_format($receivedTotal,2) ?></div><div class="stat-lbl">Total Received</div></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon blue"><i class="fa-solid fa-dollar-sign"></i></div>
            <div><div class="stat-val">$<?= number_format($allTotal,2) ?></div><div class="stat-lbl">All Time Spend</div></div>
        </div>
    </div>

    <!-- Tabs -->
    <?php
    $tabs = [
        'all'       => ['label'=>'All',       'icon'=>'fa-list'],
        'Draft'     => ['label'=>'Draft',     'icon'=>'fa-pen'],
        'Ordered'   => ['label'=>'Ordered',   'icon'=>'fa-clock'],
        'Partially Received' => ['label'=>'Part delivered', 'icon'=>'fa-truck-ramp-box'],
        'Received'  => ['label'=>'Received',  'icon'=>'fa-check'],
        'Cancelled' => ['label'=>'Cancelled', 'icon'=>'fa-xmark'],
    ];
    ?>
    <div class="tabs">
        <?php foreach ($tabs as $key => $tab):
            $cnt = $key === 'all' ? $allCount : (int)($stats[$key]['cnt'] ?? 0);
            $active = $filter === $key ? 'active' : '';
        ?>
        <a class="tab <?= $active ?>" href="?status=<?= $key ?>">
            <i class="fa-solid <?= $tab['icon'] ?>"></i> <?= $tab['label'] ?>
            <span class="cnt"><?= $cnt ?></span>
        </a>
        <?php endforeach; ?>
    </div>

    <div class="table-wrap">
        <?php if (empty($pos)): ?>
        <div class="empty-state">
            <i class="fa-solid fa-file-invoice"></i>
            <p>No purchase orders<?= $filter !== 'all' ? " with status \"$filter\"" : '' ?>.</p>
            <br><a class="btn btn-accent" href="purchase_order_create.php" style="margin:auto"><i class="fa-solid fa-plus"></i> Create First PO</a>
        </div>
        <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>PO Number</th>
                    <th>Supplier</th>
                    <th>Status</th>
                    <th>Items</th>
                    <th style="text-align:right">Total Cost</th>
                    <th style="text-align:right">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($pos as $po):
                $sc = $statusColors[$po['status']] ?? $statusColors['Draft'];
            ?>
            <tr>
                <td>
                    <div class="po-num"><?= he($po['po_number']) ?></div>
                    <div class="po-date"><?= date('M j, Y', strtotime($po['created_at'])) ?></div>
                </td>
                <td><?= he($po['supplier_name']) ?></td>
                <td>
                    <span class="status-badge" style="background:<?= $sc['bg'] ?>;color:<?= $sc['color'] ?>">
                        <i class="fa-solid <?= $sc['icon'] ?>"></i> <?= $po['status'] ?>
                    </span>
                    <?php /* A closed-short PO is Received, so without this it looks
                             identical to a clean delivery in the list. The reason
                             rides in the tooltip rather than the cell: the chip
                             answers "is there a write-off here", the tooltip answers
                             "why", and only one of those belongs in a table. */ ?>
                    <?php if ((int)($po['closed_short'] ?? 0) === 1):
                        $tip = po_short_reason_label($po['closed_short_reason'] ?? '');
                        if (trim((string)($po['closed_short_note'] ?? '')) !== '') {
                            $tip .= ' — ' . $po['closed_short_note'];
                        }
                    ?>
                    <span class="status-badge"
                          style="background:rgba(224,169,85,.14);color:#e0a955;margin-left:4px;"
                          title="<?= he($tip) ?>">
                        <i class="fa-solid fa-file-circle-xmark"></i> closed short
                    </span>
                    <?php endif; ?>
                </td>
                <td><?= (int)$po['item_count'] ?> item<?= $po['item_count'] != 1 ? 's' : '' ?></td>
                <td style="text-align:right;font-weight:600;color:var(--success)">
                    $<?= number_format($po['total_cost'],2) ?>
                </td>
                <td>
                    <?php /* Status-driven actions. The forms POST to purchase_order_view.php,
                             which owns the only stock-mutating receive logic — duplicating it
                             here would mean two places to keep in step. return=list sends the
                             manager back to this list instead of the detail page. */ ?>
                    <div class="actions">
                        <?php /* The PO number goes in a data-* attribute, never interpolated into
                                 inline JS: htmlspecialchars protects HTML context, but the parser
                                 decodes entities back before the JS string is parsed, so an
                                 apostrophe would still break out. Read via dataset below. */ ?>
                        <?php if ($po['status'] === 'Draft'): ?>
                        <form method="POST" action="purchase_order_view.php?po_id=<?= $po['po_id'] ?>" style="display:inline"
                              class="po-action" data-confirm="order" data-po="<?= he($po['po_number']) ?>">
                            <input type="hidden" name="action" value="mark_ordered">
                            <input type="hidden" name="return" value="list">
                            <input type="hidden" name="csrf_token" value="<?= he($_SESSION['csrf_token']) ?>">
                            <button type="submit" class="btn btn-info btn-sm"><i class="fa-solid fa-paper-plane"></i> Mark Ordered</button>
                        </form>
                        <?php elseif (in_array($po['status'], ['Ordered','Partially Received'], true)): ?>
                        <?php /* Receiving needs a quantity per line, so it happens on
                                 the PO page where the lines are rendered. Keeping one
                                 caller for the stock arithmetic is the point. */ ?>
                        <a class="btn btn-success btn-sm" href="purchase_order_view.php?po_id=<?= (int)$po['po_id'] ?>">
                            <i class="fa-solid fa-truck-ramp-box"></i> Receive
                        </a>
                        <?php endif; ?>
                        <a class="btn btn-outline btn-sm" href="purchase_order_view.php?po_id=<?= $po['po_id'] ?>">
                            <i class="fa-solid fa-eye"></i> View
                        </a>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
        <?php if ($total_pages > 1): ?>
        <div class="po-pagination">
            <?php if ($page > 1): ?>
            <a href="<?= $pg_base . 1 ?>" class="pg-btn" title="First"><i class="fa-solid fa-angles-left"></i></a>
            <a href="<?= $pg_base . ($page - 1) ?>" class="pg-btn" title="Previous"><i class="fa-solid fa-angle-left"></i></a>
            <?php else: ?>
            <span class="pg-btn disabled"><i class="fa-solid fa-angles-left"></i></span>
            <span class="pg-btn disabled"><i class="fa-solid fa-angle-left"></i></span>
            <?php endif; ?>
            <?php
            $window = array_unique(array_merge([1, $total_pages], range(max(2, $page - 2), min($total_pages - 1, $page + 2))));
            sort($window);
            $prev_p = null;
            foreach ($window as $p_num):
                if ($prev_p !== null && $p_num - $prev_p > 1) echo '<span class="pg-ellipsis">…</span>';
            ?>
            <a href="<?= $pg_base . $p_num ?>" class="pg-btn <?= $p_num === $page ? 'pg-active' : '' ?>"><?= $p_num ?></a>
            <?php $prev_p = $p_num; endforeach; ?>
            <?php if ($page < $total_pages): ?>
            <a href="<?= $pg_base . ($page + 1) ?>" class="pg-btn" title="Next"><i class="fa-solid fa-angle-right"></i></a>
            <a href="<?= $pg_base . $total_pages ?>" class="pg-btn" title="Last"><i class="fa-solid fa-angles-right"></i></a>
            <?php else: ?>
            <span class="pg-btn disabled"><i class="fa-solid fa-angle-right"></i></span>
            <span class="pg-btn disabled"><i class="fa-solid fa-angles-right"></i></span>
            <?php endif; ?>
            <span class="pg-info">Page <?= $page ?> of <?= $total_pages ?> &nbsp;·&nbsp; <?= number_format($filter_count) ?> total</span>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ── Confirm dialog ── -->
<div class="pc-back" id="pcBack">
  <div class="pc" role="alertdialog" aria-modal="true" aria-labelledby="pcTitle" aria-describedby="pcMsg">
    <div class="pc-glow" id="pcGlow"></div>
    <div class="pc-body">
      <div class="pc-icon" id="pcIcon"></div>
      <div class="pc-title" id="pcTitle"></div>
      <div class="pc-msg" id="pcMsg"></div>
      <div class="pc-note" id="pcNote" style="display:none"></div>
    </div>
    <div class="pc-foot">
      <button type="button" class="pc-btn" id="pcCancel">Cancel</button>
      <button type="button" class="pc-btn go" id="pcOk">Confirm</button>
    </div>
  </div>
</div>

<script>
/* Status-action confirms. The PO number is read from dataset rather than being
   baked into an inline handler, so no page data is ever parsed as JavaScript.
   Also single-flight: receiving stock mutates inventory, and a double-submit
   must not reach the server twice. */
const pcBack = document.getElementById('pcBack');

function poConfirm(opts) {
    return new Promise(resolve => {
        const ok     = document.getElementById('pcOk');
        const cancel = document.getElementById('pcCancel');
        const icon   = document.getElementById('pcIcon');
        const note   = document.getElementById('pcNote');

        document.getElementById('pcTitle').textContent = opts.title;
        // textContent, not innerHTML — the PO number is page data.
        document.getElementById('pcMsg').textContent   = opts.message;
        icon.innerHTML          = '<i class="fa-solid ' + opts.icon + '"></i>';
        icon.style.background   = opts.color + '24';
        icon.style.border       = '1px solid ' + opts.color + '59';
        icon.style.color        = opts.color;
        document.getElementById('pcGlow').style.background = opts.color;
        ok.style.background     = opts.color;
        ok.textContent          = opts.confirmText;

        if (opts.note) { note.textContent = opts.note; note.style.display = ''; }
        else           { note.style.display = 'none'; }

        pcBack.classList.add('open');
        setTimeout(() => ok.focus(), 60);

        function done(val) {
            pcBack.classList.remove('open');
            ok.removeEventListener('click', onOk);
            cancel.removeEventListener('click', onCancel);
            pcBack.removeEventListener('click', onBack);
            document.removeEventListener('keydown', onKey);
            resolve(val);
        }
        function onOk()     { done(true); }
        function onCancel() { done(false); }
        function onBack(ev) { if (ev.target === pcBack) done(false); }
        function onKey(ev)  { if (ev.key === 'Escape') done(false); }

        ok.addEventListener('click', onOk);
        cancel.addEventListener('click', onCancel);
        pcBack.addEventListener('click', onBack);
        document.addEventListener('keydown', onKey);
    });
}

document.addEventListener('submit', async function (e) {
    const form = e.target.closest('form.po-action');
    if (!form) return;

    // Always stop the native submit: the dialog is async, so the form is
    // re-submitted from code once the manager confirms.
    e.preventDefault();
    if (form.dataset.sent) return;

    const po = form.dataset.po || 'this order';

    const okd = await poConfirm({
        title:       'Send this order?',
        message:     'Mark ' + po + ' as ordered.',
        note:        'Records that the order has been placed with the supplier. Stock is unchanged until it arrives.',
        confirmText: 'Mark Ordered',
        icon:        'fa-paper-plane',
        color:       '#3498db',
    });
    if (!okd) return;

    form.dataset.sent = '1';
    const btn = form.querySelector('button[type="submit"]');
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Working…'; }
    form.submit();
});
</script>
</body>
</html>
