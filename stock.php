<?php
require_once 'config.php';

if (!isLoggedIn()) {
    redirect('index.php');
}

// Update minimum stock level
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_min_stock'])) {
    $part_id = intval($_POST['part_id']);
    $min_stock_level = intval($_POST['min_stock_level']);
    
    $query = "UPDATE stock SET min_stock_level = $min_stock_level WHERE part_id = $part_id";
    if (mysqli_query($conn, $query)) {
        $_SESSION['success'] = "Minimum stock level updated successfully!";
    } else {
        $_SESSION['error'] = "Error updating stock level: " . mysqli_error($conn);
    }
    redirect('stock.php');
}

// Fetch all categories
$categories_query = "SELECT * FROM categories ORDER BY category_name";
$categories = mysqli_query($conn, $categories_query);

// Debug: Check if stock table has data
$stock_check = mysqli_query($conn, "SELECT COUNT(*) as total FROM stock");
$stock_count = mysqli_fetch_assoc($stock_check);
error_log("Total stock records: " . $stock_count['total']);

// Fetch all parts with their stock information and latest selling price
$parts_query = "SELECT 
                    p.id as part_id,
                    p.part_number, 
                    p.part_name, 
                    p.unit_price as purchase_price,
                    c.category_name, 
                    bc.name as company_name, 
                    bm.model_name,
                    s.quantity as current_stock,
                    s.min_stock_level,
                    CASE 
                        WHEN s.id IS NULL THEN 0
                        ELSE s.quantity
                    END as quantity,
                    COALESCE(s.min_stock_level, 5) as min_stock_level_display,
                    (
                        SELECT pi.selling_price 
                        FROM purchase_items pi 
                        WHERE pi.part_id = p.id 
                        ORDER BY pi.id DESC 
                        LIMIT 1
                    ) as selling_price,
                    CASE 
                        WHEN s.id IS NULL OR s.quantity <= 0 THEN 'out'
                        WHEN s.quantity <= COALESCE(s.min_stock_level, 5) THEN 'danger'
                        WHEN s.quantity <= COALESCE(s.min_stock_level, 5) * 2 THEN 'warning'
                        ELSE 'success'
                    END as stock_status
                FROM parts_master p
                LEFT JOIN stock s ON p.id = s.part_id
                LEFT JOIN categories c ON p.category_id = c.id
                LEFT JOIN bike_companies bc ON p.company_id = bc.id
                LEFT JOIN bike_models bm ON p.model_id = bm.id
                ORDER BY 
                    c.category_name ASC,
                    p.part_name ASC";

$parts_result = mysqli_query($conn, $parts_query);

if (!$parts_result) {
    die("Error in query: " . mysqli_error($conn));
}

// Debug: Check number of rows returned
error_log("Total parts returned: " . mysqli_num_rows($parts_result));

// Group parts by category
$parts_by_category = [];
$uncategorized_parts = [];

while ($part = mysqli_fetch_assoc($parts_result)) {
    // If no selling price found, set to 0
    $part['selling_price'] = $part['selling_price'] ?? 0;
    
    // Ensure quantity is properly set
    $part['quantity'] = intval($part['quantity'] ?? 0);
    
    if ($part['category_name']) {
        $category = $part['category_name'];
        if (!isset($parts_by_category[$category])) {
            $parts_by_category[$category] = [];
        }
        $parts_by_category[$category][] = $part;
    } else {
        $uncategorized_parts[] = $part;
    }
}

// Get summary statistics
$total_parts = mysqli_num_rows(mysqli_query($conn, "SELECT COUNT(*) as total FROM parts_master"));

$low_stock_query = "SELECT COUNT(*) as count 
                    FROM parts_master p
                    LEFT JOIN stock s ON p.id = s.part_id
                    WHERE s.quantity > 0 
                    AND s.quantity <= COALESCE(s.min_stock_level, 5)";
$low_stock_result = mysqli_query($conn, $low_stock_query);
$low_stock = mysqli_fetch_assoc($low_stock_result)['count'] ?? 0;

