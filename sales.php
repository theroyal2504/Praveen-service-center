<?php
require_once 'config.php';

if (!isLoggedIn()) {
    redirect('index.php');
}

// Generate new invoice number
$invoice_number = generateInvoiceNumber($conn);

// Handle save draft
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_draft'])) {
    $draft_data = [
        'customer_id' => $_POST['customer_id'] ?? '',
        'vehicle_registration' => $_POST['vehicle_registration'] ?? '',
        'sale_date' => $_POST['sale_date'],
        'invoice_number' => $_POST['invoice_number'],
        'payment_method' => $_POST['payment_method'],
        'paid_amount' => floatval($_POST['paid_amount'] ?? 0),
        'discount_type' => $_POST['discount_type'] ?? 'fixed',
        'discount_value' => floatval($_POST['discount_value'] ?? 0),
        'items' => []
    ];
    
    // Save items
    $part_ids = $_POST['part_id'] ?? [];
    $quantities = $_POST['quantity'] ?? [];
    $selling_prices = $_POST['selling_price'] ?? [];
    
    for ($i = 0; $i < count($part_ids); $i++) {
        if (!empty($part_ids[$i]) && !empty($quantities[$i]) && $quantities[$i] > 0) {
            $draft_data['items'][] = [
                'part_id' => $part_ids[$i],
                'quantity' => intval($quantities[$i]),
                'selling_price' => floatval($selling_prices[$i])
            ];
        }
    }
    
    // New customer data if any
    if (isset($_POST['new_customer']) && $_POST['new_customer'] == '1') {
        $draft_data['new_customer'] = [
            'name' => $_POST['new_customer_name'] ?? '',
            'phone' => $_POST['new_customer_phone'] ?? '',
            'vehicle' => $_POST['new_vehicle_registration'] ?? ''
        ];
    }
    
    // Save to session
    $_SESSION['sale_draft'] = $draft_data;
    $_SESSION['success'] = "Sale saved as draft. You can continue later.";
    redirect('sales.php?draft=1');
}

// Handle load draft
if (isset($_GET['load_draft'])) {
    if (isset($_SESSION['sale_draft'])) {
        $draft = $_SESSION['sale_draft'];
        // Draft will be loaded via JavaScript
        $_SESSION['info'] = "Draft loaded. Complete the sale or save again.";
    } else {
        $_SESSION['error'] = "No draft found.";
    }
    redirect('sales.php');
}

// Handle clear draft
if (isset($_GET['clear_draft'])) {
    unset($_SESSION['sale_draft']);
    $_SESSION['success'] = "Draft cleared.";
    redirect('sales.php');
}

