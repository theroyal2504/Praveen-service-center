<?php
require_once 'config.php';

if (!isLoggedIn()) {
    redirect('index.php');
}

// Get selected date (default today)
$selected_date = $_GET['date'] ?? date('Y-m-d');
$view_type = $_GET['view'] ?? 'daily'; // daily, weekly, monthly

// Handle adding transaction
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_transaction'])) {
    $date = $_POST['date'];
    $type = $_POST['type'];
    $amount = floatval($_POST['amount']);
    $description = mysqli_real_escape_string($conn, $_POST['description']);
    $category = mysqli_real_escape_string($conn, $_POST['category'] ?? '');
    $payment_method = $_POST['payment_method'] ?? 'cash';
    $created_by = $_SESSION['user_id'];
    
    // Start transaction
    mysqli_begin_transaction($conn);
    
    try {
        // Insert transaction
        $query = "INSERT INTO daily_transactions (transaction_date, type, amount, description, category, payment_method, created_by) 
                  VALUES ('$date', '$type', $amount, '$description', '$category', '$payment_method', $created_by)";
        mysqli_query($conn, $query);
        
        // Update or create daily balance
        $check_balance = mysqli_query($conn, "SELECT * FROM daily_balance WHERE balance_date = '$date'");
        
        if (mysqli_num_rows($check_balance) > 0) {
            $balance = mysqli_fetch_assoc($check_balance);
            
            if ($type == 'income') {
                $update = "UPDATE daily_balance SET 
                          total_income = total_income + $amount,
                          closing_balance = closing_balance + $amount
                          WHERE balance_date = '$date'";
            } else {
                $update = "UPDATE daily_balance SET 
                          total_expense = total_expense + $amount,
                          closing_balance = closing_balance - $amount
                          WHERE balance_date = '$date'";
            }
            mysqli_query($conn, $update);
        } else {
            // Get previous day's closing balance
            $prev_date = date('Y-m-d', strtotime($date . ' -1 day'));
            $prev_balance = mysqli_fetch_assoc(mysqli_query($conn, 
                "SELECT closing_balance FROM daily_balance WHERE balance_date = '$prev_date'"))['closing_balance'] ?? 0;
            
            $opening = $prev_balance;
            $income = ($type == 'income') ? $amount : 0;
            $expense = ($type == 'expense') ? $amount : 0;
            $closing = $opening + $income - $expense;
            
            $insert = "INSERT INTO daily_balance (balance_date, opening_balance, total_income, total_expense, closing_balance) 
                      VALUES ('$date', $opening, $income, $expense, $closing)";
            mysqli_query($conn, $insert);
        }
        
        mysqli_commit($conn);
        $_SESSION['success'] = "Transaction added successfully!";
        
    } catch (Exception $e) {
        mysqli_rollback($conn);
        $_SESSION['error'] = "Error: " . $e->getMessage();
    }
    
    redirect("accounting.php?date=$date&view=$view_type");
}

// Handle delete transaction
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_transaction'])) {
    $id = $_POST['id'];
    $date = $_POST['date'];
    
    mysqli_begin_transaction($conn);
    
    try {
        // Get transaction details
        $trans = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM daily_transactions WHERE id = $id"));
        
        // Delete transaction
        mysqli_query($conn, "DELETE FROM daily_transactions WHERE id = $id");
        
        // Update daily balance
        if ($trans['type'] == 'income') {
            $update = "UPDATE daily_balance SET 
                      total_income = total_income - {$trans['amount']},
                      closing_balance = closing_balance - {$trans['amount']}
                      WHERE balance_date = '{$trans['transaction_date']}'";
        } else {
            $update = "UPDATE daily_balance SET 
                      total_expense = total_expense - {$trans['amount']},
                      closing_balance = closing_balance + {$trans['amount']}
                      WHERE balance_date = '{$trans['transaction_date']}'";
        }
        mysqli_query($conn, $update);
        
        mysqli_commit($conn);
        $_SESSION['success'] = "Transaction deleted!";
        
    } catch (Exception $e) {
        mysqli_rollback($conn);
        $_SESSION['error'] = "Error: " . $e->getMessage();
    }
    
    redirect("accounting.php?date=$date&view=$view_type");
}

