<?php
session_start();

// Only for logged-in sales_executive
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'sales_executive') {
    header("Location: ../index.php");
    exit();
}

require_once '../config/config.php';
require_once '../includes/device_helper.php';

// Device / IP restriction (reuse same rules as other sales_executive pages)
check_device_restriction();
if (!is_ip_allowed()) {
    header("Location: ../index.php?error=access_denied");
    exit();
}

// Resolve agent pc_number and name (same pattern as client_retention_form)
$agent_pc_number = $_SESSION['username'];
if (isset($_SESSION['user_id'])) {
    $stmt = $conn->prepare("SELECT pc_number, name FROM users WHERE id = ?");
    $stmt->bind_param("i", $_SESSION['user_id']);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) {
        if (!empty($row['pc_number'])) {
            $agent_pc_number = $row['pc_number'];
            $_SESSION['pc_number'] = $agent_pc_number;
        }
        if (!empty($row['name'])) {
            $_SESSION['name'] = $row['name'];
        }
    }
    $stmt->close();
}

// Collect all active discord_emails for this agent
$discord_emails = [];
$stmt = $conn->prepare("SELECT discord_email FROM accounts WHERE pc_number = ? AND status = 1 AND discord_email IS NOT NULL AND discord_email != ''");
$stmt->bind_param("s", $agent_pc_number);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $discord_emails[] = $row['discord_email'];
}
$stmt->close();

$today = date('Y-m-d');
$tasks = [];

if (!empty($discord_emails)) {
    // Build IN (...) safely
    $placeholders = implode(',', array_fill(0, count($discord_emails), '?'));
    $types = str_repeat('s', count($discord_emails)) . 'ss';

    $sql = "SELECT *
            FROM client_retention
            WHERE discord_email IN ($placeholders)
              AND (
                    next_payment_date = ?
                 OR expected_next_upsale_date = ?
              )
            ORDER BY next_payment_date ASC, expected_next_upsale_date ASC, created_at DESC";

    $params = $discord_emails;
    $params[] = $today;
    $params[] = $today;

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $tasks[] = $row;
    }
    $stmt->close();
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Today's Client Retention Tasks</title>
    <link rel="stylesheet" href="../assets/style.css">
    <style>
        .tasks-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }

        .tasks-header h1 {
            margin: 0;
        }

        .tasks-date {
            font-weight: 600;
            color: #555;
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
            margin-top: 10px;
            overflow-x: auto;
        }

        .accounts-table {
            width: 100%;
            border-collapse: collapse;
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.08);
        }

        .accounts-table th,
        .accounts-table td {
            padding: 10px 14px;
            border-bottom: 1px solid #eee;
            text-align: left;
            white-space: nowrap;
        }

        .accounts-table th {
            background: #f8f9fa;
            font-weight: 600;
        }

        .accounts-table tr:hover {
            background: #f8f9fa;
        }

        .pill {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 600;
            color: #fff;
        }

        .pill-next-payment {
            background-color: #17a2b8;
        }

        .pill-upsale {
            background-color: #28a745;
        }
    </style>
</head>

<body>
    <div class="dashboard-container">
        <header class="dashboard-header">
            <div class="tasks-header">
                <h1>Today's Client Retention Tasks</h1>
                <div class="tasks-date">
                    <?php echo htmlspecialchars($today); ?>
                </div>
            </div>
            <div class="user-info">
                <span>Welcome, <?php echo htmlspecialchars($_SESSION['name']); ?></span>
                <a href="../includes/logout.php" class="logout-btn">Logout</a>
            </div>
        </header>

        <div class="dashboard-content">
            <a href="dashboard_sales_executive.php" class="back-link">&larr; Back to Dashboard</a>

            <?php if (!empty($tasks)): ?>
                <div class="table-wrapper">
                    <table class="accounts-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Order #</th>
                                <th>Discord Email</th>
                                <th>Client Name</th>
                                <th>Next Payment Date</th>
                                <th>Expected Next Up Sale Date</th>
                                <th>Fresh Sale Item</th>
                                <th>Nurturing Sale</th>
                                <th>Comments</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $i = 1; ?>
                            <?php foreach ($tasks as $task): ?>
                                <tr>
                                    <td><?php echo $i++; ?></td>
                                    <td><?php echo htmlspecialchars($task['order_number']); ?></td>
                                    <td><?php echo htmlspecialchars($task['discord_email']); ?></td>
                                    <td><?php echo htmlspecialchars($task['client_name_payment']); ?></td>
                                    <td>
                                        <?php if (!empty($task['next_payment_date'])): ?>
                                            <span class="pill pill-next-payment">
                                                <?php echo htmlspecialchars($task['next_payment_date']); ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($task['expected_next_upsale_date'])): ?>
                                            <span class="pill pill-upsale">
                                                <?php echo htmlspecialchars($task['expected_next_upsale_date']); ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($task['fresh_sale_item']); ?></td>
                                    <td><?php echo htmlspecialchars($task['nurturing_sale']); ?></td>
                                    <td><?php echo nl2br(htmlspecialchars($task['comments'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p>No client retention tasks scheduled for today based on Next Payment Date or Expected Next Up Sale Date.</p>
            <?php endif; ?>
        </div>
    </div>
</body>

</html>

