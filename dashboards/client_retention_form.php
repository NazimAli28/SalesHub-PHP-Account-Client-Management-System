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

if (!function_exists('sanitizeUpsaleOrderNumbers')) {
    function sanitizeUpsaleOrderNumbers($value)
    {
        if (!isset($value)) {
            return '';
        }
        if (is_array($value)) {
            $value = implode(',', $value);
        }
        $parts = preg_split('/[,\s]+/', (string)$value);
        $clean = [];
        foreach ($parts as $part) {
            $digits = preg_replace('/\D+/', '', $part);
            if ($digits !== '') {
                $clean[] = $digits;
            }
        }
        $clean = array_values(array_unique($clean));
        return implode(',', $clean);
    }
}

if (!function_exists('formatUpsaleOrderNumbers')) {
    function formatUpsaleOrderNumbers($value)
    {
        if ($value === null) {
            return '';
        }
        $value = trim((string)$value);
        if ($value === '') {
            return '';
        }
        $parts = array_filter(array_map('trim', explode(',', $value)));
        return implode(', ', $parts);
    }
}

if (!function_exists('extractFirstUpsaleOrderNumber')) {
    function extractFirstUpsaleOrderNumber($value)
    {
        $sanitized = sanitizeUpsaleOrderNumbers($value);
        if ($sanitized === '') {
            return '';
        }
        $parts = explode(',', $sanitized);
        return $parts[0] ?? '';
    }
}

if (!function_exists('sanitizeFirstSalePriceText')) {
    function sanitizeFirstSalePriceText($value)
    {
        if (!isset($value)) {
            return '';
        }
        $normalized = str_replace(["\r\n", "\r"], "\n", (string)$value);
        return trim($normalized);
    }
}

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

// Sync pc_number and sales_executive for existing records on account reassignment
foreach ($discord_emails as $email) {
    // Get current pc_number and agent name for this discord_email
    $sync_stmt = $conn->prepare("SELECT pc_number FROM accounts WHERE discord_email = ? AND status = 1");
    $sync_stmt->bind_param("s", $email);
    $sync_stmt->execute();
    $sync_res = $sync_stmt->get_result();
    $current_pc = null;
    if ($sync_row = $sync_res->fetch_assoc()) {
        $current_pc = $sync_row['pc_number'];
    }
    $sync_stmt->close();

    if ($current_pc) {
        // Get agent name from users table
        $name_stmt = $conn->prepare("SELECT name FROM users WHERE pc_number = ? OR username = ?");
        $name_stmt->bind_param("ss", $current_pc, $current_pc);
        $name_stmt->execute();
        $name_res = $name_stmt->get_result();
        $current_name = null;
        if ($name_row = $name_res->fetch_assoc()) {
            $current_name = $name_row['name'];
        }
        $name_stmt->close();

        if ($current_name) {
            // Update records where discord_email matches and pc_number or sales_executive differs
            $update_stmt = $conn->prepare("UPDATE client_retention SET pc_number = ?, sales_executive = ? WHERE discord_email = ? AND (pc_number != ? OR sales_executive != ?)");
            $update_stmt->bind_param("sssss", $current_pc, $current_name, $email, $current_pc, $current_name);
            $update_stmt->execute();
            $update_stmt->close();
        }
    }
}

// Create table if not exists
$table_sql = "CREATE TABLE IF NOT EXISTS client_retention (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_number INT,
    sales_executive VARCHAR(255),
    discord_email VARCHAR(255),
    upsale_order_number INT,
    pc_number VARCHAR(255),
    fresh_sale_item VARCHAR(255),
    first_sale_price TEXT,
    next_payment_date DATE,
    closed_by VARCHAR(255),
    team VARCHAR(255),
    nurturing_sale VARCHAR(255),
    plan_upcoming_sales TEXT,
    nurturing_rating DECIMAL(5,2),
    items_left_upsale TEXT,
    client_discord_username VARCHAR(255),
    client_name_payment VARCHAR(255),
    expected_next_upsale_date DATE,
    if_client_lost TEXT,
    comments TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)";
$conn->query($table_sql);
$conn->query("ALTER TABLE client_retention MODIFY upsale_order_number VARCHAR(255) NULL");
$conn->query("ALTER TABLE client_retention MODIFY first_sale_price TEXT NULL");

