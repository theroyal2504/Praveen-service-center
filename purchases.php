<?php
require_once 'config.php';

if (!isLoggedIn()) {
    redirect('index.php');
}

// Handle new purchase - both admin and staff can add
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_purchase'])) {
    $supplier_id = $_POST['supplier_id'];
    $purchase_date = $_POST['purchase_date'];
    $invoice_number = mysqli_real_escape_string($conn, $_POST['invoice_number']);
    $created_by = $_SESSION['user_id'];
    $status = isset($_POST['save_as_draft']) ? 'draft' : 'completed';
    
    // Start transaction
    mysqli_begin_transaction($conn);
    
    try {
        // Insert purchase with status
        $query = "INSERT INTO purchases (supplier_id, purchase_date, invoice_number, created_by, status, total_amount, operational_total) 
                  VALUES ($supplier_id, '$purchase_date', '$invoice_number', $created_by, '$status', 0, 0)";
        mysqli_query($conn, $query);
        $purchase_id = mysqli_insert_id($conn);
        
        $total_amount = 0;
        $operational_total = 0;
        
        // Check if there are items to insert (purchase items for selling)
        if (isset($_POST['part_id']) && is_array($_POST['part_id'])) {
            $part_ids = $_POST['part_id'];
            $quantities = $_POST['quantity'];
            $purchase_prices = $_POST['purchase_price'];
            $selling_prices = $_POST['selling_price'];
            
            for ($i = 0; $i < count($part_ids); $i++) {
                if (!empty($part_ids[$i]) && !empty($quantities[$i]) && $quantities[$i] > 0) {
                    $part_id = $part_ids[$i];
                    $quantity = $quantities[$i];
                    $purchase_price = $purchase_prices[$i];
                    $selling_price = $selling_prices[$i];
                    
                    $item_query = "INSERT INTO purchase_items (purchase_id, part_id, quantity, purchase_price, selling_price) 
                                  VALUES ($purchase_id, $part_id, $quantity, $purchase_price, $selling_price)";
                    mysqli_query($conn, $item_query);
                    
                    $total_amount += $quantity * $purchase_price;
                    
                    // Update stock only if status is completed
                    if ($status == 'completed') {
                        $stock_query = "UPDATE stock SET quantity = quantity + $quantity WHERE part_id = $part_id";
                        mysqli_query($conn, $stock_query);
                    }
                }
            }
        }
        
        // Handle operational expenses (business use items - not for selling)
        if (isset($_POST['expense_description']) && is_array($_POST['expense_description'])) {
            $expense_descriptions = $_POST['expense_description'];
            $expense_quantities = $_POST['expense_quantity'];
            $expense_prices = $_POST['expense_price'];
            $expense_categories = $_POST['expense_category'];
            
            for ($i = 0; $i < count($expense_descriptions); $i++) {
                if (!empty($expense_descriptions[$i]) && !empty($expense_quantities[$i]) && $expense_quantities[$i] > 0) {
                    $description = mysqli_real_escape_string($conn, $expense_descriptions[$i]);
                    $category = mysqli_real_escape_string($conn, $expense_categories[$i]);
                    $quantity = $expense_quantities[$i];
                    $price = $expense_prices[$i];
                    $expense_total = $quantity * $price;
                    
                    // Insert into operational_expenses table
                    $expense_query = "INSERT INTO operational_expenses 
                                     (purchase_id, category, description, quantity, unit_price, total_amount, created_by, expense_date) 
                                     VALUES 
                                     ($purchase_id, '$category', '$description', $quantity, $price, $expense_total, $created_by, '$purchase_date')";
                    mysqli_query($conn, $expense_query);
                    
                    $operational_total += $expense_total;
                }
            }
        }
        
        // Update total amounts in purchase
        mysqli_query($conn, "UPDATE purchases SET total_amount = $total_amount, operational_total = $operational_total WHERE id = $purchase_id");
        
        mysqli_commit($conn);
        
        if ($status == 'draft') {
            $_SESSION['success'] = "Purchase draft saved successfully! You can complete it later.";
        } else {
            $_SESSION['success'] = "Purchase completed successfully!";
        }
        
    } catch (Exception $e) {
        mysqli_rollback($conn);
        $_SESSION['error'] = "Error: " . $e->getMessage();
    }
    
    redirect('purchases.php');
}

