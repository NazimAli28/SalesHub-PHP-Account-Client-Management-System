<?php
session_start();

// Check if user is logged in and is tl
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'tl') {
    header("Location: ../index.php");
    exit();
}

require_once '../config/config.php';
require_once '../includes/device_helper.php';

// Check device restriction (tl role doesn't allow mobile)
check_device_restriction();

// Check IP restriction for tl
if (!is_ip_allowed()) {
    header("Location: ../index.php?error=access_denied");
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Team Lead Dashboard - Sales Management</title>
    <link rel="stylesheet" href="../assets/style.css">
</head>

<body>
    <div class="dashboard-container">
        <header class="dashboard-header">
            <h1>Team Lead Dashboard</h1>
            <div class="user-info">
                <span>Welcome, <?php echo htmlspecialchars($_SESSION['username']); ?> (<?php echo htmlspecialchars($_SESSION['role']); ?>)</span>
                <a href="../includes/logout.php" class="logout-btn">Logout</a>
            </div>
        </header>

        <div class="dashboard-content">
            <div class="dashboard-grid">
                <div class="dashboard-card">
                    <h3>Team Performance</h3>
                    <p>Monitor team sales and productivity</p>
                    <a href="#" class="btn">View Performance</a>
                </div>

                <div class="dashboard-card">
                    <h3>Agent Management</h3>
                    <p>Manage team members and assignments</p>
                    <a href="#" class="btn">Manage Team</a>
                </div>

                <div class="dashboard-card">
                    <h3>Sales Targets</h3>
                    <p>Set and track sales goals</p>
                    <a href="#" class="btn">Set Targets</a>
                </div>

                <div class="dashboard-card">
                    <h3>Reports</h3>
                    <p>Generate team and individual reports</p>
                    <a href="#" class="btn">View Reports</a>
                </div>
            </div>
        </div>
    </div>
</body>

</html>