// Handle AJAX fetch for pc_number
if (isset($_GET['fetch_pc']) && isset($_GET['discord_email'])) {
    $discord_email = filter_var($_GET['discord_email'], FILTER_SANITIZE_EMAIL);
    $pc_number = '';
    if (!empty($discord_email)) {
        $stmt = $conn->prepare("SELECT pc_number FROM accounts WHERE discord_email = ? AND status = 1 LIMIT 1");
        $stmt->bind_param("s", $discord_email);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $pc_number = $row['pc_number'];
        }
        $stmt->close();
    }
    header('Content-Type: application/json');
    echo json_encode(['pc_number' => $pc_number]);
    $conn->close();
    exit();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? $_POST['action'] : 'create';
    $isAjax = isset($_POST['ajax']);

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
        $ins = $conn->prepare("INSERT INTO approvals (table_name, record_id, action, changes_json, submitted_by, submitted_by_user_id) VALUES ('client_retention', ?, 'delete', ?, ?, ?)");
        $ins->bind_param("issi", $id, $json, $submittedBy, $submittedById);
        $ins->execute();
        $ins->close();

        header("Location: " . basename(__FILE__) . "?queued=1");
        exit();
    }

    // Server-side validation for required fields (create/update)
    $required_fields = ['order_number', 'sales_executive', 'discord_email', 'pc_number', 'fresh_sale_item', 'first_sale_price', 'next_payment_date', 'closed_by', 'team', 'nurturing_sale', 'client_discord_username', 'client_name_payment', 'expected_next_upsale_date', 'comments'];
    foreach ($required_fields as $field) {
        if (empty($_POST[$field])) {
            echo json_encode(['error' => "Error: Field '$field' is required."]);
            exit();
        }
    }
    // Validate date fields
    $date_fields = ['next_payment_date', 'expected_next_upsale_date'];
    foreach ($date_fields as $field) {
        if (empty($_POST[$field]) || $_POST[$field] === '0000-00-00' || !DateTime::createFromFormat('Y-m-d', $_POST[$field])) {
            echo json_encode(['error' => "Error: Invalid date for '$field'."]);
            exit();
        }
    }
    if (!isset($_POST['plan_upcoming_sales']) || !is_array($_POST['plan_upcoming_sales']) || count($_POST['plan_upcoming_sales']) < 1 || count($_POST['plan_upcoming_sales']) > 4) {
        echo json_encode(['error' => "Error: Field 'plan_upcoming_sales' must have between 1 and 4 selections."]);
        exit();
    }
    if (!isset($_POST['nurturing_rating']) || $_POST['nurturing_rating'] === '') {
        echo json_encode(['error' => "Error: Field 'nurturing_rating' is required."]);
        exit();
    }

    // Collect and sanitize inputs
    $order_number = intval($_POST['order_number']);
    $sales_executive = htmlspecialchars($_POST['sales_executive']);
    $discord_email = filter_var($_POST['discord_email'], FILTER_SANITIZE_EMAIL);
    $upsale_order_number = sanitizeUpsaleOrderNumbers($_POST['upsale_order_number']);
    $pc_number = htmlspecialchars($_POST['pc_number']);
    $fresh_sale_item = htmlspecialchars($_POST['fresh_sale_item']);
    $first_sale_price = sanitizeFirstSalePriceText($_POST['first_sale_price']);
    $next_payment_date = !empty($_POST['next_payment_date']) ? $_POST['next_payment_date'] : null;
    $closed_by = htmlspecialchars($_POST['closed_by']);
    $team = htmlspecialchars($_POST['team']);
    $nurturing_sale = htmlspecialchars($_POST['nurturing_sale']);
    $plan_upcoming_sales = implode(',', $_POST['plan_upcoming_sales']);
    $nurturing_rating = floatval($_POST['nurturing_rating']);
    $items_left_upsale = isset($_POST['items_left_upsale']) ? htmlspecialchars($_POST['items_left_upsale']) : '';
    $client_discord_username = htmlspecialchars($_POST['client_discord_username']);
    $client_name_payment = htmlspecialchars($_POST['client_name_payment']);
    $expected_next_upsale_date = !empty($_POST['expected_next_upsale_date']) ? $_POST['expected_next_upsale_date'] : null;
    $if_client_lost = isset($_POST['if_client_lost']) ? htmlspecialchars($_POST['if_client_lost']) : '';
    $comments = htmlspecialchars($_POST['comments']);

    // Validate upsale order numbers are unique across all entries
    if (!empty($upsale_order_number)) {
        $new_parts = array_filter(array_map('trim', explode(',', $upsale_order_number)));
        $added_parts = $new_parts; // default for create

        if ($action === 'update') {
            $id = intval($_POST['id']);
            // Get current upsale_order_number
            $curStmt = $conn->prepare("SELECT upsale_order_number FROM client_retention WHERE id = ?");
            $curStmt->bind_param("i", $id);
            $curStmt->execute();
            $curRes = $curStmt->get_result();
            $current_upsale = '';
            if ($curRow = $curRes->fetch_assoc()) {
                $current_upsale = $curRow['upsale_order_number'];
            }
            $curStmt->close();
            $old_parts = array_filter(array_map('trim', explode(',', $current_upsale)));
            $added_parts = array_diff($new_parts, $old_parts);
        }

        foreach ($added_parts as $num) {
            if ($num !== '') {
                // $query = "SELECT id FROM client_retention WHERE (FIND_IN_SET(?, upsale_order_number) > 0 OR order_number = ?)";
                // $params = [$num, intval($num)];
                // $types = "si";
                $query = "SELECT id FROM client_retention WHERE (FIND_IN_SET(?, upsale_order_number) > 0 OR CAST(order_number AS CHAR) = ?)";
                $params = [$num, $num];
                $types = "ss";
                if ($action === 'update') {
                    $query .= " AND id != ?";
                    $params[] = intval($_POST['id']);
                    $types .= "i";
                }
                $stmt = $conn->prepare($query);
                $stmt->bind_param($types, ...$params);
                $stmt->execute();
                $result = $stmt->get_result();
                if ($result->num_rows > 0) {
                    echo json_encode(['error' => "Error: Upsale order number '$num' is already used in another entry (either as upsale or order number)."]);
                    exit();
                }
                $stmt->close();
            }
        }
    }

    if ($action === 'update') {
        if (!isset($_POST['id'])) {
            if ($isAjax) {
                echo json_encode(['error' => 'Error: Missing id for update.']);
                exit();
            } else {
                die('Error: Missing id for update.');
            }
        }
        $id = intval($_POST['id']);

        // Compare with existing to build changes diff
        $curStmt = $conn->prepare("SELECT * FROM client_retention WHERE id = ?");
        $curStmt->bind_param("i", $id);
        $curStmt->execute();
        $curRes = $curStmt->get_result();
        $current = $curRes->fetch_assoc();
        $curStmt->close();

        if (!$current) {
            if ($isAjax) {
                echo json_encode(['error' => 'Error: Record not found.']);
                exit();
            } else {
                die('Error: Record not found.');
            }
        }

        $proposed = [
            'order_number' => $order_number,
            'sales_executive' => $sales_executive,
            'discord_email' => $discord_email,
            'upsale_order_number' => $upsale_order_number,
            'pc_number' => $pc_number,
            'fresh_sale_item' => $fresh_sale_item,
            'first_sale_price' => $first_sale_price,
            'next_payment_date' => $next_payment_date,
            'closed_by' => $closed_by,
            'team' => $team,
            'nurturing_sale' => $nurturing_sale,
            'plan_upcoming_sales' => $plan_upcoming_sales,
            'nurturing_rating' => $nurturing_rating,
            'items_left_upsale' => $items_left_upsale,
            'client_discord_username' => $client_discord_username,
            'client_name_payment' => $client_name_payment,
            'expected_next_upsale_date' => $expected_next_upsale_date,
            'if_client_lost' => $if_client_lost,
            'comments' => $comments
        ];

        // Define numeric fields that should be compared as numbers
        $numeric_fields = ['order_number', 'nurturing_rating'];

        $changes = [];
        foreach ($proposed as $field => $value) {
            $curVal = isset($current[$field]) ? $current[$field] : null;

            // For numeric fields, compare as numbers
            if (in_array($field, $numeric_fields)) {
                $curNum = ($curVal !== null && $curVal !== '') ? floatval($curVal) : null;
                $newNum = ($value !== null && $value !== '') ? floatval($value) : null;

                // Handle null comparisons
                if ($curNum === null && $newNum === null) {
                    // Both null, no change
                    continue;
                } elseif ($curNum === null || $newNum === null) {
                    // One is null, other isn't - there's a change
                    $changes[$field] = $value;
                } else {
                    // Both have values, compare with small epsilon to handle floating point precision
                    if (abs($curNum - $newNum) > 0.001) {
                        $changes[$field] = $value;
                    }
                }
            } else {
                // For non-numeric fields, compare as strings (handle nulls)
                $curStr = ($curVal !== null && $curVal !== '') ? (string)$curVal : '';
                $newStr = ($value !== null && $value !== '') ? (string)$value : '';
                if ($curStr !== $newStr) {
                    $changes[$field] = $value;
                }
            }
        }

        if (empty($changes)) {
            if ($isAjax) {
                echo json_encode(['error' => 'No changes detected.']);
                exit();
            } else {
                header("Location: " . basename(__FILE__) . "?nochange=1");
                exit();
            }
        }

        $json = json_encode($changes, JSON_UNESCAPED_UNICODE);
        $submittedBy = isset($_SESSION['username']) ? $_SESSION['username'] : 'sales_executive';
        $submittedById = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : 0;

        $ins = $conn->prepare("INSERT INTO approvals (table_name, record_id, action, changes_json, submitted_by, submitted_by_user_id) VALUES ('client_retention', ?, 'update', ?, ?, ?)");
        $ins->bind_param("issi", $id, $json, $submittedBy, $submittedById);
        if (!$ins->execute()) {
            if ($isAjax) {
                echo json_encode(['error' => 'Failed to queue update for approval.']);
                exit();
            } else {
                die('Failed to queue update for approval.');
            }
        }
        $ins->close();

        if ($isAjax) {
            echo json_encode(['success' => true, 'message' => 'Update queued for approval.']);
            $conn->close();
            exit();
        } else {
            header("Location: " . basename(__FILE__) . "?queued=1");
            exit();
        }
    }

    // Default: create
    $stmt = $conn->prepare("INSERT INTO client_retention (order_number, sales_executive, discord_email, upsale_order_number, pc_number, fresh_sale_item, first_sale_price, next_payment_date, closed_by, team, nurturing_sale, plan_upcoming_sales, nurturing_rating, items_left_upsale, client_discord_username, client_name_payment, expected_next_upsale_date, if_client_lost, comments) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("issssssssssssssssss", $order_number, $sales_executive, $discord_email, $upsale_order_number, $pc_number, $fresh_sale_item, $first_sale_price, $next_payment_date, $closed_by, $team, $nurturing_sale, $plan_upcoming_sales, $nurturing_rating, $items_left_upsale, $client_discord_username, $client_name_payment, $expected_next_upsale_date, $if_client_lost, $comments);
    $stmt->execute();
    $stmt->close();

    if ($isAjax) {
        echo json_encode(['success' => true, 'redirect' => basename(__FILE__) . "?success=1"]);
        $conn->close();
        exit();
    } else {
        header("Location: " . basename(__FILE__) . "?success=1");
        exit();
    }
}

