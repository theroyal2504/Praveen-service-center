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
    
    // Start transaction
    mysqli_begin_transaction($conn);
    
    try {
        // Get current sale details
        $current_sale = mysqli_fetch_assoc(mysqli_query($conn, "SELECT total_amount, paid_amount, due_amount FROM sales WHERE id = $sale_id"));
        
        $new_paid_amount = $current_sale['paid_amount'] + $payment_amount;
        $new_due_amount = $current_sale['total_amount'] - $new_paid_amount;
        
        // Determine payment status
        if ($new_due_amount <= 0) {
            $payment_status = 'paid';
            $new_due_amount = 0; // Ensure due amount is exactly 0
        } else {
            $payment_status = 'partial';
        }
        
        // Insert payment record
        $payment_query = "INSERT INTO sale_payments (sale_id, payment_amount, payment_method, reference_number, notes, received_by) 
                         VALUES ($sale_id, $payment_amount, '$payment_method', '$reference_number', '$notes', $received_by)";
        if (!mysqli_query($conn, $payment_query)) {
            throw new Exception("Error inserting payment: " . mysqli_error($conn));
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
        $_SESSION['success'] = "Payment of ₹" . number_format($payment_amount, 2) . " added successfully! Due amount is now ₹" . number_format($new_due_amount, 2);
        
    } catch (Exception $e) {
        mysqli_rollback($conn);
        $_SESSION['error'] = "Error adding payment: " . $e->getMessage();
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
        /* smaller fonts for payment summary and breakdown */
        .payment-summary .card-body h6 { font-size: 10px; margin-bottom: 4px; }
        .payment-summary .card-body h3 { font-size: 16px; margin-bottom: 0; }
        .payment-summary .card-body { padding: 8px; }
        .payment-breakdown .card-body strong { font-size: 11px; display: block; margin-bottom: 2px; }
        .payment-breakdown .card-body h4 { font-size: 16px; margin: 0; }
        .payment-breakdown .border { padding: 6px; }
        .payment-breakdown .row.mt-3 { margin-top: 8px !important; }
        /* reduce spacing between sections */
        .payment-summary, .payment-breakdown, .card.mb-4 { margin-bottom: 10px !important; }
        .payment-summary .row > div, .payment-breakdown .row > div { padding-bottom: 0 !important; }
        /* specific shrink for items sold before history */
        .items-sold-section { margin-bottom: 5px !important; }
        .payment-history-section { margin-top: 5px !important; }
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
                <?php if($sale_details['due_amount'] > 0): ?>
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
                    <div class="col-md-3">
                        <strong>Created By:</strong> <?php echo htmlspecialchars($sale_details['username']); ?>
                    </div>
                </div>
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
                <?php if($sale_details['address']): ?>
                <div class="row mt-2">
                    <div class="col-12">
                        <strong>Address:</strong> <?php echo htmlspecialchars($sale_details['address']); ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Payment Summary -->
        <div class="row mb-4 payment-summary">
            <div class="col-md-3">
                <div class="card bg-success text-white">
                    <div class="card-body">
                        <h6>Total Amount</h6>
                        <h3>₹<?php echo number_format($sale_details['total_amount'], 2); ?></h3>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-info text-white">
                    <div class="card-body">
                        <h6>Paid Amount</h6>
                        <h3>₹<?php echo number_format($sale_details['paid_amount'], 2); ?></h3>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-<?php echo $sale_details['due_amount'] > 0 ? 'danger' : 'success'; ?> text-white">
                    <div class="card-body">
                        <h6>Due Amount</h6>
                        <h3>₹<?php echo number_format($sale_details['due_amount'], 2); ?></h3>
                        <?php if($sale_details['due_amount'] <= 0): ?>
                        <span class="badge bg-light text-dark">FULLY PAID</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-warning text-white">
                    <div class="card-body">
                        <h6>Payment Status</h6>
                        <h3>
                            <?php 
                            if($sale_details['payment_status'] == 'paid') echo '<span class="badge bg-success">PAID</span>';
                            elseif($sale_details['payment_status'] == 'partial') echo '<span class="badge bg-warning">PARTIAL</span>';
                            else echo '<span class="badge bg-danger">PENDING</span>';
                            ?>
                        </h3>
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
                        <!--<div class="row mt-3">
                            <div class="col-12 text-end">
                                <strong>Total Paid: </strong> ₹<?php echo number_format($payment_summary['total_paid'] ?? 0, 2); ?>
                            </div>  
                        </div>-->
                    </div>
                </div>
            </div>
        </div>

        <!-- Items Sold -->
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
                            $subtotal = 0;
                            while($item = mysqli_fetch_assoc($items)): 
                                $total = $item['quantity'] * $item['selling_price'];
                                $subtotal += $total;
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
                                <th>₹<?php echo number_format($subtotal, 2); ?></th>
                            </tr>
                            <tr>
                                <th colspan="4" class="text-end">Grand Total:</th>
                                <th>₹<?php echo number_format($sale_details['total_amount'], 2); ?></th>
                            </tr>
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

    <!-- Add Payment Modal -->
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
                            <label class="form-label">Current Due Amount</label>
                            <input type="text" class="form-control" id="currentDue" value="<?php echo $sale_details['due_amount']; ?>" readonly style="font-weight: bold; color: #dc3545; font-size: 1.2em;">
                        </div>
                        
                        <div class="mb-3">
                            <label for="payment_amount" class="form-label">Payment Amount *</label>
                            <input type="number" step="0.01" class="form-control" id="payment_amount" name="payment_amount" 
                                   max="<?php echo $sale_details['due_amount']; ?>" required oninput="updateRemainingDue()">
                            <small class="text-muted">Maximum: ₹<?php echo number_format($sale_details['due_amount'], 2); ?></small>
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
        const remainingDue = currentDue - paymentAmount;
        
        let remainingElement = document.getElementById('remainingDue');
        remainingElement.value = '₹' + remainingDue.toFixed(2);
        
        // Change color based on remaining amount
        if (remainingDue <= 0) {
            remainingElement.style.color = '#28a745';
            remainingElement.style.fontWeight = 'bold';
        } else {
            remainingElement.style.color = '#dc3545';
            remainingElement.style.fontWeight = 'bold';
        }
        
        // Validate payment amount
        if (paymentAmount > currentDue) {
            document.getElementById('payment_amount').setCustomValidity('Payment amount cannot exceed due amount');
            document.getElementById('submitPayment').disabled = true;
        } else {
            document.getElementById('payment_amount').setCustomValidity('');
            document.getElementById('submitPayment').disabled = false;
        }
    }
    
    // Initialize on modal open
    document.getElementById('addPaymentModal').addEventListener('shown.bs.modal', function () {
        updateRemainingDue();
    });
    </script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>