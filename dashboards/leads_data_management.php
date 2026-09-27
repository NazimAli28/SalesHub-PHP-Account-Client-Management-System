<?php
session_start();

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

if (!function_exists('normalizeDateToYmd')) {
    /**
     * Normalize various date formats into Y-m-d.
     * Returns null when the provided value is empty or invalid.
     */
    function normalizeDateToYmd($value)
    {
        if (!isset($value)) {
            return null;
        }

        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }

        $normalized = str_replace(['/', '.'], '-', $value);
        $formats = [
            'Y-m-d',
            'd-m-Y',
            'm-d-Y',
            'd-m-y',
            'm-d-y',
            'Y/m/d',
            'd/m/Y',
            'm/d/Y',
            'd/m/y',
            'm/d/y'
        ];

        foreach ($formats as $format) {
            $dt = DateTime::createFromFormat($format, $normalized);
            if ($dt instanceof DateTime) {
                $errors = DateTime::getLastErrors();
                if ($errors['warning_count'] === 0 && $errors['error_count'] === 0) {
                    return $dt->format('Y-m-d');
                }
            }
        }

        $timestamp = strtotime($value);
        if ($timestamp !== false) {
            return date('Y-m-d', $timestamp);
        }

        return null;
    }
}

if (!function_exists('fetchAccountDetailsByDiscord')) {
    /**
     * Fetch sales executive name and PC number for a discord email.
     */
    function fetchAccountDetailsByDiscord($conn, $discord_email)
    {
        $details = [
            'sales_executive' => '',
            'pc_number' => ''
        ];

        if (empty($discord_email)) {
            return $details;
        }

        $stmt = $conn->prepare("SELECT agent_name, pc_number FROM accounts WHERE discord_email = ? AND status = 1 LIMIT 1");
        if (!$stmt) {
            return $details;
        }
        $stmt->bind_param("s", $discord_email);
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc()) {
                $details['sales_executive'] = $row['agent_name'] ?? '';
                $details['pc_number'] = $row['pc_number'] ?? '';
            }
        }
        $stmt->close();

        return $details;
    }
}

$discord_emails = [];
$discord_email_details = [];
// Fetch all Discord emails from all accounts along with agent metadata
$stmt = $conn->prepare("SELECT discord_email, agent_name, pc_number FROM accounts WHERE discord_email IS NOT NULL AND discord_email != ''");
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $email = $row['discord_email'];
    if (!$email) {
        continue;
    }
    $discord_emails[] = $email;
    $discord_email_details[$email] = [
        'sales_executive' => $row['agent_name'] ?? '',
        'pc_number' => $row['pc_number'] ?? ''
    ];
}
$discord_emails = array_unique($discord_emails);
$stmt->close();

// Create table if not exists
$table_sql = "CREATE TABLE IF NOT EXISTS leads_data (
    id INT AUTO_INCREMENT PRIMARY KEY,
    date DATE,
    sales_executive VARCHAR(255),
    pc_number VARCHAR(255),
    closer_name VARCHAR(255),
    discord_email VARCHAR(255),
    client_discord_username VARCHAR(255),
    client_email VARCHAR(255),
    service_items TEXT,
    follow_up_stage VARCHAR(255),
    last_message TEXT,
    scenario_if_lost TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)";