// Fetch all entries owned by the agent's discord_emails with optional filters
if (!empty($discord_emails)) {
    $placeholders = str_repeat('?,', count($discord_emails) - 1) . '?';
    $sql = "SELECT * FROM client_retention WHERE discord_email IN ($placeholders)";
    $params = $discord_emails;
    $types = str_repeat('s', count($discord_emails));

    // Add filters
    if (isset($_GET['filter_order_number']) && !empty($_GET['filter_order_number'])) {
        $sql .= " AND order_number = ?";
        $params[] = intval($_GET['filter_order_number']);
        $types .= 'i';
    }
    if (isset($_GET['filter_discord_email']) && !empty($_GET['filter_discord_email'])) {
        $sql .= " AND discord_email LIKE ?";
        $params[] = '%' . $conn->real_escape_string($_GET['filter_discord_email']) . '%';
        $types .= 's';
    }
    if (isset($_GET['filter_upsale_order_number']) && !empty($_GET['filter_upsale_order_number'])) {
        $needle = extractFirstUpsaleOrderNumber($_GET['filter_upsale_order_number']);
        if ($needle !== '') {
            $sql .= " AND FIND_IN_SET(?, upsale_order_number)";
            $params[] = $needle;
            $types .= 's';
        }
    }
    if (isset($_GET['filter_fresh_sale_item']) && !empty($_GET['filter_fresh_sale_item'])) {
        $sql .= " AND fresh_sale_item LIKE ?";
        $params[] = '%' . $conn->real_escape_string($_GET['filter_fresh_sale_item']) . '%';
        $types .= 's';
    }
    if (isset($_GET['filter_next_payment_date']) && !empty($_GET['filter_next_payment_date'])) {
        $sql .= " AND next_payment_date = ?";
        $params[] = $_GET['filter_next_payment_date'];
        $types .= 's';
    }
    if (isset($_GET['filter_client_discord_username']) && !empty($_GET['filter_client_discord_username'])) {
        $sql .= " AND client_discord_username LIKE ?";
        $params[] = '%' . $conn->real_escape_string($_GET['filter_client_discord_username']) . '%';
        $types .= 's';
    }
    if (isset($_GET['filter_client_name_payment']) && !empty($_GET['filter_client_name_payment'])) {
        $sql .= " AND client_name_payment LIKE ?";
        $params[] = '%' . $conn->real_escape_string($_GET['filter_client_name_payment']) . '%';
        $types .= 's';
    }
    if (isset($_GET['filter_expected_next_upsale_date']) && !empty($_GET['filter_expected_next_upsale_date'])) {
        $sql .= " AND expected_next_upsale_date = ?";
        $params[] = $_GET['filter_expected_next_upsale_date'];
        $types .= 's';
    }

    $sql .= " ORDER BY
            CASE WHEN next_payment_date IS NULL THEN 1 ELSE 0 END ASC,
            ABS(DATEDIFF(next_payment_date, CURDATE())) ASC,
            (next_payment_date <= CURDATE()) DESC,
            next_payment_date ASC,
            created_at DESC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
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
} else {
    $entries = [];
}
$conn->close();

