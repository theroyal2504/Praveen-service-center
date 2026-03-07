<?php
require_once 'config.php';

if (!isLoggedIn()) {
    redirect('index.php');
}

// Handle add income/expense
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['add_transaction'])) {
        $date = $_POST['date'];
        $type = $_POST['type'];
        $amount = floatval($_POST['amount']);
        $description = mysqli_real_escape_string($conn, $_POST['description']);
        $created_by = $_SESSION['user_id'];
        
        $query = "INSERT INTO simple_transactions (date, type, amount, description, created_by) 
                  VALUES ('$date', '$type', $amount, '$description', $created_by)";
        
        if (mysqli_query($conn, $query)) {
            $_SESSION['success'] = "Transaction added successfully!";
        } else {
            $_SESSION['error'] = "Error: " . mysqli_error($conn);
        }
        redirect('simple_accounting.php');
    }
    
    if (isset($_POST['delete'])) {
        $id = $_POST['id'];
        mysqli_query($conn, "DELETE FROM simple_transactions WHERE id = $id");
        $_SESSION['success'] = "Transaction deleted!";
        redirect('simple_accounting.php');
    }
}

// Get today's date
$today = date('Y-m-d');

// Calculate totals
// Today's income
$today_income = mysqli_fetch_assoc(mysqli_query($conn, 
    "SELECT SUM(amount) as total FROM simple_transactions 
     WHERE type = 'income' AND date = '$today'"))['total'] ?? 0;

// Today's expenses
$today_expense = mysqli_fetch_assoc(mysqli_query($conn, 
    "SELECT SUM(amount) as total FROM simple_transactions 
     WHERE type = 'expense' AND date = '$today'"))['total'] ?? 0;

// Total balance (all time)
$total_income = mysqli_fetch_assoc(mysqli_query($conn, 
    "SELECT SUM(amount) as total FROM simple_transactions WHERE type = 'income'"))['total'] ?? 0;
    
$total_expense = mysqli_fetch_assoc(mysqli_query($conn, 
    "SELECT SUM(amount) as total FROM simple_transactions WHERE type = 'expense'"))['total'] ?? 0;

$balance = $total_income - $total_expense;

// Get recent transactions
$transactions = mysqli_query($conn, 
    "SELECT t.*, u.username FROM simple_transactions t
     LEFT JOIN users u ON t.created_by = u.id
     ORDER BY t.date DESC, t.id DESC LIMIT 50");
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
        .balance-card {
            border-radius: 15px;
            padding: 20px;
            color: white;
            margin-bottom: 20px;
        }
        .balance-amount {
            font-size: 2.5em;
            font-weight: bold;
        }
        .income-card {
            background: linear-gradient(135deg, #28a745, #20c997);
        }
        .expense-card {
            background: linear-gradient(135deg, #dc3545, #fd7e14);
        }
        .balance-total {
            background: linear-gradient(135deg, #17a2b8, #6610f2);
        }
        .stat-value {
            font-size: 2em;
            font-weight: bold;
        }
        .stat-label {
            font-size: 0.9em;
            opacity: 0.9;
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

        <!-- Balance Cards -->
        <div class="row">
            <div class="col-md-4">
                <div class="balance-card income-card">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="stat-label">Today's Income</div>
                            <div class="stat-value">₹<?php echo number_format($today_income, 2); ?></div>
                        </div>
                        <i class="bi bi-arrow-up-circle fs-1"></i>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="balance-card expense-card">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="stat-label">Today's Expense</div>
                            <div class="stat-value">₹<?php echo number_format($today_expense, 2); ?></div>
                        </div>
                        <i class="bi bi-arrow-down-circle fs-1"></i>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="balance-card balance-total">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="stat-label">Total Balance</div>
                            <div class="stat-value">₹<?php echo number_format($balance, 2); ?></div>
                        </div>
                        <i class="bi bi-wallet2 fs-1"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Add Transaction Form -->
        <div class="row mt-3">
            <div class="col-12">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="bi bi-plus-circle"></i> Add Income / Expense</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" class="row g-3">
                            <div class="col-md-2">
                                <label for="date" class="form-label">Date</label>
                                <input type="date" class="form-control" id="date" name="date" value="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                            <div class="col-md-2">
                                <label for="type" class="form-label">Type</label>
                                <select class="form-control" id="type" name="type" required>
                                    <option value="income">Income</option>
                                    <option value="expense">Expense</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label for="amount" class="form-label">Amount (₹)</label>
                                <input type="number" step="0.01" class="form-control" id="amount" name="amount" required>
                            </div>
                            <div class="col-md-4">
                                <label for="description" class="form-label">Description</label>
                                <input type="text" class="form-control" id="description" name="description" placeholder="e.g., Customer payment, Rent, etc." required>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">&nbsp;</label>
                                <button type="submit" name="add_transaction" class="btn btn-primary d-block w-100">
                                    <i class="bi bi-plus"></i> Add
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
                        <h5 class="mb-0"><i class="bi bi-list-ul"></i> Recent Transactions</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped table-hover">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Type</th>
                                        <th>Description</th>
                                        <th>Amount</th>
                                        <th>Added By</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while($row = mysqli_fetch_assoc($transactions)): ?>
                                    <tr>
                                        <td><?php echo date('d-m-Y', strtotime($row['date'])); ?></td>
                                        <td>
                                            <?php if($row['type'] == 'income'): ?>
                                                <span class="badge bg-success">Income</span>
                                            <?php else: ?>
                                                <span class="badge bg-danger">Expense</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($row['description']); ?></td>
                                        <td class="<?php echo $row['type'] == 'income' ? 'text-success' : 'text-danger'; ?> fw-bold">
                                            ₹<?php echo number_format($row['amount'], 2); ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($row['username']); ?></td>
                                        <td>
                                            <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this transaction?')">
                                                <input type="hidden" name="id" value="<?php echo $row['id']; ?>">
                                                <button type="submit" name="delete" class="btn btn-sm btn-danger">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                    <?php endwhile; ?>
                                </tbody>
                                <tfoot class="table-secondary">
                                    <tr>
                                        <th colspan="3" class="text-end">Total Income:</th>
                                        <th class="text-success">₹<?php echo number_format($total_income, 2); ?></th>
                                        <th colspan="2"></th>
                                    </tr>
                                    <tr>
                                        <th colspan="3" class="text-end">Total Expense:</th>
                                        <th class="text-danger">₹<?php echo number_format($total_expense, 2); ?></th>
                                        <th colspan="2"></th>
                                    </tr>
                                    <tr>
                                        <th colspan="3" class="text-end">Final Balance:</th>
                                        <th class="text-primary">₹<?php echo number_format($balance, 2); ?></th>
                                        <th colspan="2"></th>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>