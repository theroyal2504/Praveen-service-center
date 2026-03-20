<?php
require_once 'config.php';

if (!isAdmin()) {
    redirect('dashboard.php');
}

// Handle GST settings update
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Update GST rates
    $gst_rates = [
        'service_rate' => floatval($_POST['service_rate']),
        'parts_rate_18' => floatval($_POST['parts_rate_18']),
        'oil_rate_28' => floatval($_POST['oil_rate_28'])
    ];
    
    // Save to settings table
    foreach ($gst_rates as $key => $value) {
        mysqli_query($conn, "INSERT INTO system_settings (setting_key, setting_value) 
                            VALUES ('$key', '$value')
                            ON DUPLICATE KEY UPDATE setting_value = '$value'");
    }
    
    // Update HSN/SAC mapping
    if (isset($_POST['hsn_mapping'])) {
        $hsn_mapping = $_POST['hsn_mapping'];
        // Store as JSON
        mysqli_query($conn, "INSERT INTO system_settings (setting_key, setting_value) 
                            VALUES ('hsn_mapping', '" . mysqli_real_escape_string($conn, json_encode($hsn_mapping)) . "')
                            ON DUPLICATE KEY UPDATE setting_value = '" . mysqli_real_escape_string($conn, json_encode($hsn_mapping)) . "'");
    }
    
    $_SESSION['success'] = "GST settings updated successfully!";
    redirect('gst_settings.php');
}

// Fetch current settings
$settings = [];
$result = mysqli_query($conn, "SELECT setting_key, setting_value FROM system_settings");
while ($row = mysqli_fetch_assoc($result)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

$service_rate = $settings['service_rate'] ?? 18;
$parts_rate_18 = $settings['parts_rate_18'] ?? 18;
$oil_rate_28 = $settings['oil_rate_28'] ?? 28;
$hsn_mapping = isset($settings['hsn_mapping']) ? json_decode($settings['hsn_mapping'], true) : [];

// Get categories for HSN mapping
$categories = mysqli_query($conn, "SELECT * FROM categories ORDER BY category_name");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GST Settings - Bike Management System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <style>
        .settings-card {
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            margin-bottom: 20px;
        }
        .settings-card .card-header {
            background: linear-gradient(135deg, #28a745, #20c997);
            color: white;
            border-radius: 12px 12px 0 0;
            padding: 12px 20px;
        }
        .info-text {
            font-size: 0.85rem;
            color: #6c757d;
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
                        <a class="nav-link" href="gstr1_report.php">
                            <i class="bi bi-file-text"></i> GSTR-1 Report
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
            <div class="alert alert-success alert-dismissible fade show">
                <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="row">
            <div class="col-md-6">
                <div class="card settings-card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-percent"></i> GST Rate Configuration</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <div class="mb-3">
                                <label class="form-label">Service GST Rate (%)</label>
                                <input type="number" step="0.1" class="form-control" name="service_rate" value="<?php echo $service_rate; ?>" required>
                                <small class="info-text">Repair & Maintenance services (SAC: 9988)</small>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Spare Parts GST Rate (18% slab)</label>
                                <input type="number" step="0.1" class="form-control" name="parts_rate_18" value="<?php echo $parts_rate_18; ?>" required>
                                <small class="info-text">Most auto parts (HSN: 8714)</small>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Engine Oil/Lubricants GST Rate (%)</label>
                                <input type="number" step="0.1" class="form-control" name="oil_rate_28" value="<?php echo $oil_rate_28; ?>" required>
                                <small class="info-text">Engine oil, lubricants, greases (HSN: 2710)</small>
                            </div>
                            <hr>
                            <div class="mb-3">
                                <label class="form-label">Business GSTIN</label>
                                <input type="text" class="form-control" name="gst_number" value="<?php echo $settings['gst_number'] ?? ''; ?>" placeholder="22XXXXX1234X1X">
                                <small class="info-text">Your GST Registration Number</small>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">SAC Code (Services)</label>
                                <input type="text" class="form-control" name="sac_code" value="<?php echo $settings['sac_code'] ?? '9988'; ?>" placeholder="9988">
                                <small class="info-text">SAC code for repair services</small>
                            </div>
                            <button type="submit" class="btn btn-primary">Save GST Settings</button>
                        </form>
                    </div>
                </div>
            </div>
            
            <div class="col-md-6">
                <div class="card settings-card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-tags"></i> HSN Code Mapping</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <table class="table table-sm table-bordered">
                                <thead class="table-light">
                                    <tr>
                                        <th>Category</th>
                                        <th>HSN Code</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    mysqli_data_seek($categories, 0);
                                    while($cat = mysqli_fetch_assoc($categories)): 
                                        $cat_hsn = $hsn_mapping[$cat['category_name']] ?? '8714';
                                    ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($cat['category_name']); ?></td>
                                        <td>
                                            <input type="text" class="form-control form-control-sm" 
                                                   name="hsn_mapping[<?php echo htmlspecialchars($cat['category_name']); ?>]" 
                                                   value="<?php echo $cat_hsn; ?>" placeholder="8714">
                                        </td>
                                    </tr>
                                    <?php endwhile; ?>
                                    <tr>
                                        <td>Services (Labour)</td>
                                        <td>
                                            <input type="text" class="form-control form-control-sm" 
                                                   name="hsn_mapping[Service]" value="<?php echo $hsn_mapping['Service'] ?? '9988'; ?>">
                                        </td>
                                    </tr>
                                    <tr>
                                        <td>Engine Oil</td>
                                        <td>
                                            <input type="text" class="form-control form-control-sm" 
                                                   name="hsn_mapping[Engine Oil]" value="<?php echo $hsn_mapping['Engine Oil'] ?? '2710'; ?>">
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                            <button type="submit" name="save_hsn" class="btn btn-secondary">Save HSN Mapping</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <div class="row mt-3">
            <div class="col-12">
                <div class="card">
                    <div class="card-header bg-info text-white">
                        <h5 class="mb-0"><i class="bi bi-info-circle"></i> GST Filing Instructions</h5>
                    </div>
                    <div class="card-body">
                        <ul class="list-unstyled">
                            <li><i class="bi bi-check-circle-fill text-success"></i> <strong>GSTR-1 (Outward Supplies):</strong> File by 11th of next month</li>
                            <li><i class="bi bi-check-circle-fill text-success"></i> <strong>GSTR-3B (Summary):</strong> File by 20th of next month</li>
                            <li><i class="bi bi-check-circle-fill text-success"></i> <strong>Services:</strong> SAC 9988 @ <?php echo $service_rate; ?>% GST</li>
                            <li><i class="bi bi-check-circle-fill text-success"></i> <strong>Spare Parts:</strong> HSN 8714 @ <?php echo $parts_rate_18; ?>% GST</li>
                            <li><i class="bi bi-check-circle-fill text-success"></i> <strong>Engine Oil:</strong> HSN 2710 @ <?php echo $oil_rate_28; ?>% GST</li>
                        </ul>
                        <hr>
                        <div class="alert alert-warning">
                            <i class="bi bi-exclamation-triangle"></i> <strong>Note:</strong> 
                            Make sure to update these rates as per government notifications.
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>