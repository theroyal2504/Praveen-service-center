<?php
require_once 'config.php';

if (!isLoggedIn()) {
    redirect('index.php');
}

// Handle Add Expense
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_expense'])) {
    $expense_date = $_POST['expense_date'];
    $category = mysqli_real_escape_string($conn, $_POST['category']);
    $description = mysqli_real_escape_string($conn, $_POST['description']);
    $quantity = $_POST['quantity'];
    $unit_price = $_POST['unit_price'];
    $total_amount = $quantity * $unit_price;
    $notes = mysqli_real_escape_string($conn, $_POST['notes']);
    $created_by = $_SESSION['user_id'];
    
    $query = "INSERT INTO operational_expenses (expense_date, category, description, quantity, unit_price, total_amount, notes, created_by) 
              VALUES ('$expense_date', '$category', '$description', $quantity, $unit_price, $total_amount, '$notes', $created_by)";
    
    if (mysqli_query($conn, $query)) {
        $_SESSION['success'] = "Expense added successfully!";
    } else {
        $_SESSION['error'] = "Error: " . mysqli_error($conn);
    }
    redirect('operational_expenses.php');
}

// Handle Edit Expense
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_expense'])) {
    $id = $_POST['id'];
    $expense_date = $_POST['expense_date'];
    $category = mysqli_real_escape_string($conn, $_POST['category']);
    $description = mysqli_real_escape_string($conn, $_POST['description']);
    $quantity = $_POST['quantity'];
    $unit_price = $_POST['unit_price'];
    $total_amount = $quantity * $unit_price;
    $notes = mysqli_real_escape_string($conn, $_POST['notes']);
    
    $query = "UPDATE operational_expenses SET 
              expense_date = '$expense_date',
              category = '$category',
              description = '$description',
              quantity = $quantity,
              unit_price = $unit_price,
              total_amount = $total_amount,
              notes = '$notes'
              WHERE id = $id";
    
    if (mysqli_query($conn, $query)) {
        $_SESSION['success'] = "Expense updated successfully!";
    } else {
        $_SESSION['error'] = "Error: " . mysqli_error($conn);
    }
    redirect('operational_expenses.php');
}

// Handle Delete Expense
if (isset($_GET['delete'])) {
    $id = $_GET['delete'];
    $query = "DELETE FROM operational_expenses WHERE id = $id";
    
    if (mysqli_query($conn, $query)) {
        $_SESSION['success'] = "Expense deleted successfully!";
    } else {
        $_SESSION['error'] = "Error: " . mysqli_error($conn);
    }
    redirect('operational_expenses.php');
}

// Fetch expenses with filters
$where_clause = "WHERE 1=1";
$filter_date = isset($_GET['filter_date']) ? $_GET['filter_date'] : '';
$filter_category = isset($_GET['filter_category']) ? $_GET['filter_category'] : '';
$filter_month = isset($_GET['filter_month']) ? $_GET['filter_month'] : '';

if (!empty($filter_date)) {
    $where_clause .= " AND expense_date = '$filter_date'";
}
if (!empty($filter_category)) {
    $where_clause .= " AND category = '$filter_category'";
}
if (!empty($filter_month)) {
    $where_clause .= " AND DATE_FORMAT(expense_date, '%Y-%m') = '$filter_month'";
}

$expenses_query = "SELECT e.*, u.username 
                   FROM operational_expenses e 
                   LEFT JOIN users u ON e.created_by = u.id 
                   $where_clause 
                   ORDER BY e.expense_date DESC, e.id DESC";
$expenses = mysqli_query($conn, $expenses_query);

// Get summary statistics
$today = date('Y-m-d');
$month_start = date('Y-m-01');
$month_end = date('Y-m-t');

// Today's expenses
$today_query = mysqli_query($conn, "SELECT SUM(total_amount) as total FROM operational_expenses WHERE expense_date = '$today'");
$today_total = mysqli_fetch_assoc($today_query)['total'] ?? 0;

// This month's expenses
$month_query = mysqli_query($conn, "SELECT SUM(total_amount) as total FROM operational_expenses WHERE expense_date BETWEEN '$month_start' AND '$month_end'");
$month_total = mysqli_fetch_assoc($month_query)['total'] ?? 0;

// All time total
$total_query = mysqli_query($conn, "SELECT SUM(total_amount) as total FROM operational_expenses");
$all_time_total = mysqli_fetch_assoc($total_query)['total'] ?? 0;

