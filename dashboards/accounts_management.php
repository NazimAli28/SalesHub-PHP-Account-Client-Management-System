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

// Check IP restriction for support (admin can access from anywhere)
// if ($_SESSION['role'] === 'support' && !is_ip_allowed()) {
//     header("Location: ../index.php?error=access_denied");
//     exit();
// }

// Ensure approvals table exists
$createApprovals = "
CREATE TABLE IF NOT EXISTS approvals (
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
)";
$conn->query($createApprovals);

// Pagination settings
$rows_per_page = isset($_GET['rows_per_page']) ? (int)$_GET['rows_per_page'] : 20;
$rows_per_page = max(1, $rows_per_page); // Ensure at least 1
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$page = max(1, $page); // Ensure page is at least 1
$offset = ($page - 1) * $rows_per_page;


// Collect filters (GET)
$filters = [
    'email' => isset($_GET['email']) ? trim($_GET['email']) : '',
    'discord_email' => isset($_GET['discord_email']) ? trim($_GET['discord_email']) : '',
    'recovery_email' => isset($_GET['recovery_email']) ? trim($_GET['recovery_email']) : '',
    'agent_name' => isset($_GET['agent_name']) ? trim($_GET['agent_name']) : '',
    'unit' => isset($_GET['unit']) ? trim($_GET['unit']) : '',
    'pc_number' => isset($_GET['pc_number']) ? trim($_GET['pc_number']) : '',
    'tl_name' => isset($_GET['tl_name']) ? trim($_GET['tl_name']) : '',
    'standings' => isset($_GET['standings']) ? trim($_GET['standings']) : '',
    'has_client' => isset($_GET['has_client']) ? $_GET['has_client'] : '', // '', '0', '1'
    'status' => isset($_GET['status']) ? $_GET['status'] : '', // '', '0', '1'
    'discord_account_date_from' => isset($_GET['discord_account_date_from']) ? trim($_GET['discord_account_date_from']) : '',
    'discord_account_date_to' => isset($_GET['discord_account_date_to']) ? trim($_GET['discord_account_date_to']) : '',
    'account_batch_from' => isset($_GET['account_batch_from']) ? trim($_GET['account_batch_from']) : '',
    'account_batch_to' => isset($_GET['account_batch_to']) ? trim($_GET['account_batch_to']) : '',
    'empty_agent_or_pc' => isset($_GET['empty_agent_or_pc']) ? $_GET['empty_agent_or_pc'] : '',
    'discord_password_filter' => isset($_GET['discord_password_filter']) ? $_GET['discord_password_filter'] : ''
];

// Build WHERE clause and bind params
$where_clauses = [];
$bind_types = '';
$bind_values = [];

if ($filters['email'] !== '') {
    $where_clauses[] = 'email LIKE ?';
    $bind_types .= 's';
    $bind_values[] = '%' . $filters['email'] . '%';
}
if ($filters['discord_email'] !== '') {
    $where_clauses[] = 'discord_email LIKE ?';
    $bind_types .= 's';
    $bind_values[] = '%' . $filters['discord_email'] . '%';
}
if ($filters['recovery_email'] !== '') {
    $where_clauses[] = 'recovery_email LIKE ?';
    $bind_types .= 's';
    $bind_values[] = '%' . $filters['recovery_email'] . '%';
}
if ($filters['agent_name'] !== '') {
    $where_clauses[] = 'agent_name LIKE ?';
    $bind_types .= 's';
    $bind_values[] = '%' . $filters['agent_name'] . '%';
}
if ($filters['unit'] !== '') {
    $where_clauses[] = 'unit LIKE ?';
    $bind_types .= 's';
    $bind_values[] = '%' . $filters['unit'] . '%';
}
if ($filters['pc_number'] !== '') {
    $where_clauses[] = 'pc_number LIKE ?';
    $bind_types .= 's';
    $bind_values[] = '%' . $filters['pc_number'] . '%';
}
if ($filters['tl_name'] !== '') {
    $where_clauses[] = 'tl_name LIKE ?';
    $bind_types .= 's';
    $bind_values[] = '%' . $filters['tl_name'] . '%';
}
if ($filters['standings'] !== '') {
    $where_clauses[] = 'standings = ?';
    $bind_types .= 's';
    $bind_values[] = $filters['standings'];
}
if ($filters['has_client'] !== '' && ($filters['has_client'] === '0' || $filters['has_client'] === '1')) {
    $where_clauses[] = 'has_client = ?';
    $bind_types .= 'i';
    $bind_values[] = (int)$filters['has_client'];
}
if ($filters['status'] !== '' && ($filters['status'] === '0' || $filters['status'] === '1')) {
    $where_clauses[] = 'status = ?';
    $bind_types .= 'i';
    $bind_values[] = (int)$filters['status'];
}
if ($filters['discord_account_date_from'] !== '') {
    $where_clauses[] = 'discord_account_date >= ?';
    $bind_types .= 's';
    $bind_values[] = $filters['discord_account_date_from'];
}
if ($filters['discord_account_date_to'] !== '') {
    $where_clauses[] = 'discord_account_date <= ?';
    $bind_types .= 's';
    $bind_values[] = $filters['discord_account_date_to'];
}
if ($filters['account_batch_from'] !== '') {
    $where_clauses[] = 'account_batch >= ?';
    $bind_types .= 's';
    $bind_values[] = $filters['account_batch_from'];
}
if ($filters['account_batch_to'] !== '') {
    $where_clauses[] = 'account_batch <= ?';
    $bind_types .= 's';
    $bind_values[] = $filters['account_batch_to'];
}

if ($filters['empty_agent_or_pc'] === '1') {
    $where_clauses[] = '(agent_name = "" OR agent_name IS NULL OR pc_number = "" OR pc_number IS NULL)';
}
if ($filters['discord_password_filter'] === 'nill') {
    $where_clauses[] = 'discord_password = ?';
    $bind_types .= 's';
    $bind_values[] = 'nill';
} elseif ($filters['discord_password_filter'] === 'not_nill') {
    $where_clauses[] = '(discord_password != ? OR discord_password IS NULL)';
    $bind_types .= 's';
    $bind_values[] = 'nill';
}

// Build base WHERE for counts (excluding standings filter)
$base_where_clauses = [];
$base_bind_types = '';
$base_bind_values = [];
foreach ($where_clauses as $i => $clause) {
    if ($clause !== 'standings = ?' && isset($bind_types[$i])) {
        $base_where_clauses[] = $clause;
        $base_bind_types .= $bind_types[$i];
        $base_bind_values[] = $bind_values[$i];
    }
}
if ($filters['empty_agent_or_pc'] === '1') {
    $base_where_clauses[] = '(agent_name = "" OR agent_name IS NULL OR pc_number = "" OR pc_number IS NULL)';
}

// Helper for WHERE SQL
$where_sql = '';
if (!empty($where_clauses)) {
    $where_sql = ' WHERE ' . implode(' AND ', $where_clauses) . ' ';
}

