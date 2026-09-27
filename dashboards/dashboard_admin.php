<?php
session_start();

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../index.php");
    exit();
}

require_once '../includes/device_helper.php';

// Check device restriction (admin role doesn't allow mobile)
check_device_restriction();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Sales Management</title>
    <link rel="stylesheet" href="../assets/style.css">
</head>

<body>
    <div class="dashboard-container">
        <header class="dashboard-header">
            <h1>Admin Dashboard</h1>
            <div class="user-info">
                <span>Welcome, <?php echo htmlspecialchars($_SESSION['name']); ?> (<?php echo htmlspecialchars($_SESSION['role']); ?>)</span>
                <a href="../includes/logout.php" class="logout-btn">Logout</a>
            </div>
        </header>

        <div class="dashboard-content">
            <div class="dashboard-grid">
                <div class="dashboard-card">
                    <h3>User Management</h3>
                    <p>Manage users, roles, and permissions</p>
                    <a href="user_management.php" class="btn">Manage Users</a>
                </div>

                <div class="dashboard-card">
                    <h3>Accounts Management</h3>
                    <p>Manage Discord accounts and related information</p>
                    <a href="accounts_management.php" class="btn">Manage Accounts</a>
                </div>

                <div class="dashboard-card">
                    <h3>Support Tickets Approval History</h3>
                    <p>Handle customer support requests</p>
                    <a href="approvals_history.php" class="btn">View Tickets</a>
                </div>

                <div class="dashboard-card">
                    <h3>Client Retention Management</h3>
                    <p>Manage client retention and related data</p>
                    <a href="client_retention_management.php" class="btn">Manage Clients Data</a>
                </div>

                <div class="dashboard-card">
                    <h3>Leads Data Management</h3>
                    <p>Manage leads data entries</p>
                    <a href="leads_data_management.php" class="btn">Manage Leads Data</a>
                </div>

                <div class="dashboard-card">
                    <h3>Socials Data Management</h3>
                    <p>Manage social media accounts</p>
                    <a href="socials_data_management.php" class="btn">Manage Socials Data</a>
                </div>

                <div class="dashboard-card">
                    <h3>User Accounts Summary</h3>
                    <p>View summary of accounts assigned to sales executives</p>
                    <a href="user_accounts_summary.php" class="btn">View Summary</a>
                </div>
            </div>
        </div>
    </div>
</body>

</html>
