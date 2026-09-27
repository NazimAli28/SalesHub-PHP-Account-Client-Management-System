<?php
session_start();

// Check if user is logged in and is support
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

// Check IP restriction (admin and support can access from anywhere)
if ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'support' && !is_ip_allowed()) {
    header("Location: ../index.php?error=access_denied");
    exit();
}

if (!function_exists('normalizeDateToYmd')) {
    /**
     * Normalize various date formats (dd-mm-yy, mm/dd/YYYY, etc.) into Y-m-d.
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

        // Common delimiters converted to hyphen for easier parsing
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

        // Fallback to strtotime for any other reasonable string
        $timestamp = strtotime($value);
        if ($timestamp !== false) {
            return date('Y-m-d', $timestamp);
        }

        return null;
    }
}

if (!function_exists('sanitizeUpsaleOrderNumbers')) {
    /**
     * Accepts comma or space separated upsale order numbers and returns a comma-delimited numeric string.
     */
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
    /**
     * Present upsale order numbers with single spaces after commas for readability.
     */
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
    /**
     * Returns the first upsale order number from any user input for filtering.
     */
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
    /**
     * Trim and normalize multi-line first sale price notes.
     */
    function sanitizeFirstSalePriceText($value)
    {
        if (!isset($value)) {
            return '';
        }
        $normalized = str_replace(["\r\n", "\r"], "\n", (string)$value);
        return trim($normalized);
    }
}

$discord_emails = [];

// Fetch discord emails from accounts table
$stmt_discord = $conn->prepare("SELECT DISTINCT discord_email FROM accounts WHERE status = 1 AND discord_email IS NOT NULL AND discord_email != ''");
if (!$stmt_discord) {
    die('Prepare failed: ' . $conn->error);
}
$stmt_discord->execute();
$result_discord = $stmt_discord->get_result();
while ($row = $result_discord->fetch_assoc()) {
    $discord_emails[] = $row['discord_email'];
}
$stmt_discord->close();

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