$base_where_sql = '';
if (!empty($base_where_clauses)) {
    $base_where_sql = ' WHERE ' . implode(' AND ', $base_where_clauses) . ' ';
}

    // Handle CSV download
    if (isset($_GET['download']) && $_GET['download'] === 'csv') {
        // Fetch all filtered accounts without pagination
        $csv_sql = "SELECT email, email_password, discord_email, discord_password, discord_account_date, discord_account_assigned_date, recovery_email, recovery_phone_number, phone_holder_name, account_batch, agent_name, unit, pc_number, tl_name, standings, status, has_client FROM accounts " . $where_sql . " ORDER BY id DESC";

        $csv_stmt = $conn->prepare($csv_sql);
        if (!empty($bind_values)) {
            $csv_bind_params = [];
            $csv_bind_params[] = &$bind_types;
            foreach ($bind_values as $k => $v) {
                $csv_bind_params[] = &$bind_values[$k];
            }
            call_user_func_array([$csv_stmt, 'bind_param'], $csv_bind_params);
        }
        $csv_stmt->execute();
        $csv_result = $csv_stmt->get_result();

        // Set headers for CSV download
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="accounts.csv"');

        // Output CSV headers
        $output = fopen('php://output', 'w');
        fputcsv($output, ['Email', 'Email Password', 'Discord Email', 'Discord Password', 'Discord Account Date', 'Discord Account Assigned Date', 'Recovery Email', 'Recovery Phone Number', 'Phone Holder Name', 'Account Batch', 'Agent Name', 'Unit', 'PC Number', 'TL Name', 'Standings', 'Status', 'Has Client']);

        // Output data
        while ($row = $csv_result->fetch_assoc()) {
            fputcsv($output, [
                $row['email'],
                $row['email_password'],
                $row['discord_email'] ?? '',
                $row['discord_password'] ?? '',
                $row['discord_account_date'] ?? '',
                $row['discord_account_assigned_date'] ?? '',
                $row['recovery_email'] ?? '',
                $row['recovery_phone_number'] ?? '',
                $row['phone_holder_name'] ?? '',
                $row['account_batch'] ?? '',
                $row['agent_name'] ?? '',
                $row['unit'] ?? '',
                $row['pc_number'] ?? '',
                $row['tl_name'] ?? '',
                $row['standings'],
                $row['status'],
                $row['has_client']
            ]);
        }

        fclose($output);
        $csv_stmt->close();
        $conn->close();
        exit();
    }


