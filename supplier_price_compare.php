<?php
require_once 'config.php';

if (!isLoggedIn()) {
    redirect('index.php');
}

// Only admin can access
if (!isAdmin()) {
    redirect('dashboard.php');
}

// ============ SUPPLIER PRICE COMPARE SECTION ============

// Query to get all suppliers
$suppliers_query = "SELECT id, supplier_name, contact_person, phone, email FROM suppliers ORDER BY supplier_name";
$suppliers_result = mysqli_query($conn, $suppliers_query);

// Query to get all parts with their latest purchase prices from each supplier
$supplier_price_compare_query = "
    SELECT 
        p.id as part_id,
        p.part_number,
        p.part_name,
        c.category_name,
        s.id as supplier_id,
        s.supplier_name,
        pi.purchase_price,
        pi.quantity,
        pur.purchase_date,
        pur.invoice_number,
        (
            SELECT purchase_price 
            FROM purchase_items pi2 
            WHERE pi2.part_id = p.id 
            ORDER BY pi2.id DESC 
            LIMIT 1
        ) as latest_price
    FROM parts_master p
    LEFT JOIN categories c ON p.category_id = c.id
    LEFT JOIN purchase_items pi ON p.id = pi.part_id
    LEFT JOIN purchases pur ON pi.purchase_id = pur.id
    LEFT JOIN suppliers s ON pur.supplier_id = s.id
    WHERE pi.id IS NOT NULL AND s.id IS NOT NULL
    ORDER BY p.part_name, s.supplier_name, pur.purchase_date DESC";

$supplier_price_compare_result = mysqli_query($conn, $supplier_price_compare_query);

// Organize data for matrix display
$price_matrix = [];
$all_parts = [];
$all_suppliers = [];

if ($supplier_price_compare_result && mysqli_num_rows($supplier_price_compare_result) > 0) {
    while($row = mysqli_fetch_assoc($supplier_price_compare_result)) {
        $part_id = $row['part_id'];
        $supplier_id = $row['supplier_id'];
        
        // Store unique parts
        if (!isset($all_parts[$part_id])) {
            $all_parts[$part_id] = [
                'id' => $part_id,
                'part_number' => $row['part_number'],
                'part_name' => $row['part_name'],
                'category' => $row['category_name'] ?? 'Uncategorized',
                'latest_price' => $row['latest_price']
            ];
        }
        
        // Store unique suppliers
        if (!isset($all_suppliers[$supplier_id])) {
            $all_suppliers[$supplier_id] = [
                'id' => $supplier_id,
                'name' => $row['supplier_name']
            ];
        }
        
        // Store price in matrix
        $price_matrix[$part_id][$supplier_id] = [
            'price' => $row['purchase_price'],
            'quantity' => $row['quantity'],
            'date' => $row['purchase_date'],
            'invoice' => $row['invoice_number']
        ];
    }
}

// Get price statistics for each part
$part_stats = [];
foreach ($all_parts as $part_id => $part) {
    if (isset($price_matrix[$part_id]) && count($price_matrix[$part_id]) > 1) {
        $prices = array_column($price_matrix[$part_id], 'price');
        $min_price = min($prices);
        $max_price = max($prices);
        $avg_price = array_sum($prices) / count($prices);
        
        $part_stats[$part_id] = [
            'min_price' => $min_price,
            'max_price' => $max_price,
            'avg_price' => $avg_price,
            'difference' => $max_price - $min_price,
            'variation_percent' => $min_price > 0 ? round(($max_price - $min_price) / $min_price * 100, 2) : 0,
            'supplier_count' => count($price_matrix[$part_id])
        ];
    }
}

// Calculate average variation
$avg_variation = 0;
if (count($part_stats) > 0) {
    $total_variation = array_sum(array_column($part_stats, 'variation_percent'));
    $avg_variation = round($total_variation / count($part_stats), 2);
}

// Get unique categories for filter
$categories = array_unique(array_column($all_parts, 'category'));
sort($categories);