// Handle draft completion
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['complete_purchase'])) {
    $purchase_id = $_POST['purchase_id'];
    
    mysqli_begin_transaction($conn);
    
    try {
        // Get purchase items
        $items_query = "SELECT * FROM purchase_items WHERE purchase_id = $purchase_id";
        $items_result = mysqli_query($conn, $items_query);
        
        // Update stock for each item
        while ($item = mysqli_fetch_assoc($items_result)) {
            $stock_query = "UPDATE stock SET quantity = quantity + {$item['quantity']} WHERE part_id = {$item['part_id']}";
            mysqli_query($conn, $stock_query);
        }
        
        // Update purchase status
        mysqli_query($conn, "UPDATE purchases SET status = 'completed' WHERE id = $purchase_id");
        
        mysqli_commit($conn);
        $_SESSION['success'] = "Purchase completed successfully!";
        
    } catch (Exception $e) {
        mysqli_rollback($conn);
        $_SESSION['error'] = "Error completing purchase: " . $e->getMessage();
    }
    
    redirect('purchases.php');
}

// Fetch data for dropdowns
$suppliers = mysqli_query($conn, "SELECT * FROM suppliers ORDER BY supplier_name");

// Fetch categories
$categories = mysqli_query($conn, "SELECT * FROM categories ORDER BY category_name");

// Fetch parts with category information
$parts_query = "SELECT p.*, s.quantity as current_stock, c.category_name, c.id as category_id
                FROM parts_master p 
                LEFT JOIN stock s ON p.id = s.part_id 
                LEFT JOIN categories c ON p.category_id = c.id 
                ORDER BY c.category_name, p.part_name";
$parts = mysqli_query($conn, $parts_query);

// Group parts by category for JavaScript
$parts_by_category = [];
$parts_result = mysqli_query($conn, $parts_query);
while($part = mysqli_fetch_assoc($parts_result)) {
    $cat_name = $part['category_name'] ?? 'Uncategorized';
    if (!isset($parts_by_category[$cat_name])) {
        $parts_by_category[$cat_name] = [];
    }
    $parts_by_category[$cat_name][] = $part;
}

