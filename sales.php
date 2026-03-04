<?php
require_once 'config.php';

if (!isLoggedIn()) {
    redirect('index.php');
}

// Generate new invoice number
$invoice_number = generateInvoiceNumber($conn);

// Handle new sale
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_sale'])) {
    $customer_id = $_POST['customer_id'] ?: 'NULL';
    $vehicle_registration = strtoupper(mysqli_real_escape_string($conn, $_POST['vehicle_registration'] ?? ''));
    $sale_date = $_POST['sale_date'];
    $invoice_number = mysqli_real_escape_string($conn, $_POST['invoice_number']);
    $payment_method = $_POST['payment_method'];
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
        
        // Insert sale
        $query = "INSERT INTO sales (customer_id, sale_date, invoice_number, payment_method, paid_amount, created_by) 
                  VALUES ($customer_id, '$sale_date', '$invoice_number', '$payment_method', $paid_amount, $created_by)";
        mysqli_query($conn, $query);
        $sale_id = mysqli_insert_id($conn);
        
        // Insert sale items
        $part_ids = $_POST['part_id'];
        $quantities = $_POST['quantity'];
        $selling_prices = $_POST['selling_price'];
        
        $total_amount = 0;
        
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
                    throw new Exception("Insufficient stock for item");
                }
            }
        }
        
        // Calculate due amount and payment status
        $due_amount = $total_amount - $paid_amount;
        $payment_status = 'paid';
        if ($due_amount > 0) {
            $payment_status = 'partial';
        } elseif ($due_amount < 0) {
            // If paid amount exceeds total, adjust paid amount
            $paid_amount = $total_amount;
            $due_amount = 0;
            $payment_status = 'paid';
        }
        
        // Update sale with total amount and payment status
        $update_query = "UPDATE sales SET 
                        total_amount = $total_amount,
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
        $_SESSION['success'] = "Sale completed successfully! Invoice: $invoice_number";
        redirect("sale_view.php?id=$sale_id");
        
    } catch (Exception $e) {
        mysqli_rollback($conn);
        $_SESSION['error'] = "Error: " . $e->getMessage();
        redirect('sales.php');
    }
}

// Fetch categories
$categories = mysqli_query($conn, "SELECT * FROM categories ORDER BY category_name");

// Fetch parts with category information - UPDATED to include category for grouping
$parts_query = "SELECT p.*, s.quantity as current_stock, c.category_name, c.id as category_id
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

