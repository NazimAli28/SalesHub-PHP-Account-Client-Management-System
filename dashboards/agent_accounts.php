<?php
session_start();

// Check if user is logged in and is sales_executive
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'sales_executive') {
    header("Location: ../index.php");
    exit();
}

// Prevent cache so Back won't restore stale form/modal state
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

require_once '../config/config.php';
require_once '../includes/device_helper.php';

// Check device restriction (sales_executive role doesn't allow mobile)
check_device_restriction();

// Ensure approvals table exists
$createApprovals = "
CREATE TABLE IF NOT EXISTS approvals (
	id INT AUTO_INCREMENT PRIMARY KEY,
	table_name VARCHAR(255),
	record_id INT NULL,
	action VARCHAR(255),
	changes_json TEXT,
	submitted_by VARCHAR(255),
	submitted_by_user_id INT,
	status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
	reviewed_by VARCHAR(255),
	reviewed_by_user_id INT,
	reviewed_at DATETIME,
	reviewer_comment TEXT,
	created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)";
$conn->query($createApprovals);

// Fix existing table: allow NULL for record_id (for create actions) and action as VARCHAR
$conn->query("ALTER TABLE approvals MODIFY COLUMN record_id INT NULL");
$conn->query("ALTER TABLE approvals MODIFY COLUMN action VARCHAR(255)");

// Check IP restriction for agent
if (!is_ip_allowed()) {
    header("Location: ../index.php?error=access_denied");
    exit();
}

// Handle request new accounts
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_new_accounts'])) {
    $num_accounts = intval($_POST['num_accounts']);
    if ($num_accounts > 0) {
        $changes_json = json_encode(['num_accounts' => $num_accounts]);
        $submitted_by = $_SESSION['name'];
        $submitted_by_user_id = $_SESSION['user_id'];
        $approval_stmt = $conn->prepare("INSERT INTO approvals (table_name, record_id, action, changes_json, submitted_by, submitted_by_user_id) VALUES ('accounts', NULL, 'create', ?, ?, ?)");
        $approval_stmt->bind_param("ssi", $changes_json, $submitted_by, $submitted_by_user_id);
        if ($approval_stmt->execute()) {
            $_SESSION['message'] = "Request for $num_accounts new accounts submitted to support.";
            $_SESSION['message_type'] = "success";
        } else {
            $_SESSION['message'] = "Error submitting request.";
            $_SESSION['message_type'] = "error";
        }
        $approval_stmt->close();
    } else {
        $_SESSION['message'] = "Please enter a valid number of accounts.";
        $_SESSION['message_type'] = "error";
    }
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

// Handle account update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_account'])) {
    $account_id = $_POST['account_id'];
    $new_standings = $_POST['standings'];
    $has_client = isset($_POST['has_client']) ? 1 : 0;
    $discord_password = $_POST['discord_password'];

    // Get current values
    $standings_stmt = $conn->prepare("SELECT standings, pending_standings, has_client, discord_password FROM accounts WHERE id = ?");
    $standings_stmt->bind_param("i", $account_id);
    $standings_stmt->execute();
    $result = $standings_stmt->get_result();
    $row = $result->fetch_assoc();
    $current_standings = $row['standings'];
    $pending_standings = $row['pending_standings'];
    $current_has_client = $row['has_client'];
    $current_discord_password = $row['discord_password'];
    $standings_stmt->close();

    // If there's already a pending change, use the pending as current
    if ($pending_standings) {
        $current_standings = $pending_standings;
    }

    // Collect changes
    $changes = [];
    if ($new_standings !== $current_standings) {
        $changes['standings'] = $new_standings;
    }
    if ($has_client != $current_has_client) {
        $changes['has_client'] = $has_client;
    }
    if ($discord_password !== $current_discord_password) {
        $changes['discord_password'] = $discord_password;
    }

    // If there are changes, insert into approvals table
    if (!empty($changes)) {
        $changes_json = json_encode($changes);
        $submitted_by = $_SESSION['name'];
        $submitted_by_user_id = $_SESSION['user_id'];
        $approval_stmt = $conn->prepare("INSERT INTO approvals (table_name, record_id, action, changes_json, submitted_by, submitted_by_user_id) VALUES ('accounts', ?, 'update', ?, ?, ?)");
        $approval_stmt->bind_param("issi", $account_id, $changes_json, $submitted_by, $submitted_by_user_id);
        if ($approval_stmt->execute()) {
            $_SESSION['message'] = "Account update submitted for approval.";
            $_SESSION['message_type'] = "success";
        } else {
            $_SESSION['message'] = "Error submitting approval request.";
            $_SESSION['message_type'] = "error";
        }
        $approval_stmt->close();
    }

    // Check if standings changed
    if ($new_standings !== $current_standings) {
        // Standings changed, set pending_standings
        $update_stmt = $conn->prepare("UPDATE accounts SET pending_standings = ? WHERE id = ?");
        $update_stmt->bind_param("si", $new_standings, $account_id);
        $update_stmt->execute();
        $update_stmt->close();
        $redirect_to_approval = true;
    } else {
        $redirect_to_approval = false;
    }

    // Redirect to prevent form resubmission
    if ($redirect_to_approval) {
        header("Location: ?standings=on_approval");
    } else {
        header("Location: " . $_SERVER['PHP_SELF']);
    }
    exit();
}

