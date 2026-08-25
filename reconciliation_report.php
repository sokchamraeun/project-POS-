<?php
require 'auth.php';
require 'config.php';
if (!can('cash_reconciliation')) {
    header("Location: dashboard.php?denied=1"); exit;
}
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

// Only a manager/admin closes out a variance — same split as stock_count.php's
// reconcile: holding the report permission lets you READ the drawer counts, not
// sign off on them.
$can_resolve = in_array($_SESSION['role'] ?? '', ['admin', 'manager'], true);

/* ══════════════════════════════════════════════
   AJAX: resolve — attach an investigation outcome to an Over/Short count.
   Never edits expected/actual/difference: the variance stays on the record,
   only the follow-up note is added.
══════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'resolve') {
    header('Content-Type: application/json');

    if (!$can_resolve) {
        echo json_encode(['ok'=>false,'msg'=>'Only an admin or manager can resolve a drawer variance.']); exit;
    }
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        echo json_encode(['ok'=>false,'msg'=>'Invalid session token. Reload and try again.']); exit;
    }

    $count_id = (int)($_POST['count_id'] ?? 0);
    $note     = trim($_POST['note'] ?? '');
    if ($count_id <= 0)      { echo json_encode(['ok'=>false,'msg'=>'Missing count.']); exit; }
    if ($note === '')        { echo json_encode(['ok'=>false,'msg'=>'Please describe what you found.']); exit; }
    if (mb_strlen($note) > 1000) $note = mb_substr($note, 0, 1000);

    $by = $_SESSION['username'] ?? 'system';

    // A matched drawer has nothing to investigate — guarding in SQL keeps a
    // crafted POST from stamping a resolution onto a $0.00 row.
    // resolved_at IS NULL makes this a one-shot claim: without it a second manager
    // could overwrite the first one's findings in place, leaving no record that the
    // original investigation ever happened. Re-opening a resolution deliberately
    // has no endpoint — it would need its own permission and an append-only history.
    $upd = $conn->prepare("UPDATE cash_counts
        SET resolved_at = NOW(), resolved_by = ?, resolution_note = ?
        WHERE id = ? AND ABS(difference) >= 0.01 AND resolved_at IS NULL");
    $upd->bind_param("ssi", $by, $note, $count_id);
    $upd->execute();
    if ($upd->affected_rows === 0) {
        echo json_encode(['ok'=>false,'msg'=>'That count has already been resolved, or has no variance to resolve. Reload to see the current note.']); exit;
    }
    $upd->close();

    echo json_encode([
        'ok'          => true,
        'resolved_by' => $by,
        'resolved_at' => date('d M Y, g:i A'),
        'note'        => $note,
    ]);
    exit;
}

// ── FILTERS ──
$filter_user = (int)($_GET['user_id'] ?? 0);
$filter_from = trim($_GET['from'] ?? '');
$filter_to   = trim($_GET['to']   ?? date('Y-m-d'));
if ($filter_from === '') $filter_from = date('Y-m-d', strtotime('-30 days'));

$per_page = 10;
$page     = max(1, (int)($_GET['page'] ?? 1));
$offset   = ($page - 1) * $per_page;
$is_ajax  = !empty($_GET['ajax']);

// ── CASHIER LIST ──
$cashiers = [];
$cr = $conn->query("SELECT DISTINCT user_id, username FROM cash_counts ORDER BY username ASC");

// Stock count sessions moved to inventory_count.php — this page is Cash Count only.

while ($row = $cr->fetch_assoc()) $cashiers[] = $row;

// ── WHERE CLAUSE ──
$where  = ['cr.shift_date BETWEEN ? AND ?'];
$params = [$filter_from, $filter_to];
$types  = 'ss';
if ($filter_user > 0) { $where[] = 'cr.user_id = ?'; $params[] = $filter_user; $types .= 'i'; }
$where_sql = implode(' AND ', $where);

// ── SUMMARY STATS (all matching rows) ──
$ss = $conn->prepare("SELECT
    COUNT(*)                                              AS total,
    SUM(CASE WHEN ABS(difference) < 0.01 THEN 1 ELSE 0 END) AS matches,
    SUM(CASE WHEN difference > 0.005     THEN 1 ELSE 0 END) AS overs,
    SUM(CASE WHEN difference < -0.005    THEN 1 ELSE 0 END) AS shorts,
    SUM(CASE WHEN ABS(difference) >= 0.01 AND resolved_at IS NULL THEN 1 ELSE 0 END) AS unresolved,
    COALESCE(SUM(difference), 0)                         AS total_diff
    FROM cash_counts cr WHERE $where_sql");
$ss->bind_param($types, ...$params);
$ss->execute();
$stats      = $ss->get_result()->fetch_assoc();
$total      = (int)$stats['total'];
$matches    = (int)$stats['matches'];
$overs      = (int)$stats['overs'];
$shorts     = (int)$stats['shorts'];
$unresolved = (int)$stats['unresolved'];
$total_diff = (float)$stats['total_diff'];
$total_pages = max(1, (int)ceil($total / $per_page));
if ($page > $total_pages) $page = $total_pages;

// ── PAGINATED ROWS ──
$pparams = [...$params, $per_page, $offset];
$ptypes  = $types . 'ii';
// shift_end: a cashier can clock in more than once a day, so pick the attendance
// session whose clock_in sits nearest this drawer count's login_time rather than
// blindly taking MAX(clock_out). NULL when the shift was never clocked out.
$stmt = $conn->prepare("SELECT cr.id, cr.user_id, cr.username, cr.shift_date, cr.login_time,
               cr.expected_cash, cr.actual_cash, cr.difference, cr.recorded_at,
               cr.resolved_at, cr.resolved_by, cr.resolution_note,
               (SELECT a.clock_out FROM attendance a
                 WHERE a.user_id = cr.user_id
                   AND a.date    = cr.shift_date
                   AND a.clock_out IS NOT NULL
                 ORDER BY ABS(TIMESTAMPDIFF(SECOND, a.clock_in, cr.login_time)) ASC
                 LIMIT 1) AS shift_end
        FROM cash_counts cr
        WHERE $where_sql
        ORDER BY cr.recorded_at DESC
        LIMIT ? OFFSET ?");
$stmt->bind_param($ptypes, ...$pparams);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// ── PAGINATION URL HELPER ──
function page_url(int $p): string {
    $q = $_GET;
    unset($q['ajax']);
    $q['page'] = $p;
    return '?' . http_build_query($q);
}

// ── RESULTS HTML (shared between AJAX and full-page) ──
ob_start();
?>
    <div class="table-head">
        <h3>Results</h3>
        <span class="count-badge"><?= $total ?> record<?= $total !== 1 ? 's' : '' ?></span>
    </div>

    <?php if (empty($rows)): ?>
    <div class="empty">
        <i class="fa-solid fa-inbox"></i>
        <h3>No records found</h3>
        <p>No drawer counts submitted for this date range.</p>
    </div>
    <?php else: ?>
    <table>
        <thead>
            <tr>
                <th>Cashier</th>
                <th>Shift Date</th>
                <th>Shift Start</th>
                <th>Shift End</th>
                <th>Expected</th>
                <th>Actual</th>
                <th>Difference</th>
                <th>Status</th>
                <th>Submitted</th>
                <th style="text-align:right">Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r):
            $diff = (float)$r['difference'];
            if (abs($diff) < 0.01) {
                $status = 'match'; $diff_class = 'diff-match'; $diff_label = '✓ 0.00';
            } elseif ($diff > 0) {
                $status = 'over';  $diff_class = 'diff-over';  $diff_label = '+$' . number_format($diff, 2);
            } else {
                $status = 'short'; $diff_class = 'diff-short'; $diff_label = '-$' . number_format(abs($diff), 2);
            }
            $initial    = strtoupper(substr($r['username'], 0, 1));
            $is_var     = ($status !== 'match');            // only a variance can be resolved
            $is_done    = !empty($r['resolved_at']);
            // Payload for the modal — read straight off the row so View and Resolve
            // share one source of truth and never re-query.
            $row_json   = htmlspecialchars(json_encode([
                'id'       => (int)$r['id'],
                'cashier'  => $r['username'],
                'date'     => date('d M Y', strtotime($r['shift_date'])),
                'start'    => date('g:i A', strtotime($r['login_time'])),
                'end'      => $r['shift_end'] ? date('g:i A', strtotime($r['shift_end'])) : null,
                'expected' => number_format((float)$r['expected_cash'], 2),
                'actual'   => number_format((float)$r['actual_cash'], 2),
                'diff'     => $diff_label,
                'status'   => $status,
                'variance' => $is_var,
                'note'     => $r['resolution_note'],
                'by'       => $r['resolved_by'],
                'at'       => $is_done ? date('d M Y, g:i A', strtotime($r['resolved_at'])) : null,
            ], JSON_UNESCAPED_UNICODE), ENT_QUOTES);
        ?>
        <tr class="row-<?= $status ?>" data-row='<?= $row_json ?>'>
            <td>
                <div class="cashier-cell">
                    <div class="avatar"><?= htmlspecialchars($initial) ?></div>
                    <?= htmlspecialchars($r['username']) ?>
                </div>
            </td>
            <td><?= date('d M Y', strtotime($r['shift_date'])) ?></td>
            <td><?= date('g:i A', strtotime($r['login_time'])) ?></td>
            <td<?= $r['shift_end'] ? '' : ' style="color:var(--muted)"' ?>>
                <?= $r['shift_end'] ? date('g:i A', strtotime($r['shift_end'])) : '—' ?>
            </td>
            <td>$<?= number_format($r['expected_cash'], 2) ?></td>
            <td>$<?= number_format($r['actual_cash'], 2) ?></td>
            <td class="<?= $diff_class ?>"><?= $diff_label ?></td>
            <td>
                <div class="status-cell">
                <span class="badge <?= $status ?>"><?= $status === 'match' ? '✓ Match' : ($status === 'over' ? '↑ Over' : '↓ Short') ?></span>
                <?php if ($is_var): ?>
                    <?php if ($is_done): ?>
                    <span class="res-pill done" title="<?= htmlspecialchars($r['resolved_by'] . ' — ' . date('d M Y, g:i A', strtotime($r['resolved_at'])), ENT_QUOTES) ?>">
                        <i class="fa-solid fa-circle-check"></i> Resolved
                    </span>
                    <?php else: ?>
                    <span class="res-pill open"><i class="fa-solid fa-circle-exclamation"></i> Unresolved</span>
                    <?php endif; ?>
                <?php endif; ?>
                </div>
            </td>
            <td style="color:var(--muted2)"><?= date('g:i A', strtotime($r['recorded_at'])) ?></td>
            <td style="text-align:right">
                <?php if (!$is_var): ?>
                    <button type="button" class="act-btn view js-open-row"><i class="fa-solid fa-eye"></i> View</button>
                <?php elseif ($is_done): ?>
                    <button type="button" class="act-btn view js-open-row"><i class="fa-solid fa-file-lines"></i> View note</button>
                <?php else: ?>
                    <button type="button" class="act-btn resolve js-open-row"><i class="fa-solid fa-gavel"></i> Resolve</button>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <?php if ($total_pages > 1): ?>
    <div class="pagination">
        <div class="page-info">
            Showing <?= number_format(($page - 1) * $per_page + 1) ?>–<?= number_format(min($page * $per_page, $total)) ?> of <?= number_format($total) ?> records
        </div>
        <div class="page-btns">
            <a href="<?= page_url(1) ?>" class="page-btn <?= $page <= 1 ? 'disabled' : '' ?>" title="First">
                <i class="fa-solid fa-angles-left" style="font-size:11px"></i>
            </a>
            <a href="<?= page_url($page - 1) ?>" class="page-btn <?= $page <= 1 ? 'disabled' : '' ?>" title="Previous">
                <i class="fa-solid fa-angle-left" style="font-size:11px"></i>
            </a>

            <?php
            $range = 2;
            $start = max(1, $page - $range);
            $end   = min($total_pages, $page + $range);
            if ($start > 1) echo '<span class="page-dots">…</span>';
            for ($i = $start; $i <= $end; $i++):
            ?>
            <a href="<?= page_url($i) ?>" class="page-btn <?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
            <?php endfor;
            if ($end < $total_pages) echo '<span class="page-dots">…</span>';
            ?>

            <a href="<?= page_url($page + 1) ?>" class="page-btn <?= $page >= $total_pages ? 'disabled' : '' ?>" title="Next">
                <i class="fa-solid fa-angle-right" style="font-size:11px"></i>
            </a>
            <a href="<?= page_url($total_pages) ?>" class="page-btn <?= $page >= $total_pages ? 'disabled' : '' ?>" title="Last">
                <i class="fa-solid fa-angles-right" style="font-size:11px"></i>
            </a>
        </div>
    </div>
    <?php endif; ?>

    <?php endif; ?>
<?php
$results_html = ob_get_clean();

// ── AJAX: return only the results box content ──
if ($is_ajax) {
    header('Content-Type: text/html; charset=utf-8');
    echo $results_html;
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Cash Count Report</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script>(function(){try{if(localStorage.getItem("theme")==="light")document.documentElement.setAttribute("data-theme","light");}catch(e){}})();</script>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#0b0c10;--surface:#14151e;--surface2:#191a26;--border:#232433;
  --amber:#10b981;--amber-dim:rgba(16,185,129,.12);--amber-border:rgba(16,185,129,.25);
  --green:#10b981;--green-dim:rgba(16,185,129,.1);--green-border:rgba(16,185,129,.25);
  --red:#ef4444;--red-dim:rgba(239,68,68,.1);--red-border:rgba(239,68,68,.2);
  --yellow:#f59e0b;--yellow-dim:rgba(245,158,11,.1);--yellow-border:rgba(245,158,11,.2);
  --text:#f0f0f0;--muted:#8e8e9f;--muted2:#a0a0b2;
  --radius:14px;
}
@keyframes fadeInUp {from{opacity:0;transform:translateY(20px)}to{opacity:1;transform:translateY(0)}}
@keyframes scaleIn  {from{opacity:0;transform:scale(.94)}to{opacity:1;transform:scale(1)}}
@keyframes fadeIn   {from{opacity:0}to{opacity:1}}
@keyframes rowIn    {from{opacity:0;transform:translateX(-8px)}to{opacity:1;transform:translateX(0)}}

body{
  font-family:'Poppins',sans-serif;
  background:radial-gradient(ellipse 80% 40% at 50% 0%,rgba(16,185,129,.07) 0%,transparent 100%),#0b0c10;
  color:var(--text);min-height:100vh;
}

/* ── Top bar ── */
.topbar{
  display:flex;align-items:center;justify-content:space-between;
  padding:16px 28px;
  background:rgba(255,255,255,.02);
  border-bottom:1px solid var(--border);
  animation:fadeIn .4s ease both;
}
.topbar-left{display:flex;align-items:center;gap:14px}
.back-btn{
  display:inline-flex;align-items:center;gap:7px;padding:7px 14px;border-radius:10px;
  background:rgba(16,185,129,.08);border:1px solid rgba(16,185,129,.35);
  color:#10b981;font-size:13px;font-weight:600;text-decoration:none;transition:all .2s;
}
.back-btn:hover{background:rgba(16,185,129,.16);border-color:#10b981}
.page-title{font-size:18px;font-weight:700;color:var(--text)}
.page-sub{font-size:12px;color:var(--muted);margin-top:1px}

/* ── Content ── */
.wrap{max-width:1100px;margin:0 auto;padding:28px 24px}

/* ── Summary cards ── */
.cards{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:24px}
@media(max-width:700px){.cards{grid-template-columns:repeat(2,1fr)}}
.card{
  background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);
  padding:20px 22px;
  animation:scaleIn .45s cubic-bezier(.16,1,.3,1) both;
  transition:transform .2s,border-color .2s;
}
.card:hover{transform:translateY(-2px)}
.card:nth-child(1){animation-delay:.08s}
.card:nth-child(2){animation-delay:.14s}
.card:nth-child(3){animation-delay:.20s}
.card:nth-child(4){animation-delay:.26s}
.card-icon{font-size:18px;margin-bottom:10px;opacity:.6}
.card-val{font-size:30px;font-weight:800;line-height:1;margin-bottom:4px}
.card-label{font-size:11px;color:var(--muted);font-weight:500;text-transform:uppercase;letter-spacing:.5px}
.card.green{border-color:var(--green-border);background:var(--green-dim)}
.card.green .card-val{color:var(--green)}
.card.green .card-icon{color:var(--green)}
.card.red{border-color:var(--red-border);background:var(--red-dim)}
.card.red .card-val{color:var(--red)}
.card.red .card-icon{color:var(--red)}
.card.yellow{border-color:var(--yellow-border);background:var(--yellow-dim)}
.card.yellow .card-val{color:var(--yellow)}
.card.yellow .card-icon{color:var(--yellow)}
.card.amber .card-val{color:var(--amber)}
.card.amber .card-icon{color:var(--amber)}