// Handle new sale
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_sale'])) {
    $customer_id = $_POST['customer_id'] ?: 'NULL';
    $vehicle_registration = strtoupper(mysqli_real_escape_string($conn, $_POST['vehicle_registration'] ?? ''));
    $sale_date = $_POST['sale_date'];
    $invoice_number = mysqli_real_escape_string($conn, $_POST['invoice_number']);
    $payment_method = $_POST['payment_method'];
    // Store discount in database
    $discount_type = $_POST['discount_type'] ?? 'fixed';
    $discount_value = floatval($_POST['discount_value'] ?? 0);
    $paid_amount = floatval($_POST['paid_amount'] ?? 0);
    $created_by = $_SESSION['user_id'];
    
    // Start transaction
    mysqli_begin_transaction($conn);
    
    try {
        // If new customer is being added
        if (isset($_POST['new_customer']) && $_POST['new_customer'] == '1') {
            $customer_name = mysqli_real_escape_string($conn, $_POST['new_customer_name']);
            $phone = mysqli_real_escape_string($conn, $_POST['new_customer_phone']);
            $vehicle_reg = strtoupper(mysqli_real_escape_string($conn, $_POST['new_vehicle_registration'] ?? ''));
            
            $customer_query = "INSERT INTO customers (customer_name, phone, vehicle_registration) 
                              VALUES ('$customer_name', '$phone', '$vehicle_reg')";
            mysqli_query($conn, $customer_query);
            $customer_id = mysqli_insert_id($conn);
            $vehicle_registration = $vehicle_reg;
        }
        
        // Insert sale - NOW STORING DISCOUNT
        $query = "INSERT INTO sales (
            customer_id, 
            sale_date, 
            invoice_number, 
            payment_method, 
            paid_amount, 
            discount_type,
            discount_value,
            created_by
        ) VALUES (
            $customer_id, 
            '$sale_date', 
            '$invoice_number', 
            '$payment_method', 
            $paid_amount, 
            '$discount_type',
            $discount_value,
            $created_by
        )";
        mysqli_query($conn, $query);
        $sale_id = mysqli_insert_id($conn);
        
        // Insert sale items
        $part_ids = $_POST['part_id'];
        $quantities = $_POST['quantity'];
        $selling_prices = $_POST['selling_price'];
        
        $total_amount = 0;
        $out_of_stock_items = [];
        
        for ($i = 0; $i < count($part_ids); $i++) {
            if (!empty($part_ids[$i]) && $quantities[$i] > 0) {
                $part_id = $part_ids[$i];
                $quantity = intval($quantities[$i]);
                $selling_price = floatval($selling_prices[$i]);
                
                // Check stock
                $stock_check = mysqli_query($conn, "SELECT quantity FROM stock WHERE part_id = $part_id");
                $stock = mysqli_fetch_assoc($stock_check);
                
                if ($stock['quantity'] >= $quantity) {
                    $item_query = "INSERT INTO sale_items (sale_id, part_id, quantity, selling_price) 
                                  VALUES ($sale_id, $part_id, $quantity, $selling_price)";
                    mysqli_query($conn, $item_query);
                    
                    $total_amount += $quantity * $selling_price;
                    
                    // Update stock
                    mysqli_query($conn, "UPDATE stock SET quantity = quantity - $quantity WHERE part_id = $part_id");
                } else {
                    // Get part name for better error message
                    $part_query = mysqli_query($conn, "SELECT part_name FROM parts_master WHERE id = $part_id");
                    $part_data = mysqli_fetch_assoc($part_query);
                    $out_of_stock_items[] = $part_data['part_name'] . " (Available: " . $stock['quantity'] . ", Requested: " . $quantity . ")";
                }
            }
        }
        
        // If there are out of stock items, throw exception with details
        if (!empty($out_of_stock_items)) {
            throw new Exception("Insufficient stock for following items:\n- " . implode("\n- ", $out_of_stock_items) . "\n\nYou can save this as draft and purchase items first.");
        }
        
        // Calculate discount amount
        $discount_amount = 0;
        if ($discount_type == 'percentage') {
            $discount_amount = ($total_amount * $discount_value) / 100;
        } else {
            $discount_amount = $discount_value;
        }
        
        // Ensure discount doesn't exceed total
        if ($discount_amount > $total_amount) {
            $discount_amount = $total_amount;
        }
        
        // Calculate Grand Total after discount
        $grand_total = $total_amount - $discount_amount;
        
        // Calculate due amount based on Grand Total
        $due_amount = $grand_total - $paid_amount;
        
        // Determine payment status based on due amount
        $payment_status = 'paid';
        if ($due_amount > 0) {
            $payment_status = 'partial';
        } elseif ($due_amount < 0) {
            // If paid amount exceeds Grand Total, adjust paid amount
            $paid_amount = $grand_total;
            $due_amount = 0;
            $payment_status = 'paid';
        }
        
        // Update sale with all calculated values
        $update_query = "UPDATE sales SET 
                        subtotal = $total_amount,
                        total_amount = $grand_total,
                        discount_amount = $discount_amount,
                        grand_total = $grand_total,
                        paid_amount = $paid_amount,
                        due_amount = $due_amount,
                        payment_status = '$payment_status' 
                        WHERE id = $sale_id";
        mysqli_query($conn, $update_query);
        
        // Record initial payment if any
        if ($paid_amount > 0) {
            $payment_query = "INSERT INTO sale_payments (sale_id, payment_amount, payment_method, received_by) 
                             VALUES ($sale_id, $paid_amount, '$payment_method', $created_by)";
            mysqli_query($conn, $payment_query);
        }
        
        // Update customer's vehicle registration if provided
        if ($vehicle_registration && $customer_id != 'NULL') {
            mysqli_query($conn, "UPDATE customers SET vehicle_registration = '$vehicle_registration' 
                                WHERE id = $customer_id AND (vehicle_registration IS NULL OR vehicle_registration = '')");
        }
        
        mysqli_commit($conn);
        
        // Clear draft after successful sale
        unset($_SESSION['sale_draft']);
        
        $_SESSION['success'] = "Sale completed successfully! Invoice: $invoice_number (Grand Total: ₹" . number_format($grand_total, 2) . ", Paid: ₹" . number_format($paid_amount, 2) . ", Due: ₹" . number_format($due_amount, 2) . ")";
        redirect("sale_view.php?id=$sale_id");
        
    } catch (Exception $e) {
        mysqli_rollback($conn);
        $_SESSION['error'] = "Error: " . $e->getMessage();
        redirect('sales.php');
    }
}

// Rest of your existing code remains the same...
// Fetch categories
$categories = mysqli_query($conn, "SELECT * FROM categories ORDER BY category_name");

// Fetch parts with category information and latest selling price
$parts_query = "SELECT 
                    p.*, 
                    s.quantity as current_stock, 
                    c.category_name, 
                    c.id as category_id,
                    (
                        SELECT pi.selling_price 
                        FROM purchase_items pi 
                        WHERE pi.part_id = p.id 
                        ORDER BY pi.id DESC 
                        LIMIT 1
                    ) as latest_selling_price
                FROM parts_master p 
                JOIN stock s ON p.id = s.part_id 
                LEFT JOIN categories c ON p.category_id = c.id
                WHERE s.quantity > 0 
                ORDER BY c.category_name, p.part_name";
$parts = mysqli_query($conn, $parts_query);

