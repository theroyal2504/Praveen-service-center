<?php
require_once 'config.php';

if (!isLoggedIn()) {
    redirect('index.php');
}

$sale_id = $_GET['id'] ?? 0;

// Fetch sale details with customer information - Use grand_total if available
// Modified query to include vehicle fields from both sales and customers tables
$sale = mysqli_query($conn, "SELECT s.*, 
                                     c.customer_name, 
                                     c.phone, 
                                     c.address, 
                                     c.email,
                                     c.vehicle_registration as customer_vehicle,
                                     u.username,
                                     COALESCE(s.grand_total, s.total_amount) as invoice_total
                              FROM sales s
                              LEFT JOIN customers c ON s.customer_id = c.id
                              LEFT JOIN users u ON s.created_by = u.id
                              WHERE s.id = $sale_id");
$sale_details = mysqli_fetch_assoc($sale);

if (!$sale_details) {
    $_SESSION['error'] = "Sale not found!";
    redirect('sales.php');
}

// Fetch sale items
$items = mysqli_query($conn, "SELECT si.*, p.part_number, p.part_name
                              FROM sale_items si
                              JOIN parts_master p ON si.part_id = p.id
                              WHERE si.sale_id = $sale_id");

// Fetch payment summary
$payment_summary = mysqli_fetch_assoc(mysqli_query($conn, "SELECT 
                                                           COUNT(*) as payment_count,
                                                           SUM(payment_amount) as total_paid
                                                           FROM sale_payments WHERE sale_id = $sale_id"));

// Fetch business settings
$settings = [];
$settings_result = mysqli_query($conn, "SELECT setting_key, setting_value FROM system_settings");
while($row = mysqli_fetch_assoc($settings_result)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Calculate subtotal from items
$subtotal = 0;
mysqli_data_seek($items, 0);
while($item = mysqli_fetch_assoc($items)) {
    $subtotal += $item['quantity'] * $item['selling_price'];
}

// Grand Total from database (with discount applied)
$grand_total = $sale_details['invoice_total'];

// Calculate due amount based on Grand Total
$correct_due_amount = $grand_total - $sale_details['paid_amount'];
if ($correct_due_amount < 0) $correct_due_amount = 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice #<?php echo htmlspecialchars($sale_details['invoice_number']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        @media print {
            .no-print {
                display: none !important;
            }
            .invoice-box {
                border: none;
                box-shadow: none;
                margin: 0;
                padding: 20px;
            }
        }
        .invoice-box {
            max-width: 900px;
            margin: 8px auto;
            padding: 10px;
            border: 1px solid #ddd;
            box-shadow: 0 0 8px rgba(0, 0, 0, 0.1);
            font-size: 11px;
            line-height: 1.3;
            color: #333;
            background: white;
        }
        .invoice-header {
            text-align: center;
            margin-bottom: 3px;
            padding-bottom: 3px;
            border-bottom: 2px solid #f0f0f0;
        }
        .invoice-header h2 {
            color: #2c3e50;
            margin-bottom: 1px;
            margin-top: 0;
            font-weight: bold;
            font-size: 14px;
        }
        .business-info {
            text-align: center;
            margin-bottom: 2px;
            padding: 3px;
            background: #f8f9fa;
            border-radius: 5px;
            font-size: 10px;
        }
        .details-section {
            display: flex;
            flex-wrap: wrap;
            gap: 4px;
            justify-content: space-between;
            font-size: 10px;
        }
        .invoice-details {
            flex: 1 1 45%;
            margin: 0;
            padding: 6px;
            background: #f8f9fa;
            border-radius: 5px;
        }
        .customer-details {
            flex: 1 1 45%;
            margin: 0;
            padding: 6px;
            border: 1px solid #dee2e6;
            border-radius: 5px;
            background: #fff;
        }
        .customer-details h5 {
            color: #2c3e50;
            margin: 0 0 6px 0;
            padding-bottom: 4px;
            border-bottom: 2px solid #f0f0f0;
            font-size: 12px;
        }
        .items-table {
            width: 100%;
            margin-bottom: 10px;
            border-collapse: collapse;
            font-size: 10px;
        }
        .items-table th {
            background: #2c3e50;
            color: white;
            padding: 6px 8px;
            text-align: left;
        }
        .items-table td {
            padding: 4px 6px;
            border-bottom: 1px solid #dee2e6;
        }
        .items-table tfoot tr {
            background: #f8f9fa;
            font-weight: bold;
        }
        .payment-status {
            display: inline-block;
            padding: 5px 15px;
            border-radius: 20px;
            font-weight: bold;
            text-transform: uppercase;
            font-size: 12px;
        }
        .status-paid { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .status-partial { background: #fff3cd; color: #856404; border: 1px solid #ffeeba; }
        .status-pending { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .vehicle-badge {
            background: #e7f3ff;
            color: #004085;
            padding: 2px 25px;
            border-radius: 5px;
            font-weight: bold;
            border: 1px solid #b8daff;
        }
        .footer-note {
            margin-top: 20px;
            text-align: center;
            padding-top: 10px;
            border-top: 2px solid #f0f0f0;
            font-size: 11px;
            color: #7f8c8d;
        }
        .discount-info {
            background: #e7f3ff;
            color: #004085;
            padding: 5px 10px;
            border-radius: 5px;
            font-weight: bold;
            border: 1px solid #b8daff;
            display: inline-block;
            margin-bottom: 10px;
        }
        .grand-total {
            font-size: 1.2em;
            color: #28a745;
        }
        .due-amount {
            font-size: 1.1em;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <div class="container mt-4 no-print">
        <div class="btn-group mb-3">
            <button onclick="window.print()" class="btn btn-primary">
                <i class="bi bi-printer"></i> Print Invoice
            </button>
            <a href="sale_view.php?id=<?php echo $sale_id; ?>" class="btn btn-info">
                <i class="bi bi-eye"></i> View Sale Details
            </a>
            <a href="sales.php" class="btn btn-secondary">
                <i class="bi bi-arrow-left"></i> Back to Sales
            </a>
        </div>
    </div>

    <div class="invoice-box">
        <!-- Business Header -->
        <div class="invoice-header">
            <h2><?php echo htmlspecialchars($settings['business_name'] ?? 'PRAVEEN SERVICE CENTER'); ?></h2>
            <h4>Tax Invoice / Bill of Supply</h4>
            <div class="business-info">
                <?php if(!empty($settings['business_address'])): ?>
                <div><?php echo nl2br(htmlspecialchars($settings['business_address'])); ?></div>
                <?php endif; ?>
                <div>
                    <?php if(!empty($settings['business_phone'])): ?>
                    <span><i class="bi bi-telephone"></i> <?php echo htmlspecialchars($settings['business_phone']); ?></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Invoice Details -->
        <div class="details-section">
            <div class="invoice-details">
                <table>
                    <tr>
                        <td><strong>Invoice No:</strong></td>
                        <td><?php echo htmlspecialchars($sale_details['invoice_number']); ?></td>
                        <td><strong>Invoice Date:</strong></td>
                        <td><?php echo date('d-m-Y', strtotime($sale_details['sale_date'])); ?></td>
                    </tr>
                    <tr>
                        <td><strong>Payment Status:</strong></td>
                        <td colspan="3">
                            <span class="payment-status status-<?php echo $sale_details['payment_status']; ?>">
                                <?php 
                                if($sale_details['payment_status'] == 'paid') echo 'PAID';
                                elseif($sale_details['payment_status'] == 'partial') echo 'PARTIAL';
                                else echo 'PENDING';
                                ?>
                            </span>
                        </td>
                    </tr>
                </table>
            </div>
            <div class="customer-details">
                <h5><i class="bi bi-person"></i> Customer Details</h5>
                <table style="width: 100%;">
                    <tr>
                        <td style="width: 120px;"><strong>Name:</strong></td>
                        <td><?php echo htmlspecialchars($sale_details['customer_name'] ?? 'Walk-in Customer'); ?></td>
                    </tr>
                    <?php if(!empty($sale_details['phone'])): ?>
                    <tr>
                        <td><strong>Phone:</strong></td>
                        <td><?php echo htmlspecialchars($sale_details['phone']); ?></td>
                    </tr>
                    <?php endif; ?>
                    <tr>
                        <td><strong>Vehicle:</strong></td>
                        <td>
                            <?php 
                            // Check multiple possible sources for vehicle number
                            $vehicle_number = '';
                            
                            // Priority 1: Direct vehicle field in sales table (if exists)
                            if (isset($sale_details['vehicle_number']) && !empty($sale_details['vehicle_number'])) {
                                $vehicle_number = $sale_details['vehicle_number'];
                            }
                            // Priority 2: Vehicle registration from customers table
                            elseif (!empty($sale_details['customer_vehicle'])) {
                                $vehicle_number = $sale_details['customer_vehicle'];
                            }
                            // Priority 3: Check if vehicle_registration exists directly
                            elseif (isset($sale_details['vehicle_registration']) && !empty($sale_details['vehicle_registration'])) {
                                $vehicle_number = $sale_details['vehicle_registration'];
                            }
                            
                            if (!empty($vehicle_number)): 
                            ?>
                                <span class="vehicle-badge">
                                    <?php echo htmlspecialchars($vehicle_number); ?>
                                </span>
                            <?php else: ?>
                                <span class="text-muted">N/A</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>
            </div>
        </div>

        <!-- Discount Information -->
        <?php if(isset($sale_details['discount_amount']) && $sale_details['discount_amount'] > 0): ?>
        <div class="discount-info text-center mb-2">
            <i class="bi bi-tags"></i> 
            Discount Applied: 
            <?php if($sale_details['discount_type'] == 'percentage'): ?>
                <?php echo $sale_details['discount_value']; ?>% 
            <?php else: ?>
                ₹<?php echo number_format($sale_details['discount_value'], 2); ?>
            <?php endif; ?>
            (Savings: ₹<?php echo number_format($sale_details['discount_amount'], 2); ?>)
        </div>
        <?php endif; ?>

        <!-- Items Table -->
        <table class="items-table">
            <thead>
                <tr>
                    <th>Sl. No.</th>
                    <th>Part Number</th>
                    <th>Part Description</th>
                    <th>Qty</th>
                    <th>Unit Price (₹)</th>
                    <th>Total (₹)</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $sl_no = 1;
                $display_subtotal = 0;
                mysqli_data_seek($items, 0);
                while($item = mysqli_fetch_assoc($items)): 
                    $total = $item['quantity'] * $item['selling_price'];
                    $display_subtotal += $total;
                ?>
                <tr>
                    <td><?php echo $sl_no++; ?></td>
                    <td><?php echo htmlspecialchars($item['part_number']); ?></td>
                    <td><?php echo htmlspecialchars($item['part_name']); ?></td>
                    <td><?php echo $item['quantity']; ?></td>
                    <td class="text-end">₹<?php echo number_format($item['selling_price'], 2); ?></td>
                    <td class="text-end">₹<?php echo number_format($total, 2); ?></td>
                </tr>
                <?php endwhile; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="5" class="text-end"><strong>Subtotal:</strong></td>
                    <td class="text-end">₹<?php echo number_format($display_subtotal, 2); ?></td>
                </tr>
                <?php if(isset($sale_details['discount_amount']) && $sale_details['discount_amount'] > 0): ?>
                <tr>
                    <td colspan="5" class="text-end"><strong>Discount:</strong></td>
                    <td class="text-end text-info">-₹<?php echo number_format($sale_details['discount_amount'], 2); ?></td>
                </tr>
                <?php endif; ?>
                <tr>
                    <td colspan="5" class="text-end"><strong>Grand Total:</strong></td>
                    <td class="text-end grand-total"><strong>₹<?php echo number_format($grand_total, 2); ?></strong></td>
                </tr>
                <tr style="background: #e8f5e9;">
                    <td colspan="5" class="text-end"><strong>Amount Paid:</strong></td>
                    <td class="text-end text-success"><strong>₹<?php echo number_format($sale_details['paid_amount'], 2); ?></strong></td>
                </tr>
                <?php if($correct_due_amount > 0): ?>
                <tr style="background: #ffebee;">
                    <td colspan="5" class="text-end"><strong>Balance Due:</strong></td>
                    <td class="text-end text-danger due-amount"><strong>₹<?php echo number_format($correct_due_amount, 2); ?></strong></td>
                </tr>
                <?php else: ?>
                <tr style="background: #e8f5e9;">
                    <td colspan="5" class="text-end"><strong>Balance Due:</strong></td>
                    <td class="text-end text-success due-amount"><strong>₹0.00</strong></td>
                </tr>
                <?php endif; ?>
            </tfoot>
        </table>

        <!-- Amount in Words (using grand total after discount) -->
        <div class="mt-3">
            <strong>Amount in Words:</strong> 
            <?php
            $amount = floatval($grand_total);
            $integerPart = intval(floor($amount));
            $fractionPart = round(($amount - $integerPart) * 100);

            if (class_exists('NumberFormatter')) {
                try {
                    $f = new NumberFormatter("en", NumberFormatter::SPELLOUT);
                    $words = $f->format($integerPart);
                } catch (Exception $e) {
                    $words = (string)$integerPart;
                }
            } else {
                function _num2words($n) {
                    $units = ["", "one", "two", "three", "four", "five", "six", "seven", "eight", "nine", "ten", "eleven", "twelve", "thirteen", "fourteen", "fifteen", "sixteen", "seventeen", "eighteen", "nineteen"];
                    $tens = ["", "", "twenty", "thirty", "forty", "fifty", "sixty", "seventy", "eighty", "ninety"];

                    if ($n < 20) return $units[$n];
                    if ($n < 100) return $tens[intval($n/10)] . ($n%10 ? ' ' . $units[$n%10] : '');
                    if ($n < 1000) return _num2words(intval($n/100)) . ' hundred' . ($n%100 ? ' ' . _num2words($n%100) : '');
                    if ($n < 1000000) return _num2words(intval($n/1000)) . ' thousand' . ($n%1000 ? ' ' . _num2words($n%1000) : '');
                    if ($n < 1000000000) return _num2words(intval($n/1000000)) . ' million' . ($n%1000000 ? ' ' . _num2words($n%1000000) : '');
                    return (string)$n;
                }
                $words = _num2words($integerPart);
            }

            $words = $words ? ucwords($words) : 'Zero';
            if ($fractionPart > 0) {
                $words .= ' and ' . ($fractionPart) . '/100';
            }
            echo $words . " Rupees Only";
            ?>
        </div>

        <!-- Footer -->
        <div class="footer-note">
            <p>Thank you for your business!</p>
            <p>Generated on: <?php echo date('d-m-Y h:i A', strtotime($sale_details['created_at'])); ?> by <?php echo htmlspecialchars($sale_details['username']); ?></p>
            <?php if($payment_summary['payment_count'] > 1): ?>
            <p class="small">Note: This invoice has <?php echo $payment_summary['payment_count']; ?> payments. Total paid: ₹<?php echo number_format($payment_summary['total_paid'], 2); ?></p>
            <?php endif; ?>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>