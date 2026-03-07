<?php
require_once 'config.php';

if (!isLoggedIn()) {
    redirect('index.php');
}

// Get filter parameters
$report_type = $_GET['report_type'] ?? 'sales';
$period = $_GET['period'] ?? 'month';
$start_date = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
$end_date = $_GET['end_date'] ?? date('Y-m-d');

// Set date range based on period
switch($period) {
    case 'week':
        $start_date = date('Y-m-d', strtotime('monday this week'));
        $end_date = date('Y-m-d', strtotime('sunday this week'));
        break;
    case 'month':
        $start_date = date('Y-m-01');
        $end_date = date('Y-m-t');
        break;
    case 'quarter':
        $quarter = ceil(date('n') / 3);
        $start_date = date('Y-') . (($quarter - 1) * 3 + 1) . '-01';
        $end_date = date('Y-m-t', strtotime($start_date . ' +2 months'));
        break;
    case 'year':
        $start_date = date('Y-01-01');
        $end_date = date('Y-12-31');
        break;
    case 'custom':
        // Keep as is
        break;
    default:
        // month is default
        $start_date = date('Y-m-01');
        $end_date = date('Y-m-t');
}

// Sales Reports
if ($report_type == 'sales') {
    // Daily Sales Summary
    $daily_sales = mysqli_query($conn, "SELECT 
                                        DATE(sale_date) as date,
                                        COUNT(*) as invoice_count,
                                        SUM(total_amount) as total_sales,
                                        AVG(total_amount) as avg_sale,
                                        SUM(CASE WHEN payment_method = 'cash' THEN total_amount ELSE 0 END) as cash_sales,
                                        SUM(CASE WHEN payment_method = 'card' THEN total_amount ELSE 0 END) as card_sales,
                                        SUM(CASE WHEN payment_method = 'online' THEN total_amount ELSE 0 END) as online_sales
                                    FROM sales
                                    WHERE sale_date BETWEEN '$start_date' AND '$end_date'
                                    GROUP BY DATE(sale_date)
                                    ORDER BY sale_date DESC");

    // Top Customers
    $top_customers = mysqli_query($conn, "SELECT 
                                        c.customer_name,
                                        c.phone,
                                        COUNT(s.id) as purchase_count,
                                        SUM(s.total_amount) as total_spent,
                                        AVG(s.total_amount) as avg_spent
                                    FROM sales s
                                    LEFT JOIN customers c ON s.customer_id = c.id
                                    WHERE s.sale_date BETWEEN '$start_date' AND '$end_date'
                                    GROUP BY s.customer_id
                                    HAVING total_spent > 0
                                    ORDER BY total_spent DESC
                                    LIMIT 20");
    
    // Hourly Sales Pattern
    $hourly_sales = mysqli_query($conn, "SELECT 
                                        HOUR(created_at) as hour,
                                        COUNT(*) as transaction_count,
                                        SUM(total_amount) as total_amount
                                    FROM sales
                                    WHERE sale_date BETWEEN '$start_date' AND '$end_date'
                                    GROUP BY HOUR(created_at)
                                    ORDER BY hour");
}

// Purchase Reports
else if ($report_type == 'purchases') {
    // Daily Purchase Summary
    $daily_purchases = mysqli_query($conn, "SELECT 
                                            DATE(purchase_date) as date,
                                            COUNT(*) as purchase_count,
                                            SUM(total_amount) as total_purchases,
                                            AVG(total_amount) as avg_purchase
                                        FROM purchases
                                        WHERE purchase_date BETWEEN '$start_date' AND '$end_date'
                                        GROUP BY DATE(purchase_date)
                                        ORDER BY purchase_date DESC");
    
    // Top Suppliers
    $top_suppliers = mysqli_query($conn, "SELECT 
                                        s.supplier_name,
                                        s.contact_person,
                                        s.phone,
                                        COUNT(p.id) as purchase_count,
                                        SUM(p.total_amount) as total_purchased
                                    FROM purchases p
                                    JOIN suppliers s ON p.supplier_id = s.id
                                    WHERE p.purchase_date BETWEEN '$start_date' AND '$end_date'
                                    GROUP BY p.supplier_id
                                    ORDER BY total_purchased DESC
                                    LIMIT 20");

    // Monthly Purchase Summary
    $monthly_purchases = mysqli_query($conn, "SELECT 
                                            DATE_FORMAT(purchase_date, '%Y-%m') as month,
                                            COUNT(*) as purchase_count,
                                            SUM(total_amount) as total_purchases
                                        FROM purchases
                                        WHERE purchase_date BETWEEN '$start_date' AND '$end_date'
                                        GROUP BY DATE_FORMAT(purchase_date, '%Y-%m')
                                        ORDER BY month DESC");
}

// Stock Reports
else if ($report_type == 'stock') {
    // Stock Valuation
    $stock_value = mysqli_query($conn, "SELECT 
                                        SUM(s.quantity * p.unit_price) as total_stock_value,
                                        COUNT(DISTINCT p.id) as total_products,
                                        SUM(s.quantity) as total_items
                                    FROM stock s
                                    JOIN parts_master p ON s.part_id = p.id");
    
    // Low Stock Alert
    $low_stock = mysqli_query($conn, "SELECT 
                                    p.part_number,
                                    p.part_name,
                                    s.quantity,
                                    s.min_stock_level,
                                    (s.min_stock_level - s.quantity) as required_qty,
                                    c.category_name
                                FROM stock s
                                JOIN parts_master p ON s.part_id = p.id
                                LEFT JOIN categories c ON p.category_id = c.id
                                WHERE s.quantity <= s.min_stock_level
                                ORDER BY (s.quantity / s.min_stock_level) ASC");
    
    // Category-wise Stock
    $category_stock = mysqli_query($conn, "SELECT 
                                        c.category_name,
                                        COUNT(p.id) as total_parts,
                                        SUM(s.quantity) as total_quantity,
                                        SUM(s.quantity * p.unit_price) as total_value
                                    FROM categories c
                                    LEFT JOIN parts_master p ON c.id = p.category_id
                                    LEFT JOIN stock s ON p.id = s.part_id
                                    GROUP BY c.id
                                    HAVING total_parts > 0
                                    ORDER BY total_value DESC");
}

// Performance Reports
else if ($report_type == 'performance') {
    // Staff Performance
    $staff_performance = mysqli_query($conn, "SELECT 
                                            u.username,
                                            u.role,
                                            COUNT(DISTINCT s.id) as total_sales,
                                            SUM(s.total_amount) as sales_amount,
                                            COUNT(DISTINCT p.id) as total_purchases,
                                            SUM(p.total_amount) as purchase_amount,
                                            COUNT(DISTINCT j.id) as jobs_handled
                                        FROM users u
                                        LEFT JOIN sales s ON u.id = s.created_by AND s.sale_date BETWEEN '$start_date' AND '$end_date'
                                        LEFT JOIN purchases p ON u.id = p.created_by AND p.purchase_date BETWEEN '$start_date' AND '$end_date'
                                        LEFT JOIN pending_jobs j ON u.id = j.created_by AND j.created_at BETWEEN '$start_date' AND '$end_date'
                                        WHERE u.role = 'staff'
                                        GROUP BY u.id
                                        ORDER BY sales_amount DESC");
    
    // Monthly Trends
    $monthly_trends = mysqli_query($conn, "SELECT 
                                        DATE_FORMAT(sale_date, '%Y-%m') as month,
                                        COUNT(*) as sales_count,
                                        SUM(total_amount) as sales_amount,
                                        (SELECT SUM(total_amount) FROM purchases WHERE DATE_FORMAT(purchase_date, '%Y-%m') = month) as purchase_amount
                                    FROM sales
                                    WHERE sale_date >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
                                    GROUP BY DATE_FORMAT(sale_date, '%Y-%m')
                                    ORDER BY month DESC");
}

// Bike Models Report
else if ($report_type == 'models') {
    // Most Serviced Models
    $model_service = mysqli_query($conn, "SELECT 
                                        bm.model_name,
                                        bc.name as company_name,
                                        COUNT(j.id) as service_count,
                                        SUM(j.estimated_cost) as total_estimated
                                    FROM bike_models bm
                                    JOIN bike_companies bc ON bm.company_id = bc.id
                                    LEFT JOIN pending_jobs j ON bm.id = j.bike_model_id
                                    WHERE j.created_at BETWEEN '$start_date' AND '$end_date'
                                    GROUP BY bm.id
                                    ORDER BY service_count DESC
                                    LIMIT 20");
    
    // Parts by Model
    $parts_by_model = mysqli_query($conn, "SELECT 
                                        bm.model_name,
                                        bc.name as company_name,
                                        COUNT(p.id) as parts_count,
                                        SUM(s.quantity) as stock_quantity,
                                        SUM(s.quantity * p.unit_price) as stock_value
                                    FROM bike_models bm
                                    JOIN bike_companies bc ON bm.company_id = bc.id
                                    LEFT JOIN parts_master p ON bm.id = p.model_id
                                    LEFT JOIN stock s ON p.id = s.part_id
                                    GROUP BY bm.id
                                    HAVING parts_count > 0
                                    ORDER BY stock_value DESC");
}

// Profit Analysis Report
else if ($report_type == 'profit_analysis') {
    // Product-wise Profit
    $product_profit = mysqli_query($conn, "SELECT 
                                        p.part_name,
                                        p.part_number,
                                        c.category_name,
                                        SUM(si.quantity) as qty_sold,
                                        AVG(si.selling_price) as avg_selling,
                                        AVG(pi.purchase_price) as avg_purchase,
                                        (AVG(si.selling_price) - AVG(pi.purchase_price)) as profit_per_unit,
                                        SUM(si.quantity * (si.selling_price - pi.purchase_price)) as total_profit
                                    FROM parts_master p
                                    LEFT JOIN categories c ON p.category_id = c.id
                                    LEFT JOIN sale_items si ON p.id = si.part_id
                                    LEFT JOIN sales s ON si.sale_id = s.id AND s.sale_date BETWEEN '$start_date' AND '$end_date'
                                    LEFT JOIN purchase_items pi ON p.id = pi.part_id
                                    LEFT JOIN purchases pu ON pi.purchase_id = pu.id
                                    GROUP BY p.id
                                    HAVING qty_sold > 0
                                    ORDER BY total_profit DESC
                                    LIMIT 50");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports - Bike Management System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
                        <a class="nav-link" href="dashboard.php">
                            <i class="bi bi-speedometer2"></i> Dashboard
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="profit_loss.php">
                            <i class="bi bi-graph-up"></i> P&L
                        </a>
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
        <!-- Report Navigation -->
        <div class="card mb-4">
            <div class="card-header">
                <ul class="nav nav-tabs card-header-tabs">
                    <li class="nav-item">
                        <a class="nav-link <?php echo $report_type == 'sales' ? 'active' : ''; ?>" href="?report_type=sales&period=<?php echo $period; ?>&start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>">
                            <i class="bi bi-cash"></i> Sales Reports
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $report_type == 'purchases' ? 'active' : ''; ?>" href="?report_type=purchases&period=<?php echo $period; ?>&start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>">
                            <i class="bi bi-cart"></i> Purchase Reports
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $report_type == 'stock' ? 'active' : ''; ?>" href="?report_type=stock">
                            <i class="bi bi-boxes"></i> Stock Reports
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $report_type == 'performance' ? 'active' : ''; ?>" href="?report_type=performance&period=<?php echo $period; ?>&start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>">
                            <i class="bi bi-person-workspace"></i> Performance
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $report_type == 'models' ? 'active' : ''; ?>" href="?report_type=models&period=<?php echo $period; ?>&start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>">
                            <i class="bi bi-bicycle"></i> Bike Models
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $report_type == 'profit_analysis' ? 'active' : ''; ?>" href="?report_type=profit_analysis&period=<?php echo $period; ?>&start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>">
                            <i class="bi bi-pie-chart"></i> Profit Analysis
                        </a>
                    </li>
                </ul>
            </div>
            <div class="card-body">
                <!-- Date Filter -->
                <form method="GET" class="row g-3 mb-4">
                    <input type="hidden" name="report_type" value="<?php echo $report_type; ?>">
                    <div class="col-md-2">
                        <select name="period" class="form-control" onchange="this.form.submit()">
                            <option value="week" <?php echo $period == 'week' ? 'selected' : ''; ?>>This Week</option>
                            <option value="month" <?php echo $period == 'month' ? 'selected' : ''; ?>>This Month</option>
                            <option value="quarter" <?php echo $period == 'quarter' ? 'selected' : ''; ?>>This Quarter</option>
                            <option value="year" <?php echo $period == 'year' ? 'selected' : ''; ?>>This Year</option>
                            <option value="custom" <?php echo $period == 'custom' ? 'selected' : ''; ?>>Custom Range</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <input type="date" name="start_date" class="form-control" value="<?php echo $start_date; ?>">
                    </div>
                    <div class="col-md-2">
                        <input type="date" name="end_date" class="form-control" value="<?php echo $end_date; ?>">
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary">Apply Filter</button>
                    </div>
                    <div class="col-md-2">
                        <button type="button" class="btn btn-success" onclick="window.print()">
                            <i class="bi bi-printer"></i> Print Report
                        </button>
                    </div>
                    <div class="col-md-2">
                        <a href="export_report.php?type=<?php echo $report_type; ?>&start=<?php echo $start_date; ?>&end=<?php echo $end_date; ?>" class="btn btn-info">
                            <i class="bi bi-download"></i> Export CSV
                        </a>
                    </div>
                </form>

                <!-- Report Content -->
                <?php if ($report_type == 'sales'): ?>
                <div class="row">
                    <div class="col-12">
                        <h5>Daily Sales Summary (<?php echo date('d-m-Y', strtotime($start_date)); ?> to <?php echo date('d-m-Y', strtotime($end_date)); ?>)</h5>
                        <div class="table-responsive">
                            <table class="table table-striped table-bordered">
                                <thead class="table-dark">
                                    <tr>
                                        <th>Date</th>
                                        <th>Invoices</th>
                                        <th>Total Sales</th>
                                        <th>Average Sale</th>
                                        <th>Cash</th>
                                        <th>Card</th>
                                        <th>Online</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $total_sales = 0;
                                    $total_invoices = 0;
                                    while($row = mysqli_fetch_assoc($daily_sales)): 
                                        $total_sales += $row['total_sales'];
                                        $total_invoices += $row['invoice_count'];
                                    ?>
                                    <tr>
                                        <td><?php echo date('d-m-Y', strtotime($row['date'])); ?></td>
                                        <td><?php echo $row['invoice_count']; ?></td>
                                        <td>₹<?php echo number_format($row['total_sales'], 2); ?></td>
                                        <td>₹<?php echo number_format($row['avg_sale'], 2); ?></td>
                                        <td>₹<?php echo number_format($row['cash_sales'], 2); ?></td>
                                        <td>₹<?php echo number_format($row['card_sales'], 2); ?></td>
                                        <td>₹<?php echo number_format($row['online_sales'], 2); ?></td>
                                    </tr>
                                    <?php endwhile; ?>
                                </tbody>
                                <tfoot class="table-info">
                                    <tr>
                                        <th>Totals</th>
                                        <th><?php echo $total_invoices; ?></th>
                                        <th>₹<?php echo number_format($total_sales, 2); ?></th>
                                        <th>₹<?php echo number_format($total_invoices > 0 ? $total_sales / $total_invoices : 0, 2); ?></th>
                                        <th colspan="3"></th>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>
                
                <!-- Payment Status Summary -->
                <div class="row mt-4">
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h5>Payment Status Summary</h5>
                            </div>
                            <div class="card-body">
                                <?php
                                $payment_status_query = mysqli_query($conn, "SELECT 
                                                                              payment_status,
                                                                              COUNT(*) as count,
                                                                              SUM(total_amount) as total,
                                                                              SUM(paid_amount) as paid,
                                                                              SUM(due_amount) as due
                                                                           FROM sales 
                                                                           WHERE sale_date BETWEEN '$start_date' AND '$end_date'
                                                                           GROUP BY payment_status");
                                ?>
                                <table class="table table-sm">
                                    <thead>
                                        <tr>
                                            <th>Status</th>
                                            <th>Count</th>
                                            <th>Total Amount</th>
                                            <th>Paid</th>
                                            <th>Due</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php while($status = mysqli_fetch_assoc($payment_status_query)): ?>
                                        <tr class="table-<?php 
                                            echo $status['payment_status'] == 'paid' ? 'success' : 
                                                ($status['payment_status'] == 'partial' ? 'warning' : 'danger'); 
                                        ?>">
                                            <td><?php echo ucfirst($status['payment_status']); ?></td>
                                            <td><?php echo $status['count']; ?></td>
                                            <td>₹<?php echo number_format($status['total'], 2); ?></td>
                                            <td>₹<?php echo number_format($status['paid'], 2); ?></td>
                                            <td>₹<?php echo number_format($status['due'], 2); ?></td>
                                        </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h5>Outstanding Dues</h5>
                            </div>
                            <div class="card-body">
                                <?php
                                $outstanding_query = mysqli_query($conn, "SELECT 
                                                                           s.invoice_number,
                                                                           c.customer_name,
                                                                           s.sale_date,
                                                                           s.total_amount,
                                                                           s.paid_amount,
                                                                           s.due_amount
                                                                        FROM sales s
                                                                        LEFT JOIN customers c ON s.customer_id = c.id
                                                                        WHERE s.due_amount > 0 
                                                                        AND s.sale_date BETWEEN '$start_date' AND '$end_date'
                                                                        ORDER BY s.due_amount DESC
                                                                        LIMIT 10");
                                ?>
                                <table class="table table-sm">
                                    <thead>
                                        <tr>
                                            <th>Invoice</th>
                                            <th>Customer</th>
                                            <th>Date</th>
                                            <th>Due Amount</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php while($due = mysqli_fetch_assoc($outstanding_query)): ?>
                                        <tr>
                                            <td><?php echo $due['invoice_number']; ?></td>
                                            <td><?php echo $due['customer_name'] ?? 'Walk-in'; ?></td>
                                            <td><?php echo date('d-m-Y', strtotime($due['sale_date'])); ?></td>
                                            <td class="text-danger">₹<?php echo number_format($due['due_amount'], 2); ?></td>
                                        </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="row mt-4">
                    <div class="col-md-6">
                        <h5>Top Customers</h5>
                        <div class="table-responsive">
                            <table class="table table-sm table-striped">
                                <thead>
                                    <tr>
                                        <th>Customer</th>
                                        <th>Phone</th>
                                        <th>Purchases</th>
                                        <th>Total Spent</th>
                                        <th>Average</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while($row = mysqli_fetch_assoc($top_customers)): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($row['customer_name'] ?? 'Walk-in'); ?></td>
                                        <td><?php echo htmlspecialchars($row['phone'] ?? 'N/A'); ?></td>
                                        <td><?php echo $row['purchase_count']; ?></td>
                                        <td>₹<?php echo number_format($row['total_spent'], 2); ?></td>
                                        <td>₹<?php echo number_format($row['avg_spent'], 2); ?></td>
                                    </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <h5>Sales by Hour</h5>
                        <canvas id="hourlyChart"></canvas>
                    </div>
                </div>

                <?php elseif ($report_type == 'purchases'): ?>
                <!-- PURCHASES REPORT SECTION -->
                <div class="row">
                    <div class="col-12">
                        <h5>Daily Purchase Summary (<?php echo date('d-m-Y', strtotime($start_date)); ?> to <?php echo date('d-m-Y', strtotime($end_date)); ?>)</h5>
                        <div class="table-responsive">
                            <table class="table table-striped table-bordered">
                                <thead class="table-dark">
                                    <tr>
                                        <th>Date</th>
                                        <th>Purchase Count</th>
                                        <th>Total Purchases</th>
                                        <th>Average Purchase</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $total_purchases_amount = 0;
                                    $total_purchase_count = 0;
                                    if(mysqli_num_rows($daily_purchases) > 0):
                                        while($row = mysqli_fetch_assoc($daily_purchases)): 
                                            $total_purchases_amount += $row['total_purchases'];
                                            $total_purchase_count += $row['purchase_count'];
                                    ?>
                                    <tr>
                                        <td><?php echo date('d-m-Y', strtotime($row['date'])); ?></td>
                                        <td><?php echo $row['purchase_count']; ?></td>
                                        <td>₹<?php echo number_format($row['total_purchases'], 2); ?></td>
                                        <td>₹<?php echo number_format($row['avg_purchase'], 2); ?></td>
                                    </tr>
                                    <?php 
                                        endwhile; 
                                    else:
                                    ?>
                                    <tr>
                                        <td colspan="4" class="text-center">No purchase data found for the selected period</td>
                                    </tr>
                                    <?php endif; ?>
                                </tbody>
                                <tfoot class="table-info">
                                    <tr>
                                        <th>Totals</th>
                                        <th><?php echo $total_purchase_count; ?></th>
                                        <th>₹<?php echo number_format($total_purchases_amount, 2); ?></th>
                                        <th>₹<?php echo number_format($total_purchase_count > 0 ? $total_purchases_amount / $total_purchase_count : 0, 2); ?></th>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Monthly Purchase Summary -->
                <div class="row mt-4">
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h5>Monthly Purchase Summary</h5>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-sm table-striped">
                                        <thead>
                                            <tr>
                                                <th>Month</th>
                                                <th>Purchase Count</th>
                                                <th>Total Amount</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php 
                                            if(mysqli_num_rows($monthly_purchases) > 0):
                                                while($row = mysqli_fetch_assoc($monthly_purchases)): 
                                            ?>
                                            <tr>
                                                <td><?php echo $row['month']; ?></td>
                                                <td><?php echo $row['purchase_count']; ?></td>
                                                <td>₹<?php echo number_format($row['total_purchases'], 2); ?></td>
                                            </tr>
                                            <?php 
                                                endwhile; 
                                            else:
                                            ?>
                                            <tr>
                                                <td colspan="3" class="text-center">No monthly data found</td>
                                            </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Top Suppliers -->
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h5>Top Suppliers</h5>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-sm table-striped">
                                        <thead>
                                            <tr>
                                                <th>Supplier</th>
                                                <th>Contact</th>
                                                <th>Purchases</th>
                                                <th>Total Amount</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php 
                                            if(mysqli_num_rows($top_suppliers) > 0):
                                                while($row = mysqli_fetch_assoc($top_suppliers)): 
                                            ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($row['supplier_name']); ?></td>
                                                <td><?php echo htmlspecialchars($row['contact_person'] ?? 'N/A'); ?><br><small><?php echo htmlspecialchars($row['phone'] ?? ''); ?></small></td>
                                                <td><?php echo $row['purchase_count']; ?></td>
                                                <td>₹<?php echo number_format($row['total_purchased'], 2); ?></td>
                                            </tr>
                                            <?php 
                                                endwhile; 
                                            else:
                                            ?>
                                            <tr>
                                                <td colspan="4" class="text-center">No supplier data found</td>
                                            </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Purchase Summary Cards -->
                <div class="row mt-4">
                    <div class="col-md-4">
                        <div class="card bg-primary text-white">
                            <div class="card-body">
                                <h6>Total Purchases</h6>
                                <h3><?php echo $total_purchase_count; ?></h3>
                                <small>Transactions</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card bg-success text-white">
                            <div class="card-body">
                                <h6>Total Amount</h6>
                                <h3>₹<?php echo number_format($total_purchases_amount, 2); ?></h3>
                                <small>Purchase Value</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card bg-info text-white">
                            <div class="card-body">
                                <h6>Average Purchase</h6>
                                <h3>₹<?php echo number_format($total_purchase_count > 0 ? $total_purchases_amount / $total_purchase_count : 0, 2); ?></h3>
                                <small>Per Transaction</small>
                            </div>
                        </div>
                    </div>
                </div>

                <?php elseif ($report_type == 'stock'): 
                $stock_val = mysqli_fetch_assoc($stock_value);
                ?>
                <div class="row">
                    <div class="col-md-4">
                        <div class="card bg-primary text-white">
                            <div class="card-body">
                                <h6>Total Stock Value</h6>
                                <h3>₹<?php echo number_format($stock_val['total_stock_value'] ?? 0, 2); ?></h3>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card bg-success text-white">
                            <div class="card-body">
                                <h6>Total Products</h6>
                                <h3><?php echo $stock_val['total_products'] ?? 0; ?></h3>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card bg-info text-white">
                            <div class="card-body">
                                <h6>Total Items in Stock</h6>
                                <h3><?php echo $stock_val['total_items'] ?? 0; ?></h3>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row mt-4">
                    <div class="col-md-6">
                        <h5 class="text-danger">Low Stock Alert</h5>
                        <div class="table-responsive">
                            <table class="table table-danger table-striped">
                                <thead>
                                    <tr>
                                        <th>Part #</th>
                                        <th>Part Name</th>
                                        <th>Category</th>
                                        <th>Current</th>
                                        <th>Min</th>
                                        <th>Required</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while($row = mysqli_fetch_assoc($low_stock)): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($row['part_number']); ?></td>
                                        <td><?php echo htmlspecialchars($row['part_name']); ?></td>
                                        <td><?php echo htmlspecialchars($row['category_name'] ?? 'N/A'); ?></td>
                                        <td class="text-danger"><strong><?php echo $row['quantity']; ?></strong></td>
                                        <td><?php echo $row['min_stock_level']; ?></td>
                                        <td><?php echo $row['required_qty']; ?></td>
                                    </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <h5>Category-wise Stock</h5>
                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>Category</th>
                                        <th>Parts</th>
                                        <th>Quantity</th>
                                        <th>Value</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while($row = mysqli_fetch_assoc($category_stock)): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($row['category_name']); ?></td>
                                        <td><?php echo $row['total_parts']; ?></td>
                                        <td><?php echo $row['total_quantity']; ?></td>
                                        <td>₹<?php echo number_format($row['total_value'] ?? 0, 2); ?></td>
                                    </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <?php elseif ($report_type == 'performance'): ?>
                <div class="row">
                    <div class="col-12">
                        <h5>Staff Performance (<?php echo date('d-m-Y', strtotime($start_date)); ?> to <?php echo date('d-m-Y', strtotime($end_date)); ?>)</h5>
                        <div class="table-responsive">
                            <table class="table table-bordered table-striped">
                                <thead class="table-dark">
                                    <tr>
                                        <th>Staff</th>
                                        <th>Sales Count</th>
                                        <th>Sales Amount</th>
                                        <th>Purchase Count</th>
                                        <th>Purchase Amount</th>
                                        <th>Jobs Handled</th>
                                        <th>Performance Score</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while($row = mysqli_fetch_assoc($staff_performance)): 
                                        $score = 0;
                                        if($row['sales_amount'] > 0) $score += 50;
                                        if($row['purchase_amount'] > 0) $score += 30;
                                        if($row['jobs_handled'] > 0) $score += 20;
                                    ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($row['username']); ?></strong></td>
                                        <td><?php echo $row['total_sales'] ?? 0; ?></td>
                                        <td>₹<?php echo number_format($row['sales_amount'] ?? 0, 2); ?></td>
                                        <td><?php echo $row['total_purchases'] ?? 0; ?></td>
                                        <td>₹<?php echo number_format($row['purchase_amount'] ?? 0, 2); ?></td>
                                        <td><?php echo $row['jobs_handled'] ?? 0; ?></td>
                                        <td>
                                            <div class="progress">
                                                <div class="progress-bar bg-success" style="width: <?php echo $score; ?>%">
                                                    <?php echo $score; ?>%
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="row mt-4">
                    <div class="col-12">
                        <h5>Monthly Trends (Last 12 Months)</h5>
                        <canvas id="trendsChart"></canvas>
                    </div>
                </div>

                <?php elseif ($report_type == 'models'): ?>
                <!-- BIKE MODELS REPORT SECTION - FIXED AND ADDED -->
                <div class="row">
                    <div class="col-12">
                        <h5>Most Serviced Bike Models (<?php echo date('d-m-Y', strtotime($start_date)); ?> to <?php echo date('d-m-Y', strtotime($end_date)); ?>)</h5>
                        <div class="table-responsive">
                            <table class="table table-striped table-bordered">
                                <thead class="table-dark">
                                    <tr>
                                        <th>#</th>
                                        <th>Company</th>
                                        <th>Model Name</th>
                                        <th>Service Count</th>
                                        <th>Total Estimated (₹)</th>
                                        <th>Average per Service</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $sl_no = 1;
                                    $total_services = 0;
                                    $total_estimated_all = 0;
                                    if(mysqli_num_rows($model_service) > 0):
                                        while($row = mysqli_fetch_assoc($model_service)): 
                                            $total_services += $row['service_count'];
                                            $total_estimated_all += $row['total_estimated'];
                                            $avg_service = $row['service_count'] > 0 ? $row['total_estimated'] / $row['service_count'] : 0;
                                    ?>
                                    <tr>
                                        <td><?php echo $sl_no++; ?></td>
                                        <td><?php echo htmlspecialchars($row['company_name']); ?></td>
                                        <td><strong><?php echo htmlspecialchars($row['model_name']); ?></strong></td>
                                        <td class="text-center"><span class="badge bg-primary"><?php echo $row['service_count']; ?></span></td>
                                        <td>₹<?php echo number_format($row['total_estimated'], 2); ?></td>
                                        <td>₹<?php echo number_format($avg_service, 2); ?></td>
                                    </tr>
                                    <?php 
                                        endwhile; 
                                    else:
                                    ?>
                                    <tr>
                                        <td colspan="6" class="text-center">No service data found for the selected period</td>
                                    </tr>
                                    <?php endif; ?>
                                </tbody>
                                <tfoot class="table-info">
                                    <tr>
                                        <th colspan="3" class="text-end">Totals:</th>
                                        <th><?php echo $total_services; ?></th>
                                        <th>₹<?php echo number_format($total_estimated_all, 2); ?></th>
                                        <th>₹<?php echo number_format($total_services > 0 ? $total_estimated_all / $total_services : 0, 2); ?></th>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Parts by Model -->
                <div class="row mt-4">
                    <div class="col-12">
                        <h5>Parts Stock by Model</h5>
                        <div class="table-responsive">
                            <table class="table table-striped table-bordered">
                                <thead class="table-dark">
                                    <tr>
                                        <th>#</th>
                                        <th>Company</th>
                                        <th>Model Name</th>
                                        <th>Parts Count</th>
                                        <th>Total Quantity</th>
                                        <th>Stock Value (₹)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $sl_no = 1;
                                    $total_parts_all = 0;
                                    $total_qty_all = 0;
                                    $total_value_all = 0;
                                    if(mysqli_num_rows($parts_by_model) > 0):
                                        while($row = mysqli_fetch_assoc($parts_by_model)): 
                                            $total_parts_all += $row['parts_count'];
                                            $total_qty_all += $row['stock_quantity'];
                                            $total_value_all += $row['stock_value'];
                                    ?>
                                    <tr>
                                        <td><?php echo $sl_no++; ?></td>
                                        <td><?php echo htmlspecialchars($row['company_name']); ?></td>
                                        <td><strong><?php echo htmlspecialchars($row['model_name']); ?></strong></td>
                                        <td class="text-center"><?php echo $row['parts_count']; ?></td>
                                        <td class="text-center"><?php echo $row['stock_quantity']; ?></td>
                                        <td>₹<?php echo number_format($row['stock_value'], 2); ?></td>
                                    </tr>
                                    <?php 
                                        endwhile; 
                                    else:
                                    ?>
                                    <tr>
                                        <td colspan="6" class="text-center">No parts data found</td>
                                    </tr>
                                    <?php endif; ?>
                                </tbody>
                                <tfoot class="table-info">
                                    <tr>
                                        <th colspan="3" class="text-end">Totals:</th>
                                        <th><?php echo $total_parts_all; ?></th>
                                        <th><?php echo $total_qty_all; ?></th>
                                        <th>₹<?php echo number_format($total_value_all, 2); ?></th>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Summary Cards for Models -->
                <div class="row mt-4">
                    <div class="col-md-4">
                        <div class="card bg-primary text-white">
                            <div class="card-body">
                                <h6>Total Models Serviced</h6>
                                <h3><?php echo mysqli_num_rows($model_service); ?></h3>
                                <small>Unique Models</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card bg-success text-white">
                            <div class="card-body">
                                <h6>Total Service Jobs</h6>
                                <h3><?php echo $total_services; ?></h3>
                                <small>Service Count</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card bg-info text-white">
                            <div class="card-body">
                                <h6>Avg Service Value</h6>
                                <h3>₹<?php echo number_format($total_services > 0 ? $total_estimated_all / $total_services : 0, 2); ?></h3>
                                <small>Per Service</small>
                            </div>
                        </div>
                    </div>
                </div>

                <?php elseif ($report_type == 'profit_analysis'): ?>
                <div class="row">
                    <div class="col-12">
                        <h5>Product-wise Profit Analysis</h5>
                        <div class="table-responsive">
                            <table class="table table-bordered table-striped">
                                <thead class="table-dark">
                                    <tr>
                                        <th>Part #</th>
                                        <th>Part Name</th>
                                        <th>Category</th>
                                        <th>Qty Sold</th>
                                        <th>Avg Selling</th>
                                        <th>Avg Purchase</th>
                                        <th>Profit/Unit</th>
                                        <th>Total Profit</th>
                                        <th>Margin %</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $total_profit_all = 0;
                                    while($row = mysqli_fetch_assoc($product_profit)): 
                                        $margin = $row['avg_selling'] > 0 ? ($row['profit_per_unit'] / $row['avg_selling']) * 100 : 0;
                                        $total_profit_all += $row['total_profit'];
                                    ?>
                                    <tr class="<?php echo $margin >= 20 ? 'table-success' : ($margin >= 10 ? 'table-warning' : 'table-danger'); ?>">
                                        <td><?php echo htmlspecialchars($row['part_number']); ?></td>
                                        <td><?php echo htmlspecialchars($row['part_name']); ?></td>
                                        <td><?php echo htmlspecialchars($row['category_name'] ?? 'N/A'); ?></td>
                                        <td><?php echo $row['qty_sold']; ?></td>
                                        <td>₹<?php echo number_format($row['avg_selling'], 2); ?></td>
                                        <td>₹<?php echo number_format($row['avg_purchase'], 2); ?></td>
                                        <td>₹<?php echo number_format($row['profit_per_unit'], 2); ?></td>
                                        <td>₹<?php echo number_format($row['total_profit'], 2); ?></td>
                                        <td><?php echo number_format($margin, 2); ?>%</td>
                                    </tr>
                                    <?php endwhile; ?>
                                </tbody>
                                <tfoot class="table-info">
                                    <tr>
                                        <th colspan="7" class="text-end">Total Profit:</th>
                                        <th colspan="2">₹<?php echo number_format($total_profit_all, 2); ?></th>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
    <?php if ($report_type == 'sales'): ?>
    // Hourly Sales Chart
    const hourlyCtx = document.getElementById('hourlyChart').getContext('2d');
    new Chart(hourlyCtx, {
        type: 'bar',
        data: {
            labels: [<?php 
                mysqli_data_seek($hourly_sales, 0);
                $hours = [];
                $amounts = [];
                while($row = mysqli_fetch_assoc($hourly_sales)) {
                    $hours[] = "'" . $row['hour'] . ":00'";
                    $amounts[] = $row['total_amount'];
                }
                echo implode(',', $hours);
            ?>],
            datasets: [{
                label: 'Sales Amount',
                data: [<?php echo implode(',', $amounts); ?>],
                backgroundColor: 'rgba(54, 162, 235, 0.5)',
                borderColor: 'rgba(54, 162, 235, 1)',
                borderWidth: 1
            }]
        },
        options: {
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: function(value) {
                            return '₹' + value;
                        }
                    }
                }
            }
        }
    });
    <?php endif; ?>

    <?php if ($report_type == 'performance'): ?>
    // Trends Chart
    const trendsCtx = document.getElementById('trendsChart').getContext('2d');
    new Chart(trendsCtx, {
        type: 'line',
        data: {
            labels: [<?php 
                mysqli_data_seek($monthly_trends, 0);
                $months = [];
                $sales = [];
                $purchases = [];
                while($row = mysqli_fetch_assoc($monthly_trends)) {
                    $months[] = "'" . $row['month'] . "'";
                    $sales[] = $row['sales_amount'] ?? 0;
                    $purchases[] = $row['purchase_amount'] ?? 0;
                }
                echo implode(',', $months);
            ?>],
            datasets: [{
                label: 'Sales',
                data: [<?php echo implode(',', $sales); ?>],
                borderColor: 'rgb(75, 192, 192)',
                tension: 0.1
            }, {
                label: 'Purchases',
                data: [<?php echo implode(',', $purchases); ?>],
                borderColor: 'rgb(255, 99, 132)',
                tension: 0.1
            }]
        },
        options: {
            responsive: true,
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: function(value) {
                            return '₹' + value;
                        }
                    }
                }
            }
        }
    });
    <?php endif; ?>
    </script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>