/* ── Filter bar ── */
.filter-bar{
  background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);
  padding:16px 20px;margin-bottom:20px;
  display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;
  animation:fadeInUp .45s ease .3s both;
}
.filter-group{display:flex;flex-direction:column;gap:5px;min-width:140px}
.filter-group label{font-size:11px;color:var(--muted);font-weight:500;text-transform:uppercase;letter-spacing:.4px}
.filter-group select,
.filter-group input{
  background:var(--surface2);border:1px solid var(--border);color:var(--text);
  border-radius:9px;padding:8px 12px;font-family:inherit;font-size:13px;outline:none;
  transition:border-color .2s,box-shadow .2s;
}
.filter-group select:focus,
.filter-group input:focus{border-color:var(--amber);box-shadow:0 0 0 3px rgba(209,144,75,.1)}
.filter-group select option{background:#1a1a1a}
.btn-filter{
  display:flex;align-items:center;gap:6px;padding:9px 18px;border-radius:9px;border:none;
  background:var(--amber);color:#000;font-family:inherit;font-size:13px;font-weight:700;
  cursor:pointer;transition:all .2s;white-space:nowrap;align-self:flex-end;
}
.btn-filter:hover{opacity:.85;transform:translateY(-1px)}
.btn-reset{
  display:flex;align-items:center;gap:6px;padding:9px 14px;border-radius:9px;
  background:rgba(255,255,255,.05);border:1px solid var(--border);
  color:var(--muted2);font-family:inherit;font-size:13px;font-weight:500;
  cursor:pointer;transition:all .2s;text-decoration:none;white-space:nowrap;align-self:flex-end;
}
.btn-reset:hover{color:var(--text);background:rgba(255,255,255,.09)}

/* ── Table box ── */
.table-wrap{
  background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);
  overflow:hidden;
  animation:fadeInUp .45s ease .38s both;
}
.table-head{
  display:flex;align-items:center;justify-content:space-between;
  padding:16px 20px;border-bottom:1px solid var(--border);
}
.table-head h3{font-size:13px;font-weight:600;color:var(--muted2);text-transform:uppercase;letter-spacing:.5px}
.count-badge{
  background:var(--amber-dim);border:1px solid var(--amber-border);
  color:var(--amber);font-size:12px;font-weight:600;
  padding:3px 10px;border-radius:20px;
}
table{width:100%;border-collapse:collapse}
th{
  font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.4px;
  padding:11px 16px;text-align:left;border-bottom:1px solid var(--border);background:var(--surface2);
}
td{padding:13px 16px;font-size:13px;border-bottom:1px solid rgba(255,255,255,.04);transition:background .15s;}
tr:last-child td{border-bottom:none}
tr:hover td{background:rgba(255,255,255,.03)}
tbody tr{animation:rowIn .3s ease both;}
tbody tr:nth-child(1){animation-delay:.05s}
tbody tr:nth-child(2){animation-delay:.08s}
tbody tr:nth-child(3){animation-delay:.11s}
tbody tr:nth-child(4){animation-delay:.14s}
tbody tr:nth-child(5){animation-delay:.17s}
tbody tr:nth-child(6){animation-delay:.20s}
tbody tr:nth-child(7){animation-delay:.23s}
tbody tr:nth-child(8){animation-delay:.26s}
tbody tr:nth-child(9){animation-delay:.29s}
tbody tr:nth-child(10){animation-delay:.32s}
tr.row-short td:first-child{border-left:3px solid var(--red)}
tr.row-over  td:first-child{border-left:3px solid var(--yellow)}
tr.row-match td:first-child{border-left:3px solid var(--green)}