// Handle form submissions
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['bulk_upload'])) {
        // Bulk upload accounts from CSV
        $upload_messages = [];
        $success_count = 0;
        $error_count = 0;

        if (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] == UPLOAD_ERR_OK) {
            $file_tmp_path = $_FILES['csv_file']['tmp_name'];
            $file_name = $_FILES['csv_file']['name'];
            $file_size = $_FILES['csv_file']['size'];
            $file_type = $_FILES['csv_file']['type'];

            // Validate file type and size
            $allowed_types = ['text/csv', 'application/csv', 'application/vnd.ms-excel'];
            $max_size = 5 * 1024 * 1024; // 5MB

            if (!in_array($file_type, $allowed_types) && !preg_match('/\.csv$/i', $file_name)) {
                $upload_messages[] = 'Invalid file type. Please upload a CSV file.';
            } elseif ($file_size > $max_size) {
                $upload_messages[] = 'File size exceeds 5MB limit.';
            } else {
                // Parse CSV
                if (($handle = fopen($file_tmp_path, 'r')) !== false) {
                    $header = fgetcsv($handle, 1000, ',');
                    if ($header !== false && count($header) >= 16) {
                        $row_number = 1;
                        while (($data = fgetcsv($handle, 1000, ',')) !== false) {
                            $row_number++;
                            if (count($data) < 16) {
                                $upload_messages[] = "Row $row_number: Insufficient columns.";
                                $error_count++;
                                continue;
                            }

                            // Map data to fields
                            $email = trim($data[0]);
                            $email_password = trim($data[1]);
                            $discord_email = trim($data[2]);
                            $discord_password = trim($data[3]);
                            $discord_account_date = trim($data[4]);
                            $recovery_email = trim($data[5]);
                            $recovery_phone_number = trim($data[6]);
                            $phone_holder_name = trim($data[7]);
                            $account_batch = trim($data[8]);
                            $agent_name = trim($data[9]);
                            $unit = trim($data[10]);
                            $pc_number = trim($data[11]);
                            $tl_name = trim($data[12]);
                            $standings = trim($data[13]);
                            $has_client = trim($data[15]);

                            // Derive status: 1 if pc_number matches a username in users table, else 0
                            $status = 0;
                            $check_stmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
                            $check_stmt->bind_param("s", $pc_number);
                            $check_stmt->execute();
                            $check_stmt->store_result();
                            if ($check_stmt->num_rows > 0) {
                                $status = 1;
                            }
                            $check_stmt->close();

                            // Auto-set agent_name based on pc_number
                            $agent_name = '';
                            if (!empty($pc_number)) {
                                $agent_query = $conn->prepare("SELECT name FROM users WHERE username = ?");
                                $agent_query->bind_param("s", $pc_number);
                                $agent_query->execute();
                                $agent_result = $agent_query->get_result();
                                if ($agent_row = $agent_result->fetch_assoc()) {
                                    $agent_name = $agent_row['name'];
                                }
                                $agent_query->close();
                            }

                            // Validate required fields
                            if (empty($email) || empty($email_password) || empty($standings)) {
                                $upload_messages[] = "Row $row_number: Email, email password, and standings are required.";
                                $error_count++;
                                continue;
                            }

                            // Validate email formats
                            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                                $upload_messages[] = "Row $row_number: Invalid email format.";
                                $error_count++;
                                continue;
                            }
                            if (!empty($discord_email) && !filter_var($discord_email, FILTER_VALIDATE_EMAIL)) {
                                $upload_messages[] = "Row $row_number: Invalid Discord email format.";
                                $error_count++;
                                continue;
                            }
                            if (!empty($recovery_email) && !filter_var($recovery_email, FILTER_VALIDATE_EMAIL)) {
                                $upload_messages[] = "Row $row_number: Invalid recovery email format.";
                                $error_count++;
                                continue;
                            }

                            // Validate dates
                            if (!empty($discord_account_date) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $discord_account_date)) {
                                $upload_messages[] = "Row $row_number: Invalid Discord account date format (YYYY-MM-DD).";
                                $error_count++;
                                continue;
                            }
                            if (!empty($account_batch) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $account_batch)) {
                                $upload_messages[] = "Row $row_number: Invalid account batch date format (YYYY-MM-DD).";
                                $error_count++;
                                continue;
                            }

                            // Validate standings
                            $valid_standings = ['Active', 'Spam', 'Limited', 'Disabled', 'Violation'];
                            if (!in_array($standings, $valid_standings)) {
                                $upload_messages[] = "Row $row_number: Invalid standings value.";
                                $error_count++;
                                continue;
                            }

                            // Validate has_client
                            $has_client = ($has_client === '1') ? 1 : 0;

                            // Check for duplicates
                            $errors = [];
                            $check_email_stmt = $conn->prepare("SELECT id FROM accounts WHERE email = ?");
                            $check_email_stmt->bind_param("s", $email);
                            $check_email_stmt->execute();
                            $check_email_stmt->store_result();
                            if ($check_email_stmt->num_rows > 0) {
                                $errors[] = 'Email already exists.';
                            }
                            $check_email_stmt->close();

                            if (!empty($discord_email)) {
                                $check_discord_stmt = $conn->prepare("SELECT id FROM accounts WHERE discord_email = ?");
                                $check_discord_stmt->bind_param("s", $discord_email);
                                $check_discord_stmt->execute();
                                $check_discord_stmt->store_result();
                                if ($check_discord_stmt->num_rows > 0) {
                                    $errors[] = 'Discord email already exists.';
                                }
                                $check_discord_stmt->close();
                            }

                            if (!empty($errors)) {
                                $upload_messages[] = "Row $row_number: " . implode(' ', $errors);
                                $error_count++;
                                continue;
                            }

                            // Insert account
                            $insert_stmt = $conn->prepare("INSERT INTO accounts (email, email_password, discord_email, discord_password, discord_account_date, recovery_email, recovery_phone_number, phone_holder_name, account_batch, agent_name, unit, pc_number, tl_name, standings, status, has_client) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                            $insert_stmt->bind_param("ssssssssssssssii", $email, $email_password, $discord_email, $discord_password, $discord_account_date, $recovery_email, $recovery_phone_number, $phone_holder_name, $account_batch, $agent_name, $unit, $pc_number, $tl_name, $standings, $status, $has_client);

                            if ($insert_stmt->execute()) {
                                $upload_messages[] = "Row $row_number: Account added successfully.";
                                $success_count++;
                            } else {
                                $upload_messages[] = "Row $row_number: Error adding account: " . $conn->error;
                                $error_count++;
                            }
                            $insert_stmt->close();
                        }
                        fclose($handle);
                    } else {
                        $upload_messages[] = 'Invalid CSV header or insufficient columns.';
                    }
                } else {
                    $upload_messages[] = 'Error opening uploaded file.';
                }
            }
        } else {
            $upload_messages[] = 'No file uploaded or upload error.';
        }

        // Set session message
        $message = "Bulk upload completed. Successes: $success_count, Errors: $error_count. Details: " . implode(' | ', $upload_messages);
        $message_type = ($error_count > 0) ? 'error' : 'success';
        $_SESSION['message'] = $message;
        $_SESSION['message_type'] = $message_type;
        $redirect_url = "accounts_management.php";
        if (!empty($_GET)) {
            $redirect_url .= "?" . http_build_query($_GET);
        }
        header("Location: " . $redirect_url);
        exit();
    } elseif (isset($_POST['add_account'])) {
        // Add new account
        $email = trim($_POST['email']);
        $email_password = $_POST['email_password'];
        $discord_email = trim($_POST['discord_email']);
        $discord_password = $_POST['discord_password'];
        $discord_account_date = $_POST['discord_account_date'];
        $recovery_email = trim($_POST['recovery_email']);
        $recovery_phone_number = trim($_POST['recovery_phone_number']);
        $phone_holder_name = trim($_POST['phone_holder_name']);
        $account_batch = $_POST['account_batch'];
        $agent_name = trim($_POST['agent_name']);
        $unit = trim($_POST['unit']);
        $pc_number = trim($_POST['pc_number']);
        $tl_name = trim($_POST['tl_name']);
        $standings = $_POST['standings'];
        $has_client = isset($_POST['has_client']) ? 1 : 0;

        // Derive status: 1 if pc_number matches a username in users table, else 0
        $status = 0;
        $check_stmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
        $check_stmt->bind_param("s", $pc_number);
        $check_stmt->execute();
        $check_stmt->store_result();
        if ($check_stmt->num_rows > 0) {
            $status = 1;
        }
        $check_stmt->close();

        // Auto-set agent_name based on pc_number
        $agent_name = '';
        if (!empty($pc_number)) {
            $agent_query = $conn->prepare("SELECT name FROM users WHERE username = ?");
            $agent_query->bind_param("s", $pc_number);
            $agent_query->execute();
            $agent_result = $agent_query->get_result();
            if ($agent_row = $agent_result->fetch_assoc()) {
                $agent_name = $agent_row['name'];
            }
            $agent_query->close();
        }

        if (empty($email) || empty($email_password) || empty($standings)) {
            $message = 'Email, email password, and standings are required.';
            $message_type = 'error';
        } else {
            $errors = [];

            // Check if email already exists
            $check_email_stmt = $conn->prepare("SELECT id FROM accounts WHERE email = ?");
            $check_email_stmt->bind_param("s", $email);
            $check_email_stmt->execute();
            $check_email_stmt->store_result();
            if ($check_email_stmt->num_rows > 0) {
                $errors[] = 'Email already exists.';
            }
            $check_email_stmt->close();

            // Check if discord_email already exists, if provided
            if (!empty($discord_email)) {
                $check_discord_stmt = $conn->prepare("SELECT id FROM accounts WHERE discord_email = ?");
                $check_discord_stmt->bind_param("s", $discord_email);
                $check_discord_stmt->execute();
                $check_discord_stmt->store_result();
                if ($check_discord_stmt->num_rows > 0) {
                    $errors[] = 'Discord email already exists.';
                }
                $check_discord_stmt->close();
            }

            if (!empty($errors)) {
                $message = implode(' ', $errors);
                $message_type = 'error';
            } else {
                // Insert new account
                $insert_stmt = $conn->prepare("INSERT INTO accounts (email, email_password, discord_email, discord_password, discord_account_date, recovery_email, recovery_phone_number, phone_holder_name, account_batch, agent_name, unit, pc_number, tl_name, standings, status, has_client) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $insert_stmt->bind_param("ssssssssssssssii", $email, $email_password, $discord_email, $discord_password, $discord_account_date, $recovery_email, $recovery_phone_number, $phone_holder_name, $account_batch, $agent_name, $unit, $pc_number, $tl_name, $standings, $status, $has_client);

                if ($insert_stmt->execute()) {
                    $_SESSION['message'] = 'Account added successfully.';
                    $_SESSION['message_type'] = 'success';
                    $redirect_url = "accounts_management.php";
                    if (!empty($_GET)) {
                        $redirect_url .= "?" . http_build_query($_GET);
                    }
                    header("Location: " . $redirect_url);
                    exit();
                } else {
                    $message = 'Error adding account: ' . $conn->error;
                    $message_type = 'error';
                }
                $insert_stmt->close();
            }
        }
    } elseif (isset($_POST['edit_account'])) {
        // Edit existing account
        $account_id = intval($_POST['account_id']);

        if (empty($account_id)) {
            $message = 'Account ID is required.';
            $message_type = 'error';
        } else {
            // Fetch current account data
            $curStmt = $conn->prepare("SELECT * FROM accounts WHERE id = ?");
            $curStmt->bind_param("i", $account_id);
            $curStmt->execute();
            $curRes = $curStmt->get_result();
            $current = $curRes->fetch_assoc();
            $curStmt->close();

            if (!$current) {
                $message = 'Account not found.';
                $message_type = 'error';
            } else {
                // Collect and sanitize inputs
                $email = trim($_POST['email']);
                $email_password = !empty($_POST['email_password']) ? $_POST['email_password'] : null;
                $discord_email = trim($_POST['discord_email']);
                $discord_password = !empty($_POST['discord_password']) ? $_POST['discord_password'] : null;
                $discord_account_date = !empty($_POST['discord_account_date']) ? $_POST['discord_account_date'] : null;
                $recovery_email = trim($_POST['recovery_email']);
                $recovery_phone_number = trim($_POST['recovery_phone_number']);
                $phone_holder_name = trim($_POST['phone_holder_name']);
                $account_batch = !empty($_POST['account_batch']) ? $_POST['account_batch'] : null;
                $agent_name = trim($_POST['agent_name']);
                $unit = trim($_POST['unit']);
                $pc_number = trim($_POST['pc_number']);
                $tl_name = trim($_POST['tl_name']);
                $new_standings = $_POST['standings'];
                $has_client = isset($_POST['has_client']) ? 1 : 0;

                // Derive status: 1 if pc_number matches a username in users table, else 0
                $status = 0;
                if (!empty($pc_number)) {
                    $check_stmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
                    $check_stmt->bind_param("s", $pc_number);
                    $check_stmt->execute();
                    $check_stmt->store_result();
                    if ($check_stmt->num_rows > 0) {
                        $status = 1;
                    }
                    $check_stmt->close();
                }

                // Auto-set agent_name based on pc_number
                $agent_name = '';
                if (!empty($pc_number)) {
                    $agent_query = $conn->prepare("SELECT name FROM users WHERE username = ?");
                    $agent_query->bind_param("s", $pc_number);
                    $agent_query->execute();
                    $agent_result = $agent_query->get_result();
                    if ($agent_row = $agent_result->fetch_assoc()) {
                        $agent_name = $agent_row['name'];
                    }
                    $agent_query->close();
                }

                if (empty($email)) {
                    $message = 'Email is required.';
                    $message_type = 'error';
                } else {
                    // Build proposed changes (only include fields that are being updated)
                    $proposed = [
                        'email' => $email,
                        'discord_email' => $discord_email ?: '',
                        'discord_account_date' => $discord_account_date,
                        'recovery_email' => $recovery_email ?: '',
                        'recovery_phone_number' => $recovery_phone_number ?: '',
                        'phone_holder_name' => $phone_holder_name ?: '',
                        'account_batch' => $account_batch,
                        'agent_name' => $agent_name ?: '',
                        'unit' => $unit ?: '',
                        'pc_number' => $pc_number ?: '',
                        'tl_name' => $tl_name ?: '',
                        'standings' => $new_standings,
                        'has_client' => $has_client,
                        'status' => $status
                    ];

                    // Include passwords only if they're being changed (not empty)
                    if (!empty($email_password)) {
                        $proposed['email_password'] = $email_password;
                    }
                    if (!empty($discord_password)) {
                        $proposed['discord_password'] = $discord_password;
                    }

                    // Compare with current to build changes diff
                    $changes = [];
                    foreach ($proposed as $field => $value) {
                        $curVal = isset($current[$field]) ? $current[$field] : null;

                        // Handle null/empty comparisons
                        $curStr = ($curVal !== null && $curVal !== '') ? (string)$curVal : '';
                        $newStr = ($value !== null && $value !== '') ? (string)$value : '';

                        // For numeric fields (has_client, status), compare as integers
                        if ($field === 'has_client' || $field === 'status') {
                            $curNum = ($curVal !== null && $curVal !== '') ? intval($curVal) : 0;
                            $newNum = ($value !== null && $value !== '') ? intval($value) : 0;
                            if ($curNum !== $newNum) {
                                $changes[$field] = $value;
                            }
                        } else {
                            // For other fields, compare as strings
                            if ($curStr !== $newStr) {
                                $changes[$field] = $value;
                            }
                        }
                    }

                    if (empty($changes)) {
                        $_SESSION['message'] = 'No changes detected.';
                        $_SESSION['message_type'] = 'info';
                        header("Location: accounts_management.php");
                        exit();
                    }

                    // Check if user is admin or support - apply directly, else create approval ticket
                    if (in_array($_SESSION['role'], ['admin', 'support'])) {
                        // Apply changes directly
                        $fields = [];
                        $params = [];
                        $types = '';
                        $intFields = ['has_client', 'status'];

                        foreach ($changes as $field => $value) {
                            $fields[] = "`$field` = ?";
                            $params[] = $value;
                            if (in_array($field, $intFields) || is_int($value)) {
                                $types .= 'i';
                            } else {
                                $types .= 's';
                            }
                        }

                        if (!empty($fields)) {
                            $sql = "UPDATE accounts SET " . implode(", ", $fields) . " WHERE id = ?";
                            $params[] = $account_id;
                            $types .= 'i';
                            $upd = $conn->prepare($sql);
                            $upd->bind_param($types, ...$params);
                            $upd->execute();
                            $upd->close();
                        }

                        // Explicitly set discord_account_assigned_date if PC number is being assigned
                        if (empty($current['pc_number']) && !empty($pc_number)) {
                            $set_date = $conn->prepare("UPDATE accounts SET discord_account_assigned_date = ? WHERE id = ?");
                            $current_date = date('Y-m-d');
                            $set_date->bind_param("si", $current_date, $account_id);
                            $set_date->execute();
                            $set_date->close();
                        }

                        // If standings was changed, clear pending_standings
                        if (isset($changes['standings'])) {
                            $clear_pending = $conn->prepare("UPDATE accounts SET pending_standings = NULL WHERE id = ?");
                            $clear_pending->bind_param("i", $account_id);
                            $clear_pending->execute();
                            $clear_pending->close();
                        }

                        $_SESSION['message'] = 'Account updated successfully.';
                        $_SESSION['message_type'] = 'success';
                        $redirect_url = "accounts_management.php";
                        if (!empty($_GET)) {
                            $redirect_url .= "?" . http_build_query($_GET);
                        }
                        header("Location: " . $redirect_url);
                        exit();
                    } else {
                        // Create approval ticket
                        $json = json_encode($changes, JSON_UNESCAPED_UNICODE);
                        $submittedBy = isset($_SESSION['username']) ? $_SESSION['username'] : 'admin';
                        $submittedById = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : 0;

                        $ins = $conn->prepare("INSERT INTO approvals (table_name, record_id, action, changes_json, submitted_by, submitted_by_user_id) VALUES ('accounts', ?, 'update', ?, ?, ?)");
                        $ins->bind_param("issi", $account_id, $json, $submittedBy, $submittedById);
                        $ins->execute();
                        $ins->close();

                        $_SESSION['message'] = 'Account update queued for approval.';
                        $_SESSION['message_type'] = 'success';
                        header("Location: accounts_management.php?queued=1");
                        exit();
                    }
                }
            }
        }
    } elseif (isset($_POST['delete_account'])) {
        // Delete account
        $account_id = intval($_POST['account_id']);

        if (empty($account_id)) {
            $message = 'Account ID is required.';
            $message_type = 'error';
        } else {
            // Verify account exists
            $check_stmt = $conn->prepare("SELECT id FROM accounts WHERE id = ?");
            $check_stmt->bind_param("i", $account_id);
            $check_stmt->execute();
            $check_stmt->store_result();

            if ($check_stmt->num_rows == 0) {
                $message = 'Account not found.';
                $message_type = 'error';
            } else {
                // Check if user is support - delete directly, else create approval ticket
                if ($_SESSION['role'] === 'support') {
                    // Delete directly
                    $del = $conn->prepare("DELETE FROM accounts WHERE id = ?");
                    $del->bind_param("i", $account_id);
                    $del->execute();
                    $del->close();

                    $_SESSION['message'] = 'Account deleted successfully.';
                    $_SESSION['message_type'] = 'success';
                    $redirect_url = "accounts_management.php";
                    if (!empty($_GET)) {
                        $redirect_url .= "?" . http_build_query($_GET);
                    }
                    header("Location: " . $redirect_url);
                    exit();
                } else {
                    // Create approval ticket for deletion
                    $changes = ['_action' => 'delete']; // Placeholder to indicate deletion
                    $json = json_encode($changes, JSON_UNESCAPED_UNICODE);
                    $submittedBy = isset($_SESSION['username']) ? $_SESSION['username'] : 'admin';
                    $submittedById = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : 0;

                    $ins = $conn->prepare("INSERT INTO approvals (table_name, record_id, action, changes_json, submitted_by, submitted_by_user_id) VALUES ('accounts', ?, 'delete', ?, ?, ?)");
                    $ins->bind_param("issi", $account_id, $json, $submittedBy, $submittedById);
                    $ins->execute();
                    $ins->close();

                    $_SESSION['message'] = 'Account deletion queued for approval.';
                    $_SESSION['message_type'] = 'success';
                    $redirect_url = "accounts_management.php?queued=1";
                    if (!empty($_GET)) {
                        $redirect_url .= "&" . http_build_query($_GET);
                    }
                    header("Location: " . $redirect_url);
                    exit();
                }
            }
            $check_stmt->close();
        }
    }
}

