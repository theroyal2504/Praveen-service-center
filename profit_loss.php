<?php
require_once 'config.php';

if (!isLoggedIn()) {
    redirect('index.php');
}

// Get date filters
$filter_type = $_GET['filter_type'] ?? 'today';
$start_date = $_GET['start_date'] ?? date('Y-m-d');
$end_date = $_GET['end_date'] ?? date('Y-m-d');

switch($filter_type) {
    case 'today':
        $start_date = date('Y-m-d');
        $end_date = date('Y-m-d');
        break;
    case 'yesterday':
        $start_date = date('Y-m-d', strtotime('-1 day'));
        $end_date = date('Y-m-d', strtotime('-1 day'));
        break;
    case 'week':
        $start_date = date('Y-m-d', strtotime('monday this week'));
        $end_date = date('Y-m-d', strtotime('sunday this week'));
        break;
    case 'month':
        $start_date = date('Y-m-01');
        $end_date = date('Y-m-t');
        break;
    case 'last_month':
        $start_date = date('Y-m-01', strtotime('first day of last month'));
        $end_date = date('Y-m-t', strtotime('last day of last month'));
        break;
    case 'year':
        $start_date = date('Y-01-01');
        $end_date = date('Y-12-31');
        break;
    case 'custom':
        // Keep as is
        break;
}

// Get Total Revenue (Sales)
$sales_query = "SELECT COUNT(*) as total_transactions, 
                       SUM(total_amount) as total_revenue,
                       SUM(total_amount) as gross_sales,
                       COUNT(DISTINCT customer_id) as unique_customers
                FROM sales 
                WHERE sale_date BETWEEN '$start_date' AND '$end_date'";
$sales_result = mysqli_query($conn, $sales_query);
$sales_data = mysqli_fetch_assoc($sales_result);

// Get Total Purchases
$purchases_query = "SELECT COUNT(*) as total_purchases,
                           SUM(total_amount) as total_purchase_cost,
                           COUNT(DISTINCT supplier_id) as unique_suppliers
                    FROM purchases 
                    WHERE purchase_date BETWEEN '$start_date' AND '$end_date'";
$purchases_result = mysqli_query($conn, $purchases_query);
$purchases_data = mysqli_fetch_assoc($purchases_result);

// Calculate Profit/Loss
$total_revenue = $sales_data['total_revenue'] ?? 0;
$total_cost = $purchases_data['total_purchase_cost'] ?? 0;
$gross_profit = $total_revenue - $total_cost;
$profit_margin = $total_revenue > 0 ? ($gross_profit / $total_revenue) * 100 : 0;

// Get Daily Breakdown
$daily_query = "SELECT 
                    sale_date as date,
                    COUNT(*) as transaction_count,
                    SUM(total_amount) as daily_revenue
                FROM sales 
                WHERE sale_date BETWEEN '$start_date' AND '$end_date'
                GROUP BY sale_date
                ORDER BY sale_date DESC";
$daily_result = mysqli_query($conn, $daily_query);

// Get Payment Method Breakdown
$payment_query = "SELECT 
                    payment_method,
                    COUNT(*) as count,
                    SUM(total_amount) as amount
                  FROM sales 
                  WHERE sale_date BETWEEN '$start_date' AND '$end_date'
                  GROUP BY payment_method";
$payment_result = mysqli_query($conn, $payment_query);

// Get Top Selling Parts
$top_parts_query = "SELECT 
                        p.part_name,
                        p.part_number,
                        SUM(si.quantity) as total_quantity,
                        SUM(si.quantity * si.selling_price) as total_value,
                        AVG(si.selling_price) as avg_price
                    FROM sale_items si
                    JOIN sales s ON si.sale_id = s.id
                    JOIN parts_master p ON si.part_id = p.id
                    WHERE s.sale_date BETWEEN '$start_date' AND '$end_date'
                    GROUP BY si.part_id
                    ORDER BY total_quantity DESC
                    LIMIT 10";
$top_parts_result = mysqli_query($conn, $top_parts_query);