// Get data based on view type
if ($view_type == 'daily') {
    // Get today's transactions
    $transactions = mysqli_query($conn, 
        "SELECT t.*, u.username FROM daily_transactions t
         LEFT JOIN users u ON t.created_by = u.id
         WHERE t.transaction_date = '$selected_date'
         ORDER BY t.created_at DESC");
    
    // Get today's balance
    $balance = mysqli_fetch_assoc(mysqli_query($conn, 
        "SELECT * FROM daily_balance WHERE balance_date = '$selected_date'"));
    
} elseif ($view_type == 'weekly') {
    $start_of_week = date('Y-m-d', strtotime('monday this week', strtotime($selected_date)));
    $end_of_week = date('Y-m-d', strtotime('sunday this week', strtotime($selected_date)));
    
    // Get weekly summary
    $weekly_data = mysqli_query($conn, 
        "SELECT 
            SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END) as total_income,
            SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END) as total_expense,
            COUNT(*) as transaction_count
         FROM daily_transactions 
         WHERE transaction_date BETWEEN '$start_of_week' AND '$end_of_week'");
    
    // Get daily breakdown for the week
    $daily_breakdown = mysqli_query($conn,
        "SELECT 
            transaction_date,
            SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END) as day_income,
            SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END) as day_expense
         FROM daily_transactions 
         WHERE transaction_date BETWEEN '$start_of_week' AND '$end_of_week'
         GROUP BY transaction_date
         ORDER BY transaction_date");
    
} else { // monthly
    $year = date('Y', strtotime($selected_date));
    $month = date('m', strtotime($selected_date));
    $start_of_month = "$year-$month-01";
    $end_of_month = date('Y-m-t', strtotime($selected_date));
    
    // Get monthly summary
    $monthly_data = mysqli_query($conn, 
        "SELECT 
            SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END) as total_income,
            SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END) as total_expense,
            COUNT(*) as transaction_count
         FROM daily_transactions 
         WHERE transaction_date BETWEEN '$start_of_month' AND '$end_of_month'");
    
    // Get weekly breakdown for the month
    $weekly_breakdown = mysqli_query($conn,
        "SELECT 
            YEARWEEK(transaction_date) as week_number,
            MIN(transaction_date) as week_start,
            MAX(transaction_date) as week_end,
            SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END) as week_income,
            SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END) as week_expense
         FROM daily_transactions 
         WHERE transaction_date BETWEEN '$start_of_month' AND '$end_of_month'
         GROUP BY YEARWEEK(transaction_date)
         ORDER BY week_number");
}

// Get monthly stats for the year
$monthly_stats = mysqli_query($conn,
    "SELECT 
        DATE_FORMAT(transaction_date, '%Y-%m') as month,
        SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END) as month_income,
        SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END) as month_expense
     FROM daily_transactions 
     WHERE transaction_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
     GROUP BY DATE_FORMAT(transaction_date, '%Y-%m')
     ORDER BY month DESC");

