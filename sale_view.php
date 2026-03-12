<?php
require_once 'config.php';

if (!isLoggedIn()) {
    redirect('index.php');
}

$sale_id = $_GET['id'] ?? 0;

// Fetch sale details
$sale = mysqli_query($conn, "SELECT s.*, c.customer_name, c.phone, c.address, c.email, u.username
                             FROM sales s
                             LEFT JOIN customers c ON s.customer_id = c.id
                             LEFT JOIN users u ON s.created_by = u.id
                             WHERE s.id = $sale_id");
$sale_details = mysqli_fetch_assoc($sale);

if (!$sale_details) {
    redirect('sales.php');
}

// Handle Add Payment
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_payment'])) {
    $sale_id = $_POST['sale_id'];
    $payment_amount = floatval($_POST['payment_amount']);
    $payment_method = mysqli_real_escape_string($conn, $_POST['payment_method']);
    $reference_number = mysqli_real_escape_string($conn, $_POST['reference_number'] ?? '');
    $notes = mysqli_real_escape_string($conn, $_POST['notes'] ?? '');
    $received_by = $_SESSION['user_id'];
    
    // Check if discount is applied
    $apply_discount = isset($_POST['apply_discount']) && $_POST['apply_discount'] == '1';
    $discount_type = mysqli_real_escape_string($conn, $_POST['discount_type'] ?? 'fixed');
    $discount_value = floatval($_POST['discount_value'] ?? 0);
    $discount_note = mysqli_real_escape_string($conn, $_POST['discount_note'] ?? '');
    
    // Start transaction
    mysqli_begin_transaction($conn);
    
    try {
        // Get current sale details
        $current_sale = mysqli_fetch_assoc(mysqli_query($conn, "SELECT grand_total, paid_amount, total_amount, discount_amount, discount_type, discount_value FROM sales WHERE id = $sale_id"));
        
        // Use Grand Total from database
        $grand_total = $current_sale['grand_total'] ?? $current_sale['total_amount'];
        $new_paid_amount = $current_sale['paid_amount'] + $payment_amount;
        $new_due_amount = $grand_total - $new_paid_amount;
        
        // Handle discount if applied
        $discount_amount = 0;
        if ($apply_discount && $discount_value > 0) {
            // Calculate discount based on type
            if ($discount_type == 'percentage') {
                $discount_amount = ($grand_total * $discount_value) / 100;
            } else {
                $discount_amount = $discount_value;
            }
            
            // Ensure discount doesn't exceed grand total
            if ($discount_amount > $grand_total) {
                $discount_amount = $grand_total;
            }
            
            // Update grand total with discount
            $new_grand_total = $grand_total - $discount_amount;
            
            // Update due amount based on new grand total
            $new_due_amount = $new_grand_total - $new_paid_amount;
            
            // Update sale with discount information
            $discount_update = "UPDATE sales SET 
                               discount_amount = discount_amount + $discount_amount,
                               discount_type = '$discount_type',
                               discount_value = $discount_value,
                               discount_note = CONCAT(IFNULL(discount_note, ''), ' | ', '$discount_note'),
                               grand_total = $new_grand_total,
                               due_amount = $new_due_amount
                               WHERE id = $sale_id";
            
            if (!mysqli_query($conn, $discount_update)) {
                throw new Exception("Error applying discount: " . mysqli_error($conn));
            }
        }
        
        // Insert payment record
        $payment_query = "INSERT INTO sale_payments (sale_id, payment_amount, payment_method, reference_number, notes, received_by) 
                         VALUES ($sale_id, $payment_amount, '$payment_method', '$reference_number', '$notes', $received_by)";
        if (!mysqli_query($conn, $payment_query)) {
            throw new Exception("Error inserting payment: " . mysqli_error($conn));
        }
        
        // Determine payment status
        if ($new_due_amount <= 0) {
            $payment_status = 'paid';
            $new_due_amount = 0;
        } else {
            $payment_status = 'partial';
        }
        
        // Update sale with new paid and due amounts
        $update_query = "UPDATE sales SET 
                        paid_amount = $new_paid_amount,
                        due_amount = $new_due_amount,
                        payment_status = '$payment_status'
                        WHERE id = $sale_id";
        
        if (!mysqli_query($conn, $update_query)) {
            throw new Exception("Error updating sale: " . mysqli_error($conn));
        }
        
        mysqli_commit($conn);
        
        $success_message = "Payment of ₹" . number_format($payment_amount, 2) . " added successfully!";
        if ($apply_discount && $discount_amount > 0) {
            $success_message .= " Discount of ₹" . number_format($discount_amount, 2) . " applied.";
        }
        $success_message .= " Due amount is now ₹" . number_format($new_due_amount, 2);
        
        $_SESSION['success'] = $success_message;
        
    } catch (Exception $e) {
        mysqli_rollback($conn);
        $_SESSION['error'] = "Error adding payment: " . $e->getMessage();
    }
    
    redirect("sale_view.php?id=$sale_id");
}