// Get Top Purchased Parts
$top_purchased_query = "SELECT 
                            p.part_name,
                            p.part_number,
                            SUM(pi.quantity) as total_quantity,
                            SUM(pi.quantity * pi.purchase_price) as total_value,
                            AVG(pi.purchase_price) as avg_price
                        FROM purchase_items pi
                        JOIN purchases pu ON pi.purchase_id = pu.id
                        JOIN parts_master p ON pi.part_id = p.id
                        WHERE pu.purchase_date BETWEEN '$start_date' AND '$end_date'
                        GROUP BY pi.part_id
                        ORDER BY total_quantity DESC
                        LIMIT 10";
$top_purchased_result = mysqli_query($conn, $top_purchased_query);

// Auto-calculate from daily_transactions
$accounting_income = mysqli_fetch_assoc(mysqli_query($conn, 
    "SELECT SUM(amount) as total FROM daily_transactions 
     WHERE type = 'income' AND transaction_date BETWEEN '$start_date' AND '$end_date'"))['total'] ?? 0;
     
$accounting_expense = mysqli_fetch_assoc(mysqli_query($conn, 
    "SELECT SUM(amount) as total FROM daily_transactions 
     WHERE type = 'expense' AND transaction_date BETWEEN '$start_date' AND '$end_date'"))['total'] ?? 0;

// Add to existing totals
$total_revenue += $accounting_income;
$total_cost += $accounting_expense;

// Get Category-wise Profit
$category_profit_query = "SELECT 
                            c.category_name,
                            SUM(si.quantity * si.selling_price) as sales_value,
                            SUM(pi.quantity * pi.purchase_price) as cost_value,
                            SUM(si.quantity) as quantity_sold
                          FROM categories c
                          LEFT JOIN parts_master p ON c.id = p.category_id
                          LEFT JOIN sale_items si ON p.id = si.part_id
                          LEFT JOIN sales s ON si.sale_id = s.id AND s.sale_date BETWEEN '$start_date' AND '$end_date'
                          LEFT JOIN purchase_items pi ON p.id = pi.part_id
                          LEFT JOIN purchases pu ON pi.purchase_id = pu.id AND pu.purchase_date BETWEEN '$start_date' AND '$end_date'
                          GROUP BY c.id
                          HAVING sales_value > 0 OR cost_value > 0
                          ORDER BY sales_value DESC";
$category_profit_result = mysqli_query($conn, $category_profit_query);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profit & Loss Dashboard - Bike Management System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container-fluid">
            <a class="navbar-brand" href="dashboard.php">
                <i class="bi bi-bicycle"></i> Bike Management System
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
                        <a class="nav-link" href="reports.php">
                            <i class="bi bi-file-text"></i> Reports
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
        <!-- Date Filter -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0">Date Range Filter</h5>
            </div>
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-3">
                        <select name="filter_type" class="form-control" onchange="this.form.submit()">
                            <option value="today" <?php echo $filter_type == 'today' ? 'selected' : ''; ?>>Today</option>
                            <option value="yesterday" <?php echo $filter_type == 'yesterday' ? 'selected' : ''; ?>>Yesterday</option>
                            <option value="week" <?php echo $filter_type == 'week' ? 'selected' : ''; ?>>This Week</option>
                            <option value="month" <?php echo $filter_type == 'month' ? 'selected' : ''; ?>>This Month</option>
                            <option value="last_month" <?php echo $filter_type == 'last_month' ? 'selected' : ''; ?>>Last Month</option>
                            <option value="year" <?php echo $filter_type == 'year' ? 'selected' : ''; ?>>This Year</option>
                            <option value="custom" <?php echo $filter_type == 'custom' ? 'selected' : ''; ?>>Custom Range</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <input type="date" name="start_date" class="form-control" value="<?php echo $start_date; ?>">
                    </div>
                    <div class="col-md-3">
                        <input type="date" name="end_date" class="form-control" value="<?php echo $end_date; ?>">
                    </div>
                    <div class="col-md-3">
                        <button type="submit" class="btn btn-primary">Apply Filter</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Summary Cards -->
        <div class="row">
            <div class="col-md-3 mb-3">
                <div class="card text-white bg-success">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="card-title">Total Revenue</h6>
                                <h3 class="mb-0">₹<?php echo number_format($total_revenue, 2); ?></h3>
                                <small><?php echo $sales_data['total_transactions'] ?? 0; ?> Transactions</small>
                            </div>
                            <i class="bi bi-cash-stack fs-1"></i>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-3 mb-3">
                <div class="card text-white bg-danger">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="card-title">Total Cost</h6>
                                <h3 class="mb-0">₹<?php echo number_format($total_cost, 2); ?></h3>
                                <small><?php echo $purchases_data['total_purchases'] ?? 0; ?> Purchases</small>
                            </div>
                            <i class="bi bi-cart-dash fs-1"></i>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-3 mb-3">
                <div class="card text-white bg-<?php echo $gross_profit >= 0 ? 'primary' : 'warning'; ?>">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="card-title">Gross Profit/Loss</h6>
                                <h3 class="mb-0">₹<?php echo number_format(abs($gross_profit), 2); ?></h3>
                                <small><?php echo $gross_profit >= 0 ? 'Profit' : 'Loss'; ?></small>
                            </div>
                            <i class="bi bi-graph-up-arrow fs-1"></i>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-3 mb-3">
                <div class="card text-white bg-info">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="card-title">Profit Margin</h6>
                                <h3 class="mb-0"><?php echo number_format($profit_margin, 2); ?>%</h3>
                                <small>Margin on Revenue</small>
                            </div>
                            <i class="bi bi-percent fs-1"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Charts Row -->
        <div class="row mt-4">
            <div class="col-md-6 mb-3">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Daily Revenue Trend</h5>
                    </div>
                    <div class="card-body">
                        <canvas id="revenueChart"></canvas>
                    </div>
                </div>
            </div>
            
            <div class="col-md-6 mb-3">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Payment Methods</h5>
                    </div>
                    <div class="card-body">
                        <canvas id="paymentChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- Top Parts Row -->
        <div class="row mt-4">
            <div class="col-md-6 mb-3">
                <div class="card">
                    <div class="card-header bg-success text-white">
                        <h5 class="mb-0">Top 10 Selling Parts</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Part Name</th>
                                        <th>Part #</th>
                                        <th>Qty Sold</th>
                                        <th>Total Value</th>
                                        <th>Avg Price</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while($part = mysqli_fetch_assoc($top_parts_result)): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($part['part_name']); ?></td>
                                        <td><?php echo htmlspecialchars($part['part_number']); ?></td>
                                        <td><?php echo $part['total_quantity']; ?></td>
                                        <td>₹<?php echo number_format($part['total_value'], 2); ?></td>
                                        <td>₹<?php echo number_format($part['avg_price'], 2); ?></td>
                                    </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-6 mb-3">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">Top 10 Purchased Parts</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Part Name</th>
                                        <th>Part #</th>
                                        <th>Qty Purchased</th>
                                        <th>Total Cost</th>
                                        <th>Avg Price</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while($part = mysqli_fetch_assoc($top_purchased_result)): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($part['part_name']); ?></td>
                                        <td><?php echo htmlspecialchars($part['part_number']); ?></td>
                                        <td><?php echo $part['total_quantity']; ?></td>
                                        <td>₹<?php echo number_format($part['total_value'], 2); ?></td>
                                        <td>₹<?php echo number_format($part['avg_price'], 2); ?></td>
                                    </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Category-wise Profit -->
        <div class="row mt-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header bg-warning">
                        <h5 class="mb-0">Category-wise Profit Analysis</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered">
                                <thead>
                                    <tr>
                                        <th>Category</th>
                                        <th>Sales Value</th>
                                        <th>Cost Value</th>
                                        <th>Profit/Loss</th>
                                        <th>Margin %</th>
                                        <th>Quantity Sold</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    mysqli_data_seek($category_profit_result, 0);
                                    while($cat = mysqli_fetch_assoc($category_profit_result)): 
                                        $cat_sales = $cat['sales_value'] ?? 0;
                                        $cat_cost = $cat['cost_value'] ?? 0;
                                        $cat_profit = $cat_sales - $cat_cost;
                                        $cat_margin = $cat_sales > 0 ? ($cat_profit / $cat_sales) * 100 : 0;
                                    ?>
                                    <tr class="<?php echo $cat_profit >= 0 ? 'table-success' : 'table-danger'; ?>">
                                        <td><strong><?php echo htmlspecialchars($cat['category_name'] ?? 'Uncategorized'); ?></strong></td>
                                        <td>₹<?php echo number_format($cat_sales, 2); ?></td>
                                        <td>₹<?php echo number_format($cat_cost, 2); ?></td>
                                        <td>₹<?php echo number_format($cat_profit, 2); ?></td>
                                        <td><?php echo number_format($cat_margin, 2); ?>%</td>
                                        <td><?php echo $cat['quantity_sold'] ?? 0; ?></td>
                                    </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Daily Breakdown -->
        <div class="row mt-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Daily Transaction Breakdown</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Transactions</th>
                                        <th>Revenue</th>
                                        <th>Average Transaction</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while($day = mysqli_fetch_assoc($daily_result)): 
                                        $avg_transaction = $day['transaction_count'] > 0 ? $day['daily_revenue'] / $day['transaction_count'] : 0;
                                    ?>
                                    <tr>
                                        <td><?php echo date('d-m-Y', strtotime($day['date'])); ?></td>
                                        <td><?php echo $day['transaction_count']; ?></td>
                                        <td>₹<?php echo number_format($day['daily_revenue'], 2); ?></td>
                                        <td>₹<?php echo number_format($avg_transaction, 2); ?></td>
                                    </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
    // Revenue Chart
    const revenueCtx = document.getElementById('revenueChart').getContext('2d');
    new Chart(revenueCtx, {
        type: 'line',
        data: {
            labels: [<?php 
                mysqli_data_seek($daily_result, 0);
                $dates = [];
                $revenues = [];
                while($day = mysqli_fetch_assoc($daily_result)) {
                    $dates[] = "'" . date('d M', strtotime($day['date'])) . "'";
                    $revenues[] = $day['daily_revenue'];
                }
                echo implode(',', array_reverse($dates));
            ?>],
            datasets: [{
                label: 'Daily Revenue',
                data: [<?php echo implode(',', array_reverse($revenues)); ?>],
                borderColor: 'rgb(75, 192, 192)',
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
                            return '₹' + value.toLocaleString();
                        }
                    }
                }
            }
        }
    });

    // Payment Chart
    const paymentCtx = document.getElementById('paymentChart').getContext('2d');
    <?php
    mysqli_data_seek($payment_result, 0);
    $payment_labels = [];
    $payment_data = [];
    $payment_colors = [
        "'rgba(40, 167, 69, 0.8)'",   // cash - green
        "'rgba(23, 162, 184, 0.8)'",  // card - blue
        "'rgba(255, 193, 7, 0.8)'"    // online - yellow
    ];
    $i = 0;
    while($payment = mysqli_fetch_assoc($payment_result)) {
        $payment_labels[] = "'" . ucfirst($payment['payment_method']) . "'";
        $payment_data[] = $payment['amount'];
        $i++;
    }
    ?>
    new Chart(paymentCtx, {
        type: 'pie',
        data: {
            labels: [<?php echo implode(',', $payment_labels); ?>],
            datasets: [{
                data: [<?php echo implode(',', $payment_data); ?>],
                backgroundColor: [<?php echo implode(',', array_slice($payment_colors, 0, $i)); ?>]
            }]
        },
        options: {
            responsive: true,
            plugins: {
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            let label = context.label || '';
                            let value = context.raw || 0;
                            let total = context.dataset.data.reduce((a, b) => a + b, 0);
                            let percentage = ((value / total) * 100).toFixed(2);
                            return label + ': ₹' + value.toLocaleString() + ' (' + percentage + '%)';
                        }
                    }
                }
            }
        }
    });
    </script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>