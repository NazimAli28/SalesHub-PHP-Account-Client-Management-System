<?php
session_start();

// Check if user is logged in and is admin or support
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'support'])) {
    header("Location: ../index.php");
    exit();
}

// Prevent cache so Back won't restore stale form/modal state
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

require_once '../config/config.php';
require_once '../includes/device_helper.php';

// Check device restriction (admin and support roles don't allow mobile)
check_device_restriction();

// Fetch sales executives and their account counts
$sales_executives = [];
$stmt_users = $conn->prepare("SELECT id, name, username FROM users WHERE role = 'sales_executive' ORDER BY name ASC");
if (!$stmt_users) {
    die('Prepare failed: ' . $conn->error);
}
$stmt_users->execute();
$result_users = $stmt_users->get_result();
while ($user = $result_users->fetch_assoc()) {
    $pc_number = $user['username'];
    $agent_name = $user['name'];

    // Count total accounts for this pc_number
    $stmt_total = $conn->prepare("SELECT COUNT(*) as total FROM accounts WHERE pc_number = ?");
    $stmt_total->bind_param("s", $pc_number);
    $stmt_total->execute();
    $total = $stmt_total->get_result()->fetch_assoc()['total'];
    $stmt_total->close();

    // Count by standings
    $standings_counts = ['Active' => 0, 'Spam' => 0, 'Limited' => 0, 'Disabled' => 0, 'Violation' => 0];
    $stmt_standings = $conn->prepare("SELECT standings, COUNT(*) as count FROM accounts WHERE pc_number = ? GROUP BY standings");
    $stmt_standings->bind_param("s", $pc_number);
    $stmt_standings->execute();
    $result_standings = $stmt_standings->get_result();
    while ($row = $result_standings->fetch_assoc()) {
        $standings_counts[$row['standings']] = $row['count'];
    }
    $stmt_standings->close();

    // Get last assigned date (latest discord_account_date for accounts assigned to this pc_number)
    $stmt_last = $conn->prepare("SELECT MAX(discord_account_assigned_date) as last_assigned FROM accounts WHERE pc_number = ?");
    $stmt_last->bind_param("s", $pc_number);
    $stmt_last->execute();
    $last_assigned = $stmt_last->get_result()->fetch_assoc()['last_assigned'];
    $stmt_last->close();

    $sales_executives[] = [
        'name' => $agent_name,
        'pc_number' => $pc_number,
        'total' => $total,
        'active' => $standings_counts['Active'],
        'spam' => $standings_counts['Spam'],
        'limited' => $standings_counts['Limited'],
        'disabled' => $standings_counts['Disabled'],
        'violation' => $standings_counts['Violation'],
        'last_assigned' => $last_assigned
    ];
}
$stmt_users->close();
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Accounts Summary - Sales Management</title>
    <link rel="stylesheet" href="../assets/style.css">
    <style>
        .summary-container {
            margin: 20px 0;
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .back-link {
            display: inline-block;
            margin-bottom: 20px;
            color: #007bff;
            text-decoration: none;
        }

        .back-link:hover {
            text-decoration: underline;
        }

        .table-wrapper {
            overflow: auto;
            max-height: 70vh;
        }

        .summary-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.08);
        }

        .summary-table th,
        .summary-table td {
            padding: 12px 20px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }

        .summary-table th {
            background: #f8f9fa;
            font-weight: 600;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .summary-table tr:hover {
            background: #f8f9fa;
        }
    </style>
</head>

<body>
    <div class="dashboard-container">
        <header class="dashboard-header">
            <h1>User Accounts Summary</h1>
            <div class="user-info">
                <span>Welcome, <?php echo htmlspecialchars($_SESSION['name']); ?> (<?php echo htmlspecialchars($_SESSION['role']); ?>)</span>
                <a href="../includes/logout.php" class="logout-btn">Logout</a>
            </div>
        </header>

        <div class="dashboard-content">
            <a href="dashboard_<?php echo $_SESSION['role']; ?>.php" class="back-link">&larr; Back to Dashboard</a>
            <div class="summary-container">
                <h2>Accounts Summary by Sales Executive</h2>
                <?php if ($sales_executives): ?>
                    <div class="table-wrapper">
                        <table class="summary-table">
                            <thead>
                                <tr>
                                    <th>S.No</th>
                                    <th>Sales Executive Name</th>
                                    <th>PC Number</th>
                                    <th>Total Accounts</th>
                                    <th>Active</th>
                                    <th>Spam</th>
                                    <th>Limited</th>
                                    <th>Disabled</th>
                                    <th>Violation</th>
                                    <th>Last Assigned Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $serial = 1; ?>
                                <?php foreach ($sales_executives as $exec): ?>
                                    <tr>
                                        <td><?php echo $serial++; ?></td>
                                        <td><?php echo htmlspecialchars($exec['name']); ?></td>
                                        <td><?php echo htmlspecialchars($exec['pc_number']); ?></td>
                                        <td><?php echo htmlspecialchars($exec['total']); ?></td>
                                        <td><?php echo htmlspecialchars($exec['active']); ?></td>
                                        <td><?php echo htmlspecialchars($exec['spam']); ?></td>
                                        <td><?php echo htmlspecialchars($exec['limited']); ?></td>
                                        <td><?php echo htmlspecialchars($exec['disabled']); ?></td>
                                        <td><?php echo htmlspecialchars($exec['violation']); ?></td>
                                        <td><?php echo $exec['last_assigned'] ? htmlspecialchars($exec['last_assigned']) : 'N/A'; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p>No sales executives found.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>

</html>
