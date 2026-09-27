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

// Check IP restriction for agent
if (!is_ip_allowed()) {
    header("Location: ../index.php?error=access_denied");
    exit();
}

// Fetch Discord emails assigned to the current agent
// Always fetch pc_number from users table to ensure accuracy
$agent_pc_number = $_SESSION['username']; // default
if (isset($_SESSION['user_id'])) {
    $tmp_stmt = $conn->prepare("SELECT pc_number, name FROM users WHERE id = ?");
    $tmp_stmt->bind_param("i", $_SESSION['user_id']);
    $tmp_stmt->execute();
    $tmp_res = $tmp_stmt->get_result();
    if ($tmp_row = $tmp_res->fetch_assoc()) {
        if (!empty($tmp_row['pc_number'])) {
            $agent_pc_number = $tmp_row['pc_number'];
            $_SESSION['pc_number'] = $agent_pc_number;
        }
        if (!empty($tmp_row['name'])) {
            $_SESSION['name'] = $tmp_row['name'];
        }
    }
    $tmp_stmt->close();
}

// Always fetch name if not set
if (!isset($_SESSION['name'])) {
    if (isset($_SESSION['user_id'])) {
        $tmp_stmt = $conn->prepare("SELECT name FROM users WHERE id = ?");
        $tmp_stmt->bind_param("i", $_SESSION['user_id']);
        $tmp_stmt->execute();
        $tmp_res = $tmp_stmt->get_result();
        if ($tmp_row = $tmp_res->fetch_assoc()) {
            if (!empty($tmp_row['name'])) {
                $_SESSION['name'] = $tmp_row['name'];
            }
        }
        $tmp_stmt->close();
    }
}

$discord_emails = [];
$stmt = $conn->prepare("SELECT discord_email FROM accounts WHERE pc_number = ? AND status = 1 AND discord_email IS NOT NULL AND discord_email != ''");
$stmt->bind_param("s", $agent_pc_number);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $discord_emails[] = $row['discord_email'];
}
$stmt->close();

// Create table if not exists
$table_sql = "CREATE TABLE IF NOT EXISTS socials_data (
    id INT AUTO_INCREMENT PRIMARY KEY,
    discord_email VARCHAR(255) NOT NULL,
    social_account VARCHAR(255) NOT NULL,
    username_email VARCHAR(255) NOT NULL,
    password VARCHAR(255) NOT NULL,
    date_of_creation DATE NOT NULL,
    sales_executive VARCHAR(255) DEFAULT NULL,
    pc_number VARCHAR(255) DEFAULT NULL,
    is_using TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_discord_email (discord_email)
)";
$conn->query($table_sql);

// Ensure new columns exist for backwards compatibility
$colCheck = $conn->query("SHOW COLUMNS FROM socials_data LIKE 'sales_executive'");
if ($colCheck && $colCheck->num_rows === 0) {
    $conn->query("ALTER TABLE socials_data ADD COLUMN sales_executive VARCHAR(255) NULL AFTER date_of_creation");
}
if ($colCheck) {
    $colCheck->close();
}
$colCheck = $conn->query("SHOW COLUMNS FROM socials_data LIKE 'pc_number'");
if ($colCheck && $colCheck->num_rows === 0) {
    $conn->query("ALTER TABLE socials_data ADD COLUMN pc_number VARCHAR(255) NULL AFTER sales_executive");
}
if ($colCheck) {
    $colCheck->close();
}
$colCheck = $conn->query("SHOW COLUMNS FROM socials_data LIKE 'is_using'");
if ($colCheck && $colCheck->num_rows === 0) {
    $conn->query("ALTER TABLE socials_data ADD COLUMN is_using TINYINT(1) DEFAULT 0 AFTER pc_number");
}
if ($colCheck) {
    $colCheck->close();
}