// Handle discount removal
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['remove_discount'])) {
    $sale_id = $_POST['sale_id'];
    
    mysqli_begin_transaction($conn);
    
    try {
        // Get current sale details
        $current_sale = mysqli_fetch_assoc(mysqli_query($conn, "SELECT grand_total, discount_amount, total_amount FROM sales WHERE id = $sale_id"));
        
        // Calculate original grand total (add back discount)
        $original_grand_total = $current_sale['grand_total'] + $current_sale['discount_amount'];
        
        // Reset discount
        $update_query = "UPDATE sales SET 
                        discount_amount = 0,
                        discount_type = NULL,
                        discount_value = 0,
                        discount_note = NULL,
                        grand_total = $original_grand_total,
                        due_amount = $original_grand_total - paid_amount
                        WHERE id = $sale_id";
        
        if (!mysqli_query($conn, $update_query)) {
            throw new Exception("Error removing discount: " . mysqli_error($conn));
        }
        
        mysqli_commit($conn);
        $_SESSION['success'] = "Discount removed successfully!";
        
    } catch (Exception $e) {
        mysqli_rollback($conn);
        $_SESSION['error'] = "Error removing discount: " . $e->getMessage();
    }
    
    redirect("sale_view.php?id=$sale_id");
}

// Fetch sale items
$items = mysqli_query($conn, "SELECT si.*, p.part_number, p.part_name, p.unit_price
                              FROM sale_items si
                              JOIN parts_master p ON si.part_id = p.id
                              WHERE si.sale_id = $sale_id");

// Fetch payment history
$payments = mysqli_query($conn, "SELECT sp.*, u.username 
                                 FROM sale_payments sp
                                 LEFT JOIN users u ON sp.received_by = u.id
                                 WHERE sp.sale_id = $sale_id
                                 ORDER BY sp.payment_date DESC");

// Calculate payment summary
$payment_summary = mysqli_fetch_assoc(mysqli_query($conn, "SELECT 
                                                           COUNT(*) as payment_count,
                                                           SUM(payment_amount) as total_paid,
                                                           SUM(CASE WHEN payment_method = 'cash' THEN payment_amount ELSE 0 END) as cash_total,
                                                           SUM(CASE WHEN payment_method = 'card' THEN payment_amount ELSE 0 END) as card_total,
                                                           SUM(CASE WHEN payment_method = 'online' THEN payment_amount ELSE 0 END) as online_total,
                                                           SUM(CASE WHEN payment_method = 'bank_transfer' THEN payment_amount ELSE 0 END) as bank_total
                                                           FROM sale_payments WHERE sale_id = $sale_id"));

// Calculate subtotal from items
$subtotal = 0;
mysqli_data_seek($items, 0);
while($item = mysqli_fetch_assoc($items)) {
    $subtotal += $item['quantity'] * $item['selling_price'];
}

// Grand Total from database (with discount applied)
$grand_total = $sale_details['grand_total'] ?? $sale_details['total_amount'];

// Calculate due amount based on Grand Total
$correct_due_amount = $grand_total - $sale_details['paid_amount'];
if ($correct_due_amount < 0) $correct_due_amount = 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sale #<?php echo htmlspecialchars($sale_details['invoice_number']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <style>
        .payment-summary .card-body h6 { font-size: 10px; margin-bottom: 4px; }
        .payment-summary .card-body h3 { font-size: 16px; margin-bottom: 0; }
        .payment-summary .card-body { padding: 8px; }
        .payment-breakdown .card-body strong { font-size: 11px; display: block; margin-bottom: 2px; }
        .payment-breakdown .card-body h4 { font-size: 16px; margin: 0; }
        .payment-breakdown .border { padding: 6px; }
        .payment-breakdown .row.mt-3 { margin-top: 8px !important; }
        .payment-summary, .payment-breakdown, .card.mb-4 { margin-bottom: 10px !important; }
        .payment-summary .row > div, .payment-breakdown .row > div { padding-bottom: 0 !important; }
        .items-sold-section { margin-bottom: 5px !important; }
        .payment-history-section { margin-top: 5px !important; }
        
        /* New styles for discount display */
        .discount-badge-large {
            background: linear-gradient(135deg, #17a2b8, #0d6efd);
            color: white;
            padding: 12px 20px;
            border-radius: 10px;
            font-size: 16px;
            display: inline-block;
            margin-bottom: 15px;
            box-shadow: 0 4px 10px rgba(13, 110, 253, 0.3);
            border: 1px solid rgba(255,255,255,0.2);
            width: 100%;
            text-align: center;
        }
        .discount-badge-large i {
            font-size: 20px;
            margin-right: 8px;
        }
        .discount-amount-highlight {
            font-size: 18px;
            font-weight: bold;
            color: #ffc107;
            background: rgba(0,0,0,0.2);
            padding: 5px 15px;
            border-radius: 50px;
            margin-left: 10px;
        }
        .grand-total-text {
            font-size: 1.2em;
            font-weight: bold;
            color: #28a745;
        }
        .due-amount-correct {
            font-size: 1.2em;
            font-weight: bold;
        }
        .subtotal-text {
            color: #6c757d;
            text-decoration: line-through;
            font-size: 0.9em;
            margin-right: 10px;
        }
        .discount-row {
            background: #e7f3ff;
            border-left: 4px solid #0d6efd;
        }
        .discount-row td {
            color: #004085;
            font-weight: 600;
        }
        .savings-badge {
            background: #28a745;
            color: white;
            padding: 5px 10px;
            border-radius: 5px;
            font-size: 14px;
        }
        
        /* Discount toggle styles */
        .discount-section {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 15px;
        }
        .discount-toggle {
            cursor: pointer;
            color: #0d6efd;
        }
        .discount-toggle:hover {
            text-decoration: underline;
        }
        .discount-inputs {
            display: none;
            margin-top: 10px;
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container-fluid">
            <a class="navbar-brand" href="dashboard.php">
                <i class="bi bi-bicycle"></i> PRAVEEN SERVICE CENTER
            </a>
        </div>
    </nav>

    <div class="container mt-4">
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

        <div class="d-flex justify-content-between mb-3">
            <h4>Sale Details - Invoice #<?php echo htmlspecialchars($sale_details['invoice_number']); ?></h4>
            <div>
                <?php if($correct_due_amount > 0): ?>
                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addPaymentModal">
                    <i class="bi bi-cash"></i> Add Payment
                </button>
                <?php endif; ?>
                <a href="invoice.php?id=<?php echo $sale_id; ?>" class="btn btn-info" target="_blank">
                    <i class="bi bi-printer"></i> Print Invoice
                </a>
                <a href="sales.php" class="btn btn-secondary">
                    <i class="bi bi-arrow-left"></i> Back to Sales
                </a>
            </div>
        </div>

        <!-- Sale Information -->
        <div class="card mb-4">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0">Sale Information</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-3">
                        <strong>Invoice #:</strong> <?php echo htmlspecialchars($sale_details['invoice_number']); ?>
                    </div>
                    <div class="col-md-3">
                        <strong>Date:</strong> <?php echo date('d-m-Y', strtotime($sale_details['sale_date'])); ?>
                    </div>
                    <div class="col-md-3">
                        <strong>Customer:</strong> <?php echo htmlspecialchars($sale_details['customer_name'] ?? 'Walk-in Customer'); ?>
                    </div>
                    <div class="col-md-3">
                        <strong>Created By:</strong> <?php echo htmlspecialchars($sale_details['username']); ?>
                    </div>
                </div>
                
                <!-- Display Discount Information prominently -->
                <?php if(isset($sale_details['discount_amount']) && $sale_details['discount_amount'] > 0): ?>
                <div class="row mt-3">
                    <div class="col-12">
                        <div class="discount-badge-large">
                            <i class="bi bi-tags"></i> 
                            <strong>DISCOUNT APPLIED</strong>
                            <span class="discount-amount-highlight">
                                <?php if($sale_details['discount_type'] == 'percentage'): ?>
                                    <?php echo $sale_details['discount_value']; ?>% OFF
                                <?php else: ?>
                                    ₹<?php echo number_format($sale_details['discount_value'], 2); ?> OFF
                                <?php endif; ?>
                            </span>
                            <span class="savings-badge ms-2">
                                <i class="bi bi-piggy-bank"></i> You Saved: ₹<?php echo number_format($sale_details['discount_amount'], 2); ?>
                            </span>
                            <?php if($_SESSION['role'] == 'admin'): ?>
                            <form method="POST" class="d-inline ms-3" onsubmit="return confirm('Are you sure you want to remove this discount?');">
                                <input type="hidden" name="sale_id" value="<?php echo $sale_id; ?>">
                                <button type="submit" name="remove_discount" class="btn btn-sm btn-danger">
                                    <i class="bi bi-x-circle"></i> Remove Discount
                                </button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if($sale_details['email'] || $sale_details['phone']): ?>
                <div class="row mt-2">
                    <?php if($sale_details['email']): ?>
                    <div class="col-md-3">
                        <strong>Email:</strong> <?php echo htmlspecialchars($sale_details['email']); ?>
                    </div>
                    <?php endif; ?>
                    <?php if($sale_details['phone']): ?>
                    <div class="col-md-3">
                        <strong>Phone:</strong> <?php echo htmlspecialchars($sale_details['phone']); ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                
                <?php if($sale_details['customer_id']): 
                    $customer_vehicle = mysqli_fetch_assoc(mysqli_query($conn, "SELECT vehicle_registration FROM customers WHERE id = " . $sale_details['customer_id']));
                    if($customer_vehicle && $customer_vehicle['vehicle_registration']): 
                ?>
                <div class="row mt-2">
                    <div class="col-12">
                        <strong>Vehicle Registration:</strong> 
                        <span class="badge bg-info fs-6 p-2"><?php echo $customer_vehicle['vehicle_registration']; ?></span>
                    </div>
                </div>
                <?php endif; ?>
                <?php endif; ?>
                
                <?php if($sale_details['address']): ?>
                <div class="row mt-2">
                    <div class="col-12">
                        <strong>Address:</strong> <?php echo htmlspecialchars($sale_details['address']); ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Payment Summary with Discount Highlight -->
        <div class="row mb-4 payment-summary">
            <div class="col-md-2">
                <div class="card bg-secondary text-white">
                    <div class="card-body">
                        <h6>Subtotal</h6>
                        <h3>₹<?php echo number_format($subtotal, 2); ?></h3>
                        <small>Before discount</small>
                    </div>
                </div>
            </div>
            <?php if(isset($sale_details['discount_amount']) && $sale_details['discount_amount'] > 0): ?>
            <div class="col-md-2">
                <div class="card bg-info text-white">
                    <div class="card-body">
                        <h6>Discount</h6>
                        <h3 class="text-warning">-₹<?php echo number_format($sale_details['discount_amount'], 2); ?></h3>
                        <?php if($sale_details['discount_type'] == 'percentage'): ?>
                            <small><?php echo $sale_details['discount_value']; ?>% off</small>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            <div class="col-md-<?php echo (isset($sale_details['discount_amount']) && $sale_details['discount_amount'] > 0) ? '2' : '3'; ?>">
                <div class="card bg-primary text-white">
                    <div class="card-body">
                        <h6>Grand Total</h6>
                        <h3>₹<?php echo number_format($grand_total, 2); ?></h3>
                        <small>After discount</small>
                    </div>
                </div>
            </div>
            <div class="col-md-<?php echo (isset($sale_details['discount_amount']) && $sale_details['discount_amount'] > 0) ? '2' : '3'; ?>">
                <div class="card bg-info text-white">
                    <div class="card-body">
                        <h6>Paid Amount</h6>
                        <h3>₹<?php echo number_format($sale_details['paid_amount'], 2); ?></h3>
                    </div>
                </div>
            </div>
            <div class="col-md-<?php echo (isset($sale_details['discount_amount']) && $sale_details['discount_amount'] > 0) ? '2' : '3'; ?>">
                <div class="card bg-<?php echo $correct_due_amount > 0 ? 'danger' : 'success'; ?> text-white">
                    <div class="card-body">
                        <h6>Due Amount</h6>
                        <h3 class="due-amount-correct">₹<?php echo number_format($correct_due_amount, 2); ?></h3>
                        <?php if($correct_due_amount <= 0): ?>
                        <span class="badge bg-light text-dark">FULLY PAID</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Payment Method Breakdown -->
        <div class="row mb-4 payment-breakdown">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Payment Breakdown</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-3">
                                <div class="border p-3 text-center">
                                    <strong>Cash</strong>
                                    <h4>₹<?php echo number_format($payment_summary['cash_total'] ?? 0, 2); ?></h4>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="border p-3 text-center">
                                    <strong>Card</strong>
                                    <h4>₹<?php echo number_format($payment_summary['card_total'] ?? 0, 2); ?></h4>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="border p-3 text-center">
                                    <strong>Online</strong>
                                    <h4>₹<?php echo number_format($payment_summary['online_total'] ?? 0, 2); ?></h4>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="border p-3 text-center">
                                    <strong>Bank Transfer</strong>
                                    <h4>₹<?php echo number_format($payment_summary['bank_total'] ?? 0, 2); ?></h4>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <?php
        // Reset the items pointer
        mysqli_data_seek($items, 0);
        ?>
        
        <!-- Items Sold with Discount Row -->
        <div class="card mb-4 items-sold-section">
            <div class="card-header">
                <h5 class="mb-0">Items Sold</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-bordered">
                        <thead>
                            <tr>
                                <th>Part #</th>
                                <th>Part Name</th>
                                <th>Quantity</th>
                                <th>Selling Price</th>
                                <th>Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $display_subtotal = 0;
                            while($item = mysqli_fetch_assoc($items)): 
                                $total = $item['quantity'] * $item['selling_price'];
                                $display_subtotal += $total;
                            ?>
                            <tr>
                                <td><?php echo htmlspecialchars($item['part_number']); ?></td>
                                <td><?php echo htmlspecialchars($item['part_name']); ?></td>
                                <td><?php echo $item['quantity']; ?></td>
                                <td>₹<?php echo number_format($item['selling_price'], 2); ?></td>
                                <td>₹<?php echo number_format($total, 2); ?></td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <th colspan="4" class="text-end">Subtotal:</th>
                                <th>₹<?php echo number_format($display_subtotal, 2); ?></th>
                            </tr>
                            
                            <!-- Discount Row - Highlighted -->
                            <?php if(isset($sale_details['discount_amount']) && $sale_details['discount_amount'] > 0): ?>
                            <tr class="discount-row">
                                <th colspan="4" class="text-end">
                                    <i class="bi bi-tags"></i> 
                                    Discount 
                                    <?php if($sale_details['discount_type'] == 'percentage'): ?>
                                        (<?php echo $sale_details['discount_value']; ?>%)
                                    <?php endif; ?>:
                                </th>
                                <th class="text-info">
                                    -₹<?php echo number_format($sale_details['discount_amount'], 2); ?>
                                    <small class="d-block text-muted">You saved this amount</small>
                                </th>
                            </tr>
                            <?php endif; ?>
                            
                            <!-- Grand Total Row -->
                            <tr class="grand-total-row">
                                <th colspan="4" class="text-end">Grand Total:</th>
                                <th class="grand-total-text">₹<?php echo number_format($grand_total, 2); ?></th>
                            </tr>
                            
                            <!-- Paid Amount -->
                            <tr>
                                <th colspan="4" class="text-end">Paid Amount:</th>
                                <th class="text-success">₹<?php echo number_format($sale_details['paid_amount'], 2); ?></th>
                            </tr>
                            
                            <!-- Due Amount -->
                            <tr class="<?php echo $correct_due_amount > 0 ? 'due-amount-row' : ''; ?>">
                                <th colspan="4" class="text-end">Due Amount:</th>
                                <th class="text-<?php echo $correct_due_amount > 0 ? 'danger' : 'success'; ?> due-amount-correct">
                                    ₹<?php echo number_format($correct_due_amount, 2); ?>
                                </th>
                            </tr>
                            
                            <!-- Savings Summary -->
                            <?php if(isset($sale_details['discount_amount']) && $sale_details['discount_amount'] > 0): ?>
                            <tr class="table-info">
                                <th colspan="5" class="text-center">
                                    <i class="bi bi-piggy-bank"></i> 
                                    Total Savings: ₹<?php echo number_format($sale_details['discount_amount'], 2); ?> 
                                    (<?php echo number_format(($sale_details['discount_amount'] / $display_subtotal) * 100, 1); ?>% off)
                                </th>
                            </tr>
                            <?php endif; ?>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>

        <!-- Payment History -->
        <div class="card payment-history-section">
            <div class="card-header">
                <h5 class="mb-0">Payment History (<?php echo $payment_summary['payment_count'] ?? 0; ?> payments)</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th>Date & Time</th>
                                <th>Amount</th>
                                <th>Payment Method</th>
                                <th>Reference #</th>
                                <th>Notes</th>
                                <th>Received By</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if(mysqli_num_rows($payments) > 0): ?>
                                <?php 
                                $running_total = 0;
                                while($payment = mysqli_fetch_assoc($payments)): 
                                    $running_total += $payment['payment_amount'];
                                ?>
                                <tr>
                                    <td><?php echo date('d-m-Y h:i A', strtotime($payment['payment_date'])); ?></td>
                                    <td class="text-success">₹<?php echo number_format($payment['payment_amount'], 2); ?></td>
                                    <td>
                                        <span class="badge bg-<?php 
                                            echo $payment['payment_method'] == 'cash' ? 'success' : 
                                                ($payment['payment_method'] == 'card' ? 'info' : 
                                                ($payment['payment_method'] == 'online' ? 'warning' : 'secondary')); 
                                        ?>">
                                            <?php echo ucfirst($payment['payment_method']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($payment['reference_number'] ?? '-'); ?></td>
                                    <td><?php echo htmlspecialchars($payment['notes'] ?? '-'); ?></td>
                                    <td><?php echo htmlspecialchars($payment['username'] ?? 'N/A'); ?></td>
                                </tr>
                                <?php endwhile; ?>
                                <tr class="table-info">
                                    <td colspan="5" class="text-end"><strong>Total Paid:</strong></td>
                                    <td><strong>₹<?php echo number_format($running_total, 2); ?></strong></td>
                                </tr>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" class="text-center">No payments recorded yet</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Payment Modal with Discount Option -->
    <div class="modal fade" id="addPaymentModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Add Payment</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="paymentForm">
                    <div class="modal-body">
                        <input type="hidden" name="sale_id" value="<?php echo $sale_id; ?>">
                        
                        <div class="mb-3">
                            <label class="form-label">Grand Total</label>
                            <input type="text" class="form-control" value="₹<?php echo number_format($grand_total, 2); ?>" readonly style="font-weight: bold; color: #28a745;">
                        </div>
                        
                        <?php if(isset($sale_details['discount_amount']) && $sale_details['discount_amount'] > 0): ?>
                        <div class="mb-3">
                            <label class="form-label">Discount Applied</label>
                            <input type="text" class="form-control" value="-₹<?php echo number_format($sale_details['discount_amount'], 2); ?>" readonly style="font-weight: bold; color: #17a2b8;">
                        </div>
                        <?php endif; ?>
                        
                        <div class="mb-3">
                            <label class="form-label">Current Due Amount</label>
                            <input type="text" class="form-control" id="currentDue" value="<?php echo $correct_due_amount; ?>" readonly style="font-weight: bold; color: #dc3545; font-size: 1.2em;">
                        </div>
                        
                        <!-- Discount Toggle Section -->
                        <?php if($_SESSION['role'] == 'admin'): ?>
                        <div class="discount-section">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="applyDiscount" name="apply_discount" value="1">
                                <label class="form-check-label discount-toggle" for="applyDiscount">
                                    <i class="bi bi-tags"></i> Apply Discount
                                </label>
                            </div>
                            
                            <div class="discount-inputs" id="discountInputs">
                                <div class="row">
                                    <div class="col-md-6 mb-2">
                                        <label class="form-label">Discount Type</label>
                                        <select class="form-control" name="discount_type" id="discountType">
                                            <option value="fixed">Fixed Amount (₹)</option>
                                            <option value="percentage">Percentage (%)</option>
                                        </select>
                                    </div>
                                    <div class="col-md-6 mb-2">
                                        <label class="form-label">Discount Value</label>
                                        <input type="number" step="0.01" class="form-control" name="discount_value" id="discountValue" min="0" onchange="calculateDiscount()">
                                    </div>
                                </div>
                                <div class="mb-2">
                                    <label class="form-label">Discount Note (Optional)</label>
                                    <input type="text" class="form-control" name="discount_note" placeholder="Reason for discount">
                                </div>
                                <div class="mb-2">
                                    <label class="form-label">New Grand Total After Discount</label>
                                    <input type="text" class="form-control" id="newGrandTotal" readonly style="font-weight: bold; color: #28a745;">
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <div class="mb-3">
                            <label for="payment_amount" class="form-label">Payment Amount *</label>
                            <input type="number" step="0.01" class="form-control" id="payment_amount" name="payment_amount" 
                                   max="<?php echo $correct_due_amount; ?>" required oninput="updateRemainingDue()">
                            <small class="text-muted">Maximum: ₹<?php echo number_format($correct_due_amount, 2); ?></small>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Remaining Due After Payment</label>
                            <input type="text" class="form-control" id="remainingDue" readonly style="font-weight: bold; color: #28a745;">
                        </div>
                        
                        <div class="mb-3">
                            <label for="payment_method" class="form-label">Payment Method *</label>
                            <select class="form-control" id="payment_method" name="payment_method" required>
                                <option value="cash">Cash</option>
                                <option value="card">Card</option>
                                <option value="online">Online</option>
                                <option value="bank_transfer">Bank Transfer</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label for="reference_number" class="form-label">Reference Number (Optional)</label>
                            <input type="text" class="form-control" id="reference_number" name="reference_number">
                        </div>
                        
                        <div class="mb-3">
                            <label for="notes" class="form-label">Notes (Optional)</label>
                            <textarea class="form-control" id="notes" name="notes" rows="2"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="add_payment" class="btn btn-primary" id="submitPayment">Add Payment</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
    function updateRemainingDue() {
        const currentDue = parseFloat(document.getElementById('currentDue').value) || 0;
        const paymentAmount = parseFloat(document.getElementById('payment_amount').value) || 0;
        const discountCheckbox = document.getElementById('applyDiscount');
        let finalDue = currentDue;
        
        // If discount is applied, adjust the due amount
        if (discountCheckbox && discountCheckbox.checked) {
            const discountValue = parseFloat(document.getElementById('discountValue').value) || 0;
            const discountType = document.getElementById('discountType').value;
            const grandTotal = <?php echo $grand_total; ?>;
            
            let discountAmount = 0;
            if (discountType === 'percentage') {
                discountAmount = (grandTotal * discountValue) / 100;
            } else {
                discountAmount = discountValue;
            }
            
            // Ensure discount doesn't exceed grand total
            if (discountAmount > grandTotal) {
                discountAmount = grandTotal;
            }
            
            finalDue = currentDue - discountAmount;
        }
        
        const remainingDue = finalDue - paymentAmount;
        
        let remainingElement = document.getElementById('remainingDue');
        remainingElement.value = '₹' + remainingDue.toFixed(2);
        
        if (remainingDue <= 0) {
            remainingElement.style.color = '#28a745';
            remainingElement.style.fontWeight = 'bold';
        } else {
            remainingElement.style.color = '#dc3545';
            remainingElement.style.fontWeight = 'bold';
        }
        
        // Validate payment amount
        if (paymentAmount > finalDue) {
            document.getElementById('payment_amount').setCustomValidity('Payment amount cannot exceed due amount after discount');
            document.getElementById('submitPayment').disabled = true;
        } else {
            document.getElementById('payment_amount').setCustomValidity('');
            document.getElementById('submitPayment').disabled = false;
        }
    }
    
    function calculateDiscount() {
        const grandTotal = <?php echo $grand_total; ?>;
        const discountValue = parseFloat(document.getElementById('discountValue').value) || 0;
        const discountType = document.getElementById('discountType').value;
        const currentDue = parseFloat(document.getElementById('currentDue').value) || 0;
        
        let discountAmount = 0;
        if (discountType === 'percentage') {
            discountAmount = (grandTotal * discountValue) / 100;
        } else {
            discountAmount = discountValue;
        }
        
        // Ensure discount doesn't exceed grand total
        if (discountAmount > grandTotal) {
            discountAmount = grandTotal;
        }
        
        const newGrandTotal = grandTotal - discountAmount;
        document.getElementById('newGrandTotal').value = '₹' + newGrandTotal.toFixed(2);
        
        // Update max payment amount
        const paymentInput = document.getElementById('payment_amount');
        paymentInput.max = currentDue - discountAmount;
        
        // Update remaining due
        updateRemainingDue();
    }
    
    document.addEventListener('DOMContentLoaded', function() {
        const discountCheckbox = document.getElementById('applyDiscount');
        const discountInputs = document.getElementById('discountInputs');
        
        if (discountCheckbox) {
            discountCheckbox.addEventListener('change', function() {
                if (this.checked) {
                    discountInputs.style.display = 'block';
                } else {
                    discountInputs.style.display = 'none';
                    document.getElementById('newGrandTotal').value = '';
                }
                updateRemainingDue();
            });
        }
        
        document.getElementById('addPaymentModal').addEventListener('shown.bs.modal', function () {
            updateRemainingDue();
        });
        
        // Add event listeners for discount inputs
        const discountValue = document.getElementById('discountValue');
        const discountType = document.getElementById('discountType');
        
        if (discountValue) {
            discountValue.addEventListener('input', calculateDiscount);
        }
        if (discountType) {
            discountType.addEventListener('change', calculateDiscount);
        }
    });
    </script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>