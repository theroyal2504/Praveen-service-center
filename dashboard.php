<?php
require_once 'config.php';

if (!isLoggedIn()) {
    redirect('index.php');
}

// Get dashboard statistics
$stats = [];

// Total customers
$result = mysqli_query($conn, "SELECT COUNT(*) as count FROM customers");
$stats['customers'] = mysqli_fetch_assoc($result)['count'];

// Total bikes (parts in stock)
$result = mysqli_query($conn, "SELECT COUNT(DISTINCT part_id) as count FROM stock WHERE quantity > 0");
$stats['bikes'] = mysqli_fetch_assoc($result)['count'];

// Pending jobs
$result = mysqli_query($conn, "SELECT COUNT(*) as count FROM pending_jobs WHERE status = 'pending'");
$stats['pending_jobs'] = mysqli_fetch_assoc($result)['count'];

// Low stock items
$result = mysqli_query($conn, "SELECT COUNT(*) as count FROM stock s JOIN parts_master p ON s.part_id = p.id WHERE s.quantity <= s.min_stock_level");
$stats['low_stock'] = mysqli_fetch_assoc($result)['count'];

// Get today's sales
$today = date('Y-m-d');
$result = mysqli_query($conn, "SELECT SUM(total_amount) as today_sales, COUNT(*) as today_transactions FROM sales WHERE sale_date = '$today'");
$today_data = mysqli_fetch_assoc($result);