// Get all daily balances for the last 30 days
$recent_balances = mysqli_query($conn,
    "SELECT * FROM daily_balance 
     WHERE balance_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
     ORDER BY balance_date DESC");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Daily Accounting - Bike Management System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .balance-card {
            border-radius: 15px;
            padding: 20px;
            color: white;
            margin-bottom: 20px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        .balance-amount {
            font-size: 2em;
            font-weight: bold;
        }
        .income-card { background: linear-gradient(135deg, #28a745, #20c997); }
        .expense-card { background: linear-gradient(135deg, #dc3545, #fd7e14); }
        .opening-card { background: linear-gradient(135deg, #17a2b8, #6610f2); }
        .closing-card { background: linear-gradient(135deg, #6610f2, #6f42c1); }
        .stat-value { font-size: 1.8em; font-weight: bold; }
        .stat-label { font-size: 0.9em; opacity: 0.9; }
        .nav-link.active { font-weight: bold; background-color: #007bff; color: white !important; }
        .transaction-row:hover { background-color: #f8f9fa; }
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
                            <i class="bi bi-person-circle"></i> <?php echo $_SESSION['username']; ?>
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
        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Navigation Tabs -->
        <ul class="nav nav-tabs mb-4">
            <li class="nav-item">
                <a class="nav-link <?php echo $view_type == 'daily' ? 'active' : ''; ?>" 
                   href="?view=daily&date=<?php echo $selected_date; ?>">
                    <i class="bi bi-calendar-day"></i> Daily View
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo $view_type == 'weekly' ? 'active' : ''; ?>" 
                   href="?view=weekly&date=<?php echo $selected_date; ?>">
                    <i class="bi bi-calendar-week"></i> Weekly View
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo $view_type == 'monthly' ? 'active' : ''; ?>" 
                   href="?view=monthly&date=<?php echo $selected_date; ?>">
                    <i class="bi bi-calendar-month"></i> Monthly View
                </a>
            </li>
        </ul>

        <!-- Date Selector -->
        <div class="row mb-4">
            <div class="col-md-4">
                <form method="GET" class="d-flex">
                    <input type="hidden" name="view" value="<?php echo $view_type; ?>">
                    <input type="date" class="form-control me-2" name="date" value="<?php echo $selected_date; ?>">
                    <button type="submit" class="btn btn-primary">Go</button>
                </form>
            </div>
        </div>

        <?php if ($view_type == 'daily'): ?>
            <!-- Daily View -->
            <div class="row">
                <div class="col-md-3">
                    <div class="balance-card opening-card">
                        <div class="stat-label">Opening Balance</div>
                        <div class="stat-value">₹<?php echo number_format($balance['opening_balance'] ?? 0, 2); ?></div>
                        <small>Start of day</small>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="balance-card income-card">
                        <div class="stat-label">Today's Income</div>
                        <div class="stat-value">₹<?php echo number_format($balance['total_income'] ?? 0, 2); ?></div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="balance-card expense-card">
                        <div class="stat-label">Today's Expense</div>
                        <div class="stat-value">₹<?php echo number_format($balance['total_expense'] ?? 0, 2); ?></div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="balance-card closing-card">
                        <div class="stat-label">Closing Balance</div>
                        <div class="stat-value">₹<?php echo number_format($balance['closing_balance'] ?? 0, 2); ?></div>
                        <small>End of day</small>
                    </div>
                </div>
            </div>

        <?php elseif ($view_type == 'weekly'): 
            $week_data = mysqli_fetch_assoc($weekly_data);
        ?>
            <!-- Weekly View -->
            <div class="row">
                <div class="col-md-4">
                    <div class="balance-card income-card">
                        <div class="stat-label">Week's Income</div>
                        <div class="stat-value">₹<?php echo number_format($week_data['total_income'] ?? 0, 2); ?></div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="balance-card expense-card">
                        <div class="stat-label">Week's Expense</div>
                        <div class="stat-value">₹<?php echo number_format($week_data['total_expense'] ?? 0, 2); ?></div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="balance-card closing-card">
                        <div class="stat-label">Net Profit/Loss</div>
                        <div class="stat-value <?php echo ($week_data['total_income'] - $week_data['total_expense']) >= 0 ? 'text-success' : 'text-danger'; ?>">
                            ₹<?php echo number_format(($week_data['total_income'] ?? 0) - ($week_data['total_expense'] ?? 0), 2); ?>
                        </div>
                        <small><?php echo $week_data['transaction_count'] ?? 0; ?> transactions</small>
                    </div>
                </div>
            </div>

        <?php else: 
            $month_data = mysqli_fetch_assoc($monthly_data);
        ?>
            <!-- Monthly View -->
            <div class="row">
                <div class="col-md-4">
                    <div class="balance-card income-card">
                        <div class="stat-label">Month's Income</div>
                        <div class="stat-value">₹<?php echo number_format($month_data['total_income'] ?? 0, 2); ?></div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="balance-card expense-card">
                        <div class="stat-label">Month's Expense</div>
                        <div class="stat-value">₹<?php echo number_format($month_data['total_expense'] ?? 0, 2); ?></div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="balance-card closing-card">
                        <div class="stat-label">Net Profit/Loss</div>
                        <div class="stat-value <?php echo ($month_data['total_income'] - $month_data['total_expense']) >= 0 ? 'text-success' : 'text-danger'; ?>">
                            ₹<?php echo number_format(($month_data['total_income'] ?? 0) - ($month_data['total_expense'] ?? 0), 2); ?>
                        </div>
                        <small><?php echo $month_data['transaction_count'] ?? 0; ?> transactions</small>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Add Transaction Form -->
        <div class="row mt-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="bi bi-plus-circle"></i> Add Transaction</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" class="row g-3">
                            <div class="col-md-2">
                                <label class="form-label">Date</label>
                                <input type="date" class="form-control" name="date" value="<?php echo $selected_date; ?>" required>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Type</label>
                                <select class="form-control" name="type" required>
                                    <option value="income">Income</option>
                                    <option value="expense">Expense</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Amount (₹)</label>
                                <input type="number" step="0.01" class="form-control" name="amount" required>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Category</label>
                                <select class="form-control" name="category">
                                    <option value="">Select</option>
                                    <option value="sales">Sales</option>
                                    <option value="service">Service</option>
                                    <option value="parts">Parts</option>
                                    <option value="rent">Rent</option>
                                    <option value="salary">Salary</option>
                                    <option value="electricity">Electricity</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Payment Method</label>
                                <select class="form-control" name="payment_method">
                                    <option value="cash">Cash</option>
                                    <option value="card">Card</option>
                                    <option value="online">Online</option>
                                    <option value="bank">Bank</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Description</label>
                                <input type="text" class="form-control" name="description" placeholder="e.g., Customer payment" required>
                            </div>
                            <div class="col-12">
                                <button type="submit" name="add_transaction" class="btn btn-primary">
                                    <i class="bi bi-plus"></i> Add Transaction
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- Transactions List -->
        <div class="row mt-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header bg-dark text-white">
                        <h5 class="mb-0">
                            <i class="bi bi-list-ul"></i> 
                            <?php 
                            if ($view_type == 'daily') echo "Transactions for " . date('d-m-Y', strtotime($selected_date));
                            elseif ($view_type == 'weekly') echo "Weekly Transactions";
                            else echo "Monthly Transactions";
                            ?>
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped table-hover">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Type</th>
                                        <th>Description</th>
                                        <th>Category</th>
                                        <th>Payment</th>
                                        <th>Amount</th>
                                        <th>Added By</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    if ($view_type == 'daily') {
                                        while($row = mysqli_fetch_assoc($transactions)): 
                                    ?>
                                    <tr class="transaction-row">
                                        <td><?php echo date('d-m-Y', strtotime($row['transaction_date'])); ?></td>
                                        <td>
                                            <?php if($row['type'] == 'income'): ?>
                                                <span class="badge bg-success">Income</span>
                                            <?php else: ?>
                                                <span class="badge bg-danger">Expense</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($row['description']); ?></td>
                                        <td><?php echo htmlspecialchars($row['category'] ?? '-'); ?></td>
                                        <td><?php echo ucfirst($row['payment_method'] ?? 'cash'); ?></td>
                                        <td class="<?php echo $row['type'] == 'income' ? 'text-success' : 'text-danger'; ?> fw-bold">
                                            ₹<?php echo number_format($row['amount'], 2); ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($row['username']); ?></td>
                                        <td>
                                            <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this transaction?')">
                                                <input type="hidden" name="id" value="<?php echo $row['id']; ?>">
                                                <input type="hidden" name="date" value="<?php echo $row['transaction_date']; ?>">
                                                <button type="submit" name="delete_transaction" class="btn btn-sm btn-danger">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                    <?php endwhile; } ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Monthly Chart -->
        <?php if ($view_type == 'monthly'): ?>
        <div class="row mt-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header bg-info text-white">
                        <h5 class="mb-0">Monthly Breakdown</h5>
                    </div>
                    <div class="card-body">
                        <canvas id="monthlyChart" height="300"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <div class="row mt-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header bg-secondary text-white">
                        <h5 class="mb-0">Weekly Breakdown</h5>
                    </div>
                    <div class="card-body">
                        <table class="table table-bordered">
                            <thead>
                                <tr>
                                    <th>Week</th>
                                    <th>Period</th>
                                    <th>Income</th>
                                    <th>Expense</th>
                                    <th>Net</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while($week = mysqli_fetch_assoc($weekly_breakdown)): 
                                    $net = $week['week_income'] - $week['week_expense'];
                                ?>
                                <tr>
                                    <td>Week <?php echo substr($week['week_number'], -2); ?></td>
                                    <td><?php echo date('d-m', strtotime($week['week_start'])) . ' to ' . date('d-m', strtotime($week['week_end'])); ?></td>
                                    <td class="text-success">₹<?php echo number_format($week['week_income'] ?? 0, 2); ?></td>
                                    <td class="text-danger">₹<?php echo number_format($week['week_expense'] ?? 0, 2); ?></td>
                                    <td class="<?php echo $net >= 0 ? 'text-success' : 'text-danger'; ?> fw-bold">
                                        ₹<?php echo number_format($net, 2); ?>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Daily Balances History -->
        <div class="row mt-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header bg-dark text-white">
                        <h5 class="mb-0">Daily Balance History (Last 30 Days)</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm table-bordered">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Opening</th>
                                        <th>Income</th>
                                        <th>Expense</th>
                                        <th>Closing</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while($day = mysqli_fetch_assoc($recent_balances)): ?>
                                    <tr>
                                        <td><?php echo date('d-m-Y', strtotime($day['balance_date'])); ?></td>
                                        <td>₹<?php echo number_format($day['opening_balance'], 2); ?></td>
                                        <td class="text-success">₹<?php echo number_format($day['total_income'], 2); ?></td>
                                        <td class="text-danger">₹<?php echo number_format($day['total_expense'], 2); ?></td>
                                        <td class="fw-bold">₹<?php echo number_format($day['closing_balance'], 2); ?></td>
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
    <?php if ($view_type == 'monthly'): ?>
    // Monthly Chart
    const ctx = document.getElementById('monthlyChart').getContext('2d');
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: [<?php 
                mysqli_data_seek($monthly_stats, 0);
                $months = [];
                $income = [];
                $expense = [];
                while($row = mysqli_fetch_assoc($monthly_stats)) {
                    $months[] = "'" . $row['month'] . "'";
                    $income[] = $row['month_income'] ?? 0;
                    $expense[] = $row['month_expense'] ?? 0;
                }
                echo implode(',', $months);
            ?>],
            datasets: [{
                label: 'Income',
                data: [<?php echo implode(',', $income); ?>],
                backgroundColor: 'rgba(40, 167, 69, 0.5)',
                borderColor: '#28a745',
                borderWidth: 1
            }, {
                label: 'Expense',
                data: [<?php echo implode(',', $expense); ?>],
                backgroundColor: 'rgba(220, 53, 69, 0.5)',
                borderColor: '#dc3545',
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
    </script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>