// Check for session messages
$message = isset($_SESSION['message']) ? $_SESSION['message'] : '';
$message_type = isset($_SESSION['message_type']) ? $_SESSION['message_type'] : '';
unset($_SESSION['message'], $_SESSION['message_type']);

// Get agent's PC number
$user_id = $_SESSION['user_id'];
$user_stmt = $conn->prepare("SELECT pc_number FROM users WHERE id = ?");
$user_stmt->bind_param("i", $user_id);
$user_stmt->execute();
$user_result = $user_stmt->get_result();
$user = $user_result->fetch_assoc();
$agent_pc_number = $user['pc_number'];
$user_stmt->close();

// Get account counts
if ($agent_pc_number) {
    $total_query = "SELECT COUNT(*) as count FROM accounts WHERE pc_number = '$agent_pc_number'";
    $total_result = $conn->query($total_query);
    $total_count = $total_result->fetch_assoc()['count'];

    $active_query = "SELECT COUNT(*) as count FROM accounts WHERE pc_number = '$agent_pc_number' AND standings = 'Active' AND pending_standings IS NULL";
    $active_result = $conn->query($active_query);
    $active_count = $active_result->fetch_assoc()['count'];

    $spam_query = "SELECT COUNT(*) as count FROM accounts WHERE pc_number = '$agent_pc_number' AND standings = 'Spam' AND pending_standings IS NULL";
    $spam_result = $conn->query($spam_query);
    $spam_count = $spam_result->fetch_assoc()['count'];

    $limited_query = "SELECT COUNT(*) as count FROM accounts WHERE pc_number = '$agent_pc_number' AND standings = 'Limited' AND pending_standings IS NULL";
    $limited_result = $conn->query($limited_query);
    $limited_count = $limited_result->fetch_assoc()['count'];

    $disabled_query = "SELECT COUNT(*) as count FROM accounts WHERE pc_number = '$agent_pc_number' AND standings = 'Disabled' AND pending_standings IS NULL";
    $disabled_result = $conn->query($disabled_query);
    $disabled_count = $disabled_result->fetch_assoc()['count'];

    $violation_query = "SELECT COUNT(*) as count FROM accounts WHERE pc_number = '$agent_pc_number' AND standings = 'Violation' AND pending_standings IS NULL";
    $violation_result = $conn->query($violation_query);
    $violation_count = $violation_result->fetch_assoc()['count'];

    $on_approval_query = "SELECT COUNT(*) as count FROM accounts WHERE pc_number = '$agent_pc_number' AND pending_standings IS NOT NULL";
    $on_approval_result = $conn->query($on_approval_query);
    $on_approval_count = $on_approval_result->fetch_assoc()['count'];
} else {
    $total_count = $active_count = $spam_count = $limited_count = $disabled_count = $violation_count = $on_approval_count = 0;
}

// Get standings filter from GET parameter, default to 'Active'
$standings_filter = isset($_GET['standings']) ? $_GET['standings'] : 'Active';
$allowed_standings_filters = ['Active', 'Spam', 'Limited', 'Disabled', 'Violation', 'on_approval', 'all'];
if (!in_array($standings_filter, $allowed_standings_filters, true)) {
    $standings_filter = 'Active';
}

// Fetch unique discord_emails for filter dropdown
$discord_emails = [];
if ($agent_pc_number) {
    $email_query = "SELECT DISTINCT discord_email FROM accounts WHERE pc_number = '$agent_pc_number' AND discord_email IS NOT NULL AND discord_email != ''";
    $email_result = $conn->query($email_query);
    while ($row = $email_result->fetch_assoc()) {
        $discord_emails[] = $row['discord_email'];
    }
}