/* ── Status badges ── */
.badge{
  display:inline-flex;align-items:center;gap:5px;
  font-size:11px;font-weight:700;padding:4px 11px;border-radius:20px;
  text-transform:uppercase;letter-spacing:.3px;
}
.badge.match{background:var(--green-dim);color:var(--green);border:1px solid var(--green-border)}
.badge.over{background:var(--yellow-dim);color:var(--yellow);border:1px solid var(--yellow-border)}
.badge.short{background:var(--red-dim);color:var(--red);border:1px solid var(--red-border)}
.diff-match{color:var(--green);font-weight:700}
.diff-over{color:var(--yellow);font-weight:700}
.diff-short{color:var(--red);font-weight:700}
.cashier-cell{display:flex;align-items:center;gap:9px}
.avatar{
  width:30px;height:30px;border-radius:50%;
  background:var(--amber-dim);border:1px solid var(--amber-border);
  color:var(--amber);font-size:12px;font-weight:700;
  display:flex;align-items:center;justify-content:center;flex-shrink:0;
}

/* ── Pagination ── */
.pagination{
  display:flex;align-items:center;justify-content:space-between;
  padding:14px 20px;border-top:1px solid var(--border);
}
.page-info{font-size:12px;color:var(--muted2)}
.page-btns{display:flex;align-items:center;gap:4px}
.page-btn{
  display:flex;align-items:center;justify-content:center;
  min-width:34px;height:34px;padding:0 8px;border-radius:9px;
  background:rgba(255,255,255,.04);border:1px solid var(--border);
  color:var(--muted2);font-family:inherit;font-size:13px;font-weight:500;
  text-decoration:none;transition:all .2s;cursor:pointer;
}
.page-btn:hover{background:rgba(255,255,255,.09);color:var(--text);transform:translateY(-1px)}
.page-btn.active{background:var(--amber);color:#000;border-color:var(--amber);font-weight:700;pointer-events:none;}
.page-btn.disabled{opacity:.25;pointer-events:none;}
.page-dots{color:var(--muted);font-size:13px;padding:0 4px;}

/* ── Net diff bar ── */
.net-bar{
  display:flex;align-items:center;justify-content:flex-end;gap:10px;
  margin-top:14px;padding:12px 18px;border-radius:var(--radius);
  background:var(--surface);border:1px solid var(--border);
}
.net-bar-label{font-size:12px;color:var(--muted2)}
.net-bar-val{font-size:14px;font-weight:700}

/* ── Empty state ── */
.empty{padding:60px 20px;text-align:center;color:var(--muted);}
.empty i{font-size:52px;color:rgba(255,255,255,.05);margin-bottom:16px;display:block}
.empty h3{font-size:15px;font-weight:600;color:rgba(255,255,255,.12);margin-bottom:6px}
.empty p{font-size:13px;color:rgba(255,255,255,.1)}

/* ── AJAX loading state ── */
#results-box{transition:opacity .15s ease}
#results-box.loading{opacity:.35;pointer-events:none}

/* ── Inventory section ── */
.inv-section{
  background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);
  overflow:hidden;margin-top:20px;
  animation:fadeInUp .45s ease .5s both;
}
.inv-header{
  display:flex;align-items:center;justify-content:space-between;
  padding:16px 20px;border-bottom:1px solid var(--border);
}
.inv-title{font-size:13px;font-weight:600;color:var(--muted2);text-transform:uppercase;letter-spacing:.5px;display:flex;align-items:center;gap:8px}
.inv-new-btn{
  display:inline-flex;align-items:center;gap:6px;
  padding:7px 14px;border-radius:9px;font-size:12px;font-weight:600;
  background:var(--amber-dim);border:1px solid var(--amber-border);
  color:var(--amber);text-decoration:none;transition:all .2s;
}
.inv-new-btn:hover{background:rgba(209,144,75,.2);}
.inv-view-btn{
  display:inline-flex;align-items:center;gap:5px;
  padding:5px 11px;border-radius:8px;font-size:11px;font-weight:500;
  background:rgba(255,255,255,.05);border:1px solid var(--border);
  color:var(--muted2);text-decoration:none;transition:all .2s;white-space:nowrap;
}
.inv-view-btn:hover{color:var(--text);background:rgba(255,255,255,.09);}
/* Signpost to where the stock counts went */
.inv-jump{
  display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;
  margin-top:20px;padding:16px 20px;border-radius:var(--radius);
  background:var(--surface);border:1px solid var(--border);
  color:var(--muted2);font-size:13px;text-decoration:none;transition:all .2s;
}
.inv-jump:hover{border-color:var(--amber-border);background:var(--amber-dim);color:var(--text)}
.inv-jump-go{display:inline-flex;align-items:center;gap:7px;color:var(--amber);font-weight:600}
/* Light theme (follows shared localStorage theme) */
/* ── Follow-up strip ── */
.followup{
  display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;
  margin:-6px 0 18px;padding:10px 16px;border-radius:12px;font-size:13px;
  border:1px solid var(--border);background:var(--surface);
  animation:fadeInUp .4s ease both;
}
.followup span{display:inline-flex;align-items:center;gap:8px}
.followup.open{background:var(--red-dim);border-color:var(--red-border);color:#fca5a5}
.followup.open strong{color:var(--red);font-weight:700}
.followup.clear{background:var(--green-dim);border-color:var(--green-border);color:#86efac}
.followup-hint{font-size:11.5px;color:var(--muted2);font-weight:500}

/* ── Status cell: badge above, resolution pill below ──
   Stacked rather than inline — at this column width the two always wrapped, and a
   margin-left does nothing once the pill drops to its own line. Fixed order, even gap. */
.status-cell{display:flex;flex-direction:column;align-items:flex-start;gap:5px}

/* ── Resolution pill (sits under the Match/Over/Short badge) ── */
.res-pill{
  display:inline-flex;align-items:center;gap:4px;
  padding:2px 8px;border-radius:20px;font-size:10.5px;font-weight:600;white-space:nowrap;
}
.res-pill i{font-size:9px}
.res-pill.done{background:var(--green-dim);color:var(--green);border:1px solid var(--green-border)}
.res-pill.open{background:var(--amber-dim);color:var(--amber);border:1px solid var(--amber-border)}

/* ── Row action buttons ── */
.act-btn{
  display:inline-flex;align-items:center;gap:5px;padding:5px 11px;border-radius:8px;
  font-family:inherit;font-size:11.5px;font-weight:600;cursor:pointer;white-space:nowrap;
  border:1px solid var(--border);background:transparent;color:var(--muted2);transition:all .18s;
}
.act-btn i{font-size:10px}
.act-btn:hover{border-color:var(--amber);color:var(--amber);background:var(--amber-dim)}
.act-btn.resolve{border-color:var(--amber-border);color:var(--amber);background:var(--amber-dim)}
.act-btn.resolve:hover{background:var(--amber);color:#000;border-color:var(--amber)}

/* ── Resolve / detail modal ── */
.rz-back{
  display:none;position:fixed;inset:0;z-index:9999;padding:20px;
  background:rgba(0,0,0,.72);backdrop-filter:blur(6px);
  align-items:center;justify-content:center;
}
.rz-back.show{display:flex;animation:fadeIn .18s ease both}
.rz{
  width:100%;max-width:460px;max-height:90vh;overflow-y:auto;
  background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);
  animation:scaleIn .22s ease both;
}
.rz-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;padding:18px 20px;border-bottom:1px solid var(--border)}
.rz-title{font-size:15px;font-weight:700;color:var(--text)}
.rz-sub{font-size:12px;color:var(--muted2);margin-top:3px}
.rz-x{background:none;border:none;color:var(--muted2);font-size:18px;cursor:pointer;line-height:1;padding:2px 4px}
.rz-x:hover{color:var(--text)}
.rz-body{padding:18px 20px;display:flex;flex-direction:column;gap:14px}
.rz-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.rz-cell{background:var(--surface2);border:1px solid var(--border);border-radius:10px;padding:10px 12px}
.rz-cell .k{font-size:10px;color:var(--muted2);text-transform:uppercase;letter-spacing:.4px;font-weight:600}
.rz-cell .v{font-size:14px;font-weight:700;color:var(--text);margin-top:3px}
.rz-lbl{font-size:11px;color:var(--muted2);text-transform:uppercase;letter-spacing:.4px;font-weight:600;margin-bottom:6px;display:block}
.rz-ta{
  width:100%;min-height:96px;resize:vertical;padding:11px 13px;border-radius:10px;
  background:var(--surface2);border:1px solid var(--border);color:var(--text);
  font-family:inherit;font-size:13px;line-height:1.5;
}
.rz-ta:focus{outline:none;border-color:var(--amber)}
.rz-ta::placeholder{color:var(--muted)}
.rz-note{background:var(--surface2);border:1px solid var(--border);border-left:3px solid var(--green);border-radius:10px;padding:12px 14px;font-size:13px;line-height:1.55;color:var(--text);white-space:pre-wrap;word-break:break-word}
.rz-meta{font-size:11.5px;color:var(--muted2);margin-top:8px}
.rz-err{display:none;font-size:12px;color:#fca5a5;background:var(--red-dim);border:1px solid var(--red-border);border-radius:8px;padding:9px 12px}
.rz-foot{display:flex;justify-content:flex-end;gap:9px;padding:14px 20px;border-top:1px solid var(--border)}
.rz-btn{padding:9px 17px;border-radius:9px;font-family:inherit;font-size:12.5px;font-weight:600;cursor:pointer;border:1px solid var(--border);background:transparent;color:var(--muted2);transition:all .18s}
.rz-btn:hover{color:var(--text);border-color:var(--muted2)}
.rz-btn.primary{background:var(--amber);border-color:var(--amber);color:#000}
.rz-btn.primary:hover{filter:brightness(1.08)}
.rz-btn:disabled{opacity:.55;cursor:not-allowed}

[data-theme="light"]{--bg:#ECEEF2;--surface:#FFFFFF;--surface2:#F5F7FA;--border:#E2E5EA;--text:#111827;--muted:#5A6373;--muted2:#5A6373;}
[data-theme="light"] .followup.open{color:#b91c1c}
[data-theme="light"] .followup.clear{color:#15803d}
[data-theme="light"] .rz-back{background:rgba(15,23,42,.45)}
[data-theme="light"] .topbar{background:rgba(255,255,255,.92);}
[data-theme="light"] body{background:radial-gradient(ellipse 80% 40% at 50% 0%,rgba(209,144,75,.05) 0%,transparent 100%),#ECEEF2;}
[data-theme="light"] .filter-group select option,
[data-theme="light"] select option{background:#FFFFFF;}
</style>
</head>
<body>

<div class="topbar">
    <div class="topbar-left">
        <a href="dashboard.php" class="back-btn"><i class="fa-solid fa-arrow-left"></i> Dashboard</a>
        <div>
            <div class="page-title"><i class="fa-solid fa-cash-register" style="color:var(--amber);margin-right:8px"></i>Cash Count Report</div>
            <div class="page-sub">Drawer counts submitted by cashiers at end of shift</div>
        </div>
    </div>
    <div style="font-size:12px;color:var(--muted)"><?= date('d M Y') ?></div>
</div>

<div class="wrap">

    <!-- Summary cards -->
    <div class="cards">
        <div class="card amber">
            <div class="card-icon"><i class="fa-solid fa-cash-register"></i></div>
            <div class="card-val" data-target="<?= $total ?>">0</div>
            <div class="card-label">Total Submissions</div>
        </div>
        <div class="card green">
            <div class="card-icon"><i class="fa-solid fa-circle-check"></i></div>
            <div class="card-val" data-target="<?= $matches ?>">0</div>
            <div class="card-label">Exact Match</div>
        </div>
        <div class="card yellow">
            <div class="card-icon"><i class="fa-solid fa-arrow-trend-up"></i></div>
            <div class="card-val" data-target="<?= $overs ?>">0</div>
            <div class="card-label">Over</div>
        </div>
        <div class="card red">
            <div class="card-icon"><i class="fa-solid fa-arrow-trend-down"></i></div>
            <div class="card-val" data-target="<?= $shorts ?>">0</div>
            <div class="card-label">Short</div>
        </div>
    </div>

    <!-- Follow-up strip. Over/Short counts above stay untouched by resolving — the
         drawer really was off — so the outstanding investigation count lives here. -->
    <?php $variances = $overs + $shorts; if ($variances > 0): ?>
    <div class="followup <?= $unresolved > 0 ? 'open' : 'clear' ?>">
        <?php if ($unresolved > 0): ?>
        <span><i class="fa-solid fa-circle-exclamation"></i>
            <strong><?= $unresolved ?></strong> of <?= $variances ?> variance<?= $variances !== 1 ? 's' : '' ?> still unresolved
        </span>
        <span class="followup-hint"><?= $can_resolve ? 'Use Resolve to record what you found.' : 'A manager or admin can resolve these.' ?></span>
        <?php else: ?>
        <span><i class="fa-solid fa-circle-check"></i>
            All <?= $variances ?> variance<?= $variances !== 1 ? 's' : '' ?> investigated and resolved
        </span>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Filter bar -->
    <form method="GET" class="filter-bar" id="filter-form">
        <div class="filter-group">
            <label>Start Date</label>
            <input type="date" name="from" value="<?= htmlspecialchars($filter_from) ?>">
        </div>
        <div class="filter-group">
            <label>End Date</label>
            <input type="date" name="to" value="<?= htmlspecialchars($filter_to) ?>">
        </div>
        <div class="filter-group">
            <label>Cashier</label>
            <select name="user_id">
                <option value="0">All cashiers</option>
                <?php foreach ($cashiers as $c): ?>
                <option value="<?= $c['user_id'] ?>" <?= $filter_user === (int)$c['user_id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($c['username']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn-filter"><i class="fa-solid fa-magnifying-glass"></i> Filter</button>
        <a href="reconciliation_report.php" class="btn-reset"><i class="fa-solid fa-rotate-left"></i> Reset</a>
        <?php /* Carries the three filters through so the PDF matches what is on
                 screen. No page parameter on purpose — the screen paginates, the
                 PDF prints the whole range, or its total would not match its own
                 rows. */ ?>
        <a href="reconciliation_pdf.php?from=<?= urlencode($filter_from) ?>&to=<?= urlencode($filter_to) ?>&user_id=<?= (int)$filter_user ?>"
           target="_blank" rel="noopener" class="btn-reset" title="Export this period as PDF">
            <i class="fa-solid fa-file-pdf"></i> Export PDF
        </a>
    </form>

    <!-- Results box (AJAX target) -->
    <div class="table-wrap" id="results-box">
        <?= $results_html ?>
    </div>

    <?php if ($total > 0 && abs($total_diff) >= 0.01): ?>
    <div class="net-bar" id="net-bar">
        <span class="net-bar-label"><i class="fa-solid fa-scale-balanced" style="margin-right:6px"></i>Net difference this period</span>
        <span class="net-bar-val" style="color:<?= $total_diff >= 0 ? 'var(--yellow)' : 'var(--red)' ?>">
            <?= ($total_diff >= 0 ? '+' : '-') ?>$<?= number_format(abs($total_diff), 2) ?>
        </span>
    </div>
    <?php endif; ?>

    <!-- Stock counts moved to their own page; this report is cash only. -->
    <a href="inventory_count.php" class="inv-jump">
        <span><i class="fa-solid fa-clipboard-list" style="color:var(--amber);margin-right:8px"></i>Looking for inventory stock counts?</span>
        <span class="inv-jump-go">Inventory Count <i class="fa-solid fa-arrow-right" style="font-size:10px"></i></span>
    </a>

</div>

<!-- ── Resolve / detail modal ── -->
<div class="rz-back" id="rzBack">
  <div class="rz" role="dialog" aria-modal="true" aria-labelledby="rzTitle">
    <div class="rz-head">
      <div>
        <div class="rz-title" id="rzTitle">Drawer count</div>
        <div class="rz-sub" id="rzSub"></div>
      </div>
      <button type="button" class="rz-x" id="rzX" aria-label="Close">&times;</button>
    </div>
    <div class="rz-body">
      <div class="rz-grid">
        <div class="rz-cell"><div class="k">Shift Start</div><div class="v" id="rzStart">—</div></div>
        <div class="rz-cell"><div class="k">Shift End</div><div class="v" id="rzEnd">—</div></div>
        <div class="rz-cell"><div class="k">Expected</div><div class="v" id="rzExp">—</div></div>
        <div class="rz-cell"><div class="k">Counted</div><div class="v" id="rzAct">—</div></div>
      </div>
      <div class="rz-cell"><div class="k">Difference</div><div class="v" id="rzDiff">—</div></div>

      <!-- Existing resolution (read-only) -->
      <div id="rzDone" style="display:none">
        <span class="rz-lbl">Investigation outcome</span>
        <div class="rz-note" id="rzDoneNote"></div>
        <div class="rz-meta" id="rzDoneMeta"></div>
      </div>

      <!-- New resolution (manager/admin, unresolved variance only) -->
      <div id="rzForm" style="display:none">
        <span class="rz-lbl">What did you find?</span>
        <textarea class="rz-ta" id="rzNote" maxlength="1000"
          placeholder="e.g. Cashier mistyped a $20 as a $10 on order #1842. Cash recounted with the manager present and the drawer balances."></textarea>
        <div class="rz-err" id="rzErr"></div>
      </div>

      <div id="rzNoPerm" style="display:none;font-size:12.5px;color:var(--muted2)">
        This drawer has not been investigated yet. Only an admin or manager can record an outcome.
      </div>
    </div>
    <div class="rz-foot">
      <button type="button" class="rz-btn" id="rzClose">Close</button>
      <button type="button" class="rz-btn primary" id="rzSave" style="display:none">Save resolution</button>
    </div>
  </div>
</div>

<script>
const CAN_RESOLVE = <?= $can_resolve ? 'true' : 'false' ?>;
const CSRF_TOKEN  = <?= json_encode($_SESSION['csrf_token']) ?>;

// Animate card counters
document.querySelectorAll('.card-val[data-target]').forEach(el => {
    const target = parseInt(el.dataset.target, 10);
    if (target === 0) { el.textContent = '0'; return; }
    const duration = 600;
    const start    = performance.now();
    function step(now) {
        const p = Math.min((now - start) / duration, 1);
        el.textContent = Math.round(p * target);
        if (p < 1) requestAnimationFrame(step);
    }
    setTimeout(() => requestAnimationFrame(step), parseInt(el.closest('.card').style.animationDelay || '0') * 1000 + 200);
});

// AJAX pagination — only swap the results box
document.addEventListener('click', async function(e) {
    const btn = e.target.closest('.page-btn');
    if (!btn) return;
    if (btn.classList.contains('disabled') || btn.classList.contains('active')) return;

    e.preventDefault();
    const href = btn.getAttribute('href');
    if (!href) return;

    const box = document.getElementById('results-box');
    box.classList.add('loading');

    try {
        const sep = href.includes('?') ? '&' : '?';
        const res  = await fetch(href + sep + 'ajax=1');
        const html = await res.text();
        box.innerHTML = html;
        history.pushState(null, '', href);
    } catch(err) {}

    box.classList.remove('loading');
});

/* ── Resolve / detail modal ───────────────────────────────────────────────
   Delegated off document so rows swapped in by AJAX pagination keep working. */
(function(){
    const back = document.getElementById('rzBack');
    if (!back) return;
    const $ = id => document.getElementById(id);
    let activeRow = null, activeData = null, busy = false;

    function open(tr) {
        let d;
        try { d = JSON.parse(tr.getAttribute('data-row')); } catch(e) { return; }
        activeRow = tr; activeData = d;

        $('rzTitle').textContent = d.cashier;
        $('rzSub').textContent   = 'Shift of ' + d.date;
        $('rzStart').textContent = d.start || '—';
        $('rzEnd').textContent   = d.end   || 'Not clocked out';
        $('rzExp').textContent   = '$' + d.expected;
        $('rzAct').textContent   = '$' + d.actual;
        $('rzDiff').textContent  = d.diff;
        $('rzDiff').style.color  = d.status === 'match' ? 'var(--green)'
                                 : d.status === 'over'  ? 'var(--yellow)' : 'var(--red)';

        const resolved = !!d.at;
        $('rzDone').style.display   = resolved ? '' : 'none';
        $('rzForm').style.display   = (d.variance && !resolved && CAN_RESOLVE) ? '' : 'none';
        $('rzNoPerm').style.display = (d.variance && !resolved && !CAN_RESOLVE) ? '' : 'none';
        $('rzSave').style.display   = (d.variance && !resolved && CAN_RESOLVE) ? '' : 'none';

        if (resolved) {
            $('rzDoneNote').textContent = d.note || '';
            $('rzDoneMeta').textContent = 'Resolved by ' + (d.by || '—') + ' · ' + d.at;
        }
        $('rzNote').value = '';
        $('rzErr').style.display = 'none';
        back.classList.add('show');
        if (d.variance && !resolved && CAN_RESOLVE) setTimeout(() => $('rzNote').focus(), 60);
    }

    function close() {
        if (busy) return;
        back.classList.remove('show');
        activeRow = null; activeData = null;
    }

    document.addEventListener('click', e => {
        const btn = e.target.closest('.js-open-row');
        if (btn) { const tr = btn.closest('tr'); if (tr) open(tr); return; }
    });
    $('rzX').addEventListener('click', close);
    $('rzClose').addEventListener('click', close);
    back.addEventListener('click', e => { if (e.target === back) close(); });
    document.addEventListener('keydown', e => { if (e.key === 'Escape' && back.classList.contains('show')) close(); });

    $('rzSave').addEventListener('click', async () => {
        if (busy || !activeData) return;
        const note = $('rzNote').value.trim();
        const err  = $('rzErr');
        if (!note) {
            err.textContent = 'Please describe what you found before saving.';
            err.style.display = 'block';
            $('rzNote').focus();
            return;
        }
        busy = true;
        $('rzSave').disabled = true;
        $('rzSave').textContent = 'Saving…';
        err.style.display = 'none';

        try {
            const fd = new FormData();
            fd.append('action', 'resolve');
            fd.append('csrf_token', CSRF_TOKEN);
            fd.append('count_id', activeData.id);
            fd.append('note', note);
            const res  = await fetch('reconciliation_report.php', { method:'POST', body: fd });
            const data = await res.json();

            if (!data.ok) {
                err.textContent = data.msg || 'Could not save. Try again.';
                err.style.display = 'block';
            } else {
                applyResolved(activeRow, activeData, note, data);
                busy = false;
                close();
            }
        } catch (ex) {
            err.textContent = 'Network error. Try again.';
            err.style.display = 'block';
        }
        busy = false;
        $('rzSave').disabled = false;
        $('rzSave').textContent = 'Save resolution';
    });

    // Repaint the row in place so the table matches the DB without a reload.
    function applyResolved(tr, d, note, data) {
        if (!tr) return;
        d.note = note; d.by = data.resolved_by; d.at = data.resolved_at;
        tr.setAttribute('data-row', JSON.stringify(d));

        const pill = tr.querySelector('.res-pill');
        if (pill) {
            pill.className = 'res-pill done';
            pill.innerHTML = '<i class="fa-solid fa-circle-check"></i> Resolved';
            pill.title = data.resolved_by + ' — ' + data.resolved_at;
        }
        const btn = tr.querySelector('.js-open-row');
        if (btn) {
            btn.className = 'act-btn view js-open-row';
            btn.innerHTML = '<i class="fa-solid fa-file-lines"></i> View note';
        }
        bumpFollowup();
    }

    // Decrement the unresolved counter; flip to the all-clear state at zero.
    function bumpFollowup() {
        const strip = document.querySelector('.followup');
        if (!strip || !strip.classList.contains('open')) return;
        const strong = strip.querySelector('strong');
        if (!strong) return;
        const left = Math.max(0, parseInt(strong.textContent, 10) - 1);
        if (left > 0) { strong.textContent = left; return; }

        const totalTxt = (strip.textContent.match(/of\s+(\d+)\s+variance/) || [,'0'])[1];
        const n = parseInt(totalTxt, 10);
        strip.classList.remove('open');
        strip.classList.add('clear');
        strip.innerHTML = '<span><i class="fa-solid fa-circle-check"></i> All ' + n +
                          ' variance' + (n !== 1 ? 's' : '') + ' investigated and resolved</span>';
    }
})();
</script>
</body>
</html>