// Function to build preserved query string for redirects
function buildPreservedQueryString()
{
    $exclude = ['deleted', 'updated', 'success', 'bulk_message', 'queued', 'nochange'];
    $query = [];
    foreach ($_GET as $key => $value) {
        if (!in_array($key, $exclude)) {
            $query[] = urlencode($key) . '=' . urlencode($value);
        }
    }
    return implode('&', $query);
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? $_POST['action'] : 'create';
    $is_ajax = isset($_POST['ajax']);

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

        $expectedHeaders = ['order_number', 'discord_email', 'upsale_order_number', 'fresh_sale_item', 'first_sale_price', 'next_payment_date', 'closed_by', 'team', 'nurturing_sale', 'plan_upcoming_sales', 'nurturing_rating', 'items_left_upsale', 'client_discord_username', 'client_name_payment', 'expected_next_upsale_date', 'if_client_lost', 'comments'];

        // Normalize headers (lowercase, trim)
        $header = array_map('strtolower', array_map('trim', $header));
        // Remove empty headers (e.g., from trailing commas)
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
            // Trim trailing empty columns from the row
            while (count($row) > 0 && end($row) === '') {
                array_pop($row);
            }
            // If row is entirely empty, skip without error (common with trailing newline)
            $nonEmptyCells = array_filter($row, function ($value) {
                return trim((string)$value) !== '';
            });
            if (empty($nonEmptyCells)) {
                continue;
            }

            // Normalize column count by padding/truncating extra empty cells
            $expectedColumnCount = count($expectedHeaders);
            while (count($row) < $expectedColumnCount) {
                $row[] = '';
            }
            if (count($row) > $expectedColumnCount) {
                // If excess columns remain after trimming empties, capture error
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

            // Sanitize discord_email first
            $discord_email = filter_var(trim($data['discord_email']), FILTER_SANITIZE_EMAIL);

            // Fetch sales executive and pc number based on discord_email
            $sales_executive = '';
            $pc_number = '';
            if (!empty($discord_email)) {
                $stmt_fetch = $conn->prepare("SELECT agent_name, pc_number FROM accounts WHERE discord_email = ? AND status = 1");
                if (!$stmt_fetch) {
                    die('Prepare failed: ' . $conn->error);
                }
                $stmt_fetch->bind_param("s", $discord_email);
                $stmt_fetch->execute();
                $result_fetch = $stmt_fetch->get_result();
                if ($row_fetch = $result_fetch->fetch_assoc()) {
                    $sales_executive = htmlspecialchars(trim($row_fetch['agent_name'] ?? ''));
                    $pc_number = htmlspecialchars(trim($row_fetch['pc_number'] ?? ''));
                }
                $stmt_fetch->close();
            }

            // Parse dates using shared normalizer (reject invalid non-empty values)
            $next_payment_raw = $data['next_payment_date'] ?? '';
            $next_payment_date = normalizeDateToYmd($next_payment_raw);
            if ($next_payment_date === null && trim((string)$next_payment_raw) !== '') {
                $errors[] = 'Row ' . $currentRowNumber . ': Invalid date for next_payment_date.';
                continue;
            }

            $expected_next_raw = $data['expected_next_upsale_date'] ?? '';
            $expected_next_upsale_date = normalizeDateToYmd($expected_next_raw);
            if ($expected_next_upsale_date === null && trim((string)$expected_next_raw) !== '') {
                $errors[] = 'Row ' . $currentRowNumber . ': Invalid date for expected_next_upsale_date.';
                continue;
            }

            // Sanitize and validate other fields
            $order_number = intval($data['order_number']);
            $upsale_order_number = sanitizeUpsaleOrderNumbers($data['upsale_order_number']);
            $fresh_sale_item = htmlspecialchars(trim($data['fresh_sale_item']));
            $first_sale_price = sanitizeFirstSalePriceText($data['first_sale_price']);
            $closed_by = htmlspecialchars(trim($data['closed_by']));
            $team = htmlspecialchars(trim($data['team']));
            $nurturing_sale = htmlspecialchars(trim($data['nurturing_sale']));
            $plan_upcoming_sales = htmlspecialchars(trim($data['plan_upcoming_sales']));
            $nurturing_rating = floatval($data['nurturing_rating']);
            $items_left_upsale = htmlspecialchars(trim($data['items_left_upsale']));
            $client_discord_username = htmlspecialchars(trim($data['client_discord_username']));
            $client_name_payment = htmlspecialchars(trim($data['client_name_payment']));
            $if_client_lost = htmlspecialchars(trim($data['if_client_lost']));
            $comments = htmlspecialchars(trim($data['comments']));

            // Basic validation - only discord_email is mandatory
            if (empty($discord_email)) {
                $errors[] = 'Row ' . $currentRowNumber . ': Missing discord_email.';
                continue;
            }

            // Insert
            $stmt = $conn->prepare("INSERT INTO client_retention (order_number, sales_executive, discord_email, upsale_order_number, pc_number, fresh_sale_item, first_sale_price, next_payment_date, closed_by, team, nurturing_sale, plan_upcoming_sales, nurturing_rating, items_left_upsale, client_discord_username, client_name_payment, expected_next_upsale_date, if_client_lost, comments) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("isssssssssssdssssss", $order_number, $sales_executive, $discord_email, $upsale_order_number, $pc_number, $fresh_sale_item, $first_sale_price, $next_payment_date, $closed_by, $team, $nurturing_sale, $plan_upcoming_sales, $nurturing_rating, $items_left_upsale, $client_discord_username, $client_name_payment, $expected_next_upsale_date, $if_client_lost, $comments);
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
        header("Location: " . basename(__FILE__) . "?" . buildPreservedQueryString() . "&bulk_message=" . urlencode($message));
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

        if ($_SESSION['role'] === 'support' || $_SESSION['role'] === 'admin') {
            // Direct delete for support/admin
            $delStmt = $conn->prepare("DELETE FROM client_retention WHERE id = ?");
            $delStmt->bind_param("i", $id);
            $delStmt->execute();
            $delStmt->close();
            header("Location: " . basename(__FILE__) . "?" . buildPreservedQueryString() . "&deleted=1");
        } else {
            // Create approval ticket for others
            $changes = ['id' => $id];
            $json = json_encode($changes, JSON_UNESCAPED_UNICODE);
            $submittedBy = isset($_SESSION['username']) ? $_SESSION['username'] : 'sales_executive';
            $submittedById = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : 0;
            $ins = $conn->prepare("INSERT INTO approvals (table_name, record_id, action, changes_json, submitted_by, submitted_by_user_id) VALUES ('client_retention', ?, 'delete', ?, ?, ?)");
            $ins->bind_param("issi", $id, $json, $submittedBy, $submittedById);
            $ins->execute();
            $ins->close();
            header("Location: " . basename(__FILE__) . "?" . buildPreservedQueryString() . "&queued=1");
        }
        exit();
    }

    // Server-side validation for required fields (create/update)
    $required_fields = ['order_number', 'discord_email', 'fresh_sale_item', 'first_sale_price', 'next_payment_date', 'closed_by', 'team', 'nurturing_sale', 'client_discord_username', 'client_name_payment', 'expected_next_upsale_date', 'comments'];
    foreach ($required_fields as $field) {
        if (empty($_POST[$field])) {
            if ($is_ajax) {
                header('Content-Type: application/json');
                echo json_encode(['error' => "Field '$field' is required."]);
                exit();
            } else {
                die("Error: Field '$field' is required.");
            }
        }
    }
    // Normalize and validate required date fields
    $next_payment_date = normalizeDateToYmd($_POST['next_payment_date']);
    if ($next_payment_date === null) {
        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode(['error' => "Invalid date for 'next_payment_date'."]);
            exit();
        } else {
            die("Error: Invalid date for 'next_payment_date'.");
        }
    }

    $expected_next_upsale_date = normalizeDateToYmd($_POST['expected_next_upsale_date']);
    if ($expected_next_upsale_date === null) {
        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode(['error' => "Invalid date for 'expected_next_upsale_date'."]);
            exit();
        } else {
            die("Error: Invalid date for 'expected_next_upsale_date'.");
        }
    }
    if (!isset($_POST['plan_upcoming_sales']) || !is_array($_POST['plan_upcoming_sales']) || count($_POST['plan_upcoming_sales']) < 1 || count($_POST['plan_upcoming_sales']) > 4) {
        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode(['error' => "Field 'plan_upcoming_sales' must have between 1 and 4 selections."]);
            exit();
        } else {
            die("Error: Field 'plan_upcoming_sales' must have between 1 and 4 selections.");
        }
    }
    if (!isset($_POST['nurturing_rating']) || $_POST['nurturing_rating'] === '') {
        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode(['error' => "Field 'nurturing_rating' is required."]);
            exit();
        } else {
            die("Error: Field 'nurturing_rating' is required.");
        }
    }

    // Collect and sanitize inputs
    $order_number = intval($_POST['order_number']);
    $sales_executive = htmlspecialchars($_POST['sales_executive']);
    $discord_email = filter_var($_POST['discord_email'], FILTER_SANITIZE_EMAIL);
    $upsale_order_number = sanitizeUpsaleOrderNumbers($_POST['upsale_order_number']);
    $pc_number = htmlspecialchars($_POST['pc_number']);
    $fresh_sale_item = htmlspecialchars($_POST['fresh_sale_item']);
    $first_sale_price = sanitizeFirstSalePriceText($_POST['first_sale_price']);
    $closed_by = htmlspecialchars($_POST['closed_by']);
    $team = htmlspecialchars($_POST['team']);
    $nurturing_sale = htmlspecialchars($_POST['nurturing_sale']);
    $plan_upcoming_sales = implode(',', $_POST['plan_upcoming_sales']);
    $nurturing_rating = floatval($_POST['nurturing_rating']);
    $items_left_upsale = isset($_POST['items_left_upsale']) ? htmlspecialchars($_POST['items_left_upsale']) : '';
    $client_discord_username = htmlspecialchars($_POST['client_discord_username']);
    $client_name_payment = htmlspecialchars($_POST['client_name_payment']);
    $if_client_lost = isset($_POST['if_client_lost']) ? htmlspecialchars($_POST['if_client_lost']) : '';
    $comments = htmlspecialchars($_POST['comments']);

    // ---- START UNIQUE CHECK (add/update) ----
    if (!empty($upsale_order_number)) {
        $new_parts = array_filter(array_map('trim', explode(',', $upsale_order_number)));
        $added_parts = $new_parts;
        if ($action === 'update' && isset($_POST['id'])) {
            $id = intval($_POST['id']);
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
                $query = "SELECT id FROM client_retention WHERE (FIND_IN_SET(?, upsale_order_number) > 0 OR order_number = ?)";
                $params = [$num, intval($num)];
                $types = "si";
                if ($action === 'update' && isset($_POST['id'])) {
                    $query .= " AND id != ?";
                    $params[] = intval($_POST['id']);
                    $types .= "i";
                }
                $stmt = $conn->prepare($query);
                $stmt->bind_param($types, ...$params);
                $stmt->execute();
                $result = $stmt->get_result();
                if ($result->num_rows > 0) {
                    header('Content-Type: application/json');
                    echo json_encode(['error' => "Field 'upsale_order_number': Upsale order number '$num' is already used in another entry (either as upsale or order number)."]);
                    exit();
                }
                $stmt->close();
            }
        }
    }
    // ---- END UNIQUE CHECK ----

    if ($action === 'update') {
        if (!isset($_POST['id'])) {
            die('Error: Missing id for update.');
        }
        $id = intval($_POST['id']);

        if ($_SESSION['role'] === 'support' || $_SESSION['role'] === 'admin') {
            // Direct update for support/admin
            $updStmt = $conn->prepare("UPDATE client_retention SET order_number = ?, sales_executive = ?, discord_email = ?, upsale_order_number = ?, pc_number = ?, fresh_sale_item = ?, first_sale_price = ?, next_payment_date = ?, closed_by = ?, team = ?, nurturing_sale = ?, plan_upcoming_sales = ?, nurturing_rating = ?, items_left_upsale = ?, client_discord_username = ?, client_name_payment = ?, expected_next_upsale_date = ?, if_client_lost = ?, comments = ? WHERE id = ?");
            $updStmt->bind_param("isssssssssssdssssssi", $order_number, $sales_executive, $discord_email, $upsale_order_number, $pc_number, $fresh_sale_item, $first_sale_price, $next_payment_date, $closed_by, $team, $nurturing_sale, $plan_upcoming_sales, $nurturing_rating, $items_left_upsale, $client_discord_username, $client_name_payment, $expected_next_upsale_date, $if_client_lost, $comments, $id);
            $updStmt->execute();
            $updStmt->close();
            if ($is_ajax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => true]);
            } else {
                header("Location: " . basename(__FILE__) . "?" . buildPreservedQueryString() . "&updated=1");
            }
        } else {
            // Compare with existing to build changes diff for approval
            $curStmt = $conn->prepare("SELECT * FROM client_retention WHERE id = ?");
            $curStmt->bind_param("i", $id);
            $curStmt->execute();
            $curRes = $curStmt->get_result();
            $current = $curRes->fetch_assoc();
            $curStmt->close();

            if (!$current) {
                die('Error: Record not found.');
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
                if ($is_ajax) {
                    header('Content-Type: application/json');
                    echo json_encode(['nochange' => true]);
                } else {
                    header("Location: " . basename(__FILE__) . "?nochange=1");
                }
                exit();
            }

            $json = json_encode($changes, JSON_UNESCAPED_UNICODE);
            $submittedBy = isset($_SESSION['username']) ? $_SESSION['username'] : 'sales_executive';
            $submittedById = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : 0;

            $ins = $conn->prepare("INSERT INTO approvals (table_name, record_id, action, changes_json, submitted_by, submitted_by_user_id) VALUES ('client_retention', ?, 'update', ?, ?, ?)");
            $ins->bind_param("issi", $id, $json, $submittedBy, $submittedById);
            $ins->execute();
            $ins->close();

            header("Location: " . basename(__FILE__) . "?queued=1");
        }
        exit();
    }

    // Default: create
    $stmt = $conn->prepare("INSERT INTO client_retention (order_number, sales_executive, discord_email, upsale_order_number, pc_number, fresh_sale_item, first_sale_price, next_payment_date, closed_by, team, nurturing_sale, plan_upcoming_sales, nurturing_rating, items_left_upsale, client_discord_username, client_name_payment, expected_next_upsale_date, if_client_lost, comments) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("isssssssssssdssssss", $order_number, $sales_executive, $discord_email, $upsale_order_number, $pc_number, $fresh_sale_item, $first_sale_price, $next_payment_date, $closed_by, $team, $nurturing_sale, $plan_upcoming_sales, $nurturing_rating, $items_left_upsale, $client_discord_username, $client_name_payment, $expected_next_upsale_date, $if_client_lost, $comments);
    $stmt->execute();
    $stmt->close();

    if ($is_ajax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => true]);
    } else {
        header("Location: " . basename(__FILE__) . "?success=1");
    }
    exit();
}