// Get total number of accounts for pagination
$total_accounts_sql = "SELECT COUNT(*) as total FROM accounts " . $where_sql;
$total_accounts_stmt = $conn->prepare($total_accounts_sql);
if (!empty($bind_values)) {
    $total_bind_params = [];
    $total_bind_params[] = &$bind_types;
    foreach ($bind_values as $k => $v) {
        $total_bind_params[] = &$bind_values[$k];
    }
    call_user_func_array([$total_accounts_stmt, 'bind_param'], $total_bind_params);
}
$total_accounts_stmt->execute();
$total_accounts_result = $total_accounts_stmt->get_result();
$total_accounts = $total_accounts_result ? $total_accounts_result->fetch_assoc()['total'] : 0;
$total_accounts_stmt->close();
$total_pages = ceil($total_accounts / $rows_per_page);

// Get counts for each standings
$standings_counts = [];
$standings_list = ['Active', 'Spam', 'Limited', 'Disabled', 'Violation'];
foreach ($standings_list as $standing) {
    $count_sql = "SELECT COUNT(*) as count FROM accounts WHERE standings = ?" . ($base_where_sql ? ' AND ' . substr($base_where_sql, 7) : '');
    $count_stmt = $conn->prepare($count_sql);
    if (!$count_stmt) {
        die("Prepare failed: " . $conn->error);
    }
    $count_bind_types = 's' . $base_bind_types;
    $count_bind_values = array_merge([$standing], $base_bind_values);
    if (!empty($count_bind_values)) {
        $count_bind_params = [];
        $count_bind_params[] = &$count_bind_types;
        foreach ($count_bind_values as $k => $v) {
            $count_bind_params[] = &$count_bind_values[$k];
        }
        call_user_func_array([$count_stmt, 'bind_param'], $count_bind_params);
    }
    $count_stmt->execute();
    $count_result = $count_stmt->get_result();
    $standings_counts[$standing] = $count_result ? $count_result->fetch_assoc()['count'] : 0;
    $count_stmt->close();
}