$conn->query($table_sql);
$columnCheck = $conn->query("SHOW COLUMNS FROM leads_data LIKE 'pc_number'");
if ($columnCheck && $columnCheck->num_rows === 0) {
    $conn->query("ALTER TABLE leads_data ADD COLUMN pc_number VARCHAR(255) AFTER sales_executive");
}
if ($columnCheck) {
    $columnCheck->close();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? $_POST['action'] : 'create';

    if ($action === 'bulk_upload') {
        if (!isset($_FILES['bulk_file']) || $_FILES['bulk_file']['error'] !== UPLOAD_ERR_OK) {
            die('Error: No file uploaded or upload error.');
        }

        $file = $_FILES['bulk_file']['tmp_name'];
        $fileType = mime_content_type($file);

        if ($fileType !== 'text/csv' && $fileType !== 'application/csv' && $fileType !== 'application/vnd.ms-excel' && $fileType !== 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' && $fileType !== 'text/plain' && $fileType !== 'text/x-csv' && $fileType !== 'application/x-csv' && $fileType !== 'text/comma-separated-values') {
            die('Error: Invalid file type. Please upload a CSV file.');
        }

        $handle = fopen($file, 'r');
        if (!$handle) {
            die('Error: Unable to open file.');
        }

        // Read header
        $header = fgetcsv($handle);
        if (!$header) {
            die('Error: Invalid CSV file.');
        }

        // Remove BOM if present (common in UTF-8 CSV files)
        if (isset($header[0]) && strpos($header[0], "\xEF\xBB\xBF") === 0) {
            $header[0] = substr($header[0], 3);
        }

        $expectedHeaders = ['date', 'closer_name', 'discord_email', 'client_discord_username', 'client_email', 'service_items', 'follow_up_stage', 'last_message', 'scenario_if_lost'];

        // Normalize headers (lowercase, trim) and remove trailing empties
        $header = array_map('strtolower', array_map('trim', $header));
        $header = array_filter($header, function ($h) {
            return $h !== '';
        });

        if (count($header) !== count($expectedHeaders) || array_diff($expectedHeaders, $header) !== array_diff($header, $expectedHeaders)) {
            die('Error: CSV headers do not match expected format.');
        }

        $inserted = 0;
        $errors = [];
        $rowNumber = 1;

        while (($row = fgetcsv($handle)) !== false) {
            $currentRowNumber = $rowNumber++;

            while (count($row) > 0 && end($row) === '') {
                array_pop($row);
            }

            $nonEmptyCells = array_filter($row, function ($value) {
                return trim((string)$value) !== '';
            });
            if (empty($nonEmptyCells)) {
                continue;
            }

            $expectedColumnCount = count($expectedHeaders);
            while (count($row) < $expectedColumnCount) {
                $row[] = '';
            }
            if (count($row) > $expectedColumnCount) {
                $excess = array_splice($row, $expectedColumnCount);
                $nonEmptyExcess = array_filter($excess, function ($value) {
                    return trim((string)$value) !== '';
                });
                if (!empty($nonEmptyExcess)) {
                    $errors[] = 'Row ' . $currentRowNumber . ': Incorrect number of columns.';
                    continue;
                }
            }

            $data = array_combine($expectedHeaders, $row);

            $discord_email = filter_var(trim($data['discord_email']), FILTER_SANITIZE_EMAIL);
            if (empty($discord_email)) {
                $errors[] = 'Row ' . $currentRowNumber . ': Missing discord_email.';
                continue;
            }

            $accountDetails = fetchAccountDetailsByDiscord($conn, $discord_email);
            $sales_executive = htmlspecialchars(trim($accountDetails['sales_executive'] ?? ''));
            $pc_number = htmlspecialchars(trim($accountDetails['pc_number'] ?? ''));

            $date_raw = $data['date'] ?? '';
            $date = normalizeDateToYmd($date_raw);
            if ($date === null && trim((string)$date_raw) !== '') {
                $errors[] = 'Row ' . $currentRowNumber . ': Invalid date format.';
                continue;
            }

            $closer_name = htmlspecialchars(trim($data['closer_name'] ?? ''));
            $client_discord_username = htmlspecialchars(trim($data['client_discord_username'] ?? ''));
            $client_email = filter_var(trim($data['client_email'] ?? ''), FILTER_SANITIZE_EMAIL);
            $service_items = htmlspecialchars(trim($data['service_items'] ?? ''));
            $follow_up_stage = htmlspecialchars(trim($data['follow_up_stage'] ?? ''));
            $last_message = htmlspecialchars(trim($data['last_message'] ?? ''));
            $scenario_if_lost = htmlspecialchars(trim($data['scenario_if_lost'] ?? ''));

            $stmt = $conn->prepare("INSERT INTO leads_data (date, sales_executive, pc_number, closer_name, discord_email, client_discord_username, client_email, service_items, follow_up_stage, last_message, scenario_if_lost) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("sssssssssss", $date, $sales_executive, $pc_number, $closer_name, $discord_email, $client_discord_username, $client_email, $service_items, $follow_up_stage, $last_message, $scenario_if_lost);
            if ($stmt->execute()) {
                $inserted++;
            } else {
                $errors[] = 'Row ' . $currentRowNumber . ': Database error - ' . $stmt->error;
            }
            $stmt->close();
        }

        fclose($handle);

        $message = "Bulk upload completed: $inserted records inserted.";
        if (!empty($errors)) {
            $message .= " Errors encountered: " . implode('; ', $errors);
        }
        header("Location: " . basename(__FILE__) . "?bulk_message=" . urlencode($message));
        exit();
    }

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

        if (in_array($_SESSION['role'], ['admin', 'support'])) {
            // Direct delete for admin and support roles
            $stmt = $conn->prepare("DELETE FROM leads_data WHERE id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $stmt->close();

            header("Location: " . basename(__FILE__) . "?deleted=1");
            exit();
        } else {
            // Create approval ticket instead of deleting immediately
            $changes = ['id' => $id];
            $json = json_encode($changes, JSON_UNESCAPED_UNICODE);
            $submittedBy = isset($_SESSION['username']) ? $_SESSION['username'] : 'sales_executive';
            $submittedById = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : 0;
            $ins = $conn->prepare("INSERT INTO approvals (table_name, record_id, action, changes_json, submitted_by, submitted_by_user_id) VALUES ('leads_data', ?, 'delete', ?, ?, ?)");
            $ins->bind_param("issi", $id, $json, $submittedBy, $submittedById);
            $ins->execute();
            $ins->close();

            header("Location: " . basename(__FILE__) . "?queued=1");
            exit();
        }
    }

    // Server-side validation for required fields (create/update)
    $required_fields = ['date', 'sales_executive', 'closer_name', 'discord_email', 'client_discord_username', 'follow_up_stage'];
    foreach ($required_fields as $field) {
        if (empty($_POST[$field])) {
            die("Error: Field '$field' is required.");
        }
    }

    // Validate date field
    if (empty($_POST['date']) || !DateTime::createFromFormat('Y-m-d', $_POST['date'])) {
        die("Error: Invalid date.");
    }

    // Validate service items (at least one should be selected)
    if (!isset($_POST['service_items']) || !is_array($_POST['service_items']) || count($_POST['service_items']) < 1) {
        die("Error: At least one Service Item must be selected.");
    }

    // Collect and sanitize inputs
    $date = $_POST['date'];
    $sales_executive = htmlspecialchars($_POST['sales_executive']);
    $closer_name = htmlspecialchars($_POST['closer_name']);
    $discord_email = filter_var($_POST['discord_email'], FILTER_SANITIZE_EMAIL);
    $client_discord_username = htmlspecialchars($_POST['client_discord_username']);
    $client_email = filter_var($_POST['client_email'], FILTER_SANITIZE_EMAIL);
    $service_items = implode(',', $_POST['service_items']);
    $follow_up_stage = htmlspecialchars($_POST['follow_up_stage']);
    $last_message = isset($_POST['last_message']) ? htmlspecialchars($_POST['last_message']) : '';
    $scenario_if_lost = isset($_POST['scenario_if_lost']) ? htmlspecialchars($_POST['scenario_if_lost']) : '';
    $pc_number = '';
    $accountDetails = fetchAccountDetailsByDiscord($conn, $discord_email);
    if (!empty($accountDetails['pc_number'])) {
        $pc_number = htmlspecialchars(trim($accountDetails['pc_number']));
    } elseif (isset($_POST['pc_number'])) {
        $pc_number = htmlspecialchars(trim($_POST['pc_number']));
    }
    if (empty($sales_executive) && !empty($accountDetails['sales_executive'])) {
        $sales_executive = htmlspecialchars(trim($accountDetails['sales_executive']));
    }

    if ($action === 'update') {
        if (!isset($_POST['id'])) {
            die('Error: Missing id for update.');
        }
        $id = intval($_POST['id']);

        if (in_array($_SESSION['role'], ['admin', 'support'])) {
            // Direct update for admin and support roles
            $stmt = $conn->prepare("UPDATE leads_data SET date = ?, sales_executive = ?, pc_number = ?, closer_name = ?, discord_email = ?, client_discord_username = ?, client_email = ?, service_items = ?, follow_up_stage = ?, last_message = ?, scenario_if_lost = ? WHERE id = ?");
            $stmt->bind_param("sssssssssssi", $date, $sales_executive, $pc_number, $closer_name, $discord_email, $client_discord_username, $client_email, $service_items, $follow_up_stage, $last_message, $scenario_if_lost, $id);
            $stmt->execute();
            $stmt->close();

            header("Location: " . basename(__FILE__) . "?updated=1");
            exit();
        } else {
            // Compare with existing to build changes diff
            $curStmt = $conn->prepare("SELECT * FROM leads_data WHERE id = ?");
            $curStmt->bind_param("i", $id);
            $curStmt->execute();
            $curRes = $curStmt->get_result();
            $current = $curRes->fetch_assoc();
            $curStmt->close();

            if (!$current) {
                die('Error: Record not found.');
            }

            $proposed = [
                'date' => $date,
                'sales_executive' => $sales_executive,
                'pc_number' => $pc_number,
                'closer_name' => $closer_name,
                'discord_email' => $discord_email,
                'client_discord_username' => $client_discord_username,
                'client_email' => $client_email,
                'service_items' => $service_items,
                'follow_up_stage' => $follow_up_stage,
                'last_message' => $last_message,
                'scenario_if_lost' => $scenario_if_lost
            ];

            $changes = [];
            foreach ($proposed as $field => $value) {
                $curVal = isset($current[$field]) ? (string)$current[$field] : null;
                if ($curVal !== (string)$value) {
                    $changes[$field] = $value;
                }
            }

            if (empty($changes)) {
                header("Location: " . basename(__FILE__) . "?nochange=1");
                exit();
            }

            $json = json_encode($changes, JSON_UNESCAPED_UNICODE);
            $submittedBy = isset($_SESSION['username']) ? $_SESSION['username'] : 'sales_executive';
            $submittedById = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : 0;

            $ins = $conn->prepare("INSERT INTO approvals (table_name, record_id, action, changes_json, submitted_by, submitted_by_user_id) VALUES ('leads_data', ?, 'update', ?, ?, ?)");
            $ins->bind_param("issi", $id, $json, $submittedBy, $submittedById);
            $ins->execute();
            $ins->close();

            header("Location: " . basename(__FILE__) . "?queued=1");
            exit();
        }
    }

    // Default: create
    $stmt = $conn->prepare("INSERT INTO leads_data (date, sales_executive, pc_number, closer_name, discord_email, client_discord_username, client_email, service_items, follow_up_stage, last_message, scenario_if_lost) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("sssssssssss", $date, $sales_executive, $pc_number, $closer_name, $discord_email, $client_discord_username, $client_email, $service_items, $follow_up_stage, $last_message, $scenario_if_lost);
    $stmt->execute();
    $stmt->close();

    header("Location: " . basename(__FILE__) . "?success=1");
    exit();
}

// Fetch all entries (no restriction for support role)
$sql = "SELECT * FROM leads_data ORDER BY date DESC, created_at DESC";
$stmt = $conn->prepare($sql);
$stmt->execute();
$result = $stmt->get_result();
$entries = [];
if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $entries[] = $row;
    }
}
$result->close();
$stmt->close();