// Build filters
$where = [];
if (!empty($_GET['order_number'])) {
    $where[] = "order_number = '" . $conn->real_escape_string($_GET['order_number']) . "'";
}
if (!empty($_GET['sales_executive'])) {
    $where[] = "sales_executive LIKE '%" . $conn->real_escape_string($_GET['sales_executive']) . "%'";
}
if (!empty($_GET['discord_email'])) {
    $where[] = "discord_email LIKE '%" . $conn->real_escape_string($_GET['discord_email']) . "%'";
}
if (!empty($_GET['upsale_order_number'])) {
    $needle = extractFirstUpsaleOrderNumber($_GET['upsale_order_number']);
    if ($needle !== '') {
        $where[] = "FIND_IN_SET('" . $conn->real_escape_string($needle) . "', upsale_order_number)";
    }
}
if (!empty($_GET['pc_number'])) {
    $where[] = "pc_number LIKE '%" . $conn->real_escape_string($_GET['pc_number']) . "%'";
}
if (!empty($_GET['fresh_sale_item'])) {
    $where[] = "fresh_sale_item = '" . $conn->real_escape_string($_GET['fresh_sale_item']) . "'";
}
if (!empty($_GET['first_sale_price'])) {
    $where[] = "first_sale_price LIKE '%" . $conn->real_escape_string($_GET['first_sale_price']) . "%'";
}
if (!empty($_GET['next_payment_from'])) {
    $where[] = "next_payment_date >= '" . $conn->real_escape_string($_GET['next_payment_from']) . "'";
}
if (!empty($_GET['next_payment_to'])) {
    $where[] = "next_payment_date <= '" . $conn->real_escape_string($_GET['next_payment_to']) . "'";
}
if (!empty($_GET['closed_by'])) {
    $where[] = "closed_by LIKE '%" . $conn->real_escape_string($_GET['closed_by']) . "%'";
}
if (!empty($_GET['team'])) {
    $where[] = "team = '" . $conn->real_escape_string($_GET['team']) . "'";
}
if (!empty($_GET['nurturing_sale'])) {
    $where[] = "nurturing_sale LIKE '%" . $conn->real_escape_string($_GET['nurturing_sale']) . "%'";
}
if (!empty($_GET['plan_upcoming_sales'])) {
    $where[] = "plan_upcoming_sales LIKE '%" . $conn->real_escape_string($_GET['plan_upcoming_sales']) . "%'";
}
if (!empty($_GET['nurturing_rating'])) {
    $where[] = "nurturing_rating = '" . $conn->real_escape_string($_GET['nurturing_rating']) . "'";
}
if (!empty($_GET['items_left_upsale'])) {
    $where[] = "items_left_upsale LIKE '%" . $conn->real_escape_string($_GET['items_left_upsale']) . "%'";
}
if (!empty($_GET['client_discord_username'])) {
    $where[] = "client_discord_username LIKE '%" . $conn->real_escape_string($_GET['client_discord_username']) . "%'";
}
if (!empty($_GET['client_name_payment'])) {
    $where[] = "client_name_payment LIKE '%" . $conn->real_escape_string($_GET['client_name_payment']) . "%'";
}
if (!empty($_GET['expected_upsale_from'])) {
    $where[] = "expected_next_upsale_date >= '" . $conn->real_escape_string($_GET['expected_upsale_from']) . "'";
}
if (!empty($_GET['expected_upsale_to'])) {
    $where[] = "expected_next_upsale_date <= '" . $conn->real_escape_string($_GET['expected_upsale_to']) . "'";
}
if (!empty($_GET['if_client_lost'])) {
    $where[] = "if_client_lost LIKE '%" . $conn->real_escape_string($_GET['if_client_lost']) . "%'";
}
if (!empty($_GET['comments'])) {
    $where[] = "comments LIKE '%" . $conn->real_escape_string($_GET['comments']) . "%'";
}
if (!empty($_GET['empty_sales_pc'])) {
    $where[] = "(sales_executive IS NULL OR sales_executive = '') OR (pc_number IS NULL OR pc_number = '')";
}