// Fetch paginated accounts
$accounts = [];
$list_sql = "SELECT id, email, email_password, discord_email, discord_password, discord_account_date, discord_account_assigned_date, recovery_email, recovery_phone_number, phone_holder_name, account_batch, agent_name, unit, pc_number, tl_name, standings, pending_standings, status, has_client FROM accounts " . $where_sql . "ORDER BY id DESC LIMIT ? OFFSET ?";
$result = $conn->prepare($list_sql);

// Bind filters + pagination
if (!empty($bind_values)) {
    $list_bind_types = $bind_types . 'ii';
    $list_bind_values = $bind_values;
    $list_bind_values[] = $rows_per_page;
    $list_bind_values[] = $offset;

    $bind_params = [];
    $bind_params[] = &$list_bind_types;
    foreach ($list_bind_values as $k => $v) {
        $bind_params[] = &$list_bind_values[$k];
    }
    call_user_func_array([$result, 'bind_param'], $bind_params);
} else {
    $result->bind_param('ii', $rows_per_page, $offset);
}
$result->execute();
$result = $result->get_result();
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $accounts[] = $row;
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
    <title>Accounts Management - Sales Management</title>
    <link rel="stylesheet" href="../assets/style.css">
    <style>
        .accounts-management-container {
            margin: 0 auto;
            padding: 20px;
            width: 100%;
            overflow-x: scroll;
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

        .btn-add {
            background: #28a745;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            margin-bottom: 20px;
        }

        .btn-add:hover {
            background: #218838;
        }

        .form-container {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.08);
            margin-bottom: 20px;
            display: none;
        }

        .form-container.show {
            display: block;
        }

        .form-group {
            margin-bottom: 15px;
        }

        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
        }

        .form-group input,
        .form-group select {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 5px;
        }

        .form-row {
            display: flex;
            gap: 15px;
        }

        .form-row .form-group {
            flex: 1;
        }

        .form-actions {
            text-align: right;
        }

        .btn-submit {
            background: #007bff;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
        }

        .btn-submit:hover {
            background: #0056b3;
        }

        .btn-cancel {
            background: #6c757d;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            margin-left: 10px;
        }

        .btn-cancel:hover {
            background: #545b62;
        }

        .table-wrapper {
            overflow-x: auto;
        }

        .accounts-table {
            width: 100%;
            min-width: 2000px;
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

        .back-link {
            display: inline-block;
            margin-bottom: 20px;
            color: #007bff;
            text-decoration: none;
        }

        .back-link:hover {
            text-decoration: underline;
        }

        .status-active {
            color: #28a745;
            font-weight: bold;
        }

        .status-spam {
            color: #dc3545;
            font-weight: bold;
        }

        .status-limited_access {
            color: #ffc107;
            font-weight: bold;
        }

        .status-assigned {
            color: #28a745;
            font-weight: bold;
        }

        .status-not-assigned {
            color: #dc3545;
            font-weight: bold;
        }

        .form-section {
            margin-bottom: 25px;
            padding: 15px;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            background: #f8f9fa;
        }

        .form-section h4 {
            margin: 0 0 15px 0;
            color: #495057;
            font-size: 16px;
            font-weight: 600;
        }

        .required {
            color: #dc3545;
            font-weight: bold;
        }

        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .checkbox-wrapper {
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .checkbox-label {
            font-weight: normal;
            margin: 0;
        }

        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            justify-content: center;
            align-items: center;
        }

        .modal.show {
            display: flex;
        }

        .modal-content {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.3);
            max-width: 800px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
            position: relative;
        }

        .modal-close {
            position: absolute;
            top: 10px;
            right: 15px;
            font-size: 24px;
            cursor: pointer;
            color: #aaa;
        }

        .modal-close:hover {
            color: #000;
        }

        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            margin: 20px 0;
            gap: 10px;
            flex-wrap: wrap;
        }

        .pagination-link {
            padding: 8px 12px;
            text-decoration: none;
            border: 1px solid #ddd;
            color: #007bff;
            border-radius: 4px;
            transition: background-color 0.3s, color 0.3s;
        }

        .pagination-link:hover {
            background-color: #f8f9fa;
        }

        .pagination-link.active {
            background-color: #007bff;
            color: white;
            border-color: #007bff;
        }

        .pagination-dots {
            padding: 8px 12px;
            color: #666;
        }
    </style>
</head>