// Service Items options (same as fresh_sale_options from client_retention_form)
$service_items_options = [
    'Logo/PFP',
    'Banner',
    'logo and banners',
    'Mascot Logo',
    'Avatar',
    'Animated Avatar',
    'Animated Logo',
    'Twitch Stream Pack',
    'Animated Stream Pack',
    '3D Stream pack',
    'Animated Gifs',
    'Overlay/ Screens / Banners',
    'Animated Overlay',
    'Animated Screens',
    'Animated Banner',
    'Emotes (Static)',
    'Emotes (Animated)',
    'Static Alerts',
    'Animated Alerts',
    'OC Character',
    'Sub Badges/ Stickers',
    'Pannels',
    'Stinger Transition',
    'Static Wallpaper',
    'Animated wallpaper',
    '3D Static Wallpaper',
    '3D Animated Wallpaper',
    'Static PNG Model',
    'Expression on PNG Tuber',
    '2D Character Sheet',
    '2D Half Body Vtuber Model (Static)',
    '2D Half Body Vtuber Model (Rigging)',
    '2D Full Body (Static)',
    '2D Full Body (Rigging)',
    '2D Intro',
    '2D gaming Room',
    '3D Live Vtuber Model Full Body',
    '3D Live Vtuber Model Half Body',
    '3D Scene',
    '3D Animation',
    '3D intro',
    '3D gaming Room',
    'Character Design (Static)',
    'Character Design (Animated)',
    'Animated YouTube Background for Songs',
    'Animated YouTube Intro',
    'YT Logo & Banner',
    'YT Video Editing Package',
    'YT Thumbnails',
    'YT Banner',
    'Wall Art',
    'Book Cover Art (Front Face)',
    'Poster Design (Static Design)',
    'Music Cover Art',
    'Minecraft Skin',
    'Flash Art',
    'Controller Designs',
    'Arm Sleeve Tattoo',
    'Custom business card',
    'Website',
    'Merch Design',
    'Server Managing/Server Creation',
    'Animated Mockup',
    'Proof Reading',
    'New\'s latter designs'
];

