<?php
require_once 'config.php';

if (!isLoggedIn()) {
    redirect('index.php');
}

// Get date range from request
$from_date = isset($_GET['from_date']) ? $_GET['from_date'] : date('Y-m-01');
$to_date = isset($_GET['to_date']) ? $_GET['to_date'] : date('Y-m-t');
$report_type = isset($_GET['report_type']) ? $_GET['report_type'] : 'all';

// Build query based on report type
$where_clause = "WHERE 1=1";

if ($report_type == 'operational') {
    $where_clause .= " AND expense_date BETWEEN '$from_date' AND '$to_date'";
    
    // Get operational expenses
    $expenses_query = "SELECT 
                        DATE(expense_date) as date,
                        category,
                        description,
                        quantity,
                        unit_price,
                        total_amount,
                        notes,
                        'Operational' as expense_type
                       FROM operational_expenses 
                       $where_clause 
                       ORDER BY expense_date DESC";
    
    // Get summary by category
    $category_summary = mysqli_query($conn, "SELECT 
                                             category, 
                                             COUNT(*) as count, 
                                             SUM(total_amount) as total 
                                             FROM operational_expenses 
                                             $where_clause 
                                             GROUP BY category 
                                             ORDER BY total DESC");
    
    // Get daily summary
    $daily_summary = mysqli_query($conn, "SELECT 
                                          DATE(expense_date) as date, 
                                          COUNT(*) as count, 
                                          SUM(total_amount) as total 
                                          FROM operational_expenses 
                                          $where_clause 
                                          GROUP BY DATE(expense_date) 
                                          ORDER BY date DESC");
    
} elseif ($report_type == 'purchases') {
    $where_clause = "WHERE status = 'completed' AND purchase_date BETWEEN '$from_date' AND '$to_date'";
    
    // Get purchase expenses (stock items)
    $expenses_query = "SELECT 
                        purchase_date as date,
                        'Stock Purchase' as category,
                        CONCAT('Invoice: ', invoice_number) as description,
                        total_amount,
                        'Purchase' as expense_type
                       FROM purchases 
                       $where_clause 
                       ORDER BY purchase_date DESC";
    
    // Get supplier wise summary
    $category_summary = mysqli_query($conn, "SELECT 
                                             s.supplier_name as category, 
                                             COUNT(*) as count, 
                                             SUM(p.total_amount) as total 
                                             FROM purchases p
                                             LEFT JOIN suppliers s ON p.supplier_id = s.id
                                             $where_clause 
                                             GROUP BY s.supplier_name 
                                             ORDER BY total DESC");
    
    // Get daily summary
    $daily_summary = mysqli_query($conn, "SELECT 
                                          purchase_date as date, 
                                          COUNT(*) as count, 
                                          SUM(total_amount) as total 
                                          FROM purchases 
                                          $where_clause 
                                          GROUP BY purchase_date 
                                          ORDER BY date DESC");
    
} else {
    // Combined report
    $expenses_query = "SELECT 
                        date,
                        category,
                        description,
                        quantity,
                        unit_price,
                        total_amount,
                        expense_type
                       FROM (
                           SELECT 
                               expense_date as date,
                               category,
                               description,
                               quantity,
                               unit_price,
                               total_amount,
                               'Operational' as expense_type
                           FROM operational_expenses 
                           WHERE expense_date BETWEEN '$from_date' AND '$to_date'
                           
                           UNION ALL
                           
                           SELECT 
                               purchase_date as date,
                               'Stock Purchase' as category,
                               CONCAT('Invoice: ', invoice_number) as description,
                               1 as quantity,
                               total_amount as unit_price,
                               total_amount,
                               'Purchase' as expense_type
                           FROM purchases 
                           WHERE status = 'completed' AND purchase_date BETWEEN '$from_date' AND '$to_date'
                       ) as combined
                       ORDER BY date DESC";
    
    // Get type wise summary
    $category_summary = mysqli_query($conn, "SELECT 
                                             expense_type as category, 
                                             COUNT(*) as count, 
                                             SUM(total_amount) as total 
                                             FROM (
                                                 SELECT 
                                                     'Operational' as expense_type,
                                                     total_amount
                                                 FROM operational_expenses 
                                                 WHERE expense_date BETWEEN '$from_date' AND '$to_date'
                                                 
                                                 UNION ALL
                                                 
                                                 SELECT 
                                                     'Purchase' as expense_type,
                                                     total_amount
                                                 FROM purchases 
                                                 WHERE status = 'completed' AND purchase_date BETWEEN '$from_date' AND '$to_date'
                                             ) as combined
                                             GROUP BY expense_type");
    
    // Get daily summary
    $daily_summary = mysqli_query($conn, "SELECT 
                                          date,
                                          SUM(total) as total,
                                          SUM(count) as count
                                          FROM (
                                              SELECT 
                                                  expense_date as date,
                                                  1 as count,
                                                  total_amount as total
                                              FROM operational_expenses 
                                              WHERE expense_date BETWEEN '$from_date' AND '$to_date'
                                              
                                              UNION ALL
                                              
                                              SELECT 
                                                  purchase_date as date,
                                                  1 as count,
                                                  total_amount as total
                                              FROM purchases 
                                              WHERE status = 'completed' AND purchase_date BETWEEN '$from_date' AND '$to_date'
                                          ) as daily
                                          GROUP BY date
                                          ORDER BY date DESC");
}

$expenses = mysqli_query($conn, $expenses_query);

// Get totals
$total_query = mysqli_query($conn, "SELECT 
                                    (SELECT COALESCE(SUM(total_amount), 0) FROM operational_expenses WHERE expense_date BETWEEN '$from_date' AND '$to_date') as operational_total,
                                    (SELECT COALESCE(SUM(total_amount), 0) FROM purchases WHERE status = 'completed' AND purchase_date BETWEEN '$from_date' AND '$to_date') as purchase_total");

$totals = mysqli_fetch_assoc($total_query);
$operational_total = $totals['operational_total'] ?? 0;
$purchase_total = $totals['purchase_total'] ?? 0;
$grand_total = $operational_total + $purchase_total;

// Get previous period totals for comparison
$prev_from = date('Y-m-d', strtotime($from_date . ' - ' . (strtotime($to_date) - strtotime($from_date)) . ' seconds'));
$prev_to = date('Y-m-d', strtotime($from_date . ' - 1 day'));

$prev_total_query = mysqli_query($conn, "SELECT 
                                         (SELECT COALESCE(SUM(total_amount), 0) FROM operational_expenses WHERE expense_date BETWEEN '$prev_from' AND '$prev_to') as operational_total,
                                         (SELECT COALESCE(SUM(total_amount), 0) FROM purchases WHERE status = 'completed' AND purchase_date BETWEEN '$prev_from' AND '$prev_to') as purchase_total");

$prev_totals = mysqli_fetch_assoc($prev_total_query);
$prev_grand_total = ($prev_totals['operational_total'] ?? 0) + ($prev_totals['purchase_total'] ?? 0);

// Calculate percentage change
$percent_change = $prev_grand_total > 0 ? round(($grand_total - $prev_grand_total) / $prev_grand_total * 100, 2) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Expenses Report - Bike Management System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <style>
        .report-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
        }
        .summary-card {
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            transition: transform 0.3s;
        }
        .summary-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 20px rgba(0,0,0,0.15);
        }
        .trend-up { color: #dc3545; }
        .trend-down { color: #28a745; }
        .print-only { display: none; }
        @media print {
            .no-print { display: none; }
            .print-only { display: block; }
            .card { border: 1px solid #ddd; }
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark no-print">
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
                        <a class="nav-link" href="logout.php">
                            <i class="bi bi-box-arrow-right"></i> Logout
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container-fluid mt-4">
        <!-- Report Header -->
        <div class="report-header no-print">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2><i class="bi bi-graph-up"></i> Expenses Report</h2>
                    <p class="mb-0">From <?php echo date('d-m-Y', strtotime($from_date)); ?> to <?php echo date('d-m-Y', strtotime($to_date)); ?></p>
                </div>
                <div>
                    <button onclick="window.print()" class="btn btn-light me-2">
                        <i class="bi bi-printer"></i> Print
                    </button>
                    <a href="operational_expenses.php" class="btn btn-light">
                        <i class="bi bi-arrow-left"></i> Back to Expenses
                    </a>
                </div>
            </div>
        </div>

        <!-- Filter Form -->
        <div class="card mb-4 no-print">
            <div class="card-body">
                <form method="GET" class="row">
                    <div class="col-md-3">
                        <label class="form-label">From Date</label>
                        <input type="date" name="from_date" class="form-control" value="<?php echo $from_date; ?>" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">To Date</label>
                        <input type="date" name="to_date" class="form-control" value="<?php echo $to_date; ?>" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Report Type</label>
                        <select name="report_type" class="form-control">
                            <option value="all" <?php echo $report_type == 'all' ? 'selected' : ''; ?>>All Expenses</option>
                            <option value="operational" <?php echo $report_type == 'operational' ? 'selected' : ''; ?>>Operational Only</option>
                            <option value="purchases" <?php echo $report_type == 'purchases' ? 'selected' : ''; ?>>Stock Purchases Only</option>
                        </select>
                    </div>
                    <div class="col-md-3 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary">Generate Report</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Summary Cards -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card summary-card bg-primary text-white">
                    <div class="card-body">
                        <h6 class="card-title">Operational Expenses</h6>
                        <h3>₹<?php echo number_format($operational_total, 2); ?></h3>
                        <small>Business use items</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card summary-card bg-success text-white">
                    <div class="card-body">
                        <h6 class="card-title">Stock Purchases</h6>
                        <h3>₹<?php echo number_format($purchase_total, 2); ?></h3>
                        <small>Inventory items</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card summary-card bg-warning text-white">
                    <div class="card-body">
                        <h6 class="card-title">Grand Total</h6>
                        <h3>₹<?php echo number_format($grand_total, 2); ?></h3>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card summary-card bg-info text-white">
                    <div class="card-body">
                        <h6 class="card-title">vs Previous Period</h6>
                        <h3 class="<?php echo $percent_change > 0 ? 'trend-up' : 'trend-down'; ?>">
                            <?php echo $percent_change > 0 ? '+' : ''; ?><?php echo $percent_change; ?>%
                        </h3>
                        <small>Change from previous period</small>
                    </div>
                </div>
            </div>
        </div>

        <!-- Category Summary -->
        <div class="row mb-4">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header bg-secondary text-white">
                        <h6 class="mb-0">Category-wise Summary</h6>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Category</th>
                                        <th class="text-end">Count</th>
                                        <th class="text-end">Total (₹)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $cat_total = 0;
                                    if ($category_summary && mysqli_num_rows($category_summary) > 0):
                                        while($cat = mysqli_fetch_assoc($category_summary)):
                                            $cat_total += $cat['total'];
                                    ?>
                                    <tr>
                                        <td><?php echo $cat['category']; ?></td>
                                        <td class="text-end"><?php echo $cat['count']; ?></td>
                                        <td class="text-end"><strong>₹<?php echo number_format($cat['total'], 2); ?></strong></td>
                                    </tr>
                                    <?php endwhile; ?>
                                    <tr class="table-info">
                                        <th>Total</th>
                                        <th class="text-end"><?php echo array_sum(array_column(mysqli_fetch_all($category_summary, MYSQLI_ASSOC), 'count')); ?></th>
                                        <th class="text-end">₹<?php echo number_format($cat_total, 2); ?></th>
                                    </tr>
                                    <?php else: ?>
                                    <tr>
                                        <td colspan="3" class="text-center">No data available</td>
                                    </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Daily Summary -->
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header bg-secondary text-white">
                        <h6 class="mb-0">Daily Summary</h6>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th class="text-end">Entries</th>
                                        <th class="text-end">Total (₹)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $daily_total = 0;
                                    if ($daily_summary && mysqli_num_rows($daily_summary) > 0):
                                        while($day = mysqli_fetch_assoc($daily_summary)):
                                            $daily_total += $day['total'];
                                    ?>
                                    <tr>
                                        <td><?php echo date('d-m-Y', strtotime($day['date'])); ?></td>
                                        <td class="text-end"><?php echo $day['count']; ?></td>
                                        <td class="text-end"><strong>₹<?php echo number_format($day['total'], 2); ?></strong></td>
                                    </tr>
                                    <?php endwhile; ?>
                                    <tr class="table-info">
                                        <th>Total</th>
                                        <th class="text-end"><?php echo array_sum(array_column(mysqli_fetch_all($daily_summary, MYSQLI_ASSOC), 'count')); ?></th>
                                        <th class="text-end">₹<?php echo number_format($daily_total, 2); ?></th>
                                    </tr>
                                    <?php else: ?>
                                    <tr>
                                        <td colspan="3" class="text-center">No data available</td>
                                    </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Detailed Expenses Table -->
        <div class="card">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0"><i class="bi bi-list"></i> Detailed Expense Entries</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-bordered table-hover">
                        <thead class="table-dark">
                            <tr>
                                <th>Date</th>
                                <th>Type</th>
                                <th>Category</th>
                                <th>Description</th>
                                <th class="text-end">Quantity</th>
                                <th class="text-end">Unit Price</th>
                                <th class="text-end">Total Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if(mysqli_num_rows($expenses) > 0): ?>
                                <?php 
                                $running_total = 0;
                                while($expense = mysqli_fetch_assoc($expenses)): 
                                    $running_total += $expense['total_amount'];
                                ?>
                                <tr>
                                    <td><?php echo date('d-m-Y', strtotime($expense['date'])); ?></td>
                                    <td>
                                        <span class="badge <?php echo $expense['expense_type'] == 'Operational' ? 'bg-warning' : 'bg-info'; ?>">
                                            <?php echo $expense['expense_type']; ?>
                                        </span>
                                    </td>
                                    <td><?php echo $expense['category']; ?></td>
                                    <td><?php echo htmlspecialchars($expense['description']); ?></td>
                                    <td class="text-end"><?php echo $expense['quantity'] ?? 1; ?></td>
                                    <td class="text-end">₹<?php echo number_format($expense['unit_price'] ?? $expense['total_amount'], 2); ?></td>
                                    <td class="text-end"><strong>₹<?php echo number_format($expense['total_amount'], 2); ?></strong></td>
                                </tr>
                                <?php endwhile; ?>
                                <tr class="table-info">
                                    <th colspan="6" class="text-end">Grand Total:</th>
                                    <th class="text-end">₹<?php echo number_format($running_total, 2); ?></th>
                                </tr>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" class="text-center py-4">
                                        <i class="bi bi-inbox fs-1 d-block text-muted"></i>
                                        <p class="text-muted mt-2">No expenses found for the selected period</p>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Print Footer -->
        <div class="print-only mt-4">
            <div class="row">
                <div class="col-6">
                    <p><strong>Generated By:</strong> <?php echo $_SESSION['username']; ?></p>
                </div>
                <div class="col-6 text-end">
                    <p><strong>Generated On:</strong> <?php echo date('d-m-Y H:i:s'); ?></p>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>