<body>
    <div class="dashboard-container">
        <header class="dashboard-header">
            <h1>Accounts Management</h1>
            <div class="user-info">
                <span>Welcome, <?php echo htmlspecialchars($_SESSION['name']); ?> (<?php echo htmlspecialchars($_SESSION['role']); ?>)</span>
                <a href="../includes/logout.php" class="logout-btn">Logout</a>
            </div>
        </header>

        <div class="dashboard-content">
            <div class="accounts-management-container">
                <a href="dashboard_<?php echo $_SESSION['role']; ?>.php" class="back-link">&larr; Back to Dashboard</a>

                <?php
                if (isset($_SESSION['message'])) {
                    echo '<div class="message ' . htmlspecialchars($_SESSION['message_type']) . '">' . htmlspecialchars($_SESSION['message']) . '</div>';
                    unset($_SESSION['message']);
                    unset($_SESSION['message_type']);
                }
                if ($message): ?>
                    <div class="message <?php echo $message_type; ?>">
                        <?php echo htmlspecialchars($message); ?>
                    </div>
                <?php endif; ?>

                <?php
                // Build base query string for pagination (preserve filters)
                $query_for_links = $_GET;
                unset($query_for_links['page']);
                $base_query_string = http_build_query($query_for_links);
                $base_page_prefix = $base_query_string !== '' ? ('?' . $base_query_string . '&') : '?';
                ?>

                <div class="form-container show">
                    <h3>Filter Accounts</h3>
                    <form method="get">
                        <div class="form-section">
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="filter_email">Email</label>
                                    <input type="text" id="filter_email" name="email" value="<?php echo htmlspecialchars($filters['email']); ?>" placeholder="Search email">
                                </div>
                                <div class="form-group">
                                    <label for="filter_discord_email">Discord Email</label>
                                    <input type="text" id="filter_discord_email" name="discord_email" value="<?php echo htmlspecialchars($filters['discord_email']); ?>" placeholder="Search discord email">
                                </div>
                                <div class="form-group">
                                    <label for="filter_discord_password">Discord Password Filter</label>
                                    <select id="filter_discord_password" name="discord_password_filter">
                                        <option value="">All</option>
                                        <option value="nill" <?php if ($filters['discord_password_filter'] === 'nill') echo 'selected'; ?>>Only "nill"</option>
                                        <option value="not_nill" <?php if ($filters['discord_password_filter'] === 'not_nill') echo 'selected'; ?>>All except "nill"</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label for="filter_recovery_email">Recovery Email</label>
                                    <input type="text" id="filter_recovery_email" name="recovery_email" value="<?php echo htmlspecialchars($filters['recovery_email']); ?>" placeholder="Search recovery email">
                                </div>
                            </div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="filter_agent_name">Agent Name</label>
                                    <input type="text" id="filter_agent_name" name="agent_name" value="<?php echo htmlspecialchars($filters['agent_name']); ?>" placeholder="Agent name">
                                </div>
                                <div class="form-group">
                                    <label for="filter_unit">Unit</label>
                                    <input type="text" id="filter_unit" name="unit" value="<?php echo htmlspecialchars($filters['unit']); ?>" placeholder="Unit">
                                </div>
                                <div class="form-group">
                                    <label for="filter_pc_number">PC Number</label>
                                    <input type="text" id="filter_pc_number" name="pc_number" value="<?php echo htmlspecialchars($filters['pc_number']); ?>" placeholder="PC number">
                                </div>
                                <div class="form-group">
                                    <label for="filter_tl_name">TL Name</label>
                                    <input type="text" id="filter_tl_name" name="tl_name" value="<?php echo htmlspecialchars($filters['tl_name']); ?>" placeholder="TL name">
                                </div>
                            </div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="filter_standings">Standings</label>
                                    <select id="filter_standings" name="standings">
                                        <option value="">All</option>
                                        <option value="Active" <?php if ($filters['standings'] === 'Active') echo 'selected'; ?>>Active</option>
                                        <option value="Spam" <?php if ($filters['standings'] === 'Spam') echo 'selected'; ?>>Spam</option>
                                        <option value="Limited" <?php if ($filters['standings'] === 'Limited') echo 'selected'; ?>>Limited</option>
                                        <option value="Disabled" <?php if ($filters['standings'] === 'Disabled') echo 'selected'; ?>>Disabled</option>
                                        <option value="Violation" <?php if ($filters['standings'] === 'Violation') echo 'selected'; ?>>Violation</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label for="filter_has_client">Has Client</label>
                                    <select id="filter_has_client" name="has_client">
                                        <option value="">All</option>
                                        <option value="1" <?php if ($filters['has_client'] === '1') echo 'selected'; ?>>Yes</option>
                                        <option value="0" <?php if ($filters['has_client'] === '0') echo 'selected'; ?>>No</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label for="filter_status">Status</label>
                                    <select id="filter_status" name="status">
                                        <option value="">All</option>
                                        <option value="1" <?php if ($filters['status'] === '1') echo 'selected'; ?>>Assigned</option>
                                        <option value="0" <?php if ($filters['status'] === '0') echo 'selected'; ?>>Not Assigned</option>
                                    </select>
                                </div>
                                <div class="form-group checkbox-group">
                                    <label>Show entries with empty Agent or PC Number</label>
                                    <div class="checkbox-wrapper">
                                        <input type="checkbox" name="empty_agent_or_pc" value="1" <?php if ($filters['empty_agent_or_pc'] === '1') echo 'checked'; ?>>
                                        <label class="checkbox-label">Yes</label>
                                    </div>
                                </div>
                            </div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Discord Account Date (From / To)</label>
                                    <div class="form-row">
                                        <div class="form-group">
                                            <input type="date" name="discord_account_date_from" value="<?php echo htmlspecialchars($filters['discord_account_date_from']); ?>">
                                        </div>
                                        <div class="form-group">
                                            <input type="date" name="discord_account_date_to" value="<?php echo htmlspecialchars($filters['discord_account_date_to']); ?>">
                                        </div>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label>Batch (From / To)</label>
                                    <div class="form-row">
                                        <div class="form-group">
                                            <input type="date" name="account_batch_from" value="<?php echo htmlspecialchars($filters['account_batch_from']); ?>">
                                        </div>
                                        <div class="form-group">
                                            <input type="date" name="account_batch_to" value="<?php echo htmlspecialchars($filters['account_batch_to']); ?>">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="form-actions">
                            <button type="submit" class="btn-submit">Apply Filters</button>
                            <a class="btn-cancel" href="accounts_management.php">Reset</a>
                        </div>
                    </form>
                </div>

                <!-- Standings Counts -->
                <div class="standings-counts" style="margin-bottom: 20px; padding: 15px; background: #f8f9fa; border-radius: 10px; box-shadow: 0 5px 15px rgba(0, 0, 0, 0.08);">
                    <h4>Standings Summary</h4>
                    <div style="display: flex; gap: 20px; flex-wrap: wrap;">
                        <div><strong>All:</strong> <?php echo $total_accounts; ?></div>
                        <div><strong>Active:</strong> <?php echo $standings_counts['Active']; ?></div>
                        <div><strong>Spam:</strong> <?php echo $standings_counts['Spam']; ?></div>
                        <div><strong>Limited:</strong> <?php echo $standings_counts['Limited']; ?></div>
                        <div><strong>Disabled:</strong> <?php echo $standings_counts['Disabled']; ?></div>
                        <div><strong>Violation:</strong> <?php echo $standings_counts['Violation']; ?></div>
                    </div>
                </div>

                <div style="margin-bottom: 20px;">
                    <label for="rows_per_page">Rows per page:</label>
                    <select id="rows_per_page" onchange="changeRowsPerPage(this.value)">
                        <option value="10" <?php echo $rows_per_page == 10 ? 'selected' : ''; ?>>10</option>
                        <option value="20" <?php echo $rows_per_page == 20 ? 'selected' : ''; ?>>20</option>
                        <option value="50" <?php echo $rows_per_page == 50 ? 'selected' : ''; ?>>50</option>
                        <option value="100" <?php echo $rows_per_page == 100 ? 'selected' : ''; ?>>100</option>
                    </select>
                </div>

                <button class="btn-add" onclick="toggleForm('add')">Add New Account</button>

                <button class="btn-add" onclick="toggleForm('bulk')" style="background: #17a2b8;">Bulk Upload Accounts</button>

                <a href="?<?php echo http_build_query(array_merge($_GET, ['download' => 'csv'])); ?>" class="btn-add" style="background: #28a745; text-decoration: none; display: inline-block;">Download CSV</a>



                <div id="add-form" class="form-container">
                    <h3>Add New Account</h3>
                    <form method="post">
                        <!-- Account Credentials Section -->
                        <div class="form-section">
                            <h4>Account Credentials <span class="required">*</span></h4>
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="email">Email Address:</label>
                                    <input type="email" id="email" name="email" placeholder="Enter email address" required>
                                </div>
                                <div class="form-group">
                                    <label for="email_password">Email Password:</label>
                                    <input type="password" id="email_password" name="email_password" placeholder="Enter password" required>
                                </div>
                            </div>
                        </div>

                        <!-- Discord Information Section -->
                        <div class="form-section">
                            <h4>Discord Information</h4>
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="discord_email">Discord Email:</label>
                                    <input type="email" id="discord_email" name="discord_email" placeholder="Enter Discord email (optional)">
                                </div>
                                <div class="form-group">
                                    <label for="discord_password">Discord Password:</label>
                                    <input type="password" id="discord_password" name="discord_password" placeholder="Enter Discord password (optional)">
                                </div>
                            </div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="discord_account_date">Discord Account Date:</label>
                                    <input type="date" id="discord_account_date" name="discord_account_date">
                                </div>
                                <div class="form-group">
                                    <label for="account_batch">Account Batch:</label>
                                    <input type="date" id="account_batch" name="account_batch">
                                </div>
                            </div>
                        </div>

                        <!-- Recovery Information Section -->
                        <div class="form-section">
                            <h4>Recovery Information</h4>
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="recovery_email">Recovery Email:</label>
                                    <input type="email" id="recovery_email" name="recovery_email" placeholder="Enter recovery email (optional)">
                                </div>
                                <div class="form-group">
                                    <label for="recovery_phone_number">Recovery Phone Number:</label>
                                    <input type="text" id="recovery_phone_number" name="recovery_phone_number" placeholder="Enter phone number (optional)">
                                </div>
                            </div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="phone_holder_name">Phone Holder Name:</label>
                                    <input type="text" id="phone_holder_name" name="phone_holder_name" placeholder="Enter phone holder name (optional)">
                                </div>
                            </div>
                        </div>

                        <!-- Operational Details Section -->
                        <div class="form-section">
                            <h4>Operational Details</h4>
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="agent_name">Agent Name:</label>
                                    <input type="text" id="agent_name" name="agent_name" placeholder="Enter agent name (optional)">
                                </div>
                                <div class="form-group">
                                    <label for="unit">Unit:</label>
                                    <input type="text" id="unit" name="unit" placeholder="Enter unit (optional)">
                                </div>
                            </div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="pc_number">PC Number:</label>
                                    <input type="text" id="pc_number" name="pc_number" placeholder="Enter PC number (optional)">
                                </div>
                                <div class="form-group">
                                    <label for="tl_name">TL Name:</label>
                                    <input type="text" id="tl_name" name="tl_name" placeholder="Enter TL name (optional)">
                                </div>
                            </div>
                        </div>

                        <!-- Status and Configuration Section -->
                        <div class="form-section">
                            <h4>Status and Configuration <span class="required">*</span></h4>
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="standings">Standings:</label>
                                    <select id="standings" name="standings" required>
                                        <option value="">Select Standings</option>
                                        <option value="Active">Active</option>
                                        <option value="Spam">Spam</option>
                                        <option value="Limited">Limited</option>
                                        <option value="Disabled">Disabled</option>
                                        <option value="Violation">Violation</option>
                                    </select>
                                </div>
                            </div>
                            <div class="form-row">
                                <div class="form-group checkbox-group">
                                    <label for="has_client">Has Client:</label>
                                    <div class="checkbox-wrapper">
                                        <input type="checkbox" id="has_client" name="has_client" value="1">
                                        <label for="has_client" class="checkbox-label">Yes</label>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="form-actions">
                            <button type="submit" name="add_account" class="btn-submit">Add Account</button>
                            <button type="button" class="btn-cancel" onclick="toggleForm('add')">Cancel</button>
                        </div>
                    </form>
                </div>

                <div id="bulk-form" class="form-container">
                    <h3>Bulk Upload Accounts</h3>
                    <form method="post" enctype="multipart/form-data">
                        <div class="form-group">
                            <label for="csv_file">Select CSV File:</label>
                            <input type="file" id="csv_file" name="csv_file" accept=".csv" required>
                        </div>
                        <p><strong>CSV Format:</strong> Email, Email Password, Discord Email, Discord Password, Discord Account Date (YYYY-MM-DD), Recovery Email, Recovery Phone Number, Phone Holder Name, Account Batch (YYYY-MM-DD), Agent Name, Unit, PC Number, TL Name, Standings, Status (0 or 1), Has Client (0 or 1)</p>
                        <div class="form-actions">
                            <button type="submit" name="bulk_upload" class="btn-submit">Upload and Process</button>
                            <button type="button" class="btn-cancel" onclick="toggleForm('bulk')">Cancel</button>
                        </div>
                    </form>
                </div>

                <div class="table-wrapper">
                    <table class="accounts-table">
                        <thead>
                            <tr>
                                <th>Serial No</th>
                                <th>ID</th>
                                <th>Email</th>
                                <th>Email Password</th>
                                <th>Discord Email</th>
                                <th>Discord Password</th>
                                <th>Discord Account Date</th>
                                <th>Discord Account Assigned Date</th>
                                <th>Recovery Email</th>
                                <th>Recovery Phone</th>
                                <th>Phone Holder Name</th>
                                <th>Batch</th>
                                <th>Agent Name</th>
                                <th>Unit</th>
                                <th>PC Number</th>
                                <th>TL Name</th>
                                <th>Standings</th>
                                <th>Has Client</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $serial = $total_accounts - ($page - 1) * $rows_per_page; ?>
                            <?php foreach ($accounts as $account): ?>
                                <tr>
                                    <td><?php echo $serial--; ?></td>
                                    <td><?php echo htmlspecialchars($account['id']); ?></td>
                                    <td><?php echo htmlspecialchars($account['email']); ?></td>
                                    <td><?php echo htmlspecialchars($account['email_password']); ?></td>
                                    <td><?php echo htmlspecialchars($account['discord_email'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($account['discord_password'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($account['discord_account_date'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($account['discord_account_assigned_date'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($account['recovery_email'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($account['recovery_phone_number'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($account['phone_holder_name'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($account['account_batch'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($account['agent_name'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($account['unit'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($account['pc_number'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($account['tl_name'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $account['standings']))); ?></td>
                                    <td><?php echo htmlspecialchars($account['has_client'] ? 'Yes' : 'No'); ?></td>
                                    <td class="status-<?php echo $account['status'] == 1 ? 'assigned' : 'not-assigned'; ?>"><?php echo $account['status'] == 1 ? 'Assigned' : 'Not Assigned'; ?></td>
                                    <td>
                                        <button class="btn-edit" onclick="editAccount(<?php echo $account['id']; ?>, '<?php echo htmlspecialchars($account['email']); ?>', '<?php echo htmlspecialchars($account['email_password']); ?>', '<?php echo htmlspecialchars($account['discord_email'] ?? ''); ?>', '<?php echo htmlspecialchars($account['discord_password'] ?? ''); ?>', '<?php echo htmlspecialchars($account['discord_account_date'] ?? ''); ?>', '<?php echo htmlspecialchars($account['discord_account_assigned_date'] ?? ''); ?>', '<?php echo htmlspecialchars($account['recovery_email'] ?? ''); ?>', '<?php echo htmlspecialchars($account['recovery_phone_number'] ?? ''); ?>', '<?php echo htmlspecialchars($account['phone_holder_name'] ?? ''); ?>', '<?php echo htmlspecialchars($account['account_batch'] ?? ''); ?>', '<?php echo htmlspecialchars($account['agent_name'] ?? ''); ?>', '<?php echo htmlspecialchars($account['unit'] ?? ''); ?>', '<?php echo htmlspecialchars($account['pc_number'] ?? ''); ?>', '<?php echo htmlspecialchars($account['tl_name'] ?? ''); ?>', '<?php echo htmlspecialchars($account['standings'] ?? ''); ?>', '<?php echo htmlspecialchars($account['has_client']); ?>', '<?php echo htmlspecialchars($account['status']); ?>')">Edit</button>
                                        <form method="post" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this account?')">
                                            <input type="hidden" name="account_id" value="<?php echo $account['id']; ?>">
                                            <button type="submit" name="delete_account" class="btn-delete">Delete</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($total_pages > 1): ?>
                    <div class="pagination">
                        <?php if ($page > 1): ?>
                            <a href="<?php echo $base_page_prefix; ?>page=<?php echo $page - 1; ?>" class="pagination-link">Previous</a>
                        <?php endif; ?>

                        <?php
                        $start_page = max(1, $page - 2);
                        $end_page = min($total_pages, $page + 2);
                        if ($start_page > 1): ?>
                            <a href="<?php echo $base_page_prefix; ?>page=1" class="pagination-link">1</a>
                            <?php if ($start_page > 2): ?>
                                <span class="pagination-dots">...</span>
                            <?php endif; ?>
                        <?php endif; ?>

                        <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                            <a href="<?php echo $base_page_prefix; ?>page=<?php echo $i; ?>" class="pagination-link <?php if ($i == $page) echo 'active'; ?>"><?php echo $i; ?></a>
                        <?php endfor; ?>

                        <?php if ($end_page < $total_pages): ?>
                            <?php if ($end_page < $total_pages - 1): ?>
                                <span class="pagination-dots">...</span>
                            <?php endif; ?>
                            <a href="<?php echo $base_page_prefix; ?>page=<?php echo $total_pages; ?>" class="pagination-link"><?php echo $total_pages; ?></a>
                        <?php endif; ?>

                        <?php if ($page < $total_pages): ?>
                            <a href="<?php echo $base_page_prefix; ?>page=<?php echo $page + 1; ?>" class="pagination-link">Next</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <div id="edit-modal" class="modal">
                    <div class="modal-content">
                        <span class="modal-close" onclick="closeModal('edit')">&times;</span>
                        <h3>Edit Account</h3>
                        <form method="post">
                            <input type="hidden" id="edit-account-id" name="account_id">
                            <!-- Account Credentials Section -->
                            <div class="form-section">
                                <h4>Account Credentials <span class="required">*</span></h4>
                                <div class="form-row">
                                    <div class="form-group">
                                        <label for="edit-email">Email Address:</label>
                                        <input type="email" id="edit-email" name="email" required>
                                    </div>
                                    <div class="form-group">
                                        <label for="edit-email_password">New Email Password (leave blank to keep current):</label>
                                        <input type="password" id="edit-email_password" name="email_password">
                                    </div>
                                </div>
                            </div>

                            <!-- Discord Information Section -->
                            <div class="form-section">
                                <h4>Discord Information</h4>
                                <div class="form-row">
                                    <div class="form-group">
                                        <label for="edit-discord_email">Discord Email:</label>
                                        <input type="email" id="edit-discord_email" name="discord_email">
                                    </div>
                                    <div class="form-group">
                                        <label for="edit-discord_password">Discord Password:</label>
                                        <input type="password" id="edit-discord_password" name="discord_password">
                                    </div>
                                </div>
                                <div class="form-row">
                                    <div class="form-group">
                                        <label for="edit-discord_account_date">Discord Account Date:</label>
                                        <input type="date" id="edit-discord_account_date" name="discord_account_date">
                                    </div>
                                    <div class="form-group">
                                        <label for="edit-discord_account_assigned_date">Discord Account Assigned Date:</label>
                                        <input type="date" id="edit-discord_account_assigned_date" name="discord_account_assigned_date" readonly>
                                    </div>
                                </div>
                                <div class="form-row">
                                    <div class="form-group">
                                        <label for="edit-account_batch">Account Batch:</label>
                                        <input type="date" id="edit-account_batch" name="account_batch">
                                    </div>
                                </div>
                            </div>

                            <!-- Recovery Information Section -->
                            <div class="form-section">
                                <h4>Recovery Information</h4>
                                <div class="form-row">
                                    <div class="form-group">
                                        <label for="edit-recovery_email">Recovery Email:</label>
                                        <input type="email" id="edit-recovery_email" name="recovery_email">
                                    </div>
                                    <div class="form-group">
                                        <label for="edit-recovery_phone_number">Recovery Phone Number:</label>
                                        <input type="text" id="edit-recovery_phone_number" name="recovery_phone_number">
                                    </div>
                                </div>
                                <div class="form-row">
                                    <div class="form-group">
                                        <label for="edit-phone_holder_name">Phone Holder Name:</label>
                                        <input type="text" id="edit-phone_holder_name" name="phone_holder_name">
                                    </div>
                                </div>
                            </div>

                            <!-- Operational Details Section -->
                            <div class="form-section">
                                <h4>Operational Details</h4>
                                <div class="form-row">
                                    <div class="form-group">
                                        <label for="edit-agent_name">Agent Name:</label>
                                        <input type="text" id="edit-agent_name" name="agent_name">
                                    </div>
                                    <div class="form-group">
                                        <label for="edit-unit">Unit:</label>
                                        <input type="text" id="edit-unit" name="unit">
                                    </div>
                                </div>
                                <div class="form-row">
                                    <div class="form-group">
                                        <label for="edit-pc_number">PC Number:</label>
                                        <input type="text" id="edit-pc_number" name="pc_number">
                                    </div>
                                    <div class="form-group">
                                        <label for="edit-tl_name">TL Name:</label>
                                        <input type="text" id="edit-tl_name" name="tl_name">
                                    </div>
                                </div>
                            </div>

                            <!-- Status and Configuration Section -->
                            <div class="form-section">
                                <h4>Status and Configuration</h4>
                                <div class="form-row">
                                    <div class="form-group">
                                        <label for="edit-standings">Standings:</label>
                                        <select id="edit-standings" name="standings">
                                            <option value="Active">Active</option>
                                            <option value="Spam">Spam</option>
                                            <option value="Limited">Limited</option>
                                            <option value="Disabled">Disabled</option>
                                            <option value="Violation">Violation</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="form-row">
                                    <div class="form-group checkbox-group">
                                        <label for="edit-has_client">Has Client:</label>
                                        <div class="checkbox-wrapper">
                                            <input type="checkbox" id="edit-has_client" name="has_client" value="1">
                                            <label for="edit-has_client" class="checkbox-label">Yes</label>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="form-actions">
                                <button type="submit" name="edit_account" class="btn-submit">Update Account</button>
                                <button type="button" class="btn-cancel" onclick="closeModal('edit')">Cancel</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        function toggleForm(formType) {
            const addForm = document.getElementById('add-form');
            const bulkForm = document.getElementById('bulk-form');
            if (formType === 'add') {
                addForm.classList.toggle('show');
                bulkForm.classList.remove('show');
            } else if (formType === 'bulk') {
                bulkForm.classList.toggle('show');
                addForm.classList.remove('show');
            }
        }

        function closeModal(type) {
            document.getElementById(type + '-modal').classList.remove('show');
        }

        function editAccount(id, email, emailPassword, discordEmail, discordPassword, discordAccountDate, discordAccountAssignedDate, recoveryEmail, recoveryPhone, phoneHolderName, accountBatch, agentName, unit, pcNumber, tlName, standings, has_client, status) {
            document.getElementById('edit-account-id').value = id;
            document.getElementById('edit-email').value = email;
            document.getElementById('edit-email_password').value = emailPassword;
            document.getElementById('edit-discord_email').value = discordEmail;
            document.getElementById('edit-discord_password').value = discordPassword;
            document.getElementById('edit-discord_account_date').value = discordAccountDate;
            document.getElementById('edit-discord_account_assigned_date').value = discordAccountAssignedDate;
            document.getElementById('edit-recovery_email').value = recoveryEmail;
            document.getElementById('edit-recovery_phone_number').value = recoveryPhone;
            document.getElementById('edit-phone_holder_name').value = phoneHolderName;
            document.getElementById('edit-account_batch').value = accountBatch;
            document.getElementById('edit-agent_name').value = agentName;
            document.getElementById('edit-unit').value = unit;
            document.getElementById('edit-pc_number').value = pcNumber;
            document.getElementById('edit-tl_name').value = tlName;
            document.getElementById('edit-standings').value = standings;
            document.getElementById('edit-has_client').checked = has_client == 1;
            document.getElementById('edit-modal').classList.add('show');
        }

        function changeRowsPerPage(value) {
            const url = new URL(window.location);
            url.searchParams.set('rows_per_page', value);
            url.searchParams.set('page', '1'); // Reset to first page
            window.location.href = url.toString();
        }

        // Ensure modal/form is closed and cleared on initial load and back/restore (bfcache)
        document.addEventListener('DOMContentLoaded', function() {
            try {
                closeModal('edit');
                var editModal = document.getElementById('edit-modal');
                if (editModal) {
                    var form = editModal.querySelector('form');
                    if (form) {
                        form.reset();
                    }
                }
            } catch (e) {}
        });

        window.addEventListener('pageshow', function(event) {
            try {
                closeModal('edit');
                var editModal = document.getElementById('edit-modal');
                if (editModal) {
                    var form = editModal.querySelector('form');
                    if (form) {
                        form.reset();
                    }
                }
            } catch (e) {}
        });
    </script>
</body>

</html>