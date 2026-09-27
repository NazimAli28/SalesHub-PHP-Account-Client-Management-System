<?php
session_start();

// Check if user is logged in and is sales_executive
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'sales_executive') {
    header("Location: ../index.php");
    exit();
}

require_once '../config/config.php';
require_once '../includes/device_helper.php';

// Check device restriction (sales_executive role doesn't allow mobile)
check_device_restriction();

// Check IP restriction for agent
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
    <title>Sales Executive Dashboard</title>
    <link rel="stylesheet" href="../assets/style.css">
</head>

<body>
    <div class="dashboard-container">
        <header class="dashboard-header">
            <h1>Sales Executive Dashboard</h1>
            <div class="user-info">
                <span>Welcome, <?php echo htmlspecialchars($_SESSION['name']); ?></span>
                <a href="../includes/logout.php" class="logout-btn">Logout</a>
            </div>
        </header>

        <div class="dashboard-content">
            <div class="dashboard-grid">
                <div class="dashboard-card">
                    <h3>My Accounts</h3>
                    <p>All assigned accounts</p>
                    <a href="agent_accounts.php" class="btn">View Accounts</a>
                </div>

                <div class="dashboard-card">
                    <h3>Today's Client Retention Tasks</h3>
                    <p>Tasks due today from client retention</p>
                    <a href="client_retention_today.php" class="btn">View Today's Tasks</a>
                </div>

                <div class="dashboard-card">
                    <h3>Client Retention Form</h3>
                    <p>Fill client retention data</p>
                    <a href="client_retention_form.php" class="btn">Access Form</a>
                </div>

                <div class="dashboard-card">
                    <h3>Leads Data Form</h3>
                    <p>Fill and manage leads data</p>
                    <a href="leads_data.php" class="btn">Access Form</a>
                </div>

                <div class="dashboard-card">
                    <h3>Socials Data</h3>
                    <p>Manage social media accounts</p>
                    <a href="socials_data.php" class="btn">Access Form</a>
                </div>
            </div>
        </div>
    </div>
</body>

</html>