<?php
require_once 'config.php';

if (!isLoggedIn()) {
    redirect('index.php');
}

// Handle manual transactions (expenses only now)
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['add_expense'])) {
        $date = $_POST['date'];
        $amount = floatval($_POST['amount']);
        $description = mysqli_real_escape_string($conn, $_POST['description']);
        $category = mysqli_real_escape_string($conn, $_POST['category']);
        $payment_type = mysqli_real_escape_string($conn, $_POST['payment_type']); // cash or bank
        $created_by = $_SESSION['user_id'];
        
        $query = "INSERT INTO daily_expenses (date, amount, description, category, payment_type, created_by) 
                  VALUES ('$date', $amount, '$description', '$category', '$payment_type', $created_by)";
        
        if (mysqli_query($conn, $query)) {
            $_SESSION['success'] = "Expense added successfully!";
        } else {
            $_SESSION['error'] = "Error: " . mysqli_error($conn);
        }
        redirect('simple_accounting.php');
    }
    
    if (isset($_POST['delete_expense'])) {
        $id = $_POST['id'];
        mysqli_query($conn, "DELETE FROM daily_expenses WHERE id = $id");
        $_SESSION['success'] = "Expense deleted!";
        redirect('simple_accounting.php');
    }
    
    if (isset($_POST['update_balances'])) {
        $cash_balance = floatval($_POST['cash_balance']);
        $bank_balance = floatval($_POST['bank_balance']);
        
        // Store in settings table
        mysqli_query($conn, "INSERT INTO system_settings (setting_key, setting_value) 
                            VALUES ('cash_balance', '$cash_balance')
                            ON DUPLICATE KEY UPDATE setting_value = '$cash_balance'");
        
        mysqli_query($conn, "INSERT INTO system_settings (setting_key, setting_value) 
                            VALUES ('bank_balance', '$bank_balance')
                            ON DUPLICATE KEY UPDATE setting_value = '$bank_balance'");
        
        $_SESSION['success'] = "Balances updated successfully!";
        redirect('simple_accounting.php');
    }
}

// Get current cash and bank balances
$cash_balance = mysqli_fetch_assoc(mysqli_query($conn, 
    "SELECT setting_value FROM system_settings WHERE setting_key = 'cash_balance'"))['setting_value'] ?? 0;
$bank_balance = mysqli_fetch_assoc(mysqli_query($conn, 
    "SELECT setting_value FROM system_settings WHERE setting_key = 'bank_balance'"))['setting_value'] ?? 0;

// Get selected date or default to today
$selected_date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
$prev_date = date('Y-m-d', strtotime($selected_date . ' -1 day'));
$next_date = date('Y-m-d', strtotime($selected_date . ' +1 day'));

// ============ AUTO FETCH PURCHASE DATA ============
$purchases_query = "
    SELECT 
        DATE(pur.purchase_date) as date,
        COUNT(DISTINCT pur.id) as total_invoices,
        SUM(pi.quantity) as total_items,
        SUM(pi.quantity * pi.purchase_price) as total_amount,
        COUNT(DISTINCT pur.supplier_id) as supplier_count
    FROM purchases pur
    JOIN purchase_items pi ON pur.id = pi.purchase_id
    WHERE DATE(pur.purchase_date) = '$selected_date'
    GROUP BY DATE(pur.purchase_date)";

$purchases_result = mysqli_query($conn, $purchases_query);
$purchases_data = mysqli_fetch_assoc($purchases_result);

// ============ AUTO FETCH SALES DATA ============
$sales_query = "
    SELECT 
        DATE(sale_date) as date,
        COUNT(*) as total_invoices,
        SUM(total_amount) as total_amount,
        SUM(paid_amount) as total_paid,
        SUM(CASE 
            WHEN payment_method = 'cash' THEN paid_amount 
            ELSE 0 
        END) as cash_paid,
        SUM(CASE 
            WHEN payment_method IN ('card', 'online', 'bank_transfer') THEN paid_amount 
            ELSE 0 
        END) as bank_paid,
        SUM(CASE 
            WHEN grand_total IS NOT NULL AND grand_total > 0 
            THEN grand_total - paid_amount
            ELSE total_amount - paid_amount
        END) as total_due,
        COUNT(DISTINCT customer_id) as customer_count
    FROM sales
    WHERE DATE(sale_date) = '$selected_date'
    GROUP BY DATE(sale_date)";

$sales_result = mysqli_query($conn, $sales_query);
$sales_data = mysqli_fetch_assoc($sales_result);

// ============ GET MANUAL EXPENSES ============
$expenses_query = "
    SELECT 
        DATE(date) as expense_date,
        SUM(amount) as total_expense,
        SUM(CASE WHEN payment_type = 'cash' THEN amount ELSE 0 END) as cash_expense,
        SUM(CASE WHEN payment_type = 'bank' THEN amount ELSE 0 END) as bank_expense,
        COUNT(*) as expense_count
    FROM daily_expenses
    WHERE DATE(date) = '$selected_date'
    GROUP BY DATE(date)";

$expenses_result = mysqli_query($conn, $expenses_query);
$expenses_data = mysqli_fetch_assoc($expenses_result);

// ============ GET DETAILED EXPENSES FOR DISPLAY ============
$expenses_details = mysqli_query($conn, "
    SELECT e.*, u.username 
    FROM daily_expenses e
    LEFT JOIN users u ON e.created_by = u.id
    WHERE DATE(e.date) = '$selected_date'
    ORDER BY e.created_at DESC");

// ============ CALCULATE DAILY BALANCE ============
$daily_purchases = $purchases_data['total_amount'] ?? 0;
$daily_sales = $sales_data['total_amount'] ?? 0;
$daily_cash_sales = $sales_data['cash_paid'] ?? 0;
$daily_bank_sales = $sales_data['bank_paid'] ?? 0;
$daily_expenses = $expenses_data['total_expense'] ?? 0;
$daily_cash_expenses = $expenses_data['cash_expense'] ?? 0;
$daily_bank_expenses = $expenses_data['bank_expense'] ?? 0;

// Net daily balance (Sales - Purchases - Expenses)
$daily_net_balance = $daily_sales - $daily_purchases - $daily_expenses;

// Daily cash flow
$daily_cash_flow = $daily_cash_sales - $daily_cash_expenses;
$daily_bank_flow = $daily_bank_sales - $daily_bank_expenses;

// ============ GET OPENING BALANCE (Previous day's closing) ============
$opening_balance_query = "
    SELECT 
        COALESCE((
            SELECT SUM(total_amount) FROM sales WHERE DATE(sale_date) < '$selected_date'
        ), 0) as total_sales_before,
        COALESCE((
            SELECT SUM(pi.quantity * pi.purchase_price) 
            FROM purchases pur
            JOIN purchase_items pi ON pur.id = pi.purchase_id
            WHERE DATE(pur.purchase_date) < '$selected_date'
        ), 0) as total_purchases_before,
        COALESCE((
            SELECT SUM(amount) FROM daily_expenses WHERE DATE(date) < '$selected_date'
        ), 0) as total_expenses_before";

$opening_result = mysqli_query($conn, $opening_balance_query);
$opening_data = mysqli_fetch_assoc($opening_result);

$opening_balance = ($opening_data['total_sales_before'] ?? 0) - 
                   ($opening_data['total_purchases_before'] ?? 0) - 
                   ($opening_data['total_expenses_before'] ?? 0);

// Closing balance = Opening + Today's net
$closing_balance = $opening_balance + $daily_net_balance;

// Calculate opening cash and bank (from previous day's closing)
$opening_cash = $cash_balance;
$opening_bank = $bank_balance;

// Closing cash and bank
$closing_cash = $opening_cash + $daily_cash_flow;
$closing_bank = $opening_bank + $daily_bank_flow;

// ============ GET MONTHLY SUMMARY ============
$month_start = date('Y-m-01', strtotime($selected_date));
$month_end = date('Y-m-t', strtotime($selected_date));

$monthly_summary = mysqli_query($conn, "
    SELECT 
        COALESCE((
            SELECT SUM(total_amount) FROM sales 
            WHERE DATE(sale_date) BETWEEN '$month_start' AND '$month_end'
        ), 0) as monthly_sales,
        COALESCE((
            SELECT SUM(pi.quantity * pi.purchase_price) 
            FROM purchases pur
            JOIN purchase_items pi ON pur.id = pi.purchase_id
            WHERE DATE(pur.purchase_date) BETWEEN '$month_start' AND '$month_end'
        ), 0) as monthly_purchases,
        COALESCE((
            SELECT SUM(amount) FROM daily_expenses 
            WHERE DATE(date) BETWEEN '$month_start' AND '$month_end'
        ), 0) as monthly_expenses");

$monthly = mysqli_fetch_assoc($monthly_summary);
$monthly_net = ($monthly['monthly_sales'] ?? 0) - 
               ($monthly['monthly_purchases'] ?? 0) - 
               ($monthly['monthly_expenses'] ?? 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Simple Accounting - Bike Management System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <style>
        body {
            background: #f8f9fa;
            font-size: 0.9rem;
        }
        
        /* Date Navigator */
        .date-navigator {
            background: white;
            border-radius: 10px;
            padding: 12px 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }
        .date-navigator h3 {
            font-size: 1.3rem;
            margin: 0;
        }
        
        /* Balance Cards */
        .balance-card {
            border-radius: 12px;
            padding: 15px;
            color: white;
            margin-bottom: 15px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        .balance-card .label {
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            opacity: 0.9;
        }
        .balance-card .amount {
            font-size: 1.8rem;
            font-weight: bold;
            line-height: 1.2;
        }
        .balance-card .sub-text {
            font-size: 0.7rem;
            opacity: 0.8;
        }
        .opening-card {
            background: linear-gradient(135deg, #6c757d, #495057);
        }
        .closing-card {
            background: linear-gradient(135deg, #28a745, #20c997);
        }
        .cash-card {
            background: linear-gradient(135deg, #17a2b8, #0dcaf0);
        }
        .bank-card {
            background: linear-gradient(135deg, #6610f2, #6f42c1);
        }
        .net-card-positive {
            background: linear-gradient(135deg, #28a745, #20c997);
        }
        .net-card-negative {
            background: linear-gradient(135deg, #dc3545, #fd7e14);
        }
        
        /* Stats Cards */
        .stats-card {
            background: white;
            border-radius: 10px;
            padding: 12px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.03);
            border-left: 3px solid;
            transition: transform 0.2s;
            height: 100%;
        }
        .stats-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 10px rgba(0,0,0,0.08);
        }
        .stats-card .title {
            font-size: 0.7rem;
            text-transform: uppercase;
            color: #6c757d;
            margin-bottom: 4px;
        }
        .stats-card .value {
            font-size: 1.3rem;
            font-weight: 600;
        }
        .stats-card .sub {
            font-size: 0.65rem;
            color: #6c757d;
        }
        
        /* Section Headers */
        .section-header {
            background: white;
            padding: 8px 12px;
            border-radius: 8px;
            margin-bottom: 10px;
            font-weight: 600;
            font-size: 0.9rem;
            border-left: 4px solid #007bff;
        }
        
        /* Table */
        .table {
            font-size: 0.8rem;
        }
        .table th {
            background: #f8f9fa;
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        .badge {
            font-size: 0.65rem;
            padding: 3px 6px;
        }
        .btn-sm {
            font-size: 0.7rem;
            padding: 0.2rem 0.5rem;
        }
        
        /* Expense Form */
        .expense-form {
            background: white;
            border-radius: 10px;
            padding: 15px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.03);
        }
        .expense-form .form-label {
            font-size: 0.7rem;
            font-weight: 600;
            margin-bottom: 2px;
        }
        .expense-form .form-control,
        .expense-form .form-select {
            font-size: 0.8rem;
            padding: 0.25rem 0.5rem;
            height: auto;
        }
        
        /* Summary Cards */
        .summary-card {
            background: white;
            border-radius: 8px;
            padding: 10px 12px;
            margin-bottom: 10px;
            border: 1px solid #eee;
        }
        .summary-card .label {
            font-size: 0.65rem;
            color: #6c757d;
        }
        .summary-card .value {
            font-size: 1rem;
            font-weight: 600;
        }
        
        /* Balance Update Form */
        .balance-update-form {
            background: white;
            border-radius: 10px;
            padding: 15px;
            margin-top: 15px;
        }
        
        /* Print styles */
        @media print {
            .no-print, .navbar, .expense-form, .btn, .date-navigator .btn,
            .balance-update-form {
                display: none !important;
            }
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark py-2 no-print">
        <div class="container-fluid">
            <a class="navbar-brand fs-6" href="dashboard.php">
                <i class="bi bi-bicycle"></i> PRAVEEN SERVICE CENTER
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item">
                        <span class="nav-link text-white py-1">
                            <i class="bi bi-person-circle"></i> <?php echo $_SESSION['username']; ?>
                        </span>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link py-1" href="dashboard.php">
                            <i class="bi bi-speedometer2"></i> Dashboard
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link py-1" href="logout.php">
                            <i class="bi bi-box-arrow-right"></i> Logout
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container-fluid mt-3">
        <!-- Messages -->
        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show py-2 no-print">
                <i class="bi bi-check-circle"></i> <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
                <button type="button" class="btn-close py-2" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show py-2 no-print">
                <i class="bi bi-exclamation-triangle"></i> <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
                <button type="button" class="btn-close py-2" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Date Navigator -->
        <div class="date-navigator d-flex justify-content-between align-items-center no-print">
            <a href="?date=<?php echo $prev_date; ?>" class="btn btn-sm btn-outline-primary">
                <i class="bi bi-chevron-left"></i> Previous Day
            </a>
            <h3 class="text-center">
                <i class="bi bi-calendar3"></i> 
                <?php echo date('d F Y', strtotime($selected_date)); ?>
                <?php if($selected_date == date('Y-m-d')): ?>
                    <span class="badge bg-success ms-2">Today</span>
                <?php endif; ?>
            </h3>
            <a href="?date=<?php echo $next_date; ?>" class="btn btn-sm btn-outline-primary">
                Next Day <i class="bi bi-chevron-right"></i>
            </a>
        </div>

        <!-- Opening & Closing Balance Row -->
        <div class="row mb-3">
            <div class="col-md-3">
                <div class="balance-card opening-card">
                    <div class="label">Opening Balance</div>
                    <div class="amount">₹<?php echo number_format($opening_balance, 2); ?></div>
                    <div class="sub-text">Total as of 00:00</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="balance-card closing-card">
                    <div class="label">Closing Balance</div>
                    <div class="amount">₹<?php echo number_format($closing_balance, 2); ?></div>
                    <div class="sub-text">Total as of 23:59</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="balance-card cash-card">
                    <div class="label">Cash in Hand</div>
                    <div class="amount">₹<?php echo number_format($closing_cash, 2); ?></div>
                    <div class="sub-text">Opening: ₹<?php echo number_format($opening_cash, 2); ?></div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="balance-card bank-card">
                    <div class="label">Bank Balance</div>
                    <div class="amount">₹<?php echo number_format($closing_bank, 2); ?></div>
                    <div class="sub-text">Opening: ₹<?php echo number_format($opening_bank, 2); ?></div>
                </div>
            </div>
        </div>

        <!-- Daily Summary Stats -->
        <div class="row mb-3">
            <div class="col-md-4">
                <div class="stats-card" style="border-left-color: #28a745;">
                    <div class="d-flex justify-content-between">
                        <div>
                            <div class="title">Sales Today</div>
                            <div class="value text-success">₹<?php echo number_format($daily_sales, 2); ?></div>
                        </div>
                        <i class="bi bi-cart-check fs-2 text-success opacity-25"></i>
                    </div>
                    <div class="sub mt-1">
                        <i class="bi bi-receipt"></i> <?php echo $sales_data['total_invoices'] ?? 0; ?> invoices | 
                        <i class="bi bi-people"></i> <?php echo $sales_data['customer_count'] ?? 0; ?> customers
                    </div>
                    <div class="row mt-1">
                        <div class="col-6">
                            <small>Cash: <span class="text-success">₹<?php echo number_format($daily_cash_sales, 2); ?></span></small>
                        </div>
                        <div class="col-6">
                            <small>Bank: <span class="text-primary">₹<?php echo number_format($daily_bank_sales, 2); ?></span></small>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stats-card" style="border-left-color: #dc3545;">
                    <div class="d-flex justify-content-between">
                        <div>
                            <div class="title">Purchases Today</div>
                            <div class="value text-danger">₹<?php echo number_format($daily_purchases, 2); ?></div>
                        </div>
                        <i class="bi bi-truck fs-2 text-danger opacity-25"></i>
                    </div>
                    <div class="sub mt-1">
                        <i class="bi bi-receipt"></i> <?php echo $purchases_data['total_invoices'] ?? 0; ?> invoices | 
                        <i class="bi bi-box"></i> <?php echo $purchases_data['total_items'] ?? 0; ?> items
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stats-card" style="border-left-color: #ffc107;">
                    <div class="d-flex justify-content-between">
                        <div>
                            <div class="title">Expenses Today</div>
                            <div class="value text-warning">₹<?php echo number_format($daily_expenses, 2); ?></div>
                        </div>
                        <i class="bi bi-wallet2 fs-2 text-warning opacity-25"></i>
                    </div>
                    <div class="sub mt-1">
                        <i class="bi bi-list"></i> <?php echo $expenses_data['expense_count'] ?? 0; ?> transactions
                    </div>
                    <div class="row mt-1">
                        <div class="col-6">
                            <small>Cash: <span class="text-warning">₹<?php echo number_format($daily_cash_expenses, 2); ?></span></small>
                        </div>
                        <div class="col-6">
                            <small>Bank: <span class="text-primary">₹<?php echo number_format($daily_bank_expenses, 2); ?></span></small>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Daily Cash/Bank Flow -->
        <div class="row mb-3">
            <div class="col-md-6">
                <div class="stats-card" style="border-left-color: #17a2b8;">
                    <div class="title">Cash Flow Today</div>
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <span class="value text-info">₹<?php echo number_format(abs($daily_cash_flow), 2); ?></span>
                            <span class="badge <?php echo $daily_cash_flow >= 0 ? 'bg-success' : 'bg-danger'; ?> ms-2">
                                <?php echo $daily_cash_flow >= 0 ? 'Inflow' : 'Outflow'; ?>
                            </span>
                        </div>
                        <i class="bi bi-cash-stack fs-2 text-info opacity-25"></i>
                    </div>
                    <div class="row mt-2">
                        <div class="col-6">
                            <small>In (Sales): +₹<?php echo number_format($daily_cash_sales, 2); ?></small>
                        </div>
                        <div class="col-6">
                            <small>Out (Expense): -₹<?php echo number_format($daily_cash_expenses, 2); ?></small>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="stats-card" style="border-left-color: #6610f2;">
                    <div class="title">Bank Flow Today</div>
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <span class="value text-primary">₹<?php echo number_format(abs($daily_bank_flow), 2); ?></span>
                            <span class="badge <?php echo $daily_bank_flow >= 0 ? 'bg-success' : 'bg-danger'; ?> ms-2">
                                <?php echo $daily_bank_flow >= 0 ? 'Inflow' : 'Outflow'; ?>
                            </span>
                        </div>
                        <i class="bi bi-bank2 fs-2 text-primary opacity-25"></i>
                    </div>
                    <div class="row mt-2">
                        <div class="col-6">
                            <small>In (Sales): +₹<?php echo number_format($daily_bank_sales, 2); ?></small>
                        </div>
                        <div class="col-6">
                            <small>Out (Expense): -₹<?php echo number_format($daily_bank_expenses, 2); ?></small>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Add Expense Form -->
        <div class="row no-print">
            <div class="col-12">
                <div class="expense-form mb-3">
                    <div class="section-header">
                        <i class="bi bi-plus-circle"></i> Add Daily Expense
                    </div>
                    <form method="POST" class="row g-2">
                        <input type="hidden" name="date" value="<?php echo $selected_date; ?>">
                        <div class="col-md-2">
                            <label class="form-label">Amount (₹)</label>
                            <input type="number" step="0.01" class="form-control" name="amount" required placeholder="0.00">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Payment Type</label>
                            <select class="form-select" name="payment_type" required>
                                <option value="cash">Cash</option>
                                <option value="bank">Bank</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Category</label>
                            <select class="form-select" name="category" required>
                                <option value="">Select Category</option>
                                <option value="rent">Rent</option>
                                <option value="electricity">Electricity</option>
                                <option value="salary">Salary</option>
                                <option value="maintenance">Maintenance</option>
                                <option value="transport">Transport</option>
                                <option value="staff">Staff Expenses</option>
                                <option value="office">Office Supplies</option>
                                <option value="marketing">Marketing</option>
                                <option value="tax">Tax & Fees</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Description</label>
                            <input type="text" class="form-control" name="description" required placeholder="e.g., Electricity bill...">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">&nbsp;</label>
                            <button type="submit" name="add_expense" class="btn btn-warning w-100">
                                <i class="bi bi-plus"></i> Add Expense
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Expenses Details -->
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header bg-dark text-white py-2">
                        <h6 class="mb-0"><i class="bi bi-list-ul"></i> Daily Expenses Details</h6>
                    </div>
                    <div class="card-body p-2">
                        <div class="table-responsive">
                            <table class="table table-sm table-striped mb-0">
                                <thead>
                                    <tr>
                                        <th>Time</th>
                                        <th>Payment</th>
                                        <th>Category</th>
                                        <th>Description</th>
                                        <th>Amount</th>
                                        <th>Added By</th>
                                        <th class="no-print">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if(mysqli_num_rows($expenses_details) > 0): ?>
                                        <?php while($expense = mysqli_fetch_assoc($expenses_details)): ?>
                                        <tr>
                                            <td><?php echo date('h:i A', strtotime($expense['created_at'])); ?></td>
                                            <td>
                                                <span class="badge <?php echo $expense['payment_type'] == 'cash' ? 'bg-info' : 'bg-primary'; ?>">
                                                    <?php echo ucfirst($expense['payment_type']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="badge bg-secondary"><?php echo ucfirst($expense['category']); ?></span>
                                            </td>
                                            <td><?php echo htmlspecialchars($expense['description']); ?></td>
                                            <td class="text-danger fw-bold">₹<?php echo number_format($expense['amount'], 2); ?></td>
                                            <td><?php echo htmlspecialchars($expense['username']); ?></td>
                                            <td class="no-print">
                                                <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this expense?')">
                                                    <input type="hidden" name="id" value="<?php echo $expense['id']; ?>">
                                                    <button type="submit" name="delete_expense" class="btn btn-sm btn-danger">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="7" class="text-center py-3 text-muted">
                                                <i class="bi bi-info-circle"></i> No expenses recorded for this day
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                                <?php if(mysqli_num_rows($expenses_details) > 0): ?>
                                <tfoot class="table-secondary">
                                    <tr>
                                        <th colspan="4" class="text-end">Total Expenses:</th>
                                        <th class="text-danger">₹<?php echo number_format($daily_expenses, 2); ?></th>
                                        <th colspan="2"></th>
                                    </tr>
                                    <tr>
                                        <th colspan="4" class="text-end">Cash Expenses:</th>
                                        <th class="text-info">₹<?php echo number_format($daily_cash_expenses, 2); ?></th>
                                        <th colspan="2"></th>
                                    </tr>
                                    <tr>
                                        <th colspan="4" class="text-end">Bank Expenses:</th>
                                        <th class="text-primary">₹<?php echo number_format($daily_bank_expenses, 2); ?></th>
                                        <th colspan="2"></th>
                                    </tr>
                                </tfoot>
                                <?php endif; ?>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Summary Cards Row -->
        <div class="row mt-3">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header bg-success text-white py-2">
                        <h6 class="mb-0"><i class="bi bi-cart-check"></i> Today's Sales Summary</h6>
                    </div>
                    <div class="card-body p-2">
                        <div class="row g-2">
                            <div class="col-6">
                                <div class="summary-card">
                                    <div class="label">Total Invoices</div>
                                    <div class="value"><?php echo $sales_data['total_invoices'] ?? 0; ?></div>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="summary-card">
                                    <div class="label">Total Amount</div>
                                    <div class="value text-success">₹<?php echo number_format($daily_sales, 2); ?></div>
                                </div>
                            </div>
                            <div class="col-4">
                                <div class="summary-card">
                                    <div class="label">Cash Received</div>
                                    <div class="value text-info">₹<?php echo number_format($daily_cash_sales, 2); ?></div>
                                </div>
                            </div>
                            <div class="col-4">
                                <div class="summary-card">
                                    <div class="label">Bank Received</div>
                                    <div class="value text-primary">₹<?php echo number_format($daily_bank_sales, 2); ?></div>
                                </div>
                            </div>
                            <div class="col-4">
                                <div class="summary-card">
                                    <div class="label">Pending Due</div>
                                    <div class="value text-warning">₹<?php echo number_format($sales_data['total_due'] ?? 0, 2); ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header bg-danger text-white py-2">
                        <h6 class="mb-0"><i class="bi bi-truck"></i> Today's Purchases Summary</h6>
                    </div>
                    <div class="card-body p-2">
                        <div class="row g-2">
                            <div class="col-6">
                                <div class="summary-card">
                                    <div class="label">Purchase Invoices</div>
                                    <div class="value"><?php echo $purchases_data['total_invoices'] ?? 0; ?></div>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="summary-card">
                                    <div class="label">Total Items</div>
                                    <div class="value"><?php echo $purchases_data['total_items'] ?? 0; ?></div>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="summary-card">
                                    <div class="label">Total Amount</div>
                                    <div class="value text-danger">₹<?php echo number_format($daily_purchases, 2); ?></div>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="summary-card">
                                    <div class="label">Suppliers</div>
                                    <div class="value"><?php echo $purchases_data['supplier_count'] ?? 0; ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Balance Update Form -->
        <div class="row mt-3 no-print">
            <div class="col-12">
                <div class="balance-update-form">
                    <div class="section-header">
                        <i class="bi bi-pencil-square"></i> Update Opening Balances
                    </div>
                    <form method="POST" class="row g-2">
                        <div class="col-md-5">
                            <label class="form-label">Cash in Hand (₹)</label>
                            <input type="number" step="0.01" class="form-control" name="cash_balance" value="<?php echo $cash_balance; ?>" required>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label">Bank Balance (₹)</label>
                            <input type="number" step="0.01" class="form-control" name="bank_balance" value="<?php echo $bank_balance; ?>" required>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">&nbsp;</label>
                            <button type="submit" name="update_balances" class="btn btn-primary w-100">
                                <i class="bi bi-save"></i> Update
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Print Button -->
        <div class="row mt-3 no-print">
            <div class="col-12 text-end">
                <button onclick="window.print()" class="btn btn-sm btn-secondary">
                    <i class="bi bi-printer"></i> Print Statement
                </button>
                <a href="?date=<?php echo date('Y-m-d'); ?>" class="btn btn-sm btn-primary">
                    <i class="bi bi-calendar-today"></i> Today
                </a>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>