// Social Account options
$social_account_options = [
    'Twitch',
    'Twitter/X',
    'Kick',
    'Instagram',
    'TikTok',
    'Xbox',
    'PS 4',
    'YouTube',
    'Crunchyroll',
    'Roblox',
    'Thread',
    'Bluesky',
    'Steam',
    'Spotify',
    'Other'
];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? $_POST['action'] : 'create';

    // Ensure approvals table exists
    $conn->query("CREATE TABLE IF NOT EXISTS approvals (
		id INT AUTO_INCREMENT PRIMARY KEY,
		table_name VARCHAR(64) NOT NULL,
		record_id INT NOT NULL,
		action ENUM('update','delete','create') NOT NULL DEFAULT 'update',
		changes_json JSON NOT NULL,
		submitted_by VARCHAR(100) NOT NULL,
		submitted_by_user_id INT NOT NULL,
		status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
		reviewed_by VARCHAR(100) NULL,
		reviewed_by_user_id INT NULL,
		reviewed_at DATETIME NULL,
		created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
		reviewer_comment TEXT NULL
	)");

    if ($action === 'delete') {
        if (!isset($_POST['id'])) {
            die('Error: Missing id.');
        }
        $id = intval($_POST['id']);

        // Create approval ticket instead of deleting immediately
        $changes = ['id' => $id];
        $json = json_encode($changes, JSON_UNESCAPED_UNICODE);
        $submittedBy = isset($_SESSION['username']) ? $_SESSION['username'] : 'sales_executive';
        $submittedById = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : 0;
        $ins = $conn->prepare("INSERT INTO approvals (table_name, record_id, action, changes_json, submitted_by, submitted_by_user_id) VALUES ('socials_data', ?, 'delete', ?, ?, ?)");
        $ins->bind_param("issi", $id, $json, $submittedBy, $submittedById);
        $ins->execute();
        $ins->close();

        header("Location: " . basename(__FILE__) . "?queued=1");
        exit();
    }

    // Server-side validation for required fields (create/update)
    $required_fields = ['discord_email', 'social_account', 'username_email', 'password', 'date_of_creation'];
    foreach ($required_fields as $field) {
        if (empty($_POST[$field])) {
            die("Error: Field '$field' is required.");
        }
    }

    // Validate date field
    if (empty($_POST['date_of_creation']) || !DateTime::createFromFormat('Y-m-d', $_POST['date_of_creation'])) {
        die("Error: Invalid date.");
    }

    // Collect and sanitize inputs
    $discord_email = filter_var($_POST['discord_email'], FILTER_SANITIZE_EMAIL);

    // Fetch sales executive and pc_number based on discord_email (if assigned/active)
    $sales_executive = '';
    $pc_number = '';
    if (!empty($discord_email)) {
        $fetch_stmt = $conn->prepare("SELECT agent_name, pc_number FROM accounts WHERE discord_email = ? AND status = 1 LIMIT 1");
        if ($fetch_stmt) {
            $fetch_stmt->bind_param("s", $discord_email);
            if ($fetch_stmt->execute()) {
                $fetch_res = $fetch_stmt->get_result();
                if ($fetch_row = $fetch_res->fetch_assoc()) {
                    $sales_executive = htmlspecialchars(trim($fetch_row['agent_name'] ?? ''));
                    $pc_number = htmlspecialchars(trim($fetch_row['pc_number'] ?? ''));
                }
            }
            $fetch_stmt->close();
        }
    }

    // Handle social_account: if "Other" is selected, use the value from social_account_other
    $social_account_input = htmlspecialchars($_POST['social_account']);
    if ($social_account_input === 'Other') {
        if (empty($_POST['social_account_other']) || trim($_POST['social_account_other']) === '') {
            die("Error: Please specify the social account name when 'Other' is selected.");
        }
        $social_account = htmlspecialchars(trim($_POST['social_account_other']));
    } else {
        if (!in_array($social_account_input, $social_account_options)) {
            die("Error: Invalid social account.");
        }
        $social_account = $social_account_input;
    }

    $username_email = htmlspecialchars($_POST['username_email']);
    $password = htmlspecialchars($_POST['password']);
    $date_of_creation = $_POST['date_of_creation'];
    $is_using = isset($_POST['is_using']) ? 1 : 0;

    if ($action === 'update') {
        if (!isset($_POST['id'])) {
            die('Error: Missing id for update.');
        }
        $id = intval($_POST['id']);

        // Compare with existing to build changes diff
        $curStmt = $conn->prepare("SELECT * FROM socials_data WHERE id = ?");
        $curStmt->bind_param("i", $id);
        $curStmt->execute();
        $curRes = $curStmt->get_result();
        $current = $curRes->fetch_assoc();
        $curStmt->close();

        if (!$current) {
            die('Error: Record not found.');
        }

        $proposed = [
            'discord_email' => $discord_email,
            'social_account' => $social_account,
            'username_email' => $username_email,
            'password' => $password,
            'date_of_creation' => $date_of_creation,
            'sales_executive' => $sales_executive,
            'pc_number' => $pc_number,
            'is_using' => $is_using
        ];

        $changes = [];
        foreach ($proposed as $field => $value) {
            $curVal = isset($current[$field]) ? (string)$current[$field] : null;
            if ($curVal !== (string)$value) {
                $changes[$field] = $value;
            }
        }

        // Prevent duplicates when updating (same discord_email + social_account + username_email)
        $dup_update_stmt = $conn->prepare("SELECT id FROM socials_data WHERE discord_email = ? AND social_account = ? AND username_email = ? AND id <> ? LIMIT 1");
        $dup_update_stmt->bind_param("sssi", $discord_email, $social_account, $username_email, $id);
        $dup_update_stmt->execute();
        $dup_update_res = $dup_update_stmt->get_result();
        if ($dup_update_res->num_rows > 0) {
            $dup_update_stmt->close();
            die("Error: Duplicate entry exists for this Discord email, social account, and username/email.");
        }
        $dup_update_stmt->close();

        if (empty($changes)) {
            header("Location: " . basename(__FILE__) . "?nochange=1");
            exit();
        }

        $json = json_encode($changes, JSON_UNESCAPED_UNICODE);
        $submittedBy = isset($_SESSION['username']) ? $_SESSION['username'] : 'sales_executive';
        $submittedById = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : 0;

        $ins = $conn->prepare("INSERT INTO approvals (table_name, record_id, action, changes_json, submitted_by, submitted_by_user_id) VALUES ('socials_data', ?, 'update', ?, ?, ?)");
        $ins->bind_param("issi", $id, $json, $submittedBy, $submittedById);
        $ins->execute();
        $ins->close();

        header("Location: " . basename(__FILE__) . "?queued=1");
        exit();
    }

    // Default: create
    // Prevent duplicates on create (same discord_email + social_account + username_email)
    $dup_stmt = $conn->prepare("SELECT id FROM socials_data WHERE discord_email = ? AND social_account = ? AND username_email = ? LIMIT 1");
    $dup_stmt->bind_param("sss", $discord_email, $social_account, $username_email);
    $dup_stmt->execute();
    $dup_res = $dup_stmt->get_result();
    if ($dup_res->num_rows > 0) {
        $dup_stmt->close();
        die("Error: Duplicate entry exists for this Discord email, social account, and username/email.");
    }
    $dup_stmt->close();

    $stmt = $conn->prepare("INSERT INTO socials_data (discord_email, social_account, username_email, password, date_of_creation, sales_executive, pc_number, is_using) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("sssssssi", $discord_email, $social_account, $username_email, $password, $date_of_creation, $sales_executive, $pc_number, $is_using);
    $stmt->execute();
    $stmt->close();

    header("Location: " . basename(__FILE__) . "?success=1");
    exit();
}

