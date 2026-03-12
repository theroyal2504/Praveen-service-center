<?php
require_once 'config.php';

if (!isLoggedIn()) {
    redirect('index.php');
}

// Only admin can access this page
if (!isAdmin()) {
    redirect('dashboard.php');
}

// Get all suppliers
$suppliers_query = "SELECT id, supplier_name, contact_person, phone, email,  FROM suppliers ORDER BY supplier_name";
$suppliers_result = mysqli_query($conn, $suppliers_query);
$suppliers = [];
while($row = mysqli_fetch_assoc($suppliers_result)) {
    $suppliers[$row['id']] = $row;
}

// Get all parts with their purchase prices from each supplier
$price_query = "
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
            SELECT AVG(purchase_price) 
            FROM purchase_items pi2 
            WHERE pi2.part_id = p.id
        ) as avg_price_all
    FROM parts_master p
    LEFT JOIN categories c ON p.category_id = c.id
    LEFT JOIN purchase_items pi ON p.id = pi.part_id
    LEFT JOIN purchases pur ON pi.purchase_id = pur.id
    LEFT JOIN suppliers s ON pur.supplier_id = s.id
    WHERE pi.id IS NOT NULL AND s.id IS NOT NULL
    ORDER BY p.part_name, s.supplier_name, pur.purchase_date DESC";

$price_result = mysqli_query($conn, $price_query);

// Organize data for matrix display
$price_matrix = [];
$parts_list = [];
$supplier_list = [];

if ($price_result && mysqli_num_rows($price_result) > 0) {
    while($row = mysqli_fetch_assoc($price_result)) {
        $part_id = $row['part_id'];
        $supplier_id = $row['supplier_id'];
        
        // Store unique parts
        if (!isset($parts_list[$part_id])) {
            $parts_list[$part_id] = [
                'id' => $part_id,
                'part_number' => $row['part_number'],
                'part_name' => $row['part_name'],
                'category' => $row['category_name'] ?? 'Uncategorized',
                'avg_price' => $row['avg_price_all']
            ];
        }
        
        // Store unique suppliers
        if (!isset($supplier_list[$supplier_id])) {
            $supplier_list[$supplier_id] = $suppliers[$supplier_id];
        }
        
        // Store price in matrix (only keep latest price per supplier)
        if (!isset($price_matrix[$part_id][$supplier_id]) || 
            strtotime($row['purchase_date']) > strtotime($price_matrix[$part_id][$supplier_id]['date'])) {
            $price_matrix[$part_id][$supplier_id] = [
                'price' => $row['purchase_price'],
                'quantity' => $row['quantity'],
                'date' => $row['purchase_date'],
                'invoice' => $row['invoice_number']
            ];
        }
    }
}

// Calculate statistics for each part
$part_stats = [];
foreach ($parts_list as $part_id => $part) {
    if (isset($price_matrix[$part_id])) {
        $prices = array_column($price_matrix[$part_id], 'price');
        $supplier_count = count($price_matrix[$part_id]);
        
        if ($supplier_count > 0) {
            $min_price = min($prices);
            $max_price = max($prices);
            $avg_price = array_sum($prices) / $supplier_count;
            $diff = $max_price - $min_price;
            $var_percent = $min_price > 0 ? round(($diff / $min_price) * 100, 2) : 0;
            
            // Find best supplier
            $best_supplier_id = array_search($min_price, $prices);
            
            $part_stats[$part_id] = [
                'supplier_count' => $supplier_count,
                'min_price' => $min_price,
                'max_price' => $max_price,
                'avg_price' => $avg_price,
                'difference' => $diff,
                'variation' => $var_percent,
                'best_supplier_id' => $best_supplier_id,
                'best_supplier_name' => $supplier_list[$best_supplier_id]['supplier_name'] ?? 'Unknown'
            ];
        }
    }
}

// Get categories for filter
$categories_query = "SELECT DISTINCT c.category_name 
                    FROM categories c 
                    JOIN parts_master p ON c.id = p.category_id 
                    ORDER BY c.category_name";