// Fetch accounts assigned to this agent's PC number with standings filter and additional filters
$accounts = [];
if ($agent_pc_number) {
    $query = "SELECT id, email, email_password, discord_email, discord_password, recovery_email, standings, has_client, pending_standings FROM accounts WHERE pc_number = '$agent_pc_number'";
    if ($standings_filter !== 'all') {
        if ($standings_filter === 'on_approval') {
            $query .= " AND pending_standings IS NOT NULL";
        } else {
            $query .= " AND standings = '$standings_filter' AND pending_standings IS NULL";
        }
    }
    if (!empty($_GET['filter_discord_email'])) {
        $query .= " AND discord_email = '" . $conn->real_escape_string($_GET['filter_discord_email']) . "'";
    }
    if (!empty($_GET['search_discord_email'])) {
        $query .= " AND discord_email LIKE '%" . $conn->real_escape_string($_GET['search_discord_email']) . "%'";
    }
    $query .= " ORDER BY id DESC";
    $result = $conn->query($query);
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $accounts[] = $row;
        }
        $result->free();
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Accounts - Sales Executive Dashboard</title>
    <link rel="stylesheet" href="../assets/style.css">
    <style>
        .accounts-container {
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

        .accounts-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.08);
        }

        .accounts-table th,
        .accounts-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }

        .accounts-table th {
            background: #f8f9fa;
            font-weight: 600;
        }

        .accounts-table tr:hover {
            background: #f8f9fa;
        }

        .standings-active {
            color: #28a745;
            font-weight: bold;
        }

        .standings-spam {
            color: #dc3545;
            font-weight: bold;
        }

        .standings-limited_access {
            color: #ffc107;
            font-weight: bold;
        }

        .standings-on_approval {
            color: #ff6b35;
            font-weight: bold;
        }

        .btn-update {
            background: #007bff;
            color: white;
            padding: 5px 10px;
            border: none;
            border-radius: 3px;
            cursor: pointer;
            font-size: 14px;
        }

        .btn-edit {
            background: #007bff;
            color: white;
            padding: 5px 10px;
            border: none;
            border-radius: 3px;
            cursor: pointer;
            font-size: 14px;
        }

        .btn-edit:hover {
            background: #0056b3;
        }

        .btn-request {
            background: #28a745;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 16px;
            margin-bottom: 20px;
        }

        .btn-request:hover {
            background: #218838;
        }

        .modal {
            display: none;
            position: fixed;
            z-index: 1;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.4);
        }

        .modal-content {
            background-color: #fefefe;
            margin: 15% auto;
            padding: 20px;
            border: 1px solid #888;
            width: 80%;
            max-width: 500px;
        }

        .close {
            color: #aaa;
            float: right;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
        }

        .close:hover {
            color: black;
        }

        .masked-email {
            font-family: monospace;
        }

        .standings-tabs {
            display: flex;
            margin-bottom: 20px;
            border-bottom: 1px solid #dee2e6;
        }

        .standings-tab {
            padding: 10px 20px;
            text-decoration: none;
            color: #6c757d;
            border-bottom: 2px solid transparent;
            transition: all 0.3s ease;
        }

        .filter-container {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
        }

        .filter-form {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            align-items: end;
        }

        .form-group {
            flex: 1;
            min-width: 200px;
            display: flex;
            flex-direction: column;
        }

        .form-group label {
            margin-bottom: 5px;
            font-weight: 600;
            color: #495057;
        }

        .form-group select,
        .form-group input[type="text"] {
            padding: 8px 12px;
            border: 1px solid #ced4da;
            border-radius: 4px;
            font-size: 14px;
        }

        .filter-buttons {
            display: flex;
            gap: 10px;
            align-items: center;
        }

        .filter-btn {
            padding: 8px 16px;
            background: #007bff;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            transition: background 0.3s ease;
        }

        .filter-btn:hover {
            background: #0056b3;
        }

        .clear-btn {
            padding: 8px 16px;
            background: #6c757d;
            color: white;
            text-decoration: none;
            border-radius: 4px;
            font-size: 14px;
            transition: background 0.3s ease;
        }

        .clear-btn:hover {
            background: #545b62;
        }

        .filter-container h3 {
            margin-top: 0;
            color: #495057;
            font-size: 18px;
        }

        .standings-tab:hover {
            color: #007bff;
        }

        .standings-tab.active {
            color: #007bff;
            border-bottom-color: #007bff;
            font-weight: bold;
        }

        .message {
            padding: 10px;
            margin-bottom: 20px;
            border-radius: 5px;
            text-align: center;
        }

        .message.success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .message.error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .message.info {
            background: #d1ecf1;
            color: #0c5460;
            border: 1px solid #bee5eb;
        }

        span.count {
            font-size: 12px;
            font-weight: 800;
            margin: 0px 4px;
        }
    </style>
    <script>
        function openModal(account_id, standings, has_client, discord_password) {
            document.getElementById('account_id').value = account_id;
            document.getElementById('standings').value = standings;
            document.getElementById('has_client').checked = has_client;
            document.getElementById('discord_password').value = discord_password;
            document.getElementById('editModal').style.display = 'block';
        }

        function openRequestModal() {
            document.getElementById('requestModal').style.display = 'block';
        }

        function closeModal() {
            document.getElementById('editModal').style.display = 'none';
            document.getElementById('requestModal').style.display = 'none';
        }
        // Close modal when clicking outside
        window.onclick = function(event) {
            if (event.target == document.getElementById('editModal') || event.target == document.getElementById('requestModal')) {
                closeModal();
            }
        }

        // Ensure modal/form is closed and cleared on back/restore (bfcache) and fresh loads
        window.addEventListener('pageshow', function(event) {
            try {
                closeModal();
                var form = document.getElementById('editForm');
                if (form) {
                    form.reset();
                }
                var requestForm = document.getElementById('requestForm');
                if (requestForm) {
                    requestForm.reset();
                }
            } catch (e) {}
        });
    </script>