// Category wise summary
$category_summary = mysqli_query($conn, "SELECT category, COUNT(*) as count, SUM(total_amount) as total 
                                          FROM operational_expenses 
                                          GROUP BY category 
                                          ORDER BY total DESC");

// Get available months for filter
$months_query = mysqli_query($conn, "SELECT DISTINCT DATE_FORMAT(expense_date, '%Y-%m') as month 
                                      FROM operational_expenses 
                                      ORDER BY month DESC");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Operational Expenses - Bike Management System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <style>
        .summary-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .category-badge {
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 0.9em;
            font-weight: 500;
        }
        .category-Stationery { background-color: #17a2b8; color: white; }
        .category-Cleaning { background-color: #28a745; color: white; }
        .category-Refreshment { background-color: #ffc107; color: black; }
        .category-Petty { background-color: #fd7e14; color: white; }
        .category-Maintenance { background-color: #dc3545; color: white; }
        .category-Other { background-color: #6c757d; color: white; }
        .expense-row:hover {
            background-color: #f8f9fa;
        }
        .filter-section {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
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
        <!-- Alerts -->
        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Header -->
        <div class="row mb-4">
            <div class="col-md-8">
                <h2><i class="bi bi-cash-stack"></i> Operational Expenses Management</h2>
                <p class="text-muted">Track all business expenses like stationery, cleaning, refreshment, etc.</p>
            </div>
            <div class="col-md-4 text-end">
                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addExpenseModal">
                    <i class="bi bi-plus-circle"></i> Add New Expense
                </button>
                <a href="expenses_report.php" class="btn btn-info">
                    <i class="bi bi-graph-up"></i> View Report
                </a>
            </div>
        </div>

        <!-- Summary Cards -->
        <div class="row mb-4">
            <div class="col-md-4">
                <div class="card text-white bg-primary">
                    <div class="card-body">
                        <h6 class="card-title">Today's Expenses</h6>
                        <h3>₹<?php echo number_format($today_total, 2); ?></h3>
                        <small><?php echo date('d-m-Y'); ?></small>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card text-white bg-warning">
                    <div class="card-body">
                        <h6 class="card-title">This Month's Expenses</h6>
                        <h3>₹<?php echo number_format($month_total, 2); ?></h3>
                        <small><?php echo date('F Y'); ?></small>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card text-white bg-info">
                    <div class="card-body">
                        <h6 class="card-title">Total Expenses (All Time)</h6>
                        <h3>₹<?php echo number_format($all_time_total, 2); ?></h3>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filter Section -->
        <div class="filter-section">
            <form method="GET" class="row">
                <div class="col-md-3">
                    <label class="form-label">Filter by Date</label>
                    <input type="date" name="filter_date" class="form-control" value="<?php echo $filter_date; ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Filter by Category</label>
                    <select name="filter_category" class="form-control">
                        <option value="">All Categories</option>
                        <option value="Stationery" <?php echo $filter_category == 'Stationery' ? 'selected' : ''; ?>>Stationery</option>
                        <option value="Cleaning" <?php echo $filter_category == 'Cleaning' ? 'selected' : ''; ?>>Cleaning</option>
                        <option value="Refreshment" <?php echo $filter_category == 'Refreshment' ? 'selected' : ''; ?>>Refreshment</option>
                        <option value="Petty Cash" <?php echo $filter_category == 'Petty Cash' ? 'selected' : ''; ?>>Petty Cash</option>
                        <option value="Maintenance" <?php echo $filter_category == 'Maintenance' ? 'selected' : ''; ?>>Maintenance</option>
                        <option value="Other" <?php echo $filter_category == 'Other' ? 'selected' : ''; ?>>Other</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Filter by Month</label>
                    <select name="filter_month" class="form-control">
                        <option value="">All Months</option>
                        <?php while($month = mysqli_fetch_assoc($months_query)): ?>
                        <option value="<?php echo $month['month']; ?>" <?php echo $filter_month == $month['month'] ? 'selected' : ''; ?>>
                            <?php echo date('F Y', strtotime($month['month'].'-01')); ?>
                        </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary me-2">Apply Filters</button>
                    <a href="operational_expenses.php" class="btn btn-secondary">Clear</a>
                </div>
            </form>
        </div>

        <!-- Category Summary -->
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header bg-secondary text-white">
                        <h6 class="mb-0">Category-wise Summary</h6>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <?php while($cat = mysqli_fetch_assoc($category_summary)): ?>
                            <div class="col-md-3 mb-2">
                                <div class="border rounded p-2">
                                    <span class="category-badge category-<?php echo str_replace(' ', '', $cat['category']); ?>">
                                        <?php echo $cat['category']; ?>
                                    </span>
                                    <div class="mt-1">
                                        <strong>₹<?php echo number_format($cat['total'], 2); ?></strong>
                                        <small class="text-muted">(<?php echo $cat['count']; ?> entries)</small>
                                    </div>
                                </div>
                            </div>
                            <?php endwhile; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Expenses Table -->
        <div class="card">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0"><i class="bi bi-list"></i> Expense Entries</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-bordered table-hover">
                        <thead class="table-dark">
                            <tr>
                                <th>Date</th>
                                <th>Category</th>
                                <th>Description</th>
                                <th>Qty</th>
                                <th>Unit Price</th>
                                <th>Total</th>
                                <th>Notes</th>
                                <th>Added By</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if(mysqli_num_rows($expenses) > 0): ?>
                                <?php while($expense = mysqli_fetch_assoc($expenses)): ?>
                                <tr class="expense-row">
                                    <td><?php echo date('d-m-Y', strtotime($expense['expense_date'])); ?></td>
                                    <td>
                                        <span class="category-badge category-<?php echo str_replace(' ', '', $expense['category']); ?>">
                                            <?php echo $expense['category']; ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($expense['description']); ?></td>
                                    <td><?php echo $expense['quantity']; ?></td>
                                    <td>₹<?php echo number_format($expense['unit_price'], 2); ?></td>
                                    <td><strong>₹<?php echo number_format($expense['total_amount'], 2); ?></strong></td>
                                    <td><small class="text-muted"><?php echo htmlspecialchars($expense['notes'] ?? '-'); ?></small></td>
                                    <td><?php echo $expense['username']; ?></td>
                                    <td>
                                        <button type="button" class="btn btn-sm btn-warning" 
                                                onclick="editExpense(<?php echo $expense['id']; ?>, 
                                                '<?php echo $expense['expense_date']; ?>',
                                                '<?php echo $expense['category']; ?>',
                                                '<?php echo addslashes($expense['description']); ?>',
                                                <?php echo $expense['quantity']; ?>,
                                                <?php echo $expense['unit_price']; ?>,
                                                '<?php echo addslashes($expense['notes'] ?? ''); ?>')">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <a href="?delete=<?php echo $expense['id']; ?>" 
                                           class="btn btn-sm btn-danger" 
                                           onclick="return confirm('Are you sure you want to delete this expense?')">
                                            <i class="bi bi-trash"></i>
                                        </a>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="9" class="text-center py-4">
                                        <i class="bi bi-inbox fs-1 d-block text-muted"></i>
                                        <p class="text-muted mt-2">No expenses found</p>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Expense Modal -->
    <div class="modal fade" id="addExpenseModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">Add New Operational Expense</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Expense Date *</label>
                            <input type="date" name="expense_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Category *</label>
                            <select name="category" class="form-control" required>
                                <option value="">Select Category</option>
                                <option value="Stationery">📝 Stationery</option>
                                <option value="Cleaning">🧹 Cleaning</option>
                                <option value="Refreshment">☕ Refreshment</option>
                                <option value="Petty Cash">💰 Petty Cash</option>
                                <option value="Maintenance">🔧 Maintenance</option>
                                <option value="Other">🛠️ Other</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Description *</label>
                            <input type="text" name="description" class="form-control" placeholder="e.g., Register Book, Tea/Coffee, etc." required>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Quantity *</label>
                                <input type="number" name="quantity" class="form-control" min="1" value="1" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Unit Price (₹) *</label>
                                <input type="number" step="0.01" name="unit_price" class="form-control" min="0" required>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Notes (Optional)</label>
                            <textarea name="notes" class="form-control" rows="2"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="add_expense" class="btn btn-primary">Add Expense</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Expense Modal -->
    <div class="modal fade" id="editExpenseModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-warning">
                    <h5 class="modal-title">Edit Expense</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="id" id="edit_id">
                        <div class="mb-3">
                            <label class="form-label">Expense Date *</label>
                            <input type="date" name="expense_date" id="edit_date" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Category *</label>
                            <select name="category" id="edit_category" class="form-control" required>
                                <option value="Stationery">Stationery</option>
                                <option value="Cleaning">Cleaning</option>
                                <option value="Refreshment">Refreshment</option>
                                <option value="Petty Cash">Petty Cash</option>
                                <option value="Maintenance">Maintenance</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Description *</label>
                            <input type="text" name="description" id="edit_description" class="form-control" required>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Quantity *</label>
                                <input type="number" name="quantity" id="edit_quantity" class="form-control" min="1" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Unit Price (₹) *</label>
                                <input type="number" step="0.01" name="unit_price" id="edit_unit_price" class="form-control" min="0" required>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Notes</label>
                            <textarea name="notes" id="edit_notes" class="form-control" rows="2"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="edit_expense" class="btn btn-warning">Update Expense</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function editExpense(id, date, category, description, quantity, unitPrice, notes) {
            document.getElementById('edit_id').value = id;
            document.getElementById('edit_date').value = date;
            document.getElementById('edit_category').value = category;
            document.getElementById('edit_description').value = description;
            document.getElementById('edit_quantity').value = quantity;
            document.getElementById('edit_unit_price').value = unitPrice;
            document.getElementById('edit_notes').value = notes;
            
            new bootstrap.Modal(document.getElementById('editExpenseModal')).show();
        }
    </script>
</body>
</html>