$categories_result = mysqli_query($conn, $categories_query);
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
        }
        .page-header {
            background: linear-gradient(135deg, #6f42c1, #007bff);
            color: white;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
        }
        .filter-card {
            background: white;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .price-matrix {
            background: white;
            border-radius: 10px;
            padding: 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            overflow-x: auto;
        }
        .price-matrix table {
            min-width: 100%;
            border-collapse: collapse;
        }
        .price-matrix th {
            background: #343a40;
            color: white;
            padding: 12px 8px;
            font-size: 0.9em;
            text-align: center;
            vertical-align: middle;
            position: sticky;
            top: 0;
            z-index: 10;
        }
        .price-matrix td {
            padding: 10px 8px;
            border: 1px solid #dee2e6;
            text-align: center;
            vertical-align: middle;
        }
        .part-info {
            background: #e9ecef;
            font-weight: 600;
            text-align: left;
            position: sticky;
            left: 0;
            z-index: 5;
        }
        .supplier-col {
            min-width: 150px;
        }
        .price-lowest {
            background-color: #d4edda !important;
            color: #155724;
            font-weight: bold;
        }
        .price-highest {
            background-color: #f8d7da !important;
            color: #721c24;
            font-weight: bold;
        }
        .stats-card {
            background: white;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 15px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
            border-left: 4px solid #6f42c1;
        }
        .summary-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 15px;
        }
        .btn-back {
            background: white;
            color: #6f42c1;
            border: none;
            padding: 8px 20px;
            border-radius: 20px;
            font-weight: 600;
        }
        .btn-back:hover {
            background: #f8f9fa;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.2);
        }
        .variation-high {
            color: #dc3545;
            font-weight: bold;
        }
        .variation-medium {
            color: #fd7e14;
            font-weight: bold;
        }
        .variation-low {
            color: #28a745;
            font-weight: bold;
        }
        .export-btn {
            background: #28a745;
            color: white;
            border: none;
            padding: 8px 20px;
            border-radius: 20px;
            font-weight: 600;
        }
        .export-btn:hover {
            background: #218838;
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
        <!-- Page Header -->
        <div class="page-header d-flex justify-content-between align-items-center">
            <div>
                <h4 class="mb-1"><i class="bi bi-currency-exchange"></i> Supplier Price Compare</h4>
                <p class="mb-0">Compare purchase prices across all suppliers for each item</p>
            </div>
            <div>
                <a href="dashboard.php" class="btn btn-back me-2">
                    <i class="bi bi-arrow-left"></i> Back to Dashboard
                </a>
                <button class="btn export-btn" onclick="exportToCSV()">
                    <i class="bi bi-download"></i> Export CSV
                </button>
            </div>
        </div>

        <!-- Summary Cards -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="summary-card">
                    <h6>Total Parts</h6>
                    <h3><?php echo count($parts_list); ?></h3>
                    <small>Items in inventory</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="summary-card" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                    <h6>Total Suppliers</h6>
                    <h3><?php echo count($supplier_list); ?></h3>
                    <small>Active suppliers</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="summary-card" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);">
                    <h6>Parts with Multiple Suppliers</h6>
                    <h3><?php echo count(array_filter($part_stats, function($stat) { return $stat['supplier_count'] > 1; })); ?></h3>
                    <small>Price variations exist</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="summary-card" style="background: linear-gradient(135deg, #fa709a 0%, #fee140 100%);">
                    <h6>Avg Price Variation</h6>
                    <h3>
                        <?php 
                        $avg_var = 0;
                        $var_parts = array_filter($part_stats, function($stat) { return $stat['supplier_count'] > 1; });
                        if (count($var_parts) > 0) {
                            $total_var = array_sum(array_column($var_parts, 'variation'));
                            $avg_var = round($total_var / count($var_parts), 2);
                        }
                        echo $avg_var . '%';
                        ?>
                    </h3>
                    <small>Across all items</small>
                </div>
            </div>
        </div>

        <!-- Filter Section -->
        <div class="filter-card">
            <div class="row">
                <div class="col-md-3">
                    <label class="form-label">Filter by Category</label>
                    <select class="form-select" id="categoryFilter">
                        <option value="all">All Categories</option>
                        <?php while($cat = mysqli_fetch_assoc($categories_result)): ?>
                        <option value="<?php echo htmlspecialchars($cat['category_name']); ?>">
                            <?php echo htmlspecialchars($cat['category_name']); ?>
                        </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Supplier Count</label>
                    <select class="form-select" id="supplierFilter">
                        <option value="all">All Parts</option>
                        <option value="multiple">Multiple Suppliers</option>
                        <option value="single">Single Supplier</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Price Variation</label>
                    <select class="form-select" id="variationFilter">
                        <option value="all">All Variations</option>
                        <option value="high">High (>30%)</option>
                        <option value="medium">Medium (15-30%)</option>
                        <option value="low">Low (<15%)</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Search Part</label>
                    <input type="text" class="form-control" id="searchPart" placeholder="Part name or number...">
                </div>
            </div>
        </div>

        <!-- Price Matrix Table -->
        <div class="price-matrix">
            <table class="table table-bordered" id="priceMatrixTable">
                <thead>
                    <tr>
                        <th style="min-width: 250px;">Part Details</th>
                        <?php foreach($supplier_list as $supplier): ?>
                        <th class="supplier-col">
                            <?php echo htmlspecialchars($supplier['supplier_name']); ?>
                            <br><small class="badge bg-secondary">ID: <?php echo $supplier['id']; ?></small>
                        </th>
                        <?php endforeach; ?>
                        <th style="min-width: 200px;">Statistics</th>
                        <th style="min-width: 150px;">Best Supplier</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($parts_list as $part_id => $part): 
                        $stats = $part_stats[$part_id] ?? null;
                        $supplier_count = $stats['supplier_count'] ?? 0;
                        $variation_class = 'variation-low';
                        if ($stats) {
                            if ($stats['variation'] > 30) $variation_class = 'variation-high';
                            elseif ($stats['variation'] > 15) $variation_class = 'variation-medium';
                        }
                    ?>
                    <tr class="part-row" 
                        data-category="<?php echo htmlspecialchars($part['category']); ?>"
                        data-supplier-count="<?php echo $supplier_count; ?>"
                        data-variation="<?php echo $stats['variation'] ?? 0; ?>"
                        data-part-name="<?php echo strtolower(htmlspecialchars($part['part_name'])); ?>"
                        data-part-number="<?php echo strtolower(htmlspecialchars($part['part_number'])); ?>">
                        
                        <!-- Part Info -->
                        <td class="part-info">
                            <strong><?php echo htmlspecialchars($part['part_number']); ?></strong>
                            <br><?php echo htmlspecialchars($part['part_name']); ?>
                            <br><small class="text-muted"><?php echo htmlspecialchars($part['category']); ?></small>
                            <?php if ($stats): ?>
                            <br><span class="badge bg-info"><?php echo $stats['supplier_count']; ?> suppliers</span>
                            <?php endif; ?>
                        </td>
                        
                        <!-- Supplier Prices -->
                        <?php foreach($supplier_list as $supplier_id => $supplier): ?>
                        <td class="<?php 
                            if (isset($price_matrix[$part_id][$supplier_id])) {
                                $price = $price_matrix[$part_id][$supplier_id]['price'];
                                if ($stats) {
                                    if ($price == $stats['min_price']) echo 'price-lowest';
                                    elseif ($price == $stats['max_price']) echo 'price-highest';
                                }
                            }
                        ?>">
                            <?php if (isset($price_matrix[$part_id][$supplier_id])): 
                                $data = $price_matrix[$part_id][$supplier_id];
                            ?>
                                <strong>₹<?php echo number_format($data['price'], 2); ?></strong>
                                <br><small class="text-muted">Qty: <?php echo $data['quantity']; ?></small>
                                <br><small class="text-muted"><?php echo date('d-m-Y', strtotime($data['date'])); ?></small>
                                <br><span class="badge bg-secondary"><?php echo $data['invoice']; ?></span>
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <?php endforeach; ?>
                        
                        <!-- Statistics -->
                        <td class="text-start">
                            <?php if ($stats && $stats['supplier_count'] > 1): ?>
                                <small>Min: <span class="text-success">₹<?php echo number_format($stats['min_price'], 2); ?></span></small><br>
                                <small>Max: <span class="text-danger">₹<?php echo number_format($stats['max_price'], 2); ?></span></small><br>
                                <small>Avg: ₹<?php echo number_format($stats['avg_price'], 2); ?></small><br>
                                <small>Diff: <strong>₹<?php echo number_format($stats['difference'], 2); ?></strong></small><br>
                                <small>Var: <strong class="<?php echo $variation_class; ?>"><?php echo $stats['variation']; ?>%</strong></small>
                            <?php elseif($stats): ?>
                                <span class="text-muted">Single supplier</span>
                                <br><small>Price: ₹<?php echo number_format($stats['min_price'], 2); ?></small>
                            <?php else: ?>
                                <span class="text-muted">No data</span>
                            <?php endif; ?>
                        </td>
                        
                        <!-- Best Supplier -->
                        <td>
                            <?php if ($stats && $stats['supplier_count'] > 1): ?>
                                <span class="badge bg-success"><?php echo htmlspecialchars($stats['best_supplier_name']); ?></span>
                                <br><small class="text-success">₹<?php echo number_format($stats['min_price'], 2); ?></small>
                                <br><small>Save: ₹<?php echo number_format($stats['difference'], 2); ?></small>
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- No Data Message -->
        <?php if (empty($parts_list)): ?>
        <div class="alert alert-info text-center py-5">
            <i class="bi bi-inbox fs-1"></i>
            <h5 class="mt-3">No Data Available</h5>
            <p>Start adding purchases from different suppliers to see price comparisons.</p>
            <a href="purchases.php" class="btn btn-primary">Add New Purchase</a>
        </div>
        <?php endif; ?>

    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
    // Filter functionality
    document.addEventListener('DOMContentLoaded', function() {
        const categoryFilter = document.getElementById('categoryFilter');
        const supplierFilter = document.getElementById('supplierFilter');
        const variationFilter = document.getElementById('variationFilter');
        const searchPart = document.getElementById('searchPart');
        
        function filterTable() {
            const category = categoryFilter.value;
            const supplierType = supplierFilter.value;
            const variation = variationFilter.value;
            const search = searchPart.value.toLowerCase();
            
            document.querySelectorAll('.part-row').forEach(row => {
                let show = true;
                
                // Category filter
                if (category !== 'all' && row.dataset.category !== category) {
                    show = false;
                }
                
                // Supplier count filter
                const supplierCount = parseInt(row.dataset.supplierCount);
                if (supplierType === 'multiple' && supplierCount <= 1) show = false;
                if (supplierType === 'single' && supplierCount > 1) show = false;
                
                // Variation filter
                const variationValue = parseFloat(row.dataset.variation);
                if (variation === 'high' && variationValue <= 30) show = false;
                if (variation === 'medium' && (variationValue <= 15 || variationValue > 30)) show = false;
                if (variation === 'low' && variationValue >= 15) show = false;
                
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
        supplierFilter.addEventListener('change', filterTable);
        variationFilter.addEventListener('change', filterTable);
        searchPart.addEventListener('keyup', filterTable);
    });
    
    // Export to CSV
    function exportToCSV() {
        let csv = [];
        let rows = document.querySelectorAll('#priceMatrixTable tr');
        
        for (let i = 0; i < rows.length; i++) {
            let row = [], cols = rows[i].querySelectorAll('td, th');
            
            for (let j = 0; j < cols.length; j++) {
                let text = cols[j].innerText.replace(/,/g, ' ').replace(/\n/g, ' ').replace(/\s+/g, ' ').trim();
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
    
    // Print function
    function printComparison() {
        window.print();
    }
    </script>
</body>
</html>