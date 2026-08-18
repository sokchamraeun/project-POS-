<?php
require 'auth.php';
require 'config.php';
require 'dompdf/dompdf/autoload.inc.php';

use Dompdf\Dompdf;
use Dompdf\Options;

$type = $_GET['type'] ?? 'daily';

// Gate by report type: daily = managers only, stock = anyone who can see ingredients
if ($type === 'daily' && !can('report')) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}
if ($type === 'stock' && !can('ingredients')) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

date_default_timezone_set('Asia/Phnom_Penh');
$today = date('d M Y');
$now = date('H:i:s');
$timestamp = date('Y-m-d_H-i-s');

// ── DAILY REPORT ──
if ($type === 'daily') {
    // ── Get today's sales data ──
    $sales_sql = "SELECT IFNULL(SUM(total),0) AS total_sales FROM orders WHERE DATE(order_date) = CURDATE() AND " . paid_orders_where();
    $sales_result = mysqli_query($conn, $sales_sql);
    $sales = mysqli_fetch_assoc($sales_result)['total_sales'];
    
    $order_sql = "SELECT COUNT(*) AS total_orders FROM orders WHERE DATE(order_date) = CURDATE()";
    $order_result = mysqli_query($conn, $order_sql);
    $total_orders = mysqli_fetch_assoc($order_result)['total_orders'];
    
    $avg_sql = "SELECT IFNULL(AVG(total), 0) AS avg_order FROM orders WHERE DATE(order_date) = CURDATE() AND " . paid_orders_where();
    $avg_result = mysqli_query($conn, $avg_sql);
    $avg_order = mysqli_fetch_assoc($avg_result)['avg_order'];
    
    $items_sold_sql = "SELECT IFNULL(SUM(oi.quantity), 0) AS items_sold FROM order_items oi JOIN orders o ON oi.order_id = o.order_id WHERE DATE(o.order_date) = CURDATE() AND " . paid_orders_where('o');
    $items_sold_result = mysqli_query($conn, $items_sold_sql);
    $items_sold = mysqli_fetch_assoc($items_sold_result)['items_sold'];
    
    // ── REFUND DATA (removed) ──
    $total_refunds = 0;
    $refund_count = 0;
    $refund_list = [];
    
    // ── Top selling products ──
    $top_sql = "SELECT p.name, SUM(oi.quantity) AS qty FROM products p JOIN order_items oi ON p.product_id = oi.product_id JOIN orders o ON oi.order_id = o.order_id WHERE DATE(o.order_date) = CURDATE() AND " . paid_orders_where('o') . " GROUP BY p.product_id ORDER BY qty DESC LIMIT 5";
    $top_result = mysqli_query($conn, $top_sql);
    $top_products = [];
    while ($row = mysqli_fetch_assoc($top_result)) {
        $top_products[] = $row;
    }
    
    // ── Category breakdown ──
    $cat_sql = "SELECT COALESCE(p.category, 'Uncategorized') AS category, SUM(oi.quantity) AS qty FROM order_items oi LEFT JOIN products p ON oi.product_id = p.product_id JOIN orders o ON oi.order_id = o.order_id WHERE DATE(o.order_date) = CURDATE() AND " . paid_orders_where('o') . " GROUP BY category ORDER BY qty DESC";
    $cat_result = mysqli_query($conn, $cat_sql);
    $categories = [];
    while ($row = mysqli_fetch_assoc($cat_result)) {
        $categories[] = $row;
    }
    
    // ── Build HTML for PDF ──
    $html = "
    <html>
    <head>
        <style>
            body { font-family: 'DejaVu Sans', 'Poppins', sans-serif; padding: 20px; color: #1e1e1e; }
            h1 { color: #d1904b; text-align: center; }
            .header { text-align: center; margin-bottom: 30px; border-bottom: 2px solid #d1904b; padding-bottom: 10px; }
            .header .subtitle { color: #666; font-size: 14px; margin-top: 4px; }
            .section { margin: 20px 0; }
            .section h2 { color: #d1904b; border-bottom: 1px solid #ddd; padding-bottom: 5px; }
            .section h2.refund-title { color: #9b59b6; }
            .kpi-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 15px; margin: 10px 0; }
            .kpi-box { border: 1px solid #ddd; border-radius: 8px; padding: 15px; text-align: center; background: #f9f9f9; }
            .kpi-box .label { color: #666; font-size: 13px; }
            .kpi-box .value { font-size: 24px; font-weight: 700; color: #d1904b; }
            .kpi-box.refund-box { border-color: #9b59b6; }
            .kpi-box.refund-box .value { color: #9b59b6; }
            table { width: 100%; border-collapse: collapse; margin: 10px 0; }
            th { background: #f5f5f5; padding: 8px; text-align: left; font-size: 12px; color: #666; }
            td { padding: 8px; border-bottom: 1px solid #eee; font-size: 13px; }
            .refund-amount { color: #9b59b6; font-weight: 600; }
            .refund-reason { color: #666; font-style: italic; font-size: 12px; }
            .refund-table th { background: #9b59b6; color: #fff; }
            .footer { text-align: center; margin-top: 30px; color: #999; font-size: 11px; border-top: 1px solid #ddd; padding-top: 10px; }
        </style>
    </head>
    <body>
        <div class='header'>
            <h1>Bird's Nest Coffee</h1>
            <p class='subtitle'>Daily Sales & Refund Report — $today</p>
        </div>
        
        <div class='section'>
            <h2>Key Performance Indicators</h2>
            <div class='kpi-grid'>
                <div class='kpi-box'>
                    <div class='label'>Total Sales</div>
                    <div class='value'>$" . number_format($sales, 2) . "</div>
                </div>
                <div class='kpi-box'>
                    <div class='label'>Total Orders</div>
                    <div class='value'>$total_orders</div>
                </div>
                <div class='kpi-box'>
                    <div class='label'>Avg. Order Value</div>
                    <div class='value'>$" . number_format($avg_order, 2) . "</div>
                </div>
                <div class='kpi-box'>
                    <div class='label'>Items Sold</div>
                    <div class='value'>$items_sold</div>
                </div>
            </div>
        </div>";
        
    // ── REFUND SECTION ──
    if ($refund_count > 0) {
        $html .= "
        <div class='section'>
            <h2 class='refund-title'>🔄 Refund Summary</h2>
            <div class='kpi-grid'>
                <div class='kpi-box refund-box'>
                    <div class='label'>Total Refunds</div>
                    <div class='value'>$" . number_format($total_refunds, 2) . "</div>
                </div>
                <div class='kpi-box refund-box'>
                    <div class='label'>Refunded Orders</div>
                    <div class='value'>$refund_count</div>
                </div>
            </div>";
            
        if (count($refund_list) > 0) {
            $html .= "
            <h3 style='color: #9b59b6; margin-top: 15px;'>Refunded Orders</h3>
            <table class='refund-table'>
                <thead>
                    <tr>
                        <th>Order #</th>
                        <th>Customer</th>
                        <th>Amount</th>
                        <th>Reason</th>
                        <th>Refunded By</th>
                        <th>Time</th>
                    </tr>
                </thead>
                <tbody>";
            foreach ($refund_list as $r) {
                $html .= "<tr>
                            <td>#{$r['daily_order_no']}</td>
                            <td>" . htmlspecialchars($r['customer_name']) . "</td>
                            <td class='refund-amount'>-$" . number_format($r['refund_amount'], 2) . "</td>
                            <td class='refund-reason'>\"" . htmlspecialchars($r['refund_reason']) . "\"</td>
                            <td>" . htmlspecialchars($r['refunded_by']) . "</td>
                            <td>{$r['refund_time']}</td>
                        </tr>";
            }
            $html .= "</tbody></table>";
        }
        
        $html .= "</div>";
    }
    
    // ── TOP PRODUCTS ──
    if (count($top_products) > 0) {
        $html .= "
        <div class='section'>
            <h2>Top Selling Products</h2>
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Product</th>
                        <th>Quantity Sold</th>
                    </tr>
                </thead>
                <tbody>";
        $rank = 1;
        foreach ($top_products as $p) {
            $html .= "<tr>
                        <td>$rank</td>
                        <td>" . htmlspecialchars($p['name']) . "</td>
                        <td>{$p['qty']}</td>
                    </tr>";
            $rank++;
        }
        $html .= "</tbody></table>
        </div>";
    }
    
    // ── CATEGORY BREAKDOWN ──
    if (count($categories) > 0) {
        $html .= "
        <div class='section'>
            <h2>Category Breakdown</h2>
            <table>
                <thead>
                    <tr>
                        <th>Category</th>
                        <th>Items Sold</th>
                    </tr>
                </thead>
                <tbody>";
        foreach ($categories as $c) {
            $html .= "<tr>
                        <td>" . htmlspecialchars($c['category']) . "</td>
                        <td>{$c['qty']}</td>
                    </tr>";
        }
        $html .= "</tbody></table>
        </div>";
    }
    
    $html .= "
        <div class='footer'>
            Generated on $today at $now | Bird's Nest Coffee POS
        </div>
    </body>
    </html>";
    
    // ── Generate PDF ──
    $options = new Options();
    $options->set('isRemoteEnabled', true);
    $options->set('defaultFont', 'DejaVu Sans');
    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    
    // ── Save PDF to reports folder ──
    $pdfPath = 'reports/daily_report_' . $timestamp . '.pdf';
    if (!is_dir('reports')) mkdir('reports', 0777, true);
    file_put_contents($pdfPath, $dompdf->output());
    
    echo json_encode(['success' => true, 'message' => '✅ Daily Report saved successfully']);
    exit;
}

// ── STOCK ALERT ──
if ($type === 'stock') {
    $low_items = [];
    $total_value_at_risk = 0;
    $most_critical = null;
    $max_needed = 0;
    
    if (count($low_items) === 0) {
        echo json_encode(['success' => true, 'message' => '✅ No low stock items to report']);
        exit;
    }
    
    // ── Convert logo to base64 ──
    $logoPath = 'images/Newlogo.jpg';
    $logoData = base64_encode(file_get_contents($logoPath));
    $logoSrc = 'data:image/jpeg;base64,' . $logoData;
    
    // ── Build HTML for PDF ──
    $html = "
    <html>
    <head>
        <style>
            body { font-family: 'DejaVu Sans', 'Poppins', sans-serif; padding: 20px; color: #1e1e1e; }
            .header { text-align: center; margin-bottom: 30px; border-bottom: 2px solid #d1904b; padding-bottom: 10px; }
            .header img { width: 60px; height: 60px; border-radius: 50%; margin-bottom: 10px; }
            .header h1 { color: #d1904b; font-size: 24px; margin: 0; }
            .header p { color: #666; font-size: 14px; margin: 4px 0; }
            .summary-box { background: #f8f9fa; border: 1px solid #ddd; border-radius: 8px; padding: 15px; margin: 20px 0; }
            .summary-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px; }
            .summary-item { text-align: center; }
            .summary-item .label { color: #666; font-size: 12px; }
            .summary-item .value { font-size: 18px; font-weight: 700; color: #d1904b; }
            table { width: 100%; border-collapse: collapse; margin: 20px 0; }
            th { background: #d1904b; color: #fff; padding: 10px; text-align: left; font-size: 12px; }
            td { padding: 10px; border-bottom: 1px solid #eee; font-size: 13px; }
            .low-stock { color: #e74c3c; font-weight: 600; }
            .critical { background: #fff3f3; }
            .action { margin: 20px 0; padding: 15px; border-left: 4px solid #e74c3c; background: #fdf2f2; }
            .action p { margin: 0; font-size: 14px; }
            .footer { text-align: center; margin-top: 30px; color: #999; font-size: 11px; border-top: 1px solid #ddd; padding-top: 10px; }
            .footer .contact { display: flex; justify-content: center; gap: 20px; margin: 5px 0; font-size: 11px; }
        </style>
    </head>
    <body>
        <div class='header'>
            <img src='$logoSrc' alt='Bird's Nest Coffee Logo'>
            <h1>⚠️ Low Stock Alert</h1>
            <p>Bird's Nest Coffee — $today</p>
        </div>
        
        <div class='summary-box'>
            <div class='summary-grid'>
                <div class='summary-item'>
                    <div class='label'>Total Low Stock Items</div>
                    <div class='value'>" . count($low_items) . "</div>
                </div>
                <div class='summary-item'>
                    <div class='label'>Most Critical</div>
                    <div class='value'>" . htmlspecialchars($most_critical) . "</div>
                </div>
            </div>
        </div>
        
        <table>
            <thead>
                <tr>
                    <th>Ingredient</th>
                    <th>Current Stock</th>
                    <th>Minimum Stock</th>
                    <th>Needed</th>
                </tr>
            </thead>
            <tbody>";
    foreach ($low_items as $item) {
        $needed = $item['minimum_stock'] - $item['stock_quantity'];
        $is_critical = ($needed == $max_needed) ? 'critical' : '';
        $html .= "<tr class='$is_critical'>
                    <td>" . htmlspecialchars($item['ingredient_name']) . "</td>
                    <td class='low-stock'>{$item['stock_quantity']} {$item['unit']}</td>
                    <td>{$item['minimum_stock']} {$item['unit']}</td>
                    <td class='low-stock'>{$needed} {$item['unit']}</td>
                </tr>";
    }
    $html .= "</tbody></table>
        
        <div class='action'>
            <p><strong>Action Required:</strong> Please order the above items immediately to avoid running out of stock.</p>
        </div>
        
        <div class='footer'>
            <p>Generated on $today at $now | Bird's Nest Coffee POS</p>
            <div class='contact'>
                <span>📞 +855 123 456 789</span>
                <span>✉️ info@birdsnestcoffee.com</span>
                <span>📍 Phnom Penh, Cambodia</span>
            </div>
        </div>
    </body>
    </html>";
    
    // ── Generate PDF ──
    $options = new Options();
    $options->set('isRemoteEnabled', true);
    $options->set('defaultFont', 'DejaVu Sans');
    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    
    // ── Save PDF to stock_alerts folder ──
    $pdfPath = 'stock_alerts/stock_alert_' . $timestamp . '.pdf';
    if (!is_dir('stock_alerts')) mkdir('stock_alerts', 0777, true);
    file_put_contents($pdfPath, $dompdf->output());
    
    echo json_encode(['success' => true, 'message' => '✅ Stock Alert saved successfully']);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid request']);
?>