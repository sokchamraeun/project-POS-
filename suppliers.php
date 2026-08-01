<?php
require 'auth.php';
require 'config.php';
if (!can('suppliers')) { header("Location: dashboard.php?denied=1"); exit; }

// ── Auto-create tables ──
$conn->query("CREATE TABLE IF NOT EXISTS suppliers (
    supplier_id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    contact_person VARCHAR(100) DEFAULT '',
    phone VARCHAR(30) DEFAULT '',
    email VARCHAR(100) DEFAULT '',
    address TEXT,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$conn->query("CREATE TABLE IF NOT EXISTS purchase_orders (
    po_id INT AUTO_INCREMENT PRIMARY KEY,
    po_number VARCHAR(20) NOT NULL,
    supplier_id INT NOT NULL,
    status ENUM('Draft','Ordered','Received','Cancelled') DEFAULT 'Draft',
    notes TEXT,
    total_cost DECIMAL(10,2) DEFAULT 0.00,
    ordered_at DATETIME,
    received_at DATETIME,
    created_by VARCHAR(100) DEFAULT '',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (supplier_id) REFERENCES suppliers(supplier_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$conn->query("CREATE TABLE IF NOT EXISTS purchase_order_items (
    poi_id INT AUTO_INCREMENT PRIMARY KEY,
    po_id INT NOT NULL,
    ingredient_id INT NOT NULL,
    qty_ordered DECIMAL(10,3) NOT NULL DEFAULT 0,
    unit_cost DECIMAL(10,2) NOT NULL DEFAULT 0,
    FOREIGN KEY (po_id) REFERENCES purchase_orders(po_id) ON DELETE CASCADE,
    FOREIGN KEY (ingredient_id) REFERENCES ingredients(ingredient_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$conn->query("CREATE TABLE IF NOT EXISTS stock_refills (
    refill_id INT AUTO_INCREMENT PRIMARY KEY,
    ingredient_id INT NOT NULL,
    purchase_qty DECIMAL(10,2) NOT NULL,
    notes VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ── Migrate: add supplier_id to ingredients if missing ──
$colCheck = $conn->query("SHOW COLUMNS FROM ingredients LIKE 'supplier_id'");
if ($colCheck && $colCheck->num_rows === 0) {
    $conn->query("ALTER TABLE ingredients ADD COLUMN supplier_id INT NULL, ADD INDEX idx_ing_supplier (supplier_id)");
}

// ── AJAX: Add ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
    header('Content-Type: application/json');
    $name = trim($_POST['name'] ?? '');
    if (!$name) { echo json_encode(['success'=>false,'message'=>'Name is required']); exit; }
    $contact = trim($_POST['contact_person'] ?? '');
    $phone   = trim($_POST['phone'] ?? '');
    $email   = trim($_POST['email'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $notes   = trim($_POST['notes'] ?? '');
    $stmt = $conn->prepare("INSERT INTO suppliers (name,contact_person,phone,email,address,notes) VALUES (?,?,?,?,?,?)");
    $stmt->bind_param('ssssss', $name, $contact, $phone, $email, $address, $notes);
    $stmt->execute();
    echo json_encode(['success'=>true,'id'=>$conn->insert_id]);
    exit;
}

// ── AJAX: Edit ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit') {
    header('Content-Type: application/json');
    $id   = (int)($_POST['supplier_id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    if (!$id || !$name) { echo json_encode(['success'=>false,'message'=>'Invalid']); exit; }
    $contact = trim($_POST['contact_person'] ?? '');
    $phone   = trim($_POST['phone'] ?? '');
    $email   = trim($_POST['email'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $notes   = trim($_POST['notes'] ?? '');
    $stmt = $conn->prepare("UPDATE suppliers SET name=?,contact_person=?,phone=?,email=?,address=?,notes=? WHERE supplier_id=?");
    $stmt->bind_param('ssssssi', $name, $contact, $phone, $email, $address, $notes, $id);
    $stmt->execute();
    echo json_encode(['success'=>true]);
    exit;
}

// ── AJAX: Delete ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    header('Content-Type: application/json');
    $id = (int)($_POST['supplier_id'] ?? 0);
    if (!$id) { echo json_encode(['success'=>false,'message'=>'Invalid']); exit; }
    $chk = $conn->prepare("SELECT COUNT(*) AS cnt FROM purchase_orders WHERE supplier_id=?");
    $chk->bind_param('i', $id);
    $chk->execute();
    $cnt = $chk->get_result()->fetch_assoc()['cnt'];
    if ($cnt > 0) { echo json_encode(['success'=>false,'message'=>"Cannot delete — supplier has $cnt purchase order(s)"]); exit; }
    // Clear supplier link from any ingredients before deleting
    $conn->query("UPDATE ingredients SET supplier_id = NULL WHERE supplier_id = $id");
    $stmt = $conn->prepare("DELETE FROM suppliers WHERE supplier_id=?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    echo json_encode(['success'=>$stmt->affected_rows>0]);
    exit;
}

// ── Load data ──
$suppliers = [];
$res = $conn->query("
    SELECT s.*,
           COUNT(p.po_id)          AS po_count,
           IFNULL(SUM(p.total_cost),0) AS total_spent,
           -- Last time we actually placed an order. Draft POs have a NULL
           -- ordered_at (not ordered yet) and Cancelled ones never happened,
           -- so neither should count as 'we restocked from them'. Partially
           -- Received was definitely ordered, so it counts too.
           MAX(CASE WHEN p.status IN ('Ordered','Partially Received','Received') THEN p.ordered_at END) AS last_ordered_at
    FROM suppliers s
    LEFT JOIN purchase_orders p ON p.supplier_id = s.supplier_id
    GROUP BY s.supplier_id
    ORDER BY s.name ASC
");
while ($r = $res->fetch_assoc()) $suppliers[] = $r;

$total_suppliers = count($suppliers);
$total_pos       = (int)array_sum(array_column($suppliers,'po_count'));
$total_spent     = (float)array_sum(array_column($suppliers,'total_spent'));

function he($s){ return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8'); }

/* Relative age of the last order — the actual question a manager is asking is
   "is it time to reorder?", which a bare date makes them work out in their head. */
function ago_label(int $days): string {
    if ($days <= 0)  return 'today';
    if ($days === 1) return 'yesterday';
    if ($days < 7)   return $days . ' days ago';
    if ($days < 14)  return 'last week';
    if ($days < 60)  { $w = (int)round($days / 7); return $w . ' weeks ago'; }
    $m = (int)round($days / 30);
    return $m . ' month' . ($m !== 1 ? 's' : '') . ' ago';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Suppliers | Bird's Nest Coffee</title>
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

/* ── TOP BAR ── */
.topbar{
    display:flex;align-items:center;gap:12px;
    padding:18px 32px;border-bottom:1px solid var(--border);
    background:var(--bg-card);position:sticky;top:0;z-index:100;
}
.topbar-back{
    display:flex;align-items:center;gap:7px;color:#d1904b;
    text-decoration:none;font-size:13px;font-weight:600;
    padding:7px 14px;border-radius:10px;border:1px solid rgba(209,144,75,.35);
    background:rgba(209,144,75,.08);transition:var(--transition);
}
.topbar-back:hover{background:rgba(209,144,75,.16);border-color:#d1904b;}
.topbar-back:hover{color:var(--accent);border-color:var(--accent);}
.topbar-title{font-size:18px;font-weight:700;color:var(--text-light);flex:1;}
.topbar-title i{color:var(--accent);margin-right:8px;}
.btn{
    display:inline-flex;align-items:center;gap:7px;
    padding:8px 18px;border-radius:9px;border:none;
    font-family:'Poppins',sans-serif;font-size:13px;font-weight:600;
    cursor:pointer;transition:var(--transition);text-decoration:none;
}
.btn-accent{background:linear-gradient(135deg,var(--accent),var(--accent-light));color:#000;}
.btn-accent:hover{transform:translateY(-2px);box-shadow:var(--shadow-accent);filter:brightness(1.08);}
.btn-outline{background:transparent;color:var(--text-muted);border:1px solid var(--border);}
.btn-outline:hover{color:var(--accent);border-color:var(--accent);}
.btn-danger{background:rgba(255,95,95,.12);color:var(--danger);border:1px solid rgba(255,95,95,.2);}
.btn-danger:hover{background:rgba(255,95,95,.22);}
.btn-sm{padding:5px 12px;font-size:12px;}

/* ── CONTENT ── */
.content{max-width:1100px;margin:0 auto;padding:32px 24px;}

/* ── STATS ── */
.stats-row{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:28px;}
.stat-card{
    background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius);
    padding:20px 24px;display:flex;align-items:center;gap:16px;
    animation:fadeUp .4s ease both;
}
.stat-card:nth-child(2){animation-delay:.06s;}
.stat-card:nth-child(3){animation-delay:.12s;}
.stat-icon{
    width:44px;height:44px;border-radius:10px;
    display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0;
}
.stat-icon.orange{background:rgba(209,144,75,.15);color:var(--accent);}
.stat-icon.green{background:rgba(85,224,135,.12);color:var(--success);}
.stat-icon.blue{background:rgba(52,152,219,.12);color:var(--info);}
.stat-val{font-size:22px;font-weight:700;color:var(--text-light);}
.stat-lbl{font-size:12px;color:var(--text-muted);}

/* ── SECTION HEADER ── */
.section-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;}
.section-title{font-size:15px;font-weight:700;color:var(--text-light);}

/* ── TABLE ── */
.table-wrap{background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden;animation:fadeUp .45s .18s ease both;}
table{width:100%;border-collapse:collapse;}
thead th{
    padding:12px 18px;text-align:left;
    font-size:11px;font-weight:600;color:var(--text-muted);
    text-transform:uppercase;letter-spacing:.5px;
    border-bottom:1px solid var(--border);background:rgba(255,255,255,.02);
}
tbody td{padding:14px 18px;border-bottom:1px solid var(--border);font-size:13px;vertical-align:middle;}
tbody tr:last-child td{border-bottom:none;}
tbody tr:hover td{background:rgba(255,255,255,.025);}
.supplier-name{font-weight:600;color:var(--text-light);}
.supplier-meta{font-size:11px;color:var(--text-muted);margin-top:2px;}
.badge{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:600;}
.badge-accent{background:rgba(209,144,75,.15);color:var(--accent);}
.badge-muted{background:rgba(255,255,255,.06);color:var(--text-muted);}
.last-order{font-size:13px;color:var(--text);white-space:nowrap;}
.last-order-ago{font-size:11px;color:var(--text-muted);margin-top:2px;white-space:nowrap;}
.last-order.stale,.last-order-ago.stale{color:var(--warning);}
.actions{display:flex;gap:6px;justify-content:flex-end;}
.actions .btn{white-space:nowrap;}
.empty-state{text-align:center;padding:60px 20px;color:var(--text-muted);}
.empty-state i{font-size:48px;color:var(--border-hover);display:block;margin-bottom:12px;}
.empty-state p{font-size:14px;}

/* ── MODAL ── */
.modal-overlay{
    position:fixed;inset:0;background:rgba(0,0,0,.65);
    display:flex;align-items:center;justify-content:center;
    z-index:1000;opacity:0;pointer-events:none;transition:opacity .2s;
}
.modal-overlay.open{opacity:1;pointer-events:all;}
.modal{
    background:var(--bg-card);border:1px solid var(--border-hover);
    border-radius:18px;padding:32px;width:min(520px,calc(100vw - 32px));
    box-shadow:var(--shadow-lg);
    transform:translateY(16px);transition:transform .25s ease;
}
.modal-overlay.open .modal{transform:translateY(0);}
.modal-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;}
.modal-title{font-size:17px;font-weight:700;color:var(--text-light);}
.modal-close{background:none;border:none;color:var(--text-muted);font-size:18px;cursor:pointer;padding:4px;transition:var(--transition);}
.modal-close:hover{color:var(--danger);}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:14px;}
.field{margin-bottom:14px;}
.field label{display:block;font-size:11px;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px;}
.field input,.field textarea{
    width:100%;background:var(--bg-input);border:1px solid var(--border);
    border-radius:8px;padding:10px 14px;color:var(--text);font-family:'Poppins',sans-serif;font-size:13px;
    transition:var(--transition);resize:vertical;
}
.field input:focus,.field textarea:focus{outline:none;border-color:var(--accent);box-shadow:0 0 0 3px rgba(209,144,75,.12);}
.modal-footer{display:flex;gap:10px;justify-content:flex-end;margin-top:8px;}

/* ── ANIMATIONS ── */
@keyframes fadeUp{from{opacity:0;transform:translateY(16px)}to{opacity:1;transform:translateY(0)}}

/* ── TOAST ── */
.toast{
    position:fixed;bottom:24px;right:24px;z-index:9999;
    padding:12px 20px;border-radius:10px;font-size:13px;font-weight:600;
    display:flex;align-items:center;gap:10px;
    transform:translateY(80px);opacity:0;transition:all .3s ease;
    box-shadow:var(--shadow-lg);
}
.toast.show{transform:translateY(0);opacity:1;}
.toast.success{background:#1a3a25;color:var(--success);border:1px solid rgba(85,224,135,.2);}
.toast.error{background:#3a1a1a;color:var(--danger);border:1px solid rgba(255,95,95,.2);}

@media(max-width:700px){
    .topbar{padding:14px 16px;}
    .content{padding:20px 14px;}
    .stats-row{grid-template-columns:1fr;}
    .form-row{grid-template-columns:1fr;}
    thead th:nth-child(3),thead th:nth-child(4),
    tbody td:nth-child(3),tbody td:nth-child(4){display:none;}
}
/* Light theme (follows shared localStorage theme) */
[data-theme="light"]{--bg:#ECEEF2;--bg-card:#FFFFFF;--bg-input:#F5F7FA;--border:#E2E5EA;--border-hover:#CDD0D8;--text:#111827;--text-muted:#5A6373;--text-light:#0B0F19;}
[data-theme="light"] .topbar{background:rgba(255,255,255,.92);}
</style>
</head>
<body>

<!-- TOP BAR -->
<div class="topbar">
    <a class="topbar-back" href="dashboard.php"><i class="fa-solid fa-arrow-left"></i> Dashboard</a>
    <div class="topbar-title"><i class="fa-solid fa-truck-ramp-box"></i> Suppliers</div>
    <a class="btn btn-outline" href="purchase_orders.php"><i class="fa-solid fa-file-invoice"></i> Purchase Orders</a>
    <button class="btn btn-accent" onclick="openAddModal()"><i class="fa-solid fa-plus"></i> Add Supplier</button>
</div>

<div class="content">
    <!-- STATS -->
    <div class="stats-row">
        <div class="stat-card">
            <div class="stat-icon orange"><i class="fa-solid fa-truck-ramp-box"></i></div>
            <div><div class="stat-val"><?= $total_suppliers ?></div><div class="stat-lbl">Total Suppliers</div></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon blue"><i class="fa-solid fa-file-invoice"></i></div>
            <div><div class="stat-val"><?= $total_pos ?></div><div class="stat-lbl">Purchase Orders</div></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon green"><i class="fa-solid fa-dollar-sign"></i></div>
            <div><div class="stat-val">$<?= number_format($total_spent,2) ?></div><div class="stat-lbl">Total Spent</div></div>
        </div>
    </div>

    <!-- TABLE -->
    <div class="section-header">
        <div class="section-title">All Suppliers</div>
    </div>
    <div class="table-wrap">
        <?php if (empty($suppliers)): ?>
        <div class="empty-state">
            <i class="fa-solid fa-truck-ramp-box"></i>
            <p>No suppliers yet. Add your first supplier to get started.</p>
        </div>
        <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Supplier</th>
                    <th>Contact</th>
                    <th>Phone / Email</th>
                    <th style="text-align:right">POs</th>
                    <th>Last Order</th>
                    <th style="text-align:right">Total Spent</th>
                    <th style="text-align:right">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($suppliers as $s): ?>
            <tr>
                <td>
                    <div class="supplier-name"><?= he($s['name']) ?></div>
                    <?php if ($s['address']): ?><div class="supplier-meta"><i class="fa-solid fa-location-dot" style="width:12px"></i> <?= he($s['address']) ?></div><?php endif; ?>
                </td>
                <td><?= he($s['contact_person']) ?: '<span style="color:var(--text-muted)">—</span>' ?></td>
                <td>
                    <?php if ($s['phone']): ?><div><?= he($s['phone']) ?></div><?php endif; ?>
                    <?php if ($s['email']): ?><div style="color:var(--text-muted);font-size:12px"><?= he($s['email']) ?></div><?php endif; ?>
                    <?php if (!$s['phone'] && !$s['email']): ?><span style="color:var(--text-muted)">—</span><?php endif; ?>
                </td>
                <td style="text-align:right">
                    <span class="badge badge-accent"><?= (int)$s['po_count'] ?></span>
                </td>
                <?php
                    $last = $s['last_ordered_at'];
                    // Amber past 30 days: long enough that a cafe owner should be
                    // asking whether it is time to reorder from this supplier.
                    $days = $last ? (int)floor((time() - strtotime($last)) / 86400) : null;
                    $stale = $days !== null && $days > 30;
                ?>
                <td>
                    <?php if ($last): ?>
                        <div class="last-order<?= $stale ? ' stale' : '' ?>"><?= date('M j, Y', strtotime($last)) ?></div>
                        <div class="last-order-ago<?= $stale ? ' stale' : '' ?>"><?= he(ago_label($days)) ?></div>
                    <?php else: ?>
                        <span style="color:var(--text-muted)">Never ordered</span>
                    <?php endif; ?>
                </td>
                <td style="text-align:right;font-weight:600;color:var(--success)">
                    $<?= number_format($s['total_spent'],2) ?>
                </td>
                <td>
                    <div class="actions">
                        <a class="btn btn-outline btn-sm" href="purchase_order_create.php?supplier_id=<?= $s['supplier_id'] ?>">
                            <i class="fa-solid fa-plus"></i> New PO
                        </a>
                        <button class="btn btn-outline btn-sm" onclick='editSupplier(<?= json_encode($s) ?>)'>
                            <i class="fa-solid fa-pen"></i>
                        </button>
                        <button class="btn btn-danger btn-sm" onclick="deleteSupplier(<?= $s['supplier_id'] ?>, '<?= he($s['name']) ?>')">
                            <i class="fa-solid fa-trash"></i>
                        </button>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<!-- ADD / EDIT MODAL -->
<div class="modal-overlay" id="supplierModal">
    <div class="modal">
        <div class="modal-header">
            <div class="modal-title" id="modalTitle">Add Supplier</div>
            <button class="modal-close" onclick="closeModal()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form id="supplierForm">
            <input type="hidden" id="fAction" name="action" value="add">
            <input type="hidden" id="fId" name="supplier_id" value="">
            <div class="field">
                <label>Supplier Name *</label>
                <input type="text" id="fName" name="name" placeholder="e.g. Bird's Feed Trading" required>
            </div>
            <div class="form-row">
                <div class="field">
                    <label>Contact Person</label>
                    <input type="text" id="fContact" name="contact_person" placeholder="Full name">
                </div>
                <div class="field">
                    <label>Phone</label>
                    <input type="text" id="fPhone" name="phone" placeholder="012 345 678">
                </div>
            </div>
            <div class="field">
                <label>Email</label>
                <input type="email" id="fEmail" name="email" placeholder="supplier@example.com">
            </div>
            <div class="field">
                <label>Address</label>
                <input type="text" id="fAddress" name="address" placeholder="Street, City">
            </div>
            <div class="field">
                <label>Notes</label>
                <textarea id="fNotes" name="notes" rows="2" placeholder="Payment terms, delivery schedule…"></textarea>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal()">Cancel</button>
                <button type="submit" class="btn btn-accent" id="modalSubmit"><i class="fa-solid fa-floppy-disk"></i> Save</button>
            </div>
        </form>
    </div>
</div>

<!-- TOAST -->
<div class="toast" id="toast"></div>

<script>
const overlay = document.getElementById('supplierModal');

function openAddModal() {
    document.getElementById('modalTitle').textContent = 'Add Supplier';
    document.getElementById('fAction').value = 'add';
    document.getElementById('fId').value = '';
    document.getElementById('supplierForm').reset();
    overlay.classList.add('open');
}

function editSupplier(s) {
    document.getElementById('modalTitle').textContent = 'Edit Supplier';
    document.getElementById('fAction').value = 'edit';
    document.getElementById('fId').value = s.supplier_id;
    document.getElementById('fName').value = s.name || '';
    document.getElementById('fContact').value = s.contact_person || '';
    document.getElementById('fPhone').value = s.phone || '';
    document.getElementById('fEmail').value = s.email || '';
    document.getElementById('fAddress').value = s.address || '';
    document.getElementById('fNotes').value = s.notes || '';
    overlay.classList.add('open');
}

function closeModal() { overlay.classList.remove('open'); }
overlay.addEventListener('click', e => { if (e.target === overlay) closeModal(); });

document.getElementById('supplierForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const btn = document.getElementById('modalSubmit');
    btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving…';
    const data = new FormData(this);
    const res = await fetch('suppliers.php', {method:'POST', body:data});
    const json = await res.json();
    btn.disabled = false;
    btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save';
    if (json.success) {
        showToast('Saved successfully', 'success');
        setTimeout(() => location.reload(), 700);
    } else {
        showToast(json.message || 'Error saving', 'error');
    }
});

function deleteSupplier(id, name) {
    if (!confirm(`Delete supplier "${name}"?\nThis cannot be undone.`)) return;
    const fd = new FormData();
    fd.append('action','delete');
    fd.append('supplier_id', id);
    fetch('suppliers.php', {method:'POST', body:fd})
        .then(r => r.json())
        .then(json => {
            if (json.success) { showToast('Supplier deleted', 'success'); setTimeout(() => location.reload(), 700); }
            else showToast(json.message || 'Cannot delete', 'error');
        });
}

function showToast(msg, type='success') {
    const t = document.getElementById('toast');
    t.className = `toast ${type}`;
    t.innerHTML = `<i class="fa-solid fa-${type==='success'?'check-circle':'circle-exclamation'}"></i> ${msg}`;
    t.classList.add('show');
    setTimeout(() => t.classList.remove('show'), 3000);
}
</script>
</body>
</html>