</head>

<body>
    <div class="dashboard-container">
        <header class="dashboard-header">
            <h1>My Accounts</h1>
            <nav></nav>
            <div class="user-info">
                <span>Welcome, <?php echo htmlspecialchars($_SESSION['name']); ?></span>
                <a href="../includes/logout.php" class="logout-btn">Logout</a>
            </div>
        </header>

        <div class="dashboard-content">
            <a href="dashboard_<?php echo $_SESSION['role']; ?>.php" class="back-link">&larr; Back to Dashboard</a>
            <div class="accounts-container">
                <h2>My Accounts</h2>
                <button type="button" class="btn-request" onclick="openRequestModal()">Request New Accounts</button>
                <?php if ($message): ?>
                    <div class="message <?php echo $message_type; ?>">
                        <?php echo htmlspecialchars($message); ?>
                    </div>
                <?php endif; ?>
                <div class="standings-tabs">
                    <a href="?standings=all" class="standings-tab <?php echo $standings_filter === 'all' ? 'active' : ''; ?>">All<span class="count">(<?php echo $total_count; ?>)</span></a>
                    <a href="?standings=Active" class="standings-tab <?php echo $standings_filter === 'Active' ? 'active' : ''; ?>">Active<span class="count">(<?php echo $active_count; ?>)</span></a>
                    <a href="?standings=Spam" class="standings-tab <?php echo $standings_filter === 'Spam' ? 'active' : ''; ?>">Spam<span class="count">(<?php echo $spam_count; ?>)</span></a>
                    <a href="?standings=Limited" class="standings-tab <?php echo $standings_filter === 'Limited' ? 'active' : ''; ?>">Limited<span class="count">(<?php echo $limited_count; ?>)</span></a>
                    <a href="?standings=Disabled" class="standings-tab <?php echo $standings_filter === 'Disabled' ? 'active' : ''; ?>">Disabled<span class="count">(<?php echo $disabled_count; ?>)</span></a>
                    <a href="?standings=Violation" class="standings-tab <?php echo $standings_filter === 'Violation' ? 'active' : ''; ?>">Violation<span class="count">(<?php echo $violation_count; ?>)</span></a>
                    <a href="?standings=on_approval" class="standings-tab <?php echo $standings_filter === 'on_approval' ? 'active' : ''; ?>">On Approval<span class="count">(<?php echo $on_approval_count; ?>)</span></a>
                </div>

                <!-- Filter Form -->
                <div class="filter-container">
                    <h3>Filter Accounts by Discord Email</h3>
                    <form method="GET" action="" class="filter-form">
                        <div class="form-group">
                            <label for="filter_discord_email">Exact Match:</label>
                            <select id="filter_discord_email" name="filter_discord_email">
                                <option value="">All Discord Emails</option>
                                <?php foreach ($discord_emails as $email): ?>
                                    <option value="<?php echo htmlspecialchars($email); ?>" <?php echo (isset($_GET['filter_discord_email']) && $_GET['filter_discord_email'] === $email) ? 'selected' : ''; ?>><?php echo htmlspecialchars($email); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="search_discord_email">Partial Search:</label>
                            <input type="text" id="search_discord_email" name="search_discord_email" value="<?php echo isset($_GET['search_discord_email']) ? htmlspecialchars($_GET['search_discord_email']) : ''; ?>" placeholder="Enter part of Discord email">
                        </div>
                        <div class="filter-buttons">
                            <button type="submit" class="filter-btn">Apply Filter</button>
                            <a href="<?php echo basename(__FILE__); ?>?standings=<?php echo urlencode($standings_filter); ?>" class="clear-btn">Clear All</a>
                        </div>
                        <input type="hidden" name="standings" value="<?php echo htmlspecialchars($standings_filter); ?>">
                    </form>
                </div>

                <?php if ($accounts): ?>
                    <table class="accounts-table">
                        <thead>
                            <tr>
                                <th>Email</th>
                                <th>Email Password</th>
                                <th>Discord Email</th>
                                <th>Discord Password</th>
                                <th>Recovery Email</th>
                                <th>Standings</th>
                                <th>Has Client</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($accounts as $account): ?>
                                <?php
                                $current_standings = $account['standings'];
                                if ($account['pending_standings']) {
                                    $current_standings = $account['pending_standings'];
                                }
                                ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($account['email']); ?></td>
                                    <td><?php echo htmlspecialchars($account['email_password']); ?></td>
                                    <td><?php echo htmlspecialchars($account['discord_email'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($account['discord_password'] ?? ''); ?></td>
                                    <td> <!--class="masked-email"--><?php echo htmlspecialchars(/*maskEmail(*/$account['recovery_email'] ?? ''/*)*/); ?></td>
                                    <td>
                                        <?php
                                        $current_display = ucwords(str_replace('_', ' ', $account['standings']));
                                        if ($account['pending_standings']) {
                                            $pending_display = ucwords(str_replace('_', ' ', $account['pending_standings']));
                                            echo htmlspecialchars("$current_display (Pending: Change to $pending_display)");
                                        } else {
                                            echo htmlspecialchars($current_display);
                                        }
                                        ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($account['has_client'] ? 'Yes' : 'No'); ?></td>
                                    <td>
                                        <button type="button" class="btn-edit" onclick="openModal(<?php echo (int)$account['id']; ?>, '<?php echo htmlspecialchars($current_standings, ENT_QUOTES); ?>', <?php echo $account['has_client'] ? 'true' : 'false'; ?>, '<?php echo htmlspecialchars($account['discord_password'] ?? '', ENT_QUOTES); ?>')">Edit</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p>No accounts assigned to your PC number.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Edit Modal -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal()">&times;</span>
            <h2>Edit Account</h2>
            <form id="editForm" method="post" onsubmit="return confirm('Are you sure you want to update the account? Standings changes require approval.')">
                <input type="hidden" name="account_id" id="account_id">
                <label for="standings">Standings:</label><br>
                <select name="standings" id="standings">
                    <option value="Active">Active</option>
                    <option value="Spam">Spam</option>
                    <option value="Limited">Limited</option>
                    <option value="Disabled">Disabled</option>
                    <option value="Suspended">Suspended</option>
                    <option value="Violation">Violation</option>
                </select><br><br>
                <label for="has_client">Has Client:</label>
                <input type="checkbox" name="has_client" id="has_client"><br><br>
                <label for="discord_password">Discord Password:</label><br>
                <input type="text" name="discord_password" id="discord_password"><br><br>
                <button type="submit" name="update_account">Update</button>
            </form>
        </div>
    </div>

    <!-- Request Modal -->
    <div id="requestModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal()">&times;</span>
            <h2>Request New Accounts</h2>
            <form id="requestForm" method="post" onsubmit="return confirm('Are you sure you want to request new accounts?')">
                <label for="num_accounts">Number of Accounts:</label><br>
                <input type="number" name="num_accounts" id="num_accounts" min="1" required><br><br>
                <button type="submit" name="request_new_accounts">Submit Request</button>
            </form>
        </div>
    </div>

    <?php
    function maskEmail($email)
    {
        if (empty($email)) return '';
        $parts = explode('@', $email);
        if (count($parts) !== 2) return $email;
        $local = $parts[0];
        $domain = $parts[1];
        $maskedLocal = substr($local, 0, 3) . str_repeat('*', max(0, strlen($local) - 3));
        return $maskedLocal . '@' . $domain;
    }
    ?>
</body>

</html>