// Fetch recent purchases including drafts
$purchases = mysqli_query($conn, "SELECT p.*, s.supplier_name, u.username 
                                  FROM purchases p 
                                  LEFT JOIN suppliers s ON p.supplier_id = s.id 
                                  LEFT JOIN users u ON p.created_by = u.id 
                                  ORDER BY p.purchase_date DESC, 
                                           CASE WHEN p.status = 'draft' THEN 0 ELSE 1 END,
                                           p.id DESC 
                                  LIMIT 50");

// Fetch draft purchases for quick access
$drafts = mysqli_query($conn, "SELECT p.*, s.supplier_name 
                               FROM purchases p 
                               LEFT JOIN suppliers s ON p.supplier_id = s.id 
                               WHERE p.status = 'draft' 
                               ORDER BY p.purchase_date DESC");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Purchases - Bike Management System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <style>
        .category-select {
            background-color: #f8f9fa;
            border-left: 3px solid #007bff;
        }
        .part-select {
            border-left: 3px solid #28a745;
        }
        .category-badge {
            background-color: #6c757d;
            color: white;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 0.8em;
            margin-left: 5px;
        }
        .item-row {
            transition: all 0.3s ease;
        }
        .item-row:hover {
            background-color: #f5f5f5;
        }
        .expense-row {
            background-color: #f8f9fa;
        }
        .expense-row:hover {
            background-color: #e9ecef;
        }
        optgroup {
            font-weight: bold;
            color: #495057;
            background-color: #e9ecef;
            font-size: 1.1em;
        }
        optgroup option {
            padding-left: 20px;
            font-weight: normal;
            color: #212529;
        }
        .category-filter {
            margin-bottom: 15px;
            padding: 10px;
            background-color: #f8f9fa;
            border-radius: 5px;
        }
        .status-badge {
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 0.85em;
            font-weight: 500;
        }
        .status-draft {
            background-color: #ffc107;
            color: #000;
        }
        .status-completed {
            background-color: #28a745;
            color: #fff;
        }
        .draft-card {
            border-left: 4px solid #ffc107;
            margin-bottom: 10px;
            background-color: #fff3cd;
        }
        .draft-card:hover {
            background-color: #ffe69c;
        }
        .operational-total {
            background-color: #e7f3ff !important;
            font-weight: bold;
        }
        .expense-category-select {
            border-left: 3px solid #6c757d;
            margin-bottom: 5px;
        }
        .summary-card {
            background-color: #f8f9fa;
            border-left: 4px solid #007bff;
        }
        
        /* Small form controls for operational expenses */
        .form-control-sm {
            font-size: 0.875rem;
            padding: 0.25rem 0.5rem;
            height: 31px;
        }
        
        .btn-sm {
            padding: 0.25rem 0.5rem;
            font-size: 0.875rem;
        }
        
        .table-sm th,
        .table-sm td {
            padding: 0.3rem;
        }
        
        .table-sm input[type="text"],
        .table-sm input[type="number"],
        .table-sm select {
            font-size: 0.85rem;
        }
        
        .expense-row .form-control-sm {
            height: 30px;
        }
        
        .alert.py-1 {
            padding-top: 0.25rem !important;
            padding-bottom: 0.25rem !important;
        }
        
        .card-header.py-2 {
            padding-top: 0.5rem !important;
            padding-bottom: 0.5rem !important;
        }
        
        .card-body.py-2 {
            padding-top: 0.5rem !important;
            padding-bottom: 0.5rem !important;
        }
        
        .text-white-50 {
            color: rgba(255, 255, 255, 0.7) !important;
        }
        
        .table.mb-2 {
            margin-bottom: 0.5rem !important;
        }
        
        .btn-sm.py-0 {
            padding-top: 0 !important;
            padding-bottom: 0 !important;
        }
        
        .btn-sm.px-1 {
            padding-left: 0.25rem !important;
            padding-right: 0.25rem !important;
        }
        
        .table-borderless td {
            border: none;
        }
    </style>
    <!-- Add Select2 for better search -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
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
        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?php 
                echo $_SESSION['success']; 
                unset($_SESSION['success']);
                ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?php 
                echo $_SESSION['error']; 
                unset($_SESSION['error']);
                ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if (mysqli_num_rows($drafts) > 0): ?>
        <div class="row mb-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header bg-warning">
                        <h5 class="mb-0"><i class="bi bi-pencil-square"></i> Draft Purchases (Pending Completion)</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <?php while($draft = mysqli_fetch_assoc($drafts)): ?>
                            <div class="col-md-4">
                                <div class="card draft-card">
                                    <div class="card-body">
                                        <h6 class="card-title">
                                            <span class="badge bg-warning text-dark">Draft</span>
                                            Invoice: <?php echo htmlspecialchars($draft['invoice_number']); ?>
                                        </h6>
                                        <p class="card-text">
                                            <small>
                                                Supplier: <?php echo htmlspecialchars($draft['supplier_name']); ?><br>
                                                Date: <?php echo date('d-m-Y', strtotime($draft['purchase_date'])); ?><br>
                                                Stock Items: ₹<?php echo number_format($draft['total_amount'], 2); ?><br>
                                                Operational: ₹<?php echo number_format($draft['operational_total'] ?? 0, 2); ?>
                                            </small>
                                        </p>
                                        <a href="purchase_edit.php?id=<?php echo $draft['id']; ?>" class="btn btn-sm btn-warning">
                                            <i class="bi bi-pencil"></i> Continue Draft
                                        </a>
                                        <button type="button" class="btn btn-sm btn-success" 
                                                onclick="completePurchase(<?php echo $draft['id']; ?>, '<?php echo htmlspecialchars($draft['invoice_number']); ?>')">
                                            <i class="bi bi-check-circle"></i> Complete
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <?php endwhile; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="bi bi-cart-plus"></i> New Purchase Entry</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" id="purchaseForm">
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label for="supplier_id" class="form-label">Supplier</label>
                                        <select class="form-control" id="supplier_id" name="supplier_id" required>
                                            <option value="">Select Supplier</option>
                                            <?php while($supplier = mysqli_fetch_assoc($suppliers)): ?>
                                            <option value="<?php echo $supplier['id']; ?>"><?php echo htmlspecialchars($supplier['supplier_name']); ?></option>
                                            <?php endwhile; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label for="purchase_date" class="form-label">Purchase Date</label>
                                        <input type="date" class="form-control" id="purchase_date" name="purchase_date" value="<?php echo date('Y-m-d'); ?>" required>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label for="invoice_number" class="form-label">Invoice Number</label>
                                        <input type="text" class="form-control" id="invoice_number" name="invoice_number" required>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Stock Items Section (For Selling) -->
                            <div class="card mb-3">
                                <div class="card-header bg-success text-white">
                                    <h6 class="mb-0"><i class="bi bi-box"></i> Stock Items (For Selling)</h6>
                                </div>
                                <div class="card-body">
                                    <div class="table-responsive">
                                        <table class="table table-bordered" id="itemsTable">
                                            <thead class="table-dark">
                                                <tr>
                                                    <th width="15%">Category</th>
                                                    <th width="25%">Part</th>
                                                    <th width="10%">Quantity</th>
                                                    <th width="15%">Purchase Price</th>
                                                    <th width="15%">Selling Price</th>
                                                    <th width="10%">Total</th>
                                                    <th width="10%">Action</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <tr class="item-row">
                                                    <td>
                                                        <select class="form-control category-select" onchange="filterPartsByCategory(this)">
                                                            <option value="">Select Category</option>
                                                            <?php 
                                                            mysqli_data_seek($categories, 0);
                                                            while($cat = mysqli_fetch_assoc($categories)): 
                                                            ?>
                                                            <option value="<?php echo htmlspecialchars($cat['category_name']); ?>">
                                                                <?php echo htmlspecialchars($cat['category_name']); ?>
                                                            </option>
                                                            <?php endwhile; ?>
                                                            <option value="Uncategorized">Uncategorized</option>
                                                        </select>
                                                    </td>
                                                    <td>
                                                        <select class="form-control part-select" name="part_id[]" required disabled>
                                                            <option value="">First select category</option>
                                                        </select>
                                                    </td>
                                                    <td><input type="number" class="form-control quantity" name="quantity[]" min="1" required disabled></td>
                                                    <td><input type="number" step="0.01" class="form-control purchase-price" name="purchase_price[]" required disabled></td>
                                                    <td><input type="number" step="0.01" class="form-control selling-price" name="selling_price[]" required disabled></td>
                                                    <td><input type="text" class="form-control row-total" readonly></td>
                                                    <td><button type="button" class="btn btn-danger btn-sm remove-row"><i class="bi bi-trash"></i></button></td>
                                                </tr>
                                            </tbody>
                                            <tfoot>
                                                <tr class="table-info">
                                                    <td colspan="5" class="text-end"><strong>Stock Items Total:</strong></td>
                                                    <td><input type="text" class="form-control" id="grandTotal" readonly style="font-weight:bold;"></td>
                                                    <td></td>
                                                </tr>
                                            </tfoot>
                                        </table>
                                    </div>
                                    
                                    <button type="button" class="btn btn-success" id="addRow">
                                        <i class="bi bi-plus-circle"></i> Add Another Stock Item
                                    </button>
                                </div>
                            </div>
                            
                            <!-- Operational Expenses Section (Business Use Only - Not for Selling) -->
                            <div class="card mb-3">
                                <div class="card-header bg-secondary text-white py-2">
                                    <h6 class="mb-0"><i class="bi bi-briefcase"></i> Operational Expenses <small class="text-white-50">(Business Use Only - Not for Selling)</small></h6>
                                </div>
                                <div class="card-body py-2">
                                    <div class="alert alert-secondary py-1 mb-2">
                                        <small><i class="bi bi-info-circle"></i> These items are for business use only (stationery, cleaning supplies, staff refreshment, etc.). They won't be sold and won't affect loss calculations.</small>
                                    </div>
                                    
                                    <div class="table-responsive">
                                        <table class="table table-bordered table-sm mb-2" id="expenseItemsTable">
                                            <thead class="table-secondary">
                                                <tr>
                                                    <th width="20%"><small>Category</small></th>
                                                    <th width="30%"><small>Item Description</small></th>
                                                    <th width="10%"><small>Qty</small></th>
                                                    <th width="15%"><small>Unit Price (₹)</small></th>
                                                    <th width="15%"><small>Total</small></th>
                                                    <th width="10%"><small>Action</small></th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <tr class="expense-row">
                                                    <td>
                                                        <select class="form-control form-control-sm expense-category-select" name="expense_category[]" onchange="handleExpenseCategory(this)">
                                                            <option value="">Select</option>
                                                            <option value="Stationery">📝 Stationery</option>
                                                            <option value="Cleaning">🧹 Cleaning</option>
                                                            <option value="Refreshment">☕ Refreshment</option>
                                                            <option value="Petty Cash">💰 Petty Cash</option>
                                                            <option value="Maintenance">🔧 Maintenance</option>
                                                            <option value="Other">🛠️ Other</option>
                                                        </select>
                                                    </td>
                                                    <td>
                                                        <input type="text" class="form-control form-control-sm expense-description" 
                                                               name="expense_description[]" 
                                                               placeholder="Enter description">
                                                    </td>
                                                    <td>
                                                        <input type="number" class="form-control form-control-sm expense-quantity" 
                                                               name="expense_quantity[]" min="1" value="1">
                                                    </td>
                                                    <td>
                                                        <input type="number" step="0.01" class="form-control form-control-sm expense-price" 
                                                               name="expense_price[]" min="0" step="0.01" value="0">
                                                    </td>
                                                    <td>
                                                        <input type="text" class="form-control form-control-sm expense-row-total" readonly>
                                                    </td>
                                                    <td>
                                                        <button type="button" class="btn btn-danger btn-sm remove-expense py-0 px-1">
                                                            <i class="bi bi-trash"></i>
                                                        </button>
                                                    </td>
                                                </tr>
                                            </tbody>
                                            <tfoot>
                                                <tr class="table-info">
                                                    <td colspan="4" class="text-end"><small><strong>Operational Expenses Total:</strong></small></td>
                                                    <td>
                                                        <input type="text" class="form-control form-control-sm operational-total" id="expensesGrandTotal" 
                                                               readonly style="font-weight:bold;">
                                                    </td>
                                                    <td></td>
                                                </tr>
                                            </tfoot>
                                        </table>
                                    </div>
                                    
                                    <button type="button" class="btn btn-secondary btn-sm" id="addExpenseRow">
                                        <i class="bi bi-plus-circle"></i> Add Item
                                    </button>
                                </div>
                            </div>
                            
                            <!-- Summary Card -->
                            <div class="card summary-card mb-3">
                                <div class="card-body py-2">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <small><strong>Purchase Summary:</strong></small>
                                            <table class="table table-sm table-borderless mb-0">
                                                <tr>
                                                    <td><small>Stock Items Total:</small></td>
                                                    <td><small><strong id="summaryStockTotal">₹0.00</strong></small></td>
                                                </tr>
                                                <tr>
                                                    <td><small>Operational Expenses:</small></td>
                                                    <td><small><strong id="summaryExpenseTotal">₹0.00</strong></small></td>
                                                </tr>
                                                <tr class="table-primary">
                                                    <td><small><strong>Grand Total:</strong></small></td>
                                                    <td><small><strong id="summaryGrandTotal">₹0.00</strong></small></td>
                                                </tr>
                                            </table>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="alert alert-info py-1 mb-0">
                                                <small><i class="bi bi-info-circle"></i> Operational expenses are recorded separately and won't affect profit/loss calculations.</small>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Hidden inputs -->
                            <input type="hidden" name="operational_expenses_total" id="operationalExpensesTotal" value="0">
                            
                            <div class="mt-3">
                                <button type="submit" name="add_purchase" class="btn btn-primary">
                                    <i class="bi bi-save"></i> Save & Complete
                                </button>
                                <button type="submit" name="add_purchase" value="draft" class="btn btn-warning" id="saveDraft">
                                    <i class="bi bi-pencil-square"></i> Save as Draft
                                </button>
                                <button type="reset" class="btn btn-secondary">
                                    <i class="bi bi-arrow-counterclockwise"></i> Reset
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <div class="row mt-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header bg-success text-white">
                        <h5 class="mb-0"><i class="bi bi-clock-history"></i> Recent Purchases</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered table-striped">
                                <thead class="table-dark">
                                    <tr>
                                        <th>Date</th>
                                        <th>Invoice #</th>
                                        <th>Supplier</th>
                                        <th>Stock Items</th>
                                        <th>Operational</th>
                                        <th>Total</th>
                                        <th>Status</th>
                                        <th>Created By</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while($purchase = mysqli_fetch_assoc($purchases)): ?>
                                    <tr>
                                        <td><?php echo date('d-m-Y', strtotime($purchase['purchase_date'])); ?></td>
                                        <td><strong><?php echo htmlspecialchars($purchase['invoice_number']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($purchase['supplier_name']); ?></td>
                                        <td>₹<?php echo number_format($purchase['total_amount'], 2); ?></td>
                                        <td>₹<?php echo number_format($purchase['operational_total'] ?? 0, 2); ?></td>
                                        <td><strong>₹<?php echo number_format(($purchase['total_amount'] + ($purchase['operational_total'] ?? 0)), 2); ?></strong></td>
                                        <td>
                                            <?php if($purchase['status'] == 'draft'): ?>
                                                <span class="status-badge status-draft">Draft</span>
                                            <?php else: ?>
                                                <span class="status-badge status-completed">Completed</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($purchase['username']); ?></td>
                                        <td>
                                            <?php if($purchase['status'] == 'draft'): ?>
                                                <a href="purchase_edit.php?id=<?php echo $purchase['id']; ?>" class="btn btn-sm btn-warning">
                                                    <i class="bi bi-pencil"></i> Edit
                                                </a>
                                                <button type="button" class="btn btn-sm btn-success" 
                                                        onclick="completePurchase(<?php echo $purchase['id']; ?>, '<?php echo htmlspecialchars($purchase['invoice_number']); ?>')">
                                                    <i class="bi bi-check-circle"></i> Complete
                                                </button>
                                            <?php else: ?>
                                                <a href="purchase_view.php?id=<?php echo $purchase['id']; ?>" class="btn btn-sm btn-info">
                                                    <i class="bi bi-eye"></i> View
                                                </a>
                                            <?php endif; ?>
                                        </td>
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

    <!-- Complete Purchase Modal -->
    <div class="modal fade" id="completePurchaseModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title">Complete Purchase</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="purchase_id" id="complete_purchase_id">
                        <p>Are you sure you want to complete this purchase? This will update the stock quantities.</p>
                        <p><strong>Invoice: <span id="complete_invoice"></span></strong></p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="complete_purchase" class="btn btn-success">Complete Purchase</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Include jQuery and Select2 -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
    // Parts data by category from PHP
    const partsByCategory = <?php echo json_encode($parts_by_category); ?>;

    // Function to filter parts based on selected category
    function filterPartsByCategory(categorySelect) {
        const row = categorySelect.closest('tr');
        const partSelect = row.querySelector('.part-select');
        const category = categorySelect.value;
        
        // Clear existing options
        partSelect.innerHTML = '';
        
        if (category) {
            // Add default option
            const defaultOption = document.createElement('option');
            defaultOption.value = '';
            defaultOption.textContent = 'Select Part';
            partSelect.appendChild(defaultOption);
            
            // Add parts for selected category
            const parts = partsByCategory[category] || [];
            parts.forEach(part => {
                const option = document.createElement('option');
                option.value = part.id;
                option.textContent = part.part_name;
                option.dataset.price = part.unit_price;
                option.dataset.stock = part.current_stock;
                partSelect.appendChild(option);
            });
            
            // Enable part select and dependent fields
            partSelect.disabled = false;
            row.querySelector('.quantity').disabled = false;
            row.querySelector('.purchase-price').disabled = false;
            row.querySelector('.selling-price').disabled = false;
        } else {
            // Disable part select and dependent fields
            partSelect.disabled = true;
            row.querySelector('.quantity').disabled = true;
            row.querySelector('.purchase-price').disabled = true;
            row.querySelector('.selling-price').disabled = true;
            
            // Clear values
            row.querySelector('.quantity').value = '';
            row.querySelector('.purchase-price').value = '';
            row.querySelector('.selling-price').value = '';
        }
    }

    function completePurchase(id, invoice) {
        document.getElementById('complete_purchase_id').value = id;
        document.getElementById('complete_invoice').textContent = invoice;
        new bootstrap.Modal(document.getElementById('completePurchaseModal')).show();
    }

    // Handle expense category selection
    function handleExpenseCategory(select) {
        const row = select.closest('.expense-row');
        const descriptionInput = row.querySelector('.expense-description');
        
        const placeholders = {
            'Stationery': 'e.g., Register, Pen, Stapler, Files',
            'Cleaning': 'e.g., Cleaning liquid, Cloth, Broom',
            'Refreshment': 'e.g., Tea/Coffee, Snacks, Water',
            'Petty Cash': 'e.g., Auto fare, Tea for customer, etc.',
            'Maintenance': 'e.g., Tool repair, Equipment maintenance',
            'Other': 'Enter specific description'
        };
        
        descriptionInput.placeholder = placeholders[select.value] || 'Enter item description';
    }

    // Calculate summary totals
    function updateSummaryTotals() {
        const stockTotal = parseFloat(document.getElementById('grandTotal').value.replace('₹', '')) || 0;
        const expenseTotal = parseFloat(document.getElementById('expensesGrandTotal').value.replace('₹', '')) || 0;
        
        document.getElementById('summaryStockTotal').textContent = '₹' + stockTotal.toFixed(2);
        document.getElementById('summaryExpenseTotal').textContent = '₹' + expenseTotal.toFixed(2);
        document.getElementById('summaryGrandTotal').textContent = '₹' + (stockTotal + expenseTotal).toFixed(2);
    }

    document.addEventListener('DOMContentLoaded', function() {
        // Initialize Select2 on all part selects
        $('.part-select').select2({
            placeholder: "Search for a part...",
            allowClear: true,
            width: '100%'
        });

        // Save as Draft button handler
        document.getElementById('saveDraft').addEventListener('click', function(e) {
            e.preventDefault();
            
            // Remove required attributes for draft save
            document.querySelectorAll('.part-select, .quantity, .purchase-price, .selling-price').forEach(field => {
                field.required = false;
            });
            
            // Create hidden input to indicate draft
            const draftInput = document.createElement('input');
            draftInput.type = 'hidden';
            draftInput.name = 'save_as_draft';
            draftInput.value = '1';
            document.getElementById('purchaseForm').appendChild(draftInput);
            
            // Submit form
            document.getElementById('purchaseForm').submit();
        });

        // Regular submit handler
        document.getElementById('purchaseForm').addEventListener('submit', function(e) {
            // Check if it's not a draft save
            if (!e.submitter || e.submitter.id !== 'saveDraft') {
                const rows = document.querySelectorAll('#itemsTable tbody tr');
                let hasValidItems = false;
                
                rows.forEach(row => {
                    const partSelect = row.querySelector('.part-select');
                    const quantity = parseFloat(row.querySelector('.quantity').value) || 0;
                    
                    if (partSelect.value && quantity > 0) {
                        hasValidItems = true;
                    }
                });
                
                if (!hasValidItems) {
                    const expenseRows = document.querySelectorAll('#expenseItemsTable tbody tr');
                    let hasValidExpenses = false;
                    
                    expenseRows.forEach(row => {
                        const desc = row.querySelector('.expense-description').value;
                        const quantity = parseFloat(row.querySelector('.expense-quantity').value) || 0;
                        
                        if (desc && quantity > 0) {
                            hasValidExpenses = true;
                        }
                    });
                    
                    if (!hasValidItems && !hasValidExpenses) {
                        e.preventDefault();
                        alert('Please add at least one valid item (stock or operational) to complete the purchase');
                        return false;
                    }
                }
            }
        });

        // Add new stock row
        document.getElementById('addRow').addEventListener('click', function() {
            const tbody = document.querySelector('#itemsTable tbody');
            const newRow = tbody.rows[0].cloneNode(true);
            
            // Clear input values
            newRow.querySelectorAll('input').forEach(input => {
                if (input.type !== 'button') {
                    input.value = '';
                }
            });
            
            // Reset selects
            const categorySelect = newRow.querySelector('.category-select');
            categorySelect.selectedIndex = 0;
            
            const partSelect = newRow.querySelector('.part-select');
            partSelect.innerHTML = '<option value="">First select category</option>';
            partSelect.disabled = true;
            
            // Disable dependent fields
            newRow.querySelector('.quantity').disabled = true;
            newRow.querySelector('.purchase-price').disabled = true;
            newRow.querySelector('.selling-price').disabled = true;
            
            // Add remove button event
            const removeBtn = newRow.querySelector('.remove-row');
            removeBtn.addEventListener('click', function() {
                if (tbody.rows.length > 1) {
                    this.closest('tr').remove();
                    calculateGrandTotal();
                    updateSummaryTotals();
                }
            });
            
            tbody.appendChild(newRow);
            
            // Initialize Select2 on new part select
            $(partSelect).select2({
                placeholder: "Search for a part...",
                allowClear: true,
                width: '100%'
            });
        });
        
        // Add new expense row
        document.getElementById('addExpenseRow').addEventListener('click', function() {
            const tbody = document.querySelector('#expenseItemsTable tbody');
            const newRow = tbody.rows[0].cloneNode(true);
            
            // Clear input values
            newRow.querySelectorAll('input').forEach(input => {
                if (input.type !== 'button') {
                    input.value = '';
                }
            });
            
            // Reset select
            newRow.querySelector('.expense-category-select').selectedIndex = 0;
            
            // Set default quantity to 1
            newRow.querySelector('.expense-quantity').value = '1';
            
            // Add remove button event
            const removeBtn = newRow.querySelector('.remove-expense');
            removeBtn.addEventListener('click', function() {
                if (tbody.rows.length > 1) {
                    this.closest('tr').remove();
                    calculateExpensesGrandTotal();
                    updateSummaryTotals();
                }
            });
            
            tbody.appendChild(newRow);
        });
        
        // Remove stock row
        document.addEventListener('click', function(e) {
            if (e.target.classList.contains('remove-row') || e.target.closest('.remove-row')) {
                const tbody = document.querySelector('#itemsTable tbody');
                if (tbody.rows.length > 1) {
                    e.target.closest('tr').remove();
                    calculateGrandTotal();
                    updateSummaryTotals();
                }
            }
        });
        
        // Remove expense row
        document.addEventListener('click', function(e) {
            if (e.target.classList.contains('remove-expense') || e.target.closest('.remove-expense')) {
                const tbody = document.querySelector('#expenseItemsTable tbody');
                if (tbody.rows.length > 1) {
                    e.target.closest('tr').remove();
                    calculateExpensesGrandTotal();
                    updateSummaryTotals();
                }
            }
        });
        
        // Handle part selection to auto-fill selling price
        document.addEventListener('change', function(e) {
            if (e.target.classList.contains('part-select')) {
                const selected = e.target.options[e.target.selectedIndex];
                const row = e.target.closest('tr');
                
                if (selected.value) {
                    const price = selected.dataset.price;
                    if (price) {
                        row.querySelector('.selling-price').value = price;
                    }
                }
            }
        });
        
        // Calculate stock row total
        document.addEventListener('input', function(e) {
            if (e.target.classList.contains('quantity') || e.target.classList.contains('purchase-price')) {
                const row = e.target.closest('tr');
                const quantity = parseFloat(row.querySelector('.quantity').value) || 0;
                const price = parseFloat(row.querySelector('.purchase-price').value) || 0;
                const total = quantity * price;
                row.querySelector('.row-total').value = total.toFixed(2);
                calculateGrandTotal();
                updateSummaryTotals();
            }
        });
        
        // Calculate expense row total
        document.addEventListener('input', function(e) {
            if (e.target.classList.contains('expense-quantity') || e.target.classList.contains('expense-price')) {
                const row = e.target.closest('.expense-row');
                const quantity = parseFloat(row.querySelector('.expense-quantity').value) || 0;
                const price = parseFloat(row.querySelector('.expense-price').value) || 0;
                const total = quantity * price;
                row.querySelector('.expense-row-total').value = '₹' + total.toFixed(2);
                calculateExpensesGrandTotal();
                updateSummaryTotals();
            }
        });
        
        function calculateGrandTotal() {
            let grandTotal = 0;
            document.querySelectorAll('.row-total').forEach(input => {
                grandTotal += parseFloat(input.value) || 0;
            });
            document.getElementById('grandTotal').value = '₹' + grandTotal.toFixed(2);
        }
        
        function calculateExpensesGrandTotal() {
            let grandTotal = 0;
            document.querySelectorAll('.expense-row-total').forEach(input => {
                const value = parseFloat(input.value.replace('₹', '')) || 0;
                grandTotal += value;
            });
            document.getElementById('expensesGrandTotal').value = '₹' + grandTotal.toFixed(2);
            document.getElementById('operationalExpensesTotal').value = grandTotal.toFixed(2);
        }
    });
    </script>
</body>
</html>