// Fetch recent sales with vehicle info
$sales = mysqli_query($conn, "SELECT s.*, c.customer_name, c.vehicle_registration, u.username,
                              (SELECT COUNT(*) FROM sale_payments WHERE sale_id = s.id) as payment_count
                              FROM sales s 
                              LEFT JOIN customers c ON s.customer_id = c.id 
                              LEFT JOIN users u ON s.created_by = u.id 
                              ORDER BY s.sale_date DESC LIMIT 50");
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
            font-size: 1.2em;
        }
        .due-positive {
            color: #dc3545;
            font-weight: bold;
            background-color: #f8d7da;
        }
        .due-zero {
            color: #28a745;
            font-weight: bold;
            background-color: #d4edda;
        }
        .grand-total {
            font-size: 1.2em;
            font-weight: bold;
            background-color: #cce5ff;
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
            font-size: 1em;
        }
        .part-select option {
            padding: 5px;
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

        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="bi bi-cart-plus"></i> New Sale / Billing</h5>
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
                                        <input type="number" step="0.01" class="form-control editable-paid" id="paid_amount" name="paid_amount" value="0" min="0">
                                        <small class="text-muted">Edit this amount to calculate due</small>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Items Table with Category-wise Grouped Parts - Only Name Shown -->
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
                                                <?php
                                                // Build parts map grouped by category for JS-driven category->part linkage
                                                mysqli_data_seek($parts, 0);
                                                $parts_map = [];
                                                $categories = [];
                                                while($p = mysqli_fetch_assoc($parts)) {
                                                    $cat = $p['category_name'] ?? 'Uncategorized';
                                                    if (!isset($parts_map[$cat])) $parts_map[$cat] = [];
                                                    $parts_map[$cat][] = $p;
                                                    if (!in_array($cat, $categories)) $categories[] = $cat;
                                                }
                                                ?>
                                                <div class="d-flex align-items-center gap-2">
                                                    <select class="form-control form-control-sm category-select" style="flex:0 0 45%;">
                                                        <option value="">Category</option>
                                                        <?php foreach($categories as $cat): ?>
                                                            <option value="<?php echo htmlspecialchars($cat); ?>"><?php echo htmlspecialchars($cat); ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                    <select class="form-control form-control-sm part-select" name="part_id[]" required disabled style="flex:1;">
                                                        <option value="">Part</option>
                                                    </select>
                                                </div>
                                            </td>
                                            <td><input type="text" class="form-control available-stock" readonly></td>
                                            <td><input type="number" class="form-control quantity" name="quantity[]" min="1" required></td>
                                            <td><input type="number" step="0.01" class="form-control selling-price" name="selling_price[]" required></td>
                                            <td><input type="text" class="form-control row-total" readonly></td>
                                            <td><button type="button" class="btn btn-danger btn-sm remove-row"><i class="bi bi-trash"></i></button></td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            
                            <!-- Calculation Section -->
                            <div class="row calculation-row">
                                <div class="col-md-6">
                                    <button type="button" class="btn btn-success" id="addRow">
                                        <i class="bi bi-plus-circle"></i> Add Another Item
                                    </button>
                                    <button type="button" class="btn btn-info" id="calculateTotal">
                                        <i class="bi bi-calculator"></i> Calculate Total
                                    </button>
                                </div>
                                <div class="col-md-6">
                                    <div class="card">
                                        <div class="card-body">
                                            <table class="table table-sm table-borderless mb-0">
                                                <tr>
                                                    <td width="50%"><strong>Grand Total:</strong></td>
                                                    <td><input type="text" class="form-control grand-total" id="grandTotal" readonly value="0.00" style="font-weight:bold; background-color:#e3f2fd; text-align:right;"></td>
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

        <!-- Recent Sales with Vehicle Info -->
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
                                        <th>Total</th>
                                        <th>Paid</th>
                                        <th>Due</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while($sale = mysqli_fetch_assoc($sales)): ?>
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
                                        <td>₹<?php echo number_format($sale['total_amount'], 2); ?></td>
                                        <td class="text-success">₹<?php echo number_format($sale['paid_amount'], 2); ?></td>
                                        <td class="text-<?php echo $sale['due_amount'] > 0 ? 'danger' : 'success'; ?>">
                                            ₹<?php echo number_format($sale['due_amount'], 2); ?>
                                        </td>
                                        <td>
                                            <?php 
                                            $status_class = 'success';
                                            $status_text = 'Paid';
                                            if($sale['payment_status'] == 'partial') {
                                                $status_class = 'warning';
                                                $status_text = 'Partial';
                                            } elseif($sale['payment_status'] == 'pending') {
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
                                                <?php if($sale['due_amount'] > 0): ?>
                                                <a href="sale_view.php?id=<?php echo $sale['id']; ?>#payments" class="btn btn-warning" title="Collect">
                                                    <i class="bi bi-cash"></i>
                                                </a>
                                                <?php endif; ?>
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
    // partsByCategory will be populated by PHP below
    var partsByCategory = {};
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
        
        // Auto-fill new customer tab if needed
        if (selected.value) {
            document.getElementById('new_customer_name').value = selected.dataset.name || '';
            document.getElementById('new_customer_phone').value = selected.dataset.phone || '';
        }
    }

    // Handle tab switching for validation
    document.getElementById('existing-customer-tab').addEventListener('click', function() {
        document.getElementById('new_customer').checked = false;
    });
    
    document.getElementById('new-customer-tab').addEventListener('click', function() {
        document.getElementById('new_customer').checked = true;
    });

    document.addEventListener('DOMContentLoaded', function() {
        const paidAmountInput = document.getElementById('paid_amount');
        const grandTotalInput = document.getElementById('grandTotal');
        const displayPaidAmount = document.getElementById('displayPaidAmount');
        const dueAmountInput = document.getElementById('dueAmount');
        
        // Function to update all calculations
        function updateCalculations() {
            calculateGrandTotal();
            updateDueAmount();
        }

        // Debug: ensure partsByCategory is available and populate category selects
        console.log('partsByCategory:', partsByCategory);
        function populateCategorySelects() {
            const cats = Object.keys(partsByCategory || {});
            document.querySelectorAll('.category-select').forEach(sel => {
                // remove existing non-default options
                for (let i = sel.options.length - 1; i >= 1; i--) sel.remove(i);
                cats.forEach(cat => {
                    const opt = document.createElement('option');
                    opt.value = cat;
                    opt.textContent = cat;
                    sel.appendChild(opt);
                });
            });
        }
        populateCategorySelects();
        
        // Calculate grand total from all rows
        function calculateGrandTotal() {
            let grandTotal = 0;
            document.querySelectorAll('.row-total').forEach(input => {
                grandTotal += parseFloat(input.value) || 0;
            });
            grandTotalInput.value = grandTotal.toFixed(2);
            return grandTotal;
        }
        
        // Update due amount based on grand total and paid amount
        function updateDueAmount() {
            const grandTotal = parseFloat(grandTotalInput.value) || 0;
            const paidAmount = parseFloat(paidAmountInput.value) || 0;
            let dueAmount = grandTotal - paidAmount;
            
            // Ensure due amount is not negative
            if (dueAmount < 0) {
                dueAmount = 0;
                paidAmountInput.value = grandTotal.toFixed(2);
            }
            
            displayPaidAmount.value = paidAmount.toFixed(2);
            dueAmountInput.value = dueAmount.toFixed(2);
            
            // Style due amount based on value
            if (dueAmount > 0) {
                dueAmountInput.style.color = '#dc3545';
                dueAmountInput.style.backgroundColor = '#f8d7da';
                dueAmountInput.style.fontWeight = 'bold';
            } else {
                dueAmountInput.style.color = '#28a745';
                dueAmountInput.style.backgroundColor = '#d4edda';
                dueAmountInput.style.fontWeight = 'bold';
            }
            
            // Validate paid amount against grand total
            if (paidAmount > grandTotal) {
                paidAmountInput.style.borderColor = '#dc3545';
                paidAmountInput.style.backgroundColor = '#f8d7da';
            } else {
                paidAmountInput.style.borderColor = '#ffc107';
                paidAmountInput.style.backgroundColor = '#fff3cd';
            }
        }
        
        // Add new row
        document.getElementById('addRow').addEventListener('click', function() {
            const tbody = document.querySelector('#itemsTable tbody');
            const newRow = tbody.rows[0].cloneNode(true);
            
            // Clear input values
            newRow.querySelectorAll('input').forEach(input => {
                if (input.type !== 'button') input.value = '';
            });

            // Reset selects: category -> default, part -> disabled/empty
            const categorySelect = newRow.querySelector('.category-select');
            const partSelect = newRow.querySelector('.part-select');
            if (categorySelect) categorySelect.selectedIndex = 0;
            if (partSelect) {
                partSelect.innerHTML = '<option value="">Select Part</option>';
                partSelect.disabled = true;
            }
            
            // Add remove button event
            const removeBtn = newRow.querySelector('.remove-row');
            removeBtn.addEventListener('click', function() {
                if (tbody.rows.length > 1) {
                    this.closest('tr').remove();
                    updateCalculations();
                }
            });
            
            tbody.appendChild(newRow);
        });
        
        // Remove row
        document.addEventListener('click', function(e) {
            if (e.target.classList.contains('remove-row') || e.target.closest('.remove-row')) {
                const tbody = document.querySelector('#itemsTable tbody');
                if (tbody.rows.length > 1) {
                    e.target.closest('tr').remove();
                    updateCalculations();
                }
            }
        });
        
        // Handle part selection
        document.addEventListener('change', function(e) {
            // When category changes, populate the part select for that row
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
                        opt.dataset.price = p.unit_price;
                        opt.dataset.stock = p.current_stock;
                        partSelect.appendChild(opt);
                    });
                    partSelect.disabled = false;
                }
                // clear price/stock for the row
                row.querySelector('.available-stock').value = '';
                row.querySelector('.selling-price').value = '';
                row.querySelector('.row-total').value = '';
            }

            // When part changes, populate price/stock for that row
            if (e.target.classList.contains('part-select')) {
                const selected = e.target.options[e.target.selectedIndex];
                const row = e.target.closest('tr');

                if (selected && selected.value) {
                    const price = selected.dataset.price;
                    const stock = selected.dataset.stock;

                    row.querySelector('.selling-price').value = price;
                    row.querySelector('.available-stock').value = stock;

                    // Validate quantity against stock
                    const quantityInput = row.querySelector('.quantity');
                    quantityInput.max = stock;
                    quantityInput.setAttribute('max', stock);
                } else {
                    row.querySelector('.available-stock').value = '';
                    row.querySelector('.selling-price').value = '';
                }
            }
        });
        
        // Calculate row total on quantity or price change
        document.addEventListener('input', function(e) {
            if (e.target.classList.contains('quantity') || e.target.classList.contains('selling-price')) {
                const row = e.target.closest('tr');
                const quantity = parseInt(row.querySelector('.quantity').value) || 0;
                const price = parseFloat(row.querySelector('.selling-price').value) || 0;
                const availableStock = parseInt(row.querySelector('.available-stock').value) || 0;
                
                // Validate quantity against stock
                if (quantity > availableStock) {
                    alert('Quantity exceeds available stock! Available: ' + availableStock);
                    row.querySelector('.quantity').value = availableStock;
                    return;
                }
                
                const total = quantity * price;
                row.querySelector('.row-total').value = total.toFixed(2);
                updateCalculations();
            }
        });
        
        // Update due amount when paid amount changes (real-time)
        paidAmountInput.addEventListener('input', function() {
            updateDueAmount();
        });
        
        // Manual calculate button
        document.getElementById('calculateTotal').addEventListener('click', function() {
            updateCalculations();
        });
        
        // Auto-uppercase for registration fields
        const regFields = ['existing_vehicle', 'new_vehicle_registration'];
        regFields.forEach(id => {
            const field = document.getElementById(id);
            if (field) {
                field.addEventListener('input', function() {
                    this.value = this.value.toUpperCase();
                });
            }
        });
        
        // Form validation before submit
        document.getElementById('saleForm').addEventListener('submit', function(e) {
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
            
            // Check if at least one item is added
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
            
            // Validate paid amount doesn't exceed total
            const grandTotal = parseFloat(grandTotalInput.value) || 0;
            const paidAmount = parseFloat(paidAmountInput.value) || 0;
            
            if (paidAmount > grandTotal) {
                if (!confirm('Paid amount (₹' + paidAmount.toFixed(2) + ') is greater than grand total (₹' + grandTotal.toFixed(2) + '). Do you want to continue?')) {
                    e.preventDefault();
                    return false;
                }
            }
        });
        
        // Initialize on page load
        updateCalculations();
    });
    </script>

    <script>
    // Populate partsByCategory from PHP-generated map
    partsByCategory = <?php echo json_encode($parts_map); ?> || {};
    </script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>