// Pagination settings
$rows_per_page = isset($_GET['rows_per_page']) ? (int)$_GET['rows_per_page'] : 20;
$rows_per_page = max(1, $rows_per_page); // Ensure at least 1
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$page = max(1, $page); // Ensure page is at least 1
$offset = ($page - 1) * $rows_per_page;

// Build base query string for pagination (preserve filters)
$query_for_links = $_GET;
unset($query_for_links['page']);
$base_query_string = http_build_query($query_for_links);
$base_page_prefix = $base_query_string !== '' ? ('?' . $base_query_string . '&') : '?';

// Get total number of entries for pagination
$total_sql = "SELECT COUNT(*) as total FROM client_retention";
if (!empty($where)) {
    $total_sql .= " WHERE " . implode(" AND ", $where);
}
$total_stmt = $conn->prepare($total_sql);
$total_stmt->execute();
$total_result = $total_stmt->get_result();
$total_entries = $total_result->fetch_assoc()['total'];
$total_stmt->close();
$total_pages = ceil($total_entries / $rows_per_page);

// Handle CSV download
if (isset($_GET['download']) && $_GET['download'] === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="client_retention_' . date('Y-m-d') . '.csv"');

    $fp = fopen('php://output', 'w');
    fputcsv($fp, [
        'ID',
        'Order Number',
        'Sales Executive',
        'Discord Email',
        'Up-sale Order Number',
        'PC Number',
        'Fresh Sale Item',
        '1st Sale Price Details',
        'Next Payment Date',
        'Closed By',
        'Team',
        'Nurturing Sale',
        'Plan Upcoming Sales',
        'Nurturing Rating',
        'Items Left for Up-Sale',
        'Client Discord Username',
        'Client Name Payment',
        'Expected Next Up Sale Date',
        'If Client Lost',
        'Comments'
    ]);

    // Fetch all filtered data without pagination
    $sql_download = "SELECT * FROM client_retention";
    if (!empty($where)) {
        $sql_download .= " WHERE " . implode(" AND ", $where);
    }
    $sql_download .= " ORDER BY
            CASE WHEN next_payment_date IS NULL THEN 1 ELSE 0 END ASC,
            ABS(DATEDIFF(next_payment_date, CURDATE())) ASC,
            (next_payment_date <= CURDATE()) DESC,
            next_payment_date ASC,
            created_at DESC";
    $stmt_download = $conn->prepare($sql_download);
    $stmt_download->execute();
    $result_download = $stmt_download->get_result();
    while ($row = $result_download->fetch_assoc()) {
        fputcsv($fp, [
            $row['id'],
            $row['order_number'],
            $row['sales_executive'],
            $row['discord_email'],
            $row['upsale_order_number'],
            $row['pc_number'],
            $row['fresh_sale_item'],
            $row['first_sale_price'],
            $row['next_payment_date'],
            $row['closed_by'],
            $row['team'],
            $row['nurturing_sale'],
            $row['plan_upcoming_sales'],
            $row['nurturing_rating'],
            $row['items_left_upsale'],
            $row['client_discord_username'],
            $row['client_name_payment'],
            $row['expected_next_upsale_date'],
            $row['if_client_lost'],
            $row['comments']
        ]);
    }
    $stmt_download->close();
    fclose($fp);
    $conn->close();
    exit();
}