// Fetch all entries owned by the agent's discord_emails with optional filters
$entries = [];
if (!empty($discord_emails)) {
    $placeholders = str_repeat('?,', count($discord_emails) - 1) . '?';
    $sql = "SELECT * FROM socials_data WHERE discord_email IN ($placeholders)";
    $params = $discord_emails;
    $types = str_repeat('s', count($discord_emails));

    // Add filters
    if (!empty($_GET['filter_discord_email'])) {
        $sql .= " AND discord_email = ?";
        $params[] = $_GET['filter_discord_email'];
        $types .= 's';
    }
    if (!empty($_GET['filter_social_account'])) {
        $sql .= " AND social_account = ?";
        $params[] = $_GET['filter_social_account'];
        $types .= 's';
    }
    if (!empty($_GET['filter_username_email'])) {
        $sql .= " AND username_email LIKE ?";
        $params[] = '%' . $_GET['filter_username_email'] . '%';
        $types .= 's';
    }

    $sql .= " ORDER BY date_of_creation DESC, created_at DESC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $entries[] = $row;
        }
    }
    $result->close();
    $stmt->close();
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Socials Data - Sales Executive Dashboard</title>
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

        .table-wrapper {
            overflow-x: auto;
        }

        .accounts-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.08);
        }

        .accounts-table th,
        .accounts-table td {
            padding: 12px 20px;
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

        .btn-add {
            background: #007bff;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            margin-bottom: 20px;
        }

        .btn-add:hover {
            background: #0056b3;
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
            margin: 5% auto;
            padding: 20px;
            border: 1px solid #888;
            width: 90%;
            max-width: 600px;
            max-height: 80%;
            overflow-y: auto;
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

        .form-group {
            margin-bottom: 15px;
        }

        label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }

        input,
        select,
        textarea {
            width: 100%;
            padding: 8px;
            border: 1px solid #ccc;
            border-radius: 4px;
            box-sizing: border-box;
        }

        button {
            padding: 10px 20px;
            background: #007bff;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }

        button:hover {
            background: #0056b3;
        }

        .btn-edit {
            background: #ffc107;
            color: #212529;
            padding: 5px 10px;
            border: none;
            border-radius: 3px;
            cursor: pointer;
            font-size: 14px;
        }

        .btn-edit:hover {
            background: #e0a800;
        }

        .btn-delete {
            background: #dc3545;
            color: white;
            padding: 5px 10px;
            border: none;
            border-radius: 3px;
            cursor: pointer;
            font-size: 14px;
            margin-left: 5px;
        }

        .btn-delete:hover {
            background: #c82333;
        }

        .alert {
            padding: 15px;
            margin-bottom: 20px;
            border: 1px solid transparent;
            border-radius: 4px;
        }

        .alert-success {
            color: #155724;
            background-color: #d4edda;
            border-color: #c3e6cb;
        }

        .alert-info {
            color: #0c5460;
            background-color: #d1ecf1;
            border-color: #bee5eb;
        }

        .other-field {
            display: none;
            margin-top: 10px;
        }
    </style>
    <script>
        function openModal() {
            document.getElementById('addModal').style.display = 'block';
        }

        function closeModal() {
            document.getElementById('addModal').style.display = 'none';
            document.getElementById('editModal').style.display = 'none';
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            if (event.target == document.getElementById('addModal')) {
                closeModal();
            }
            if (event.target == document.getElementById('editModal')) {
                closeModal();
            }
        }

        function toggleOtherField(selectElement, prefix = '') {
            const otherField = document.getElementById(prefix + 'social_account_other');
            if (otherField) {
                otherField.parentElement.style.display = (selectElement.value === 'Other') ? 'block' : 'none';
                if (selectElement.value !== 'Other') {
                    otherField.value = '';
                }
            }
        }

        function openEditModal(data) {
            const m = document.getElementById('editModal');
            document.getElementById('edit_id').value = data.id;
            document.getElementById('edit_discord_email').value = data.discord_email;
            document.getElementById('edit_username_email').value = data.username_email;
            document.getElementById('edit_password').value = data.password;
            document.getElementById('edit_date_of_creation').value = data.date_of_creation;

            // Check if social_account is in the predefined list
            const socialAccountSelect = document.getElementById('edit_social_account');
            const otherField = document.getElementById('edit_social_account_other');
            const otherFieldGroup = otherField.parentElement;
            const predefinedOptions = <?php echo json_encode($social_account_options); ?>;

            if (predefinedOptions.includes(data.social_account)) {
                // It's a predefined option
                socialAccountSelect.value = data.social_account;
                otherField.value = '';
                otherFieldGroup.style.display = 'none';
            } else {
                // It's a custom "Other" value
                socialAccountSelect.value = 'Other';
                otherField.value = data.social_account;
                otherFieldGroup.style.display = 'block';
            }

            document.getElementById('edit_is_using').checked = data.is_using == 1;

            m.style.display = 'block';
        }

        document.addEventListener('DOMContentLoaded', function() {
            // Edit handlers
            document.querySelectorAll('.btn-edit').forEach(btn => {
                btn.addEventListener('click', function() {
                    const d = this.dataset;
                    openEditModal({
                        id: d.id,
                        discord_email: d.discord_email,
                        social_account: d.social_account,
                        username_email: d.username_email,
                        password: d.password,
                        date_of_creation: d.date_of_creation,
                        is_using: d.is_using
                    });
                });
            });

            // Delete handlers
            const delForm = document.getElementById('deleteForm');
            document.querySelectorAll('.btn-delete').forEach(btn => {
                btn.addEventListener('click', function() {
                    const id = this.dataset.id;
                    if (confirm('Are you sure you want to delete this entry?')) {
                        delForm.querySelector('input[name="id"]').value = id;
                        delForm.submit();
                    }
                });
            });

            // Toggle "Other" field for add form
            const addSocialAccount = document.getElementById('social_account');
            if (addSocialAccount) {
                addSocialAccount.addEventListener('change', function() {
                    toggleOtherField(this);
                });
            }

            // Toggle "Other" field for edit form
            const editSocialAccount = document.getElementById('edit_social_account');
            if (editSocialAccount) {
                editSocialAccount.addEventListener('change', function() {
                    toggleOtherField(this, 'edit_');
                });
            }

            // Form validation for "Other" field
            const addForm = document.querySelector('#addModal form');
            if (addForm) {
                addForm.addEventListener('submit', function(e) {
                    const socialAccount = document.getElementById('social_account').value;
                    const otherField = document.getElementById('social_account_other');
                    if (socialAccount === 'Other' && (!otherField || !otherField.value.trim())) {
                        e.preventDefault();
                        alert('Please specify the social account name when "Other" is selected.');
                        return false;
                    }
                });
            }

            const editForm = document.querySelector('#editModal form');
            if (editForm) {
                editForm.addEventListener('submit', function(e) {
                    const socialAccount = document.getElementById('edit_social_account').value;
                    const otherField = document.getElementById('edit_social_account_other');
                    if (socialAccount === 'Other' && (!otherField || !otherField.value.trim())) {
                        e.preventDefault();
                        alert('Please specify the social account name when "Other" is selected.');
                        return false;
                    }
                });
            }
        });

        // Ensure modal/form is closed and cleared on back/restore (bfcache) and fresh loads
        window.addEventListener('pageshow', function(event) {
            try {
                closeModal();
                var form = document.getElementById('editForm');
                if (form) {
                    form.reset();
                }
            } catch (e) {}
        });
    </script>
</head>

<body>
    <div class="dashboard-container">
        <header class="dashboard-header">
            <h1>Socials Data</h1>
            <div class="user-info">
                <span>Welcome, <?php echo htmlspecialchars($_SESSION['name']); ?></span>
                <a href="../includes/logout.php" class="logout-btn">Logout</a>
            </div>
        </header>

        <div class="dashboard-content">
            <a href="dashboard_<?php echo $_SESSION['role']; ?>.php" class="back-link">&larr; Back to Dashboard</a>
            <div class="accounts-container">
                <?php if (isset($_GET['success'])): ?>
                    <div class="alert alert-success">Social account added successfully!</div>
                <?php endif; ?>
                <?php if (isset($_GET['queued'])): ?>
                    <div class="alert alert-info">Your request has been queued for approval.</div>
                <?php endif; ?>
                <?php if (isset($_GET['nochange'])): ?>
                    <div class="alert alert-info">No changes were made.</div>
                <?php endif; ?>

                <button type="button" class="btn-add" onclick="openModal()">Add New Social Account</button>

                <!-- Hidden Delete Form -->
                <form id="deleteForm" method="POST" style="display:none;">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="">
                </form>

                <!-- Filter Form -->
                <div class="filter-container" style="background: #f8f9fa; padding: 20px; border-radius: 10px; margin-bottom: 20px;">
                    <h3>Filter Social Accounts</h3>
                    <form method="GET" action="">
                        <div style="display: flex; flex-wrap: wrap; gap: 15px; align-items: end;">
                            <div class="form-group" style="flex: 1; min-width: 150px;">
                                <label for="filter_discord_email">Discord Email:</label>
                                <select id="filter_discord_email" name="filter_discord_email">
                                    <option value="">All</option>
                                    <?php foreach ($discord_emails as $email): ?>
                                        <option value="<?php echo htmlspecialchars($email); ?>" <?php echo (isset($_GET['filter_discord_email']) && $_GET['filter_discord_email'] === $email) ? 'selected' : ''; ?>><?php echo htmlspecialchars($email); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group" style="flex: 1; min-width: 150px;">
                                <label for="filter_social_account">Social Account:</label>
                                <select id="filter_social_account" name="filter_social_account">
                                    <option value="">All</option>
                                    <?php foreach ($social_account_options as $option): ?>
                                        <option value="<?php echo htmlspecialchars($option); ?>" <?php echo (isset($_GET['filter_social_account']) && $_GET['filter_social_account'] === $option) ? 'selected' : ''; ?>><?php echo htmlspecialchars($option); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group" style="flex: 1; min-width: 150px;">
                                <label for="filter_username_email">Username/Email:</label>
                                <input type="text" id="filter_username_email" name="filter_username_email" value="<?php echo isset($_GET['filter_username_email']) ? htmlspecialchars($_GET['filter_username_email']) : ''; ?>" placeholder="Partial match">
                            </div>
                            <div style="display: flex; gap: 10px;">
                                <button type="submit" style="padding: 8px 16px; background: #007bff; color: white; border: none; border-radius: 4px; cursor: pointer;">Filter</button>
                                <a href="<?php echo basename(__FILE__); ?>" style="padding: 8px 16px; background: #6c757d; color: white; text-decoration: none; border-radius: 4px;">Clear Filters</a>
                            </div>
                        </div>
                    </form>
                </div>

                <h2>Social Accounts</h2>
                <?php if ($entries): ?>
                    <div class="table-wrapper">
                        <table class="accounts-table">
                            <thead>
                                <tr>
                                    <th>S.No</th>
                                    <th>ID</th>
                                    <th>Discord Email</th>
                                    <th>Social Account</th>
                                    <th>Username/Email</th>
                                    <th>Password</th>
                                    <th>Date of Creation</th>
                                    <th>Created At</th>
                                    <th>Using</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $serial_counter = count($entries); ?>
                                <?php foreach ($entries as $entry): ?>
                                    <tr>
                                        <td><?php echo $serial_counter--; ?></td>
                                        <td><?php echo htmlspecialchars($entry['id']); ?></td>
                                        <td><?php echo htmlspecialchars($entry['discord_email']); ?></td>
                                        <td><?php echo htmlspecialchars($entry['social_account']); ?></td>
                                        <td><?php echo htmlspecialchars($entry['username_email']); ?></td>
                                        <td><?php echo htmlspecialchars($entry['password']); ?></td>
                                        <td><?php echo htmlspecialchars($entry['date_of_creation']); ?></td>
                                        <td><?php echo htmlspecialchars($entry['created_at']); ?></td>
                                        <td><?php echo $entry['is_using'] ? 'Yes' : 'No'; ?></td>
                                        <td>
                                            <button type="button" class="btn-edit"
                                                data-id="<?php echo htmlspecialchars($entry['id']); ?>"
                                                data-discord_email="<?php echo htmlspecialchars($entry['discord_email']); ?>"
                                                data-social_account="<?php echo htmlspecialchars($entry['social_account']); ?>"
                                                data-username_email="<?php echo htmlspecialchars($entry['username_email']); ?>"
                                                data-password="<?php echo htmlspecialchars($entry['password']); ?>"
                                                data-date_of_creation="<?php echo htmlspecialchars($entry['date_of_creation']); ?>"
                                                data-is_using="<?php echo htmlspecialchars($entry['is_using']); ?>">Edit</button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p>No entries found.</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Add Modal -->
        <div id="addModal" class="modal">
            <div class="modal-content">
                <span class="close" onclick="closeModal()">&times;</span>
                <h2>Add New Social Account</h2>
                <form method="POST">
                    <input type="hidden" name="action" value="create">
                    <div class="form-group">
                        <label for="discord_email">Discord Email: <span style="color: red;">*</span></label>
                        <select id="discord_email" name="discord_email" required>
                            <option value="">Select Discord Email</option>
                            <?php foreach ($discord_emails as $email): ?>
                                <option value="<?php echo htmlspecialchars($email); ?>"><?php echo htmlspecialchars($email); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="social_account">Social Account: <span style="color: red;">*</span></label>
                        <select id="social_account" name="social_account" required>
                            <option value="">Select Social Account</option>
                            <?php foreach ($social_account_options as $option): ?>
                                <option value="<?php echo htmlspecialchars($option); ?>"><?php echo htmlspecialchars($option); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group other-field" id="social_account_other_group">
                        <label for="social_account_other">If Other (Please specify): <span style="color: red;">*</span></label>
                        <input type="text" id="social_account_other" name="social_account_other" placeholder="Enter social account name">
                    </div>
                    <div class="form-group">
                        <label for="username_email">Username/Email: <span style="color: red;">*</span></label>
                        <input type="text" id="username_email" name="username_email" required>
                    </div>
                    <div class="form-group">
                        <label for="password">Password: <span style="color: red;">*</span></label>
                        <input type="text" id="password" name="password" required>
                    </div>
                    <div class="form-group">
                        <label for="date_of_creation">Date of Creation: <span style="color: red;">*</span></label>
                        <input type="date" id="date_of_creation" name="date_of_creation" required>
                    </div>
                    <div class="form-group">
                        <label for="is_using">Is Using:</label>
                        <input type="checkbox" id="is_using" name="is_using" value="1">
                    </div>
                    <button type="submit">Submit</button>
                </form>
            </div>
        </div>

        <!-- Edit Modal -->
        <div id="editModal" class="modal">
            <div class="modal-content">
                <span class="close" onclick="closeModal()">&times;</span>
                <h2>Edit Social Account</h2>
                <form method="POST" id="editForm">
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" id="edit_id" name="id">
                    <div class="form-group">
                        <label for="edit_discord_email">Discord Email: <span style="color: red;">*</span></label>
                        <select id="edit_discord_email" name="discord_email" required readonly>
                            <option value="">Select Discord Email</option>
                            <?php foreach ($discord_emails as $email): ?>
                                <option value="<?php echo htmlspecialchars($email); ?>"><?php echo htmlspecialchars($email); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="edit_social_account">Social Account: <span style="color: red;">*</span></label>
                        <select id="edit_social_account" name="social_account" required>
                            <option value="">Select Social Account</option>
                            <?php foreach ($social_account_options as $option): ?>
                                <option value="<?php echo htmlspecialchars($option); ?>"><?php echo htmlspecialchars($option); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group other-field" id="edit_social_account_other_group">
                        <label for="edit_social_account_other">If Other (Please specify): <span style="color: red;">*</span></label>
                        <input type="text" id="edit_social_account_other" name="social_account_other" placeholder="Enter social account name">
                    </div>
                    <div class="form-group">
                        <label for="edit_username_email">Username/Email: <span style="color: red;">*</span></label>
                        <input type="text" id="edit_username_email" name="username_email" required>
                    </div>
                    <div class="form-group">
                        <label for="edit_password">Password: <span style="color: red;">*</span></label>
                        <input type="text" id="edit_password" name="password" required>
                    </div>
                    <div class="form-group">
                        <label for="edit_date_of_creation">Date of Creation: <span style="color: red;">*</span></label>
                        <input type="date" id="edit_date_of_creation" name="date_of_creation" required>
                    </div>
                    <div class="form-group">
                        <label for="edit_is_using">Is Using:</label>
                        <input type="checkbox" id="edit_is_using" name="edit_is_using" value="1">
                    </div>
                    <button type="submit">Update</button>
                </form>
            </div>
        </div>
    </div>
</body>

</html>