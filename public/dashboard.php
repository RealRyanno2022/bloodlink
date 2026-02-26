<?php
require_once '../app/config/database.php';

# Create new db
$database = new Database();
$db = $database->getConnection();

// Total donors
$donorStmt = $db->query("SELECT COUNT(*) as total FROM donors");
$totalDonors = $donorStmt->fetch(PDO::FETCH_ASSOC)['total'];

// Total available units
$availableStmt = $db->query("SELECT COUNT(*) as total FROM blood_units WHERE status = 'available'");
$totalAvailable = $availableStmt->fetch(PDO::FETCH_ASSOC)['total'];

// Units expiring in next 7 days
$expiryStmt = $db->query("
    SELECT COUNT(*) as total 
    FROM blood_units 
    WHERE status = 'available'
    AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
");
$expiringSoon = $expiryStmt->fetch(PDO::FETCH_ASSOC)['total'];

// Inventory by blood type
$inventoryStmt = $db->query("
    SELECT blood_type, COUNT(*) as total
    FROM blood_units
    WHERE status = 'available'
    GROUP BY blood_type
");
$inventory = $inventoryStmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html>
<head>
    <title>BloodLink Dashboard</title>
    <style>
        body { font-family: Arial; background:#f4f4f4; padding:40px; }
        .card { background:white; padding:20px; margin-bottom:20px; border-radius:6px; }
        .warning { background:#ffe6e6; }
        table { width:100%; border-collapse: collapse; }
        th, td { padding:8px; border-bottom:1px solid #ddd; text-align:left; }
    </style>
</head>
<body>

<h1>BloodLink Dashboard</h1>

<div class="card">
    <h2>Overview</h2>
    <p><strong>Total Donors:</strong> <?php echo $totalDonors; ?></p>
    <p><strong>Available Blood Units:</strong> <?php echo $totalAvailable; ?></p>
    <p><strong>Expiring in 7 Days:</strong> <?php echo $expiringSoon; ?></p>
</div>

<div class="card">
    <h2>Inventory by Blood Type</h2>
    <table>
        <tr>
            <th>Blood Type</th>
            <th>Available Units</th>
        </tr>
        <?php foreach ($inventory as $row): ?>
        <tr>
            <td><?php echo $row['blood_type']; ?></td>
            <td><?php echo $row['total']; ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
</div>

<?php if ($expiringSoon > 0): ?>
<div class="card warning">
    <h2>⚠ Expiry Alert</h2>
    <p><?php echo $expiringSoon; ?> units will expire within 7 days.</p>
</div>
<?php endif; ?>

</body>
</html>