// Fetch paginated entries for support management
$sql = "SELECT * FROM client_retention";
if (!empty($where)) {
    $sql .= " WHERE " . implode(" AND ", $where);
}
$sql .= " ORDER BY
        CASE WHEN next_payment_date IS NULL THEN 1 ELSE 0 END ASC,
        ABS(DATEDIFF(next_payment_date, CURDATE())) ASC,
        (next_payment_date <= CURDATE()) DESC,
        next_payment_date ASC,
        created_at DESC
        LIMIT ? OFFSET ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $rows_per_page, $offset);
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
            width: 15%;
        }

        /* Fresh Sale Item */
        .accounts-table th:nth-child(8),
        .accounts-table td:nth-child(8) {
            width: 14%;
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
            width: 20%;
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
            margin: 20px 0;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.08);
        }

        .filter-container h3 {
            margin-bottom: 15px;
        }

        .filter-container .form-group {
            flex: 1 1 200px;
        }

        .btn-clear {
            background: #6c757d;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            text-decoration: none;
            display: inline-block;
            margin-left: 10px;
        }

        .btn-clear:hover {
            background: #5a6268;
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

        .form-section {
            margin-bottom: 25px;
            padding: 15px;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            background: #f8f9fa;
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

        .pagination {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin: 20px 0;
        }

        .pagination-info {
            font-size: 14px;
        }

        .pagination-controls a,
        .pagination-controls span {
            margin: 0 5px;
            padding: 5px 10px;
            text-decoration: none;
            border: 1px solid #ccc;
            background: #fff;
            color: #007bff;
        }

        .pagination-controls .current {
            background: #007bff;
            color: white;
            border: 1px solid #007bff;
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

        function openBulkModal() {
            document.getElementById('bulkModal').style.display = 'block';
        }

        function closeModal() {
            document.getElementById('addModal').style.display = 'none';
            document.getElementById('editModal').style.display = 'none';
            document.getElementById('bulkModal').style.display = 'none';
        }
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

        function addUpsaleField(containerId = 'upsale_order_container') {
            const container = document.getElementById(containerId);
            const group = document.createElement('div');
            group.className = 'upsale-input-group';
            group.innerHTML = `
                <input type="number" class="upsale_order_input" name="upsale_order_number[]" placeholder="e.g. 2534">
                <button type="button" class="remove-upsale" onclick="removeUpsaleField(this)">Remove</button>
            `;
            container.appendChild(group);
        }

        function removeUpsaleField(button) {
            const group = button.parentElement;
            const container = group.parentElement;
            group.remove();
            if (container.children.length === 0) {
                addUpsaleField(container.id);
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
            m.style.display = 'block';

            // Populate upsale order numbers
            const upsaleNumbers = (data.upsale_order_number || '').split(',').map(s => s.trim()).filter(s => s !== '');
            const container = document.getElementById('edit_upsale_order_container');
            container.innerHTML = '';
            if (upsaleNumbers.length === 0) {
                // Add one empty field if none
                addUpsaleField('edit_upsale_order_container');
            } else {
                upsaleNumbers.forEach(num => {
                    const group = document.createElement('div');
                    group.className = 'upsale-input-group';
                    group.innerHTML = `
                        <input type="number" class="upsale_order_input" name="upsale_order_number[]" value="${num}" placeholder="e.g. 2534">
                        <button type="button" class="remove-upsale" onclick="removeUpsaleField(this)">Remove</button>
                    `;
                    container.appendChild(group);
                });
            }

            // Fetch sales info based on discord_email for edit modal
            const editDiscordEmail = document.getElementById('edit_discord_email');
            const editSalesExec = document.getElementById('edit_sales_executive');
            const editPcNumber = document.getElementById('edit_pc_number');
            if (editDiscordEmail && editSalesExec && editPcNumber && editDiscordEmail.value) {
                fetchSalesInfo(editDiscordEmail.value, editSalesExec, editPcNumber);
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            // Edit handlers
            document.querySelectorAll('.btn-edit').forEach(btn => {
                btn.addEventListener('click', function() {
                    const d = this.dataset;
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
                                    // Reload the page to refresh the table
                                    location.reload();
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
                            } else if (data.nochange) {
                                errorDiv.textContent = 'No changes detected.';
                                errorDiv.style.color = 'orange';
                                errorDiv.style.display = 'block';
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
        });

        // Function to fetch sales executive name and PC number based on discord email
        function fetchSalesInfo(discordEmail, salesExecField, pcNumberField) {
            if (!discordEmail) {
                salesExecField.value = '';
                pcNumberField.value = '';
                return;
            }

            fetch('../api/get_sales_info.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'discord_email=' + encodeURIComponent(discordEmail)
                })
                .then(response => response.json())
                .then(data => {
                    salesExecField.value = data.name || '';
                    pcNumberField.value = data.pc_number || '';
                })
                .catch(error => {
                    console.error('Error fetching sales info:', error);
                    salesExecField.value = '';
                    pcNumberField.value = '';
                });
        }

        // Add event listeners for discord email changes
        document.addEventListener('DOMContentLoaded', function() {
            // For add modal
            const addDiscordEmail = document.getElementById('discord_email');
            const addSalesExec = document.getElementById('sales_executive');
            const addPcNumber = document.getElementById('pc_number');

            if (addDiscordEmail && addSalesExec && addPcNumber) {
                addDiscordEmail.addEventListener('change', function() {
                    fetchSalesInfo(this.value, addSalesExec, addPcNumber);
                });
            }

            // For edit modal
            const editDiscordEmail = document.getElementById('edit_discord_email');
            const editSalesExec = document.getElementById('edit_sales_executive');
            const editPcNumber = document.getElementById('edit_pc_number');

            if (editDiscordEmail && editSalesExec && editPcNumber) {
                editDiscordEmail.addEventListener('change', function() {
                    fetchSalesInfo(this.value, editSalesExec, editPcNumber);
                });
            }
        });

        // Function to handle rows per page change
        function changeRowsPerPage(value) {
            const url = new URL(window.location);
            url.searchParams.set('rows_per_page', value);
            url.searchParams.set('page', '1'); // Reset to first page when changing rows per page
            window.location.href = url.toString();
        }
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
                <button type="button" class="btn-add" onclick="openBulkModal()" style="background: #28a745;">Bulk Upload</button>
                <a href="?<?php echo http_build_query(array_merge($_GET, ['download' => 'csv'])); ?>" class="btn-add" style="background: #17a2b8;">Download CSV</a>

                <?php if (isset($_GET['bulk_message'])): ?>
                    <p style="color: green; margin-top: 10px;"><?php echo htmlspecialchars($_GET['bulk_message']); ?></p>
                <?php endif; ?>

                <!-- Hidden Delete Form -->
                <form id="deleteForm" method="POST" style="display:none;">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="">
                </form>

                <div class="filter-container">
                    <h3>Filter Entries</h3>
                    <form method="GET">
                        <div class="form-section">
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="order_number_filter">Order #:</label>
                                    <input type="number" id="order_number_filter" name="order_number" value="<?php echo isset($_GET['order_number']) ? htmlspecialchars($_GET['order_number']) : ''; ?>" placeholder="Order number">
                                </div>
                                <div class="form-group">
                                    <label for="sales_executive_filter">Sales Executive:</label>
                                    <input type="text" id="sales_executive_filter" name="sales_executive" value="<?php echo isset($_GET['sales_executive']) ? htmlspecialchars($_GET['sales_executive']) : ''; ?>" placeholder="Sales executive">
                                </div>
                                <div class="form-group">
                                    <label for="discord_email_filter">Discord Email:</label>
                                    <input type="text" id="discord_email_filter" name="discord_email" value="<?php echo isset($_GET['discord_email']) ? htmlspecialchars($_GET['discord_email']) : ''; ?>" placeholder="Enter Discord Email">
                                </div>
                                <div class="form-group">
                                    <label for="upsale_order_number_filter">Up-sale Order #:</label>
                                    <input type="text" id="upsale_order_number_filter" name="upsale_order_number" value="<?php echo isset($_GET['upsale_order_number']) ? htmlspecialchars($_GET['upsale_order_number']) : ''; ?>" placeholder="e.g. 2534">
                                </div>
                            </div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="pc_number_filter">PC Number:</label>
                                    <input type="text" id="pc_number_filter" name="pc_number" value="<?php echo isset($_GET['pc_number']) ? htmlspecialchars($_GET['pc_number']) : ''; ?>" placeholder="PC number">
                                </div>
                                <div class="form-group">
                                    <label for="empty_sales_pc_filter">Show entries with empty Sales Executive or PC Number:</label>
                                    <input type="checkbox" id="empty_sales_pc_filter" name="empty_sales_pc" value="1" <?php if (isset($_GET['empty_sales_pc'])) echo 'checked'; ?>>
                                </div>
                                <div class="form-group">
                                    <label for="fresh_sale_item_filter">Fresh Sale Item:</label>
                                    <select id="fresh_sale_item_filter" name="fresh_sale_item">
                                        <option value="">All</option>
                                        <?php foreach ($fresh_sale_options as $option): ?>
                                            <option value="<?php echo htmlspecialchars($option); ?>" <?php if (isset($_GET['fresh_sale_item']) && $_GET['fresh_sale_item'] == $option) echo 'selected'; ?>><?php echo htmlspecialchars($option); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label for="first_sale_price_filter">1st Sale Price Notes:</label>
                                    <input type="text" id="first_sale_price_filter" name="first_sale_price" value="<?php echo isset($_GET['first_sale_price']) ? htmlspecialchars($_GET['first_sale_price']) : ''; ?>" placeholder="Total: $100 | Received: $10">
                                </div>
                                <div class="form-group">
                                    <label for="closed_by_filter">Closed By:</label>
                                    <input type="text" id="closed_by_filter" name="closed_by" value="<?php echo isset($_GET['closed_by']) ? htmlspecialchars($_GET['closed_by']) : ''; ?>" placeholder="Closed by">
                                </div>
                            </div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="team_filter">Team:</label>
                                    <select id="team_filter" name="team">
                                        <option value="">All</option>
                                        <?php foreach ($team_options as $option): ?>
                                            <option value="<?php echo htmlspecialchars($option); ?>" <?php if (isset($_GET['team']) && $_GET['team'] == $option) echo 'selected'; ?>><?php echo htmlspecialchars($option); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label for="nurturing_sale_filter">Nurturing Sale:</label>
                                    <input type="text" id="nurturing_sale_filter" name="nurturing_sale" value="<?php echo isset($_GET['nurturing_sale']) ? htmlspecialchars($_GET['nurturing_sale']) : ''; ?>" placeholder="Nurturing sale">
                                </div>
                                <div class="form-group">
                                    <label for="plan_upcoming_sales_filter">Plan Upcoming Sales:</label>
                                    <input type="text" id="plan_upcoming_sales_filter" name="plan_upcoming_sales" value="<?php echo isset($_GET['plan_upcoming_sales']) ? htmlspecialchars($_GET['plan_upcoming_sales']) : ''; ?>" placeholder="Plan upcoming sales">
                                </div>
                                <div class="form-group">
                                    <label for="nurturing_rating_filter">Nurturing Rating:</label>
                                    <input type="number" step="0.01" id="nurturing_rating_filter" name="nurturing_rating" value="<?php echo isset($_GET['nurturing_rating']) ? htmlspecialchars($_GET['nurturing_rating']) : ''; ?>" placeholder="Nurturing rating">
                                </div>
                            </div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="items_left_upsale_filter">Items Left for Up-Sale:</label>
                                    <input type="text" id="items_left_upsale_filter" name="items_left_upsale" value="<?php echo isset($_GET['items_left_upsale']) ? htmlspecialchars($_GET['items_left_upsale']) : ''; ?>" placeholder="Items left for up-sale">
                                </div>
                                <div class="form-group">
                                    <label for="client_discord_username_filter">Client Discord Username:</label>
                                    <input type="text" id="client_discord_username_filter" name="client_discord_username" value="<?php echo isset($_GET['client_discord_username']) ? htmlspecialchars($_GET['client_discord_username']) : ''; ?>" placeholder="Client Discord username">
                                </div>
                                <div class="form-group">
                                    <label for="client_name_payment_filter">Client Name Payment:</label>
                                    <input type="text" id="client_name_payment_filter" name="client_name_payment" value="<?php echo isset($_GET['client_name_payment']) ? htmlspecialchars($_GET['client_name_payment']) : ''; ?>" placeholder="Client name payment">
                                </div>
                            </div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Next Payment Date (From / To)</label>
                                    <div class="form-row">
                                        <div class="form-group">
                                            <input type="date" id="next_payment_from" name="next_payment_from" value="<?php echo isset($_GET['next_payment_from']) ? htmlspecialchars($_GET['next_payment_from']) : ''; ?>">
                                        </div>
                                        <div class="form-group">
                                            <input type="date" id="next_payment_to" name="next_payment_to" value="<?php echo isset($_GET['next_payment_to']) ? htmlspecialchars($_GET['next_payment_to']) : ''; ?>">
                                        </div>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label>Expected Next Up Sale Date (From / To)</label>
                                    <div class="form-row">
                                        <div class="form-group">
                                            <input type="date" id="expected_upsale_from" name="expected_upsale_from" value="<?php echo isset($_GET['expected_upsale_from']) ? htmlspecialchars($_GET['expected_upsale_from']) : ''; ?>">
                                        </div>
                                        <div class="form-group">
                                            <input type="date" id="expected_upsale_to" name="expected_upsale_to" value="<?php echo isset($_GET['expected_upsale_to']) ? htmlspecialchars($_GET['expected_upsale_to']) : ''; ?>">
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="if_client_lost_filter">If Client Lost:</label>
                                    <input type="text" id="if_client_lost_filter" name="if_client_lost" value="<?php echo isset($_GET['if_client_lost']) ? htmlspecialchars($_GET['if_client_lost']) : ''; ?>" placeholder="If client lost">
                                </div>
                                <div class="form-group">
                                    <label for="comments_filter">Comments:</label>
                                    <input type="text" id="comments_filter" name="comments" value="<?php echo isset($_GET['comments']) ? htmlspecialchars($_GET['comments']) : ''; ?>" placeholder="Comments">
                                </div>
                            </div>
                        </div>
                        <div class="form-actions">
                            <button type="submit" class="btn-submit">Apply Filters</button>
                            <a class="btn-cancel" href="<?php echo basename(__FILE__); ?>">Reset</a>
                        </div>
                    </form>
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
                                                data-upsale_order_number="<?php echo htmlspecialchars(formatUpsaleOrderNumbers($entry['upsale_order_number'])); ?>"
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
                                            <button type="button" class="btn-delete" data-id="<?php echo htmlspecialchars($entry['id']); ?>">Delete</button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination Controls -->
                    <?php if ($total_pages > 1): ?>
                        <div class="pagination">
                            <div class="pagination-info">
                                Showing <?php echo ($page - 1) * $rows_per_page + 1; ?> to <?php echo min($page * $rows_per_page, $total_entries); ?> of <?php echo $total_entries; ?> entries
                            </div>
                            <div class="pagination-controls">
                                <?php if ($page > 1): ?>
                                    <a href="<?php echo $base_page_prefix; ?>page=<?php echo $page - 1; ?>">Previous</a>
                                <?php endif; ?>

                                <?php
                                $start_page = max(1, $page - 2);
                                $end_page = min($total_pages, $page + 2);
                                if ($start_page > 1): ?>
                                    <a href="<?php echo $base_page_prefix; ?>page=1">1</a>
                                    <?php if ($start_page > 2): ?>
                                        <span>...</span>
                                    <?php endif; ?>
                                <?php endif; ?>

                                <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                                    <a href="<?php echo $base_page_prefix; ?>page=<?php echo $i; ?>" class="<?php echo $i == $page ? 'current' : ''; ?>"><?php echo $i; ?></a>
                                <?php endfor; ?>

                                <?php if ($end_page < $total_pages): ?>
                                    <?php if ($end_page < $total_pages - 1): ?>
                                        <span>...</span>
                                    <?php endif; ?>
                                    <a href="<?php echo $base_page_prefix; ?>page=<?php echo $total_pages; ?>"><?php echo $total_pages; ?></a>
                                <?php endif; ?>

                                <?php if ($page < $total_pages): ?>
                                    <a href="<?php echo $base_page_prefix; ?>page=<?php echo $page + 1; ?>">Next</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
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
                    <div class="form-group">
                        <label for="order_number">Order # (1st sale): <span style="color: red;">*</span></label>
                        <input type="number" id="order_number" name="order_number" required>
                    </div>
                    <div class="form-group">
                        <label for="sales_executive">Sales Executive Name: <span style="color: red;">*</span></label>
                        <input type="text" id="sales_executive" name="sales_executive" required readonly>
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
                        <input type="text" id="pc_number" name="pc_number" value="" required>
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

        <!-- Bulk Upload Modal -->
        <div id="bulkModal" class="modal">
            <div class="modal-content">
                <span class="close" onclick="closeModal()">&times;</span>
                <h2>Bulk Upload Client Retention Entries</h2>
                <p>Upload a CSV file with the following headers: order_number, discord_email, upsale_order_number, fresh_sale_item, first_sale_price, next_payment_date, closed_by, team, nurturing_sale, plan_upcoming_sales, nurturing_rating, items_left_upsale, client_discord_username, client_name_payment, expected_next_upsale_date, if_client_lost, comments</p>
                <p style="font-size: 14px; color: #555;">Tip: store first_sale_price as multi-line notes (e.g., &quot;Total: $100&quot;, &quot;Received: $10&quot;, etc.). Use \n for new lines inside the CSV cell.</p>
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