// Fetch customers
$customers = mysqli_query($conn, "SELECT c.*, bm.model_name, bc.name as company_name 
                                  FROM customers c
                                  LEFT JOIN bike_models bm ON c.vehicle_model_id = bm.id
                                  LEFT JOIN bike_companies bc ON bm.company_id = bc.id
                                  ORDER BY c.customer_name");

// Fetch recent sales with vehicle info - Use grand_total for display
$sales = mysqli_query($conn, "SELECT s.*, 
                              c.customer_name, 
                              c.vehicle_registration, 
                              u.username,
                              COALESCE(s.grand_total, s.total_amount) as display_total,
                              (SELECT COUNT(*) FROM sale_payments WHERE sale_id = s.id) as payment_count
                              FROM sales s 
                              LEFT JOIN customers c ON s.customer_id = c.id 
                              LEFT JOIN users u ON s.created_by = u.id 
                              ORDER BY s.sale_date DESC LIMIT 50");

// Build parts map for JavaScript
$parts_map = [];
$categories_list = [];
mysqli_data_seek($parts, 0);
while($p = mysqli_fetch_assoc($parts)) {
    $cat = $p['category_name'] ?? 'Uncategorized';
    if (!isset($parts_map[$cat])) {
        $parts_map[$cat] = [];
    }
    // Use latest selling price if available
    $p['selling_price'] = $p['latest_selling_price'] ?? $p['unit_price'];
    $parts_map[$cat][] = $p;
    if (!in_array($cat, $categories_list)) {
        $categories_list[] = $cat;
    }
}

// Get draft data if exists
$draft_data = isset($_SESSION['sale_draft']) ? $_SESSION['sale_draft'] : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sales - Bike Management System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <style>
        .editable-paid {
            background-color: #fff3cd;
            border: 2px solid #ffc107;
            font-weight: bold;
        }
        .discount-section {
            background-color: #e8f4f8;
            border: 2px solid #17a2b8;
            border-radius: 8px;
            padding: 15px;
            margin: 10px 0;
        }
        .discount-input {
            border: 2px solid #17a2b8;
            font-weight: bold;
        }
        .grand-total {
            font-size: 1.2em;
            font-weight: bold;
            background-color: #cce5ff;
        }
        .final-total {
            font-size: 1.2em;
            font-weight: bold;
            background-color: #d4edda;
        }
        .item-row {
            transition: all 0.3s ease;
        }
        .item-row:hover {
            background-color: #f5f5f5;
        }
        .calculation-row {
            border-top: 2px solid #dee2e6;
            margin-top: 10px;
            padding-top: 10px;
        }
        .category-badge {
            background-color: #6c757d;
            color: white;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 0.8em;
            margin-left: 5px;
        }
        .discount-note {
            font-size: 0.8em;
            color: #6c757d;
            margin-top: 5px;
        }
        .draft-badge {
            background-color: #ffc107;
            color: #000;
            padding: 5px 10px;
            border-radius: 4px;
            font-weight: bold;
        }
        .out-of-stock-warning {
            color: #dc3545;
            font-size: 0.9em;
            margin-top: 5px;
        }
        .draft-actions {
            background-color: #fff3cd;
            border: 1px solid #ffeeba;
            border-radius: 8px;
            padding: 15px;
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

        <?php if (isset($_SESSION['info'])): ?>
            <div class="alert alert-info alert-dismissible fade show" role="alert">
                <?php 
                echo $_SESSION['info']; 
                unset($_SESSION['info']);
                ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Draft Actions -->
        <?php if ($draft_data): ?>
        <div class="draft-actions">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h5 class="mb-2"><i class="bi bi-save"></i> Draft Sale Available</h5>
                    <p class="mb-0">
                        <span class="draft-badge"><i class="bi bi-calendar"></i> <?php echo date('d-m-Y H:i', strtotime($draft_data['sale_date'] ?? 'now')); ?></span>
                        <span class="badge bg-secondary">Items: <?php echo count($draft_data['items'] ?? []); ?></span>
                        <span class="badge bg-info">Invoice: <?php echo htmlspecialchars($draft_data['invoice_number'] ?? ''); ?></span>
                    </p>
                </div>
                <div class="col-md-4 text-end">
                    <a href="?load_draft=1" class="btn btn-warning">
                        <i class="bi bi-arrow-repeat"></i> Load Draft
                    </a>
                    <a href="?clear_draft=1" class="btn btn-danger" onclick="return confirm('Clear this draft?')">
                        <i class="bi bi-x-circle"></i> Clear
                    </a>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="bi bi-cart-plus"></i> New Sale / Billing</h5>
                        <div>
                            <button type="button" class="btn btn-warning btn-sm" id="checkStockBtn">
                                <i class="bi bi-exclamation-triangle"></i> Check Stock Availability
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <form method="POST" id="saleForm">
                            <!-- Customer Selection Section -->
                            <div class="row mb-2">
                                <div class="col-12">
                                    <div class="card bg-light" style="margin-bottom:0;">
                                        <div class="card-header">
                                            <ul class="nav nav-tabs card-header-tabs" id="customerTab" role="tablist">
                                                <li class="nav-item" role="presentation">
                                                    <a class="nav-link active" id="existing-customer-tab" data-bs-toggle="tab" href="#existing-customer" role="tab">Existing Customer</a>
                                                </li>
                                                <li class="nav-item" role="presentation">
                                                    <a class="nav-link" id="new-customer-tab" data-bs-toggle="tab" href="#new-customer" role="tab">New Customer</a>
                                                </li>
                                            </ul>
                                        </div>
                                        <div class="card-body p-2">
                                            <div class="tab-content">
                                                <!-- Existing Customer Tab -->
                                                <div class="tab-pane fade show active" id="existing-customer" role="tabpanel">
                                                    <div class="row">
                                                        <div class="col-md-8">
                                                            <div class="mb-2">
                                                                <label for="customer_id" class="form-label">Select Customer</label>
                                                                <select class="form-control form-control-sm" id="customer_id" name="customer_id" onchange="loadCustomerVehicle()">
                                                                    <option value="">Walk-in Customer</option>
                                                                    <?php 
                                                                    mysqli_data_seek($customers, 0);
                                                                    while($customer = mysqli_fetch_assoc($customers)): 
                                                                    ?>
                                                                    <option value="<?php echo $customer['id']; ?>" 
                                                                            data-vehicle="<?php echo htmlspecialchars($customer['vehicle_registration']); ?>"
                                                                            data-name="<?php echo htmlspecialchars($customer['customer_name']); ?>"
                                                                            data-phone="<?php echo htmlspecialchars($customer['phone']); ?>">
                                                                        <?php echo htmlspecialchars($customer['customer_name']); ?> - <?php echo $customer['phone']; ?>
                                                                        <?php if($customer['vehicle_registration']): ?>
                                                                            [<?php echo $customer['vehicle_registration']; ?>]
                                                                        <?php endif; ?>
                                                                    </option>
                                                                    <?php endwhile; ?>
                                                                </select>
                                                            </div>
                                                        </div>
                                                        <div class="col-md-4">
                                                            <div class="mb-2">
                                                                <label for="existing_vehicle" class="form-label">Vehicle Registration</label>
                                                                <input type="text" class="form-control form-control-sm" id="existing_vehicle" name="vehicle_registration" 
                                                                       placeholder="Enter manually if different" style="text-transform:uppercase">
                                                                <small class="text-muted">Auto-fills from selected customer</small>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div id="customerVehicleInfo" class="alert alert-info" style="display:none;">
                                                        <i class="bi bi-bicycle"></i> <span id="customerVehicleDetails"></span>
                                                    </div>
                                                </div>
                                                
                                                <!-- New Customer Tab -->
                                                <div class="tab-pane fade" id="new-customer" role="tabpanel">
                                                    <div class="row">
                                                        <div class="col-md-4">
                                                            <div class="mb-2">
                                                                <label for="new_customer_name" class="form-label">Customer Name *</label>
                                                                <input type="text" class="form-control form-control-sm" id="new_customer_name" name="new_customer_name">
                                                            </div>
                                                        </div>
                                                        <div class="col-md-3">
                                                            <div class="mb-2">
                                                                <label for="new_customer_phone" class="form-label">Phone Number *</label>
                                                                <input type="text" class="form-control form-control-sm" id="new_customer_phone" name="new_customer_phone">
                                                            </div>
                                                        </div>
                                                        <div class="col-md-3">
                                                            <div class="mb-2">
                                                                <label for="new_vehicle_registration" class="form-label">Vehicle Registration</label>
                                                                <input type="text" class="form-control form-control-sm" id="new_vehicle_registration" name="new_vehicle_registration" 
                                                                       placeholder="e.g., MH12AB1234" style="text-transform:uppercase">
                                                            </div>
                                                        </div>
                                                        <div class="col-md-2">
                                                            <div class="mb-2">
                                                                <label class="form-label">&nbsp;</label>
                                                                <div class="form-check">
                                                                    <input class="form-check-input" type="checkbox" id="new_customer" name="new_customer" value="1">
                                                                    <label class="form-check-label" for="new_customer">
                                                                        Add as new
                                                                    </label>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <small class="text-muted">Check the box to add as new customer in database</small>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Invoice Details -->
                            <div class="row mb-3">
                                <div class="col-md-3">
                                    <div class="mb-3">
                                        <label for="sale_date" class="form-label">Sale Date</label>
                                        <input type="date" class="form-control" id="sale_date" name="sale_date" value="<?php echo date('Y-m-d'); ?>" required>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="mb-3">
                                        <label for="invoice_number" class="form-label">Invoice Number</label>
                                        <input type="text" class="form-control" id="invoice_number" name="invoice_number" value="<?php echo $invoice_number; ?>" readonly class="bg-light">
                                        <small class="text-muted">Auto-generated</small>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="mb-3">
                                        <label for="payment_method" class="form-label">Payment Method</label>
                                        <select class="form-control" id="payment_method" name="payment_method" required>
                                            <option value="cash">Cash</option>
                                            <option value="card">Card</option>
                                            <option value="online">Online</option>
                                            <option value="bank_transfer">Bank Transfer</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="mb-3">
                                        <label for="paid_amount" class="form-label">Paid Amount (₹)</label>
                                        <input type="number" step="0.01" class="form-control" id="paid_amount" name="paid_amount" value="0" min="0">
                                        <small class="text-muted">Amount customer is paying</small>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Items Table -->
                            <div class="table-responsive">
                                <table class="table table-bordered" id="itemsTable">
                                    <thead class="table-dark">
                                        <tr>
                                            <th width="30%">Part <span class="category-badge">Grouped by Category</span></th>
                                            <th width="10%">Available Stock</th>
                                            <th width="10%">Quantity</th>
                                            <th width="15%">Selling Price (₹)</th>
                                            <th width="15%">Total (₹)</th>
                                            <th width="10%">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr class="item-row">
                                            <td>
                                                <div class="d-flex align-items-center gap-2">
                                                    <select class="form-control form-control-sm category-select" style="flex:0 0 45%;">
                                                        <option value="">Category</option>
                                                        <?php foreach($categories_list as $cat): ?>
                                                            <option value="<?php echo htmlspecialchars($cat); ?>"><?php echo htmlspecialchars($cat); ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                    <select class="form-control form-control-sm part-select" name="part_id[]" required disabled style="flex:1;">
                                                        <option value="">Part</option>
                                                    </select>
                                                </div>
                                            </td>
                                            <td><input type="text" class="form-control available-stock" readonly></td>
                                            <td><input type="number" class="form-control quantity" name="quantity[]" min="1" required disabled></td>
                                            <td><input type="number" step="0.01" class="form-control selling-price" name="selling_price[]" required disabled></td>
                                            <td><input type="text" class="form-control row-total" readonly></td>
                                            <td><button type="button" class="btn btn-danger btn-sm remove-row"><i class="bi bi-trash"></i></button></td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            
                            <!-- Stock Warning Area -->
                            <div id="stockWarning" class="alert alert-warning" style="display: none;">
                                <i class="bi bi-exclamation-triangle"></i> <span id="stockWarningMessage"></span>
                            </div>
                            
                            <!-- Calculation Section with Discount -->
                            <div class="row calculation-row">
                                <div class="col-md-6">
                                    <button type="button" class="btn btn-success" id="addRow">
                                        <i class="bi bi-plus-circle"></i> Add Another Item
                                    </button>
                                    <button type="button" class="btn btn-info" id="calculateTotal">
                                        <i class="bi bi-calculator"></i> Calculate Total
                                    </button>
                                    <button type="submit" name="save_draft" class="btn btn-warning" id="saveDraft">
                                        <i class="bi bi-save"></i> Save as Draft
                                    </button>
                                </div>
                                <div class="col-md-6">
                                    <div class="card">
                                        <div class="card-body">
                                            <table class="table table-sm table-borderless mb-0">
                                                <tr>
                                                    <td width="50%"><strong>Sub Total:</strong></td>
                                                    <td><input type="text" class="form-control grand-total" id="subTotal" readonly value="0.00" style="font-weight:bold; background-color:#e3f2fd; text-align:right;"></td>
                                                </tr>
                                                
                                                <!-- Discount Section (Now stored in database) -->
                                                <tr class="discount-section">
                                                    <td colspan="2" class="p-2">
                                                        <div class="row">
                                                            <div class="col-5">
                                                                <select class="form-control form-control-sm" id="discount_type" name="discount_type">
                                                                    <option value="fixed">Fixed (₹)</option>
                                                                    <option value="percentage">Percentage (%)</option>
                                                                </select>
                                                            </div>
                                                            <div class="col-4">
                                                                <input type="number" step="0.01" class="form-control form-control-sm discount-input" id="discount_value" name="discount_value" value="0" min="0">
                                                            </div>
                                                            <div class="col-3">
                                                                <span class="badge bg-info p-2" id="discount_display">₹0</span>
                                                            </div>
                                                        </div>
                                                        <small class="text-muted">Discount will be stored in database</small>
                                                    </td>
                                                </tr>
                                                
                                                <tr>
                                                    <td><strong>Grand Total (after discount):</strong></td>
                                                    <td><input type="text" class="form-control grand-total" id="grandTotal" readonly value="0.00" style="font-weight:bold; background-color:#cce5ff; text-align:right;"></td>
                                                </tr>
                                                <tr>
                                                    <td><strong>Paid Amount:</strong></td>
                                                    <td><input type="text" class="form-control" id="displayPaidAmount" readonly value="0.00" style="font-weight:bold; color:#28a745; text-align:right;"></td>
                                                </tr>
                                                <tr>
                                                    <td><strong>Due Amount:</strong></td>
                                                    <td><input type="text" class="form-control" id="dueAmount" readonly value="0.00" style="font-weight: bold; text-align:right;"></td>
                                                </tr>
                                            </table>
                                            <div class="discount-note text-center mt-2">
                                                <i class="bi bi-info-circle"></i> Note: Discount is now stored in database for accurate reporting
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row mt-3">
                                <div class="col-12 text-center">
                                    <button type="submit" name="add_sale" class="btn btn-primary btn-lg" id="completeSale">
                                        <i class="bi bi-cash"></i> Complete Sale & Generate Invoice
                                    </button>
                                    <button type="reset" class="btn btn-secondary btn-lg">
                                        <i class="bi bi-arrow-counterclockwise"></i> Reset Form
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recent Sales Section -->
        <div class="row mt-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header bg-success text-white">
                        <h5 class="mb-0"><i class="bi bi-clock-history"></i> Recent Sales</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered table-striped table-hover">
                                <thead class="table-dark">
                                    <tr>
                                        <th>Date</th>
                                        <th>Invoice #</th>
                                        <th>Customer</th>
                                        <th>Vehicle</th>
                                        <th>Sub Total</th>
                                        <th>Discount</th>
                                        <th>Grand Total</th>
                                        <th>Paid</th>
                                        <th>Due</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    // Reset the sales pointer
                                    mysqli_data_seek($sales, 0);
                                    while($sale = mysqli_fetch_assoc($sales)): 
                                        // Get subtotal from sale items
                                        $subtotal_query = mysqli_query($conn, "SELECT SUM(quantity * selling_price) as subtotal 
                                                                              FROM sale_items WHERE sale_id = " . $sale['id']);
                                        $subtotal_data = mysqli_fetch_assoc($subtotal_query);
                                        $subtotal = $subtotal_data['subtotal'] ?? $sale['total_amount'];
                                        
                                        // Get discount amount from sales table
                                        $discount_amount = $sale['discount_amount'] ?? 0;
                                        
                                        // Grand total is stored in total_amount (after discount)
                                        $grand_total = $sale['total_amount'];
                                        
                                        // Calculate due amount
                                        $due_amount = $grand_total - $sale['paid_amount'];
                                    ?>
                                    <tr>
                                        <td><?php echo date('d-m-Y', strtotime($sale['sale_date'])); ?></td>
                                        <td><strong><?php echo htmlspecialchars($sale['invoice_number']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($sale['customer_name'] ?? 'Walk-in'); ?></td>
                                        <td>
                                            <?php if($sale['vehicle_registration']): ?>
                                                <span class="badge bg-info"><?php echo $sale['vehicle_registration']; ?></span>
                                            <?php else: ?>
                                                -
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-secondary">₹<?php echo number_format($subtotal, 2); ?></td>
                                        <td class="text-info">
                                            <?php if($discount_amount > 0): ?>
                                                -₹<?php echo number_format($discount_amount, 2); ?>
                                                <?php if(isset($sale['discount_type']) && $sale['discount_type'] == 'percentage'): ?>
                                                    <br><small>(<?php echo $sale['discount_value']; ?>%)</small>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                -
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-primary"><strong>₹<?php echo number_format($grand_total, 2); ?></strong></td>
                                        <td class="text-success">₹<?php echo number_format($sale['paid_amount'], 2); ?></td>
                                        <td class="text-<?php echo $due_amount > 0 ? 'danger' : 'success'; ?>">
                                            ₹<?php echo number_format($due_amount, 2); ?>
                                        </td>
                                        <td>
                                            <?php 
                                            $status_class = 'success';
                                            $status_text = 'Paid';
                                            if($due_amount > 0 && $sale['paid_amount'] > 0) {
                                                $status_class = 'warning';
                                                $status_text = 'Partial';
                                            } elseif($due_amount == $grand_total && $grand_total > 0) {
                                                $status_class = 'danger';
                                                $status_text = 'Pending';
                                            }
                                            ?>
                                            <span class="badge bg-<?php echo $status_class; ?>">
                                                <?php echo $status_text; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="btn-group btn-group-sm">
                                                <a href="sale_view.php?id=<?php echo $sale['id']; ?>" class="btn btn-info" title="View">
                                                    <i class="bi bi-eye"></i>
                                                </a>
                                                <a href="invoice.php?id=<?php echo $sale['id']; ?>" class="btn btn-secondary" target="_blank" title="Print">
                                                    <i class="bi bi-printer"></i>
                                                </a>
                                            </div>
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

    <script>
    // Parts data
    var partsByCategory = <?php echo json_encode($parts_map); ?> || {};
    
    // Draft data
    var draftData = <?php echo json_encode($draft_data); ?>;

    function loadCustomerVehicle() {
        const select = document.getElementById('customer_id');
        const selected = select.options[select.selectedIndex];
        const vehicleInput = document.getElementById('existing_vehicle');
        const vehicleInfo = document.getElementById('customerVehicleInfo');
        const vehicleDetails = document.getElementById('customerVehicleDetails');
        
        if (selected.value && selected.dataset.vehicle) {
            vehicleInput.value = selected.dataset.vehicle;
            vehicleInfo.style.display = 'block';
            vehicleDetails.innerHTML = 'Vehicle: ' + selected.dataset.vehicle + ' for ' + selected.dataset.name;
        } else if (selected.value) {
            vehicleInput.value = '';
            vehicleInfo.style.display = 'none';
        } else {
            vehicleInput.value = '';
            vehicleInfo.style.display = 'none';
        }
        
        if (selected.value) {
            document.getElementById('new_customer_name').value = selected.dataset.name || '';
            document.getElementById('new_customer_phone').value = selected.dataset.phone || '';
        }
    }

    function checkStockAvailability() {
        let outOfStockItems = [];
        document.querySelectorAll('.item-row').forEach(row => {
            const partSelect = row.querySelector('.part-select');
            const quantity = parseInt(row.querySelector('.quantity').value) || 0;
            const availableStock = parseInt(row.querySelector('.available-stock').value) || 0;
            
            if (partSelect && partSelect.value && quantity > 0 && quantity > availableStock) {
                const partName = partSelect.options[partSelect.selectedIndex]?.text || 'Unknown';
                outOfStockItems.push(`${partName} (Available: ${availableStock}, Requested: ${quantity})`);
            }
        });
        
        const warningDiv = document.getElementById('stockWarning');
        const warningMsg = document.getElementById('stockWarningMessage');
        
        if (outOfStockItems.length > 0) {
            warningMsg.innerHTML = 'Out of stock items detected:<br>- ' + outOfStockItems.join('<br>- ');
            warningDiv.style.display = 'block';
        } else {
            warningDiv.style.display = 'none';
        }
    }

    function loadDraftData() {
        if (!draftData) return;
        
        // Load customer data
        if (draftData.customer_id) {
            document.getElementById('customer_id').value = draftData.customer_id;
            loadCustomerVehicle();
        }
        
        // Load vehicle registration
        if (draftData.vehicle_registration) {
            document.getElementById('existing_vehicle').value = draftData.vehicle_registration;
        }
        
        // Load date
        if (draftData.sale_date) {
            document.getElementById('sale_date').value = draftData.sale_date;
        }
        
        // Load payment method
        if (draftData.payment_method) {
            document.getElementById('payment_method').value = draftData.payment_method;
        }
        
        // Load paid amount
        if (draftData.paid_amount) {
            document.getElementById('paid_amount').value = draftData.paid_amount;
        }
        
        // Load discount
        if (draftData.discount_type) {
            document.getElementById('discount_type').value = draftData.discount_type;
        }
        if (draftData.discount_value) {
            document.getElementById('discount_value').value = draftData.discount_value;
        }
        
        // Load new customer data if any
        if (draftData.new_customer) {
            document.getElementById('new_customer').checked = true;
            document.getElementById('new_customer_name').value = draftData.new_customer.name || '';
            document.getElementById('new_customer_phone').value = draftData.new_customer.phone || '';
            document.getElementById('new_vehicle_registration').value = draftData.new_customer.vehicle || '';
            
            // Switch to new customer tab
            document.getElementById('new-customer-tab').click();
        }
        
        // Load items
        if (draftData.items && draftData.items.length > 0) {
            const tbody = document.querySelector('#itemsTable tbody');
            
            // Remove default empty row
            while (tbody.rows.length > 0) {
                tbody.deleteRow(0);
            }
            
            // Add rows for each item
            draftData.items.forEach((item, index) => {
                if (index === 0) {
                    // Modify the first row we'll add
                    addNewRow(item);
                } else {
                    addNewRow(item);
                }
            });
        }
    }

    function addNewRow(itemData = null) {
        const tbody = document.querySelector('#itemsTable tbody');
        const newRow = document.createElement('tr');
        newRow.className = 'item-row';
        
        newRow.innerHTML = `
            <td>
                <div class="d-flex align-items-center gap-2">
                    <select class="form-control form-control-sm category-select" style="flex:0 0 45%;">
                        <option value="">Category</option>
                        <?php foreach($categories_list as $cat): ?>
                            <option value="<?php echo htmlspecialchars($cat); ?>"><?php echo htmlspecialchars($cat); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select class="form-control form-control-sm part-select" name="part_id[]" required disabled style="flex:1;">
                        <option value="">Select Part</option>
                    </select>
                </div>
            </td>
            <td><input type="text" class="form-control available-stock" readonly></td>
            <td><input type="number" class="form-control quantity" name="quantity[]" min="1" required disabled></td>
            <td><input type="number" step="0.01" class="form-control selling-price" name="selling_price[]" required disabled></td>
            <td><input type="text" class="form-control row-total" readonly></td>
            <td><button type="button" class="btn btn-danger btn-sm remove-row"><i class="bi bi-trash"></i></button></td>
        `;
        
        tbody.appendChild(newRow);
        
        // If item data provided, populate it
        if (itemData) {
            populateRowWithData(newRow, itemData);
        }
    }

    function populateRowWithData(row, itemData) {
        // Find the part in partsByCategory to set category and part
        for (let category in partsByCategory) {
            const part = partsByCategory[category].find(p => p.id == itemData.part_id);
            if (part) {
                // Set category
                const categorySelect = row.querySelector('.category-select');
                categorySelect.value = category;
                
                // Trigger category change to load parts
                const event = new Event('change', { bubbles: true });
                categorySelect.dispatchEvent(event);
                
                // Set part after a short delay to allow parts to load
                setTimeout(() => {
                    const partSelect = row.querySelector('.part-select');
                    partSelect.value = itemData.part_id;
                    
                    // Trigger part change to load details
                    const partEvent = new Event('change', { bubbles: true });
                    partSelect.dispatchEvent(partEvent);
                    
                    // Set quantity and price
                    row.querySelector('.quantity').value = itemData.quantity;
                    row.querySelector('.selling-price').value = itemData.selling_price;
                    
                    // Calculate row total
                    const quantity = itemData.quantity;
                    const price = itemData.selling_price;
                    row.querySelector('.row-total').value = (quantity * price).toFixed(2);
                    
                    // Recalculate all totals
                    calculateAll();
                }, 100);
                break;
            }
        }
    }

    document.addEventListener('DOMContentLoaded', function() {
        const paidAmountInput = document.getElementById('paid_amount');
        const subTotalInput = document.getElementById('subTotal');
        const grandTotalInput = document.getElementById('grandTotal');
        const displayPaidAmount = document.getElementById('displayPaidAmount');
        const dueAmountInput = document.getElementById('dueAmount');
        const discountType = document.getElementById('discount_type');
        const discountValue = document.getElementById('discount_value');
        const discountDisplay = document.getElementById('discount_display');

        function calculateAll() {
            // Calculate subtotal from items
            let subTotal = 0;
            document.querySelectorAll('.row-total').forEach(input => {
                subTotal += parseFloat(input.value) || 0;
            });
            subTotalInput.value = subTotal.toFixed(2);
            
            // Calculate discount
            let discount = 0;
            let discountVal = parseFloat(discountValue.value) || 0;
            
            if (discountType.value === 'percentage') {
                discount = (subTotal * discountVal) / 100;
            } else {
                discount = discountVal;
            }
            
            // Ensure discount doesn't exceed subtotal
            if (discount > subTotal) {
                discount = subTotal;
                discountValue.value = discount;
            }
            
            // Update discount display
            if (discountType.value === 'percentage') {
                discountDisplay.innerHTML = discountVal + '%';
            } else {
                discountDisplay.innerHTML = '₹' + discount.toFixed(2);
            }
            
            // Calculate grand total after discount
            let grandTotal = subTotal - discount;
            if (grandTotal < 0) grandTotal = 0;
            grandTotalInput.value = grandTotal.toFixed(2);
            
            // Update due amount based on grand total after discount
            const paidAmount = parseFloat(paidAmountInput.value) || 0;
            let dueAmount = grandTotal - paidAmount;
            
            if (dueAmount < 0) {
                dueAmount = 0;
                paidAmountInput.value = grandTotal.toFixed(2);
            }
            
            displayPaidAmount.value = paidAmount.toFixed(2);
            dueAmountInput.value = dueAmount.toFixed(2);
            
            // Style due amount
            if (dueAmount > 0) {
                dueAmountInput.style.color = '#dc3545';
                dueAmountInput.style.backgroundColor = '#f8d7da';
                dueAmountInput.style.fontWeight = 'bold';
            } else {
                dueAmountInput.style.color = '#28a745';
                dueAmountInput.style.backgroundColor = '#d4edda';
                dueAmountInput.style.fontWeight = 'bold';
            }
            
            // Check stock availability
            checkStockAvailability();
        }

        // Event listeners for discount
        discountType.addEventListener('change', calculateAll);
        discountValue.addEventListener('input', calculateAll);
        paidAmountInput.addEventListener('input', calculateAll);

        // Add new row
        document.getElementById('addRow').addEventListener('click', function() {
            addNewRow();
        });
        
        // Check stock button
        document.getElementById('checkStockBtn').addEventListener('click', function() {
            checkStockAvailability();
        });
        
        // Remove row
        document.addEventListener('click', function(e) {
            if (e.target.classList.contains('remove-row') || e.target.closest('.remove-row')) {
                const tbody = document.querySelector('#itemsTable tbody');
                if (tbody.rows.length > 1) {
                    e.target.closest('tr').remove();
                    calculateAll();
                }
            }
        });
        
        // Category selection
        document.addEventListener('change', function(e) {
            if (e.target.classList.contains('category-select')) {
                const row = e.target.closest('tr');
                const partSelect = row.querySelector('.part-select');
                const cat = e.target.value;
                
                partSelect.innerHTML = '<option value="">Select Part</option>';
                partSelect.disabled = true;
                
                if (cat && partsByCategory[cat]) {
                    partsByCategory[cat].forEach(p => {
                        const opt = document.createElement('option');
                        opt.value = p.id;
                        opt.textContent = p.part_name;
                        opt.dataset.price = p.selling_price || p.unit_price;
                        opt.dataset.stock = p.current_stock;
                        partSelect.appendChild(opt);
                    });
                    partSelect.disabled = false;
                }
                
                row.querySelector('.available-stock').value = '';
                row.querySelector('.selling-price').value = '';
                row.querySelector('.quantity').value = '';
                row.querySelector('.row-total').value = '';
            }

            // Part selection
            if (e.target.classList.contains('part-select')) {
                const selected = e.target.options[e.target.selectedIndex];
                const row = e.target.closest('tr');

                if (selected && selected.value) {
                    const price = selected.dataset.price;
                    const stock = selected.dataset.stock;

                    row.querySelector('.selling-price').value = price;
                    row.querySelector('.available-stock').value = stock;
                    row.querySelector('.quantity').disabled = false;
                    row.querySelector('.selling-price').disabled = false;

                    const quantityInput = row.querySelector('.quantity');
                    quantityInput.max = stock;
                    quantityInput.setAttribute('max', stock);
                } else {
                    row.querySelector('.available-stock').value = '';
                    row.querySelector('.selling-price').value = '';
                    row.querySelector('.quantity').value = '';
                    row.querySelector('.quantity').disabled = true;
                    row.querySelector('.selling-price').disabled = true;
                }
            }
        });
        
        // Calculate row total
        document.addEventListener('input', function(e) {
            if (e.target.classList.contains('quantity') || e.target.classList.contains('selling-price')) {
                const row = e.target.closest('tr');
                const quantity = parseInt(row.querySelector('.quantity').value) || 0;
                const price = parseFloat(row.querySelector('.selling-price').value) || 0;
                const availableStock = parseInt(row.querySelector('.available-stock').value) || 0;
                
                if (quantity > availableStock) {
                    alert('Quantity exceeds available stock! Available: ' + availableStock);
                    row.querySelector('.quantity').value = availableStock;
                    return;
                }
                
                const total = quantity * price;
                row.querySelector('.row-total').value = total.toFixed(2);
                calculateAll();
            }
        });
        
        // Form validation
        document.getElementById('saleForm').addEventListener('submit', function(e) {
            // Check if this is a draft save or complete sale
            const isDraftSave = e.submitter && e.submitter.name === 'save_draft';
            
            if (!isDraftSave) {
                // Validate for complete sale
                const newCustomerChecked = document.getElementById('new_customer').checked;
                
                if (newCustomerChecked) {
                    const name = document.getElementById('new_customer_name').value;
                    const phone = document.getElementById('new_customer_phone').value;
                    
                    if (!name || !phone) {
                        e.preventDefault();
                        alert('Please enter customer name and phone for new customer');
                        return false;
                    }
                }
                
                const quantities = document.querySelectorAll('.quantity');
                let hasItems = false;
                quantities.forEach(q => {
                    if (parseInt(q.value) > 0) hasItems = true;
                });
                
                if (!hasItems) {
                    e.preventDefault();
                    alert('Please add at least one item to the sale');
                    return false;
                }
                
                const grandTotal = parseFloat(grandTotalInput.value) || 0;
                const paidAmount = parseFloat(paidAmountInput.value) || 0;
                
                if (paidAmount > grandTotal) {
                    if (!confirm('Paid amount (₹' + paidAmount.toFixed(2) + ') is greater than grand total (₹' + grandTotal.toFixed(2) + '). Do you want to continue?')) {
                        e.preventDefault();
                        return false;
                    }
                }
                
                // Check for out of stock items
                let outOfStockItems = [];
                document.querySelectorAll('.item-row').forEach(row => {
                    const partSelect = row.querySelector('.part-select');
                    const quantity = parseInt(row.querySelector('.quantity').value) || 0;
                    const availableStock = parseInt(row.querySelector('.available-stock').value) || 0;
                    
                    if (partSelect && partSelect.value && quantity > 0 && quantity > availableStock) {
                        const partName = partSelect.options[partSelect.selectedIndex]?.text || 'Unknown';
                        outOfStockItems.push(partName);
                    }
                });
                
                if (outOfStockItems.length > 0) {
                    if (!confirm('The following items are out of stock: ' + outOfStockItems.join(', ') + '. Do you want to save as draft instead?')) {
                        e.preventDefault();
                        return false;
                    }
                }
            } else {
                // For draft save, just ensure there's at least one item
                const quantities = document.querySelectorAll('.quantity');
                let hasItems = false;
                quantities.forEach(q => {
                    if (parseInt(q.value) > 0) hasItems = true;
                });
                
                if (!hasItems) {
                    e.preventDefault();
                    alert('Please add at least one item to save as draft');
                    return false;
                }
            }
        });
        
        // Initialize
        calculateAll();
        
        // Load draft data if available
        if (draftData && Object.keys(draftData).length > 0) {
            loadDraftData();
        }
    });
    </script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>