$zero_stock_query = "SELECT COUNT(*) as count 
                     FROM parts_master p
                     LEFT JOIN stock s ON p.id = s.part_id
                     WHERE s.quantity IS NULL OR s.quantity = 0";
$zero_stock_result = mysqli_query($conn, $zero_stock_query);
$zero_stock = mysqli_fetch_assoc($zero_stock_result)['count'] ?? 0;

// Calculate total stock value based on selling price
$total_value_query = "SELECT SUM(COALESCE(s.quantity, 0) * 
                        COALESCE((
                            SELECT pi.selling_price 
                            FROM purchase_items pi 
                            WHERE pi.part_id = p.id 
                            ORDER BY pi.id DESC 
                            LIMIT 1
                        ), p.unit_price)) as total_value
                     FROM parts_master p
                     LEFT JOIN stock s ON p.id = s.part_id";
$total_value_result = mysqli_query($conn, $total_value_query);
$total_value = mysqli_fetch_assoc($total_value_result)['total_value'] ?? 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Stock Management - Bike Management System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <style>
        .stock-badge {
            font-size: 0.9em;
            padding: 5px 10px;
        }
        .category-header {
            background-color: #2c3e50;
            color: white;
            padding: 12px 15px;
            margin: 10px 0;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s;
            font-weight: bold;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .category-header:hover {
            background-color: #34495e;
            transform: translateX(5px);
        }
        .category-header .toggle-icon {
            font-size: 1.2em;
        }
        .category-content {
            margin-bottom: 20px;
            transition: all 0.3s;
        }
        .category-stats {
            font-size: 0.9em;
            background-color: rgba(255,255,255,0.2);
            padding: 3px 10px;
            border-radius: 20px;
        }
        .summary-card {
            transition: transform 0.2s;
            border: none;
            border-radius: 10px;
        }
        .summary-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
        }
        .part-row-out {
            background-color: #ffebee;
        }
        .part-row-danger {
            background-color: #fff3e0;
        }
        .part-row-warning {
            background-color: #fff8e1;
        }
        .status-badge {
            padding: 5px 10px;
            border-radius: 20px;
            font-weight: 500;
        }
        .action-buttons {
            white-space: nowrap;
        }
        .selling-price {
            color: #28a745;
            font-weight: bold;
        }
        .no-price {
            color: #6c757d;
            font-style: italic;
        }
        .price-info {
            font-size: 0.8em;
            color: #6c757d;
        }
        .quantity-display {
            font-size: 1.1em;
            font-weight: bold;
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
                <i class="bi bi-check-circle"></i> <?php echo $_SESSION['success']; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php unset($_SESSION['success']); ?>
        <?php endif; ?>

        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="bi bi-exclamation-triangle"></i> <?php echo $_SESSION['error']; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php unset($_SESSION['error']); ?>
        <?php endif; ?>

        <!-- Stock Summary Cards -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card text-white bg-primary summary-card">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="card-title">Total Parts</h6>
                                <h2 class="mb-0"><?php echo $total_parts; ?></h2>
                                <small>All parts in system</small>
                            </div>
                            <i class="bi bi-boxes fs-1"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-white bg-danger summary-card">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="card-title">Low Stock</h6>
                                <h2 class="mb-0"><?php echo $low_stock; ?></h2>
                                <small>Below minimum level</small>
                            </div>
                            <i class="bi bi-exclamation-triangle fs-1"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-white bg-warning summary-card">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="card-title">Out of Stock</h6>
                                <h2 class="mb-0"><?php echo $zero_stock; ?></h2>
                                <small>Zero quantity</small>
                            </div>
                            <i class="bi bi-x-circle fs-1"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-white bg-success summary-card">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="card-title">Stock Value</h6>
                                <h2 class="mb-0">₹<?php echo number_format($total_value, 2); ?></h2>
                                <small>Based on selling price</small>
                            </div>
                            <i class="bi bi-cash-stack fs-1"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Category-wise Stock Display -->
        <div class="card">
            <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="bi bi-boxes"></i> Category-wise Stock Status</h5>
                <div>
                    <button class="btn btn-sm btn-light" onclick="expandAll()">
                        <i class="bi bi-arrows-expand"></i> Expand All
                    </button>
                    <button class="btn btn-sm btn-light" onclick="collapseAll()">
                        <i class="bi bi-arrows-collapse"></i> Collapse All
                    </button>
                </div>
            </div>
            <div class="card-body">
                <!-- Display Parts by Category -->
                <?php foreach ($parts_by_category as $category_name => $parts): ?>
                    <div class="category-section">
                        <div class="category-header" onclick="toggleCategory('<?php echo md5($category_name); ?>')">
                            <span>
                                <i class="bi bi-folder"></i> <?php echo htmlspecialchars($category_name); ?>
                                <span class="category-stats">
                                    <?php 
                                    $cat_total = count($parts);
                                    $cat_low = count(array_filter($parts, function($p) { return $p['stock_status'] == 'danger'; }));
                                    $cat_out = count(array_filter($parts, function($p) { return $p['stock_status'] == 'out'; }));
                                    echo "{$cat_total} items | {$cat_low} low | {$cat_out} out";
                                    ?>
                                </span>
                            </span>
                            <span class="toggle-icon" id="icon-<?php echo md5($category_name); ?>">
                                <i class="bi bi-chevron-down"></i>
                            </span>
                        </div>
                        <div class="category-content" id="category-<?php echo md5($category_name); ?>">
                            <div class="table-responsive">
                                <table class="table table-bordered table-striped table-hover">
                                    <thead class="table-secondary">
                                        <tr>
                                            <th>Part #</th>
                                            <th>Part Name</th>
                                            <th>Company/Model</th>
                                            <th>Current Stock</th>
                                            <th>Min Stock</th>
                                            <th>Selling Price (₹)</th>
                                            <th>Stock Value (₹)</th>
                                            <th>Status</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($parts as $item): 
                                            $stock_value = $item['quantity'] * $item['selling_price'];
                                            $row_class = '';
                                            if ($item['stock_status'] == 'out') $row_class = 'part-row-out';
                                            elseif ($item['stock_status'] == 'danger') $row_class = 'part-row-danger';
                                            elseif ($item['stock_status'] == 'warning') $row_class = 'part-row-warning';
                                        ?>
                                        <tr class="<?php echo $row_class; ?>">
                                            <td><strong><?php echo htmlspecialchars($item['part_number']); ?></strong></td>
                                            <td><?php echo htmlspecialchars($item['part_name']); ?></td>
                                            <td>
                                                <?php if($item['company_name']): ?>
                                                    <strong><?php echo htmlspecialchars($item['company_name']); ?></strong>
                                                    <?php if($item['model_name']): ?>
                                                        <br><small class="text-muted"><?php echo htmlspecialchars($item['model_name']); ?></small>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <span class="text-muted">N/A</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center">
                                                <span class="badge bg-<?php 
                                                    echo $item['quantity'] == 0 ? 'danger' : 
                                                        ($item['stock_status'] == 'danger' ? 'warning' : 
                                                        ($item['stock_status'] == 'warning' ? 'info' : 'success')); 
                                                ?> stock-badge quantity-display">
                                                    <?php echo $item['quantity']; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <form method="POST" class="d-flex align-items-center">
                                                    <input type="hidden" name="part_id" value="<?php echo $item['part_id']; ?>">
                                                    <input type="number" name="min_stock_level" value="<?php echo $item['min_stock_level_display']; ?>" 
                                                           class="form-control form-control-sm" style="width: 70px;" min="1" required>
                                                    <button type="submit" name="update_min_stock" class="btn btn-sm btn-primary ms-1" title="Update Min Stock">
                                                        <i class="bi bi-check-lg"></i>
                                                    </button>
                                                </form>
                                            </td>
                                            <td>
                                                <?php if($item['selling_price'] > 0): ?>
                                                    <span class="selling-price">₹<?php echo number_format($item['selling_price'], 2); ?></span>
                                                <?php else: ?>
                                                    <span class="no-price">Not set</span>
                                                    <br><small class="price-info">(Purchase: ₹<?php echo number_format($item['purchase_price'], 2); ?>)</small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <strong class="<?php echo $item['selling_price'] > 0 ? 'text-success' : 'text-muted'; ?>">
                                                    ₹<?php echo number_format($stock_value, 2); ?>
                                                </strong>
                                                <?php if($item['selling_price'] == 0): ?>
                                                    <br><small class="text-muted">Based on purchase price</small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if($item['quantity'] == 0): ?>
                                                    <span class="badge bg-danger status-badge">Out of Stock</span>
                                                <?php elseif($item['stock_status'] == 'danger'): ?>
                                                    <span class="badge bg-warning text-dark status-badge">Low Stock</span>
                                                <?php elseif($item['stock_status'] == 'warning'): ?>
                                                    <span class="badge bg-info status-badge">Moderate</span>
                                                <?php else: ?>
                                                    <span class="badge bg-success status-badge">Good</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="action-buttons">
                                                <a href="stock_movement.php?part_id=<?php echo $item['part_id']; ?>" class="btn btn-sm btn-info" title="View History">
                                                    <i class="bi bi-graph-up"></i>
                                                </a>
                                                <a href="parts.php?edit=<?php echo $item['part_id']; ?>" class="btn btn-sm btn-warning" title="Edit Part">
                                                    <i class="bi bi-pencil"></i>
                                                </a>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>

                <!-- Uncategorized Parts -->
                <?php if (!empty($uncategorized_parts)): ?>
                <div class="category-section">
                    <div class="category-header" onclick="toggleCategory('uncategorized')">
                        <span>
                            <i class="bi bi-folder"></i> Uncategorized
                            <span class="category-stats">
                                <?php 
                                $cat_total = count($uncategorized_parts);
                                $cat_low = count(array_filter($uncategorized_parts, function($p) { return $p['stock_status'] == 'danger'; }));
                                $cat_out = count(array_filter($uncategorized_parts, function($p) { return $p['stock_status'] == 'out'; }));
                                echo "{$cat_total} items | {$cat_low} low | {$cat_out} out";
                                ?>
                            </span>
                        </span>
                        <span class="toggle-icon" id="icon-uncategorized">
                            <i class="bi bi-chevron-down"></i>
                        </span>
                    </div>
                    <div class="category-content" id="category-uncategorized">
                        <div class="table-responsive">
                            <table class="table table-bordered table-striped table-hover">
                                <thead class="table-secondary">
                                    <tr>
                                        <th>Part #</th>
                                        <th>Part Name</th>
                                        <th>Company/Model</th>
                                        <th>Current Stock</th>
                                        <th>Min Stock</th>
                                        <th>Selling Price (₹)</th>
                                        <th>Stock Value (₹)</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($uncategorized_parts as $item): 
                                        $stock_value = $item['quantity'] * $item['selling_price'];
                                    ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($item['part_number']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($item['part_name']); ?></td>
                                        <td>
                                            <?php if($item['company_name']): ?>
                                                <strong><?php echo htmlspecialchars($item['company_name']); ?></strong>
                                                <?php if($item['model_name']): ?>
                                                    <br><small class="text-muted"><?php echo htmlspecialchars($item['model_name']); ?></small>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="text-muted">N/A</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge bg-<?php 
                                                echo $item['quantity'] == 0 ? 'danger' : 
                                                    ($item['stock_status'] == 'danger' ? 'warning' : 
                                                    ($item['stock_status'] == 'warning' ? 'info' : 'success')); 
                                            ?> stock-badge quantity-display">
                                                <?php echo $item['quantity']; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <form method="POST" class="d-flex align-items-center">
                                                <input type="hidden" name="part_id" value="<?php echo $item['part_id']; ?>">
                                                <input type="number" name="min_stock_level" value="<?php echo $item['min_stock_level_display']; ?>" 
                                                       class="form-control form-control-sm" style="width: 70px;" min="1" required>
                                                <button type="submit" name="update_min_stock" class="btn btn-sm btn-primary ms-1">
                                                    <i class="bi bi-check-lg"></i>
                                                </button>
                                            </form>
                                        </td>
                                        <td>
                                            <?php if($item['selling_price'] > 0): ?>
                                                <span class="selling-price">₹<?php echo number_format($item['selling_price'], 2); ?></span>
                                            <?php else: ?>
                                                <span class="no-price">Not set</span>
                                                <br><small class="price-info">(Purchase: ₹<?php echo number_format($item['purchase_price'], 2); ?>)</small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <strong class="<?php echo $item['selling_price'] > 0 ? 'text-success' : 'text-muted'; ?>">
                                                ₹<?php echo number_format($stock_value, 2); ?>
                                            </strong>
                                            <?php if($item['selling_price'] == 0): ?>
                                                <br><small class="text-muted">Based on purchase price</small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if($item['quantity'] == 0): ?>
                                                <span class="badge bg-danger">Out of Stock</span>
                                            <?php elseif($item['stock_status'] == 'danger'): ?>
                                                <span class="badge bg-warning text-dark">Low Stock</span>
                                            <?php elseif($item['stock_status'] == 'warning'): ?>
                                                <span class="badge bg-info">Moderate</span>
                                            <?php else: ?>
                                                <span class="badge bg-success">Good</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <a href="stock_movement.php?part_id=<?php echo $item['part_id']; ?>" class="btn btn-sm btn-info">
                                                <i class="bi bi-graph-up"></i>
                                            </a>
                                            <a href="parts.php?edit=<?php echo $item['part_id']; ?>" class="btn btn-sm btn-warning">
                                                <i class="bi bi-pencil"></i>
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (empty($parts_by_category) && empty($uncategorized_parts)): ?>
                <div class="text-center py-5">
                    <i class="bi bi-inbox fs-1 text-muted d-block"></i>
                    <h5 class="text-muted mt-3">No parts found in the system</h5>
                    <a href="parts.php" class="btn btn-primary mt-2">
                        <i class="bi bi-plus-circle"></i> Add New Part
                    </a>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
    // Toggle category visibility
    function toggleCategory(categoryId) {
        const content = document.getElementById('category-' + categoryId);
        const icon = document.getElementById('icon-' + categoryId);
        
        if (content.style.display === 'none') {
            content.style.display = 'block';
            icon.innerHTML = '<i class="bi bi-chevron-down"></i>';
        } else {
            content.style.display = 'none';
            icon.innerHTML = '<i class="bi bi-chevron-right"></i>';
        }
    }

    // Expand all categories
    function expandAll() {
        document.querySelectorAll('[id^="category-"]').forEach(content => {
            content.style.display = 'block';
        });
        document.querySelectorAll('[id^="icon-"]').forEach(icon => {
            icon.innerHTML = '<i class="bi bi-chevron-down"></i>';
        });
    }

    // Collapse all categories
    function collapseAll() {
        document.querySelectorAll('[id^="category-"]').forEach(content => {
            content.style.display = 'none';
        });
        document.querySelectorAll('[id^="icon-"]').forEach(icon => {
            icon.innerHTML = '<i class="bi bi-chevron-right"></i>';
        });
    }

    // Auto-hide alerts after 3 seconds
    setTimeout(function() {
        document.querySelectorAll('.alert').forEach(function(alert) {
            alert.style.transition = 'opacity 0.5s';
            alert.style.opacity = '0';
            setTimeout(function() {
                alert.remove();
            }, 500);
        });
    }, 3000);

    // Initialize all categories as expanded
    document.addEventListener('DOMContentLoaded', function() {
        expandAll();
    });
    </script>
</body>
</html>