<?php
session_start();

// Check if user is logged in and is admin or support
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'support'])) {
    header("Location: ../index.php");
    exit();
}

require_once '../config/config.php';
require_once '../includes/device_helper.php';

// Check device restriction (admin and support roles don't allow mobile)
check_device_restriction();

// Check IP restriction for support (admin can access from anywhere)
// if ($_SESSION['role'] === 'support' && !is_ip_allowed()) {
//     header("Location: ../index.php?error=access_denied");
//     exit();
// }

// Fetch all approvals with additional details
$approvals = [];
$query = "
    SELECT a.*,
        CASE
            WHEN a.table_name = 'accounts' THEN (SELECT agent_name FROM accounts WHERE id = a.record_id)
            WHEN a.table_name = 'leads_data' THEN (SELECT sales_executive FROM leads_data WHERE id = a.record_id)
            WHEN a.table_name = 'client_retention' THEN (SELECT sales_executive FROM client_retention WHERE id = a.record_id)
            ELSE NULL
        END as sales_executive,
        CASE
            WHEN a.table_name = 'accounts' THEN (SELECT pc_number FROM accounts WHERE id = a.record_id)
            WHEN a.table_name = 'leads_data' THEN (SELECT pc_number FROM leads_data WHERE id = a.record_id)
            WHEN a.table_name = 'client_retention' THEN (SELECT pc_number FROM client_retention WHERE id = a.record_id)
            ELSE NULL
        END as pc_number,
        CASE
            WHEN a.table_name = 'accounts' THEN (SELECT discord_email FROM accounts WHERE id = a.record_id)
            WHEN a.table_name = 'leads_data' THEN (SELECT discord_email FROM leads_data WHERE id = a.record_id)
            WHEN a.table_name = 'client_retention' THEN (SELECT discord_email FROM client_retention WHERE id = a.record_id)
            ELSE NULL
        END as discord_email
    FROM approvals a
    ORDER BY a.created_at DESC
";
$result = $conn->query($query);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $approvals[] = $row;
    }
    $result->free();
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Approvals History - Sales Management</title>
    <link rel="stylesheet" href="../assets/style.css">
    <style>
        .history-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }

        .history-table th,
        .history-table td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: left;
        }

        .history-table th {
            background-color: #f2f2f2;
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

        .status-pending {
            color: orange;
        }

        .status-approved {
            color: green;
        }

        .status-rejected {
            color: red;
        }
    </style>
</head>

<body>
    <div class="dashboard-container">
        <header class="dashboard-header">
            <h1>Approvals History</h1>
            <div class="user-info">
                <span>Welcome, <?php echo htmlspecialchars($_SESSION['name'] ?? $_SESSION['username']); ?> (<?php echo htmlspecialchars($_SESSION['role']); ?>)</span>
                <a href="../includes/logout.php" class="logout-btn">Logout</a>
            </div>
        </header>

        <div class="dashboard-content">
            <a href="dashboard_<?php echo $_SESSION['role']; ?>.php" class="back-link">&larr; Back to Dashboard</a>

            <?php if (empty($approvals)): ?>
                <p>No approvals found.</p>
            <?php else: ?>
                <table class="history-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Table</th>
                            <th>Record ID</th>
                            <th>Action</th>
                            <th>Changes</th>
                            <th>Status</th>
                            <th>Sales Executive</th>
                            <th>Discord Email</th>
                            <th>PC Number</th>
                            <th>Reviewed By</th>
                            <th>Created At</th>
                            <th>Reviewed At</th>
                            <th>Comment</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($approvals as $approval): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($approval['id']); ?></td>
                                <td><?php echo htmlspecialchars($approval['table_name']); ?></td>
                                <td><?php echo htmlspecialchars($approval['record_id']); ?></td>
                                <td><?php echo htmlspecialchars(ucfirst($approval['action'])); ?></td>
                                <td><?php
                                    $pretty = json_encode(json_decode($approval['changes_json'], true), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                                    echo htmlspecialchars($pretty);
                                    ?></td>
                                <td class="status-<?php echo htmlspecialchars($approval['status']); ?>"><?php echo htmlspecialchars(ucfirst($approval['status'])); ?></td>
                                <td><?php echo htmlspecialchars($approval['sales_executive'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($approval['discord_email'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($approval['pc_number'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($approval['reviewed_by'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($approval['created_at']); ?></td>
                                <td><?php echo htmlspecialchars($approval['reviewed_at'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($approval['reviewer_comment'] ?? 'N/A'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</body>

</html>