// ============ END OF SUPPLIER PRICE COMPARE SECTION ============

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Supplier Price Compare - Bike Management System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <style>
        body {
            background: #f8f9fa;
            font-size: 0.9rem;
        }
        
        /* Header Gradient - Smaller */
        .page-header {
            background: linear-gradient(135deg, #6f42c1, #007bff);
            color: white;
            padding: 12px 20px;
            border-radius: 8px;
            margin-bottom: 15px;
            box-shadow: 0 2px 8px rgba(111, 66, 193, 0.15);
        }
        .page-header h4 {
            font-size: 1.3rem;
            margin-bottom: 2px;
        }
        .page-header p {
            font-size: 0.8rem;
            margin-top: 2px;
        }
        .back-link {
            font-size: 0.85rem;
        }
        
        /* Stats Cards - Smaller */
        .stats-card {
            background: white;
            border-radius: 8px;
            padding: 10px 12px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.03);
            transition: transform 0.2s;
            border-left: 3px solid #6f42c1;
        }
        .stats-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 3px 10px rgba(0,0,0,0.08);
        }
        .stats-card .stats-icon {
            font-size: 1.8rem;
            color: #6f42c1;
            opacity: 0.7;
        }
        .stats-card small {
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        .stats-card h3 {
            font-size: 1.4rem;
            margin-bottom: 0;
            font-weight: 600;
        }
        
        /* Filter Section - Compact */
        .filter-section {
            background: white;
            padding: 12px 15px;
            border-radius: 8px;
            box-shadow: 0 1px 5px rgba(0,0,0,0.03);
            margin-bottom: 15px;
        }
        .filter-section .form-label {
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            margin-bottom: 3px;
        }
        .filter-section .form-select,
        .filter-section .form-control {
            font-size: 0.85rem;
            padding: 0.25rem 0.5rem;
            height: auto;
        }
        
        /* Price Matrix - Compact */
        .price-matrix {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            overflow-x: auto;
            margin-bottom: 15px;
        }
        .price-matrix table {
            margin-bottom: 0;
            min-width: 100%;
            font-size: 0.8rem;
        }
        .price-matrix th {
            background: #343a40;
            color: white;
            padding: 8px 6px;
            font-size: 0.75rem;
            font-weight: 500;
            text-align: center;
            vertical-align: middle;
            position: sticky;
            top: 0;
            z-index: 10;
            white-space: nowrap;
        }
        .price-matrix td {
            padding: 6px 4px;
            border: 1px solid #dee2e6;
            text-align: center;
            vertical-align: middle;
            font-size: 0.75rem;
        }
        .price-matrix .part-info {
            background: #f8f9fa;
            font-weight: 600;
            text-align: left;
            position: sticky;
            left: 0;
            z-index: 5;
            font-size: 0.75rem;
        }
        .price-matrix .supplier-col {
            min-width: 120px;
        }
        .price-matrix .badge-supplier {
            background: #6c757d;
            color: white;
            padding: 2px 5px;
            border-radius: 10px;
            font-size: 0.65rem;
            font-weight: normal;
        }
        .price-matrix .badge {
            font-size: 0.65rem;
            padding: 2px 5px;
        }
        .price-matrix small {
            font-size: 0.65rem;
        }
        .price-matrix strong {
            font-size: 0.75rem;
        }
        
        /* Price Colors */
        .price-lowest {
            background-color: #d4edda !important;
            color: #155724;
            font-weight: 600;
        }
        .price-highest {
            background-color: #f8d7da !important;
            color: #721c24;
            font-weight: 600;
        }
        
        /* Variation Indicators - Smaller */
        .variation-indicator {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            display: inline-block;
            margin-right: 3px;
        }
        .variation-high { background: #dc3545; }
        .variation-medium { background: #fd7e14; }
        .variation-low { background: #28a745; }
        
        /* Export Buttons */
        .btn-sm {
            font-size: 0.75rem;
            padding: 0.25rem 0.8rem;
        }
        
        /* Alert Box */
        .alert {
            font-size: 0.9rem;
            padding: 1rem;
        }
        
        /* Table text */
        .table td, .table th {
            font-size: 0.75rem;
        }
        
        /* Container padding */
        .container-fluid {
            padding-left: 15px;
            padding-right: 15px;
        }
        
        /* Row spacing */
        .row {
            margin-left: -8px;
            margin-right: -8px;
        }
        .col-md-3, .col-md-4 {
            padding-left: 8px;
            padding-right: 8px;
        }
    </style>
</head>
<body>
    <!-- Navigation - Compact -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark py-2">
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
                        <span class="nav-link text-white py-1 fs-7">
                            <i class="bi bi-person-circle"></i> <?php echo $_SESSION['username']; ?> (<?php echo $_SESSION['role']; ?>)
                        </span>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link py-1 fs-7" href="logout.php">
                            <i class="bi bi-box-arrow-right"></i> Logout
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container-fluid mt-3">
        <!-- Page Header -->
        <div class="page-header d-flex justify-content-between align-items-center">
            <div>
                <a href="dashboard.php" class="back-link me-2 text-white-50">
                    <i class="bi bi-arrow-left"></i> Dashboard
                </a>
                <h4 class="d-inline-block mb-0">
                    <i class="bi bi-currency-exchange"></i> Supplier Price Compare
                </h4>
                <p class="mb-0 mt-1 text-white-50">Compare purchase prices across suppliers</p>
            </div>
        </div>

        <?php if (!empty($all_parts) && !empty($all_suppliers)): ?>
        
        <!-- Summary Cards - Compact -->
        <div class="row mb-3">
            <div class="col-md-3 mb-2">
                <div class="stats-card d-flex justify-content-between align-items-center">
                    <div>
                        <small class="text-muted">Total Parts</small>
                        <h3 class="mb-0"><?php echo count($all_parts); ?></h3>
                    </div>
                    <div class="stats-icon">
                        <i class="bi bi-box-seam"></i>
                    </div>
                </div>
            </div>
            <div class="col-md-3 mb-2">
                <div class="stats-card d-flex justify-content-between align-items-center">
                    <div>
                        <small class="text-muted">Total Suppliers</small>
                        <h3 class="mb-0"><?php echo count($all_suppliers); ?></h3>
                    </div>
                    <div class="stats-icon">
                        <i class="bi bi-truck"></i>
                    </div>
                </div>
            </div>
            <div class="col-md-3 mb-2">
                <div class="stats-card d-flex justify-content-between align-items-center">
                    <div>
                        <small class="text-muted">Multi-Supplier Parts</small>
                        <h3 class="mb-0"><?php echo count($part_stats); ?></h3>
                    </div>
                    <div class="stats-icon">
                        <i class="bi bi-diagram-3"></i>
                    </div>
                </div>
            </div>
            <div class="col-md-3 mb-2">
                <div class="stats-card d-flex justify-content-between align-items-center">
                    <div>
                        <small class="text-muted">Avg Variation</small>
                        <h3 class="mb-0 <?php echo $avg_variation > 30 ? 'text-danger' : ($avg_variation > 15 ? 'text-warning' : 'text-success'); ?>">
                            <?php echo $avg_variation; ?>%
                        </h3>
                    </div>
                    <div class="stats-icon">
                        <i class="bi bi-graph-up-arrow"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filter Section - Compact -->
        <div class="filter-section">
            <div class="row g-2">
                <div class="col-md-4">
                    <label class="form-label"><i class="bi bi-tags"></i> Category</label>
                    <select class="form-select" id="categoryFilter">
                        <option value="all">All Categories</option>
                        <?php foreach($categories as $cat): ?>
                        <option value="<?php echo htmlspecialchars($cat); ?>"><?php echo htmlspecialchars($cat); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label"><i class="bi bi-funnel"></i> Filter by</label>
                    <select class="form-select" id="variationFilter">
                        <option value="all">All Parts</option>
                        <option value="multiple">Multiple Suppliers</option>
                        <option value="single">Single Supplier</option>
                        <option value="high">High Variation (>30%)</option>
                        <option value="medium">Medium Variation (15-30%)</option>
                        <option value="low">Low Variation (<15%)</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label"><i class="bi bi-search"></i> Search Part</label>
                    <input type="text" class="form-control" id="searchPart" placeholder="Part name or number...">
                </div>
            </div>
        </div>

        <!-- Price Matrix Table - Compact -->
        <div class="price-matrix">
            <table class="table table-bordered" id="priceMatrixTable">
                <thead>
                    <tr>
                        <th style="min-width: 180px;">Part Details</th>
                        <?php foreach($all_suppliers as $supplier): ?>
                        <th class="supplier-col">
                            <?php echo htmlspecialchars($supplier['name']); ?>
                            <br><small class="badge-supplier">ID: <?php echo $supplier['id']; ?></small>
                        </th>
                        <?php endforeach; ?>
                        <th style="min-width: 140px;">Statistics</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($all_parts as $part_id => $part): ?>
                    <tr class="part-row" data-category="<?php echo htmlspecialchars($part['category']); ?>" 
                        data-part-name="<?php echo strtolower(htmlspecialchars($part['part_name'])); ?>"
                        data-part-number="<?php echo strtolower(htmlspecialchars($part['part_number'])); ?>"
                        data-supplier-count="<?php echo isset($price_matrix[$part_id]) ? count($price_matrix[$part_id]) : 0; ?>"
                        data-variation="<?php echo isset($part_stats[$part_id]) ? $part_stats[$part_id]['variation_percent'] : 0; ?>">
                        
                        <!-- Part Info -->
                        <td class="part-info">
                            <strong><?php echo htmlspecialchars($part['part_number']); ?></strong>
                            <br><?php echo htmlspecialchars($part['part_name']); ?>
                            <br><small class="text-muted"><?php echo htmlspecialchars($part['category']); ?></small>
                            <?php if(isset($part_stats[$part_id])): ?>
                            <br>
                            <span class="variation-indicator <?php 
                                if($part_stats[$part_id]['variation_percent'] > 30) echo 'variation-high';
                                elseif($part_stats[$part_id]['variation_percent'] > 15) echo 'variation-medium';
                                else echo 'variation-low';
                            ?>"></span>
                            <small><?php echo $part_stats[$part_id]['supplier_count']; ?> suppliers</small>
                            <?php endif; ?>
                        </td>
                        
                        <!-- Supplier Prices -->
                        <?php foreach($all_suppliers as $supplier_id => $supplier): ?>
                        <td class="<?php 
                            if(isset($price_matrix[$part_id][$supplier_id])) {
                                $price = $price_matrix[$part_id][$supplier_id]['price'];
                                if(isset($part_stats[$part_id])) {
                                    if($price == $part_stats[$part_id]['min_price']) echo 'price-lowest';
                                    elseif($price == $part_stats[$part_id]['max_price']) echo 'price-highest';
                                }
                            }
                        ?>">
                            <?php if(isset($price_matrix[$part_id][$supplier_id])): 
                                $data = $price_matrix[$part_id][$supplier_id];
                            ?>
                                <strong>₹<?php echo number_format($data['price'], 2); ?></strong>
                                <br><small>Qty: <?php echo $data['quantity']; ?></small>
                                <br><small><?php echo date('d-m', strtotime($data['date'])); ?></small>
                                <br><span class="badge bg-secondary"><?php echo $data['invoice']; ?></span>
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <?php endforeach; ?>
                        
                        <!-- Statistics -->
                        <td>
                            <?php if(isset($part_stats[$part_id])): 
                                $stats = $part_stats[$part_id];
                            ?>
                                <div class="text-start">
                                    <small>Min: <span class="text-success">₹<?php echo number_format($stats['min_price'], 0); ?></span></small><br>
                                    <small>Max: <span class="text-danger">₹<?php echo number_format($stats['max_price'], 0); ?></span></small><br>
                                    <small>Avg: ₹<?php echo number_format($stats['avg_price'], 0); ?></small><br>
                                    <small>Diff: <strong>₹<?php echo number_format($stats['difference'], 0); ?></strong></small><br>
                                    <small>Var: <strong class="<?php 
                                        if($stats['variation_percent'] > 30) echo 'text-danger';
                                        elseif($stats['variation_percent'] > 15) echo 'text-warning';
                                        else echo 'text-success';
                                    ?>"><?php echo $stats['variation_percent']; ?>%</strong></small>
                                </div>
                            <?php else: ?>
                                <span class="text-muted">Single supplier</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Export Options - Compact -->
        <div class="text-end mb-3">
            <button class="btn btn-sm btn-outline-secondary" onclick="exportToCSV()">
                <i class="bi bi-download"></i> Export CSV
            </button>
            <button class="btn btn-sm btn-outline-primary" onclick="printComparison()">
                <i class="bi bi-printer"></i> Print
            </button>
        </div>

        <?php else: ?>
        <div class="alert alert-info text-center py-4">
            <i class="bi bi-info-circle fs-2"></i>
            <h5 class="mt-2">No Supplier Price Data Available</h5>
            <p class="mb-3">Start adding purchases from different suppliers to see price comparisons.</p>
            <a href="purchases.php" class="btn btn-primary">
                <i class="bi bi-plus-circle"></i> Add New Purchase
            </a>
        </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        setupFilters();
    });
    
    // Filter functionality
    function setupFilters() {
        const categoryFilter = document.getElementById('categoryFilter');
        const variationFilter = document.getElementById('variationFilter');
        const searchPart = document.getElementById('searchPart');
        
        function filterTable() {
            const category = categoryFilter.value;
            const variation = variationFilter.value;
            const search = searchPart.value.toLowerCase();
            
            document.querySelectorAll('.part-row').forEach(row => {
                let show = true;
                
                // Category filter
                if (category !== 'all' && row.dataset.category !== category) {
                    show = false;
                }
                
                // Variation filter
                if (variation !== 'all') {
                    const supplierCount = parseInt(row.dataset.supplierCount);
                    const varPercent = parseFloat(row.dataset.variation);
                    
                    if (variation === 'multiple' && supplierCount <= 1) show = false;
                    if (variation === 'single' && supplierCount > 1) show = false;
                    if (variation === 'high' && varPercent <= 30) show = false;
                    if (variation === 'medium' && (varPercent <= 15 || varPercent > 30)) show = false;
                    if (variation === 'low' && varPercent >= 15) show = false;
                }
                
                // Search filter
                if (search) {
                    const partName = row.dataset.partName;
                    const partNumber = row.dataset.partNumber;
                    if (!partName.includes(search) && !partNumber.includes(search)) {
                        show = false;
                    }
                }
                
                row.style.display = show ? '' : 'none';
            });
        }
        
        categoryFilter.addEventListener('change', filterTable);
        variationFilter.addEventListener('change', filterTable);
        searchPart.addEventListener('keyup', filterTable);
    }
    
    // Export to CSV
    function exportToCSV() {
        let csv = [];
        let rows = document.querySelectorAll('#priceMatrixTable tr');
        
        for (let i = 0; i < rows.length; i++) {
            let row = [], cols = rows[i].querySelectorAll('td, th');
            
            for (let j = 0; j < cols.length; j++) {
                let text = cols[j].innerText.replace(/,/g, ' ').replace(/\n/g, ' ');
                row.push('"' + text + '"');
            }
            
            csv.push(row.join(','));
        }
        
        let csvContent = csv.join('\n');
        let blob = new Blob([csvContent], { type: 'text/csv' });
        let url = window.URL.createObjectURL(blob);
        let a = document.createElement('a');
        a.href = url;
        a.download = 'supplier_price_comparison.csv';
        a.click();
    }
    
    // Print comparison
    function printComparison() {
        window.print();
    }
    </script>
</body>
</html>