// Follow Up Stage options
$follow_up_stage_options = [
    'Shows interest in idea',
    'Shows interest in portfolio',
    'Show Interest in Payment'
];

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Leads Data Form - Sales Executive Dashboard</title>
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
            max-width: 800px;
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

        select[multiple] {
            height: 120px;
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

        .custom-multiselect {
            position: relative;
            border: 1px solid #ccc;
            border-radius: 4px;
            background: white;
        }

        .selected-items {
            display: flex;
            flex-wrap: wrap;
            min-height: 36px;
            padding: 4px;
        }

        .selected-item {
            background: #007bff;
            color: white;
            padding: 4px 8px;
            margin: 2px;
            border-radius: 4px;
            display: flex;
            align-items: center;
            font-size: 14px;
        }

        .selected-item .remove {
            margin-left: 8px;
            cursor: pointer;
            font-weight: bold;
        }

        .dropdown-toggle {
            padding: 8px;
            cursor: pointer;
            background: #f8f9fa;
            border-top: 1px solid #ccc;
            text-align: center;
        }

        .dropdown-list {
            display: none;
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: white;
            border: 1px solid #ccc;
            border-top: none;
            max-height: 200px;
            overflow-y: auto;
            z-index: 10;
        }

        .option {
            padding: 8px;
            cursor: pointer;
        }

        .option:hover {
            background: #f8f9fa;
        }

        .option.selected {
            background: #007bff;
            color: white;
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

        .alert-danger {
            color: #721c24;
            background-color: #f8d7da;
            border-color: #f5c6cb;
        }

        textarea {
            min-height: 80px;
            resize: vertical;
        }
    </style>
    <script>
        function openModal() {
            document.getElementById('addModal').style.display = 'block';
        }

        function openBulkModal() {
            document.getElementById('bulkModal').style.display = 'block';
        }

        function closeModal() {
            document.getElementById('addModal').style.display = 'none';
            document.getElementById('editModal').style.display = 'none';
            document.getElementById('bulkModal').style.display = 'none';
        }

        const discordEmailDetails = <?php echo json_encode($discord_email_details, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?> || {};

        // Close modal when clicking outside
        window.onclick = function(event) {
            if (event.target == document.getElementById('addModal')) {
                closeModal();
            }
            if (event.target == document.getElementById('editModal')) {
                closeModal();
            }
            if (event.target == document.getElementById('bulkModal')) {
                closeModal();
            }
        }

        function openEditModal(data) {
            const m = document.getElementById('editModal');
            document.getElementById('edit_id').value = data.id;
            document.getElementById('edit_date').value = data.date;
            document.getElementById('edit_sales_executive').value = data.sales_executive;
            document.getElementById('edit_pc_number').value = data.pc_number || '';
            document.getElementById('edit_closer_name').value = data.closer_name;
            document.getElementById('edit_discord_email').value = data.discord_email;
            document.getElementById('edit_client_discord_username').value = data.client_discord_username;
            document.getElementById('edit_client_email').value = data.client_email;
            // multi-select for service items
            const multi = document.getElementById('edit_service_items');
            const selected = (data.service_items || '').split(',').map(s => s.trim()).filter(Boolean);
            for (let i = 0; i < multi.options.length; i++) {
                multi.options[i].selected = selected.includes(multi.options[i].value);
            }
            if (window.customMultiSelects && window.customMultiSelects['edit_service_items'] && typeof window.customMultiSelects['edit_service_items'].refresh === 'function') {
                window.customMultiSelects['edit_service_items'].refresh();
            }
            document.getElementById('edit_follow_up_stage').value = data.follow_up_stage;
            document.getElementById('edit_last_message').value = data.last_message || '';
            document.getElementById('edit_scenario_if_lost').value = data.scenario_if_lost || '';
            m.style.display = 'block';
        }

        document.addEventListener('DOMContentLoaded', function() {
            // Edit handlers
            document.querySelectorAll('.btn-edit').forEach(btn => {
                btn.addEventListener('click', function() {
                    const d = this.dataset;
                    openEditModal({
                        id: d.id,
                        date: d.date,
                        sales_executive: d.sales_executive,
                        pc_number: d.pc_number,
                        closer_name: d.closer_name,
                        discord_email: d.discord_email,
                        client_discord_username: d.client_discord_username,
                        client_email: d.client_email,
                        service_items: d.service_items,
                        follow_up_stage: d.follow_up_stage,
                        last_message: d.last_message,
                        scenario_if_lost: d.scenario_if_lost
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

            function bindPcNumber(selectId, pcInputId) {
                const selectEl = document.getElementById(selectId);
                const pcInputEl = document.getElementById(pcInputId);
                if (!selectEl || !pcInputEl) return;
                const updatePc = () => {
                    const meta = discordEmailDetails[selectEl.value] || {};
                    pcInputEl.value = meta.pc_number || '';
                };
                selectEl.addEventListener('change', updatePc);
                updatePc();
            }

            bindPcNumber('discord_email', 'pc_number');

            // Custom Multi-Select (tag-style dropdown) for service_items
            window.customMultiSelects = window.customMultiSelects || {};

            function initCustomMultiSelect(selectEl) {
                if (!selectEl || selectEl.dataset.enhanced === '1') return null;
                selectEl.dataset.enhanced = '1';

                const wrapper = document.createElement('div');
                wrapper.className = 'custom-multiselect';

                const selectedItems = document.createElement('div');
                selectedItems.className = 'selected-items';

                const toggle = document.createElement('div');
                toggle.className = 'dropdown-toggle';
                toggle.textContent = 'Select items';

                const list = document.createElement('div');
                list.className = 'dropdown-list';

                // Build option items
                const optionEls = [];
                for (let i = 0; i < selectEl.options.length; i++) {
                    const opt = selectEl.options[i];
                    const item = document.createElement('div');
                    item.className = 'option';
                    item.textContent = opt.textContent;
                    item.dataset.value = opt.value;
                    if (opt.selected) item.classList.add('selected');
                    item.addEventListener('click', function(e) {
                        e.stopPropagation();
                        const value = this.dataset.value;
                        const currentlySelected = getSelectedValues();
                        const isSelected = currentlySelected.includes(value);
                        if (isSelected) {
                            // remove
                            setSelectedValues(currentlySelected.filter(v => v !== value));
                        } else {
                            setSelectedValues([...currentlySelected, value]);
                        }
                    });
                    list.appendChild(item);
                    optionEls.push({
                        option: opt,
                        node: item
                    });
                }

                // Helpers
                function getSelectedValues() {
                    const vals = [];
                    for (let i = 0; i < selectEl.options.length; i++) {
                        if (selectEl.options[i].selected) vals.push(selectEl.options[i].value);
                    }
                    return vals;
                }

                function setSelectedValues(values) {
                    // Sync select
                    for (let i = 0; i < selectEl.options.length; i++) {
                        selectEl.options[i].selected = values.includes(selectEl.options[i].value);
                    }
                    // Refresh UI
                    refreshUI();
                }

                function refreshUI() {
                    const values = getSelectedValues();
                    // tags
                    selectedItems.innerHTML = '';
                    values.forEach(v => {
                        const option = Array.from(selectEl.options).find(o => o.value === v);
                        if (!option) return;
                        const tag = document.createElement('div');
                        tag.className = 'selected-item';
                        tag.textContent = option.textContent;
                        const remove = document.createElement('span');
                        remove.className = 'remove';
                        remove.textContent = '×';
                        remove.title = 'Remove';
                        remove.addEventListener('click', function(e) {
                            e.stopPropagation();
                            setSelectedValues(values.filter(val => val !== v));
                        });
                        tag.appendChild(remove);
                        selectedItems.appendChild(tag);
                    });
                    // options highlight
                    optionEls.forEach(({
                        option,
                        node
                    }) => {
                        if (option.selected) node.classList.add('selected');
                        else node.classList.remove('selected');
                    });
                    // placeholder if empty
                    if (values.length === 0) {
                        const ph = document.createElement('span');
                        ph.style.color = '#6c757d';
                        ph.style.padding = '6px';
                        ph.textContent = 'Select at least one item';
                        selectedItems.appendChild(ph);
                    }
                }

                // Toggle behavior
                toggle.addEventListener('click', function(e) {
                    e.stopPropagation();
                    list.style.display = (list.style.display === 'block') ? 'none' : 'block';
                });
                document.addEventListener('click', function() {
                    list.style.display = 'none';
                });

                // Compose
                wrapper.appendChild(selectedItems);
                wrapper.appendChild(toggle);
                wrapper.appendChild(list);

                // Insert and hide original select
                selectEl.style.display = 'none';
                selectEl.parentNode.insertBefore(wrapper, selectEl.nextSibling);

                // Initial UI
                refreshUI();

                return {
                    refresh: refreshUI
                };
            }

            // Initialize on Add and Edit selects
            const addSelect = document.getElementById('service_items');
            const editSelect = document.getElementById('edit_service_items');
            if (addSelect) {
                const api = initCustomMultiSelect(addSelect);
                if (api) window.customMultiSelects['service_items'] = api;
            }
            if (editSelect) {
                const api = initCustomMultiSelect(editSelect);
                if (api) window.customMultiSelects['edit_service_items'] = api;
            }

            // Client-side validation: enforce at least one selection on submit
            function validateSelection(selectEl) {
                const count = Array.from(selectEl.options).filter(o => o.selected).length;
                return count >= 1;
            }

            const addForm = document.querySelector('#addModal form');
            if (addForm && addSelect) {
                addForm.addEventListener('submit', function(e) {
                    if (!validateSelection(addSelect)) {
                        e.preventDefault();
                        alert('Please select at least one Service Item.');
                    }
                });
            }
            const editForm = document.querySelector('#editModal form');
            if (editForm && editSelect) {
                editForm.addEventListener('submit', function(e) {
                    if (!validateSelection(editSelect)) {
                        e.preventDefault();
                        alert('Please select at least one Service Item.');
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
            <h1>Leads Data Form</h1>
            <div class="user-info">
                <span>Welcome, <?php echo htmlspecialchars($_SESSION['name']); ?></span>
                <a href="../includes/logout.php" class="logout-btn">Logout</a>
            </div>
        </header>

        <div class="dashboard-content">
            <a href="dashboard_<?php echo $_SESSION['role']; ?>.php" class="back-link">&larr; Back to Dashboard</a>
            <div class="accounts-container">
                <?php if (isset($_GET['success'])): ?>
                    <div class="alert alert-success">Leads data added successfully!</div>
                <?php endif; ?>
                <?php if (isset($_GET['updated'])): ?>
                    <div class="alert alert-success">Leads data updated successfully!</div>
                <?php endif; ?>
                <?php if (isset($_GET['deleted'])): ?>
                    <div class="alert alert-success">Leads data deleted successfully!</div>
                <?php endif; ?>

                <button type="button" class="btn-add" onclick="openModal()">Add New Leads Data</button>
                <button type="button" class="btn-add" onclick="openBulkModal()" style="background: #28a745;">Bulk Upload</button>

                <?php if (isset($_GET['bulk_message'])): ?>
                    <p style="color: green; margin-top: 10px;"><?php echo htmlspecialchars($_GET['bulk_message']); ?></p>
                <?php endif; ?>

                <!-- Hidden Delete Form -->
                <form id="deleteForm" method="POST" style="display:none;">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="">
                </form>

                <h2>Leads Data Entries</h2>
                <?php if ($entries): ?>
                    <div class="table-wrapper">
                        <table class="accounts-table">
                            <thead>
                                <tr>
                                    <th>S.No</th>
                                    <th>ID</th>
                                    <th>Date</th>
                                    <th>Sales Executive</th>
                                    <th>PC Number</th>
                                    <th>Closer Name</th>
                                    <th>Discord Email</th>
                                    <th>Client Discord Username</th>
                                    <th>Client Email</th>
                                    <th>Service Items</th>
                                    <th>Follow Up Stage</th>
                                    <th>Last Message</th>
                                    <th>Scenario If Lost</th>
                                    <th>Created At</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $serial_counter = count($entries); ?>
                                <?php foreach ($entries as $entry): ?>
                                    <tr>
                                        <td><?php echo $serial_counter--; ?></td>
                                        <td><?php echo htmlspecialchars($entry['id']); ?></td>
                                        <td><?php echo htmlspecialchars($entry['date']); ?></td>
                                        <td><?php echo htmlspecialchars($entry['sales_executive']); ?></td>
                                        <td><?php echo htmlspecialchars($entry['pc_number']); ?></td>
                                        <td><?php echo htmlspecialchars($entry['closer_name']); ?></td>
                                        <td><?php echo htmlspecialchars($entry['discord_email']); ?></td>
                                        <td><?php echo htmlspecialchars($entry['client_discord_username']); ?></td>
                                        <td><?php echo htmlspecialchars($entry['client_email']); ?></td>
                                        <td><?php echo htmlspecialchars($entry['service_items']); ?></td>
                                        <td><?php echo htmlspecialchars($entry['follow_up_stage']); ?></td>
                                        <td><?php echo htmlspecialchars($entry['last_message']); ?></td>
                                        <td><?php echo htmlspecialchars($entry['scenario_if_lost']); ?></td>
                                        <td><?php echo htmlspecialchars($entry['created_at']); ?></td>
                                        <td>
                                            <button type="button" class="btn-edit"
                                                data-id="<?php echo htmlspecialchars($entry['id']); ?>"
                                                data-date="<?php echo htmlspecialchars($entry['date']); ?>"
                                                data-sales_executive="<?php echo htmlspecialchars($entry['sales_executive']); ?>"
                                                data-pc_number="<?php echo htmlspecialchars($entry['pc_number']); ?>"
                                                data-closer_name="<?php echo htmlspecialchars($entry['closer_name']); ?>"
                                                data-discord_email="<?php echo htmlspecialchars($entry['discord_email']); ?>"
                                                data-client_discord_username="<?php echo htmlspecialchars($entry['client_discord_username']); ?>"
                                                data-client_email="<?php echo htmlspecialchars($entry['client_email']); ?>"
                                                data-service_items="<?php echo htmlspecialchars($entry['service_items']); ?>"
                                                data-follow_up_stage="<?php echo htmlspecialchars($entry['follow_up_stage']); ?>"
                                                data-last_message="<?php echo htmlspecialchars($entry['last_message']); ?>"
                                                data-scenario_if_lost="<?php echo htmlspecialchars($entry['scenario_if_lost']); ?>">Edit</button>
                                            <button type="button" class="btn-delete" data-id="<?php echo htmlspecialchars($entry['id']); ?>">Delete</button>
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
                <h2>Add New Leads Data</h2>
                <form method="POST">
                    <input type="hidden" name="action" value="create">
                    <div class="form-group">
                        <label for="date">Date: <span style="color: red;">*</span></label>
                        <input type="date" id="date" name="date" required>
                    </div>
                    <div class="form-group">
                        <label for="sales_executive">Sales Executive: <span style="color: red;">*</span></label>
                        <input type="text" id="sales_executive" name="sales_executive" value="<?php echo isset($_SESSION['name']) ? htmlspecialchars($_SESSION['name']) : ''; ?>" required readonly>
                    </div>
                    <div class="form-group">
                        <label for="pc_number">PC Number:</label>
                        <input type="text" id="pc_number" name="pc_number" placeholder="Auto-filled via Discord email" readonly>
                    </div>
                    <div class="form-group">
                        <label for="closer_name">Closer Name: <span style="color: red;">*</span></label>
                        <input type="text" id="closer_name" name="closer_name" required>
                    </div>
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
                        <label for="client_discord_username">Client Discord Username: <span style="color: red;">*</span></label>
                        <input type="text" id="client_discord_username" name="client_discord_username" required>
                    </div>
                    <div class="form-group">
                        <label for="client_email">Client's Email:</label>
                        <input type="email" id="client_email" name="client_email">
                    </div>
                    <div class="form-group">
                        <label for="service_items">Service Items: <span style="color: red;">*</span></label>
                        <select id="service_items" name="service_items[]" multiple required>
                            <?php foreach ($service_items_options as $option): ?>
                                <option value="<?php echo htmlspecialchars($option); ?>"><?php echo htmlspecialchars($option); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small>Select at least one service item</small>
                    </div>
                    <div class="form-group">
                        <label for="follow_up_stage">Follow Up Stage: <span style="color: red;">*</span></label>
                        <select id="follow_up_stage" name="follow_up_stage" required>
                            <option value="">Select Follow Up Stage</option>
                            <?php foreach ($follow_up_stage_options as $option): ?>
                                <option value="<?php echo htmlspecialchars($option); ?>"><?php echo htmlspecialchars($option); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="last_message">Last Message:</label>
                        <textarea id="last_message" name="last_message"></textarea>
                    </div>
                    <div class="form-group">
                        <label for="scenario_if_lost">Scenario If Lost:</label>
                        <textarea id="scenario_if_lost" name="scenario_if_lost"></textarea>
                    </div>
                    <button type="submit">Submit</button>
                </form>
            </div>
        </div>

        <!-- Edit Modal -->
        <div id="editModal" class="modal">
            <div class="modal-content">
                <span class="close" onclick="closeModal()">&times;</span>
                <h2>Edit Leads Data</h2>
                <form method="POST">
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" id="edit_id" name="id">
                    <div class="form-group">
                        <label for="edit_date">Date: <span style="color: red;">*</span></label>
                        <input type="date" id="edit_date" name="date" required>
                    </div>
                    <div class="form-group">
                        <label for="edit_sales_executive">Sales Executive: <span style="color: red;">*</span></label>
                        <input type="text" id="edit_sales_executive" name="sales_executive" required readonly>
                    </div>
                    <div class="form-group">
                        <label for="edit_pc_number">PC Number:</label>
                        <input type="text" id="edit_pc_number" name="pc_number" readonly>
                    </div>
                    <div class="form-group">
                        <label for="edit_closer_name">Closer Name: <span style="color: red;">*</span></label>
                        <input type="text" id="edit_closer_name" name="closer_name" required>
                    </div>
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
                        <label for="edit_client_discord_username">Client Discord Username: <span style="color: red;">*</span></label>
                        <input type="text" id="edit_client_discord_username" name="client_discord_username" required>
                    </div>
                    <div class="form-group">
                        <label for="edit_client_email">Client's Email:</label>
                        <input type="email" id="edit_client_email" name="client_email">
                    </div>
                    <div class="form-group">
                        <label for="edit_service_items">Service Items: <span style="color: red;">*</span></label>
                        <select id="edit_service_items" name="service_items[]" multiple required>
                            <?php foreach ($service_items_options as $option): ?>
                                <option value="<?php echo htmlspecialchars($option); ?>"><?php echo htmlspecialchars($option); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small>Select at least one service item</small>
                    </div>
                    <div class="form-group">
                        <label for="edit_follow_up_stage">Follow Up Stage: <span style="color: red;">*</span></label>
                        <select id="edit_follow_up_stage" name="follow_up_stage" required>
                            <option value="">Select Follow Up Stage</option>
                            <?php foreach ($follow_up_stage_options as $option): ?>
                                <option value="<?php echo htmlspecialchars($option); ?>"><?php echo htmlspecialchars($option); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="edit_last_message">Last Message:</label>
                        <textarea id="edit_last_message" name="last_message"></textarea>
                    </div>
                    <div class="form-group">
                        <label for="edit_scenario_if_lost">Scenario If Lost:</label>
                        <textarea id="edit_scenario_if_lost" name="scenario_if_lost"></textarea>
                    </div>
                    <button type="submit">Update</button>
                </form>
            </div>
        </div>

        <!-- Bulk Upload Modal -->
        <div id="bulkModal" class="modal">
            <div class="modal-content">
                <span class="close" onclick="closeModal()">&times;</span>
                <h2>Bulk Upload Leads Data</h2>
                <p>Upload a CSV file with the following headers (keep the order exactly): date, closer_name, discord_email, client_discord_username, client_email, service_items, follow_up_stage, last_message, scenario_if_lost.</p>
                <p style="font-size: 14px; color: #555;">Sales Executive and PC Number will be auto-fetched from the Accounts list using the Discord Email, so only the Discord Email column is mandatory.</p>
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="bulk_upload">
                    <div class="form-group">
                        <label for="bulk_file">Select CSV File: <span style="color: red;">*</span></label>
                        <input type="file" id="bulk_file" name="bulk_file" accept=".csv" required>
                    </div>
                    <button type="submit">Upload</button>
                </form>
            </div>
        </div>
    </div>
</body>

</html>