// Fresh Sale Item options
$fresh_sale_options = [
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

// Team options
$team_options = [
    'Not Assigned',
    'Suspended',
    'Supervisors',
    'Unit 1 Alpha (Floor 3 Evening)',
    'Unit 2 Bravo (Floor 3 Evening)',
    'Unit 1 Charlie (Floor 2 Night)',
    'Unit 2 Delta (Floor 2 Night)',
    'Unit 4 Echo (Floor 2 Night)',
    'Unit 1 Foxtrot (Floor 1 Night)',
    'Unit 2 Foxtrot (Floor 1 Night)',
    'Unit 3 Golf (Floor 1 Night)',
    'Unit 4 Hotel (Floor 1 Night)',
    'Unit 5 India (Floor 1 Night)',
    'Unit 6 Juliet (Floor 4 Night)',
    'Unit 7 Juliet (Floor 4 Night)'
];

// Plan upcoming sales options (same as fresh sale)
$plan_options = $fresh_sale_options;
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Client Retention Form - Sales Executive Dashboard</title>
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
            width: 200%;
            border-collapse: collapse;
            background: white;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.08);
            table-layout: fixed;
        }

        /* S.No */
        .accounts-table th:nth-child(1),
        .accounts-table td:nth-child(1) {
            width: 4%;
        }

        /* ID */
        .accounts-table th:nth-child(2),
        .accounts-table td:nth-child(2) {
            width: 4%;
        }

        /* Order # */
        .accounts-table th:nth-child(3),
        .accounts-table td:nth-child(3) {
            width: 8%;
        }

        /* Sales Executive */
        .accounts-table th:nth-child(4),
        .accounts-table td:nth-child(4) {
            width: 14%;
        }

        /* Discord Email */
        .accounts-table th:nth-child(5),
        .accounts-table td:nth-child(5) {
            width: 25%;
        }

        /* Up-sale Order # */
        .accounts-table th:nth-child(6),
        .accounts-table td:nth-child(6) {
            width: 10%;
        }

        /* PC Number */
        .accounts-table th:nth-child(7),
        .accounts-table td:nth-child(7) {
            width: 10%;
        }

        /* Fresh Sale Item */
        .accounts-table th:nth-child(8),
        .accounts-table td:nth-child(8) {
            width: 12%;
        }

        /* 1st Sale Price */
        .accounts-table th:nth-child(9),
        .accounts-table td:nth-child(9) {
            width: 12%;
        }

        /* Next Payment Date */
        .accounts-table th:nth-child(10),
        .accounts-table td:nth-child(10) {
            width: 14%;
        }

        /* Closed By */
        .accounts-table th:nth-child(11),
        .accounts-table td:nth-child(11) {
            width: 10%;
        }

        /* Team */
        .accounts-table th:nth-child(12),
        .accounts-table td:nth-child(12) {
            width: 15%;
        }

        /* Nurturing Sale */
        .accounts-table th:nth-child(13),
        .accounts-table td:nth-child(13) {
            width: 12%;
        }

        /* Plan Upcoming Sales */
        .accounts-table th:nth-child(14),
        .accounts-table td:nth-child(14) {
            width: 15%;
        }

        /* Nurturing Rating */
        .accounts-table th:nth-child(15),
        .accounts-table td:nth-child(15) {
            width: 12%;
        }

        /* Items Left for Up-Sale */
        .accounts-table th:nth-child(16),
        .accounts-table td:nth-child(16) {
            width: 12%;
        }

        /* Client Discord Username */
        .accounts-table th:nth-child(17),
        .accounts-table td:nth-child(17) {
            width: 15%;
        }

        /* Client Name Payment */
        .accounts-table th:nth-child(18),
        .accounts-table td:nth-child(18) {
            width: 15%;
        }

        /* Expected Next Up Sale Date */
        .accounts-table th:nth-child(19),
        .accounts-table td:nth-child(19) {
            width: 14%;
        }

        /* If Client Lost */
        .accounts-table th:nth-child(20),
        .accounts-table td:nth-child(20) {
            width: 25%;
        }

        /* Comments */
        .accounts-table th:nth-child(21),
        .accounts-table td:nth-child(21) {
            width: 25%;
        }

        /* Created At */
        .accounts-table th:nth-child(22),
        .accounts-table td:nth-child(22) {
            width: 10%;
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
        }

        select[multiple] {
            height: 100px;
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

        .btn-support {
            background: #17a2b8;
            color: white;
            padding: 5px 10px;
            border: none;
            border-radius: 3px;
            cursor: pointer;
            font-size: 14px;
            margin-left: 5px;
        }

        .btn-support:hover {
            background: #138496;
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

        .filter-container {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        .filter-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 15px;
            align-items: end;
        }

        .filter-form .form-group {
            margin-bottom: 0;
        }

        .filter-form button,
        .filter-form a {
            padding: 10px 20px;
            background: #007bff;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            margin-right: 10px;
        }

        .filter-form button:hover,
        .filter-form a:hover {
            background: #0056b3;
        }

        .filter-form a {
            background: #6c757d;
        }

        .filter-form a:hover {
            background: #5a6268;
        }

        .error-message {
            color: red;
            margin-bottom: 15px;
            font-weight: bold;
        }
    </style>
    <script>
        function openModal() {
            document.getElementById('addModal').style.display = 'block';
        }

        function closeModal() {
            document.getElementById('addModal').style.display = 'none';
            document.getElementById('editModal').style.display = 'none';
            document.getElementById('supportModal').style.display = 'none';
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

        function openEditModal(data) {
            const m = document.getElementById('editModal');
            document.getElementById('edit_id').value = data.id;
            document.getElementById('edit_order_number').value = data.order_number;
            document.getElementById('edit_sales_executive').value = data.sales_executive;
            document.getElementById('edit_discord_email').value = data.discord_email;
            document.getElementById('edit_pc_number').value = data.pc_number;
            document.getElementById('edit_fresh_sale_item').value = data.fresh_sale_item;
            document.getElementById('edit_first_sale_price').value = data.first_sale_price;
            document.getElementById('edit_next_payment_date').value = data.next_payment_date;
            document.getElementById('edit_closed_by').value = data.closed_by;
            document.getElementById('edit_team').value = data.team;
            document.getElementById('edit_nurturing_sale').value = data.nurturing_sale;
            // multi-select
            const multi = document.getElementById('edit_plan_upcoming_sales');
            const selected = (data.plan_upcoming_sales || '').split(',').map(s => s.trim()).filter(Boolean);
            for (let i = 0; i < multi.options.length; i++) {
                multi.options[i].selected = selected.includes(multi.options[i].value);
            }
            if (window.customMultiSelects && window.customMultiSelects['edit_plan_upcoming_sales'] && typeof window.customMultiSelects['edit_plan_upcoming_sales'].refresh === 'function') {
                window.customMultiSelects['edit_plan_upcoming_sales'].refresh();
            }
            document.getElementById('edit_nurturing_rating').value = data.nurturing_rating;
            document.getElementById('edit_items_left_upsale').value = data.items_left_upsale || '';
            document.getElementById('edit_client_discord_username').value = data.client_discord_username;
            document.getElementById('edit_client_name_payment').value = data.client_name_payment;
            document.getElementById('edit_expected_next_upsale_date').value = data.expected_next_upsale_date;
            document.getElementById('edit_if_client_lost').value = data.if_client_lost || '';
            document.getElementById('edit_comments').value = data.comments;
            // Populate upsale order numbers (ensure unique)
            const upsaleContainer = document.getElementById('edit_upsale_order_container');
            upsaleContainer.innerHTML = '';
            const upsaleNumbers = [...new Set((data.upsale_order_number || '').split(',').map(s => s.trim()).filter(s => s !== ''))];
            upsaleNumbers.forEach(num => addUpsaleField('edit_upsale_order_container', num));
            if (upsaleNumbers.length === 0) {
                addUpsaleField('edit_upsale_order_container');
            }
            console.log('Setting modal display to block');
            m.style.display = 'block';
            console.log('Modal display set to:', m.style.display);
        }

        function addUpsaleField(containerId = 'upsale_order_container', value = '') {
            const container = document.getElementById(containerId);
            const group = document.createElement('div');
            group.className = 'upsale-input-group';
            group.innerHTML = `
                <input type="number" class="upsale_order_input" name="upsale_order_number[]" placeholder="e.g. 2534" value="${value}">
                <button type="button" class="remove-upsale" onclick="removeUpsaleField(this, '${containerId}')">Remove</button>
            `;
            container.appendChild(group);
        }

        function removeUpsaleField(button, containerId) {
            const group = button.parentElement;
            group.remove();
            const container = document.getElementById(containerId);
            if (container.children.length === 0) {
                addUpsaleField(containerId);
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            // Edit handlers
            document.querySelectorAll('.btn-edit').forEach(btn => {
                btn.addEventListener('click', function() {
                    console.log('Edit button clicked');
                    const d = this.dataset;
                    console.log('Dataset:', d);
                    openEditModal({
                        id: d.id,
                        order_number: d.order_number,
                        sales_executive: d.sales_executive,
                        discord_email: d.discord_email,
                        upsale_order_number: d.upsale_order_number,
                        pc_number: d.pc_number,
                        fresh_sale_item: d.fresh_sale_item,
                        first_sale_price: d.first_sale_price,
                        next_payment_date: d.next_payment_date,
                        closed_by: d.closed_by,
                        team: d.team,
                        nurturing_sale: d.nurturing_sale,
                        plan_upcoming_sales: d.plan_upcoming_sales,
                        nurturing_rating: d.nurturing_rating,
                        items_left_upsale: d.items_left_upsale,
                        client_discord_username: d.client_discord_username,
                        client_name_payment: d.client_name_payment,
                        expected_next_upsale_date: d.expected_next_upsale_date,
                        if_client_lost: d.if_client_lost,
                        comments: d.comments
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

            // Support ticket handlers
            document.querySelectorAll('.btn-support').forEach(btn => {
                btn.addEventListener('click', function() {
                    document.getElementById('support_entry_id').value = this.dataset.id;
                    document.getElementById('support_discord_email').value = this.dataset.discord_email;
                    document.getElementById('support_order_number').value = this.dataset.order_number;
                    document.getElementById('support_query').value = '';
                    document.getElementById('supportModal').style.display = 'block';
                });
            });

            // Custom Multi-Select (tag-style dropdown) for plan_upcoming_sales
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

                const maxSelections = 4;
                const minSelections = 1;

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
                            if (currentlySelected.length >= maxSelections) {
                                alert('You can select a maximum of ' + maxSelections + ' items.');
                                return;
                            }
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
                        ph.textContent = 'Select 1 to 4 items';
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
            const addSelect = document.getElementById('plan_upcoming_sales');
            const editSelect = document.getElementById('edit_plan_upcoming_sales');
            if (addSelect) {
                const api = initCustomMultiSelect(addSelect);
                if (api) window.customMultiSelects['plan_upcoming_sales'] = api;
            }
            if (editSelect) {
                const api = initCustomMultiSelect(editSelect);
                if (api) window.customMultiSelects['edit_plan_upcoming_sales'] = api;
            }

            // Client-side validation: enforce 1-4 selections on submit
            function validateSelection(selectEl) {
                const count = Array.from(selectEl.options).filter(o => o.selected).length;
                return count >= 1 && count <= 4;
            }

            const addForm = document.querySelector('#addModal form');
            if (addForm && addSelect) {
                addForm.addEventListener('submit', function(e) {
                    if (!validateSelection(addSelect)) {
                        e.preventDefault();
                        alert('Please select between 1 and 4 items for Plan for upcoming sales.');
                        return;
                    }
                    e.preventDefault(); // Prevent default form submission
                    const formData = new FormData(addForm);
                    formData.append('ajax', '1'); // Ensure ajax flag is set
                    fetch(window.location.href, {
                            method: 'POST',
                            body: formData
                        })
                        .then(response => response.json())
                        .then(data => {
                            const errorDiv = document.getElementById('addErrorMessage');
                            if (data.error) {
                                errorDiv.textContent = data.error;
                                errorDiv.style.display = 'block';
                                // Scroll to the top of the modal to show the error
                                const modalContent = document.querySelector('#addModal .modal-content');
                                if (modalContent) {
                                    modalContent.scrollTo({
                                        top: 0,
                                        behavior: 'smooth'
                                    });
                                }
                                // Scroll to the field with error if applicable
                                const fieldMatch = data.error.match(/Field '([^']+)'/);
                                if (fieldMatch) {
                                    const fieldName = fieldMatch[1];
                                    const fieldElement = document.getElementById(fieldName);
                                    if (fieldElement) {
                                        const modalContent = fieldElement.closest('.modal-content');
                                        if (modalContent) {
                                            const rect = fieldElement.getBoundingClientRect();
                                            const modalRect = modalContent.getBoundingClientRect();
                                            const scrollTop = modalContent.scrollTop + (rect.top - modalRect.top) - (modalContent.clientHeight / 2) + (rect.height / 2);
                                            modalContent.scrollTo({
                                                top: scrollTop,
                                                behavior: 'smooth'
                                            });
                                        }
                                    }
                                }
                            } else if (data.success) {
                                errorDiv.textContent = 'Entry added successfully!';
                                errorDiv.style.color = 'green';
                                errorDiv.style.display = 'block';
                                addForm.reset(); // Reset the form
                                // Optionally close the modal after a delay
                                setTimeout(() => {
                                    closeModal();
                                    errorDiv.style.display = 'none';
                                    errorDiv.style.color = 'red'; // Reset color
                                }, 2000);
                            }
                        })
                        .catch(error => {
                            console.error('Error:', error);
                            const errorDiv = document.getElementById('addErrorMessage');
                            errorDiv.textContent = 'An unexpected error occurred. Please try again.';
                            errorDiv.style.display = 'block';
                        });
                });
            }
            const editForm = document.querySelector('#editModal form');
            if (editForm && editSelect) {
                editForm.addEventListener('submit', function(e) {
                    if (!validateSelection(editSelect)) {
                        e.preventDefault();
                        alert('Please select between 1 and 4 items for Plan for upcoming sales.');
                        return;
                    }
                    e.preventDefault(); // Prevent default form submission
                    const formData = new FormData(editForm);
                    formData.append('ajax', '1'); // Ensure ajax flag is set
                    fetch(window.location.href, {
                            method: 'POST',
                            body: formData
                        })
                        .then(response => response.json())
                        .then(data => {
                            const errorDiv = document.getElementById('editErrorMessage');
                            if (data.error) {
                                errorDiv.textContent = data.error;
                                errorDiv.style.display = 'block';
                                // Scroll to the top of the modal to show the error
                                const modalContent = document.querySelector('#editModal .modal-content');
                                if (modalContent) {
                                    modalContent.scrollTo({
                                        top: 0,
                                        behavior: 'smooth'
                                    });
                                }
                                // Scroll to the field with error if applicable
                                const fieldMatch = data.error.match(/Field '([^']+)'/);
                                if (fieldMatch) {
                                    const fieldName = fieldMatch[1];
                                    const fieldElement = document.getElementById('edit_' + fieldName);
                                    if (fieldElement) {
                                        const modalContent = fieldElement.closest('.modal-content');
                                        if (modalContent) {
                                            const rect = fieldElement.getBoundingClientRect();
                                            const modalRect = modalContent.getBoundingClientRect();
                                            const scrollTop = modalContent.scrollTop + (rect.top - modalRect.top) - (modalContent.clientHeight / 2) + (rect.height / 2);
                                            modalContent.scrollTo({
                                                top: scrollTop,
                                                behavior: 'smooth'
                                            });
                                        }
                                    }
                                }
                            } else if (data.success) {
                                errorDiv.textContent = data.message || 'Update successful!';
                                errorDiv.style.color = 'green';
                                errorDiv.style.display = 'block';
                                editForm.reset(); // Reset the form
                                // Optionally close the modal after a delay
                                setTimeout(() => {
                                    closeModal();
                                    errorDiv.style.display = 'none';
                                    errorDiv.style.color = 'red'; // Reset color
                                    // Optionally reload the page to refresh the table
                                    location.reload();
                                }, 2000);
                            }
                        })
                        .catch(error => {
                            console.error('Error:', error);
                            const errorDiv = document.getElementById('editErrorMessage');
                            errorDiv.textContent = 'An unexpected error occurred. Please try again.';
                            errorDiv.style.display = 'block';
                        });
                });
            }

            // Auto-populate PC Number when Discord Email is selected
            document.getElementById('discord_email').addEventListener('change', function() {
                const email = this.value;
                if (email) {
                    fetch(`?fetch_pc=1&discord_email=${encodeURIComponent(email)}`)
                        .then(response => response.json())
                        .then(data => {
                            document.getElementById('pc_number').value = data.pc_number;
                        })
                        .catch(error => console.error('Error fetching PC number:', error));
                } else {
                    document.getElementById('pc_number').value = '';
                }
            });

            document.getElementById('edit_discord_email').addEventListener('change', function() {
                const email = this.value;
                if (email) {
                    fetch(`?fetch_pc=1&discord_email=${encodeURIComponent(email)}`)
                        .then(response => response.json())
                        .then(data => {
                            document.getElementById('edit_pc_number').value = data.pc_number;
                        })
                        .catch(error => console.error('Error fetching PC number:', error));
                } else {
                    document.getElementById('edit_pc_number').value = '';
                }
            });
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
            <h1>Client Retention Form</h1>
            <div class="user-info">
                <span>Welcome, <?php echo htmlspecialchars($_SESSION['name']); ?></span>
                <a href="../includes/logout.php" class="logout-btn">Logout</a>
            </div>
        </header>

        <div class="dashboard-content">
            <a href="dashboard_<?php echo $_SESSION['role']; ?>.php" class="back-link">&larr; Back to Dashboard</a>
            <div class="accounts-container">
                <button type="button" class="btn-add" onclick="openModal()">Add New Entry</button>

                <!-- Hidden Delete Form -->
                <form id="deleteForm" method="POST" style="display:none;">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="">
                </form>

                <div class="filter-container">
                    <form class="filter-form" method="GET">
                        <div class="form-group">
                            <label for="filter_order_number">Order Number:</label>
                            <input type="number" id="filter_order_number" name="filter_order_number" value="<?php echo htmlspecialchars($_GET['filter_order_number'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label for="filter_discord_email">Discord Email:</label>
                            <select id="filter_discord_email" name="filter_discord_email">
                                <option value="">All</option>
                                <?php foreach ($discord_emails as $email): ?>
                                    <option value="<?php echo htmlspecialchars($email); ?>" <?php if (isset($_GET['filter_discord_email']) && $_GET['filter_discord_email'] == $email) echo 'selected'; ?>><?php echo htmlspecialchars($email); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="filter_upsale_order_number">Up-sale Order Number:</label>
                            <input type="text" id="filter_upsale_order_number" name="filter_upsale_order_number" value="<?php echo htmlspecialchars($_GET['filter_upsale_order_number'] ?? ''); ?>" placeholder="e.g. 2534">
                        </div>
                        <div class="form-group">
                            <label for="filter_fresh_sale_item">Fresh Sale Item:</label>
                            <input type="text" id="filter_fresh_sale_item" name="filter_fresh_sale_item" value="<?php echo htmlspecialchars($_GET['filter_fresh_sale_item'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label for="filter_next_payment_date">Next Payment Date:</label>
                            <input type="date" id="filter_next_payment_date" name="filter_next_payment_date" value="<?php echo htmlspecialchars($_GET['filter_next_payment_date'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label for="filter_client_discord_username">Client Discord Username:</label>
                            <input type="text" id="filter_client_discord_username" name="filter_client_discord_username" value="<?php echo htmlspecialchars($_GET['filter_client_discord_username'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label for="filter_client_name_payment">Client Name Payment:</label>
                            <input type="text" id="filter_client_name_payment" name="filter_client_name_payment" value="<?php echo htmlspecialchars($_GET['filter_client_name_payment'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label for="filter_expected_next_upsale_date">Expected Next Up Sale Date:</label>
                            <input type="date" id="filter_expected_next_upsale_date" name="filter_expected_next_upsale_date" value="<?php echo htmlspecialchars($_GET['filter_expected_next_upsale_date'] ?? ''); ?>">
                        </div>
                        <button type="submit">Filter</button>
                        <a href="<?php echo basename(__FILE__); ?>">Clear Filters</a>
                    </form>
                </div>

                <h2>Client Retention Entries</h2>
                <?php if ($entries): ?>
                    <div class="table-wrapper">
                        <table class="accounts-table">
                            <thead>
                                <tr>
                                    <th>S.No</th>
                                    <th>ID</th>
                                    <th>Order #</th>
                                    <th>Sales Executive</th>
                                    <th>Discord Email</th>
                                    <th>Up-sale Order #</th>
                                    <th>PC Number</th>
                                    <th>Fresh Sale Item</th>
                                    <th>1st Sale Price Details</th>
                                    <th>Next Payment Date</th>
                                    <th>Closed By</th>
                                    <th>Team</th>
                                    <th>Nurturing Sale</th>
                                    <th>Plan Upcoming Sales</th>
                                    <th>Nurturing Rating</th>
                                    <th>Items Left for Up-Sale</th>
                                    <th>Client Discord Username</th>
                                    <th>Client Name Payment</th>
                                    <th>Expected Next Up Sale Date</th>
                                    <th>If Client Lost</th>
                                    <th>Comments</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $serial_counter = count($entries); ?>
                                <?php foreach ($entries as $entry): ?>
                                    <tr>
                                        <td><?php echo $serial_counter--; ?></td>
                                        <td><?php echo htmlspecialchars($entry['id']); ?></td>
                                        <td><?php echo htmlspecialchars($entry['order_number']); ?></td>
                                        <td><?php echo htmlspecialchars($entry['sales_executive']); ?></td>
                                        <td><?php echo htmlspecialchars($entry['discord_email']); ?></td>
                                        <td><?php echo htmlspecialchars(formatUpsaleOrderNumbers($entry['upsale_order_number'])); ?></td>
                                        <td><?php echo htmlspecialchars($entry['pc_number']); ?></td>
                                        <td><?php echo htmlspecialchars($entry['fresh_sale_item']); ?></td>
                                        <td><?php echo nl2br(htmlspecialchars($entry['first_sale_price'])); ?></td>
                                        <td><?php echo htmlspecialchars($entry['next_payment_date']); ?></td>
                                        <td><?php echo htmlspecialchars($entry['closed_by']); ?></td>
                                        <td><?php echo htmlspecialchars($entry['team']); ?></td>
                                        <td><?php echo htmlspecialchars($entry['nurturing_sale']); ?></td>
                                        <td><?php echo htmlspecialchars($entry['plan_upcoming_sales']); ?></td>
                                        <td><?php echo htmlspecialchars($entry['nurturing_rating']); ?>%</td>
                                        <td><?php echo htmlspecialchars($entry['items_left_upsale']); ?></td>
                                        <td><?php echo htmlspecialchars($entry['client_discord_username']); ?></td>
                                        <td><?php echo htmlspecialchars($entry['client_name_payment']); ?></td>
                                        <td><?php echo htmlspecialchars($entry['expected_next_upsale_date']); ?></td>
                                        <td><?php echo htmlspecialchars($entry['if_client_lost']); ?></td>
                                        <td><?php echo htmlspecialchars($entry['comments']); ?></td>
                                        <td>
                                            <button type="button" class="btn-edit"
                                                data-id="<?php echo htmlspecialchars($entry['id']); ?>"
                                                data-order_number="<?php echo htmlspecialchars($entry['order_number']); ?>"
                                                data-sales_executive="<?php echo htmlspecialchars($entry['sales_executive']); ?>"
                                                data-discord_email="<?php echo htmlspecialchars($entry['discord_email']); ?>"
                                                data-upsale_order_number="<?php echo htmlspecialchars($entry['upsale_order_number']); ?>"
                                                data-pc_number="<?php echo htmlspecialchars($entry['pc_number']); ?>"
                                                data-fresh_sale_item="<?php echo htmlspecialchars($entry['fresh_sale_item']); ?>"
                                                data-first_sale_price="<?php echo htmlspecialchars($entry['first_sale_price']); ?>"
                                                data-next_payment_date="<?php echo htmlspecialchars($entry['next_payment_date']); ?>"
                                                data-closed_by="<?php echo htmlspecialchars($entry['closed_by']); ?>"
                                                data-team="<?php echo htmlspecialchars($entry['team']); ?>"
                                                data-nurturing_sale="<?php echo htmlspecialchars($entry['nurturing_sale']); ?>"
                                                data-plan_upcoming_sales="<?php echo htmlspecialchars($entry['plan_upcoming_sales']); ?>"
                                                data-nurturing_rating="<?php echo htmlspecialchars($entry['nurturing_rating']); ?>"
                                                data-items_left_upsale="<?php echo htmlspecialchars($entry['items_left_upsale']); ?>"
                                                data-client_discord_username="<?php echo htmlspecialchars($entry['client_discord_username']); ?>"
                                                data-client_name_payment="<?php echo htmlspecialchars($entry['client_name_payment']); ?>"
                                                data-expected_next_upsale_date="<?php echo htmlspecialchars($entry['expected_next_upsale_date']); ?>"
                                                data-if_client_lost="<?php echo htmlspecialchars($entry['if_client_lost']); ?>"
                                                data-comments="<?php echo htmlspecialchars($entry['comments']); ?>">Edit</button>
                                            <button type="button" class="btn-support"
                                                data-id="<?php echo htmlspecialchars($entry['id']); ?>"
                                                data-discord_email="<?php echo htmlspecialchars($entry['discord_email']); ?>"
                                                data-order_number="<?php echo htmlspecialchars($entry['order_number']); ?>">Support Ticket</button>
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
                <h2>Add New Client Retention Entry</h2>
                <div id="addErrorMessage" class="error-message" style="display:none;"></div>
                <form method="POST">
                    <input type="hidden" name="action" value="create">
                    <input type="hidden" name="ajax" value="1">
                    <div class="form-group">
                        <label for="order_number">Order # (1st sale): <span style="color: red;">*</span></label>
                        <input type="number" id="order_number" name="order_number" required>
                    </div>
                    <div class="form-group">
                        <label for="sales_executive">Sales Executive Name: <span style="color: red;">*</span></label>
                        <input type="text" id="sales_executive" name="sales_executive" value="<?php echo isset($_SESSION['name']) ? htmlspecialchars($_SESSION['name']) : ''; ?>" required readonly>
                    </div>
                    <div class="form-group">
                        <label for="discord_email">Discord ID Email: <span style="color: red;">*</span></label>
                        <select id="discord_email" name="discord_email" required>
                            <option value="">Select Discord Email</option>
                            <?php foreach ($discord_emails as $email): ?>
                                <option value="<?php echo htmlspecialchars($email); ?>"><?php echo htmlspecialchars($email); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="upsale_order_number">Up-sale Order # (add multiple unique numbers):</label>
                        <div id="upsale_order_container">
                            <div class="upsale-input-group">
                                <input type="number" class="upsale_order_input" name="upsale_order_number[]" placeholder="e.g. 2534">
                                <button type="button" class="remove-upsale" onclick="removeUpsaleField(this)">Remove</button>
                            </div>
                        </div>
                        <button type="button" onclick="addUpsaleField()">Add Another Order Number</button>
                    </div>
                    <div class="form-group">
                        <label for="pc_number">PC Number: <span style="color: red;">*</span></label>
                        <input type="text" id="pc_number" name="pc_number" value="<?php echo htmlspecialchars((isset($_SESSION['pc_number']) && $_SESSION['pc_number'] !== '') ? $_SESSION['pc_number'] : $_SESSION['username']); ?>" required readonly>
                    </div>
                    <div class="form-group">
                        <label for="fresh_sale_item">Fresh Sale Item: <span style="color: red;">*</span></label>
                        <select id="fresh_sale_item" name="fresh_sale_item" required>
                            <option value="">Select Item</option>
                            <?php foreach ($fresh_sale_options as $option): ?>
                                <option value="<?php echo htmlspecialchars($option); ?>"><?php echo htmlspecialchars($option); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="first_sale_price">1st Sale Price Details: <span style="color: red;">*</span></label>
                        <textarea id="first_sale_price" name="first_sale_price" rows="6" placeholder="Total: $100&#10;&#10;Received: $10&#10;&#10;Remaining: $90&#10;&#10;Work: 6 Static Emotes.&#10;&#10;Email: client@example.com" required></textarea>
                    </div>
                    <div class="form-group">
                        <label for="next_payment_date">Next Payment Date: <span style="color: red;">*</span></label>
                        <input type="date" id="next_payment_date" name="next_payment_date" required>
                    </div>
                    <div class="form-group">
                        <label for="closed_by">Closed By: <span style="color: red;">*</span></label>
                        <input type="text" id="closed_by" name="closed_by" required>
                    </div>
                    <div class="form-group">
                        <label for="team">Team: <span style="color: red;">*</span></label>
                        <select id="team" name="team" required>
                            <option value="">Select Team</option>
                            <?php foreach ($team_options as $option): ?>
                                <option value="<?php echo htmlspecialchars($option); ?>"><?php echo htmlspecialchars($option); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="nurturing_sale">Which Sale are you Nurturing Client for? <span style="color: red;">*</span></label>
                        <input type="text" id="nurturing_sale" name="nurturing_sale" required>
                    </div>
                    <div class="form-group">
                        <label for="plan_upcoming_sales">Plan for upcoming sales (select 1-4 items): <span style="color: red;">*</span></label>
                        <select id="plan_upcoming_sales" name="plan_upcoming_sales[]" multiple required>
                            <?php foreach ($plan_options as $option): ?>
                                <option value="<?php echo htmlspecialchars($option); ?>"><?php echo htmlspecialchars($option); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="nurturing_rating">Rating of Client Nurturing (%): <span style="color: red;">*</span></label>
                        <input type="number" step="0.01" min="0" max="100" id="nurturing_rating" name="nurturing_rating" required>
                    </div>
                    <div class="form-group">
                        <label for="client_discord_username">Client's Discord Username: <span style="color: red;">*</span></label>
                        <input type="text" id="client_discord_username" name="client_discord_username" required>
                    </div>
                    <div class="form-group">
                        <label for="client_name_payment">Client's Name on Payment Method: <span style="color: red;">*</span></label>
                        <input type="text" id="client_name_payment" name="client_name_payment" required>
                    </div>
                    <div class="form-group">
                        <label for="expected_next_upsale_date">Expected Date of Next Up Sale: <span style="color: red;">*</span></label>
                        <input type="date" id="expected_next_upsale_date" name="expected_next_upsale_date" required>
                    </div>
                    <div class="form-group">
                        <label for="if_client_lost">If client is lost (Scenario):</label>
                        <textarea id="if_client_lost" name="if_client_lost"></textarea>
                    </div>
                    <div class="form-group">
                        <label for="comments">Comments: <span style="color: red;">*</span></label>
                        <textarea id="comments" name="comments" required></textarea>
                    </div>
                    <button type="submit">Submit</button>
                </form>
            </div>
        </div>

        <!-- Edit Modal -->
        <div id="editModal" class="modal">
            <div class="modal-content">
                <span class="close" onclick="closeModal()">&times;</span>
                <h2>Edit Client Retention Entry</h2>
                <div id="editErrorMessage" class="error-message" style="display:none;"></div>
                <form method="POST">
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" id="edit_id" name="id">
                    <div class="form-group">
                        <label for="edit_order_number">Order # (1st sale): <span style="color: red;">*</span></label>
                        <input type="number" id="edit_order_number" name="order_number" required>
                    </div>
                    <div class="form-group">
                        <label for="edit_sales_executive">Sales Executive Name: <span style="color: red;">*</span></label>
                        <input type="text" id="edit_sales_executive" name="sales_executive" required readonly>
                    </div>
                    <div class="form-group">
                        <label for="edit_discord_email">Discord ID Email: <span style="color: red;">*</span></label>
                        <select id="edit_discord_email" name="discord_email" required>
                            <option value="">Select Discord Email</option>
                            <?php foreach ($discord_emails as $email): ?>
                                <option value="<?php echo htmlspecialchars($email); ?>"><?php echo htmlspecialchars($email); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="edit_upsale_order_number">Up-sale Order # (add multiple unique numbers):</label>
                        <div id="edit_upsale_order_container">
                        </div>
                        <button type="button" onclick="addUpsaleField('edit_upsale_order_container')">Add Another Order Number</button>
                    </div>
                    <div class="form-group">
                        <label for="edit_pc_number">PC Number: <span style="color: red;">*</span></label>
                        <input type="text" id="edit_pc_number" name="pc_number" required readonly>
                    </div>
                    <div class="form-group">
                        <label for="edit_fresh_sale_item">Fresh Sale Item: <span style="color: red;">*</span></label>
                        <select id="edit_fresh_sale_item" name="fresh_sale_item" required>
                            <option value="">Select Item</option>
                            <?php foreach ($fresh_sale_options as $option): ?>
                                <option value="<?php echo htmlspecialchars($option); ?>"><?php echo htmlspecialchars($option); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="edit_first_sale_price">1st Sale Price Details: <span style="color: red;">*</span></label>
                        <textarea id="edit_first_sale_price" name="first_sale_price" rows="6" placeholder="Total: $100&#10;&#10;Received: $10&#10;&#10;Remaining: $90&#10;&#10;Work: 6 Static Emotes.&#10;&#10;Email: client@example.com" required></textarea>
                    </div>
                    <div class="form-group">
                        <label for="edit_next_payment_date">Next Payment Date: <span style="color: red;">*</span></label>
                        <input type="date" id="edit_next_payment_date" name="next_payment_date" required>
                    </div>
                    <div class="form-group">
                        <label for="edit_closed_by">Closed By: <span style="color: red;">*</span></label>
                        <input type="text" id="edit_closed_by" name="closed_by" required>
                    </div>
                    <div class="form-group">
                        <label for="edit_team">Team: <span style="color: red;">*</span></label>
                        <select id="edit_team" name="team" required>
                            <option value="">Select Team</option>
                            <?php foreach ($team_options as $option): ?>
                                <option value="<?php echo htmlspecialchars($option); ?>"><?php echo htmlspecialchars($option); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="edit_nurturing_sale">Which Sale are you Nurturing Client for? <span style="color: red;">*</span></label>
                        <input type="text" id="edit_nurturing_sale" name="nurturing_sale" required>
                    </div>
                    <div class="form-group">
                        <label for="edit_plan_upcoming_sales">Plan for upcoming sales (select 1-4 items): <span style="color: red;">*</span></label>
                        <select id="edit_plan_upcoming_sales" name="plan_upcoming_sales[]" multiple required>
                            <?php foreach ($plan_options as $option): ?>
                                <option value="<?php echo htmlspecialchars($option); ?>"><?php echo htmlspecialchars($option); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="edit_items_left_upsale">Items Left for Up-Sale:</label>
                        <textarea id="edit_items_left_upsale" name="items_left_upsale"></textarea>
                    </div>
                    <div class="form-group">
                        <label for="edit_nurturing_rating">Rating of Client Nurturing (%): <span style="color: red;">*</span></label>
                        <input type="number" step="0.01" min="0" max="100" id="edit_nurturing_rating" name="nurturing_rating" required>
                    </div>
                    <div class="form-group">
                        <label for="edit_client_discord_username">Client's Discord Username: <span style="color: red;">*</span></label>
                        <input type="text" id="edit_client_discord_username" name="client_discord_username" required>
                    </div>
                    <div class="form-group">
                        <label for="edit_client_name_payment">Client's Name on Payment Method: <span style="color: red;">*</span></label>
                        <input type="text" id="edit_client_name_payment" name="client_name_payment" required>
                    </div>
                    <div class="form-group">
                        <label for="edit_expected_next_upsale_date">Expected Date of Next Up Sale: <span style="color: red;">*</span></label>
                        <input type="date" id="edit_expected_next_upsale_date" name="expected_next_upsale_date" required>
                    </div>
                    <div class="form-group">
                        <label for="edit_if_client_lost">If client is lost (Scenario):</label>
                        <textarea id="edit_if_client_lost" name="if_client_lost"></textarea>
                    </div>
                    <div class="form-group">
                        <label for="edit_comments">Comments: <span style="color: red;">*</span></label>
                        <textarea id="edit_comments" name="comments" required></textarea>
                    </div>
                    <button type="submit">Update</button>
                </form>
            </div>
        </div>
    </div>

    <!-- Support Ticket Modal -->
    <div id="supportModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal()">&times;</span>
            <h2>Create Support Ticket</h2>
            <form action="support_tickets.php" method="POST">
                <input type="hidden" name="entry_id" id="support_entry_id">
                <input type="hidden" name="discord_email" id="support_discord_email">
                <input type="hidden" name="order_number" id="support_order_number">
                <div class="form-group">
                    <label for="support_query">Enter your query:</label>
                    <textarea id="support_query" name="query" rows="5" required></textarea>
                </div>
                <button type="submit">Send to Support</button>
            </form>
        </div>
    </div>
</body>

</html>