// Get outstanding dues
$dues_query = mysqli_query($conn, "SELECT 
                                    COUNT(*) as total_due_invoices,
                                    SUM(CASE 
                                        WHEN grand_total IS NOT NULL AND grand_total > 0 
                                        THEN (grand_total - paid_amount)
                                        ELSE (total_amount - paid_amount)
                                    END) as total_due_amount
                                FROM sales 
                                WHERE (CASE 
                                        WHEN grand_total IS NOT NULL AND grand_total > 0 
                                        THEN grand_total
                                        ELSE total_amount
                                    END) > paid_amount");
$dues = mysqli_fetch_assoc($dues_query);

// Get recent dues
$recent_dues = mysqli_query($conn, "SELECT 
                                    s.id, 
                                    s.invoice_number, 
                                    s.sale_date, 
                                    s.total_amount,
                                    s.grand_total,
                                    s.paid_amount, 
                                    s.due_amount,
                                    s.payment_status,
                                    s.discount_type,
                                    s.discount_value,
                                    s.discount_amount,
                                    s.subtotal,
                                    COALESCE(s.grand_total, s.total_amount) as actual_total,
                                    (COALESCE(s.grand_total, s.total_amount) - s.paid_amount) as actual_due_amount,
                                    c.customer_name, 
                                    c.phone, 
                                    c.vehicle_registration,
                                    c.id as customer_id
                                  FROM sales s
                                  LEFT JOIN customers c ON s.customer_id = c.id
                                  WHERE (COALESCE(s.grand_total, s.total_amount) - s.paid_amount) > 0
                                  ORDER BY s.sale_date DESC
                                  LIMIT 15");

// ============ SUPPLIER PRICE COMPARE SECTION ============

// Query to get all suppliers
$suppliers_query = "SELECT id, supplier_name, contact_person, phone, email FROM suppliers ORDER BY supplier_name";
$suppliers_result = mysqli_query($conn, $suppliers_query);

// Query to get all parts with their latest purchase prices from each supplier
$supplier_price_compare_query = "
    SELECT 
        p.id as part_id,
        p.part_number,
        p.part_name,
        c.category_name,
        s.id as supplier_id,
        s.supplier_name,
        pi.purchase_price,
        pi.quantity,
        pur.purchase_date,
        pur.invoice_number,
        (
            SELECT purchase_price 
            FROM purchase_items pi2 
            WHERE pi2.part_id = p.id 
            ORDER BY pi2.id DESC 
            LIMIT 1
        ) as latest_price
    FROM parts_master p
    LEFT JOIN categories c ON p.category_id = c.id
    LEFT JOIN purchase_items pi ON p.id = pi.part_id
    LEFT JOIN purchases pur ON pi.purchase_id = pur.id
    LEFT JOIN suppliers s ON pur.supplier_id = s.id
    WHERE pi.id IS NOT NULL AND s.id IS NOT NULL
    ORDER BY p.part_name, s.supplier_name, pur.purchase_date DESC";

$supplier_price_compare_result = mysqli_query($conn, $supplier_price_compare_query);

// Organize data for matrix display
$price_matrix = [];
$all_parts = [];
$all_suppliers = [];

if ($supplier_price_compare_result && mysqli_num_rows($supplier_price_compare_result) > 0) {
    while($row = mysqli_fetch_assoc($supplier_price_compare_result)) {
        $part_id = $row['part_id'];
        $supplier_id = $row['supplier_id'];
        
        // Store unique parts
        if (!isset($all_parts[$part_id])) {
            $all_parts[$part_id] = [
                'id' => $part_id,
                'part_number' => $row['part_number'],
                'part_name' => $row['part_name'],
                'category' => $row['category_name'] ?? 'Uncategorized',
                'latest_price' => $row['latest_price']
            ];
        }
        
        // Store unique suppliers
        if (!isset($all_suppliers[$supplier_id])) {
            $all_suppliers[$supplier_id] = [
                'id' => $supplier_id,
                'name' => $row['supplier_name']
            ];
        }
        
        // Store price in matrix
        $price_matrix[$part_id][$supplier_id] = [
            'price' => $row['purchase_price'],
            'quantity' => $row['quantity'],
            'date' => $row['purchase_date'],
            'invoice' => $row['invoice_number']
        ];
    }
}

// Get price statistics for each part
$part_stats = [];
foreach ($all_parts as $part_id => $part) {
    if (isset($price_matrix[$part_id]) && count($price_matrix[$part_id]) > 1) {
        $prices = array_column($price_matrix[$part_id], 'price');
        $min_price = min($prices);
        $max_price = max($prices);
        $avg_price = array_sum($prices) / count($prices);
        
        $part_stats[$part_id] = [
            'min_price' => $min_price,
            'max_price' => $max_price,
            'avg_price' => $avg_price,
            'difference' => $max_price - $min_price,
            'variation_percent' => $min_price > 0 ? round(($max_price - $min_price) / $min_price * 100, 2) : 0,
            'supplier_count' => count($price_matrix[$part_id])
        ];
    }
}

// ============ END OF SUPPLIER PRICE COMPARE SECTION ============

// ============ EXPENSES SECTION ============

// Get today's operational expenses
$today_ops_query = mysqli_query($conn, "SELECT SUM(total_amount) as today_ops FROM operational_expenses WHERE expense_date = '$today'");
$today_ops = mysqli_fetch_assoc($today_ops_query)['today_ops'] ?? 0;

// Get this month's operational expenses
$month_start = date('Y-m-01');
$month_end = date('Y-m-t');
$month_ops_query = mysqli_query($conn, "SELECT SUM(total_amount) as month_ops FROM operational_expenses WHERE expense_date BETWEEN '$month_start' AND '$month_end'");
$month_ops = mysqli_fetch_assoc($month_ops_query)['month_ops'] ?? 0;

// Get today's purchases (stock items)
$today_purchase_query = mysqli_query($conn, "SELECT SUM(total_amount) as today_purchase FROM purchases WHERE purchase_date = '$today' AND status = 'completed'");
$today_purchase = mysqli_fetch_assoc($today_purchase_query)['today_purchase'] ?? 0;

// Get total operational expenses
$total_ops_query = mysqli_query($conn, "SELECT SUM(total_amount) as total_ops FROM operational_expenses");
$total_ops = mysqli_fetch_assoc($total_ops_query)['total_ops'] ?? 0;

// Get total purchases (all time)
$total_purchase_query = mysqli_query($conn, "SELECT SUM(total_amount) as total_purchase FROM purchases WHERE status = 'completed'");
$total_purchase = mysqli_fetch_assoc($total_purchase_query)['total_purchase'] ?? 0;

// Get recent expenses count
$recent_expenses_query = mysqli_query($conn, "SELECT COUNT(*) as count FROM operational_expenses WHERE expense_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)");
$recent_count = mysqli_fetch_assoc($recent_expenses_query)['count'] ?? 0;

// Get top 3 expense categories
$category_summary_query = mysqli_query($conn, "SELECT category, SUM(total_amount) as total 
                                              FROM operational_expenses 
                                              GROUP BY category 
                                              ORDER BY total DESC 
                                              LIMIT 3");

// ============ END OF EXPENSES SECTION ============

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Bike Management System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <style>
        /* Statistics Cards (larger) */
        .row > .col-md-3 { padding-bottom: 8px; }
        .row > .col-md-3 .card .card-body { padding: 10px; }
        .row > .col-md-3 .card .card-body h6 { font-size: 14px; margin-bottom: 4px; }
        .row > .col-md-3 .card .card-body h2 { font-size: 22px; margin-bottom: 4px; }
        .row > .col-md-3 .card .card-body small { font-size: 12px; display:block; margin-top:0; }
        .row > .col-md-3 i.bi { font-size: 28px; }

        /* Reduce gap between Statistics Cards and Today's Performance */
        .statistics-cards { margin-bottom: 6px; }
        .statistics-cards .col-md-3 { margin-bottom: 6px; }
        .row.mt-2 { margin-top: 6px; }

        /* Master Entries styling */
        .master-entries .card { border-radius: 10px; overflow: hidden; transition: transform .15s ease, box-shadow .15s ease; box-shadow: 0 2px 6px rgba(0,0,0,0.06); }
        .master-entries .card:hover { transform: translateY(-6px); box-shadow: 0 10px 30px rgba(0,0,0,0.12); }
        .master-entries .card .card-body { padding: 18px 12px; }
        .master-entries .card .card-body i.bi { font-size: 48px; opacity: .98; }
        .master-entries .card .card-body h5 { font-size: 18px; margin-top: 8px; margin-bottom: 6px; font-weight: 700; }
        .master-entries .card .card-body p { font-size: 14px; margin-bottom: 8px; color: #6c757d; }
        .master-entries .card .btn { border-radius: 20px; padding: .35rem .8rem; font-size: 14px; }
        .master-entries .card .text-muted.small { font-size: 13px; }
        @media (max-width: 1200px) { .master-entries .card .card-body i.bi { font-size: 42px; } }
        @media (max-width: 768px) { .master-entries .card .card-body i.bi { font-size: 36px; } }
        @media (max-width: 576px) { .master-entries .card .card-body i.bi { font-size: 30px; } }

        /* Staff Operations styling - modern, compact cards */
        .staff-ops .card { border-radius: 12px; overflow: hidden; transition: transform .14s ease, box-shadow .14s ease; box-shadow: 0 2px 8px rgba(0,0,0,0.06); }
        .staff-ops .card:hover { transform: translateY(-6px); box-shadow: 0 12px 28px rgba(0,0,0,0.12); }
        .staff-ops .card .card-body { padding: 14px 10px; }
        .staff-ops .card .card-body i.bi { font-size: 30px; margin-bottom: 6px; color: inherit; }
        .staff-ops .card .card-body h6 { font-size: 14px; margin-bottom: 4px; font-weight: 600; }
        .staff-ops .card .card-body small { font-size: 12px; color: #6c757d; }
        .staff-ops .card.bg-light { background: linear-gradient(180deg,#ffffff,#fbfbfb); }
        .staff-ops .card .btn { font-size: 13px; border-radius: 18px; padding: .3rem .6rem; }
        .staff-ops .col-md-2 { padding-bottom: 10px; }
        @media (max-width: 768px) { .staff-ops .card .card-body i.bi { font-size: 26px; } }

        /* Today's Performance & Dues (larger) */
        .row.mt-2 .card .card-body { padding: 10px; }
        .row.mt-2 .card .card-body h6 { font-size: 14px; margin-bottom: 4px; }
        .row.mt-2 .card .card-body h2 { font-size: 20px; margin-bottom: 4px; }
        .row.mt-2 .card .card-body small { font-size: 12px; display:block; margin-top:0; }
        .row.mt-2 i.bi { font-size: 30px; }
        /* tighter card spacing */
        .row.mt-2 .col-md-6 { padding-bottom: 8px; }

        @media (max-width: 992px) {
            .row > .col-md-3 .card .card-body h2 { font-size: 20px; }
            .row > .col-md-3 i.bi { font-size: 24px; }
            .row.mt-2 .card .card-body h2 { font-size: 18px; }
            .row.mt-2 i.bi { font-size: 24px; }
        }
        @media (max-width: 576px) {
            .row.mt-2 .card .card-body h2 { font-size: 16px; }
            .row.mt-2 i.bi { font-size: 20px; }
            .row > .col-md-3 .card .card-body h2 { font-size: 16px; }
            .row > .col-md-3 i.bi { font-size: 20px; }
        }
        
        /* Due amount styling */
        .due-amount {
            font-weight: bold;
        }
        .due-positive {
            color: #dc3545;
        }
        .due-zero {
            color: #28a745;
        }
        
        /* Collect payment button styling */
        .collect-payment-btn {
            background-color: #ffc107;
            color: #000;
            border: none;
            padding: 5px 12px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 12px;
            transition: all 0.3s;
        }
        .collect-payment-btn:hover {
            background-color: #e0a800;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(255, 193, 7, 0.3);
        }
        .collect-payment-btn i {
            margin-right: 4px;
        }
        
        /* Quick payment modal */
        .quick-payment-modal .modal-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        .quick-payment-modal .modal-title i {
            margin-right: 8px;
        }
        .due-highlight {
            font-size: 1.2em;
            font-weight: bold;
            color: #dc3545;
            padding: 10px;
            background: #f8d7da;
            border-radius: 8px;
            text-align: center;
        }
        
        /* Supplier Price Compare Styling */
        .supplier-price-compare {
            margin-top: 30px;
            margin-bottom: 20px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 10px;
        }
        .supplier-price-compare .header {
            background: linear-gradient(135deg, #6f42c1, #007bff);
            color: white;
            padding: 15px;
            border-radius: 8px 8px 0 0;
            margin: -15px -15px 15px -15px;
        }
        .price-matrix {
            overflow-x: auto;
            background: white;
            border-radius: 5px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .price-matrix table {
            min-width: 50%;
            border-collapse: collapse;
        }
        .price-matrix th {
            background: #343a40;
            color: white;
            padding: 12px 8px;
            font-size: 0.9em;
            text-align: center;
            vertical-align: middle;
            position: sticky;
            top: 0;
            z-index: 10;
        }
        .price-matrix td {
            padding: 10px 8px;
            border: 1px solid #dee2e6;
            text-align: center;
            vertical-align: middle;
        }
        .price-matrix .part-info {
            background: #e9ecef;
            font-weight: 600;
            text-align: left;
            position: sticky;
            left: 0;
            z-index: 5;
        }
        .price-matrix .supplier-col {
            min-width: 120px;
        }
        .price-lowest {
            background-color: #d4edda !important;
            color: #155724;
            font-weight: bold;
        }
        .price-highest {
            background-color: #f8d7da !important;
            color: #721c24;
            font-weight: bold;
        }
        .price-average {
            background-color: #fff3cd !important;
            color: #856404;
        }
        .badge-supplier {
            background: #6c757d;
            color: white;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 0.8em;
        }
        .stats-mini-card {
            background: white;
            border-radius: 1px;
            padding: 5px;
            margin-bottom: 0px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }
        .variation-indicator {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            display: inline-block;
            margin-right: 5px;
        }
        .variation-high {
            background: #dc3545;
        }
        .variation-medium {
            background: #fd7e14;
        }
        .variation-low {
            background: #28a745;
        }
        .filter-section {
            background: white;
            padding: 1px;
            border-radius: 4px;
            margin-bottom: 2px;
        }

        /* Expenses Card Styling */
        .expense-card {
            border-color: #fd7e14 !important;
        }
        .expense-card .border-rounded {
            border-radius: 4px;
        }
        .expense-stats .row.g-1 {
            margin-bottom: 8px;
        }
        .expense-stats .border {
            border: 1px solid #dee2e6;
            border-radius: 4px;
            padding: 5px;
            background: white;
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container-fluid">
            <a class="navbar-brand" href="dashboard.php">
                <i class="bi bi-bicycle"></i> PRAVEEN SERVICE CENTER
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item">
                        <span class="nav-link text-white">
                            <i class="bi bi-person-circle"></i> <?php echo $_SESSION['username']; ?> (<?php echo $_SESSION['role']; ?>)
                        </span>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="logout.php">
                            <i class="bi bi-box-arrow-right"></i> Logout
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container-fluid mt-4">
        <!-- Welcome Banner -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="alert alert-info">
                    <h5 class="alert-heading">Welcome back, <?php echo $_SESSION['username']; ?>!</h5>
                    <p class="mb-0">Today is <?php echo date('l, d F Y'); ?></p>
                </div>
            </div>
        </div>

        <!-- Statistics Cards -->
        <div class="row statistics-cards">
            <div class="col-md-3 mb-3">
                <div class="card text-white bg-primary">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="card-title">Total Customers</h6>
                                <h2 class="mb-0"><?php echo $stats['customers']; ?></h2>
                                <small>Registered customers</small>
                            </div>
                            <i class="bi bi-people fs-1"></i>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-3 mb-3">
                <div class="card text-white bg-success">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="card-title">Total Bikes</h6>
                                <h2 class="mb-0"><?php echo $stats['bikes']; ?></h2>
                                <small>Parts in stock</small>
                            </div>
                            <i class="bi bi-bicycle fs-1"></i>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-3 mb-3">
                <div class="card text-white bg-warning">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="card-title">Pending Jobs</h6>
                                <h2 class="mb-0"><?php echo $stats['pending_jobs']; ?></h2>
                                <small>Service jobs pending</small>
                            </div>
                            <i class="bi bi-tools fs-1"></i>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-3 mb-3">
                <div class="card text-white bg-danger">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="card-title">Low Stock Items</h6>
                                <h2 class="mb-0"><?php echo $stats['low_stock']; ?></h2>
                                <small>Need reorder</small>
                            </div>
                            <i class="bi bi-exclamation-triangle fs-1"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Today's Performance & Dues -->
        <div class="row mt-2">
            <div class="col-md-6 mb-3">
                <div class="card bg-info text-white">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="card-title">Today's Sales</h6>
                                <h2 class="mb-0">₹<?php echo number_format($today_data['today_sales'] ?? 0, 2); ?></h2>
                                <small><?php echo $today_data['today_transactions'] ?? 0; ?> transactions today</small>
                            </div>
                            <i class="bi bi-graph-up-arrow fs-1"></i>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-6 mb-3">
                <div class="card bg-warning text-white">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="card-title">Outstanding Dues</h6>
                                <h2 class="mb-0">₹<?php echo number_format($dues['total_due_amount'] ?? 0, 2); ?></h2>
                                <small><?php echo $dues['total_due_invoices'] ?? 0; ?> pending invoices</small>
                            </div>
                            <i class="bi bi-cash-stack fs-1"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Master Entries Section - Only visible to Admin -->
        <?php if (isAdmin()): ?>
        <div class="master-entries">
            <div class="row mt-4">
                <div class="col-12">
                    <h4 class="mb-3 border-bottom pb-2">
                        <i class="bi bi-database"></i> Master Entries (Admin Only)
                    </h4>
                </div>
                
                <!-- Bike Companies -->
                <div class="col-md-3 mb-3">
                    <div class="card h-100 border-primary">
                        <div class="card-body text-center">
                            <i class="bi bi-building fs-1 text-primary"></i>
                            <h5 class="card-title mt-2">Bike Companies</h5>
                            <p class="card-text text-muted small">Add/Edit bike & scooty companies</p>
                            <a href="companies.php" class="btn btn-outline-primary btn-sm w-100">Manage</a>
                            <small class="text-muted d-block mt-2">Purchase entry & stock update</small>
                        </div>
                    </div>
                </div>
                
                <!-- Bike Models -->
                <div class="col-md-3 mb-3">
                    <div class="card h-100 border-success">
                        <div class="card-body text-center">
                            <i class="bi bi-gear fs-1 text-success"></i>
                            <h5 class="card-title mt-2">Bike Models</h5>
                            <p class="card-text text-muted small">Add models for each company</p>
                            <a href="models.php" class="btn btn-outline-success btn-sm w-100">Manage</a>
                            <small class="text-muted d-block mt-2">View current stock & movements</small>
                        </div>
                    </div>
                </div>
                
                <!-- Categories -->
                <div class="col-md-3 mb-3">
                    <div class="card h-100 border-warning">
                        <div class="card-body text-center">
                            <i class="bi bi-tags fs-1 text-warning"></i>
                            <h5 class="card-title mt-2">Categories</h5>
                            <p class="card-text text-muted small">Manage part categories</p>
                            <a href="categories.php" class="btn btn-outline-warning btn-sm w-100">Manage</a>
                            <small class="text-muted d-block mt-2">Profit/Loss, Sales reports</small>
                        </div>
                    </div>
                </div>
                
                <!-- Parts Master -->
                <div class="col-md-3 mb-3">
                    <div class="card h-100 border-info">
                        <div class="card-body text-center">
                            <i class="bi bi-box-seam fs-1 text-info"></i>
                            <h5 class="card-title mt-2">Parts Master</h5>
                            <p class="card-text text-muted small">Add parts with part numbers</p>
                            <a href="parts.php" class="btn btn-outline-info btn-sm w-100">Manage</a>
                            <small class="text-muted d-block mt-2">View all parts</small>
                        </div>
                    </div>
                </div>
                
                <!-- Suppliers -->
                <div class="col-md-3 mb-3">
                    <div class="card h-100 border-secondary">
                        <div class="card-body text-center">
                            <i class="bi bi-truck fs-1 text-secondary"></i>
                            <h5 class="card-title mt-2">Suppliers</h5>
                            <p class="card-text text-muted small">Manage vendors/suppliers</p>
                            <a href="suppliers.php" class="btn btn-outline-secondary btn-sm w-100">Manage</a>
                            <small class="text-muted d-block mt-2">Vendor management</small>
                        </div>
                    </div>
                </div>
                
                <!-- Customers (Admin can manage) -->
                <div class="col-md-3 mb-3">
                    <div class="card h-100 border-primary">
                        <div class="card-body text-center">
                            <i class="bi bi-people fs-1 text-primary"></i>
                            <h5 class="card-title mt-2">Customers</h5>
                            <p class="card-text text-muted small">Manage customers with vehicle details</p>
                            <a href="customers.php" class="btn btn-outline-primary btn-sm w-100">Manage</a>
                            <small class="text-muted d-block mt-2">Add/Edit/Delete customers</small>
                        </div>
                    </div>
                </div>
                
                <!-- System Settings -->
                <div class="col-md-3 mb-3">
                    <div class="card h-100 border-dark">
                        <div class="card-body text-center">
                            <i class="bi bi-gear fs-1 text-dark"></i>
                            <h5 class="card-title mt-2">System Settings</h5>
                            <p class="card-text text-muted small">Configure invoice & business info</p>
                            <a href="settings.php" class="btn btn-outline-dark btn-sm w-100">Configure</a>
                            <small class="text-muted d-block mt-2">Invoice format, GST, etc.</small>
                        </div>
                    </div>
                </div>
                
                <!-- Reports & Analytics -->
                <div class="col-md-3 mb-3">
                    <div class="card h-100 border-danger">
                        <div class="card-body text-center">
                            <i class="bi bi-graph-up fs-1 text-danger"></i>
                            <h5 class="card-title mt-2">Profit & Loss</h5>
                            <p class="card-text text-muted small">View revenue, costs and profits</p>
                            <a href="profit_loss.php" class="btn btn-outline-danger btn-sm w-100">View P&L</a>
                            <small class="text-muted d-block mt-2">Financial analysis</small>
                        </div>
                    </div>
                </div>
                
                <!-- Simple Accounting -->
                <div class="col-md-3 mb-3">
                    <div class="card h-100 border-success">
                        <div class="card-body text-center">
                            <i class="bi bi-calculator fs-1 text-success"></i>
                            <h5 class="card-title mt-2">Simple Accounting</h5>
                            <p class="card-text text-muted small">Track income, expenses & balance</p>
                            <a href="simple_accounting.php" class="btn btn-outline-success btn-sm w-100">View Accounting</a>
                            <small class="text-muted d-block mt-2">Today's income & total balance</small>
                        </div>
                    </div>
                </div>

                <!-- Supplier Price Compare Card -->
                <div class="col-md-3 mb-3">
                    <div class="card h-100" style="border-color: #6f42c1;">
                        <div class="card-body text-center">
                            <i class="bi bi-currency-exchange fs-1" style="color: #6f42c1;"></i>
                            <h5 class="card-title mt-2">Supplier Price Compare</h5>
                            <p class="card-text text-muted small">Compare purchase prices across all suppliers</p>
                            
                            <!-- Quick Stats -->
                            <div class="row g-1 mb-2">
                                <div class="col-6">
                                    <div class="border rounded p-1">
                                        <small class="text-muted d-block">Parts</small>
                                        <strong><?php echo !empty($all_parts) ? count($all_parts) : 0; ?></strong>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="border rounded p-1">
                                        <small class="text-muted d-block">Suppliers</small>
                                        <strong><?php echo !empty($all_suppliers) ? count($all_suppliers) : 0; ?></strong>
                                    </div>
                                </div>
                            </div>
                            
                            <?php if (!empty($all_parts) && !empty($all_suppliers)): ?>
                                <!-- Variation Stats -->
                                <div class="border rounded p-1 mb-2">
                                    <small class="text-muted d-block">Avg Price Variation</small>
                                    <strong class="<?php 
                                        $avg_variation = 0;
                                        if (!empty($part_stats)) {
                                            $total_variation = array_sum(array_column($part_stats, 'variation_percent'));
                                            $avg_variation = round($total_variation / count($part_stats), 2);
                                        }
                                        echo $avg_variation > 30 ? 'text-danger' : ($avg_variation > 15 ? 'text-warning' : 'text-success');
                                    ?>">
                                        <?php echo $avg_variation; ?>%
                                    </strong>
                                </div>
                            <?php endif; ?>
                            
                            <a href="supplier_price_compare.php" class="btn btn-sm w-100" style="background-color: #6f42c1; color: white;">
                                <i class="bi bi-eye"></i> View Price Comparison
                            </a>
                            
                            <?php if (empty($all_parts) || empty($all_suppliers)): ?>
                            <small class="text-muted d-block mt-2 text-warning">
                                <i class="bi bi-exclamation-triangle"></i> No data available
                            </small>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- EXPENSES CARD - NEW -->
                <div class="col-md-3 mb-2">
                    <div class="card h-100 expense-card" style="border-color: #fd7e14;">
                        <div class="card-body text-center">
                            <i class="bi bi-cash-stack fs-1" style="color: #fd7e14;"></i>
                            <h5 class="card-title mt-2">Expenses</h5>
                            <p class="card-text text-muted small">Track all business expenses</p>
                            
                            <!-- Expense Stats -->
                            <div class="expense-stats">
                                <div class="row g-1 mb-2">
                                    <div class="col-6">
                                        <div class="border rounded p-1">
                                            <small class="text-muted d-block">Today's Ops</small>
                                            <strong class="text-primary">₹<?php echo number_format($today_ops, 0); ?></strong>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <div class="border rounded p-1">
                                            <small class="text-muted d-block">This Month</small>
                                            <strong class="text-warning">₹<?php echo number_format($month_ops, 0); ?></strong>
                                        </div>
                                    </div>
                                </div>
                                
                                
                                <!-- Quick Action Buttons -->
                                <div class="d-grid gap-1">
                                    <a href="operational_expenses.php" class="btn btn-sm" style="background-color: #fd7e14; color: white;">
                                        <i class="bi bi-plus-circle"></i> Add Operational Expense
                                    </a>
                                    <a href="expenses_report.php" class="btn btn-sm btn-outline-secondary">
                                        <i class="bi bi-graph-up"></i> View Full Report
                                    </a>
                                </div>
                                
                                <?php if ($recent_count > 0): ?>
                                <small class="text-muted d-block mt-2">
                                    <i class="bi bi-clock"></i> <?php echo $recent_count; ?> expenses in last 7 days
                                </small>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Staff Operations - Visible to both Admin and Staff -->
        <div class="row mt-4 staff-ops">
            <div class="col-12">
                <h4 class="mb-3 border-bottom pb-2">
                    <i class="bi bi-tools"></i> Staff Operations
                </h4>
            </div>
            
            <div class="col-md-2 mb-3">
                <a href="purchases.php" class="text-decoration-none">
                    <div class="card bg-light h-100">
                        <div class="card-body text-center">
                            <i class="bi bi-cart-plus fs-1 text-primary"></i>
                            <h6 class="mt-2">New Purchase</h6>
                            <small class="text-muted">Add stock</small>
                        </div>
                    </div>
                </a>
            </div>
            
            <div class="col-md-2 mb-3">
                <a href="sales.php" class="text-decoration-none">
                    <div class="card bg-light h-100">
                        <div class="card-body text-center">
                            <i class="bi bi-cash-stack fs-1 text-success"></i>
                            <h6 class="mt-2">New Sale</h6>
                            <small class="text-muted">Create invoice</small>
                        </div>
                    </div>
                </a>
            </div>
            
            <div class="col-md-2 mb-3">
                <a href="jobs.php" class="text-decoration-none">
                    <div class="card bg-light h-100">
                        <div class="card-body text-center">
                            <i class="bi bi-wrench fs-1 text-warning"></i>
                            <h6 class="mt-2">Pending Jobs</h6>
                            <small class="text-muted">Service jobs</small>
                        </div>
                    </div>
                </a>
            </div>
            
            <div class="col-md-2 mb-3">
                <a href="stock.php" class="text-decoration-none">
                    <div class="card bg-light h-100">
                        <div class="card-body text-center">
                            <i class="bi bi-boxes fs-1 text-info"></i>
                            <h6 class="mt-2">Stock Report</h6>
                            <small class="text-muted">Current stock</small>
                        </div>
                    </div>
                </a>
            </div>

            <!-- Customers View - Staff can only view -->
            <?php if (isStaff()): ?>
            <div class="col-md-2 mb-3">
                <a href="customers.php" class="text-decoration-none">
                    <div class="card bg-light h-100">
                        <div class="card-body text-center">
                            <i class="bi bi-people fs-1 text-secondary"></i>
                            <h6 class="mt-2">View Customers</h6>
                            <small class="text-muted">Customer list</small>
                        </div>
                    </div>
                </a>
            </div>
            <?php endif; ?>
            
            <div class="col-md-2 mb-3">
                <a href="reports.php" class="text-decoration-none">
                    <div class="card bg-light h-100">
                        <div class="card-body text-center">
                            <i class="bi bi-file-text fs-1 text-danger"></i>
                            <h6 class="mt-2">Reports</h6>
                            <small class="text-muted">Analytics</small>
                        </div>
                    </div>
                </a>
            </div>
        </div>

        <!-- Recent Outstanding Dues Section -->
        <div class="row mt-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header bg-warning text-white d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">
                            <i class="bi bi-exclamation-triangle"></i> 
                            Recent Outstanding Dues 
                            <span class="badge bg-light text-dark ms-2"><?php echo mysqli_num_rows($recent_dues); ?> Bills</span>
                        </h5>
                        <a href="sales.php" class="btn btn-sm btn-light">View All Sales</a>
                    </div>
                    <div class="card-body">
                        <?php
                        if(mysqli_num_rows($recent_dues) > 0):
                        ?>
                        <div class="table-responsive">
                            <table class="table table-sm table-striped">
                                <thead>
                                    <tr>
                                        <th>Invoice #</th>
                                        <th>Date</th>
                                        <th>Customer</th>
                                        <th>Vehicle</th>
                                        <th>Sub Total</th>
                                        <th>Discount</th>
                                        <th>Grand Total</th>
                                        <th>Paid</th>
                                        <th>Due Amount</th>
                                        <th>Status</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    mysqli_data_seek($recent_dues, 0);
                                    while($due = mysqli_fetch_assoc($recent_dues)): 
                                        $subtotal_query = mysqli_query($conn, "SELECT SUM(quantity * selling_price) as subtotal 
                                                                              FROM sale_items WHERE sale_id = " . $due['id']);
                                        $subtotal_data = mysqli_fetch_assoc($subtotal_query);
                                        $subtotal = $subtotal_data['subtotal'] ?? $due['actual_total'];
                                        
                                        $discount_amount = $due['discount_amount'] ?? 0;
                                        $discount_type = $due['discount_type'] ?? 'fixed';
                                        $discount_value = $due['discount_value'] ?? 0;
                                        
                                        $total_bill = $due['actual_total'];
                                        $due_amount = $due['actual_due_amount'];
                                        $paid_percentage = $total_bill > 0 ? ($due['paid_amount'] / $total_bill) * 100 : 0;
                                    ?>
                                    <tr>
                                        <td><strong><?php echo $due['invoice_number']; ?></strong></td>
                                        <td><?php echo date('d-m-Y', strtotime($due['sale_date'])); ?></td>
                                        <td>
                                            <?php echo htmlspecialchars($due['customer_name'] ?? 'Walk-in'); ?>
                                            <?php if($due['phone']): ?>
                                                <br><small class="text-muted"><?php echo $due['phone']; ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if($due['vehicle_registration']): ?>
                                                <span class="badge bg-info"><?php echo $due['vehicle_registration']; ?></span>
                                            <?php else: ?>
                                                -
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-secondary">
                                            <strong>₹<?php echo number_format($subtotal, 2); ?></strong>
                                        </td>
                                        <td class="text-info">
                                            <?php if($discount_amount > 0): ?>
                                                <strong>-₹<?php echo number_format($discount_amount, 2); ?></strong>
                                                <?php if($discount_type == 'percentage'): ?>
                                                    <br><small>(<?php echo $discount_value; ?>%)</small>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                -
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-primary">
                                            <strong>₹<?php echo number_format($total_bill, 2); ?></strong>
                                        </td>
                                        <td class="text-success">
                                            ₹<?php echo number_format($due['paid_amount'], 2); ?>
                                            <br><small class="text-muted"><?php echo round($paid_percentage, 1); ?>%</small>
                                        </td>
                                        <td class="text-danger due-amount">
                                            <strong>₹<?php echo number_format($due_amount, 2); ?></strong>
                                        </td>
                                        <td>
                                            <?php 
                                            $status_class = 'warning';
                                            $status_text = 'Partial';
                                            if($due_amount == $total_bill) {
                                                $status_class = 'danger';
                                                $status_text = 'Pending';
                                            } elseif($due_amount == 0) {
                                                $status_class = 'success';
                                                $status_text = 'Paid';
                                            }
                                            ?>
                                            <span class="badge bg-<?php echo $status_class; ?>">
                                                <?php echo $status_text; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="btn-group btn-group-sm" role="group">
                                                <a href="sale_view.php?id=<?php echo $due['id']; ?>" 
                                                   class="btn btn-info" 
                                                   title="View Details">
                                                    <i class="bi bi-eye"></i>
                                                </a>
                                                <button type="button" 
                                                        class="btn btn-warning" 
                                                        onclick="openQuickPayment(<?php echo $due['id']; ?>, '<?php echo $due['invoice_number']; ?>', <?php echo $due_amount; ?>)"
                                                        title="Collect Payment">
                                                    <i class="bi bi-cash"></i>
                                                </button>
                                                <a href="invoice.php?id=<?php echo $due['id']; ?>" 
                                                   class="btn btn-secondary" 
                                                   target="_blank" 
                                                   title="Print Invoice">
                                                    <i class="bi bi-printer"></i>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endwhile; ?>
                                </tbody>
                                <?php
                                // Calculate totals for footer
                                $total_subtotal = 0;
                                $total_discount = 0;
                                $total_grand = 0;
                                $total_paid_foot = 0;
                                $total_due_foot = 0;
                                
                                mysqli_data_seek($recent_dues, 0);
                                while($due = mysqli_fetch_assoc($recent_dues)) {
                                    $subtotal_query = mysqli_query($conn, "SELECT SUM(quantity * selling_price) as subtotal 
                                                                          FROM sale_items WHERE sale_id = " . $due['id']);
                                    $subtotal_data = mysqli_fetch_assoc($subtotal_query);
                                    $subtotal = $subtotal_data['subtotal'] ?? $due['actual_total'];
                                    
                                    $total_subtotal += $subtotal;
                                    $total_discount += ($due['discount_amount'] ?? 0);
                                    $total_grand += $due['actual_total'];
                                    $total_paid_foot += $due['paid_amount'];
                                    $total_due_foot += $due['actual_due_amount'];
                                }
                                ?>
                                <tfoot class="table-secondary">
                                    <tr>
                                        <th colspan="4" class="text-end">Totals:</th>
                                        <th class="text-secondary">₹<?php echo number_format($total_subtotal, 2); ?></th>
                                        <th class="text-info">-₹<?php echo number_format($total_discount, 2); ?></th>
                                        <th class="text-primary">₹<?php echo number_format($total_grand, 2); ?></th>
                                        <th class="text-success">₹<?php echo number_format($total_paid_foot, 2); ?></th>
                                        <th class="text-danger">₹<?php echo number_format($total_due_foot, 2); ?></th>
                                        <th colspan="2"></th>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                        
                        <?php
                        $collection_percentage = $total_grand > 0 ? ($total_paid_foot / $total_grand) * 100 : 0;
                        ?>
                        <div class="mt-3">
                            <div class="d-flex justify-content-between align-items-center">
                                <span>
                                    <strong>Summary:</strong> 
                                    Subtotal: ₹<?php echo number_format($total_subtotal, 2); ?> | 
                                    Discount: ₹<?php echo number_format($total_discount, 2); ?> | 
                                    Grand Total: ₹<?php echo number_format($total_grand, 2); ?> | 
                                    <span class="text-success">Paid: ₹<?php echo number_format($total_paid_foot, 2); ?></span> | 
                                    <span class="text-danger">Due: ₹<?php echo number_format($total_due_foot, 2); ?></span>
                                </span>
                                <span><strong>Collection Rate:</strong> <?php echo round($collection_percentage, 1); ?>%</span>
                            </div>
                            <div class="progress mt-1" style="height: 8px;">
                                <div class="progress-bar bg-success" style="width: <?php echo $collection_percentage; ?>%"></div>
                                <div class="progress-bar bg-warning" style="width: <?php echo 100 - $collection_percentage; ?>%"></div>
                            </div>
                        </div>
                        
                        <?php else: ?>
                        <div class="text-center py-4">
                            <i class="bi bi-check-circle-fill text-success fs-1"></i>
                            <p class="text-muted mt-2 mb-0">No outstanding dues! All invoices are paid. 🎉</p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Quick Actions Footer -->
        <div class="row mt-4 mb-4">
            <div class="col-12">
                <div class="card bg-light">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <span><i class="bi bi-info-circle"></i> Current Month: <?php echo date('F Y'); ?></span>
                            <span><i class="bi bi-calendar"></i> Invoice Prefix: 
                                <?php 
                                $prefix = mysqli_fetch_assoc(mysqli_query($conn, "SELECT setting_value FROM system_settings WHERE setting_key = 'invoice_prefix'"));
                                echo $prefix ? $prefix['setting_value'] : 'INV';
                                ?>
                            </span>
                            <span><i class="bi bi-clock-history"></i> Last Login: <?php echo date('d-m-Y H:i'); ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Quick Payment Modal -->
    <div class="modal fade quick-payment-modal" id="quickPaymentModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-cash-stack"></i> Quick Payment Collection
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="sale_view.php" id="quickPaymentForm">
                    <div class="modal-body">
                        <input type="hidden" name="sale_id" id="quick_sale_id">
                        <input type="hidden" name="add_payment" value="1">
                        
                        <div class="alert alert-info" id="invoice_info"></div>
                        
                        <div class="due-highlight mb-3" id="due_display"></div>
                        
                        <div class="mb-3">
                            <label for="quick_payment_amount" class="form-label">Payment Amount *</label>
                            <input type="number" step="0.01" class="form-control" id="quick_payment_amount" name="payment_amount" required>
                            <small class="text-muted">Enter amount to collect</small>
                        </div>
                        
                        <div class="mb-3">
                            <label for="quick_payment_method" class="form-label">Payment Method *</label>
                            <select class="form-control" id="quick_payment_method" name="payment_method" required>
                                <option value="cash">Cash</option>
                                <option value="card">Card</option>
                                <option value="online">Online</option>
                                <option value="bank_transfer">Bank Transfer</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label for="quick_reference_number" class="form-label">Reference Number (Optional)</label>
                            <input type="text" class="form-control" id="quick_reference_number" name="reference_number">
                        </div>
                        
                        <div class="mb-3">
                            <label for="quick_notes" class="form-label">Notes (Optional)</label>
                            <textarea class="form-control" id="quick_notes" name="notes" rows="2"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary" id="submitQuickPayment">
                            <i class="bi bi-check-circle"></i> Confirm Payment
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
    // Initialize tooltips
    document.addEventListener('DOMContentLoaded', function() {
        var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        var tooltipList = tooltipTriggerList.map(function(tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl);
        });
    });
    
    // Function to open quick payment modal
    function openQuickPayment(saleId, invoiceNumber, dueAmount) {
        document.getElementById('quick_sale_id').value = saleId;
        document.getElementById('invoice_info').innerHTML = '<strong>Invoice #:</strong> ' + invoiceNumber;
        document.getElementById('due_display').innerHTML = 'Due Amount: ₹' + dueAmount.toFixed(2);
        document.getElementById('quick_payment_amount').max = dueAmount;
        document.getElementById('quick_payment_amount').value = dueAmount;
        
        var modal = new bootstrap.Modal(document.getElementById('quickPaymentModal'));
        modal.show();
    }
    
    // Validate payment amount
    document.getElementById('quick_payment_amount').addEventListener('input', function() {
        const maxAmount = parseFloat(this.max);
        const currentAmount = parseFloat(this.value) || 0;
        
        if (currentAmount > maxAmount) {
            this.value = maxAmount;
            alert('Payment amount cannot exceed due amount');
        }
    });
    
    // Form submission validation
    document.getElementById('quickPaymentForm').addEventListener('submit', function(e) {
        const paymentAmount = parseFloat(document.getElementById('quick_payment_amount').value) || 0;
        if (paymentAmount <= 0) {
            e.preventDefault();
            alert('Please enter a valid payment amount');
        }
    });
    </script>
</body>
</html>